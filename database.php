<?php
date_default_timezone_set('America/Chicago');
require_once __DIR__ . '/config.php';

// ── Error policy ───────────────────────────────────────────────────────────
// One place rather than a per-file ini_set, which is how display_errors ended
// up switched on across the admin panel and in signup.php — the login
// endpoint, where a failed query would print the query and the absolute path.
//
// Errors are always logged. Whether they are shown is a config decision, and
// it defaults to off, so a production config is safe by omission rather than
// by remembering.
if (!defined('CTX_DEBUG')) define('CTX_DEBUG', false);

ini_set('log_errors', '1');
ini_set('display_errors',         CTX_DEBUG ? '1' : '0');
ini_set('display_startup_errors', CTX_DEBUG ? '1' : '0');
error_reporting(CTX_DEBUG ? E_ALL : E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

try {
  $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  // The message names the database user and host, so it goes to the log and
  // the visitor gets nothing describing our infrastructure.
  error_log('DB connection failed: ' . $e->getMessage());
  http_response_code(503);
  exit(CTX_DEBUG ? 'Connection failed: ' . $e->getMessage() : 'Service temporarily unavailable.');
}
