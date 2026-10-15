#!/bin/bash
# ============================================
# regression.sh — 维护工单回归门禁脚本
# 用途：静态门禁（全量 kaohe-api/*.php 的 php -l，本地无 php 则 tar 管道推 NAS
#       /tmp 临时目录用 php84/php82 lint，结束清理）、前端脚本语法检查、版本号
#       两处一致性核验、线上接口冒烟；按模板生成回归报告。不碰生产数据、不部署。
# 用法：regression.sh <ticket-id> [--skip-online] [--base-url URL]
#   <ticket-id>   工单编号，如 M-TEST（必填）
#   --skip-online 跳过网络冒烟
#   --base-url    冒烟基址，默认取 workflow.config(.local).json 的 deployment.online_url
# 退出码：全部门禁 PASS=0；任一 FAIL=1（SKIP 不算失败）。
# 兼容：macOS 自带 GNU bash 3.2（不使用 bash4 特性）
# ============================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONFIG="$ROOT/.ops/config/workflow.config.json"
LOCAL_CONFIG="$ROOT/.ops/config/workflow.config.local.json"
# 若存在本地不入库配置，则与公开配置深度合并（同名键以本地为准），供部署/冒烟读取真实值
MERGED_CONFIG="$CONFIG"
CFG_TMP=""
if [ -f "$LOCAL_CONFIG" ]; then
  CFG_TMP="$(mktemp -t kaohe-cfg.XXXXXX)"
  jq -s '.[0] * .[1]' "$CONFIG" "$LOCAL_CONFIG" > "$CFG_TMP"
  MERGED_CONFIG="$CFG_TMP"
fi

TICKET_ID=""
SKIP_ONLINE=0
BASE_URL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --skip-online) SKIP_ONLINE=1; shift ;;
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --base-url=*) BASE_URL="${1#--base-url=}"; shift ;;
    -h|--help) echo "用法: regression.sh <ticket-id> [--skip-online] [--base-url URL]"; exit 0 ;;
    *)
      if [ -z "$TICKET_ID" ]; then TICKET_ID="$1"; shift; else echo "未知参数: $1" >&2; exit 2; fi ;;
  esac
done
if [ -z "$TICKET_ID" ]; then
  echo "用法: regression.sh <ticket-id> [--skip-online] [--base-url URL]" >&2
  exit 2
fi
# BASE_URL 优先级：命令行 --base-url > 环境变量 KAOHE_BASE_URL > 本地配置 online_url 推导 > 占位
ONLINE_URL="$(jq -r '.deployment.online_url // empty' "$MERGED_CONFIG")"
if [ -z "$BASE_URL" ]; then
  BASE_URL="${KAOHE_BASE_URL:-}"
fi
if [ -z "$BASE_URL" ] && [ -n "$ONLINE_URL" ]; then
  BASE_URL="$(printf '%s' "$ONLINE_URL" | sed -E 's#/kaohe\.html$##')"
fi
BASE_URL="${BASE_URL:-https://YOUR_DOMAIN}"
NAS="${KAOHE_NAS:-$(jq -r '.deployment.nas_ssh' "$MERGED_CONFIG")}"
SSH="ssh -o ConnectTimeout=8 -o BatchMode=yes -o LogLevel=ERROR"

MANDIR="$(mktemp -d -t kaohe-regression.XXXXXX)"
trap 'rm -rf "$MANDIR" "${CFG_TMP:-}"' EXIT
echo "== 回归门禁 $TICKET_ID =="

