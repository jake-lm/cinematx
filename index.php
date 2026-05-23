<?php
error_reporting(0);
session_start();
require 'database.php';

// ******* theatre support start ------------------------------

$now = time();
$today = strtotime('today', $now);  $today = $today + 21600;
$tomorrow = $today + 86400;

// $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // set the PDO error mode to exception

$sql_q1 = $conn->query("SELECT * FROM `showtimes` WHERE `showtime` >
    $now AND `showtime` < $tomorrow AND `theatre` = 2"); // select from now to tomorrow.

  if(! $sql_q1) { // if it doesn't exist

  }
  else { // if it does
    $sql = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` >
      $now AND `showtime` < $tomorrow AND `theatre` = 2 ORDER BY `showtime` ASC"); // select from now (endtime) to tomorrow.
    $sql->execute();
    $films_count = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `showtime` < $tomorrow AND `theatre` = 2")->fetchColumn();
  }

  $sql_q2 = $conn->query("SELECT * FROM `showtimes` WHERE `endtime` >
    $now AND `showtime` < $tomorrow AND `theatre` = 1");

  if(! $sql_q2) { // if it doesn't exist

    }
  else { // if it does
      $sql1 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` >
        $now AND `showtime` < $tomorrow AND `theatre` = 1 ORDER BY `showtime` ASC");
      $sql1->execute();
      $films_count1 = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `showtime` < $tomorrow AND `theatre` = 1")->fetchColumn();
      $films_count2 = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `theatre` = 1")->fetchColumn();
    }
    $sql_now1 = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
    $playing1 = $sql_now1->fetch();
  
    $sql_next1 = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 LIMIT 1");
    $playing_next1 = $sql_next1->fetch();
  
    $sql_now2 = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2");
    $playing2 = $sql_now2->fetch();
  
    $sql_next2 = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 LIMIT 1");
    $playing_next2 = $sql_next2->fetch();

  
    if(! $playing1) {
      if(! $playing_next1) {
        // Why aren't any films next?
      }
      else {
        $sql9 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
        $sql9->execute();
        $showtime9 = $sql9->fetch();
        $f_idth1 = $showtime9['f_id'];
        $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
        $sql6->execute();
        $film_th1 = $sql6->fetch();
      }
    }
    else {
      $sql5 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
      $sql5->execute();
      $showtime5 = $sql5->fetch();
      $f_idth1 = $showtime5['f_id'];
      $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
      $sql6->execute();
      $film_th1 = $sql6->fetch();
    }
  
    if(! $playing2) {
      if(! $playing_next2) {
        // Why aren't any films next?
      }
      else {
        $sql10 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 ORDER BY `showtime` ASC LIMIT 1");
        $sql10->execute();
        $showtime10 = $sql10->fetch();
        $f_idth1 = $showtime10['f_id'];
        $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
        $sql6->execute();
        $film_th2 = $sql6->fetch();
      }
    }
    else {
      $sql7 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2");
      $sql7->execute();
      $showtime6 = $sql7->fetch();
      $f_idth2 = $showtime6['f_id'];
      $sql8 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth2");
      $sql8->execute();
      $film_th2 = $sql8->fetch();
    }

    $sql12_film = $film_th1['id'];

    $sql12 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` > :now AND `theatre` = 1 AND `f_id` = :film_id ORDER BY `showtime` ASC");
    $sql12->bindValue(':now', $now, PDO::PARAM_STR);
    $sql12->bindValue(':film_id', $sql12_film, PDO::PARAM_INT);
    $sql12->execute();

// ******* theatre support end ----------------------------

if(isset($_SESSION['username'])) {
$user = $_SESSION['username'];
$sql11 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$user'");
$sql11->execute();
$qUser=$sql11->fetch();
$isName = isset($qUser['name']);

$users_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1")->fetchColumn();
if($users_count < $limit) {
  $limit = $users_count;
}

$view = $_GET['view'];
$limitview = $_GET['limit'];
$limit = 25;
switch($limitview){ // replace w/function
  case $users_count: $limit = $users_count;
  case 25: $limit=50; break;
  case 50: $limit=75; break;
  case 75: $limit=100; break;
  case 100: $limit=125; break;
  case 125: $limit=150; break;
  case 150: $limit=175; break;
  case 175: $limit=200; break;
  case 200: $limit=225; break;
}

$limiter = 8;

$qDept = $qUser['dept'];

$sql2 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY `sign_date` DESC LIMIT " . $limit);
$sql2->execute();

$sql3 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` = '$qDept' ORDER BY `sign_date` DESC");
$sql3->execute();
$dept_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != ''")->fetchColumn();


$fName = substr($qUser['name'],0,strrpos($qUser['name'], " ")); //isolate first name
if($fName == "") $fName = null;
}
?>
<html onload="cycle()">
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta property="og:title" content="" />
  <meta property="og:description" content="" />
  <link rel="stylesheet" href="css/sass.scss" />
  <!--link rel="stylesheet" href="css/sass-jlm.scss" /-->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="js/script.js"></script>
  <title>Cinema, TX</title>
