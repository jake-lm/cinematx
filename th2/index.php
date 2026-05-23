<?php
require '../database.php';
  $now = time();
  $today = strtotime('today', $now); $today = $today + 21600;
  $tomorrow = $today + 86400;
  //$sql = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime`");
  $sql_now = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2");
  $showtime = $sql_now->fetch();

  $sql_next = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 LIMIT 1");
  $showtime_next = $sql_next->fetch();

  $sql3 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` >
    $now AND `showtime` < $tomorrow AND `theatre` = 2 ORDER BY `showtime` ASC");
  $sql3->execute();
  $films_count = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `showtime` < $tomorrow AND `theatre` = 2")->fetchColumn();

  if(! $showtime) {
    if(! $showtime_next) {
      // why aren't any films scheduled
    }
    else {
      $sql = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 ORDER BY `showtime` ASC LIMIT 1");
      $sql->execute();
      $showtime = $sql->fetch();
      $f_id = $showtime['f_id'];
      $sql2 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
      $sql2->execute();
      $film=$sql2->fetch();
      $showtime2 = date('g:ia',$showtime['showtime']);
      $noteq = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = $f_id");
      $noteq->execute();
      $note = $noteq->fetch();
    }
  }
  else {
    $sql = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2 LIMIT 1");
    $sql->execute();
    $showtime = $sql->fetch();
    $f_id = $showtime['f_id'];
    $sql2 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
    $sql2->execute();
    $film=$sql2->fetch();
    $showtime2 = date('g:ia',$showtime['showtime']);
    $noteq = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = $f_id");
    $noteq->execute();
    $note = $noteq->fetch();
  }


  //$sql->execute();
  //$showtime=$sql->fetch();

?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width" />
  <meta property="og:title" content="<?php echo $film['title'] . ' - ' . $showtime2; ?>" />
  <meta property="og:image" content="/motw/<?php echo $film['poster']; ?>.png" />
  <meta property="og:description" content="First ever real-time virtual cinema, probably." />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <link rel="stylesheet" href="/css/sass.css?v=2" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script src="/js/script.js"></script>
  <title>Theatre 2</title>
</head>
<body id="" onload="theatre_1(
  <?php
    if(isset($showtime['showtime'])){
      echo $showtime['showtime'].','.$film['dur'].',\''.$film['filename'].'\'';
    }
  ?>
)">

  <?php include '../header.php'; ?>

  <a href="../"><div class="back-button">&larr;</div></a>

  <div class="main-content">
    <div class="top-bar" style="">
      Theatre 2
      <span class="np"></span>
    </div>

    <div class="video-container">

    <div class="video-hold"><video
      id="motw"
      class="video-js"
      oncontextmenu="return false;"
      controls
      autoplay
      muted
      preload
      poster="/motw/<?php echo $film['poster']; ?>.png"
      data-setup='{"controls": true, "autoplay": true, "preload": "true"}'
      >
      <source class="v-js-s" src="/motw/" type="video/mp4" />
      <!--<track kind="captions" src="/motw/lvl/lavoielactee.srt" srclang="en" label="English" default>-->
    </video></div>
    <!--div class="np-overlay"></div-->

      <div class="video-info">
        <div class="topbar">
          <span class="title">
            <?php
              if(isset($film['title'])){
                echo $film['title']. ' - ' . $showtime2 . '';
              }
              else {
                echo 'Thanks for watching';
              }
            ?>
          </span>
          <div class="bottombar">
            <span class="directed">
              by <?php echo $film['director']; ?>
              <?php //echo gmdate('H:i',$film['dur']); ?>
            </span>
            <span class="dur"><?php echo gmdate('g\hi\m',$film['dur']); ?></span>
          </div>
        </div>

        <div class="info-txt">
          <?php echo $note['note']; ?>
          <br><br>
          <a href="/info" target="_blank">Please consider donating.</a>
        </div>

        <div class="info-txt"><div class="showtimes">
          <span class="today">Up next</span><br><br>
          <?php
          for($i = 0;$i < $films_count ;++$i) {
        		$showtime3 = $sql3->fetch();
            $f_id3 = $showtime3['f_id'];
            $sql4 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id3");
            $sql4->execute();
            $film3=$sql4->fetch();
            $showtime4 = date('g:ia',$showtime3['showtime']);
            $director = explode(' ', $film3['director']);
            echo '<span class="block"><span class="showtime">' . $showtime4.'</span> - <span class="title">' . $film3['title'] . '</span></span><br>';
          }
          ?>
        </div></div>

        <br><br>

        <div class="botbar">
          <div class="hold">
            <a href="https://discord.gg/f6psQxz" target="_blank"><i class="fab fa-discord"></i></a>
          </div>
        </div>

      </div>

      </div>


  </div>

</body>
</html>
