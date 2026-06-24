<?php
// 7 Fundays Park — auto-deploy webhook.
// On a GitHub "push" (HMAC-verified) OR a manual ?key= trigger, download the repo
// and copy the deployable files into public_html — mirrors .cpanel.yml.
// content.json / cms_key.txt (outside web root) and uploads/ are never touched.
header('Content-Type: text/plain; charset=utf-8');

$CMS        = dirname(dirname(__DIR__)) . '/fundays-cms';   // /home/<user>/fundays-cms
$SECRETFILE = $CMS . '/deploy_secret.txt';
$LOG        = $CMS . '/deploy.log';
$REPO_ZIP   = 'https://codeload.github.com/bose317/fundays-park-demo/zip/refs/heads/main';
$DEST       = dirname(__DIR__);                              // public_html

function out($code, $msg){ http_response_code($code); echo $msg . "\n"; exit; }
function logln($m){ global $LOG; @file_put_contents($LOG, gmdate('c') . ' ' . $m . "\n", FILE_APPEND); }

// quick liveness check (no auth, reveals nothing sensitive)
if (isset($_GET['ping'])) out(200, 'fundays deploy alive');

$secret = is_file($SECRETFILE) ? trim(file_get_contents($SECRETFILE)) : '';
if ($secret === '') out(500, 'deploy not configured (missing deploy_secret.txt)');

// --- auth: GitHub HMAC signature, or manual ?key= ---
$raw  = file_get_contents('php://input');
$sig  = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
$ok   = ($sig && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig))
     || (isset($_GET['key']) && hash_equals($secret, (string)$_GET['key']));
if (!$ok) { logln('unauthorized'); out(403, 'unauthorized'); }

if ((isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '') === 'ping') out(200, 'pong');
if (!function_exists('curl_init')) out(500, 'curl unavailable on this server');
if (!class_exists('ZipArchive'))   out(500, 'ZipArchive unavailable on this server');

// --- download the repo zip ---
$tmp = tempnam(sys_get_temp_dir(), 'fdp') . '.zip';
$fp  = fopen($tmp, 'w');
$ch  = curl_init($REPO_ZIP);
curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'fundays-deploy', CURLOPT_TIMEOUT => 90]);
$okc = curl_exec($ch); $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
if (!$okc || $hc !== 200) { @unlink($tmp); logln("download failed hc=$hc"); out(502, "download failed (hc=$hc)"); }

// --- extract ---
$ex = sys_get_temp_dir() . '/fdpx_' . bin2hex(random_bytes(4));
@mkdir($ex, 0700, true);
$za = new ZipArchive();
if ($za->open($tmp) !== true) { @unlink($tmp); out(500, 'zip open failed'); }
$za->extractTo($ex); $za->close(); @unlink($tmp);

$root = null;
foreach (scandir($ex) as $d) { if ($d[0] !== '.' && is_dir("$ex/$d")) { $root = "$ex/$d"; break; } }
if (!$root) out(500, 'extract root missing');

// --- copy allow-list into public_html (mirror .cpanel.yml) ---
function rcopy($s, $d){ if (is_dir($s)) { @mkdir($d, 0755, true); foreach (scandir($s) as $f) { if ($f === '.' || $f === '..') continue; rcopy("$s/$f", "$d/$f"); } } else { @copy($s, $d); } }
function rrm($p){ if (is_dir($p)) { foreach (scandir($p) as $f) { if ($f === '.' || $f === '..') continue; rrm("$p/$f"); } @rmdir($p); } else { @unlink($p); } }

$copied = [];
foreach (['index.html', 'admin.html', '.htaccess'] as $f) { if (is_file("$root/$f")) { @copy("$root/$f", "$DEST/$f"); $copied[] = $f; } }
@mkdir("$DEST/assets", 0755, true);
if (is_dir("$root/assets")) { rcopy("$root/assets", "$DEST/assets"); $copied[] = 'assets/'; }
@mkdir("$DEST/api", 0755, true);
foreach (['content.php', 'upload.php', 'deploy.php'] as $f) { if (is_file("$root/api/$f")) { @copy("$root/api/$f", "$DEST/api/$f"); $copied[] = "api/$f"; } }

rrm($ex);
logln('deployed: ' . implode(',', $copied));
out(200, 'deployed ok: ' . implode(', ', $copied));