</head>
<body id="index" onload="cycle()">

<div class="main-content">

<?php require 'header.php'; ?>

<div class="home-base">

  <div class="content-block-w">
    <?php
    if(isset($_SESSION['username'])){ // if logged in
    if($qUser['name'] != "" || $isName = false) {
      echo '<div class="title">Welcome, '.$qUser['name'].'.</div>';
    }
    else {
      echo '<div class="title">Welcome.</div>';
    }
    if($qUser['dept'] == null || $qUser['dept'] == "0" || $qUser['name'] == null || $qUser['name'] == "" || $qUser['position'] == null || $qUser['position'] == "") { // if info is empty
      $error = $_GET['error'];
      if($error === "106") { // login issue
        echo "<div class='error'>Please enter a Department, Position, and Name.</div><br>";
      }
    ?>
    <form action="dashboard/signup.php?action=firstcontact" method="post">
      <div class="info-hold">
        <div class="smallbox">
          Please let us know your most focused department,
          and position most often filled (or aspired toward).
          <br><br>
          Required to proceed.
        </div>

        <div class="input-hold">
          <select class="input-2" value="Department" name="dept">
            <option value="0">Select your department:</option>
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
          <!--sup><i class="fa-solid fa-circle-info hover-info">
          <div class="info-box">
            Can be updated later.
          </div>
        </i></sup-->
          <input class="input-2" type="text" name="position" placeholder="Specify position*" />
        </div>
      </div>

      <br>

      <div class="info-hold">
        <div class="smallbox">
          Tell us a little about yourself.
          <br><br>
          (name is required)
        </div>
      <div class="input-hold">
        <input class="input-2" value="<?php echo $qUser['name']; ?>" type="text" name="uname" placeholder="Name*" />
        <!--sup><i class="fa-solid fa-circle-info hover-info">
          <div class="info-box">
            Doesn't have to be a real one.
          </div>
        </i></sup-->
        <input class="input-2" value="<?php echo $qUser['phone']; ?>" type="tel" name="phone" placeholder="Phone" />
        <input class="input-2" value="<?php echo $qUser['website']; ?>" type="text" name="website" placeholder="Website" />
        <input class="input-2" value="<?php echo $qUser['lb']; ?>" type="text" name="lb" placeholder="Letterboxd" />
        <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" /><br>
        <input type="submit" class="submit" value="Continue" />
      </div>
      </div>
    </form>
    <?php
    }
    else {

    if($qUser['active'] == 0) { // if w/o access
      ?>
      <form action="dashboard/signup.php?action=activateacct" method="post">
        <div class="info-hold">

            <div class="smallbox">
              Please submit an access code to view and be included on the list.
              If you don't have one, feel free to email us or search around your
              local coffee shop.
            </div>

            <div class="input-hold">

          <input class="input-2" type="text" name="code" placeholder="Access code" /><br>
          <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" />
          <input class="submit" value="Submit" type="submit" />

            </div>
        </div>

      </form>
      <?php
    }
    else {
      ?>
      <div class="thelist">
        <span class="subtitle">Sort By:
          <select id="loadsort" class="subtle" onchange="loadSort()">
            <option name="sign_date">New</option>
            <option name="last_date">Most Active</option>
            <option name="dept" value="<?php echo $qUser['dept'];?>">My Dept</option>
            <option name="mylist">My List</option>
            <!--option>My List</option-->
          </select>
          &nbsp;
          <input class="subtle list_search" name="list_search" placeholder="Search" onkeyup="list_search()" />
          <sup><i class="fa-solid fa-circle-info hover-info">
            <div class="info-box" style="width:175px;text-align:left;">
              Ex. area codes, names, or departments.
            </div>
          </i></sup>
        </span>
        <div class="list_entry">
          <?php
          for($i = 0;$i < $limiter ;++$i) {
            $lUser = $sql2->fetch();
            $name = $lUser['name'];

            if ($i % 2 == 0 )
              $type = "odd";
            else
              $type = "even";

              include 'entries.php';

            }
          ?>
        </div>
        <div id="reContain"></div>

        <div class="loadmore-hold">
          <button class="entry loadmoretxt" onclick="loadMore()">Load more</button>
        </div>
      </div>
      <br>
      <!--div class="thelist">
        <span class="subtitle">More from <?php// echo $dName; ?></span>
      <?php
      /*for($i = 0;$i < $limiter ;++$i) {
        $cUser = $sql3->fetch();
        $name = $cUser['name'];
        echo '<div class="entry"><b class="name">' . $name . '</b><i class="fa-solid fa-envelope hover-info email"><div class="info-box">'.$cUser['email'].'</div></i><i class="fa-solid fa-phone hover-info phone"><div class="info-box">'.$cUser['phone'].'</div></i><i class="position">' . $cUser['position'] . '</i></div>';
      }*/
      ?>
      <div class="entry">Load More</div>
    </div-->
      <?php
    }
    }
    }
    else {
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
    <div class="title"><span class="welcome">Welcome.</span></div>
    <br>
    <div class="text">
      New movies <i>every night</i>.<br>
      <!--Monthly publishings weekly.<br-->
      Streamed simultaneously for everyone online.<br>  
      Become a member to access <a href="/about">our directory</a>.
      <br><br>
      <!--a href="/dashboard">Sign up here</a>.<br-->
    </div>
    <!--div class="title">Sign up</div-->

    <br><br>

    <div class="content-box blue" onclick="window.location='/th1';">
        <div class="lower-bar" style="text-align:right;">
          <span class="txt">The Theatre</span>
        </div>
        <div class="info-txt">

        </div>
        <div class="since-txt">
          <?php
          for($i = 0;$i < $films_count1 ;++$i) {
            $showtime1 = $sql1->fetch();
            $f_id1 = $showtime1['f_id'];
            $sql4 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id1");
            $sql4->execute();
            $film1=$sql4->fetch();
            $showtime2 = date('g:ia',$showtime1['showtime']);
            echo $film1['title'] . ' - '.$showtime2.'<br>';
          }
          ?>
        </div>
        <div class="bg-gradient"></div>
        <div class="bg-video-poster"
        style="background-image:url('/motw/<?php echo $film_th1['poster']; ?>.png');"></div>
        <div class="uBorder"></div>

      </div>

    <?php
    }
    ?>
  </div>
  <!--div class="content-block-t">
    <div class="main-piece">
      <div class="main-img" style="background-image: url('img/');">
        <div class="overlay"></div>
      </div>
    </div>
  </div-->

  <a href="/th1"><div class="motw">
    <span class="stamp"><?php echo $motw['title']; ?></span>
    <div class="banner" id="dynamicBanner" style="background-image:url('/motw/<?php echo $film_th1['poster']; ?>.png')">
      <div class="overlay">
        <span class="txt">Showtimes</span><br>
        <?php
        for($i = 1;$i <= 1 ;++$i) {
          $showtime3 = $sql12->fetch();
            $f_id3 = $showtime3['f_id'];
            $sql13 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id1");
            $sql13->execute();
            $film3=$sql13->fetch();
            $showtime4 = date('D, h:ia',$showtime3['showtime']);
            echo '<span class="subtxt">'.$showtime4.'</span>'; echo '<br>';
        }
        ?>
      </div>
    </div>
     <span class="title" id="film-title"><?php echo $film_th1['title']; ?></span>
     <span class="subtitle"><?php echo $film_th1['director']; ?></span>
     <div class="overlay"></div>
  </div></a>

</div>

</div>

</body>
</html>
