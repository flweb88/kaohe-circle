#!/bin/bash
# ============================================
# render-dashboard.sh — 看板数据渲染脚本
# 用途：汇总 .ops/tickets/*.md（含 DONE）+ maintenance-board.md + audit/preempt-*.md，
#       生成 .ops/scripts/run/board-data.js（window.BOARD_DATA = <JSON>;），
#       供 dashboard.html 渲染。幂等；生成后自检 JSON 合法。不改看板、不部署。
# 用法：render-dashboard.sh
# 兼容：macOS 自带 GNU bash 3.2（不使用 bash4 特性）
# ============================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONFIG="$ROOT/.ops/config/workflow.config.json"

mkdir -p "$ROOT/.ops/scripts/run"

ROOT="$ROOT" python3 - <<'PY'
import os, re, json, glob
from datetime import datetime, timedelta, timezone

ROOT = os.environ["ROOT"]
TZ = timezone(timedelta(hours=8))
NOW = datetime.now(TZ)
CONFIG = os.path.join(ROOT, ".ops/config/workflow.config.json")
TICKETS = os.path.join(ROOT, ".ops/tickets")
AUDIT = os.path.join(ROOT, ".ops/audit")
BOARD = os.path.join(ROOT, ".ops/maintenance-board.md")

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

def parse_scalar_fm(fm_text):
    fm = {}
    cur_list_key = None
    for line in fm_text.splitlines():
        s = line.rstrip()
        if not s.strip() or s.strip().startswith("#"):
            continue
        mm = re.match(r"^([A-Za-z_][\w.-]*):\s*(.*)$", s.strip())
        if mm:
            k, v = mm.group(1), mm.group(2).strip()
            qm = re.match(r'^"(.*?)"\s*(?:#.*)?$', v)
            if qm:
                v = qm.group(1)
            elif v:
                v = re.sub(r"\s+#.*$", "", v).strip()
            fm[k] = v
            cur_list_key = k
        # 列表续行由调用方另行处理（preempted_from）
    return fm

def parse_doc(path):
    text = open(path, encoding="utf-8").read()
    m = re.match(r"^---\n(.*?)\n---\n?(.*)$", text, re.S)
    if not m:
        return {}, text
    return parse_scalar_fm(m.group(1)), m.group(2)

def parse_preempt_doc(path):
    """解析带 preempted_from 列表的审计文件。"""
    text = open(path, encoding="utf-8").read()
    m = re.match(r"^---\n(.*?)\n---\n?(.*)$", text, re.S)
    fm = {}
    preempted_items = []
    if m:
        cur_list = None
        cur_item = None
        for line in m.group(1).splitlines():
            s = line.rstrip()
            if not s.strip() or s.strip().startswith("#"):
                continue
            mm = re.match(r"^([A-Za-z_][\w.-]*):\s*(.*)$", s.strip())
            if mm and not re.match(r"^\s", s):
                k, v = mm.group(1), mm.group(2).strip()
                qm = re.match(r'^"(.*?)"\s*(?:#.*)?$', v)
                if qm:
                    v = qm.group(1)
                elif v:
                    v = re.sub(r"\s+#.*$", "", v).strip()
                fm[k] = v
                cur_list = k if v == "" else None
                if v == "" and k == "preempted_from":
                    preempted_items = []
                continue
            if cur_list == "preempted_from":
                mt = re.match(r"^\s*-\s*ticket:\s*(.+?)\s*$", s)
                if mt:
                    if cur_item:
                        preempted_items.append(cur_item)
                    cur_item = {"ticket": mt.group(1)}
                    continue
                ma = re.match(r"^\s*action:\s*(.+?)\s*$", s)
                if ma and cur_item is not None:
                    cur_item["action"] = ma.group(1)
        if cur_item:
            preempted_items.append(cur_item)
    return fm, preempted_items

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

PAUSED_STATES = {"BLOCKED", "NEEDS-INFO"}
WIP_STATES = {"ASSIGNED", "FIXING", "REGRESSING", "REWORK", "DEPLOYING", "VERIFYING", "BLOCKED"}
BACKLOG_STATES = {"TRIAGED", "READY"}