# ---------- 1. PHP 静态门禁（全量 kaohe-api/*.php，实际数量以 glob 为准） ----------
LINT_MODE=""
LINT_LANG=""
LINT_TOTAL=0
LINT_FAILS=0
if command -v php >/dev/null 2>&1; then
  LINT_MODE="本地 php"
  LINT_LANG="$(command -v php)"
  for f in "$ROOT"/kaohe-api/*.php; do
    LINT_TOTAL=$((LINT_TOTAL + 1))
    if ! php -l "$f" >/dev/null 2>"$MANDIR/lint-err.txt"; then
      LINT_FAILS=$((LINT_FAILS + 1))
      echo "$(basename "$f")|FAIL|$(cat "$MANDIR/lint-err.txt")" >> "$MANDIR/lint.txt"
      echo "  ❌ LINT FAIL: $f" >&2
    else
      echo "$(basename "$f")|OK|" >> "$MANDIR/lint.txt"
    fi
  done
else
  LINT_MODE="NAS /tmp 临时目录 php -l"
  TAG="$(echo "$TICKET_ID" | tr -dc 'A-Za-z0-9_-')"
  REMOTE_DIR="/tmp/kaohe-lint-${TAG:-x}-$$"
  echo "本地无 php，走 NAS lint（${NAS}）..."
  $SSH "$NAS" "rm -rf '$REMOTE_DIR' && mkdir -p '$REMOTE_DIR'"
  if [ -n "${KAOHE_PHP_LINT:-}" ]; then
    LINT_LANG="$KAOHE_PHP_LINT"
  else
    LINT_LANG="$($SSH "$NAS" 'for c in /var/packages/PHP8.4/target/usr/local/bin/php84 /var/packages/PHP8.2/target/usr/local/bin/php82 /usr/bin/php php; do if [ -x "$c" ]; then echo "$c"; break; fi; done')"
  fi
  echo "lint 解释器：$LINT_LANG"
  (cd "$ROOT/kaohe-api" && tar -cf - *.php) | $SSH "$NAS" "tar -C '$REMOTE_DIR' -xf -"
  cat > "$MANDIR/remote-lint.sh" <<REMOTE
set -u
LP="$LINT_LANG"
RDIR="$REMOTE_DIR"
for f in "\$RDIR"/*.php; do
  out=\$("\$LP" -l "\$f" 2>&1)
  rc=\$?
  b=\$(basename "\$f")
  if [ \$rc -eq 0 ]; then echo "\$b|OK|"; else echo "\$b|FAIL|\$out"; fi
done
REMOTE
  $SSH "$NAS" 'bash -s' < "$MANDIR/remote-lint.sh" > "$MANDIR/lint.txt"
  $SSH "$NAS" "rm -rf '$REMOTE_DIR'" || true
  while IFS='|' read -r _name st _detail; do
    LINT_TOTAL=$((LINT_TOTAL + 1))
    if [ "$st" = "FAIL" ]; then
      LINT_FAILS=$((LINT_FAILS + 1))
    fi
  done < "$MANDIR/lint.txt"
fi
if [ "$LINT_FAILS" -gt 0 ]; then
  echo "  ❌ php -l：$LINT_FAILS/$LINT_TOTAL 失败" >&2
else
  echo "  ✅ php -l：$LINT_TOTAL 个 PHP 全部通过（${LINT_MODE}）" >&2
fi

# ---------- 2. 前端脚本语法检查（尽力而为，不阻塞） ----------
FE_RESULT="SKIP"
FE_DETAIL="本地无 node"
if command -v node >/dev/null 2>&1; then
  FE_DIR="$MANDIR/js"
  mkdir -p "$FE_DIR"
  NBLOCKS="$(ROOT="$ROOT" FE_DIR="$FE_DIR" python3 - <<'PY'
import os, re
html = open(os.path.join(os.environ["ROOT"], "kaohe.html"), encoding="utf-8").read()
blocks = re.findall(r"<script([^>]*)>(.*?)</script>", html, re.S | re.I)
i = 0
for attrs, code in blocks:
    if "src=" in attrs:
        continue
    code = code.strip()
    if not code:
        continue
    i += 1
    open(os.path.join(os.environ["FE_DIR"], "block%d.js" % i), "w", encoding="utf-8").write(code)
print(i)
PY
)"
  FE_FAIL=0
  for j in "$FE_DIR"/*.js; do
    [ -e "$j" ] || continue
    if ! node --check "$j" 2>"$MANDIR/fe-err.txt"; then
      FE_FAIL=$((FE_FAIL + 1))
      echo "  ❌ 前端脚本语法错误：$(basename "$j")" >&2
      cat "$MANDIR/fe-err.txt" >&2
    fi
  done
  if [ "$FE_FAIL" -gt 0 ]; then
    FE_RESULT="FAIL"; FE_DETAIL="$NBLOCKS 个内联脚本块，$FE_FAIL 个语法错误"
  else
    FE_RESULT="PASS"; FE_DETAIL="$NBLOCKS 个内联脚本块 node --check 全部通过"
  fi
  echo "  前端语法检查：${FE_RESULT}（${FE_DETAIL}）" >&2
else
  echo "  ⏭️ 前端语法检查 SKIP（本地无 node）" >&2
fi

# ---------- 3. 版本号两处一致核验 ----------
read -r V1 V2 <<EOF
$(ROOT="$ROOT" python3 - <<'PY'
import os, re
html = open(os.path.join(os.environ["ROOT"], "kaohe.html"), encoding="utf-8").read()
v1 = v2 = ""
m = re.search(r'class="rk-ver"[^<]*?v(\d+\.\d+\.\d+)', html)
if m: v1 = "v" + m.group(1)
m = re.search(r'<footer>[^<]*?v(\d+\.\d+\.\d+)', html)
if m: v2 = "v" + m.group(1)
print("%s %s" % (v1 or "-", v2 or "-"))
PY
)
EOF
if [ -n "$V1" ] && [ "$V1" = "$V2" ]; then
  VER_RESULT="PASS"; VER_VERSION="$V1"
  echo "  ✅ 版本号一致：${V1}（rk-ver / footer）" >&2
else
  VER_RESULT="FAIL"; VER_VERSION="$V1"
  echo "  ❌ 版本号不一致或缺失：rk-ver=$V1 footer=$V2" >&2
fi

# ---------- 4. 线上冒烟 ----------
SMOKE_NOTE=""
if [ "$SKIP_ONLINE" = 1 ]; then
  SMOKE_NOTE="--skip-online，跳过冒烟"
  echo "  ⏭️ 线上冒烟：跳过" >&2
else
  echo "  线上冒烟（base=${BASE_URL}）..." >&2
  while IFS= read -r ep; do
    name="$(echo "$ep" | jq -r '.name')"
    if echo "$ep" | jq -e 'has("url")' >/dev/null 2>&1; then
      url="$(echo "$ep" | jq -r '.url')"
      expect="$(echo "$ep" | jq -r '.expect_status')"
      code="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)"
      if [ "$code" = "$expect" ]; then st=PASS; else st=FAIL; fi
      echo "$name|HTTP $expect|HTTP $code|$st" >> "$MANDIR/smoke.txt"
    else
      api="$(echo "$ep" | jq -r '.api')"
      code="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/$api" 2>/dev/null || echo 000)"
      case "$code" in
        000|404|5??) st=FAIL ;;
        *) st=PASS ;;
      esac
      echo "$name|存在/可响应|HTTP $code|$st" >> "$MANDIR/smoke.txt"
    fi
  done <<< "$(jq -c '.regression.smoke_endpoints[]' "$MERGED_CONFIG")"
fi

# ---------- 汇总 ----------
OVERALL="PASS"
[ "$LINT_FAILS" -gt 0 ] && OVERALL="FAIL"
[ "$FE_RESULT" = "FAIL" ] && OVERALL="FAIL"
[ "$VER_RESULT" = "FAIL" ] && OVERALL="FAIL"
if [ "$SKIP_ONLINE" = 0 ] && [ -f "$MANDIR/smoke.txt" ]; then
  if grep -q '|FAIL|$' "$MANDIR/smoke.txt"; then OVERALL="FAIL"; fi
fi

# ---------- 5. 生成回归报告 ----------
mkdir -p "$ROOT/.ops/evidence/$TICKET_ID"
ROOT="$ROOT" TICKET="$TICKET_ID" MANDIR="$MANDIR" OVERALL="$OVERALL" \
LINT_MODE="$LINT_MODE" LINT_LANG="$LINT_LANG" LINT_TOTAL="$LINT_TOTAL" LINT_FAILS="$LINT_FAILS" \
FE_RESULT="$FE_RESULT" FE_DETAIL="$FE_DETAIL" VER_RESULT="$VER_RESULT" VER_VERSION="$VER_VERSION" \
python3 - <<'PY'
import os, json
from datetime import datetime, timedelta, timezone
TZ = timezone(timedelta(hours=8))
now = datetime.now(TZ)

ticket = os.environ["TICKET"]
mandir = os.environ["MANDIR"]
overall = os.environ["OVERALL"]

lint_rows = []
if os.path.exists(os.path.join(mandir, "lint.txt")):
    for ln in open(os.path.join(mandir, "lint.txt"), encoding="utf-8"):
        ln = ln.rstrip("\n")
        if not ln:
            continue
        parts = ln.split("|", 2)
        name, st = parts[0], parts[1]
        detail = parts[2] if len(parts) > 2 else ""
        lint_rows.append((name, st, detail))
lint_fail_names = [n for n, s, _ in lint_rows if s == "FAIL"]

smoke_rows = []
sp = os.path.join(mandir, "smoke.txt")
if os.path.exists(sp):
    for ln in open(sp, encoding="utf-8"):
        ln = ln.rstrip("\n")
        if ln:
            smoke_rows.append(ln.split("|"))

lint_mark = "✅" if not lint_fail_names else "❌"
lint_desc = "%s；解释器：%s；共 %d 个" % (os.environ["LINT_MODE"], os.environ["LINT_LANG"], int(os.environ["LINT_TOTAL"]))
if lint_fail_names:
    lint_desc += "；失败：%s" % "、".join(lint_fail_names)

fe_mark = {"PASS": "✅", "FAIL": "❌", "SKIP": "⏭️"}[os.environ["FE_RESULT"]]
ver_mark = "✅" if os.environ["VER_RESULT"] == "PASS" else "❌"
ver_desc = "rk-ver=%s" % os.environ["VER_VERSION"]

summary = "静态门禁%s；前端%s；版本核验%s" % (
    "通过" if not lint_fail_names else "失败",
    os.environ["FE_RESULT"],
    os.environ["VER_RESULT"])

lines = []
lines.append("---")
lines.append("ticket: %s" % ticket)
lines.append("ran_at: %s" % now.isoformat(timespec="seconds"))
lines.append("ran_by: regression.sh")
lines.append("version: %s" % os.environ["VER_VERSION"])
lines.append("result: %s" % overall)
lines.append("summary: %s" % summary)
lines.append("---")
lines.append("")
lines.append("# 回归报告 %s" % ticket)
lines.append("")
lines.append("## 1. 静态检查")
lines.append("| 项 | 结果 | 说明 |")
lines.append("|---|---|---|")
lines.append("| PHP `php -l`（全部 kaohe-api/*.php） | %s | %s |" % (lint_mark, lint_desc))
lines.append("| 前端语法/加载检查（kaohe.html 内联 script） | %s | %s |" % (fe_mark, os.environ["FE_DETAIL"]))
lines.append("| 版本号两处一致（rk-ver / footer） | %s | %s |" % (ver_mark, ver_desc))
lines.append("")
lines.append("## 2. 关键接口冒烟")
lines.append("| 接口/页面 | 期望 | 实际 | 结果 |")
lines.append("|---|---|---|---|")
if smoke_rows:
    for r in smoke_rows:
        if len(r) < 4:
            continue
        mark = "✅" if r[3] == "PASS" else "❌"
        lines.append("| %s | %s | %s | %s |" % (r[0], r[1], r[2], mark))
else:
    lines.append("| （--skip-online 跳过） | - | - | ⏭️ |")
lines.append("")
lines.append("## 3. 缺陷复现验证（staging 隔离账号）")
lines.append("- 使用账号：")
lines.append("- 修复前复现：⬜ 能复现 / 描述")
lines.append("- 修复后验证：⬜ 通过 / 描述")
lines.append("- 证据截图/日志：`.ops/evidence/%s/`" % ticket)
lines.append("")
lines.append("## 4. 数据安全（如涉及数据修复）")
lines.append("- 生产数据是否备份：⬜ 备份文件名/时间戳")
lines.append("- 是否影响其他用户：⬜")
lines.append("- 备份恢复方式：")
lines.append("")
lines.append("## 5. 回归范围影响面（自查）")
lines.append("- 改动文件：")
lines.append("- 可能受影响的关联功能：")
lines.append("- 是否需要全量回归（还是仅冒烟）：")
lines.append("")
lines.append("## 结论")
lines.append("- **PASS** → 进入 DEPLOYING")
lines.append("- **FAIL** → 回 REWORK，记录失败项与原因，rework_count +1")
if lint_fail_names:
    lines.append("- FAIL 项：php -l 失败文件 %s" % "、".join(lint_fail_names))
if os.environ["FE_RESULT"] == "FAIL":
    lines.append("- FAIL 项：前端脚本语法错误")
if os.environ["VER_RESULT"] == "FAIL":
    lines.append("- FAIL 项：版本号两处不一致（rk-ver=%s）" % os.environ["VER_VERSION"])
for r in smoke_rows:
    if len(r) >= 4 and r[3] == "FAIL":
        lines.append("- FAIL 项：冒烟 %s 实际 %s" % (r[0], r[2]))
lines.append("")

out = os.path.join(os.environ["ROOT"], ".ops/evidence/%s/regression-report.md" % ticket)
open(out, "w", encoding="utf-8").write("\n".join(lines))
print(out)
PY

echo "" >&2
if [ "$OVERALL" = "PASS" ]; then
  echo "== 回归结果：PASS（退出码 0） ==" >&2
  exit 0
else
  echo "== 回归结果：FAIL（退出码 1），详见报告 ==" >&2
  exit 1
fi
