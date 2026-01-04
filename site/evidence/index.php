<?php
declare(strict_types=1);

/**
 * evidence/index.php
 *
 * ✅ ブラウザに API_KEY を渡さない（サーバ側で Worker を代理呼び出し）
 * ✅ 画面遷移なし：PDF生成 → status更新 → doneで「PDFを開く」有効化
 * ✅ Worker status: queued / running / done / failed（app.pyに合わせる）
 * ✅ AdSense：広告は1つだけ（ログの下）※邪魔にならず信頼感優先
 * ✅ 有料版への自然な動線（次の選択肢として案内）
 * ✅ タイトルに「XPost AI Checker」を明示
 * ✅ ログ時刻は日本時間（JST）
 */

// ============================================================
// 設定読み込み（Xserverの外に置いた config.php を読む）
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

// ★ あなたの環境のキー名（ここは維持）
//   $cfg['XPOST_PDF_WORKER_URL'] / $cfg['XPOST_PDF_API_KEY']
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

function validate_x_url(string $url): void {
  $url = trim($url);
  if ($url === '') throw new RuntimeException("URLが空です");

  $p = parse_url($url);
  if (!$p || empty($p['scheme']) || empty($p['host'])) {
    throw new RuntimeException("URLの形式が不正です");
  }
  $scheme = strtolower((string)$p['scheme']);
  if (!in_array($scheme, ['http','https'], true)) {
    throw new RuntimeException("URLは http(s) のみ対応しています");
  }

  $host = strtolower((string)$p['host']);
  $ok_host = (
    $host === 'x.com' || $host === 'twitter.com' ||
    str_ends_with($host, '.x.com') || str_ends_with($host, '.twitter.com')
  );
  if (!$ok_host) {
    throw new RuntimeException("x.com / twitter.com のURLのみ対応しています");
  }

  $path = (string)($p['path'] ?? '');
  if (!preg_match('#/status/\d+#', $path)) {
    throw new RuntimeException("投稿URL（/status/xxxxx を含むURL）を入力してください");
  }
}

/**
 * JSON API を呼ぶ（workerのJSONレスポンス想定）
 * 返り値に _ok, _http を付ける
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
    return ['_ok' => false, '_http' => $code, 'error' => $err ?: 'curl error'];
  }

  $data = json_decode($resp, true);
  if (!is_array($data)) {
    return ['_ok' => ($code >= 200 && $code < 400), '_http' => $code, 'raw' => $resp];
  }

  $data['_ok'] = ($code >= 200 && $code < 400);
  $data['_http'] = $code;
  return $data;
}

/**
 * PDF取得用（Locationヘッダの302転送を拾うためヘッダも取得）
 */
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
// API（ブラウザから叩く：ただしキーはサーバ保持）
// ============================================================
$action = (string)($_GET['action'] ?? '');

