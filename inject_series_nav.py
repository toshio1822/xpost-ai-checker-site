#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import argparse
import re
from pathlib import Path
from typing import List, Tuple, Optional

ROOT = Path("blog")
SERIES_DIRS = None  # None: blog直下の全サブフォルダ

NAV_BEGIN = "<!-- NAV-CHAIN:START -->"
NAV_END   = "<!-- NAV-CHAIN:END -->"

# (./) → (_index.md) 正規化
REL_LINK_PATTERN = re.compile(
    r"""\]\(\s*         # ](
         \./            # ./
         (?P<frag>\#[^) \t\r\n]+)?   # #fragment
         \s*\)          # )
    """, re.VERBOSE
)

# Snippet include（pymdownx.snippets の記法）
SNIPPET_LINE_RE = re.compile(r'^\s*--8<--\s+"(?P<path>[^"]+)"\s*$', re.MULTILINE)
# クーポン系をより優先して検出（ファイル名に coupon を含む）
SNIPPET_COUPON_RE = re.compile(r'^\s*--8<--\s+"(?P<path>[^"]*coupon[^"]+)"\s*$', re.IGNORECASE|re.MULTILINE)

# front matter / タイトル抽出
FRONT_MATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n", re.DOTALL)
TITLE_LINE_RE   = re.compile(r"^title\s*:\s*(.+?)\s*$", re.MULTILINE)
H1_MD_RE        = re.compile(r"^\#\s+(.+?)\s*$", re.MULTILINE)
H1_HTML_RE      = re.compile(r"<h1[^>]*>(.*?)</h1>", re.IGNORECASE | re.DOTALL)
TAG_STRIP_RE    = re.compile(r"<[^>]+>")

def list_series_dirs(root: Path) -> List[Path]:
    if SERIES_DIRS:
        return [root / d for d in SERIES_DIRS]
    return [p for p in root.iterdir() if p.is_dir()]

def sort_key_by_prefix_num(p: Path) -> Tuple[int, str]:
    m = re.match(r"^(\d+)_", p.name)
    num = int(m.group(1)) if m else 999999
    return (num, p.name)

def extract_title(md_text: str) -> Optional[str]:
    fm = FRONT_MATTER_RE.search(md_text)
    if fm:
        block = fm.group(1)
        m = TITLE_LINE_RE.search(block)
        if m:
            return m.group(1).strip().strip("\"'")
    m = H1_MD_RE.search(md_text)
    if m:
        return m.group(1).strip()
    m = H1_HTML_RE.search(md_text)
    if m:
        text = TAG_STRIP_RE.sub("", m.group(1))
        text = re.sub(r"\s+", " ", text).strip()
        return text or None
    return None

def normalize_series_top_links(md_text: str) -> str:
    def _repl(m):
        frag = m.group("frag") or ""
        return f"](_index.md{frag})"
    return REL_LINK_PATTERN.sub(_repl, md_text)

def build_nav_line(prev_rel: Optional[str], prev_title: Optional[str],
                   next_rel: Optional[str], next_title: Optional[str]) -> str:
    if prev_rel and prev_title:
        prev_text = f"前回：[◀ {prev_title}]({prev_rel})"
    else:
        prev_text = "前回：―"
    middle = "[シリーズトップに戻る](_index.md)"
    if next_rel and next_title:
        next_text = f"次回：[{next_title} ▶]({next_rel})"
    else:
        next_text = "次回：―"
    return f"{prev_text} | {middle} | {next_text}"

def wrap_nav_block(line: str) -> str:
    return f"\n{NAV_BEGIN}\n{line}\n{NAV_END}\n"

