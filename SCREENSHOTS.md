# Screenshot Workflow

Playwright を使って、ローカル表示のスクリーンショット取得と差分確認を行うためのメモです。

## 初回セットアップ

```bash
cd /home/toshio/xpost-ai-checker-site
npm install
npx playwright install
```

## 開発中の基本フロー

別ターミナルで MkDocs のローカルサーバーを起動します。

```bash
cd /home/toshio/xpost-ai-checker-site
source .venv/bin/activate
mkdocs serve -a 127.0.0.1:8000
```

その後、必要に応じて以下を実行します。

### 1. 基準画像を作る

```bash
npm run shot:baseline
```

保存先:
- `tmp/screenshots/<timestamp>/`
- `tmp/screenshots/latest/`
- `tmp/screenshots/baseline/`

### 2. 通常のスクリーンショットを撮る

```bash
npm run shot
```

保存先:
- `tmp/screenshots/<timestamp>/`
- `tmp/screenshots/latest/`

### 3. baseline と latest の差分を見る

```bash
npm run shot:diff
```

保存先:
- `tmp/screenshots/diff/`

## 現在の取得対象

- トップページ PC
- トップページ スマホ
- service ページ PC
- evidence ページ スマホ

## おすすめ運用

- 大きな見た目変更の前に `npm run shot:baseline`
- 修正後に `npm run shot`
- 差分確認で `npm run shot:diff`
- 問題なければ commit

## 差分が大きかったとき

- まず `tmp/screenshots/diff/` を見る
- 変更が意図通りなら `npm run shot:baseline` で基準を更新
- 意図しない差分なら CSS や文言を再確認
