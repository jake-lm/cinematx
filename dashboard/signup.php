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

  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$user'");
  $sql1->execute();
  $qUser = $sql1->fetch();

  $pwv = password_verify($pass, $qUser['password']);

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
  $name = null;
  $pass = $_POST['pw'];
  $cPass = $_POST['pw2'];
  $email = $_POST['email'];
  $phone = null;
  $website = null;
  $sign_date = time();
  $lb = null;
  $dept = 0;
  $position = null;
  $active = 0;
  //$code = $_POST['code'];

  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$email'");
  $sql1->execute();
  $qUser = $sql1->fetch();

  if(is_array($qUser)) {
    ?>
    <script type="text/javascript" language="javascript">
      window.location="/dashboard/?error=104"
    </script>
    <?php
    exit;
  }

  if($email === "" || $pass === "" || $pass != $cPass) {
    ?>
    <script type="text/javascript" language="javascript">
      window.location="/dashboard/?error=102"
    </script>
    <?php
    exit;
  }

  /*$sql2 = $conn->prepare("SELECT * FROM `codes` WHERE `code` = '$code'");
  $sql2->execute();
  $code_q=$sql2->fetch();

  if($code_q['code'] === $code) {
    $active = 1;
  }
  else {
    $active = 0;
  }*/

  $hash = password_hash($pass, PASSWORD_DEFAULT);


  $stmt = $conn->prepare("INSERT INTO `users` (name, email, password, phone, website, lb, dept, position, active, sign_date, last_date)
                         VALUES (:name, :email, :password, :phone, :website, :lb, :dept, :position, :active, :sign_date, :last_date)");

  $stmt->bindParam(':name', $name);
  $stmt->bindParam(':email', $email);
  $stmt->bindParam(':password', $hash);
  $stmt->bindParam(':phone', $phone);
  $stmt->bindParam(':website', $website);
  $stmt->bindParam(':lb', $lb);
  $stmt->bindParam(':dept', $dept);
  $stmt->bindParam(':position', $position);
  $stmt->bindParam(':active', $active);
  $stmt->bindParam(':sign_date', $sign_date);
  $stmt->bindParam(':last_date', $sign_date);

  $stmt->execute();

  $_SESSION['logged'] ="yes";
  $_SESSION['username']=$email;
?>
  <script type="text/javascript" language="javascript">
    window.location="../"
  </script>
<?php
}
else if($action==="updateprof") {
  $uid = $_POST['uid'];
  $email = $_POST['email'];
  $name = $_POST['uname'];
  $phone = $_POST['phone'];
  $website = $_POST['website'];
  $lb = $_POST['lb'];
  $dept = $_POST['dept'];
  $position = $_POST['position'];

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
  $uid = $_POST['uid'];
  $name = $_POST['uname'];
  $phone = $_POST['phone'];
  $website = $_POST['website'];
  $lb = $_POST['lb'];
  $dept = $_POST['dept'];
  $position = $_POST['position'];

  //echo $dept; echo $position; echo $name;
  //exit;

  if($dept == "0" || $position == "" || $position == null || $name == null || $name == "") {
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
  $code = $_POST['code'];
  $uid = $_POST['uid'];
  $sql2 = $conn->prepare("SELECT * FROM `codes` WHERE `code` = '$code'");
  $sql2->execute();
  $code_q=$sql2->fetch();

  if($code_q['code'] === $code) {
    $active = 1;
  }
  else {
    $active = 0;
  }

  $stmt = $conn->prepare("UPDATE `users` SET `active` = :active WHERE `id` = :uid");
  $stmt->execute(
    array(
      'active' => $active,
      'uid' => $uid
    )
  );

  ?>
  <script type="text/javascript" language="javascript">
    window.location="../"
  </script>
  <?php

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