if ($action === 'create_job') {
  $raw = file_get_contents('php://input');
  $req = json_decode($raw ?: '', true);

  try {
    $target_url = (string)($req['url'] ?? '');
    validate_x_url($target_url);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
  }

  $endpoint = $WORKER_URL . '/jobs?mode=job';
  $headers = [
    'Content-Type: application/json',
    'X-API-Key: ' . $API_KEY,
  ];
  $body = json_encode(['url' => $target_url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $r = curl_json('POST', $endpoint, $headers, $body, 60);
  if (!($r['_ok'] ?? false)) {
    json_response(['ok' => false, 'error' => $r['error'] ?? 'worker error', 'worker_http' => $r['_http'] ?? null], 502);
  }

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

  $endpoint = $WORKER_URL . '/jobs/' . rawurlencode($job_id);
  $headers = [
    'Accept: application/json',
    'X-API-Key: ' . $API_KEY,
  ];

  $r = curl_json('GET', $endpoint, $headers, null, 30);
  if (!($r['_ok'] ?? false)) {
    json_response(['ok' => false, 'error' => $r['error'] ?? 'worker error', 'worker_http' => $r['_http'] ?? null], 502);
  }

  json_response([
    'ok' => true,
    'job' => [
      'job_id' => $r['job_id'] ?? $job_id,
      'url' => $r['url'] ?? null,
      'status' => $r['status'] ?? null,      // queued / running / done / failed
      'created_at' => $r['created_at'] ?? null,
      'updated_at' => $r['updated_at'] ?? null,
      'error' => $r['error'] ?? null,
    ],
    'worker_http' => $r['_http'] ?? null,
    'ts' => now_iso(),
  ]);
}

if ($action === 'pdf') {
  $job_id = (string)($_GET['job_id'] ?? '');
  if ($job_id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "job_id required";
    exit;
  }

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

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="evidence.pdf"');
  echo $r['body'] ?? '';
  exit;
}

// ============================================================
// UI（HTML / JS）
// ============================================================
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- ✅ タイトルに「XPost AI Checker」を入れる -->
  <title>XPost AI Checker｜X投稿の証拠PDFを作成（無料）</title>

  <meta name="description" content="X（旧Twitter）の投稿URLを入力するだけで、証拠PDFを作成できます。PDFが完成したら「PDFを開く」が有効になります。">

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

    /* AdSense block（広告を隠さず、UIと混ぜない） */
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

    /* CTA（押し売りに見えない“次の選択肢”） */
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

    /* Loading overlay */
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

<div class="card">
  <!-- ✅ 画面にも「XPost AI Checker」を明示 -->
  <div class="title-bar">XPost AI Checker｜X投稿の証拠PDFを作成（無料）</div>

  <div class="feature-box">
    <div>✅ 投稿URLを入れるだけで、証拠PDFを作成します。</div>
    <div>✅ PDFが準備できたら「PDFを開く」が有効になります。</div>
    <div class="muted" style="margin-top:8px;">
      ※ URLは投稿ページ（<code>/status/xxxxx</code> を含む）を入力してください。
    </div>
  </div>

  <label for="url">投稿URL（x.com / twitter.com）</label>
  <input id="url" type="text" placeholder="https://x.com/.../status/..." autocomplete="off">

  <div class="row">
    <button id="btnCreate" class="btn">PDFを作成する</button>
    <button id="btnOpen" class="btn btn-secondary" disabled>PDFを開く</button>
    <span id="status" class="badge idle">idle</span>
  </div>

  <div class="feature-box">
    <div>job_id: <code id="jobid">-</code></div>
    <div class="muted">完了まで数十秒かかる場合があります。</div>
  </div>

  <h3 style="margin:18px 0 10px;">ログ</h3>
  <pre id="log">ここにログが表示されます。</pre>

  <!-- ✅ 広告は1つだけ：ログの下（目的達成後の読み物フェーズ） -->
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

  <!-- 有料導線（自然：押し売りにしない「次の選択肢」） -->
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
      <a class="btn btn-accent" href="https://xpostaichecker.jp/" target="_blank" rel="noopener">有料の証拠化サービスを見る</a>
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

<!-- 生成中オーバーレイ -->
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
  // ==========================================================
  // UI Helpers
  // ==========================================================
  const elUrl = document.getElementById('url');
  const btnCreate = document.getElementById('btnCreate');
  const btnOpen = document.getElementById('btnOpen');
  const elStatus = document.getElementById('status');
  const elJobId = document.getElementById('jobid');
  const elLog = document.getElementById('log');
  const overlay = document.getElementById('loadingOverlay');
  const loadingText = document.getElementById('loadingText');

  let currentJobId = null;
  let pollTimer = null;

  // ✅ 日本時間（JST）でログ時刻を出す
  function nowJST() {
    // 例: 2026-01-04 16:52:11
    return new Date().toLocaleString('ja-JP', {
      timeZone: 'Asia/Tokyo',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    }).replace(/\//g, '-');
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

  function stopPolling(){
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  function enableOpen(jobId){
    btnOpen.disabled = false;
    btnOpen.onclick = () => {
      window.open(`?action=pdf&job_id=${encodeURIComponent(jobId)}`, '_blank', 'noopener');
    };
  }

  // ==========================================================
  // API
  // ==========================================================
  async function createJob(url){
    const res = await fetch('?action=create_job', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({url})
    });
    const data = await res.json().catch(()=>null);
    if (!res.ok || !data || !data.ok) {
      const msg = (data && data.error) ? data.error : `HTTP ${res.status}`;
      throw new Error(msg);
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
      const msg = (data && data.error) ? data.error : `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return data.job;
  }

  // Worker status（app.py）に合わせて固定：queued / running / done / failed
  function isDoneStatus(st){ return String(st).toLowerCase() === 'done'; }
  function isErrorStatus(st){ return String(st).toLowerCase() === 'failed'; }

  async function startPolling(jobId){
    stopPolling();
    pollTimer = setInterval(async () => {
      try{
        const job = await getStatus(jobId);
        const st = job.status || 'unknown';
        setStatus(st);
        log(`status: ${st}`);

        if (isDoneStatus(st)) {
          stopPolling();
          hideOverlay();
          enableOpen(jobId);
          log('PDFの準備ができました。');
          return;
        }

        if (isErrorStatus(st)) {
          stopPolling();
          hideOverlay();
          btnOpen.disabled = true;
          log(`生成に失敗しました: ${job.error || 'unknown error'}`);
          return;
        }
        // queued / running の間は overlay 維持
      } catch(e){
        stopPolling();
        hideOverlay();
        setStatus('failed');
        btnOpen.disabled = true;
        log(`status取得エラー: ${e.message}`);
      }
    }, 1500);
  }

  // ==========================================================
  // Events
  // ==========================================================
  btnCreate.addEventListener('click', async () => {
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
      currentJobId = r.job_id;
      elJobId.textContent = currentJobId || '-';
      log(`job created: ${currentJobId}`);
      setStatus('queued');
      await startPolling(currentJobId);
    } catch(e){
      hideOverlay();
      setStatus('failed');
      log(`作成エラー: ${e.message}`);
    } finally {
      setTimeout(()=>{ btnCreate.disabled = false; }, 400);
    }
  });
</script>

</body>
</html>

