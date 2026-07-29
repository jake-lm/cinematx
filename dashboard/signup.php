<?php
session_start();
date_default_timezone_set('America/Chicago');

require '../database.php';
require '../roles.php';

// ── Password policy ────────────────────────────────────────────────────────
// bcrypt silently ignores everything past 72 bytes, so a longer passphrase is
// not more secure than its first 72 characters — say so rather than pretend.
const PW_MIN = 8;
const PW_MAX = 72;

// ── Login throttling ───────────────────────────────────────────────────────
// Nothing limited guessing before this. Rate limiting is the actual defence
// against brute force — not the incidental slowness of a wasted hash.
const LOGIN_WINDOW   = 900;   // 15 minutes
const LOGIN_MAX_FAIL = 10;

function auth_idents() {
    // Throttle the account and the source independently, so hammering one
    // account cannot lock out an unrelated member behind the same NAT, and a
    // spray across many accounts from one address still trips.
    return [
        'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        'email:' . mb_strtolower(trim((string)($_POST['email'] ?? ''))),
    ];
}

function auth_throttled($conn) {
    $q = $conn->prepare("SELECT COUNT(*) FROM `login_attempts` WHERE `ident` = :i AND `ts` > :t");
    foreach (auth_idents() as $ident) {
        $q->execute([':i' => $ident, ':t' => time() - LOGIN_WINDOW]);
        if ((int)$q->fetchColumn() >= LOGIN_MAX_FAIL) return true;
    }
    return false;
}

function auth_fail($conn) {
    $q = $conn->prepare("INSERT INTO `login_attempts` (`ident`, `ts`) VALUES (:i, :t)");
    foreach (auth_idents() as $ident) $q->execute([':i' => $ident, ':t' => time()]);
    // Opportunistic sweep so the table cannot grow without bound.
    $conn->prepare("DELETE FROM `login_attempts` WHERE `ts` < :t")->execute([':t' => time() - LOGIN_WINDOW * 4]);
}

function auth_clear($conn) {
    $q = $conn->prepare("DELETE FROM `login_attempts` WHERE `ident` = :i");
    foreach (auth_idents() as $ident) $q->execute([':i' => $ident]);
}

/**
 * Start an authenticated session.
 *
 * session_regenerate_id() is the point of this function. Without it the id the
 * browser arrived with stays valid after login, so anyone able to plant a
 * cookie first — and sibling subdomains on cinematx.net can — could fixate a
 * session, wait for the member to sign in, and then use that same id as them.
 */
function auth_login($email) {
    session_regenerate_id(true);
    session_unset();
    $_SESSION['logged']   = 'yes';
    $_SESSION['username'] = $email;
}

/**
 * Letterboxd stores a bare username: it is interpolated into
 * letterboxd.com/<lb>/ on the profile and directory, and handed to
 * fetch_letterboxd_profile(). People paste the whole profile URL, which
 * silently produced letterboxd.com/https://letterboxd.com/jake// and a scraper
 * that found nothing. Unpick it rather than blaming the member.
 */
function normalize_lb($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    // Scheme optional: people paste "letterboxd.com/jake" as often as the URL.
    $v = preg_replace('#^(https?://)?(www\.)?letterboxd\.com/#i', '', $v);
    $v = ltrim($v, '@/');
    $v = explode('/', $v)[0];            // drop /films, /list/... tails
    $v = preg_replace('/[^A-Za-z0-9_]/', '', $v);
    return $v !== '' ? $v : null;
}

$action = $_GET['action'] ?? '';