def sla_state(prio, kind, due, created, paused, done):
    """返回 (state, remain_minutes, remain_text)。"""
    if done:
        return "done", None, "已完成"
    if paused:
        return "paused", None, "暂停计时"
    mins = config["priorities"].get(prio, {}).get(
        "response_sla_minutes" if kind == "response" else "fix_sla_minutes", 240)
    if due is None:
        base = created or NOW
        if prio in ("P0", "P1"):
            due = base + timedelta(minutes=mins)
        else:
            due = add_work_minutes(base, mins)
    if due is None:
        return "none", None, ""
    if due.tzinfo is None:
        due = due.replace(tzinfo=TZ)
    remain = (due - NOW).total_seconds() / 60.0
    if remain <= 0:
        return "overdue", int(round(remain)), fmt_remain(remain)
    if mins and remain / mins < 0.25:
        return "due-soon", int(round(remain)), fmt_remain(remain)
    return "ok", int(round(remain)), fmt_remain(remain)

# —— 解析工单 ——
tickets = []
for fp in sorted(glob.glob(os.path.join(TICKETS, "*.md"))):
    if os.path.basename(fp) == ".gitkeep":
        continue
    fm, body = parse_doc(fp)
    status = fm.get("status", "") or ""
    paused = status in PAUSED_STATES
    done = status == "DONE"
    created = parse_dt(fm.get("created_at"))
    assigned = parse_dt(fm.get("assigned_at"))
    deployed = parse_dt(fm.get("deployed_at"))

    resp_due = parse_dt(fm.get("due_response_at"))
    fix_due = parse_dt(fm.get("due_fix_at"))

    r_state = sla_state(fm.get("priority", ""), "response", resp_due, created, paused, done)
    f_state = sla_state(fm.get("priority", ""), "fix", fix_due, created, paused, done)

    if done or deployed:
        active_deadline = "none"
        a_state = ("done", None, "已完成") if done else ("none", None, "")
    elif not assigned:
        active_deadline = "response"
        a_state = r_state
    else:
        active_deadline = "fix"
        a_state = f_state

    badges = []
    try:
        rc = int(fm.get("rework_count", 0) or 0)
    except Exception:
        rc = 0
    if status == "REWORK" and rc > 0:
        badges.append("返工×%d" % rc)
    if status == "BLOCKED":
        badges.append("⏸️ 阻塞")
    if status == "NEEDS-INFO":
        badges.append("⏸️ 待补信息")
    if status == "NEEDS-HUMAN":
        badges.append("🧑‍🔧 需人工")
    if (fm.get("duplicate_of", "") or "").strip():
        badges.append("疑似重复")

    tid = fm.get("id") or os.path.basename(fp)
    tickets.append({
        "queue": "development" if tid.startswith("F-") else "maintenance",
        "id": tid,
        "title": fm.get("title", "") or "",
        "priority": fm.get("priority", "") or "",
        "status": status,
        "module": fm.get("module", "") or "",
        "assignee": fm.get("assignee", "") or "",
        "file_lock": fm.get("file_lock", "") or "",
        "rework_count": rc,
        "duplicate_of": fm.get("duplicate_of", "") or "",
        "created_at": fm.get("created_at", "") or "",
        "assigned_at": fm.get("assigned_at", "") or "",
        "deployed_at": fm.get("deployed_at", "") or "",
        "closed_at": fm.get("closed_at", "") or "",
        "due_response_at": fm.get("due_response_at", "") or "",
        "due_fix_at": fm.get("due_fix_at", "") or "",
        "sla": {
            "response": {"state": r_state[0], "remain_minutes": r_state[1], "remain_text": r_state[2]},
            "fix": {"state": f_state[0], "remain_minutes": f_state[1], "remain_text": f_state[2]},
            "active": {"deadline": active_deadline, "state": a_state[0], "remain_minutes": a_state[1], "remain_text": a_state[2]},
        },
        "badges": badges,
    })

# —— 按 id 前缀拆分：M-/其它 = 维护队列；F- = 开发队列（FEAT） ——
maint_tickets = [t for t in tickets if t["queue"] != "development"]
feat_tickets = [t for t in tickets if t["queue"] == "development"]

