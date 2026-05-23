<?php
  $uid = $lUser['id'];
  $fid = $qUser['id'];
  $mylist_rows = $conn->query("SELECT count(*) FROM `mylist` WHERE `uid` = '".$uid."' AND `fid` = '".$fid."'")->fetchColumn();

  echo '
  <div class="entry '.$type.'">
    <b class="name">' . $name . '</b>
    <i class="fa-solid fa-envelope hover-info-list email" onclick="copyToClip(`'.$lUser['email'].'`)">
    <div class="info-box-list"><a style="color:inherit;text-decoration:none;" href="mailto:'.$lUser['email'].'">'.$lUser['email'].'</a></div>
    </i>';

    if($lUser['phone'] != "") {
    echo '<i class="fa-solid fa-phone hover-info-list phone" onclick="copyToClip('.$lUser['phone'].')">
    <div class="info-box-list"><a style="color:inherit;text-decoration:none;" href="tel:'.$lUser['phone'].'">'.$lUser['phone'].'</a></div>
    </i>';
    }
    else {

    }

    echo '<span class="addremove'.$lUser['id'].'">';

    if($mylist_rows > 0) {
      echo '<i class="fa-solid fa-minus remove hover-infoAdd" onclick="removeFrom('.$lUser['id'].','.$qUser['id'].')">
      <span class="info-boxAdd">remove from list</span>
      </i>';
    }
    else {
      echo '<i class="fa-solid fa-plus addto hover-infoAdd" onclick="addTo('.$lUser['id'].','.$qUser['id'].')">
      <span class="info-boxAdd">add to list</span>
      </i>';
    }
    echo '</span>
    <i class="position">' . $lUser['dept'] . '</i>
  </div>';
?>
