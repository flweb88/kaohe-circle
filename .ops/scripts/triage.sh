#!/bin/bash
# ============================================
# triage.sh — 维护收件箱初评脚本
# 用途：扫描 .ops/inbox/*.md，做必填字段校验、关键词优先级建议、模块归属建议、
#       SLA 时限计算（Asia/Shanghai，P2/P3 按 09:00-22:00 工作时段顺延）、
#       与未关闭工单的重复候选比对。只输出「建议」，绝不修改工单、不自动定 P0。
# 用法：triage.sh [--dry-run]
#   --dry-run  只评估不落盘（不写 run/triage-建议.json）
# 兼容：macOS 自带 GNU bash 3.2（不使用 declare -A / mapfile / ${var,,} 等 bash4 特性）
# ============================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONFIG="$ROOT/.ops/config/workflow.config.json"

DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) echo "用法: triage.sh [--dry-run]"; exit 0 ;;
    *) echo "未知参数: ${arg}（用法: triage.sh [--dry-run]）" >&2; exit 2 ;;
  esac
done

# python3 完成全部解析与计算：人类可读报告走 stderr，机器可读 JSON 走 stdout
RESULT="$(ROOT="$ROOT" DRY_RUN="$DRY_RUN" python3 - <<'PY'
import os, re, json, sys, glob, difflib
from datetime import datetime, timedelta, timezone

ROOT = os.environ["ROOT"]
TZ = timezone(timedelta(hours=8))
NOW = datetime.now(TZ)
CONFIG = os.path.join(ROOT, ".ops/config/workflow.config.json")
INBOX = os.path.join(ROOT, ".ops/inbox")
TICKETS = os.path.join(ROOT, ".ops/tickets")

def log(*a):
    print(*a, file=sys.stderr)

def load_config():
    # 先读公开的 workflow.config.json，若存在本地 workflow.config.local.json 则深度合并覆盖
    with open(CONFIG, encoding="utf-8") as f:
        cfg = json.load(f)
    local = CONFIG[:-5] + ".local.json" if CONFIG.endswith(".json") else ""
    if local and os.path.exists(local):
        with open(local, encoding="utf-8") as f:
            loc = json.load(f)
        def _dm(a, b):
            for k, v in b.items():
                if k.startswith("_"):
                    continue
                if isinstance(v, dict) and isinstance(a.get(k), dict):
                    _dm(a[k], v)
                else:
                    a[k] = v
            return a
        cfg = _dm(cfg, loc)
    return cfg

config = load_config()

def parse_doc(path):
    text = open(path, encoding="utf-8").read()
    m = re.match(r"^---\n(.*?)\n---\n?(.*)$", text, re.S)
    if not m:
        return {}, text
    fm = {}
    for line in m.group(1).splitlines():
        s = line.strip()
        if not s or s.startswith("#"):
            continue
        mm = re.match(r"^([A-Za-z_][\w.-]*):\s*(.*)$", s)
        if not mm:
            continue
        k, v = mm.group(1), mm.group(2).strip()
        # 双引号值：取首个闭合引号内容（允许引号后再带行尾注释，如 title: "xx"  # 说明）；
        # 非引号值：剥离行尾注释。
        qm = re.match(r'^"(.*?)"\s*(?:#.*)?$', v)
        if qm:
            v = qm.group(1)
        else:
            v = re.sub(r"\s+#.*$", "", v).strip()
        fm[k] = v
    return fm, m.group(2)

def body_section(body, heading="现象描述"):
    m = re.search(r"^##\s*" + re.escape(heading) + r"\s*$", body, re.M)
    if not m:
        return body
    seg = body[m.end():]
    nxt = re.search(r"^##\s", seg, re.M)
    return seg[:nxt.start()] if nxt else seg

