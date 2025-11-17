<?php
// 文字化け防止
header("Content-Type: text/html; charset=UTF-8");

// ===== APIキー読込（config.php） =====
require_once($_SERVER['DOCUMENT_ROOT'] . '/../config/config.php'); // config.php に $OPENAI_API_KEY を定義しておく
$api_key = $OPENAI_API_KEY;

// 入力取得
$input_text = $_POST["text"] ?? "";

if (!$input_text) {
    echo "文章が入力されていません。<br><a href='index.html'>戻る</a>";
    exit;
}

// ===== OpenAI設定 =====
$url   = "https://api.openai.com/v1/chat/completions";
$model = "gpt-4o-mini";   // gpt-4.1-mini/gpt-4o-mini などでもOK

// ===== SYSTEM プロンプト（属性でひとくくりにする表現に統一） =====
$system_prompt = <<<'EOT'
あなたは、日本語のSNS投稿文をチェックして、炎上やトラブルのリスクを評価するアシスタントです。

ユーザーの投稿文を読み、以下の観点でリスクを評価し、日本語のコメントを付けてください。

【評価する観点（カテゴリ）】
1. 攻撃性（aggression）
   - 暴言、罵倒、人格否定、見下す表現など
2. 誤解を招く表現（misinterpretation）
   - 主語が曖昧、文脈依存、皮肉・遠回しな表現など
3. 属性でひとくくりにする表現（bias）
   - 性別・年齢・職業・国籍などの属性で、グループ全体をまとめて評価する表現
4. プライバシー・個人情報リスク（privacy）
   - 氏名、住所、勤務先、学校、ハンドル名など、個人を特定しうる情報
5. 威圧・脅しとして読める表現（implied_threat）
   - 「〜しに行く」「〜させてやる」など、相手に恐怖やプレッシャーを与えうる表現
6. 過度な感情表現（emotional_risk）
   - 「ムカつく」「マジで無理」など、怒りや嫌悪を強く表す表現
7. その他のリスク（other_risk）
   - 上記に当てはまらないが、炎上やトラブルにつながりうる表現

【出力JSON仕様】
必ず次のキーのみを含むJSONオブジェクトで出力してください。JSON以外のテキストは禁止です。

{
  "input_text": string,
  "overall_risk_score": number,           // 1〜5
  "overall_risk_label": string,           // 任意のラベル（こちらでは参考程度に扱います）
  "category_scores": {
    "aggression": number,
    "misinterpretation": number,
    "bias": number,
    "privacy": number,
    "implied_threat": number,
    "emotional_risk": number,
    "other_risk": number
  },
  "main_categories": [string],
  "highlight_spans": [
    {
      "phrase": string,
      "categories": [string],
      "reason": string
    }
  ],
  "summary_reason": string,
  "suggested_texts": {
    "soft": string,
    "business": string,
    "humor": string
  },
  "disclaimer": string
}

【修正文のスタイル】
- soft（やわらかマイルド版）：
  ・怒りや不満は残してもよいが、直接的な攻撃や罵倒は避ける
  ・相手を責めるより「自分がどう感じたか」に焦点を当てる
  ・日常的なX投稿として違和感のない表現にする

- business（事務的ビジネス版）：
  ・感情表現をできるだけ抑え、事実と要望を簡潔に述べる
  ・社内外への連絡やお知らせとしても使える程度の硬さにする
  ・相手を非難するニュアンスは避ける

- humor（ユーモア版）：
  ・攻撃性や皮肉は避けつつ、軽い冗談やツッコミで柔らかく表現する
  ・誰かを笑い者にするのではなく、「自分や状況」を少しだけネタにする方向にする
  ・場の空気を和らげることを目的とする

【制約】
- suggested_texts の各文は必ず全角換算140文字以内。
- 140文字を超えそうな場合は、内容を簡潔にまとめて短くしてください。
- 法律用語を用いる場合も断定は避け、「〜と受け取られる可能性があります」「〜と評価されるおそれがあります」のように書いてください。
- これは法的判断ではなく「リスクの目安」です。
- 出力は有効なJSONのみとし、JSON以外の文字（解説文、コードブロック記号など）は一切書かないでください。
EOT;

