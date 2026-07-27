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
// ═══════════════════════════════════════════════════════════════════════════

$ctx_title  = $ctx_title  ?? 'Cinema, TX';
$ctx_scroll = $ctx_scroll ?? false;
$ctx_video  = $ctx_video  ?? false;
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
<body>

<div class="app<?php echo $ctx_scroll ? ' app--scroll' : ''; ?>" id="app">