# —— 维护口径 WIP / backlog（只遍历维护单，F- 绝不混入） ——
maint_wip = [t for t in maint_tickets if t["status"] in WIP_STATES]
backlog = [t for t in maint_tickets if t["status"] in BACKLOG_STATES]
file_lock = "kaohe.html" if any(t["file_lock"] == "kaohe.html" for t in maint_wip) else None

# —— 开发口径 WIP（以 F- 工单为真值）；board 正则读到的数字仅作对照 ——
feat_wip = [t for t in feat_tickets if t["status"] != "DONE"]

board_dev_wip = 0
if os.path.exists(BOARD):
    btext = open(BOARD, encoding="utf-8").read()
    m = re.search(r"\|\s*修复/开发中\s*\|\s*(\d+)\s*\|", btext)
    if m:
        board_dev_wip = int(m.group(1))

queues = {
    "maintenance": {
        "wip": len(maint_wip),
        "wip_limit": config["queues"]["maintenance"]["wip_limit"],
        "p0_wip_limit": config["queues"]["maintenance"]["p0_wip_limit"],
        "backlog": len(backlog),
        "backlog_limit": config["queues"]["maintenance"]["backlog_limit"],
        "file_lock": file_lock,
    },
    "development": {
        "wip": len(feat_wip),
        "board_wip": board_dev_wip,
        "wip_limit": config["queues"]["development"]["wip_limit"],
        "backlog_limit": config["queues"]["development"]["backlog_limit"],
        "note": "详见各 MVP 文档与总览 §3.1；常态 WIP 1，P0 时可被抢占到 0",
    },
}

# —— FEAT 已关闭明细（全部以 F- 为来源） ——
feat_closed = [t for t in feat_tickets if t["status"] == "DONE"]
feat_closed.sort(key=lambda t: t["closed_at"] or "", reverse=True)
feat_recently_closed = [{
    "id": t["id"], "title": t["title"], "priority": t["priority"], "status": t["status"],
    "module": t["module"], "created_at": t["created_at"], "assigned_at": t["assigned_at"],
    "deployed_at": t["deployed_at"], "closed_at": t["closed_at"], "version": "",
} for t in feat_closed[:10]]
for item in feat_recently_closed:
    fp = os.path.join(TICKETS, item["id"] + ".md")
    if os.path.exists(fp):
        fm, _ = parse_doc(fp)
        item["version"] = fm.get("version", "") or fm.get("resolved_version", "") or ""

# —— SLA 告警与升级建议（仅维护单；F- 增强单不参与维护 SLA） ——
sla_alerts = []
escalations = []
for t in maint_tickets:
    st = t["sla"]["active"]["state"]
    if st in ("overdue", "due-soon"):
        prio = t["priority"]
        action = "处理阻塞原因"
        if st == "overdue":
            if prio == "P1":
                action = "建议升 P0（插入队首，提示是否抢占）"
                escalations.append({"id": t["id"], "from": "P1", "to": "P0", "reason": "P1 SLA 超时，建议升 P0"})
            elif prio == "P2":
                action = "建议升 P1，重排队首"
                escalations.append({"id": t["id"], "from": "P2", "to": "P1", "reason": "P2 SLA 超时，建议升 P1"})
            elif prio == "P0":
                action = "P0 超时，直接提醒 owner"
                escalations.append({"id": t["id"], "from": "P0", "to": "owner", "reason": "P0 已超时"})
        sla_alerts.append({
            "id": t["id"], "title": t["title"], "priority": prio, "status": t["status"],
            "deadline": t["sla"]["active"]["deadline"],
            "level": st, "remain_text": t["sla"]["active"]["remain_text"], "action": action,
        })

# —— P0 抢占审计 ——
p0_preemptions = []
for fp in sorted(glob.glob(os.path.join(AUDIT, "preempt-*.md"))):
    fm, items = parse_preempt_doc(fp)
    preempted_str = "；".join(
        "%s%s" % (it.get("ticket", ""), ("（%s）" % it["action"]) if it.get("action") else "")
        for it in items) or "（无）"
    resolved = (fm.get("resolved_at", "") or "").strip()
    p0_preemptions.append({
        "preempt_id": fm.get("preempt_id", "") or os.path.basename(fp),
        "at": fm.get("at", "") or "",
        "p0_ticket": fm.get("p0_ticket", "") or "",
        "preempted_from": preempted_str,
        "resource_change": fm.get("resource_change", "") or "",
        "restore_status": "已恢复" if resolved else "未恢复",
        "audit_file": os.path.basename(fp),
    })

