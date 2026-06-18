<?php
// 7 Fundays Park — image upload (admin only)
// POST {key, image} -> validates a real raster image, stores it under
// public_html/uploads/ (web-accessible) and returns its URL.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$CMS     = dirname(dirname(__DIR__)) . '/fundays-cms';
$KEYFILE = $CMS . '/cms_key.txt';
$UP      = dirname(__DIR__) . '/uploads';                 // public_html/uploads

function out($o){ echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }

$expected = is_file($KEYFILE) ? trim(file_get_contents($KEYFILE)) : '';
$key = isset($_POST['key']) ? (string)$_POST['key'] : '';
if ($expected === '' || !hash_equals($expected, $key)) {
  http_response_code(403);
  out(['ok' => false, 'error' => 'unauthorized']);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  out(['ok' => false, 'error' => 'no file received']);
}
$f = $_FILES['image'];
if ($f['size'] > 6 * 1024 * 1024) {
  out(['ok' => false, 'error' => 'image too large (max 6 MB)']);
}
$info = @getimagesize($f['tmp_name']);
if ($info === false) {
  out(['ok' => false, 'error' => 'not a valid image']);
}
$map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$mime = $info['mime'];
if (!isset($map[$mime])) {
  out(['ok' => false, 'error' => 'unsupported type — use JPG, PNG, WEBP or GIF']);
}
$ext = $map[$mime];

if (!is_dir($UP)) { @mkdir($UP, 0755, true); }
// Harden the uploads dir: never execute anything in here.
$ht = $UP . '/.htaccess';
if (!is_file($ht)) {
  @file_put_contents($ht,
    "Options -ExecCGI\n" .
    "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl\n" .
    "RemoveType .php .phtml\n" .
    "<FilesMatch \"\\.(php|phtml|php[0-9]|phps|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n"
  );
}

try { $rand = bin2hex(random_bytes(4)); }
catch (Exception $e) { $rand = substr(md5(uniqid('', true)), 0, 8); }
$name = 'img_' . date('Ymd_His') . '_' . $rand . '.' . $ext;

if (!move_uploaded_file($f['tmp_name'], $UP . '/' . $name)) {
  http_response_code(500);
  out(['ok' => false, 'error' => 'could not save file']);
}
out(['ok' => true, 'url' => '/uploads/' . $name, 'name' => $name]);
