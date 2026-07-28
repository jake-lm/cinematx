# Deploying Cinema, TX

Written for a first deploy onto a fresh server with a fresh database.
Everything here has an order for a reason — the last two steps are what stop
the first visitor having a bad time.

---

## 0. Server requirements

| | |
|---|---|
| PHP | **7.4 minimum** (arrow functions). Developed against 8.4. |
| Extensions | `pdo_mysql`, `curl`, `mbstring`, `fileinfo` |
| MySQL | 5.7+ |
| Apache | `mod_rewrite`, and `AllowOverride All` on the web root, or `.htaccess` is ignored **silently** |

`AllowOverride` is the one that fails quietly. If it's off you get no pretty
post URLs *and* none of the deny rules — `.git` included. Check it explicitly.

Upload limits govern the film uploader. The default `upload_max_filesize` of
2M will reject every film:

```ini
upload_max_filesize = 512M
post_max_size       = 512M
max_execution_time  = 600
```

---

## 1. Files

Deploy the working tree. Do **not** deploy `.git` if you can avoid it — if you
deploy by cloning, confirm `https://yourdomain/.git/config` returns 404 before
going further.

Writable by the web server — set them **all** at once rather than listing
directories by hand, because the list is longer than it looks:

```bash
chown -R root:www-data motw uploads list data
find uploads -type d -exec chmod 775 {} \;
chmod 775 motw list data data/visits
```

```
motw/                 uploaded films and posters
uploads/posts/        images attached to journal posts
uploads/profiles/     member photos
uploads/events/       event posters
list/                 scrape caches are written here
data/                 visitor counts and the hashing salt
```

Two that bite:

`uploads/posts/` and `uploads/profiles/` contain tracked files, so a checkout
creates them at 755 — readable but **not** group-writable. Every image upload
then fails with a bare "image upload failed" and nothing in the Apache error
log, because the handler catches `move_uploaded_file` returning false and
answers with JSON. Chmod the whole tree, not the directories you remember.

`list/` is the other one. If it isn't writable, every page request re-scrapes
because the cache can never be saved, and The List becomes extremely slow
rather than visibly broken.

---

## 2. Config

`config.php` is gitignored and must be created by hand:

```bash
cp config.example.php config.php
```

Fill in the database credentials and `TMDB_API_KEY`, and leave:

```php
define('CTX_DEBUG', false);
```

**Never true on a public host.** It's what decides whether PHP errors — SQL,
absolute paths, stack traces — are printed to visitors. Errors are written to
the PHP error log either way.

---

## 3. Database

Import the schema, then create your account through the normal sign-up flow at
`/#join` rather than by hand, so the password is hashed the way the login
expects.

Then grant yourself admin — there is no UI for this, deliberately:

```sql
UPDATE users SET admin = 1 WHERE email = 'you@example.com';
```

`users.admin` is what `/_admin` gates on. Without it the panel 404s for
everyone, including you.

---

## 4. Cron

```cron
*/30 * * * * /usr/bin/php /var/www/cinematx/bin/warm-cache.php >> /var/log/cinematx-warm.log 2>&1
```

Without this the caches refill lazily, and whichever visitor happens to arrive
after a TTL expires waits on three venue scrapes plus a TMDB lookup for every
unfamiliar title.

The log is worth reading occasionally. A venue whose page structure changes
scrapes to zero results and fails silently — The List simply stops mentioning
them — so the warmer prints a warning when a venue yields nothing.

---

## 5. Warm the cache before announcing the site

```bash
php bin/warm-cache.php
```

On a fresh deploy every cache is cold. Without this, the first visitor pays for
the entire listing — roughly a hundred HTTP round-trips — before a single byte
renders. It takes a few seconds from the command line and it is the difference
between the site feeling instant and feeling broken.

Expect output like:

```
warm: 36 screenings in 1.5s
      Austin Film Society      24
      Paramount Theatre         7
      Hyperreal Film Club       5
      poster                   36/36
      year                     36/36
      runtime                  36/36
      wiki                     32/36
```

Not every film has an English Wikipedia article, so `wiki` is expected to be
short of the total. `poster` and `year` well below the total means TMDB is
rejecting the key.

---

## 6. Verify before announcing

```bash
curl -o /dev/null -w '%{http_code}\n' https://yourdomain/_admin/     # 404
curl -o /dev/null -w '%{http_code}\n' https://yourdomain/.git/config # 404
curl -s https://yourdomain/ | grep -c 'Warning\|Fatal error'         # 0
```

- `/_admin/` must 404 while logged out, and while logged in as a non-admin.
- Sign in, confirm `/_admin/` loads, and schedule one showtime.
- Load `/th1` and confirm playback starts and is in sync.
- Hover a row on `/list` and confirm the preview card appears.

---

## Known, deliberate

- **`films.motw` is dead.** Nothing reads it. It's still written as `0` on
  upload and can be dropped when the schema is next touched.
- **`dashboard.js` and `quick-create.js`** are still jQuery, ~1,200 lines. They
  work; they're the last of the old front-end.