# —— 最近关闭（维护口径，仅 M-/非 F- 单；F- 已关闭走 feat_recently_closed） ——
closed = [t for t in maint_tickets if t["status"] == "DONE"]
closed.sort(key=lambda t: t["closed_at"] or "", reverse=True)
recently_closed = [{
    "id": t["id"], "title": t["title"], "priority": t["priority"],
    "closed_at": t["closed_at"],
    "version": "", "review_pack": "",
} for t in closed[:10]]
# 补 frontmatter 上的 version / review_pack
for item in recently_closed:
    fp = os.path.join(TICKETS, item["id"] + ".md")
    if os.path.exists(fp):
        fm, _ = parse_doc(fp)
        item["version"] = fm.get("version", "") or fm.get("resolved_version", "") or ""
        item["review_pack"] = fm.get("review_pack", "") or fm.get("review_pack_file", "") or ""

# 合并看板 §6 中 tickets 没有的行
if os.path.exists(BOARD):
    btext = open(BOARD, encoding="utf-8").read()
    sec = re.search(r"^##\s*6\..*?$(.*?)(?=^##\s|\Z)", btext, re.M | re.S)
    have_ids = {c["id"] for c in recently_closed}
    if sec:
        for line in sec.group(1).splitlines():
            if not line.startswith("|") or "---" in line or "ID" in line:
                continue
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if len(cells) < 6:
                continue
            cid = cells[0]
            if not cid or cid in have_ids or "（" in cid:
                continue
            recently_closed.append({
                "id": cid, "title": cells[1], "priority": cells[2],
                "closed_at": cells[3], "version": cells[4], "review_pack": cells[5],
            })
            have_ids.add(cid)

# —— counts（维护口径，仅维护单；F- 不计入维护状态计数） ——
counts = {st: 0 for st in config["states"]}
for t in maint_tickets:
    counts[t["status"]] = counts.get(t["status"], 0) + 1

data = {
    "generated_at": NOW.isoformat(timespec="seconds"),
    "queues": queues,
    "columns": [
        {"key": "INBOX", "label": "收件箱", "statuses": ["INBOX", "NEEDS-INFO"]},
        {"key": "READY", "label": "待分派", "statuses": ["TRIAGED", "READY"]},
        {"key": "FIXING", "label": "修复中", "statuses": ["ASSIGNED", "FIXING", "REWORK", "BLOCKED", "NEEDS-HUMAN"]},
        {"key": "REGRESSING", "label": "回归", "statuses": ["REGRESSING"]},
        {"key": "DEPLOYING", "label": "部署验证", "statuses": ["DEPLOYING", "VERIFYING"]},
        {"key": "DONE", "label": "已关闭", "statuses": ["DONE"]},
    ],
    "tickets": tickets,
    "maint_tickets": maint_tickets,
    "feat_tickets": feat_tickets,
    "feat_wip": feat_wip,
    "feat_recently_closed": feat_recently_closed,
    "sla_alerts": sla_alerts,
    "escalations": escalations,
    "p0_preemptions": p0_preemptions,
    "recently_closed": recently_closed,
    "counts": counts,
}

out_path = os.path.join(ROOT, ".ops/scripts/run/board-data.js")
body = "window.BOARD_DATA = " + json.dumps(data, ensure_ascii=False, indent=2) + ";"
open(out_path, "w", encoding="utf-8").write(body)

# 自检：去掉前缀/尾分号后必须是合法 JSON
stripped = body[len("window.BOARD_DATA = "):].rstrip().rstrip(";")
json.loads(stripped)
print("board-data.js 已生成：%s（tickets=%d, 维护WIP=%d, 维护backlog=%d, 开发WIP=%d, alerts=%d, preemptions=%d）" % (
    out_path, len(tickets), queues["maintenance"]["wip"], queues["maintenance"]["backlog"],
    queues["development"]["wip"], len(sla_alerts), len(p0_preemptions)))
PY
