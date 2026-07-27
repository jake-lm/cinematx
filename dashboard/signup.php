<?php
ini_set('display_errors', 1);
session_start();
error_reporting(1);
date_default_timezone_set('America/Chicago');

require '../database.php';
$action = $_GET['action'];
if($action==='login') {
	$user = $_POST['email'];
	$pass = $_POST['pw'];
	$hash = password_hash($pass, PASSWORD_DEFAULT);

  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
  $sql1->execute([':email' => $user]);
  $qUser = $sql1->fetch();

  // Guarded: an unknown email used to index into `false` and warn.
  $pwv = $qUser ? password_verify($pass, $qUser['password']) : false;

	if($user == "" || $pass == "" || $pwv == false) {
		?>
        <script type="text/javascript" language="javascript">
		window.location="../dashboard/?error=100";
		</script>

		<?php
    exit;
	}
	else {
		$_SESSION['logged'] ="yes";
		$_SESSION['username']=$user;
    $last_date = time();
    $stmt = $conn->prepare("UPDATE `users`
          SET `last_date` = :last_date
          WHERE `email` = :email");
          $stmt->bindParam(':last_date', $last_date);
          $stmt->bindParam(':email', $user);
    $stmt->execute();
		?>
        <script type="text/javascript" language="javascript">
		window.location="../"
		</script>

		<?php

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
  $dept = 0;
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

  $stmt = $conn->prepare("INSERT INTO `users` (name, email, password, phone, website, lb, dept, position, active, sign_date, last_date)
                         VALUES (:name, :email, :password, :phone, :website, :lb, :dept, :position, :active, :sign_date, :last_date)");
  $stmt->execute([
    ':name'      => $name,
    ':email'     => $email,
    ':password'  => $hash,
    ':phone'     => $phone,
    ':website'   => $website,
    ':lb'        => $lb,
    ':dept'      => $dept,
    ':position'  => $position,
    ':active'    => $active,
    ':sign_date' => $sign_date,
    ':last_date' => $sign_date,
  ]);

  // increment code usage counter
  $conn->prepare("UPDATE `codes` SET `uses` = `uses` + 1 WHERE `id` = :id")
       ->execute([':id' => $valid_code['id']]);

  $_SESSION['logged'] = "yes";
  $_SESSION['username'] = $email;

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

  $email = $_POST['email'];
  $name = $_POST['uname'];
  $phone = $_POST['phone'];
  $website = $_POST['website'];
  $lb = $_POST['lb'];
  $dept = $_POST['dept'];
  $position = $_POST['position'] ?? null;

  $stmt = $conn->prepare("UPDATE `users` SET `email` = :email, `name` = :name, `phone` = :phone, `website` = :website, `lb` = :lb, `dept` = :dept, `position` = :position WHERE `id` = :uid");

  /*$stmt->bindParam(':email', $email);
  $stmt->bindParam(':name', $name);
  $stmt->bindParam(':phone', $phone);
  $stmt->bindParam(':website', $website);
  $stmt->bindParam(':lb', $lb);
  $stmt->bindParam(':id', $uid);*/

  $stmt->execute(
    array(
      'email' => $email,
      'name' => $name,
      'phone' => $phone,
      'website' => $website,
      'lb' => $lb,
      'dept' => $dept,
      'position' => $position,
      'uid' => $uid
    )
  );

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
  $name = $_POST['uname'];
  $phone = $_POST['phone'];
  $website = $_POST['website'];
  $lb = $_POST['lb'];
  $dept = $_POST['dept'];
  $position = $_POST['position'] ?? null;

  //echo $dept; echo $position; echo $name;
  //exit;

  if($dept == "0" || $name == null || $name == "") {
    ?>
      <script type="text/javascript" language="javascript">
        window.location="/?error=106"
      </script>
  <?php
  exit;
  }

  $stmt = $conn->prepare("UPDATE `users` SET `name` = :name, `phone` = :phone, `website` = :website, `lb` = :lb, `dept` = :dept, `position` = :position WHERE `id` = :uid");

  /*$stmt->bindParam(':email', $email);
  $stmt->bindParam(':name', $name);
  $stmt->bindParam(':phone', $phone);
  $stmt->bindParam(':website', $website);
  $stmt->bindParam(':lb', $lb);
  $stmt->bindParam(':id', $uid);*/

  $stmt->execute(
    array(
      'name' => $name,
      'phone' => $phone,
      'website' => $website,
      'lb' => $lb,
      'dept' => $dept,
      'position' => $position,
      'uid' => $uid
    )
  );

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
  $code_q = $conn->prepare("SELECT `code` FROM `codes` WHERE `code` = :code LIMIT 1");
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
	session_destroy();
	?>
  <script type="text/javascript" language="javascript">
    window.location="../dashboard"
  </script>
  <?php
}
?>
