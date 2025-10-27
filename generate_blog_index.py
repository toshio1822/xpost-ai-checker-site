#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
docs/blog/ 配下の *.md から、ブログ一覧 (docs/blog/index.md) を自動生成します。
- 各記事のフロントマターの title / description を使用
- 先頭の連番 (001_, 002_ ...) があれば、降順（新しい番号が上）に並べ替え
- サムネイル無しのカードHTML (.blog-cards / .blog-card) で出力
"""

import re
import os
from pathlib import Path
from typing import Optional, Tuple

DOCS_DIR = Path("docs")
BLOG_DIR = DOCS_DIR / "blog"
OUT_FILE = BLOG_DIR / "index.md"

FRONT_MATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*", re.DOTALL | re.MULTILINE)

def read_front_matter(md_text: str) -> Tuple[Optional[str], Optional[str]]:
    """フロントマターから title / description を取得（無ければ None）"""
    m = FRONT_MATTER_RE.match(md_text)
    if not m:
        return None, None
    block = m.group(1)
    title = None
    desc = None
    # シンプルな key: value だけ想定（複数行の値は想定外）
    for line in block.splitlines():
        if line.strip().startswith("title:"):
            title = line.split(":", 1)[1].strip().strip('"').strip("'")
        elif line.strip().startswith("description:"):
            desc = line.split(":", 1)[1].strip().strip('"').strip("'")
    return title, desc

def sort_key(path: Path) -> Tuple[int, str]:
    """
    先頭の連番（例: 001_...）があれば、それを数値として降順ソートしたいのでキーにする。
    無い場合はファイル名で降順（新しい記事ほど後から作る前提なら変更してOK）。
    """
    name = path.name
    m = re.match(r"^(\d{1,})_", name)
    if m:
        try:
            return (int(m.group(1)), name)
        except ValueError:
            pass
    return (0, name)

def build_card(title: str, desc: str, rel_href: str) -> str:
    safe_title = title or "（無題）"
    safe_desc = desc or ""
    # .mdを削除（MkDocsでは /blog/xxx/ が正しいパス）
    rel_href = rel_href.replace(".md", "/")
    return f"""  <article class="blog-card">
    <h3>{safe_title}</h3>
    <p>{safe_desc}</p>
    <a class="blog-link" href="{rel_href}">記事を読む</a>
  </article>"""

def main() -> None:
    BLOG_DIR.mkdir(parents=True, exist_ok=True)

    items = []
    for md in sorted(BLOG_DIR.glob("*.md")):
        if md.name == "index.md":
            continue
        text = md.read_text(encoding="utf-8")
        title, desc = read_front_matter(text)
        rel = md.name  # blog/ からの相対リンクはファイル名だけでOK
        items.append((md, title, desc, rel))

    # 降順に並べ替え（番号が大きい＝新しい想定）
    items_sorted = sorted(items, key=lambda t: sort_key(t[0]), reverse=True)

    cards_html = "\n\n".join(
        build_card(title, desc, rel) for (_p, title, desc, rel) in items_sorted
    )

    out = f"""---
title: 開発者ブログ
description: XPost AI Checker の背景や仕組み、使い方、リリース情報をまとめたブログ一覧です。
---

# 開発者ブログ

XPost AI Checker の背景や仕組み、使い方を詳しく紹介しています。

<div class="blog-cards">
{cards_html}
</div>
"""

    OUT_FILE.write_text(out.strip() + "\n", encoding="utf-8")
    print(f"[OK] Generated: {OUT_FILE}")

if __name__ == "__main__":
    main()

