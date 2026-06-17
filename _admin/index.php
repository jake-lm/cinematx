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

    <div class="adminPanel">

      <div class="admin-panel">
        <span class="panel-label">Upload Film</span>
        <form id="uploadForm" action="/_admin/upload.php" method="post" enctype="multipart/form-data">
          <label>Title</label>
          <input class="admin-input" name="title" type="text" placeholder="Title" />
          <label>Director</label>
          <input class="admin-input" name="director" type="text" placeholder="Director" />
          <label>Wiki URL</label>
          <input class="admin-input" name="wiki" type="text" placeholder="https://en.wikipedia.org/..." />
          <label>Programmed by</label>
          <input class="admin-input" name="program" type="text" placeholder="Selected by..." />
          <label>Video file</label>
          <input class="admin-file" name="film" id="fileInput" type="file" />
          <label>Poster image</label>
          <input class="admin-file" name="poster" type="file" />
          <progress id="uploadProgress" value="0" max="100"></progress>
          <span id="estimatedTime"></span>
          <input id="uploadButton" class="admin-submit" type="submit" value="Upload" />
        </form>
      </div>

      <div class="admin-panel">
        <span class="panel-label">Add Note</span>
        <form action="/_admin/note.php" method="post">
          <label>Film</label>
          <select class="admin-select" name="film_id">
            <?php
            for($i = 0;$i < $films_count ;++$i) {
              $film = $sql1->fetch();
              $name = $film['title'];
              echo '<option value="'.$film['id'].'">'.$name.'</option>';
            }
            ?>
          </select>
          <label>Note</label>
          <textarea class="admin-textarea" name="note" placeholder="Write a note..."></textarea>
          <input class="admin-submit" type="submit" value="Add Note" />
        </form>
      </div>

      <div class="admin-panel">
        <span class="panel-label">Schedule Showtime</span>
        <form action="/_admin/showtime.php" method="post">
          <label>Film</label>
          <select class="admin-select" name="film_id">
            <?php
            for($i = 0;$i < $films_count ;++$i) {
              $film = $sql->fetch();
              $name = $film['title'];
              echo '<option value="'.$film['id'].'">'.$name.'</option>';
            }
            ?>
          </select>
          <label>Theatre</label>
          <select class="admin-select" name="theatre">
            <option value="1">Theatre 1</option>
            <option value="2">Theatre 2</option>
          </select>
          <label>Date &amp; Time</label>
          <input class="admin-input" name="showtime" type="datetime-local" />
          <input class="admin-submit" type="submit" value="Create Showtime" />
        </form>
      </div>

      <div class="admin-panel">
        <span class="panel-label">Delete Film</span>
        <form action="/_admin/delete.php" method="post" onsubmit="return confirm('Are you sure you want to delete this film?');">
          <label>Film</label>
          <select class="admin-select" name="film_id">
            <?php
            for($i = 0;$i < $films_count ;++$i) {
              $film = $sql3->fetch();
              $name = $film['title'];
              echo '<option value="'.$film['id'].'">'.$name.'</option>';
            }
            ?>
          </select>
          <input class="admin-submit admin-delete" type="submit" value="Delete Film" />
        </form>
      </div>

    </div>
  </div>
</div>

</body>
</html>
