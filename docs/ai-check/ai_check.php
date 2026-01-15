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
$len_input    = count_x_chars($input_text);
$len_soft     = count_x_chars($suggested_soft);
$len_business = count_x_chars($suggested_business);
$len_humor    = count_x_chars($suggested_humor);

// X（Twitter）風の文字数カウント（index.html と同じルール）
// - 改行: CRLF → LF に揃える（1改行 = 1文字）
// - ASCII（U+00FF以下）: 0.5文字
// - それ以外（日本語など）: 1文字
// 合計を切り上げて「文字数」とする
function count_x_chars($text) {
    // 改行を正規化
    $normalized = str_replace("\r\n", "\n", $text);
    $len = mb_strlen($normalized, 'UTF-8');
    $weight = 0.0; // 0.5単位で加算

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($normalized, $i, 1, 'UTF-8');
        $code = unpack('N', mb_convert_encoding($ch, 'UTF-32BE', 'UTF-8'))[1];

        if ($code <= 0xFF) {
            // 半角（英数字・半角記号など）
            $weight += 0.5;
        } else {
            // 全角（日本語・全角記号など）
            $weight += 1.0;
        }
    }

    return (int)ceil($weight);
}

// X風のカウントで最大 $max 文字に切り詰める関数
function truncate_x_chars($text, $max = 140) {
    if ($text === '' || $max <= 0) return '';

    $normalized = str_replace("\r\n", "\n", $text);
    $len = mb_strlen($normalized, 'UTF-8');
    $weight = 0.0;
    $result = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($normalized, $i, 1, 'UTF-8');
        $code = unpack('N', mb_convert_encoding($ch, 'UTF-32BE', 'UTF-8'))[1];
        $add  = ($code <= 0xFF) ? 0.5 : 1.0;

        // 次の文字を足したときに 140 を超えるなら終了
        if (ceil($weight + $add) > $max) {
            break;
        }

        $result .= $ch;
        $weight += $add;
    }

    return $result;
}


// ===== Twitter投稿URL生成 =====
function tweet_url($text) {
    if (!$text) return "";
    // X方式で切り詰める
    $text = truncate_x_chars($text, 140);
    return "https://twitter.com/intent/tweet?text=" . urlencode($text);
}

$tweet_original_url = tweet_url($input_text);
$tweet_soft_url     = tweet_url($suggested_soft);
$tweet_business_url = tweet_url($suggested_business);
$tweet_humor_url    = tweet_url($suggested_humor);

// ===== 炎アイコン =====
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
<title>AIチェック結果｜X投稿あんしんチェッカー</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>
body {
    font-family: "Noto Sans JP", system-ui, sans-serif;
    background:#fafafa;
    padding:20px;
    line-height:1.8;
    color:#333;
}

/* カードUI */
.card {
    background:#fff;
    padding:22px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,0.08);
    max-width:800px;
    margin:0 auto 22px auto;
}

/* 見出し（左に黄色ライン） */
.section-title {
    font-size:1.05rem;
    font-weight:600;
    margin-bottom:10px;
    padding-left:10px;
    border-left:4px solid #ffca28;
}

.phrase {
    background: #fff3b0;
    padding:2px 4px;
    border-radius:4px;
    font-weight:bold;
}

/* Tweetボタン */
.tweet-btn {
    display:inline-block;
    margin-top:10px;
    padding:10px 14px;
    background:#1d9bf0;
    color:#fff;
    border-radius:8px;
    text-decoration:none;
}
.tweet-btn:hover {
    background:#0d8adf;
}

/* 注意カード */
.caution {
    background:#fff9e5;
}
</style>

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BDWT0LDTZT"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BDWT0LDTZT');
</script>

</head>

<body>
<script>
if (typeof gtag === 'function') {
  gtag('event', 'ai_check_result_view', {
    service: 'ai-check'
  });
}
</script>

<!-- 🧡 まず最初に：安心の前置き -->
<div class="card" style="background:#f3f8ff;">
    <h3 class="section-title">この結果の見方</h3>
    <div style="font-size:0.95rem; color:#334;">
        この結果は、投稿文が<strong>第三者にどう受け取られる可能性があるか</strong>を整理した「目安」です。<br>
        あなたの意図や人格を評価するものではありません。<br>
        不安が強いときは、まず<strong>誤解されやすい箇所</strong>だけ確認してみてください。
    </div>
</div>

