<div class="menu">
  <a href="/"><div class="block index">
    <i class="icon fa-solid fa-house"></i>
    <span class="content">Index</span>
  </div></a>
  <a href="/about"><div class="block about">
    <i class="icon fa-solid fa-circle-info"></i>
    <span class="content">About</span>
  </div></a>
  <a href="/list"><div class="block list">
    <i class="icon fa-solid fa-film"></i>
    <span class="content">The list</span>
  </div></a>

    <?php
    if(isset($_SESSION['username'])) {
     ?>
     <a href="/dashboard"><div class="block signup">
       <i class="icon fa-solid fa-gauge"></i>
       <span class="content">Dashboard</span>
     </div></a>
     <a href="/directory"><div class="block directory">
       <i class="icon fa-solid fa-users"></i>
       <span class="content">Directory</span>
     </div></a>

     <form action="/dashboard/signup.php?action=logout" method="post">
       <button type="submit" class="block logout-btn">
         <i class="icon fa-solid fa-arrow-right-from-bracket"></i>
         <span class="content">Logout</span>
       </button>
     </form>
    <?php
    }
    else {
    ?>
    <div class="text">
    <form action="/dashboard/signup.php?action=login" method="post" enctype="multipart/form-data">
      <input class="input-u" type="text" name="email" placeholder="Email" />
      <input class="input-u" type="password" name="pw" placeholder="Password" /> <br>

      <input type="submit" class="submit-u" value="Sign in" />
    </form>
    </div>
    <?php
    }
    ?>
  <div class="social">
    <a href="#"><i class="fa-brands fa-discord fa"></i></a>
    &nbsp;
    <a href="#"><i class="fa-brands fa-instagram fa"></i></a>
  </div>
</div>
<div class="top-bar">
  <div class="underlay"></div>
  <div class="right-hold"></div>
  <div class="top-hold">
  <div class="head-txt"><span class="bgcolor">Cinema, TX</span></div>
  <span class="welcome">Welcome to the Cinema</span>
  <div class="menu-hold"><span class="menu-btn">
    <i class="fa-solid fa-bars"></i>
  </span></div>
  </div>
  <?php if (isset($_SESSION['username'])): ?>
  <div class="topbar-actions">
    <button class="topbar-btn" id="topbar-notify-btn" title="Notifications — coming soon" disabled>
      <i class="fa-solid fa-bell"></i>
    </button>
    <div class="topbar-recent-wrap">
      <button class="topbar-btn" id="topbar-recent-btn" title="Recent activity">
        <i class="fa-solid fa-clock-rotate-left"></i>
      </button>
      <div class="topbar-recent-dropdown" id="topbar-recent-dropdown">
        <div class="topbar-recent-empty">Loading...</div>
      </div>
    </div>
    <button class="topbar-btn topbar-btn-create" id="topbar-create-btn" title="Create">
      <i class="fa-solid fa-plus"></i>
    </button>
  </div>
  <?php endif; ?>
</div>

