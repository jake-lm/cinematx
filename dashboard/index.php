<?php error_reporting(1);
session_start();
require '../database.php';
?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <meta property="og:title" content="" />
  <meta property="og:description" content="" />
  <link rel="stylesheet" href="../css/sass.scss" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="../script.js"></script>
  <title>dashboard</title>
</head>
<body id="signup" onload="cycle()">

<div class="main-content">

<?php require '../header.php'; ?>

<div class="home-base">
  <div class="content-block-w">
  <?php
  if(isset($_SESSION['username'])) { // if logged in
    $user = $_SESSION['username'];
    $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$user'");
    $sql1->execute();
    $qUser=$sql1->fetch();
  ?>
  <span class="title">Account Details</span>
  <br>
  <div class="text">
  <form action="signup.php?action=updateprof" method="post">
    <input class="input" type="text" value="<?php echo $qUser['email']; ?>" placeholder="Email" disabled />
    <input type="hidden" value="<?php echo $qUser['email']; ?>" name="email" placeholder="Email" />
    <sup><i class="fa-solid fa-circle-info hover-info">
      <div class="info-box">
        Visibile to everyone.
      </div>
    </i></sup><br>
    <input class="input" type="text" value="<?php echo $qUser['name']; ?>" name="uname" placeholder="Name" />
    <input class="input" type="tel" value="<?php echo $qUser['phone']; ?>" name="phone" placeholder="Phone" /><br>
    <select class="input" value="Department" name="dept">
      <?php
      switch($qUser['dept']){
        case '0':
        echo "<option value='0'>Select your department</option>";break;
        case 'Production':
        echo "<option value='Production'>Production</option>";break;
        case 'Camera':
        echo "<option value='Camera'>Camera</option>";break;
        case 'Sound':
        echo "<option value='Sound'>Sound</option>";break;
        case 'Locations':
        echo "<option value='Locations'>Locations</option>";break;
        case 'G&E':
        echo "<option value='G&E'>G&E</option>";break;
        case 'Art':
        echo "<option value='Art'>Art</option>";break;
        case 'Hair & Make-up':
        echo "<option value='Hair & Make-up'>Hair & Make-up</option>";break;
        case 'Casting':
        echo "<option value='Casting'>Casting</option>";break;
        case 'Editing':
        echo "<option value='Editing'>Editing</option>";break;
        case 'VFX':
        echo "<option value='VFX'>VFX</option>";break;
        case 'Other':
        echo "<option value='Other'>Other</option>";break;
      }
       ?>
      <option value="Production">Production</option>
      <option value="Camera">Camera</option>
      <option value="Sound">Sound</option>
      <option value="Locations">Locations</option>
      <option value="G&E">G&E</option>
      <option value="Art">Art</option>
      <option value="Hair & Make-up">Hair & Make-up</option>
      <option value="Casting">Casting</option>
      <option value="Editing">Editing</option>
      <option value="VFX">VFX</option>
      <option value="Other">Other</option>
    </select>
    <input class="input" value="<?php echo $qUser['position']; ?>" type="text" name="position" placeholder="Specify position" /><br>
    <input class="input" type="text" value="<?php echo $qUser['website']; ?>" name="website" placeholder="Website" />
    <input class="input" type="text" value="<?php echo $qUser['lb']; ?>" name="lb" placeholder="Letterboxd" /><br>
    <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" />
    <input type="submit" class="submit" value="Update" />
  </form>


  <!--i class="fa-solid fa-toggle-on"></i-->

  </div>
  <?php
} else { // if not
    $error = $_GET['error'];
    if($error === "100") { // login issue
      echo "<div class='error'>Retry email or password.</div><br>";
    }
    else if($error === "102") { // sign up issue
      echo "<div class='error'>Registration error.</div><br>";
    }
    else if($error === "104") { // sign up issue
      echo "<div class='error'>Email already registered.</div><br>";
    }
  ?>
    <div class="title">Sign up</div>
    <br>
    <div class="text" style="padding:0;margin:0;">
    <form action="signup.php?action=signup" method="post" enctype="multipart/form-data">
      <input class="input" type="text" name="email" placeholder="Email" />
      <!--input class="input" type="text" name="code" placeholder="Access Code">
      <sup><i class="fa-solid fa-circle-info hover-info">
        <div class="info-box">
          Limited access without code.<br>
          <a href="/about" target="_blank">Learn more.</a>
        </div>
      </i></sup></input-->
      <br>
      <input class="input" type="password" name="pw" placeholder="Password" />
      <input class="input" type="password" name="pw2" placeholder="Confirm Password" />
      <sup><i class="fa-solid fa-circle-info hover-info">
        <div class="info-box">
          Encrypted with php.<br>
          <a href="https://www.php.net/manual/en/function.crypt.php" target="_blank">Learn more.</a>
        </div>
      </i></sup>
      <br>
      <input type="submit" class="submit" value="Sign up" />
    </form>
    </div>
  <?php } ?>
  </div>
  <!--div class="content-block-t">
    <div class="main-piece">
      <div class="main-img" style="background-image: url('img/');">
        <div class="overlay"></div>
      </div>
    </div>
  </div-->

</div>

</div>

</body>
</html>
