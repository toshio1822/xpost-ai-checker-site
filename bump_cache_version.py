import datetime, yaml

stamp = datetime.datetime.now().strftime("%Y%m%d%H%M")
with open("mkdocs.yml", "r", encoding="utf-8") as f:
    cfg = yaml.safe_load(f)

def bump(paths):
    out = []
    for p in paths:
        base, _, _ = p.partition("?v=")
        out.append(f"{base}?v={stamp}")
    return out

if "extra_css" in cfg:
    cfg["extra_css"] = bump(cfg["extra_css"])
if "extra_javascript" in cfg:
    cfg["extra_javascript"] = bump(cfg["extra_javascript"])

with open("mkdocs.yml", "w", encoding="utf-8") as f:
    yaml.safe_dump(cfg, f, allow_unicode=True, sort_keys=False)

