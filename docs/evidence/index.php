<?php
declare(strict_types=1);

// ========== 設定読み込み（Xserverの外に置いた config.php を読む） ==========
$config_path = __DIR__ . '/../../config/config.php';

if (!file_exists($config_path)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Server config error: config.php not found: {$config_path}";
  exit;
}

$cfg = require $config_path;
if (!is_array($cfg)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Server config error: config.php must return array.";
  exit;
}

$WORKER_URL = trim((string)($cfg['XPOST_PDF_WORKER_URL'] ?? ''));
$API_KEY    = trim((string)($cfg['XPOST_PDF_API_KEY'] ?? ''));

// 必須チェック
if ($WORKER_URL === '' || $API_KEY === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Server config error: XPOST_PDF_WORKER_URL / XPOST_PDF_API_KEY is required.";
  exit;
}

// 末尾スラッシュを除去して安定化
$WORKER_URL = rtrim($WORKER_URL, '/');

// ========== ユーティリティ ==========
function json_response($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function now_iso(): string {
  return gmdate('c');
}

function validate_x_url(string $url): void {
  $url = trim($url);
  if ($url === '') throw new RuntimeException("url is empty");

  $p = parse_url($url);
  if (!$p || empty($p['scheme']) || empty($p['host'])) {
    throw new RuntimeException("invalid url");
  }
  if (!in_array($p['scheme'], ['http','https'], true)) {
    throw new RuntimeException("invalid scheme");
  }

  // x.com / twitter.com のみ許可（必要に応じて拡張）
  $host = strtolower($p['host']);
  if ($host !== 'x.com' && $host !== 'twitter.com' && !str_ends_with($host, '.x.com') && !str_ends_with($host, '.twitter.com')) {
    throw new RuntimeException("host must be x.com or twitter.com");
  }
}

function curl_json(string $method, string $url, array $headers, ?string $body_json = null, int $timeout = 60): array {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  if ($body_json !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
  }

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($resp === false) {
    return ['ok' => false, 'http' => 0, 'error' => "curl error: {$err}"];
  }

  $decoded = json_decode($resp, true);
  if ($code >= 200 && $code < 300 && is_array($decoded)) {
    $decoded['_http'] = $code;
    $decoded['_raw']  = null;
    $decoded['_ok']   = true;
    return $decoded;
  }

  return [
    'ok' => false,
    'http' => $code,
    'error' => "HTTP {$code}: " . (is_string($resp) ? mb_substr($resp, 0, 2000) : ''),
    'raw' => $resp,
  ];
}

// PDF取得用：ヘッダも取って 302(Location) を拾えるようにする
function curl_fetch_with_headers(string $url, array $headers, int $timeout = 120): array {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // ←重要：302を自分で処理
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hdr_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($resp === false) {
    return ['ok' => false, 'http' => 0, 'error' => "curl error: {$err}"];
  }

  $raw_headers = substr($resp, 0, $hdr_size);
  $body        = substr($resp, $hdr_size);

  $location = null;
  foreach (explode("\n", $raw_headers) as $line) {
    if (stripos($line, 'Location:') === 0) {
      $location = trim(substr($line, strlen('Location:')));
      break;
    }
  }

  return [
    'ok' => ($code >= 200 && $code < 400),
    'http' => $code,
    'headers_raw' => $raw_headers,
    'location' => $location,
    'body' => $body,
  ];
}

// ========== API（ブラウザから叩く） ==========
$action = (string)($_GET['action'] ?? '');

if ($action === 'create_job') {
  // POST JSON: {url: "..."}
  $raw = file_get_contents('php://input');
  $req = json_decode($raw ?: '', true);

  try {
    $target_url = (string)($req['url'] ?? '');
    validate_x_url($target_url);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
  }

  global $WORKER_URL, $API_KEY;

  $endpoint = $WORKER_URL . '/jobs?mode=job';
  $headers = [
    'Content-Type: application/json',
    'X-API-Key: ' . $API_KEY,
  ];
  $body = json_encode(['url' => $target_url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $r = curl_json('POST', $endpoint, $headers, $body, 60);
  if (!($r['_ok'] ?? false)) {
    json_response(['ok' => false, 'error' => $r['error'] ?? 'worker error'], 502);
  }

  // worker 返却をそのまま返す（job_id / status_url / download_url など）
  json_response([
    'ok' => true,
    'job_id' => $r['job_id'] ?? null,
    'status_url' => $r['status_url'] ?? null,
    'download_url' => $r['download_url'] ?? null,
    'worker_http' => $r['_http'] ?? null,
    'ts' => now_iso(),
  ]);
}

if ($action === 'status') {
  $job_id = (string)($_GET['job_id'] ?? '');
  if ($job_id === '') json_response(['ok' => false, 'error' => 'job_id required'], 400);

  global $WORKER_URL, $API_KEY;
  $endpoint = $WORKER_URL . '/jobs/' . rawurlencode($job_id);
  $headers = [
    'Accept: application/json',
    'X-API-Key: ' . $API_KEY,
  ];

  $r = curl_json('GET', $endpoint, $headers, null, 30);
  if (!($r['_ok'] ?? false)) {
    json_response(['ok' => false, 'error' => $r['error'] ?? 'worker error'], 502);
  }

  // 代表フィールドだけ整形して返す
  json_response([
    'ok' => true,
    'job' => [
      'job_id' => $r['job_id'] ?? $job_id,
      'url' => $r['url'] ?? null,
      'status' => $r['status'] ?? null,
      'created_at' => $r['created_at'] ?? null,
      'updated_at' => $r['updated_at'] ?? null,
      'error' => $r['error'] ?? null,
    ],
    'worker_http' => $r['_http'] ?? null,
    'ts' => now_iso(),
  ]);
}

if ($action === 'pdf') {
  // ブラウザで開く/DL：APIキーはサーバ側で付けて代理取得
  $job_id = (string)($_GET['job_id'] ?? '');
  if ($job_id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "job_id required";
    exit;
  }

  global $WORKER_URL, $API_KEY;
  $endpoint = $WORKER_URL . '/jobs/' . rawurlencode($job_id) . '/pdf';
  $headers = [
    'X-API-Key: ' . $API_KEY,
  ];

  $r = curl_fetch_with_headers($endpoint, $headers, 120);

  // worker が 302（署名URL）を返したら、そのままブラウザへ転送（帯域節約）
  if (($r['http'] ?? 0) === 302 && !empty($r['location'])) {
    header('Location: ' . $r['location'], true, 302);
    exit;
  }

  if (($r['http'] ?? 0) >= 400) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "worker error: HTTP " . ($r['http'] ?? 0);
    exit;
  }

  // PDF をそのまま返す
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="evidence.pdf"');
  echo $r['body'] ?? '';
  exit;
}

// ========== UI（HTML/JS） ==========
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>XPost PDF Worker（jobモード）</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; }
    .row { margin: 12px 0; }
    input[type="text"] { width: 100%; padding: 10px; }
    button { padding: 10px 14px; margin-right: 8px; }
    pre { background: #111; color: #ddd; padding: 12px; overflow: auto; }
    .badge { display:inline-block; padding:2px 8px; border-radius: 999px; background:#eee; margin-left:8px; }
  </style>
</head>
<body>
  <h1>XPost PDF Worker（jobモード）</h1>
  <p>生成はサーバ側で job に投入し、完了したら PDF を開きます（APIキーはブラウザに渡しません）。</p>

  <div class="row">
    <label>投稿URL（x.com / twitter.com）</label>
    <input id="url" type="text" placeholder="https://x.com/.../status/..." />
  </div>

  <div class="row">
    <button id="btnCreate">PDF生成（job）</button>
    <button id="btnOpen" disabled>PDFを開く</button>
    <span id="status" class="badge">idle</span>
  </div>

  <div class="row">
    <div>job_id: <code id="jobid">-</code></div>
  </div>

  <h3>ログ</h3>
  <pre id="log">ここにログが表示されます。</pre>

<script>
const $ = (id) => document.getElementById(id);
const log = (msg) => {
  const ts = new Date().toISOString();
  $('log').textContent = `[${ts}] ${msg}\n` + $('log').textContent;
};

let currentJobId = null;
let pollTimer = null;

async function api(action, method='GET', body=null) {
  const url = `index.php?action=${encodeURIComponent(action)}` + (action==='status' || action==='pdf' ? `&job_id=${encodeURIComponent(currentJobId||'')}` : '');
  const opt = { method, headers: {} };
  if (body) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(body);
  }
  const res = await fetch(url, opt);
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch {}
  if (!res.ok) {
    throw new Error(`HTTP ${res.status}: ${text.slice(0, 2000)}`);
  }
  return json ?? { ok:true, raw:text };
}

async function createJob() {
  const u = $('url').value.trim();
  if (!u) { alert('URLを入力してください'); return; }

  $('status').textContent = 'creating';
  $('btnOpen').disabled = true;
  currentJobId = null;
  $('jobid').textContent = '-';

  log('create job...');
  const r = await api('create_job', 'POST', { url: u });
  if (!r.ok) throw new Error(r.error || 'create_job failed');

  currentJobId = r.job_id;
  $('jobid').textContent = currentJobId;
  $('status').textContent = 'queued';
  log(`job created: ${currentJobId}`);

  startPolling();
}

function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    if (!currentJobId) return;
    try {
      const r = await api('status', 'GET');
      const st = r?.job?.status || 'unknown';
      $('status').textContent = st;
      if (st === 'done') {
        $('btnOpen').disabled = false;
        clearInterval(pollTimer);
        pollTimer = null;
        log('done. you can open pdf.');
      } else if (st === 'failed') {
        clearInterval(pollTimer);
        pollTimer = null;
        log('failed: ' + (r?.job?.error || 'unknown error'));
      }
    } catch (e) {
      log('poll error: ' + e.message);
    }
  }, 2000);
}

function openPdf() {
  if (!currentJobId) return;
  // PHP が代理で /pdf を取り、worker が 302(署名URL)ならブラウザへ転送する
  window.open(`index.php?action=pdf&job_id=${encodeURIComponent(currentJobId)}`, '_blank');
}

$('btnCreate').addEventListener('click', async () => {
  try { await createJob(); }
  catch(e){ $('status').textContent = 'error'; log('ERROR: ' + e.message); }
});

$('btnOpen').addEventListener('click', () => openPdf());
</script>
</body>
</html>