// ===== USER プロンプト =====
$user_prompt = "以下のSNS投稿文をチェックし、指定JSON形式で返してください。\n---\n{$input_text}\n---";

// ===== API呼び出し =====
$data = [
    "model" => $model,
    "temperature" => 0.1,
    "response_format" => ["type" => "json_object"],
    "messages" => [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user",   "content" => $user_prompt]
    ]
];

$options = [
    "http" => [
        "method"  => "POST",
        "header"  =>
            "Content-Type: application/json\r\n" .
            "Authorization: Bearer {$api_key}\r\n",
        "content" => json_encode($data, JSON_UNESCAPED_UNICODE),
        "ignore_errors" => true,
    ]
];

$response_json = file_get_contents($url, false, stream_context_create($options));

if (!$response_json) {
    echo "API呼び出しに失敗しました。";
    exit;
}

$response = json_decode($response_json, true);
if (isset($response["error"])) {
    echo "APIエラー: " . htmlspecialchars($response["error"]["message"]);
    echo "<pre>" . htmlspecialchars($response_json) . "</pre>";
    exit;
}

$content = $response["choices"][0]["message"]["content"] ?? "";
$ai      = is_string($content) ? json_decode($content, true) : $content;

if (!is_array($ai)) {
    echo "AIレスポンス解析に失敗しました。<pre>";
    echo htmlspecialchars(print_r($response, true));
    echo "</pre>";
    exit;
}

// ===== AIデータ抽出 =====
$overall_risk_score = $ai["overall_risk_score"] ?? 0;
$highlight_spans    = $ai["highlight_spans"] ?? [];
$summary_reason     = $ai["summary_reason"] ?? "";

$suggested_soft     = $ai["suggested_texts"]["soft"]     ?? "";
$suggested_business = $ai["suggested_texts"]["business"] ?? "";
$suggested_humor    = $ai["suggested_texts"]["humor"]    ?? "";

// 文字数
$len_input    = mb_strlen($input_text, 'UTF-8');
$len_soft     = mb_strlen($suggested_soft, 'UTF-8');
$len_business = mb_strlen($suggested_business, 'UTF-8');
$len_humor    = mb_strlen($suggested_humor, 'UTF-8');

// ===== Twitter投稿URL生成 =====
function tweet_url($text) {
    if (!$text) return "";
    if (mb_strlen($text, 'UTF-8') > 140) {
        $text = mb_substr($text, 0, 140, 'UTF-8');
    }
    return "https://twitter.com/intent/tweet?text=" . urlencode($text);
}

$tweet_original_url = tweet_url($input_text);
$tweet_soft_url     = tweet_url($suggested_soft);
$tweet_business_url = tweet_url($suggested_business);
$tweet_humor_url    = tweet_url($suggested_humor);

// ===== 炎マークとラベル =====
function fire_marks($score) {
    $score = max(0, min(5, (int)$score));
    return str_repeat("🔥", $score) . str_repeat("・", 5 - $score);
}

function risk_label_from_score($score) {
    $score = (int)$score;
    switch ($score) {
        case 1: return "ごく低い";
        case 2: return "低い";
        case 3: return "中程度";
        case 4: return "高い";
        case 5: return "非常に高い";
        default: return "不明";
    }
}

$overall_risk_label = risk_label_from_score($overall_risk_score);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>SNS投稿チェック結果｜XPost AI Checker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body {
    font-family: "Noto Sans JP", system-ui, sans-serif;
    background:#fafafa;
    padding:20px;
    color:#333;
    line-height:1.7;
}

/* 共通カード */
.card {
    background:#fff;
    padding:20px;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.05);
    max-width:800px;
    margin:0 auto 22px auto;
}

/* 見出し（左に黄色ライン） */
.section-title {
    font-size:1rem;
    font-weight:600;
    margin:0 0 10px 0;
    padding-left:10px;
    border-left:4px solid #ffca28;
}

/* 注意カード */
.caution-card {
    background:#fffdf5;
}

/* 凡例 */
.legend-list {
    font-size:0.9rem;
    margin-top:8px;
    padding-left:18px;
}

/* Xボタン */
.tweet-btn {
    display:inline-block;
    padding:10px 14px;
    background:#1d9bf0;
    color:#fff;
    border-radius:8px;
    text-decoration:none;
    font-size:0.9rem;
    margin-top:14px;
}
.tweet-btn:hover {
    background:#0d8adf;
}
</style>
</head>
<body>

