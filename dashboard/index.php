<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Dashboard
//
//  A restyle, not a rewrite. Every id, class and data attribute below is
//  exactly what js/dashboard.js binds to — the write form, the image and
//  poster uploads, autosave, draft state, publish and unpublish. That file is
//  deliberately untouched; only the shell and the stylesheet change.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$me = ctx_me($conn);
// Signing in and onboarding both live at the root now.
if (!$me || ctx_state($conn) !== 'member') { header('Location: /'); exit; }

$q = $conn->prepare("SELECT id, title, subtitle, type, stamp, edited, active
                     FROM `posts` WHERE uid = :uid ORDER BY COALESCE(edited, stamp) DESC");
$q->execute([':uid' => $me['id']]);
$all_posts   = $q->fetchAll(PDO::FETCH_ASSOC);
$post_count  = count($all_posts);

$q = $conn->prepare("SELECT id, title, poster, screentime, location, address, stamp, edited, active
                     FROM `events` WHERE uid = :uid ORDER BY screentime DESC");
$q->execute([':uid' => $me['id']]);
$all_events  = $q->fetchAll(PDO::FETCH_ASSOC);
$event_count = count($all_events);

$e = 'ctx_e';

$ctx_title  = 'Dashboard — Cinema, TX';
$ctx_active = 'dashboard';
$ctx_scroll = true;
$ctx_video  = false;

ob_start(); ?>
<script src="https://code.jquery.com/jquery-3.2.1.min.js"
        integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
<script src="/js/dashboard.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/dashboard.js'); ?>"></script>
<?php $ctx_scripts = ob_get_clean();

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing">

      <div class="listing__head">
        <div class="listing__title">
          <h1 class="listing__h">Dashboard</h1>
          <span class="listing__sub"><?php echo $e($me['name']); ?></span>
        </div>

        <nav class="dash-nav">
          <span class="dash-tab" data-panel="write">Write</span>
          <span class="dash-tab" data-panel="posts">Posts<?php if ($post_count): ?> <span class="dash-count"><?php echo $post_count; ?></span><?php endif; ?></span>
          <span class="dash-tab" data-panel="events">Screenings<?php if ($event_count): ?> <span class="dash-count"><?php echo $event_count; ?></span><?php endif; ?></span>
          <span class="dash-tab" data-panel="account">Account</span>
        </nav>
      </div>

      <div class="dash-card">

        <!-- ── Write ──────────────────────────────────────────────────── -->
        <div class="dash-panel" id="panel-write">
          <div class="write-wrap">
            <input type="text" id="post-title"    class="write-title"    placeholder="Title"    autocomplete="off" />
            <input type="text" id="post-subtitle" class="write-subtitle" placeholder="Subtitle" autocomplete="off" />

            <div class="write-row">
              <select id="post-type" class="write-type">
                <option value="">Type — optional</option>
                <option value="review">Review</option>
                <option value="essay">Essay</option>
                <option value="note">Note</option>
              </select>
              <label class="write-featured">
                <input type="checkbox" id="post-featured" /> Featured
              </label>
            </div>

            <textarea id="post-content" class="write-content" placeholder="Write something…"></textarea>

            <div class="write-image-wrap">
              <div class="write-image-row">
                <label class="write-image-btn" id="post-image-label">
                  <i class="fa-solid fa-image"></i> Main image
                  <input type="file" id="post-image" accept="image/jpeg,image/png,image/webp,image/gif" disabled />
                </label>
                <span class="write-image-hint" id="post-image-hint">Save a draft first</span>
              </div>
              <div class="write-image-preview" id="post-image-preview" style="display:none;">
                <img id="post-image-thumb" src="" alt="preview" />
                <button class="write-image-remove" id="post-image-remove" title="Remove image">&#x2715;</button>
              </div>
              <input type="text" id="post-photo-cred" class="write-photo-cred" placeholder="Photo credit — optional" autocomplete="off" />
            </div>

            <div class="write-footer">
              <span id="autosave-status" class="autosave-status"></span>
              <div class="write-actions">
                <button id="post-save"    class="submit write-save">Save draft</button>
                <button id="post-publish" class="submit write-publish" disabled>Publish</button>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Posts ──────────────────────────────────────────────────── -->
        <div class="dash-panel" id="panel-posts">
          <?php if ($all_posts): ?>
          <ul class="drafts-list">
            <?php foreach ($all_posts as $post): $is_live = (int)$post['active'] === 1; ?>
            <li class="draft-row <?php echo $is_live ? 'post-live' : ''; ?>"
                data-id="<?php echo (int)$post['id']; ?>"
                data-active="<?php echo (int)$post['active']; ?>"
                data-title="<?php echo $e($post['title']); ?>"
                data-subtitle="<?php echo $e($post['subtitle']); ?>"
                data-type="<?php echo $e($post['type']); ?>">
              <div class="draft-info">
                <span class="draft-title"><?php echo $post['title'] ? $e($post['title']) : '<em>Untitled</em>'; ?></span>
                <?php if ($post['type']): ?><span class="draft-type"><?php echo $e($post['type']); ?></span><?php endif; ?>
                <span class="post-status <?php echo $is_live ? 'status-live' : 'status-draft'; ?>"><?php echo $is_live ? 'live' : 'draft'; ?></span>
                <span class="draft-date"><?php echo date('j M Y', $post['edited'] ?: $post['stamp']); ?></span>
              </div>
              <div class="post-row-actions">
                <?php if ($is_live): ?>
                  <a class="post-view" href="<?php echo $e(ctx_post_url($post['id'], $post['title'])); ?>" target="_blank">view</a>
                  <button class="post-edit" data-id="<?php echo (int)$post['id']; ?>">edit</button>
                  <button class="post-unpublish" data-id="<?php echo (int)$post['id']; ?>">unpublish</button>
                <?php else: ?>
                  <button class="draft-delete" data-id="<?php echo (int)$post['id']; ?>">&#x2715;</button>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <p class="drafts-empty">Nothing here yet.</p>
          <?php endif; ?>
        </div>

        <!-- ── Screenings ─────────────────────────────────────────────── -->
        <div class="dash-panel" id="panel-events">
          <div class="write-wrap">
            <input type="text" id="event-title"    class="write-title"    placeholder="Title" autocomplete="off" />
            <input type="text" id="event-location" class="write-subtitle" placeholder="Location — e.g. AFS Cinema, or &quot;my backyard&quot;" autocomplete="off" />
            <input type="text" id="event-address"  class="write-subtitle" placeholder="Address — optional" autocomplete="off" />
            <input type="datetime-local" id="event-screentime" class="write-subtitle event-datetime" />

            <div class="write-image-wrap">
              <div class="write-image-row">
                <label class="write-image-btn" id="event-poster-label">
                  <i class="fa-solid fa-image"></i> Poster
                  <input type="file" id="event-poster" accept="image/jpeg,image/png,image/webp,image/gif" disabled />
                </label>
                <span class="write-image-hint" id="event-poster-hint">Save a draft first</span>
              </div>
              <div class="write-image-preview" id="event-poster-preview" style="display:none;">
                <img id="event-poster-thumb" src="" alt="preview" />
                <button class="write-image-remove" id="event-poster-remove" title="Remove poster">&#x2715;</button>
              </div>
            </div>

            <div class="write-footer">
              <span id="event-autosave-status" class="autosave-status"></span>
              <div class="write-actions">
                <button id="event-save"    class="submit write-save">Save draft</button>
                <button id="event-publish" class="submit write-publish" disabled>Publish</button>
              </div>
            </div>
          </div>

          <h3 class="events-list-heading">Your screenings</h3>
          <?php if ($all_events): ?>
          <ul class="drafts-list" id="events-list">
            <?php foreach ($all_events as $ev): $ev_live = (int)$ev['active'] === 1; ?>
            <li class="draft-row event-row <?php echo $ev_live ? 'post-live' : ''; ?>"
                data-id="<?php echo (int)$ev['id']; ?>"
                data-active="<?php echo (int)$ev['active']; ?>">
              <div class="draft-info">
                <span class="draft-title"><?php echo $e($ev['title']); ?></span>
                <span class="draft-type"><?php echo $e($ev['location']); ?></span>
                <span class="post-status <?php echo $ev_live ? 'status-live' : 'status-draft'; ?>"><?php echo $ev_live ? 'live' : 'draft'; ?></span>
                <span class="draft-date"><?php echo date('j M Y, g:ia', $ev['screentime']); ?></span>
              </div>
              <div class="post-row-actions">
                <?php if ($ev_live): ?>
                  <a class="post-view" href="/events/?id=<?php echo (int)$ev['id']; ?>" target="_blank">view</a>
                  <button class="event-edit" data-id="<?php echo (int)$ev['id']; ?>">edit</button>
                  <button class="event-unpublish" data-id="<?php echo (int)$ev['id']; ?>">unpublish</button>
                <?php else: ?>
                  <button class="event-delete" data-id="<?php echo (int)$ev['id']; ?>">&#x2715;</button>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <p class="drafts-empty">No screenings submitted yet.</p>
          <?php endif; ?>
        </div>

        <!-- ── Account ────────────────────────────────────────────────── -->
        <div class="dash-panel" id="panel-account">
          <div class="profile-photo-wrap">
            <div class="profile-photo-circle" id="profile-photo-circle">
              <img id="profile-photo-preview-img"
                   src="<?php echo !empty($me['photo']) ? '/uploads/profiles/' . $e($me['photo']) : ''; ?>"
                   alt="" style="<?php echo empty($me['photo']) ? 'display:none;' : ''; ?>" />
              <i class="fa-solid fa-user" id="profile-photo-placeholder"
                 style="<?php echo !empty($me['photo']) ? 'display:none;' : ''; ?>"></i>
            </div>
            <div class="profile-photo-actions">
              <label class="write-image-btn enabled" for="profile-photo-input">
                <i class="fa-solid fa-camera"></i> Change photo
              </label>
              <input type="file" id="profile-photo-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" />
              <button type="button" class="profile-photo-remove" id="profile-photo-remove"
                      style="<?php echo empty($me['photo']) ? 'display:none;' : ''; ?>">Remove</button>
            </div>
          </div>

          <form class="dash-form" action="/dashboard/signup.php?action=updateprof" method="post">
            <div class="field">
              <label class="field__label">Email</label>
              <input class="field__input" type="text" value="<?php echo $e($me['email']); ?>" disabled />
              <input type="hidden" name="email" value="<?php echo $e($me['email']); ?>" />
            </div>
            <div class="gate__row">
              <div class="field">
                <label class="field__label" for="d-name">Name</label>
                <input class="field__input" id="d-name" type="text" name="uname" value="<?php echo $e($me['name']); ?>" />
              </div>
              <div class="field">
                <label class="field__label" for="d-role">Role</label>
                <select class="field__input" id="d-role" name="dept">
                  <option value="0">Select your role</option>
                  <?php foreach ($roles as $r): ?>
                  <option value="<?php echo $e($r); ?>"<?php echo $me['dept'] === $r ? ' selected' : ''; ?>><?php echo $e($r); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="gate__row">
              <div class="field">
                <label class="field__label" for="d-lb">Letterboxd</label>
                <input class="field__input" id="d-lb" type="text" name="lb" value="<?php echo $e($me['lb']); ?>" placeholder="username" />
              </div>
              <div class="field">
                <label class="field__label" for="d-site">Website</label>
                <input class="field__input" id="d-site" type="text" name="website" value="<?php echo $e($me['website']); ?>" />
              </div>
            </div>
            <div class="field">
              <label class="field__label" for="d-phone">Phone</label>
              <input class="field__input" id="d-phone" type="tel" name="phone" value="<?php echo $e($me['phone']); ?>" />
            </div>
            <button class="btn" type="submit">Update</button>
          </form>
        </div>

      </div><!-- /.dash-card -->
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
