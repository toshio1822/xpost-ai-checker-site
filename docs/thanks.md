---
title: ご注文ありがとうございました | XPost AI Checker
---

# 🎉 ご注文ありがとうございます

お支払いが正常に完了しました。  
以下の注文番号を控えて、**発注フォーム** に入力してください。

---

<div id="session-id" style="background:#f6f8fa; padding:0.8rem; border-radius:8px; font-family:monospace; margin:1rem 0; color:#555;">
読み込み中...
</div>

<a class="md-button md-button--primary" href="https://docs.google.com/forms/d/e/1FAIpQLSecT4r8PGt0WKRco_66TC_FJdVEA0oxUVrHg1shlzb9yuYL-g/viewform?usp=header" target="_blank">📝 発注フォームへ進む</a>
> ※ ご入力内容を確認後、1〜2営業日以内に担当よりご連絡いたします。

---

© 2025 XPost AI Checker

<script>
  // URLパラメータから session_id を取得して表示
  const params = new URLSearchParams(window.location.search);
  const sessionId = params.get('session_id');
  const box = document.getElementById('session-id');
  if (box) box.textContent = sessionId || '注文番号が取得できませんでした。';
</script>

<style>
  body {
    font-family: "Noto Sans JP", "Segoe UI", sans-serif;
    background: #fafafa;
    color: #333;
  }
  h1 {
    text-align: center;
    color: #222;
  }
  .btn {
    display: inline-block;
    background: #ffcf33;
    color: #000 !important;
    font-weight: bold;
    text-decoration: none;
    padding: 0.8rem 2rem;
    border-radius: 8px;
    margin: 1.5rem auto;
    transition: 0.2s;
  }
  .btn:hover {
    background: #ffd84d;
  }
</style>