<?php if (isset($_SESSION['username'])): ?>
<div class="quick-create-backdrop" id="quick-create-backdrop"></div>
<div class="quick-create-sidebar" id="quick-create-sidebar">

  <div class="qcs-header">
    <div class="qp-selector" id="qcs-selector">
      <div class="qp-selector-current" id="qcs-selector-btn">
        <span id="qcs-current-label">Essay</span>
        <i class="fa-solid fa-chevron-down qp-chevron"></i>
      </div>
      <div class="qp-selector-list" id="qcs-selector-list">
        <div class="qp-option active" data-type="essay">Essay</div>
        <div class="qp-option" data-type="review">Review</div>
        <div class="qp-option" data-type="event">Event</div>
      </div>
    </div>
    <button class="qcs-close" id="qcs-close" title="Close">&#x2715;</button>
  </div>

  <div class="qcs-body">

    <div class="qcs-mode active" id="qcs-mode-post">
      <input  type="text" id="qcs-post-title"    class="write-title"    placeholder="Title"                    autocomplete="off" />
      <input  type="text" id="qcs-post-subtitle" class="write-subtitle" placeholder="Subtitle — optional"       autocomplete="off" />
      <label class="write-featured">
        <input type="checkbox" id="qcs-post-featured" /> Featured
      </label>
      <textarea id="qcs-post-content" class="write-content" placeholder="Write something..."></textarea>
      <div class="write-image-wrap">
        <div class="write-image-row">
          <label class="write-image-btn" id="qcs-post-image-label">
            <i class="fa-solid fa-image"></i> Main image
            <input type="file" id="qcs-post-image" accept="image/jpeg,image/png,image/webp,image/gif" disabled />
          </label>
          <span class="write-image-hint" id="qcs-post-image-hint">Save a draft first</span>
        </div>
        <div class="write-image-preview" id="qcs-post-image-preview" style="display:none;">
          <img id="qcs-post-image-thumb" src="" alt="preview" />
          <button class="write-image-remove" id="qcs-post-image-remove" title="Remove image">&#x2715;</button>
        </div>
        <input type="text" id="qcs-post-photo-cred" class="write-photo-cred" placeholder="Photo credit — optional" autocomplete="off" />
      </div>
    </div>

    <div class="qcs-mode" id="qcs-mode-event">
      <input type="text" id="qcs-event-title" class="write-title" placeholder="Title" autocomplete="off" />
      <input type="text" id="qcs-event-location" class="write-subtitle" placeholder="Location — e.g. AFS Cinema, or &quot;My backyard&quot;" autocomplete="off" />
      <input type="text" id="qcs-event-address" class="write-subtitle" placeholder="Address — optional" autocomplete="off" />
      <input type="datetime-local" id="qcs-event-screentime" class="write-subtitle event-datetime" />
      <div class="write-image-wrap">
        <div class="write-image-row">
          <label class="write-image-btn" id="qcs-event-poster-label">
            <i class="fa-solid fa-image"></i> Poster
            <input type="file" id="qcs-event-poster" accept="image/jpeg,image/png,image/webp,image/gif" disabled />
          </label>
          <span class="write-image-hint" id="qcs-event-poster-hint">Save a draft first</span>
        </div>
        <div class="write-image-preview" id="qcs-event-poster-preview" style="display:none;">
          <img id="qcs-event-poster-thumb" src="" alt="preview" />
          <button class="write-image-remove" id="qcs-event-poster-remove" title="Remove poster">&#x2715;</button>
        </div>
      </div>
    </div>

  </div><!-- /.qcs-body -->

  <div class="write-footer qcs-footer">
    <span id="qcs-status" class="autosave-status"></span>
    <div class="write-actions">
      <button id="qcs-save"    class="submit write-save">Save Draft</button>
      <button id="qcs-publish" class="submit write-publish" disabled>Publish</button>
    </div>
  </div>

</div><!-- /.quick-create-sidebar -->
<script src="/js/quick-create.js?v=<?php echo filemtime(__DIR__ . '/js/quick-create.js'); ?>"></script>
<?php endif; ?>

<!--


                             ,oooooo888888888oooooo.
                        .oo88^^^^^^            ^^^^^Y8o.
                     .dP'                              `Yb.
                   dP'                                   `Yb
                 .dP'                                     `Yb.
                dP'                                         `Yb
               d8                                             8b
              ,8P                                             `8b
              88'                                              88
              88                                               88
              dP                                               88
             d8'                                               88        
             8P                                               ,dY           
           ,dP                                                88'          
          CP   ,,.....                ,,.....                 88
          `b,d8P'^^^'Y8b           ,d8P'^^^'Y8b.             ,dY
           dP'         `Yb        dP'         `Yb            88'
          dP             Yb      dP             Yb           88
         dP     db        Yb    dP     db        Yb         ,dY
         88     YP        88    88     YP        88         88'
         Yb               dP    Yb               dP         88
          Yb             dP      Yb             dP         ,dY
         dP`Yb.       ,dP'        `Yb.       ,dP'          88'
        CCo_ `YbooooodP'            `YbooooodP'            88
         dP"oo_    ,dP            Ybo__                    88
        88    "ooodP'                ""88oooooP'           88
         Yb .ood""                                        ,dY
         ,dP"                                             88'
       ,dP'                                               88
      dP'    ,dP'     ,dP       ,dP'      .bmw.           88
     d8     dP       dP        dP        o88888b          88
     88    dP       dP       ,dP       o8888888P          88
     Y8.   88      88       d8P       o8888888P          ,dY
     `8b   Yb      88       88       ,8888888P           88'
      88    Yb     Y8.      88       888888P'            88
      88    `8b    `8b      88       88                 ,dY
      88     88     88      Yb.      Yb                 88'
      Y8.   ,Y8    ,Y8      ,88      ,8b                88
       `"ooo"`"oooo" `"ooooo" `8boooooP                ,8Y
           88boo__      """       """  ____oooooooo888888
          dP  ^^""ooooooooo..oooooo"""^^^^^             88
          88               88                           88
          88               88                           88
          Yboooo__         88          ____oooooooo88888P
            ^^^"""ooooooooo''oooooo"""^^^^^

-->
