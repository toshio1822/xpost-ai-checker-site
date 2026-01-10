<?php
declare(strict_types=1);

/**
 * evidence/index.php（動作保証 + エラー可視化強化 + SEO）
 *
 * ✅ API_KEYはサーバ保持（ブラウザに出さない）
 * ✅ create_job → status polling → doneで「PDFを開く」有効化
 * ✅ Worker status: queued / running / done / failed
 * ✅ PDFは302(Location)対応
 * ✅ AdSense：広告1枠（ログ下）
 * ✅ 有料導線あり
 * ✅ SEO：title/description/canonical/OGP/h1(sr-only)/下部テキスト
 * ✅ ログ時刻：日本時間（JST）
 * ✅ エラー可視化：worker_http + detail + raw先頭
 */

// ============================================================
// 設定読み込み
// ============================================================
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

if ($WORKER_URL === '' || $API_KEY === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Server config error: XPOST_PDF_WORKER_URL / XPOST_PDF_API_KEY is required.";
  exit;
}
$WORKER_URL = rtrim($WORKER_URL, '/');

// ============================================================
// Utility
// ============================================================
function json_response($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function now_iso(): string {
  return gmdate('c');
}

function snippet(?string $s, int $limit = 400): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;
  if (mb_strlen($s) > $limit) return mb_substr($s, 0, $limit) . '...';
  return $s;
}

function validate_x_url(string $url): void {
  $url = trim($url);
  if ($url === '') throw new RuntimeException("URLが空です");

  $p = parse_url($url);
  if (!$p || empty($p['scheme']) || empty($p['host'])) {
    throw new RuntimeException("URLの形式が不正です");
  }

  $scheme = strtolower((string)$p['scheme']);
  if (!in_array($scheme, ['http', 'https'], true)) {
    throw new RuntimeException("URLは http(s) のみ対応しています");
  }

  $host = strtolower((string)$p['host']);
  $ok_host = false;

  if (function_exists('str_ends_with')) {
    $ok_host = ($host === 'x.com' || $host === 'twitter.com' || str_ends_with($host, '.x.com') || str_ends_with($host, '.twitter.com'));
  } else {
    $ok_host = ($host === 'x.com' || $host === 'twitter.com' || substr($host, -4) === '.x.com' || substr($host, -12) === '.twitter.com');
  }

  if (!$ok_host) {
    throw new RuntimeException("x.com / twitter.com のURLのみ対応しています");
  }

  $path = (string)($p['path'] ?? '');
  if (!preg_match('#/status/\d+#', $path)) {
    throw new RuntimeException("投稿URL（/status/xxxxx を含むURL）を入力してください");
  }
}

/**
 * Worker API呼び出し（JSON想定）
 * - json: 配列なら入る
 * - raw : 生文字列（JSONでなくても入る）
 */
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
    return [
      '_ok' => false,
      '_http' => $code,
      'error' => $err ?: 'curl error',
      'raw' => null,
      'json' => null,
    ];
  }

  $json = json_decode($resp, true);

  return [
    '_ok' => ($code >= 200 && $code < 400),
    '_http' => $code,
    'error' => null,
    'raw' => $resp,
    'json' => is_array($json) ? $json : null,
  ];
}

