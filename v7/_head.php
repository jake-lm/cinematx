<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — document head and shell opening
//
//  Set before including:
//    $ctx_title   string   page title (defaults to "Cinema, TX")
//    $ctx_scroll  bool     true for content pages that scroll normally.
//                          The index is the only surface locked to one screen.
//    $ctx_video   bool     load video.js and expose the theatre sync globals
//    $ctx_theatre array    from ctx_theatre(); required when $ctx_video
//    $ctx_meta    string   extra <head> markup — Open Graph, canonical, etc.
//    $ctx_shell   string   extra class on .app — the screening rooms use this
//                          to take the whole shell dark, chrome included
// ═══════════════════════════════════════════════════════════════════════════

$ctx_title  = $ctx_title  ?? 'Cinema, TX';
$ctx_scroll = $ctx_scroll ?? false;
$ctx_video  = $ctx_video  ?? false;
$ctx_shell  = $ctx_shell  ?? '';
$root       = dirname(__DIR__);
?>
<!doctype html>
<html lang="en" prefix="og: https://ogp.me/ns#">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo ctx_e($ctx_title); ?></title>
<?php if (!empty($ctx_meta)) echo $ctx_meta; ?>
<script>try{var t=localStorage.getItem('ctx-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<link rel="stylesheet" href="/css/v7.css?v=<?php echo filemtime($root . '/css/v7.css'); ?>" />
<?php if ($ctx_video): ?>
<link rel="stylesheet" href="https://vjs.zencdn.net/7.8.3/video-js.css" />
<?php endif; ?>
<link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
<script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
<?php if ($ctx_video): ?>
<script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
<script>
  window.CTX_SHOWTIME = <?php echo (int)($ctx_theatre['show_ts'] ?? 0); ?>;
  window.CTX_DUR      = <?php echo (int)($ctx_theatre['film']['dur'] ?? 0); ?>;
  window.CTX_FILE     = <?php echo json_encode($ctx_theatre['film']['filename'] ?? ''); ?>;
</script>
<?php endif; ?>
<script src="/js/v7.js?v=<?php echo filemtime($root . '/js/v7.js'); ?>" defer></script>
</head>
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
<body>

<div class="app<?php echo $ctx_scroll ? ' app--scroll' : ''; ?><?php echo $ctx_shell ? ' ' . $ctx_shell : ''; ?>" id="app">
