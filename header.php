<div class="menu">
  <a href="/"><div class="block index">
    <span class="content">index</span>
  </div></a>
  <a href="/about"><div class="block about">
    <span class="content">about</span>
  </div></a>

    <?php
    if(isset($_SESSION['username'])) {
     ?>
     <a href="/dashboard"><div class="block signup">
       <span class="content">Dashboard</span>
     </div></a>

     <form action="/dashboard/signup.php?action=logout" method="post">
       <input type="submit" class="submit-u" value="logout" />
     </form>
    <?php
    }
    else {
    ?>
    <div class="text">
    <form action="/dashboard/signup.php?action=login" method="post" enctype="multipart/form-data">
      <input class="input-u" type="text" name="email" placeholder="Email" disabled />
      <input class="input-u" type="password" name="pw" placeholder="Password" disabled /> <br>

      <input type="submit" class="submit-u" value="Sign in" disabled />
    </form>
    </div>
    <?php
    }
    ?>
</div>
<div class="top-bar">
  <div class="underlay"></div>
  <div class="right-hold"></div>
  <div class="top-hold">
  <div class="head-txt"><span class="bgcolor">Cinema, TX</span></div>
  <div class="menu-hold"><span class="menu-btn">
    <i class="fa-solid fa-bars"></i>
  </span></div>
  </div>
</div>
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