function curl_fetch_with_headers(string $url, array $headers, int $timeout = 120): array {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hdr_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($resp === false) {
    return ['ok' => false, 'http' => $code, 'error' => $err ?: 'curl error'];
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

// ============================================================
// API（ブラウザ→このPHP→Worker）
// ============================================================
$action = (string)($_GET['action'] ?? '');

if ($action === 'create_job') {
  $raw = file_get_contents('php://input');
  $req = json_decode($raw ?: '', true);

  try {
    $target_url = (string)($req['url'] ?? '');
    validate_x_url($target_url);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'where' => 'validate', 'detail' => $e->getMessage()], 400);
  }

  $endpoint = $WORKER_URL . '/jobs?mode=job';
  $headers = [
    'Content-Type: application/json',
    'X-API-Key: ' . $API_KEY,
  ];
  $body = json_encode(['url' => $target_url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $r = curl_json('POST', $endpoint, $headers, $body, 60);

  // Worker error
  if (!($r['_ok'] ?? false)) {
    $detail = null;
    if (is_array($r['json']) && isset($r['json']['detail'])) {
      $detail = (string)$r['json']['detail'];
    } elseif (!empty($r['error'])) {
      $detail = (string)$r['error'];
    } else {
      $detail = snippet((string)($r['raw'] ?? ''), 500);
    }

    json_response([
      'ok' => false,
      'where' => 'create_job',
      'worker_http' => $r['_http'] ?? null,
      'detail' => $detail,
      'raw' => snippet($r['raw'], 500),
      'ts' => now_iso(),
    ], 502);
  }

  // JSONでない / job_idが無い（←今回の「null」の本丸）
  $json = $r['json'];
  if (!is_array($json)) {
    json_response([
      'ok' => false,
      'where' => 'create_job',
      'worker_http' => $r['_http'] ?? null,
      'detail' => 'Workerの応答がJSONではありません（job_idを取得できません）',
      'raw' => snippet($r['raw'], 500),
      'ts' => now_iso(),
    ], 502);
  }

  $job_id = $json['job_id'] ?? null;
  if (!is_string($job_id) || trim($job_id) === '') {
    json_response([
      'ok' => false,
      'where' => 'create_job',
      'worker_http' => $r['_http'] ?? null,
      'detail' => 'Worker応答に job_id が含まれていません（job_id=null）',
      'raw' => snippet($r['raw'], 500),
      'ts' => now_iso(),
    ], 502);
  }

  json_response([
    'ok' => true,
    'job_id' => $job_id,
    'ts' => now_iso(),
  ]);
}

if ($action === 'status') {
  $job_id = (string)($_GET['job_id'] ?? '');
  if (trim($job_id) === '' || strtolower(trim($job_id)) === 'null') {
    json_response(['ok' => false, 'where' => 'status', 'detail' => 'job_id が空です（null）'], 400);
  }

  $endpoint = $WORKER_URL . '/jobs/' . rawurlencode($job_id);
  $headers = [
    'Accept: application/json',
    'X-API-Key: ' . $API_KEY,
  ];

  $r = curl_json('GET', $endpoint, $headers, null, 30);

  if (!($r['_ok'] ?? false)) {
    $detail = null;
    if (is_array($r['json']) && isset($r['json']['detail'])) {
      $detail = (string)$r['json']['detail'];
    } elseif (!empty($r['error'])) {
      $detail = (string)$r['error'];
    } else {
      $detail = snippet((string)($r['raw'] ?? ''), 500);
    }

    json_response([
      'ok' => false,
      'where' => 'status',
      'worker_http' => $r['_http'] ?? null,
      'detail' => $detail,
      'raw' => snippet($r['raw'], 500),
      'ts' => now_iso(),
    ], 502);
  }

  $json = $r['json'];
  if (!is_array($json)) {
    json_response([
      'ok' => false,
      'where' => 'status',
      'worker_http' => $r['_http'] ?? null,
      'detail' => 'Workerの応答がJSONではありません',
      'raw' => snippet($r['raw'], 500),
      'ts' => now_iso(),
    ], 502);
  }

  json_response([
    'ok' => true,
    'job' => [
      'job_id' => $json['job_id'] ?? $job_id,
      'url' => $json['url'] ?? null,
      'status' => $json['status'] ?? null,
      'created_at' => $json['created_at'] ?? null,
      'updated_at' => $json['updated_at'] ?? null,
      'error' => $json['error'] ?? null,
    ],
    'worker_http' => $r['_http'] ?? null,
    'ts' => now_iso(),
  ]);
}

if ($action === 'pdf') {
  $job_id = (string)($_GET['job_id'] ?? '');
  if (trim($job_id) === '' || strtolower(trim($job_id)) === 'null') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "job_id required";
    exit;
  }

  $endpoint = $WORKER_URL . '/jobs/' . rawurlencode($job_id) . '/pdf';
  $headers = ['X-API-Key: ' . $API_KEY];

  $r = curl_fetch_with_headers($endpoint, $headers, 120);

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

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="evidence.pdf"');
  echo $r['body'] ?? '';
  exit;
}

// ============================================================
// SEO固定値
// ============================================================
$CANONICAL_URL = 'https://xpostaichecker.jp/evidence/';
$PAGE_TITLE = 'XPost AI Checker(お試し版)｜X（旧Twitter）投稿の証拠PDFを無料で作成';
$PAGE_DESC  = 'X（旧Twitter）の投稿URLを入力するだけで、証拠PDFを無料で作成できます。誹謗中傷・トラブル対応・削除前の記録保存に。PDF完成後すぐにダウンロード可能。';

?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <title><?= htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($PAGE_DESC, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($CANONICAL_URL, ENT_QUOTES, 'UTF-8') ?>">

  <!-- OGP -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="XPost AI Checker">
  <meta property="og:title" content="<?= htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($PAGE_DESC, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:url" content="<?= htmlspecialchars($CANONICAL_URL, ENT_QUOTES, 'UTF-8') ?>">

  <style>
    body{
      font-family:"Noto Sans JP", system-ui, -apple-system, Segoe UI, sans-serif;
      margin:0;
      padding:20px;
      background:#fafafa;
      line-height:1.7;
      color:#333;
    }
    .card{
      background:#fff;
      padding:22px;
      border-radius:12px;
      box-shadow:0 4px 16px rgba(0,0,0,0.08);
      max-width:720px;
      margin:0 auto 18px auto;
    }
    .title-bar{
      background:#ffca28;
      padding:10px 14px;
      font-size:1.15rem;
      font-weight:700;
      color:#333;
      margin:-22px -22px 16px -22px;
      border-radius:12px 12px 0 0;
    }
    .feature-box{
      background:#f5f5f5;
      padding:12px 14px;
      border-radius:10px;
      margin:12px 0 18px 0;
      color:#333;
    }
    .muted{ color:#666; font-size:.95rem; }

    label{ font-weight:700; display:block; margin:12px 0 8px; }
    input[type="text"]{
      width:100%;
      padding:12px 12px;
      border-radius:10px;
      border:1px solid #ddd;
      font-size:1rem;
      box-sizing:border-box;
      background:#fff;
    }

    .row{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
      margin:12px 0;
    }

    .btn{
      display:inline-block;
      background:#111;
      color:#fff;
      border:none;
      padding:12px 16px;
      border-radius:10px;
      font-weight:700;
      cursor:pointer;
      text-decoration:none;
    }
    .btn:disabled{ opacity:.45; cursor:not-allowed; }
    .btn-secondary{
      background:#fff;
      color:#111;
      border:1px solid #ddd;
    }
    .btn-accent{
      background:#ffca28;
      color:#111;
      border:1px solid rgba(0,0,0,0.12);
    }

    .badge{
      display:inline-block;
      padding:4px 10px;
      border-radius:999px;
      font-weight:700;
      font-size:.9rem;
      background:#eee;
    }
    .badge.idle{ background:#eee; }
    .badge.queued{ background:#fff3e0; }
    .badge.running{ background:#e3f2fd; }
    .badge.done{ background:#e8f5e9; }
    .badge.failed{ background:#ffebee; }

    pre{
      background:#111;
      color:#ddd;
      padding:12px;
      border-radius:10px;
      overflow:auto;
      max-height:260px;
      margin:10px 0 0 0;
      font-size:.92rem;
    }

    .adsense-block{
      margin:22px 0;
      padding:14px;
      border:1px dashed #ddd;
      border-radius:12px;
      background:#fff;
    }
    .adsense-label{
      font-size:.85rem;
      color:#666;
      margin:0 0 10px;
      font-weight:700;
    }

    .cta{
      border:1px solid #eee;
      border-radius:12px;
      padding:16px;
      background:linear-gradient(180deg, #ffffff 0%, #fffdf6 100%);
    }
    .cta h3{
      margin:0 0 8px 0;
      font-size:1.05rem;
    }
    .cta ul{
      margin:10px 0 0 18px;
      color:#444;
    }

    .seo-block h2{
      margin:0 0 8px 0;
      font-size:1.05rem;
    }
    .seo-block p{ margin:0; }

    .sr-only{
      position:absolute;
      width:1px;
      height:1px;
      padding:0;
      margin:-1px;
      overflow:hidden;
      clip:rect(0,0,0,0);
      white-space:nowrap;
      border:0;
    }

    /* エラーボックス（可視化） */
    .error-box{
      margin-top:10px;
      padding:12px 14px;
      border-radius:10px;
      border:1px solid #f3b4b4;
      background:#ffebee;
      color:#7a1b1b;
      font-weight:700;
    }
    .error-box .small{
      display:block;
      margin-top:6px;
      font-weight:400;
      color:#7a1b1b;
      opacity:.9;
      white-space:pre-wrap;
      word-break:break-word;
    }

    #loadingOverlay{
      display:none;
      position:fixed;
      inset:0;
      background:rgba(0,0,0,0.45);
      z-index:9999;
      align-items:center;
      justify-content:center;
      padding:18px;
    }
    .loadingBox{
      background:#fff;
      width:min(520px, 100%);
      border-radius:14px;
      padding:18px;
      box-shadow:0 10px 30px rgba(0,0,0,.18);
    }
    .spinner{
      width:22px;
      height:22px;
      border:3px solid #ddd;
      border-top-color:#111;
      border-radius:50%;
      animation:spin .9s linear infinite;
      display:inline-block;
      vertical-align:middle;
      margin-right:10px;
    }
    @keyframes spin{ to{ transform:rotate(360deg); } }
  </style>

  <!-- AdSense（指定値） -->
  <script async
    src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8182034043692523"
    crossorigin="anonymous"></script>
</head>

<body>

<h1 class="sr-only">X（旧Twitter）投稿の証拠PDFを無料で作成｜XPost AI Checker</h1>

<div class="card">
  <div class="title-bar">XPost AI Checker(お試し版)｜X投稿の証拠PDFを作成（無料）</div>

  <div class="feature-box seo-block" style="margin-top:18px;">
    <h2>削除される前に、投稿の内容を残してください</h2>

    <p class="muted">
      Xの投稿は、削除されると第三者が内容を確認できなくなります。<br>
      まずは1件だけ、投稿URLを使って内容を保存してください。
    </p>

    <!-- まず行動させるためのショートCTA（フォームへジャンプ） -->
    <a href="#evidence-form" class="btn" style="margin-top:10px;">
      投稿URLを入力して証拠を作成する
    </a>

    <div class="muted" style="margin-top:8px;">
      ※投稿ページのURL（/status/〜 を含むもの）を入力してください
    </div>
  </div>

  <label id="evidence-form" for="url">投稿URL（x.com / twitter.com）</label>
  <input id="url" type="text" placeholder="https://x.com/.../status/..." autocomplete="off">

  <div class="row">
    <button id="btnCreate" class="btn">証拠を作成する</button>
    <button id="btnOpen" class="btn btn-secondary" disabled>作成した証拠を開く</button>
    <span id="status" class="badge idle">待機中</span>
  </div>

  <!-- ✅ ここにエラーを可視化 -->
  <div id="errorBox" class="error-box" style="display:none;"></div>

  <div class="feature-box">
    <div>処理ID: <code id="jobid">-</code></div>
    <div class="muted">完了まで数十秒かかる場合があります。</div>
  </div>

  <h3 style="margin:18px 0 10px;">ログ</h3>
  <pre id="log">ここにログが表示されます。</pre>

  <!-- ✅ 広告は1つだけ：ログの下 -->
  <div class="adsense-block" id="adBlockOnlyOne">
    <div class="adsense-label">広告</div>
    <ins class="adsbygoogle"
         style="display:block"
         data-ad-client="ca-pub-8182034043692523"
         data-ad-slot="4148468888"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
  </div>
  <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>

  <!-- 有料導線（自然） -->
  <div class="cta">
    <h3>より強い証拠保全が必要な方へ（有料）</h3>
    <div class="muted">
      無料版は「今すぐ簡単にPDF化したい」方向けです。<br>
      仕事・トラブル対応などで、後から説明しやすい形で残したい場合は、有料版が向いています。
    </div>
    <ul>
      <li>複数投稿のまとまった証拠化</li>
      <li>整理された形式で提出しやすい</li>
      <li>必要な作業をまとめて代行</li>
    </ul>
    <div class="row" style="margin-top:14px;">
      <a class="btn btn-accent" href="https://xpostaichecker.jp/service/" target="_blank" rel="noopener">
        X投稿の証拠化を代行する有料サービスを見る
      </a>
      <span class="muted">※ 別タブで開きます</span>
    </div>
  </div>

  <div class="feature-box" style="margin-top:18px;">
    <div><strong>補足</strong></div>
    <div class="muted">
      ・このページは無料機能です。広告収益で運用しています。<br>
      ・URLが正しいのに失敗する場合は、時間をおいて再度お試しください。
    </div>
  </div>
</div>

<div id="loadingOverlay">
  <div class="loadingBox">
    <div style="font-weight:700; margin-bottom:8px;">
      <span class="spinner"></span>
      PDFを作成しています…
    </div>
    <div class="muted" id="loadingText">数十秒かかる場合があります。画面は閉じずにお待ちください。</div>
  </div>
</div>

<script>
  const elUrl = document.getElementById('url');
  const btnCreate = document.getElementById('btnCreate');
  const btnOpen = document.getElementById('btnOpen');
  const elStatus = document.getElementById('status');
  const elJobId = document.getElementById('jobid');
  const elLog = document.getElementById('log');
  const overlay = document.getElementById('loadingOverlay');
  const loadingText = document.getElementById('loadingText');
  const errorBox = document.getElementById('errorBox');

  let currentJobId = null;
  let pollTimer = null;

  function nowJST() {
    const s = new Date().toLocaleString('ja-JP', {
      timeZone: 'Asia/Tokyo',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    });
    return s.replace(/\//g, '-');
  }

  function log(line){
    elLog.textContent = `[${nowJST()}] ${line}\n` + elLog.textContent;
  }

  function setStatus(statusText){
    const s = String(statusText || 'unknown').toLowerCase();
    const cls = ['idle','queued','running','done','failed'].includes(s) ? s : 'idle';
    elStatus.className = `badge ${cls}`;
    elStatus.textContent = s;
  }

  function showOverlay(msg){
    loadingText.textContent = msg || '数十秒かかる場合があります。画面は閉じずにお待ちください。';
    overlay.style.display = 'flex';
  }
  function hideOverlay(){
    overlay.style.display = 'none';
  }

  function clearError(){
    errorBox.style.display = 'none';
    errorBox.textContent = '';
  }
  function showError(title, detail){
    errorBox.style.display = 'block';
    errorBox.innerHTML = `${title}<span class="small">${detail || ''}</span>`;
  }

  function stopPolling(){
    if (pollTimer) {
      clearTimeout(pollTimer);
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function enableOpen(jobId){
    btnOpen.disabled = false;
    btnOpen.onclick = () => {
      window.open(`?action=pdf&job_id=${encodeURIComponent(jobId)}`, '_blank', 'noopener');
    };
  }

  async function createJob(url){
    const res = await fetch('?action=create_job', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({url})
    });

    const data = await res.json().catch(()=>null);

    if (!res.ok || !data || !data.ok) {
      const where = data?.where || 'create_job';
      const http  = data?.worker_http ? `worker HTTP ${data.worker_http}` : `HTTP ${res.status}`;
      const detail = data?.detail || data?.error || '不明なエラー';
      const raw = data?.raw ? `\n---\n${data.raw}` : '';
      throw new Error(`[${where}] ${http}\n${detail}${raw}`);
    }
    return data;
  }

  async function getStatus(jobId){
    const res = await fetch(`?action=status&job_id=${encodeURIComponent(jobId)}`, {
      method: 'GET',
      headers: {'Accept':'application/json'}
    });

    const data = await res.json().catch(()=>null);

    if (!res.ok || !data || !data.ok) {
      const where = data?.where || 'status';
      const http  = data?.worker_http ? `worker HTTP ${data.worker_http}` : `HTTP ${res.status}`;
      const detail = data?.detail || data?.error || '不明なエラー';
      const raw = data?.raw ? `\n---\n${data.raw}` : '';
      throw new Error(`[${where}] ${http}\n${detail}${raw}`);
    }
    return data.job;
  }

  function isDoneStatus(st){ return String(st).toLowerCase() === 'done'; }
  function isErrorStatus(st){ return String(st).toLowerCase() === 'failed'; }

  async function startPolling(jobId){
    // 既存ポーリングは必ず止める
    stopPolling();

    let delayMs = 1500;      // 最初は1.5秒
    const maxDelayMs = 8000; // 最大8秒まで伸ばす
    let stopped = false;

    // 「この startPolling が現役か」を判定するトークン
    const myToken = Symbol('poll');
    startPolling._token = myToken;

    const tick = async () => {
      // 他の startPolling が開始されたら、このループは終了
      if (startPolling._token !== myToken) return;
      if (stopped) return;

      try {
        const job = await getStatus(jobId);
        const st = job.status || 'unknown';

        setStatus(st);
        log(`status: ${st}`);

        // ✅ done になったら一発で終わり（エラー表示も消す）
        if (isDoneStatus(st)) {
          stopped = true;
          stopPolling();
          hideOverlay();
          clearError();              // ★これ重要：赤い箱を消す
          enableOpen(jobId);
          log('PDFの準備ができました。');
          return;
        }

        if (isErrorStatus(st)) {
          stopped = true;
          stopPolling();
          hideOverlay();
          btnOpen.disabled = true;
          clearError();
          log(`生成に失敗しました: ${job.error || 'unknown error'}`);
          showError('生成に失敗しました', job.error || 'Worker側でエラーが発生しました');
          return;
        }

        // 正常に取れたら delay を少しだけ戻す（混雑が解けたら軽くする）
        delayMs = Math.max(1500, Math.floor(delayMs * 0.9));

      } catch(e) {
        const msg = String(e?.message || '');

        // ✅ 429 は「混雑」なので“赤エラーにしない”
        if (msg.includes('worker HTTP 429') || msg.includes('HTTP 429') || msg.toLowerCase().includes('rate exceeded')) {
          log(`status混雑(429): リトライします（${Math.round(delayMs/1000)}秒後）`);
          // 表示は赤ではなく、必要なら“控えめ”に（ここでは表示しない）
          // showErrorしたいなら黄色系のinfoBoxを作るのが良い
          // → 今回は「画面をエラーにしない」が目的なので何もしない

          // バックオフ（少しずつ待ち時間を伸ばす）
          delayMs = Math.min(maxDelayMs, Math.floor(delayMs * 1.5));
        } else {
          // 429以外は赤エラーで見せる（原因究明に必要）
          stopPolling();
          hideOverlay();
          setStatus('failed');
          btnOpen.disabled = true;
          log(`status取得エラー: ${msg}`);
          showError('status取得エラー', msg);
          return;
        }
      }

      // 次のtick（setTimeoutで実行）
      pollTimer = setTimeout(tick, delayMs);
    };

    // 初回実行
    pollTimer = setTimeout(tick, 0);
  }

  btnCreate.addEventListener('click', async () => {
    stopPolling();
    clearError();

    const url = elUrl.value.trim();

    btnCreate.disabled = true;
    btnOpen.disabled = true;
    currentJobId = null;
    elJobId.textContent = '-';
    setStatus('queued');

    log('PDF生成ジョブを作成します…');
    showOverlay('PDFを作成しています…（数十秒かかる場合があります）');

    try{
      const r = await createJob(url);

      // ✅ job_id が無ければ即エラー（今回の null 対策）
      if (!r.job_id || String(r.job_id).trim() === '' || String(r.job_id).toLowerCase() === 'null') {
        throw new Error(`[create_job] job_id が取得できませんでした（null）\n返却値: ${JSON.stringify(r)}`);
      }

      currentJobId = r.job_id;
      elJobId.textContent = currentJobId;
      log(`job created: ${currentJobId}`);

      setStatus('queued');
      await startPolling(currentJobId);

    } catch(e){
      hideOverlay();
      setStatus('failed');
      log(`作成エラー: ${e.message}`);
      showError('作成エラー', e.message);
    } finally {
      setTimeout(()=>{ btnCreate.disabled = false; }, 400);
    }
  });
</script>

</body>
</html>

