<?php
// 7 Fundays Park — content store (news/promos + image overrides)
// GET  -> returns current content JSON (public; consumed by the homepage)
// POST -> {key, payload} saves content (key checked against a server-only file
//         OUTSIDE the web root; never stored in the public repo)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$CMS     = dirname(dirname(__DIR__)) . '/fundays-cms';   // /home/<user>/fundays-cms (not web-accessible)
$FILE    = $CMS . '/content.json';
$KEYFILE = $CMS . '/cms_key.txt';

function out($o){ echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function defaults(){ return ['updated' => '', 'images' => new stdClass(), 'text' => new stdClass(), 'items' => []]; }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  if (is_file($FILE)) {
    $j = json_decode(file_get_contents($FILE), true);
    if (!is_array($j)) $j = defaults();
  } else {
    $j = defaults();
  }
  out($j);
}

if ($method === 'POST') {
  $expected = is_file($KEYFILE) ? trim(file_get_contents($KEYFILE)) : '';
  $key = isset($_POST['key']) ? (string)$_POST['key'] : '';
  if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    out(['ok' => false, 'error' => 'unauthorized']);
  }
  $payload = isset($_POST['payload']) ? (string)$_POST['payload'] : '';
  $j = json_decode($payload, true);
  if (!is_array($j)) {
    http_response_code(400);
    out(['ok' => false, 'error' => 'bad payload']);
  }
  $images = (isset($j['images']) && is_array($j['images']) && count($j['images'])) ? $j['images'] : new stdClass();
  $text   = (isset($j['text'])   && is_array($j['text'])   && count($j['text']))   ? $j['text']   : new stdClass();
  $items  = (isset($j['items'])  && is_array($j['items']))  ? array_slice($j['items'], 0, 50) : [];
  $clean = ['updated' => date('c'), 'images' => $images, 'text' => $text, 'items' => $items];
  if (!is_dir($CMS)) @mkdir($CMS, 0700, true);
  if (file_put_contents($FILE, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
    http_response_code(500);
    out(['ok' => false, 'error' => 'could not write content']);
  }
  out(['ok' => true, 'updated' => $clean['updated']]);
}

http_response_code(405);
out(['ok' => false, 'error' => 'method not allowed']);