if ($action === 'update_lb') {
  // A single-field sibling to updateprof — that endpoint rewrites every
  // profile column at once, so posting just `lb` to it would blank out the
  // member's real name, phone, website and role. This one touches nothing
  // else, and like every other write here, whose row it touches comes from
  // the session, never from POST.
  header('Content-Type: application/json');
  if (!isset($_SESSION['username'])) { echo json_encode(['success' => false]); exit; }

  $uid_q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
  $uid_q->execute([':email' => $_SESSION['username']]);
  $uid = $uid_q->fetchColumn();
  if (!$uid) { echo json_encode(['success' => false]); exit; }

  $lb = normalize_lb($_POST['lb'] ?? '');
  $conn->prepare("UPDATE `users` SET `lb` = :lb WHERE `id` = :uid")->execute([':lb' => $lb, ':uid' => $uid]);
  echo json_encode(['success' => true, 'lb' => $lb]);
  exit;
}
else if ($action === 'check_email') {
  // Read-only and reveals nothing the signup form doesn't already disclose
  // via error 104 on submit — this just answers sooner, while typing.
  header('Content-Type: application/json');
  $email = trim((string)($_POST['email'] ?? ''));

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['valid' => false]);
    exit;
  }

  $q = $conn->prepare("SELECT id FROM `users` WHERE `email` = :email LIMIT 1");
  $q->execute([':email' => $email]);
  echo json_encode(['valid' => true, 'available' => !$q->fetchColumn()]);
  exit;
}
else if($action==='login') {
	$user = trim((string)($_POST['email'] ?? ''));
	$pass = (string)($_POST['pw'] ?? '');

  if (auth_throttled($conn)) { header('Location: /dashboard/?error=109'); exit; }

  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
  $sql1->execute([':email' => $user]);
  $qUser = $sql1->fetch();

  // Guarded: an unknown email used to index into `false` and warn.
  $pwv = $qUser ? password_verify($pass, $qUser['password']) : false;

	if($user === "" || $pass === "" || $pwv === false) {
    auth_fail($conn);
    header('Location: /dashboard/?error=100');
    exit;
	}
	else {
    auth_clear($conn);
    auth_login($user);

    // If PHP's default algorithm has moved on since this hash was written,
    // now is the only moment the plaintext is available to upgrade it.
    if (password_needs_rehash($qUser['password'], PASSWORD_DEFAULT)) {
      $conn->prepare("UPDATE `users` SET `password` = :p WHERE `id` = :id")
           ->execute([':p' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $qUser['id']]);
    }

    $stmt = $conn->prepare("UPDATE `users` SET `last_date` = :last_date WHERE `email` = :email");
    $stmt->execute([':last_date' => time(), ':email' => $user]);

    header('Location: /');
    exit;
	}
}
else if($action==='signup') {
  $is_ajax = isset($_POST['ajax']);
  $name = null;
  $pass = $_POST['pw'];
  $cPass = $_POST['pw2'];
  $email = $_POST['email'];
  $code = trim($_POST['code']);
  $phone = null;
  $website = null;
  $sign_date = time();
  $lb = null;
  $position = null;
  $active = 1;

  // validate fields
  if($email === "" || $pass === "" || $pass != $cPass) {
    if($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => '102']);
      exit;
    }
    ?>
    <script type="text/javascript" language="javascript">
      window.location="/dashboard/?error=102"
    </script>
    <?php
    exit;
  }

  // Length was never checked — a one-character password was accepted.
  if(strlen($pass) < PW_MIN || strlen($pass) > PW_MAX) {
    if($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => '110']);
      exit;
    }
    header('Location: /dashboard/?error=110');
    exit;
  }

  // check access code
  $sql_code = $conn->prepare("SELECT * FROM `codes` WHERE `code` = :code AND `active` = 1");
  $sql_code->execute([':code' => $code]);
  $valid_code = $sql_code->fetch();

  if(!$valid_code) {
    if($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => '108']);
      exit;
    }
    ?>
    <script type="text/javascript" language="javascript">
      window.location="/dashboard/?error=108"
    </script>
    <?php
    exit;
  }

  // check email not already registered
  $sql1 = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
  $sql1->execute([':email' => $email]);
  $qUser = $sql1->fetch();

  if($qUser) {
    if($is_ajax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => '104']);
      exit;
    }
    ?>
    <script type="text/javascript" language="javascript">
      window.location="/dashboard/?error=104"
    </script>
    <?php
    exit;
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("INSERT INTO `users` (name, email, password, phone, website, lb, position, active, sign_date, last_date)
                         VALUES (:name, :email, :password, :phone, :website, :lb, :position, :active, :sign_date, :last_date)");
  $stmt->execute([
    ':name'      => $name,
    ':email'     => $email,
    ':password'  => $hash,
    ':phone'     => $phone,
    ':website'   => $website,
    ':lb'        => $lb,
    ':position'  => $position,
    ':active'    => $active,
    ':sign_date' => $sign_date,
    ':last_date' => $sign_date,
  ]);

  // increment code usage counter
  $conn->prepare("UPDATE `codes` SET `uses` = `uses` + 1 WHERE `id` = :id")
       ->execute([':id' => $valid_code['id']]);

  auth_login($email);

  if($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'uid' => $conn->lastInsertId()]);
    exit;
  }
?>
  <script type="text/javascript" language="javascript">
    window.location="../"
  </script>