def body_field(body, label):
    """从「## 现象描述」区抽取 - **label**： 后的内容（含后续续行）。"""
    sec = body_section(body)
    lines = sec.splitlines()
    collected = []
    grabbing = False
    pat = re.compile(r"^\s*-\s*\*\*" + re.escape(label) + r"\*\*\s*[：:]\s*(.*)$")
    for ln in lines:
        if grabbing:
            if re.match(r"^\s*-\s*\*\*", ln) or re.match(r"^\s*#", ln):
                break
            collected.append(ln)
        else:
            mm = pat.match(ln)
            if mm:
                grabbing = True
                if mm.group(1).strip():
                    collected.append(mm.group(1))
    out = []
    for ln in collected:
        s = re.sub(r"^\s*", "", ln)
        s = re.sub(r"^\d+[\.、]\s*", "", s)
        if s.strip():
            out.append(s.strip())
    return "\n".join(out).strip()

def is_blank(v):
    return v is None or str(v).strip() in ("", '""', "''")

QUESTIONS = {
    "title": "title 缺失：请补一句话现象",
    "reproduce": "reproduce 缺失：请补复现步骤（谁、在哪个页面、点了什么）",
    "expected": "expected 缺失：请补期望结果",
    "actual": "actual 缺失：请补实际结果，并附截图/报错文本",
    "environment": "environment 缺失：请补环境（HTTP/HTTPS、设备与浏览器、账号、线上版本号）",
}

def add_work_minutes(dt, minutes):
    """P2/P3 工作时段 09:00-22:00（每日 13h）顺延。"""
    rem = float(minutes)
    cur = dt
    while True:
        ds = cur.replace(hour=9, minute=0, second=0, microsecond=0)
        de = cur.replace(hour=22, minute=0, second=0, microsecond=0)
        if cur < ds:
            cur = ds
            continue
        if cur >= de:
            cur = ds + timedelta(days=1)
            continue
        avail = (de - cur).total_seconds() / 60.0
        if rem <= avail:
            return cur + timedelta(minutes=rem)
        rem -= avail
        cur = ds + timedelta(days=1)

def compute_due(priority, kind):
    pcfg = config["priorities"][priority]
    mins = pcfg["response_sla_minutes"] if kind == "response" else pcfg["fix_sla_minutes"]
    if priority in ("P0", "P1"):
        return NOW + timedelta(minutes=mins)
    return add_work_minutes(NOW, mins)

def norm(s):
    return re.sub(r"\s+", " ", (s or "").lower()).strip()

# 已知未关闭工单（用于重复比对）
known = []
for tp in sorted(glob.glob(os.path.join(TICKETS, "*.md"))):
    if os.path.basename(tp) == ".gitkeep":
        continue
    fm, body = parse_doc(tp)
    if fm.get("status") == "DONE":
        continue
    sym = norm(body_field(body, "复现步骤") + " " + body_field(body, "实际结果"))
    known.append({
        "id": fm.get("id") or os.path.basename(tp),
        "title": fm.get("title", ""),
        "blob": norm((fm.get("title", "") or "") + " " + sym),
    })

# inbox 文件列表
inbox_files = []
for fp in sorted(glob.glob(os.path.join(INBOX, "*.md"))):
    if os.path.basename(fp) == ".gitkeep":
        continue
    inbox_files.append(fp)

log("=" * 60)
log("triage 初评  评估时刻：%s" % NOW.isoformat(timespec="seconds"))
log("=" * 60)

items = []
if not inbox_files:
    log("inbox 无待评估工单")

