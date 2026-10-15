#!/bin/bash
# ============================================
# sla-check.sh — 在途工单 SLA 巡检脚本
# 用途：扫描 .ops/tickets/*.md（DONE 除外），按响应/修复 SLA 计算剩余/超时；
#       BLOCKED/NEEDS-INFO 暂停计时；输出告警表 + 升级建议 + 看板 §1 替换片段，
#       并写 .ops/evidence/sla-YYYYMMDD.json。不自动升级、不自动改看板。
# 用法：sla-check.sh [--date YYYY-MM-DD]
#   --date  仅决定 evidence 文件日期戳，默认取 Asia/Shanghai 当天
# 兼容：macOS 自带 GNU bash 3.2（不使用 bash4 特性）
# ============================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONFIG="$ROOT/.ops/config/workflow.config.json"

DATE_ARG=""
while [ $# -gt 0 ]; do
  case "$1" in
    --date) DATE_ARG="${2:-}"; shift 2 ;;
    --date=*) DATE_ARG="${1#--date=}"; shift ;;
    -h|--help) echo "用法: sla-check.sh [--date YYYY-MM-DD]"; exit 0 ;;
    *) echo "未知参数: $1（用法: sla-check.sh [--date YYYY-MM-DD]）" >&2; exit 2 ;;
  esac
done

mkdir -p "$ROOT/.ops/evidence"

RESULT="$(ROOT="$ROOT" DATE_ARG="$DATE_ARG" python3 - <<'PY'
import os, re, json, sys, glob
from datetime import datetime, timedelta, timezone

ROOT = os.environ["ROOT"]
DATE_ARG = os.environ.get("DATE_ARG", "") or ""
TZ = timezone(timedelta(hours=8))
NOW = datetime.now(TZ)
CONFIG = os.path.join(ROOT, ".ops/config/workflow.config.json")
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
        if v.startswith('"') and v.endswith('"') and len(v) >= 2:
            v = v[1:-1]
        else:
            v = re.sub(r"\s+#.*$", "", v).strip()
        fm[k] = v
    return fm, m.group(2)

def parse_dt(s):
    if not s:
        return None
    try:
        s = s.strip()
        if s.endswith("Z"):
            s = s[:-1] + "+00:00"
        return datetime.fromisoformat(s)
    except Exception:
        return None

def add_work_minutes(dt, minutes):
    """P2/P3 工作时段 09:00-22:00（每日 13h）顺延。"""
    rem = float(minutes)
    cur = dt
    while True:
        ds = cur.replace(hour=9, minute=0, second=0, microsecond=0)
        de = cur.replace(hour=22, minute=0, second=0, microsecond=0)
        if cur < ds:
            cur = ds; continue
        if cur >= de:
            cur = ds + timedelta(days=1); continue
        avail = (de - cur).total_seconds() / 60.0
        if rem <= avail:
            return cur + timedelta(minutes=rem)
        rem -= avail
        cur = ds + timedelta(days=1)

def fmt_remain(mins):
    if mins is None:
        return ""
    sign = "已超时" if mins < 0 else "剩余"
    a = abs(int(round(mins)))
    h, m = divmod(a, 60)
    return "%s %dh%02dm" % (sign, h, m) if h else "%s %dm" % (sign, m)

PAUSED = {"BLOCKED", "NEEDS-INFO"}

tickets_out = []
escalations = []
counts = {"red": 0, "orange": 0, "green": 0, "paused": 0}

