<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — public podcast RSS feed
//
//  Submit this URL to Spotify for Podcasters / Apple Podcasts Connect /
//  etc. Public, no auth — the whole point is for outside platforms to poll
//  it. An episode appears once posted_media_id is set (the same "this
//  episode is publicly live" signal the Instagram Reel post already sets —
//  see forecast_feed_episodes() in list/forecast.php), enclosing the
//  original uploaded audio file, not the generated video.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/config.php';
require __DIR__ . '/database.php';
require __DIR__ . '/list/forecast.php';

header('Content-Type: application/rss+xml; charset=UTF-8');

$x = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
};

$episodes = forecast_feed_episodes($conn);
foreach ($episodes as $i => &$ep) {
    $ep['episode_number'] = $i + 1;
}
unset($ep);
$episodes = array_reverse($episodes);

$showTitle = 'Film Forecast';
$showDescription = 'A weekly look at what\'s playing around Austin, from Cinema, TX — new movies, old favorites, and a guest to talk it through.';
$showImageUrl = ig_public_url('/assets/forecast-cover.png', __DIR__ . '/assets/forecast-cover.png');
$feedUrl = ig_public_url('/forecast-feed.php');
$siteUrl = rtrim(CTX_SITE_URL, '/') . '/';
$ownerEmail = 'contact.cinematx@gmail.com';
$dir = __DIR__ . '/uploads/forecast';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?php echo $x($showTitle); ?></title>
  <link><?php echo $x($siteUrl); ?></link>
  <atom:link href="<?php echo $x($feedUrl); ?>" rel="self" type="application/rss+xml" />
  <description><?php echo $x($showDescription); ?></description>
  <language>en-us</language>
  <itunes:author><?php echo $x($showTitle); ?></itunes:author>
  <itunes:owner>
    <itunes:name>Cinema, TX</itunes:name>
    <itunes:email><?php echo $x($ownerEmail); ?></itunes:email>
  </itunes:owner>
  <itunes:image href="<?php echo $x($showImageUrl); ?>" />
  <image>
    <url><?php echo $x($showImageUrl); ?></url>
    <title><?php echo $x($showTitle); ?></title>
    <link><?php echo $x($siteUrl); ?></link>
  </image>
  <itunes:category text="TV &amp; Film" />
  <itunes:explicit>false</itunes:explicit>

  <?php foreach ($episodes as $ep):
      $audioPath = $dir . '/' . $ep['audio_file'];
      if (!file_exists($audioPath)) continue;

      $audioUrl  = ig_public_url('/uploads/forecast/' . rawurlencode($ep['audio_file']), $audioPath);
      $title     = 'Week of ' . date('M j, Y', strtotime($ep['week_of'])) . ' — with ' . $ep['guest_name'];
      $pubTs     = $ep['posted_at'] ?: $ep['stamp'];
      $artPath   = forecast_ensure_podcast_art($ep, $conn);
      $imageUrl  = ig_public_url('/uploads/forecast/' . basename($artPath), $artPath);
  ?>
  <item>
    <title><?php echo $x($title); ?></title>
    <link><?php echo $x($siteUrl); ?></link>
    <description><?php echo $x(forecast_feed_description($ep)); ?></description>
    <enclosure url="<?php echo $x($audioUrl); ?>" length="<?php echo (int) filesize($audioPath); ?>" type="<?php echo $x(forecast_audio_mime($ep['audio_file'])); ?>" />
    <guid isPermaLink="false">forecast-episode-<?php echo (int) $ep['id']; ?></guid>
    <pubDate><?php echo $x(date(DATE_RSS, $pubTs)); ?></pubDate>
    <itunes:episode><?php echo (int) $ep['episode_number']; ?></itunes:episode>
    <itunes:image href="<?php echo $x($imageUrl); ?>" />
    <itunes:explicit>false</itunes:explicit>
    <?php if (!empty($ep['duration_seconds'])): ?>
    <itunes:duration><?php echo $x(forecast_rss_duration($ep['duration_seconds'])); ?></itunes:duration>
    <?php endif; ?>
  </item>
  <?php endforeach; ?>
</channel>
</rss>
