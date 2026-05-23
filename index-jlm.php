<?php
error_reporting(-1);
require 'database.php';

$now = time();
$today = strtotime('today', $now);  $today = $today + 21600;
$tomorrow = $today + 86400;
  /*$sql = $conn->prepare("SELECT * FROM films WHERE showtime >
    $today AND showtime < $tomorrow  ORDER BY showtime DESC");
  $sql->execute();
  $film=$sql->fetch();
  $showtime = date('h:ia',$film['showtime']);*/

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
    }

  //$sql2 = $conn->prepare("SELECT * FROM `films` WHERE motw = 1 LIMIT 1");
  //$sql2->execute();
  //$motw = $sql2->fetch();
  //$m_id = $motw['id'];

  //$sql3 = $conn->prepare("SELECT * FROM `showtimes` WHERE `f_id` = $m_id AND $now < `endtime` ORDER BY `showtime` ASC");
  //$sql3->execute();
  //$motw_count = $conn->query("SELECT count(*) FROM `showtimes` WHERE `f_id` = $m_id AND $now < `endtime`")->fetchColumn();

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




?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width" />
  <meta property="og:title" content="The Cinema" />
  <meta property="og:description" content="First ever real-time virtual cinema, probably." />
  <link rel="stylesheet" href="css/sass.scss" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <script src="https://use.fontawesome.com/80ad235f22.js"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="/js/script.js"></script>
  <title>The Cinema</title>
</head>
<body id="index" onload="cycle()">

  <?php include 'header.php'; ?>

  <a href="/th1"><div class="motw">
    <span class="stamp">Movie of the Week</span>
    <div class="banner" style="background-image:url('/motw/<?php echo $motw['poster']; ?>.png')">
      <div class="overlay">
        <span class="txt">Showtimes</span><br>
        <?php
        for($i = 0;$i < $motw_count ;++$i) {
          $motw_time = $sql3->fetch();
          $m_showtime = Date('D, h:ia', $motw_time['showtime']);
          echo '<span class="subtxt s'.$i.'">'.$m_showtime.'</span><br>';
        }
        ?>
      </div>
    </div>
     <span class="title"><?php echo $motw['title']; ?></span>
     <span class="subtitle">by <?php echo $motw['director']; ?></span>
     <div class="overlay"></div>
  </div></a>

  <div class="main-content">

    <div class="top-bar">
      <span class="welcome">
        Bienvenidos al Cine
      </span>
    </div>


    <div class="left-hold left-cc">


      <div class="content-box blue" onclick="window.location='/th1';">
        <div class="lower-bar" style="text-align:right;">
          <span class="txt">Theatre 1</span>
        </div>
        <div class="info-txt">

        </div>
        <div class="since-txt"><i>
          <?php
          for($i = 0;$i < $films_count1 ;++$i) {
            $showtime1 = $sql1->fetch();
            $f_id1 = $showtime1['f_id'];
            $sql4 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id1");
            $sql4->execute();
            $film1=$sql4->fetch();
            $showtime2 = date('g:ia',$showtime1['showtime']);
            echo $film1['title'] . ' ('.$showtime2.')<br>';
          }
          ?>
        </i></div>
        <div class="bg-gradient"></div>
        <div class="bg-video-poster"
        style="background-image:url('/motw/<?php echo $film_th1['poster']; ?>.png');"></div>
        <div class="uBorder"></div>

      </div>

      <div class="content-box red" onclick="window.location='/th2';">
        <div class="lower-bar" style="text-align:right;">
          <span class="txt">Theatre 2</span>
        </div>
        <div class="since-txt"><i>
          <?php
          for($i = 0;$i < $films_count ;++$i) {
        		$showtime = $sql->fetch();
            $f_id = $showtime['f_id'];
            $sql2 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
            $sql2->execute();
            $film=$sql2->fetch();
            $showtime2 = date('g:ia',$showtime['showtime']);
            echo $film['title'] . ' ('.$showtime2.')<br>';
          }
          ?>
        </i></div>
        <div class="bg-gradient">
        </div>
        <div class="bg-video-poster" style="background-image:url('/motw/<?php echo $film_th2['poster']; ?>.png');">

        </div>
        <div class="uBorder"></div>

      </div>

    <!-- youse donts sees nothins -->

    <!--div class="content-box blue" onclick="window.location='/info';">
      <div class="lower-bar" style="text-align:right;">
        <span class="txt">Info</span>
      </div>
      <div class="info-txt">
        <b><a href="tel:+18176890261">+1 817.689.0261</a><br>
        <a href="mailto:jake.loyd.martinez@gmail.com">jake.loyd.martinez@gmail.com</a><br>
        6pm-2am cdt</b><br>
      </div>
      <div class="since-txt"><i>since 1995</i></div>
      <div class="bg-gradient"></div>
    </div>

    <div class="content-box red" onclick="window.location='/film';">
      <div class="lower-bar" style="text-align:right;">
        <span class="txt">Film</span>
      </div>
      <div class="since-txt"><i>cold like cannoli</i></div>
      <div class="bg-gradient"></div>
      <div class="bg-video-still" style="background-image:url('/images/clc.png');">

      </div>
    </div>

    <br-->

    <!--<div class="content-box purple" onclick="window.location='/design';">
      <div class="lower-bar" style="text-align:right;">
        <span class="txt">Design</span>
      </div>
      <div class="since-txt"><i>breathing omniscience</i></div>
      <div class="bg-gradient"></div>
      <div class="photos">
        <div class="left" style="background-image:url('/images/2-w.png');"></div>
        <div class="right-1" style="background-image:url('/images/josh-cosmo.jpeg');"></div><br>
      </div>
    </div>-->

    <!--div class="content-box black" onclick="window.location='/notes';">
      <div class="lower-bar" style="text-align:right;">
        <span class="txt" style>Notes</span>
      </div>
      <div class="since-txt"><i></i></div>
      <div class="bg-gradient"></div>
      <div class="photos">
      </div>
    </div-->

    <!--<div class="content-box green" onclick="window.location='/music';">
      <audio controls class="bg-audio">
        <source src="/music/austin.mp3" type="audio/mpeg" />
      </audio>
      <div class="lower-bar" style="text-align:right;">
        <span class="txt">Music</span>
      </div>
      <div class="since-txt"><i>behind closed doors</i></div>
      <div class="bg-gradient"></div>
      <div class="bg-photo" style="background-image:url(/images/austin.jpg);"></div>
    </div>-->

    </div>

    <div class="right">

      <!--<div class="quote-container">

        <div class="quote-txt">
          <sup><i style="font-size:12px;" class="fa fa-quote-left"></i></sup>
            <span class="quote-text"></span>
          <sub><i style="font-size:12px;" class="fa fa-quote-right"></i></sub>
        </div>

        <div class="quote-author">

        </div>
        <a class="quote-source" href="" target="_blank">Source</a>
      </div>-->
    </div>

  </div>

</body>
</html>