<!-- 🔥 リスク評価 -->
<div class="card">
    <h3 class="section-title">受け取られ方のリスク（目安）</h3>

    <div style="font-size:1.3rem; color:#ff5722;">
        <?= fire_marks($overall_risk_score) ?>（<?= htmlspecialchars($overall_risk_label) ?>）
    </div>

    <ul style="font-size:0.9rem; margin-top:8px; padding-left:18px;">
        <li>🔥・・・・・・：ごく低い（誤解されにくい傾向）</li>
        <li>🔥🔥・・・・・：低い（少しだけ言い回し注意）</li>
        <li>🔥🔥🔥・・・・：中程度（文脈しだいで強く見える可能性）</li>
        <li>🔥🔥🔥🔥・・・：高い（読み手によっては対立を招く可能性）</li>
        <li>🔥🔥🔥🔥🔥：非常に高い（投稿前に整えると安心）</li>
    </ul>
</div>

<!-- 📝 元の文章 -->
<div class="card">
    <h3 class="section-title">元の投稿</h3>
    <div style="margin-bottom:6px; font-size:0.9rem; color:#666;">
        （<?= $len_input ?>/140文字）
    </div>

    <div><?= nl2br(htmlspecialchars($input_text)) ?></div>

    <?php if ($tweet_original_url): ?>
        <a class="tweet-btn" href="<?= htmlspecialchars($tweet_original_url) ?>" target="_blank">このまま投稿する</a>
    <?php endif; ?>
</div>

<!-- 🔍 懸念箇所 -->
<div class="card">
    <h3 class="section-title">誤解されやすい可能性のある箇所</h3>

    <?php if (empty($highlight_spans)): ?>
        目立って誤解されやすい箇所は見当たりませんでした（文脈しだいで印象は変わります）。
    <?php else: ?>
        <ul>
            <?php foreach ($highlight_spans as $s): ?>
                <li>
                    <span class="phrase"><?= htmlspecialchars($s["phrase"]) ?></span><br>
                    理由：<?= htmlspecialchars($s["reason"]) ?>
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

<!-- 🟢 修正案の見方（クッション） -->
<div class="card" style="background:#f7fbf7;">
    <h3 class="section-title">修正案について</h3>
    <div style="font-size:0.95rem; color:#334;">
        以下は、誤解を避けたい場合の<strong>書き方の一例</strong>です。<br>
        必ず修正する必要はありません。あなたが納得できる形で調整してOKです。
    </div>
</div>

<!-- 🟡 修正案（3タイプ） -->
<?php
$variants = [
    ["label" => "やわらかマイルド版", "text" => $suggested_soft,     "len" => $len_soft,     "url" => $tweet_soft_url],
    ["label" => "事務的ビジネス版",   "text" => $suggested_business, "len" => $len_business, "url" => $tweet_business_url],
    ["label" => "ユーモア版",         "text" => $suggested_humor,    "len" => $len_humor,    "url" => $tweet_humor_url],
];

foreach ($variants as $v):
?>
<div class="card">
    <h3 class="section-title">修正案（<?= $v["label"] ?>）</h3>

    <?php
    $desc = "";
    if ($v["label"] === "やわらかマイルド版") $desc = "感情の強さを少し抑え、誤解されにくく整えます。";
    if ($v["label"] === "事務的ビジネス版")   $desc = "事実と要望を整理し、冷静に伝える形に寄せます。";
    if ($v["label"] === "ユーモア版")         $desc = "角が立ちにくい軽い言い回しに寄せます（誰かを笑い者にしません）。";
    ?>
    <div style="margin:-4px 0 10px; font-size:0.9rem; color:#666;">
        <?= htmlspecialchars($desc) ?>
    </div>

    <div style="margin-bottom:6px; font-size:0.9rem; color:#666;">
        （<?= $v["len"] ?>/140文字）
    </div>

    <?= nl2br(htmlspecialchars($v["text"])) ?>

    <?php if ($v["url"]): ?>
    <br>
    <a class="tweet-btn" href="<?= htmlspecialchars($v["url"]) ?>" target="_blank">
        この内容で投稿する
    </a>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- ⚠ 注意 -->
<div class="card caution">
    <h3 class="section-title">注意事項</h3>
    この結果は「目安」です。AIの判定は100%正確ではありません。<br>
    文脈や関係性によって受け取られ方は変わります。<br>
    不安が残る場合は、表現を少し整える／一度下書きに戻すなどで調整してみてください。
</div>

<!-- 戻るボタン -->
<div style="text-align:center; margin-bottom:20px;">
    <a href="index.html">別の文章をチェックする</a>
</div>

<!-- 広告 -->
<div style="font-size:0.8rem; color:#888; margin-bottom:4px;">
【広告】
</div>

<div>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8182034043692523"
     crossorigin="anonymous"></script>
<ins class="adsbygoogle"
     style="display:block"
     data-ad-client="ca-pub-8182034043692523"
     data-ad-slot="5100913315"
     data-ad-format="auto"
     data-full-width-responsive="true"></ins>
<script>
     (adsbygoogle = window.adsbygoogle || []).push({});
</script>
</div>

</body>
</html>