def inject_before_snippet(md_text: str, nav_block: str) -> Optional[str]:
    """
    クーポンスニペット行の直前、または最初のスニペット行の直前に挿入/置換。
    既存NAVがその周辺にあれば置換。なければ純挿入。
    戻り値: 変更後テキスト（挿入できなければ None）
    """
    # 1) couponスニペットの位置を最優先
    m = SNIPPET_COUPON_RE.search(md_text)
    if not m:
        # 2) 最初に見つかったスニペットの直前
        m = SNIPPET_LINE_RE.search(md_text)
    if not m:
        return None  # スニペットが無い

    insert_pos = m.start()

    # 既存NAVが直前（最大数行内）にあればガード置換
    # シンプルに全文から既存NAVを抜き、その位置に置くロジックにする
    if NAV_BEGIN in md_text and NAV_END in md_text:
        pattern = re.compile(
            re.escape(NAV_BEGIN) + r".*?" + re.escape(NAV_END),
            re.DOTALL
        )
        # まず既存を除去
        md_wo = pattern.sub("", md_text)
        # 新しく nav_block を insert_pos 直前に入れる
        return md_wo[:insert_pos] + nav_block + md_wo[insert_pos:]

    # NAVが無い場合は、そのまま挿入
    return md_text[:insert_pos] + nav_block + md_text[insert_pos:]

def inject_or_replace_nav_at_end(md_text: str, nav_block: str) -> str:
    if NAV_BEGIN in md_text and NAV_END in md_text:
        pattern = re.compile(
            re.escape(NAV_BEGIN) + r".*?" + re.escape(NAV_END),
            re.DOTALL
        )
        return pattern.sub(nav_block.strip(), md_text).rstrip() + "\n"  # 置換
    # 末尾に追加
    if not md_text.endswith("\n"):
        md_text += "\n"
    return md_text + nav_block

def process_series_dir(d: Path, write: bool, backup: bool, ext: str) -> int:
    files = [p for p in sorted(d.glob(f"*{ext}"), key=sort_key_by_prefix_num)
             if p.name != "_index.md" and p.is_file()]
    if not files:
        return 0

    titles, texts = {}, {}
    for p in files:
        t = p.read_text(encoding="utf-8")
        texts[p] = t
        titles[p] = extract_title(t) or p.stem

    changed = 0
    for i, p in enumerate(files):
        text = texts[p]
        text = normalize_series_top_links(text)

        prev_p = files[i-1] if i > 0 else None
        next_p = files[i+1] if i < len(files)-1 else None

        nav_line = build_nav_line(
            prev_p.name if prev_p else None,
            titles.get(prev_p) if prev_p else None,
            next_p.name if next_p else None,
            titles.get(next_p) if next_p else None,
        )
        nav_block = wrap_nav_block(nav_line)

        # まず「スニペット直前」への挿入を試みる
        updated = inject_before_snippet(text, nav_block)
        if updated is None:
            # 見つからなければ末尾（従来動作）
            updated = inject_or_replace_nav_at_end(text, nav_block)

        if updated != texts[p]:
            changed += 1
            print(f"[change] {p}")
            if write:
                if backup:
                    p.with_suffix(p.suffix + ".bak").write_text(texts[p], encoding="utf-8")
                p.write_text(updated, encoding="utf-8")

    return changed

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--write", action="store_true", help="実際にファイルへ書き込み")
    ap.add_argument("--backup", action="store_true", help=".bak バックアップを作成（--write時のみ）")
    ap.add_argument("--root", default=str(ROOT), help="探索ルート（既定: blog）")
    ap.add_argument("--ext", default=".md", help="対象拡張子（既定: .md）")
    args = ap.parse_args()

    root = Path(args.root)
    if not root.exists():
        alt = Path("docs/blog")
        if alt.exists():
            print(f"[info] 指定ルート {root} は存在しません。代わりに {alt} を使います。")
            root = alt
        else:
            raise FileNotFoundError(f"探索ルートが見つかりません: {root} も docs/blog も存在しません。")

    series_dirs = list_series_dirs(root)
    if not series_dirs:
        print(f"[info] シリーズフォルダが見つかりません: {root}/*")
        return

    total_files = 0
    total_changed = 0
    for d in series_dirs:
        if not d.is_dir():
            continue
        changed = process_series_dir(d, args.write, args.backup, args.ext)
        total_changed += changed
        total_files += len(list(d.glob(f"*{args.ext}")))

    mode = "WRITE" if args.write else "DRY-RUN"
    print(f"\n[{mode}] 変更ファイル: {total_changed} / 対象記事: {total_files}")

if __name__ == "__main__":
    main()