<?php
}
else if($action==="updateprof") {
  // Same fix as activateacct: the uid is whoever is signed in, never whoever
  // POST says. This branch writes `email`, so trusting POST meant any caller
  // could repoint another member's address at their own and take the account.
  if (!isset($_SESSION['username'])) { header('Location: /?error=100'); exit; }
  $uid_q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
  $uid_q->execute([':email' => $_SESSION['username']]);
  $uid = $uid_q->fetchColumn();
  if (!$uid) { header('Location: /?error=100'); exit; }

  $email = trim((string)($_POST['email'] ?? ''));
  $name     = $_POST['uname'] ?? '';
  $phone    = trim((string)($_POST['phone'] ?? '')) ?: null;
  $website  = trim((string)($_POST['website'] ?? '')) ?: null;
  $lb       = normalize_lb($_POST['lb'] ?? '');
  $position = $_POST['position'] ?? null;
  $roles_in = $_POST['roles'] ?? [];
  if (!is_array($roles_in)) $roles_in = [];

  // Nothing stopped two accounts sharing an address, and ctx_me() resolves a
  // member by email with LIMIT 1 — so the loser of that collision would start
  // signing in as the winner.
  $taken = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email AND `id` <> :uid LIMIT 1");
  $taken->execute([':email' => $email, ':uid' => $uid]);
  if ($email === '' || $taken->fetchColumn()) { header('Location: /dashboard?error=104'); exit; }

  $stmt = $conn->prepare("UPDATE `users` SET `email` = :email, `name` = :name, `phone` = :phone, `website` = :website, `lb` = :lb, `position` = :position WHERE `id` = :uid");
  $stmt->execute([
    'email' => $email,
    'name' => $name,
    'phone' => $phone,
    'website' => $website,
    'lb' => $lb,
    'position' => $position,
    'uid' => $uid
  ]);

  ctx_save_user_roles($conn, $uid, $roles_in);

  ?>
    <script type="text/javascript" language="javascript">
      window.location="../dashboard"
    </script>
  <?php
}
else if($action==="firstcontact") {
  // look up uid from session — more reliable than trusting a POST'd value
  $uid_q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
  $uid_q->execute([':email' => $_SESSION['username']]);
  $uid = $uid_q->fetchColumn();
  $name     = trim((string)($_POST['uname'] ?? ''));
  $phone    = trim((string)($_POST['phone'] ?? '')) ?: null;
  $website  = trim((string)($_POST['website'] ?? '')) ?: null;
  $lb       = normalize_lb($_POST['lb'] ?? '');
  $position = $_POST['position'] ?? null;
  $roles_in = $_POST['roles'] ?? [];
  if (!is_array($roles_in)) $roles_in = [];
  $roles_in = array_values(array_diff($roles_in, ['0', '']));

  if (empty($roles_in) || $name === null || $name === '') {
    ?>
      <script type="text/javascript" language="javascript">
        window.location="/?error=106"
      </script>
  <?php
  exit;
  }

  $stmt = $conn->prepare("UPDATE `users` SET `name` = :name, `phone` = :phone, `website` = :website, `lb` = :lb, `position` = :position WHERE `id` = :uid");
  $stmt->execute([
    'name' => $name,
    'phone' => $phone,
    'website' => $website,
    'lb' => $lb,
    'position' => $position,
    'uid' => $uid
  ]);

  ctx_save_user_roles($conn, $uid, $roles_in);

  ?>
    <script type="text/javascript" language="javascript">
      window.location="/"
    </script>
<?php
}
else if($action==='activateacct') {
  // Requires a session. The uid comes from it, never from POST — previously
  // any caller could name an arbitrary uid and flip its active flag.
  if (!isset($_SESSION['username'])) { header('Location: /?error=100'); exit; }

  $uid_q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
  $uid_q->execute([':email' => $_SESSION['username']]);
  $uid = $uid_q->fetchColumn();
  if (!$uid) { header('Location: /?error=100'); exit; }

  // Bound, not interpolated.
  $code = $_POST['code'] ?? '';
  // `active = 1` to match the signup branch — a retired code used to still work here.
  $code_q = $conn->prepare("SELECT `code` FROM `codes` WHERE `code` = :code AND `active` = 1 LIMIT 1");
  $code_q->execute([':code' => $code]);

  if ($code_q->fetchColumn() !== false) {
    $stmt = $conn->prepare("UPDATE `users` SET `active` = 1 WHERE `id` = :uid");
    $stmt->execute([':uid' => $uid]);
    header('Location: /'); exit;
  }

  // A wrong code now simply fails. It used to set active = 0, which meant a
  // bad submission could deactivate an already-active account.
  header('Location: /?error=108'); exit;
}
else if($action==='logout') {
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$p = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
	}
	session_destroy();
	?>
  <script type="text/javascript" language="javascript">
    window.location="../dashboard"
  </script>
  <?php
}
?>