for fp in sorted(glob.glob(os.path.join(TICKETS, "*.md"))):
    if os.path.basename(fp) == ".gitkeep":
        continue
    fm, body = parse_doc(fp)
    status = fm.get("status", "") or ""
    if status == "DONE":
        continue
    tid = fm.get("id") or os.path.basename(fp)
    title = fm.get("title", "") or ""
    prio = fm.get("priority", "") or ""
    created = parse_dt(fm.get("created_at"))
    assigned = parse_dt(fm.get("assigned_at"))
    deployed = parse_dt(fm.get("deployed_at"))

    row = {
        "id": tid, "title": title, "priority": prio, "status": status,
        "active_deadline": "none", "state": "none", "remain_minutes": None,
        "remain_text": "", "paused": False, "inferred": False,
    }

    if status in PAUSED:
        row["paused"] = True
        row["state"] = "paused"
        row["remain_text"] = "暂停计时"
        counts["paused"] += 1
        tickets_out.append(row)
        continue

    # 计时口径：未 assigned → 响应 SLA；已 assigned 未 deployed → 修复 SLA
    if not assigned:
        kind = "response"
        mins = config["priorities"].get(prio, {}).get("response_sla_minutes", 240)
        due = parse_dt(fm.get("due_response_at"))
        row["inferred"] = due is None
        if due is None:
            base = created or NOW
            if prio in ("P0", "P1"):
                due = base + timedelta(minutes=mins)
            else:
                due = add_work_minutes(base, mins)
    else:
        kind = "fix"
        mins = config["priorities"].get(prio, {}).get("fix_sla_minutes", 2880)
        due = parse_dt(fm.get("due_fix_at"))
        row["inferred"] = due is None
        if due is None:
            base = created or NOW
            if prio in ("P0", "P1"):
                due = base + timedelta(minutes=mins)
            else:
                due = add_work_minutes(base, mins)
    row["active_deadline"] = kind

    if due is None:
        row["state"] = "none"
        row["remain_text"] = "无时间戳"
    else:
        if due.tzinfo is None:
            due = due.replace(tzinfo=TZ)
        remain = (due - NOW).total_seconds() / 60.0
        row["remain_minutes"] = int(round(remain))
        if remain <= 0:
            row["state"] = "overdue"
            row["remain_text"] = fmt_remain(remain)
            counts["red"] += 1
        elif mins and remain / mins < 0.25:
            row["state"] = "due-soon"
            row["remain_text"] = fmt_remain(remain)
            counts["orange"] += 1
        else:
            row["state"] = "ok"
            row["remain_text"] = fmt_remain(remain)
            counts["green"] += 1

        # 升级建议（不自动升级）
        if row["state"] == "overdue":
            if prio == "P1":
                escalations.append({"id": tid, "from": "P1", "to": "P0",
                    "reason": "P1 SLA 已超时，建议升 P0 处置（插入维护队首，提示 MainAgent 是否抢占资源）"})
            elif prio == "P2":
                escalations.append({"id": tid, "from": "P2", "to": "P1",
                    "reason": "P2 SLA 已超时，建议升 P1 并重排到维护队首"})
            elif prio == "P0":
                escalations.append({"id": tid, "from": "P0", "to": "owner",
                    "reason": "P0 已超时，直接提醒 owner（不恢复被抢占资源）"})

    tickets_out.append(row)

if DATE_ARG:
    date_stamp = DATE_ARG.replace("-", "")
else:
    date_stamp = NOW.strftime("%Y%m%d")

result = {
    "ran_at": NOW.isoformat(timespec="seconds"),
    "date": date_stamp,
    "summary": counts,
    "tickets": tickets_out,
    "escalations": escalations,
}

# —— stdout 人类报告 ——
log("=" * 60)
log("SLA 巡检  %s" % NOW.isoformat(timespec="seconds"))
log("=" * 60)
red = [t for t in tickets_out if t["state"] == "overdue"]
orange = [t for t in tickets_out if t["state"] == "due-soon"]
paused = [t for t in tickets_out if t["paused"]]
if red:
    log("")
    log("🔴 已超时：")
    for t in red:
        log("  · %s「%s」[%s/%s] %s" % (t["id"], t["title"], t["priority"], t["status"], t["remain_text"]))
if orange:
    log("")
    log("🟠 临期（剩余<25%）：")
    for t in orange:
        log("  · %s「%s」[%s/%s] %s" % (t["id"], t["title"], t["priority"], t["status"], t["remain_text"]))
log("")
log("🟢 正常：%d 单" % counts["green"])
if paused:
    log("⏸️ 暂停（不计时）：")
    for t in paused:
        log("  · %s「%s」[%s]" % (t["id"], t["title"], t["status"]))
if escalations:
    log("")
    log("升级建议（不自动升级，待 MainAgent 判定）：")
    for e in escalations:
        log("  · %s：%s" % (e["id"], e["reason"]))

# —— 看板 §1 替换片段（严格复刻 maintenance-board.md §1 表格格式） ——
log("")
log("------ 以下片段供 MainAgent 替换看板 §1（脚本不自动改看板） ------")
log("| 标记 | 工单 | 优先级 | 状态 | 剩余/超时 | 动作 |")
log("|---|---|---|---|---|---|")
alert_rows = red + orange
if not alert_rows:
    log("| — | （暂无） | | | | |")
else:
    esc_by_id = {}
    for e in escalations:
        esc_by_id.setdefault(e["id"], e)
    for t in alert_rows:
        mark = "🔴" if t["state"] == "overdue" else "🟠"
        act = ""
        e = esc_by_id.get(t["id"])
        if e:
            act = "建议 %s→%s" % (e["from"], e["to"])
        log("| %s | %s %s | %s | %s | %s | %s |" % (
            mark, t["id"], t["title"], t["priority"], t["status"], t["remain_text"], act))
log("")
log("> 🔴 已超时　🟠 临期(<25%)　🟢 正常　⏸️ 暂停(BLOCKED/NEEDS-INFO)")
log("------ 片段结束 ------")

print(json.dumps(result, ensure_ascii=False, indent=2))
PY
)"

DATE_STAMP="$(echo "$RESULT" | jq -r '.date')"
echo "$RESULT" > "$ROOT/.ops/evidence/sla-${DATE_STAMP}.json"
echo "" >&2
echo "已写入：$ROOT/.ops/evidence/sla-${DATE_STAMP}.json" >&2