<!-- 🔥 炎上リスク -->
<div class="card">
    <h3 class="section-title">炎上リスク</h3>
    <div>
        <b style="font-size:1.2rem; color:#ff5722;">
            <?= fire_marks($overall_risk_score) ?>
        </b>
        （<?= htmlspecialchars($overall_risk_label) ?>）
    </div>
    <ul class="legend-list">
        <li>🔥・・・・・・：ごく低い（ほぼ安全）</li>
        <li>🔥🔥・・・・・：低い（少し注意）</li>
        <li>🔥🔥🔥・・・・：中程度（多少リスクあり）</li>
        <li>🔥🔥🔥🔥・・・：高い（注意が必要）</li>
        <li>🔥🔥🔥🔥🔥：非常に高い（炎上リスク大）</li>
    </ul>
</div>

<!-- 📝 元の投稿 -->
<div class="card">
    <h3 class="section-title">元の投稿</h3>
    （<?= $len_input ?>/140文字）<br>
    <?= nl2br(htmlspecialchars($input_text)) ?>

    <?php if ($tweet_original_url): ?>
        <br>
        <a class="tweet-btn" target="_blank" href="<?= htmlspecialchars($tweet_original_url) ?>">
            元の投稿のままXに投稿する
        </a>
    <?php endif; ?>
</div>

<!-- 🔍 懸念箇所 -->
<div class="card">
    <h3 class="section-title">懸念箇所</h3>
    <?php if (empty($highlight_spans)): ?>
        特に懸念箇所はありません。
    <?php else: ?>
        <ul>
        <?php foreach ($highlight_spans as $span): ?>
            <li>
                <span style="background:yellow;font-weight:bold;">
                    <?= htmlspecialchars($span["phrase"] ?? "") ?>
                </span><br>
                理由：<?= htmlspecialchars($span["reason"] ?? "") ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<!-- 📘 総評 -->
<div class="card">
    <h3 class="section-title">総評</h3>
    <?= nl2br(htmlspecialchars($summary_reason)) ?>
</div>

<!-- 🟡 修正案：やわらかマイルド版 -->
<div class="card">
    <h3 class="section-title">修正案（やわらかマイルド版）</h3>
    （<?= $len_soft ?>/140文字）<br>
    <?= nl2br(htmlspecialchars($suggested_soft)) ?>

    <?php if ($tweet_soft_url): ?>
        <br>
        <a class="tweet-btn" href="<?= htmlspecialchars($tweet_soft_url) ?>" target="_blank">
            この修正案でXに投稿する
        </a>
    <?php endif; ?>
</div>

<!-- 🟦 修正案：事務的ビジネス版 -->
<div class="card">
    <h3 class="section-title">修正案（事務的ビジネス版）</h3>
    （<?= $len_business ?>/140文字）<br>
    <?= nl2br(htmlspecialchars($suggested_business)) ?>

    <?php if ($tweet_business_url): ?>
        <br>
        <a class="tweet-btn" href="<?= htmlspecialchars($tweet_business_url) ?>" target="_blank">
            この修正案でXに投稿する
        </a>
    <?php endif; ?>
</div>

<!-- 🟩 修正案：ユーモア版 -->
<div class="card">
    <h3 class="section-title">修正案（ユーモア版）</h3>
    （<?= $len_humor ?>/140文字）<br>
    <?= nl2br(htmlspecialchars($suggested_humor)) ?>

    <?php if ($tweet_humor_url): ?>
        <br>
        <a class="tweet-btn" href="<?= htmlspecialchars($tweet_humor_url) ?>" target="_blank">
            この修正案でXに投稿する
        </a>
    <?php endif; ?>
</div>

<!-- ⚠ 注意書き -->
<div class="card caution-card">
    <h3 class="section-title">注意書き</h3>
    AIの判断は100%正確ではありません。<br>
    文脈やタイミングによって結果が変わる場合があります。<br>
    最終的な投稿内容はご自身で確認し、判断してください。
</div>

<div style="text-align:center; margin-top:10px;">
    <a href="index.html">別の文章をチェックする</a>
</div>

</body>
</html>

