<?php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'jlmnet');
define('TMDB_API_KEY', 'your_tmdb_api_key');

// Instagram Graph API — daily "Today in Austin" post (bin/post-instagram.php).
// Leave IG_ACCESS_TOKEN blank to skip posting; --dry-run works without any of
// these set. META_APP_ID/SECRET are only needed for bin/refresh-ig-token.php.
define('IG_ACCESS_TOKEN', '');
define('IG_BUSINESS_ACCOUNT_ID', '');
define('META_APP_ID', '');
define('META_APP_SECRET', '');

// Show PHP errors in the browser. Leave false on any public server — errors
// are always written to the log either way.
define('CTX_DEBUG', false);

// Canonical public origin, used for share links and Open Graph tags. No
// trailing slash.
define('CTX_SITE_URL', 'https://cinematx.net');
