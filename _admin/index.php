<?php
error_reporting(-1);
require '../database.php';
  $sql = $conn->prepare("SELECT * FROM `films` WHERE `active` = 1 ORDER BY `id` DESC");
  $sql->execute();
  $sql2 = $conn->prepare("SELECT * FROM `films` WHERE `active` = 1 ORDER BY `id` DESC");
  $sql2->execute();
  $sql1 = $conn->prepare("SELECT * FROM `films` WHERE `active` = 1 ORDER BY `id` DESC");
  $sql1->execute();
  $sql3 = $conn->prepare("SELECT * FROM `films`  WHERE `active` = 1 ORDER BY `id` DESC");
  $sql3->execute();
  $films_count = $conn->query("SELECT count(*) FROM `films` WHERE `active` = 1")->fetchColumn();
?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/images/icons/icon.png" type="image/x-icon"/>
  <link rel="stylesheet" href="../css/sass.css?v=2" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <script src="https://use.fontawesome.com/80ad235f22.js"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script src="../js/script.js"></script>
  <script src="../js/script-jlm.js"></script>
  <title>Theatre 1</title>
</head>
<body id="" onload="theatre_1(<?php echo $film['showtime'].','.$film['duration']; ?>)">

  <!--a href="../"><div class="back-button">&larr;</div></a-->

  <div class="main-content">
  <?php include '../header.php'; ?>
  <div class="home-base">
    <style>
      table tr td {
        padding: 25px;
        background-color: rgba(0,0,0,.15);
        opacity: .65;
        transition: background-color .25s ease, opacity .25s ease;
      }
      table tr td:hover {
        background-color: rgba(82, 125, 168, .35);
        opacity: 1;
      }
      table tr td:active {
        background-color: rgba(82, 125, 168, .35);
        opacity: 1;
      }
    </style>

    <table class="adminPanel">

    <tr>
    <td>
    <form id="uploadForm" action="upload.php" method="post" enctype="multipart/form-data">
      <input name="title" type="text" placeholder="Title" /> <input name="director" type="text" placeholder="director" /><br>
      <input name="wiki" type="text" placeholder="Wiki" /> <input name="program" type="text" placeholder="selected by..." /><br>
      video:<input name="film" id="fileInput" type="file" placeholder="MP4" /><br>
      poster:<input name="poster" type="file" placeholder="PNG" /><br>
      <progress id="uploadProgress" value="0" max="100"></progress> <span id="estimatedTime"></span>
      <input id="uploadButton" type="submit" value="Upload" /><br>
    </form>
    </td>

    <td>
    <form action="note.php" method="post">
      <select name="film_id">
        <?php
        for($i = 0;$i < $films_count ;++$i) {
          $film = $sql1->fetch();
          $name = $film['title'];
          echo '<option value="'.$film['id'].'">'.$name.'</option>';
        }
        ?>
      </select><br>
      <textarea name="note" placeholder="note.."></textarea><br>
      <input type="submit" value="add note" />
    </form>
    </td>
    </tr>

    <tr>
    <td>
    <form action="showtime.php" method="post">
      <select name="film_id">
        <?php
        for($i = 0;$i < $films_count ;++$i) {
          $film = $sql->fetch();
          $name = $film['title'];
          $showtime = date('h:ia',$film['showtime']);
          echo '<option value="'.$film['id'].'">'.$name.'</option>';
        }
        ?>
      </select>
      <br>
      <select name="theatre">
        <option value="1">theatre 1</option>
        <option value="2">theatre 2</option>
      </select>
      <input name="showtime" type="datetime-local" /> <br>
      <input type="submit" value="creat showtime" />
    </form>
    </td>

    <td>
      <form action="delete.php" method="post" onsubmit="return confirm('are you sure?');">
      <select name="film_id">
        <?php
        for($i = 0;$i < $films_count ;++$i) {
          $film = $sql3->fetch();
          $name = $film['title'];
          $showtime = date('h:ia',$film['showtime']);
          echo '<option value="'.$film['id'].'">'.$name.'</option>';
        }
        ?>
      </select>
      <input type="submit" value="delete" />
      </form>
    </td>
    </tr>

    </table>
  </div>
</div>

</body>
</html>