for fp in inbox_files:
    fm, body = parse_doc(fp)
    title = fm.get("title", "") or ""
    environment = fm.get("environment", "") or ""
    reproduce = body_field(body, "复现步骤")
    expected = body_field(body, "期望结果")
    actual = body_field(body, "实际结果")

    missing = []
    if is_blank(title): missing.append("title")
    if not reproduce: missing.append("reproduce")
    if not expected: missing.append("expected")
    if not actual: missing.append("actual")
    if is_blank(environment): missing.append("environment")
    questions = [QUESTIONS[k] for k in ("title", "reproduce", "expected", "actual", "environment") if k in missing]

    search_text = (title + "\n" + body)
    # 优先级关键词
    order = ["P0", "P1", "P2", "P3"]
    matched_hits = {}
    all_keywords = []
    for p in order:
        kws = config["priorities"][p].get("keywords", [])
        hits = [k for k in kws if k and k in search_text]
        if hits:
            matched_hits[p] = hits
            all_keywords.extend(hits)
    if "P0" in matched_hits:
        suggested = "P0"
    elif matched_hits:
        suggested = min(matched_hits.keys())  # P1<P2<P3
    else:
        suggested = "P2"

    # 模块归属
    mod_hits = []
    for mod, kws in config["module_map"].items():
        hits = [k for k in kws if k and k in search_text]
        if hits:
            mod_hits.append((len(hits), mod))
    mod_hits.sort(key=lambda x: (-x[0], x[1]))
    suggested_modules = [m for _, m in mod_hits]

    # 重复候选
    my_blob = norm(title + " " + reproduce + " " + actual)
    dups = []
    if my_blob:
        for k in known:
            if not k["blob"]:
                continue
            r = difflib.SequenceMatcher(None, my_blob, k["blob"]).ratio()
            if r >= 0.55:
                dups.append({"ticket_id": k["id"], "title": k["title"], "similarity": round(r * 100, 1)})
    dups.sort(key=lambda x: -x["similarity"])
    dups = dups[:3]

    due_response = compute_due(suggested, "response")
    due_fix = compute_due(suggested, "fix")

    if missing:
        suggested_action = "建议标 NEEDS-INFO，按追问清单补全后重跑 triage（不进入 READY）"
    elif dups:
        suggested_action = "疑似重复，请 MainAgent 核对是否并入 %s，不新开单" % "、".join(d["ticket_id"] for d in dups)
    else:
        suggested_action = "信息齐全，建议 MainAgent 确认优先级后落 READY"

    note = ""
    if suggested == "P0":
        note = "P0 仅为关键词初判（命中：%s），必须 MainAgent 复核确认后方可抢占" % "、".join(matched_hits.get("P0", []))
    elif not matched_hits:
        note = "无关键词命中，默认建议 P2，请 MainAgent 复核定级"

    item = {
        "source": os.path.relpath(fp, ROOT),
        "title": title,
        "field_check": {"complete": not missing, "missing": missing, "questions": questions},
        "suggested_priority": suggested,
        "matched_keywords": all_keywords,
        "suggested_modules": suggested_modules,
        "duplicates": dups,
        "due_response_at": due_response.isoformat(timespec="minutes"),
        "due_fix_at": due_fix.isoformat(timespec="minutes"),
        "suggested_action": suggested_action,
        "note": note,
    }
    items.append(item)

    log("")
    log("── %s ──" % item["source"])
    log("标题：%s" % (title or "（空）"))
    if missing:
        log("字段：缺失 %s" % "、".join(missing))
        for q in questions:
            log("  · 追问：%s" % q)
    else:
        log("字段：齐全（title / reproduce / expected / actual / environment）")
    log("建议级别：%s%s" % (suggested, ("（命中：%s）" % "、".join(all_keywords)) if all_keywords else "（无关键词命中）"))
    if suggested_modules:
        log("建议模块：%s" % "、".join(suggested_modules))
    else:
        log("建议模块：（无命中，默认 kaohe.html）")
    if dups:
        log("重复候选：")
        for d in dups:
            log("  · %s「%s」相似度 %.1f%%" % (d["ticket_id"], d["title"], d["similarity"]))
    else:
        log("重复候选：无")
    log("SLA：响应 %s ／ 修复 %s" % (item["due_response_at"], item["due_fix_at"]))
    log("建议动作：%s" % suggested_action)
    if note:
        log("备注：%s" % note)

log("")
if os.environ.get("DRY_RUN") == "1":
    log("（--dry-run：不落盘；正式运行将写入 .ops/scripts/run/triage-建议.json）")

result = {
    "ran_at": NOW.isoformat(timespec="seconds"),
    "dry_run": os.environ.get("DRY_RUN") == "1",
    "items": items,
}
print(json.dumps(result, ensure_ascii=False, indent=2))
PY
)"

# 落盘
if [ "$DRY_RUN" = "1" ]; then
  echo "[dry-run] 未写任何文件；正式运行将写入 $ROOT/.ops/scripts/run/triage-建议.json" >&2
else
  mkdir -p "$ROOT/.ops/scripts/run"
  echo "$RESULT" > "$ROOT/.ops/scripts/run/triage-建议.json"
  echo "已写入：$ROOT/.ops/scripts/run/triage-建议.json" >&2
fi
