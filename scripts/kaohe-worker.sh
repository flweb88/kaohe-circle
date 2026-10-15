#!/usr/bin/env bash
# ============================================================
# kaohe-worker.sh — 考核小管家 MVP-5「Mac 侧媒体压缩工人」（单轮）
#
# 用途：由 launchd 每 30~60s 调用（也可手动 bash kaohe-worker.sh），
#       单轮做一次：轮询 NAS _pending → ssh cat 拉取 → sips/ffmpeg 压缩
#       → ssh cat 回传 _incoming/{id} → ssh 调 NAS 侧 manifest_tool.php 落位。
#
# 设计要点（对应方案 §7）：
#   - 轮询：单轮运行，无活即静默退出（正常轮次 stdout 无输出）。
#   - 拉取/回传：ssh 原始二进制管道（ssh 'cat remote' > local / ssh 'cat >' < local，
#     deploy.sh 同款）；实测群晖 sshd 拒绝 `rsync --server`，故不用 rsync；禁用 scp/sftp/base64。
#   - 认证：ssh BatchMode 走密钥；检测到需要密码即失败退出（绝不弹密码、绝不写明文）。
#   - 退避：NAS 停机（如周三 01:30-08:00）时指数退避、上限 5 分钟，静默不刷日志。
#   - 缓存：每批结束清空；上限 5GB，超限按 mtime 先清最旧。
#   - 安全：产物本地 file(1) 类型复核 + NAS 侧 finfo 白名单双校验后才落正式区。
#
# 可配置项：下方常量可用环境变量覆盖，也可写 ~/.config/kaohe-worker.conf。
# ============================================================
set -uo pipefail
umask 077

# ---------------- 可配置常量 ----------------
NAS_HOST="${KAOHE_NAS:-}"
NAS_UPLOADS="${KAOHE_NAS_UPLOADS:-/var/services/web/kaohe-data/uploads}"
REMOTE_PHP="${KAOHE_REMOTE_PHP:-/var/packages/PHP8.2/target/usr/local/bin/php82}"
REMOTE_TOOL="${KAOHE_REMOTE_TOOL:-/var/services/web/kaohe-api/manifest_tool.php}"
REMOTE_RSYNC="${KAOHE_REMOTE_RSYNC:-/usr/bin/rsync}"
CACHE_DIR="${KAOHE_CACHE:-$HOME/.cache/kaohe-worker}"
LOG_DIR="${KAOHE_LOG_DIR:-$HOME/.local/log}"
SIPS_BIN="${KAOHE_SIPS:-/usr/bin/sips}"
FFMPEG_BIN="${KAOHE_FFMPEG:-}"           # 留空自动 PATH 探测；找不到则 >50MB 视频判 failed
IMG_BYTES=$((3 * 1024 * 1024))          # 图片 >3MB 才压 orig
VID_BYTES=$((50 * 1024 * 1024))         # 视频 >50MB 才压 720p
CACHE_LIMIT=$((5 * 1024 * 1024 * 1024)) # 缓存上限 5GB
PENDING_MIN_AGE=15                       # 跳过上传未满 15s 的文件，避免抓半文件
BACKOFF_BASE=30                          # 指数退避起点（秒）
BACKOFF_MAX=300                          # 退避上限 5 分钟
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=2"

CONF="$HOME/.config/kaohe-worker.conf"
[ -f "$CONF" ] && . "$CONF"

# M-0001：已去除硬编码内网地址；NAS_HOST 必须由 KAOHE_NAS 环境变量或 conf 显式提供。
# 为空时明确报错并以非 0 退出（避免 ssh 以空主机名静默失败/误连）。
if [ -z "$NAS_HOST" ]; then
  echo "kaohe-worker: NAS_HOST 未设置。请设置 KAOHE_NAS，如 export KAOHE_NAS='用户@NAS主机'（亦可写入 ${CONF}）。" >&2
  exit 3
fi

# ffmpeg 自动探测（launchd/非交互 shell 的 PATH 不含 ~/.local/bin，command -v 探测会落空 → 显式 fallback 到固定安装路径；v3.5.4 补丁）
if [ -z "$FFMPEG_BIN" ]; then
  FFMPEG_BIN="$(command -v ffmpeg 2>/dev/null || true)"
fi
if [ -z "$FFMPEG_BIN" ] && [ -x "$HOME/.local/bin/ffmpeg" ]; then
  FFMPEG_BIN="$HOME/.local/bin/ffmpeg"
fi

# ---------------- 目录与日志 ----------------
LOG_FILE="$LOG_DIR/kaohe-worker.log"
STATE_DIR="$CACHE_DIR/.state"
mkdir -p "$CACHE_DIR/pending" "$CACHE_DIR/prod" "$STATE_DIR" "$LOG_DIR"

# 日志滚动：超过 1MB 轮转为 .1（只保留一份历史）
if [ -f "$LOG_FILE" ]; then
  sz=$(stat -f %z "$LOG_FILE" 2>/dev/null || echo 0)
  [ "$sz" -gt 1048576 ] && mv "$LOG_FILE" "$LOG_FILE.1" 2>/dev/null || true
fi
log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG_FILE"; }

# ---------------- 退避门控：NAS 停机窗口静默重试 ----------------
now_ts=$(date +%s)
last_fail_f="$STATE_DIR/last_fail"
streak_f="$STATE_DIR/streak"
streak=0
[ -f "$streak_f" ] && streak=$(cat "$streak_f" 2>/dev/null || echo 0)
case "$streak" in (*[!0-9]*) streak=0;; esac

if [ -f "$last_fail_f" ]; then
  last=$(cat "$last_fail_f" 2>/dev/null || echo 0)
  case "$last" in (*[!0-9]*) last=0;; esac
  shift_n=$streak; [ $shift_n -gt 5 ] && shift_n=5
  delay=$(( BACKOFF_BASE * (1 << shift_n) ))
  [ $delay -gt $BACKOFF_MAX ] && delay=$BACKOFF_MAX
  if [ $((now_ts - last)) -lt $delay ]; then
    exit 0   # 仍在退避窗口：静默退出，不唤醒 NAS、不写日志
  fi
fi

# 轻量连通性探测（BatchMode：无密钥/密码即失败，绝不交互）
if ! ssh $SSH_OPTS "$NAS_HOST" "echo ok" >/dev/null 2>&1; then
  new_streak=$((streak + 1)); [ $new_streak -gt 8 ] && new_streak=8
  echo "$now_ts" >"$last_fail_f"
  echo "$new_streak" >"$streak_f"
  log "nas unreachable (streak=$new_streak), silent backoff up to ${BACKOFF_MAX}s"
  exit 0
fi
# NAS 恢复：复位退避状态
rm -f "$last_fail_f"
echo 0 >"$streak_f"

# ---------------- 缓存上限：超 5GB 先清最旧（按 mtime） ----------------
enforce_cache_quota() {
  local used limit_kb oldest
  used=$(du -sk "$CACHE_DIR" 2>/dev/null | awk '{print $1}')
  [ -z "$used" ] && return 0
  limit_kb=$(( CACHE_LIMIT / 1024 ))
  while [ "$used" -gt "$limit_kb" ]; do
    oldest=$(find "$CACHE_DIR/pending" "$CACHE_DIR/prod" -type f \
      -exec stat -f '%m %N' {} \; 2>/dev/null | sort -n | head -1 | cut -d' ' -f2-)
    [ -z "$oldest" ] && break
    rm -f "$oldest"
    used=$(du -sk "$CACHE_DIR" 2>/dev/null | awk '{print $1}')
  done
}
enforce_cache_quota

# ---------------- 拉取 _pending 新文件（ssh cat 管道） ----------------
# 注：实测群晖 sshd 拒绝执行 `rsync --server`（rsync over ssh 不可用），
#     故采用 deploy.sh 同款原始二进制管道 ssh 'cat remote' > local（非 base64/scp/sftp）。
# 远端 shell 列出上传已满 PENDING_MIN_AGE 秒的文件，避免抓半上传。
files=()
remote_names=$(ssh $SSH_OPTS "$NAS_HOST" "
  cd '$NAS_UPLOADS/_pending' 2>/dev/null || exit 0
  now=\$(date +%s)
  for f in *; do
    [ -f \"\$f\" ] || continue
    mt=\$(stat -c %Y \"\$f\" 2>/dev/null || echo 0)
    [ \$((now-mt)) -ge $PENDING_MIN_AGE ] && echo \"\$f\"
  done" 2>>"$LOG_FILE" || true)
for fname in $remote_names; do
  case "$fname" in .*|*/*) continue;; esac
  ssh $SSH_OPTS "$NAS_HOST" "cat '$NAS_UPLOADS/_pending/$fname'" > "$CACHE_DIR/pending/$fname" 2>>"$LOG_FILE" \
    && files+=("$CACHE_DIR/pending/$fname") || rm -f "$CACHE_DIR/pending/$fname"
done

if [ ${#files[@]} -eq 0 ]; then
  rm -rf "$CACHE_DIR/pending/"* "$CACHE_DIR/prod/"* 2>/dev/null || true
  exit 0
fi

# ---------------- 类型白名单（v3.5.3：图片补 bmp/avif，视频补 mkv/avi，音频补 opus/flac） ----------------
whitelist_kind() {
  case "$1" in
    jpg|jpeg|png|webp|gif|heic|heif|bmp|avif) echo image ;;
    mp4|mov|m4v|mkv|avi|webm)                echo video ;;
    mp3|m4a|aac|ogg|opus|flac)                echo audio ;;
    *)                                         echo unknown ;;
  esac
}

ok_n=0; fail_n=0
# v3.5.4：视频抽帧封面（-ss 0.1 首帧 → poster.jpg，q:v3）；ffmpeg 缺失/抽帧失败不致命（前端 poster onerror 兜底）
grab_poster() {
  if [ -n "$FFMPEG_BIN" ] && command -v "$FFMPEG_BIN" >/dev/null 2>&1; then
    "$FFMPEG_BIN" -y -ss 0.1 -i "$fpath" -frames:v 1 -q:v 3 "$pdir/$id.poster.jpg" >>"$LOG_FILE" 2>&1 || { posterf=""; return; }
    posterf="$id.poster.jpg"
  else
    posterf=""
  fi
}
for fpath in "${files[@]}"; do
  fname=$(basename "$fpath")
  id="${fname%%.*}"
  ext="${fname##*.}"; ext=$(echo "$ext" | tr 'A-Z' 'a-z')
  kind=$(whitelist_kind "$ext")
  [ "$kind" = unknown ] && { log "skip unknown type: $fname"; continue; }

  size=$(stat -f %z "$fpath")
  pdir="$CACHE_DIR/prod/$id"
  mkdir -p "$pdir"
  origf=""; smf=""; mdf=""; v720f=""; posterf=""; fail_reason=""
  ok_prod=1

  # ---------- 压缩 ----------
  if [ "$kind" = image ]; then
    # HEIC/HEIF：Chrome 不支持 heic 原图直显，orig 一律转 jpg（1080 内，formatOptions 92 保持显示质量）
    if [ "$ext" = heic ] || [ "$ext" = heif ]; then
      "$SIPS_BIN" -s format jpeg -s formatOptions 92 -Z 1080 "$fpath" --out "$pdir/$id.o.jpg" >>"$LOG_FILE" 2>&1 || ok_prod=0
      origf="$id.o.jpg"
    else
      # 其余图片：>3MB 缩到最大边 1080；否则原样
      if [ "$size" -gt "$IMG_BYTES" ]; then
        "$SIPS_BIN" -Z 1080 "$fpath" --out "$pdir/$id.o.$ext" >>"$LOG_FILE" 2>&1 || ok_prod=0
      else
        cp "$fpath" "$pdir/$id.o.$ext"
      fi
      origf="$id.o.$ext"
    fi
    # 缩略图 sm(360)/md(720)，强制 jpg 质量 82
    "$SIPS_BIN" -s format jpeg -s formatOptions 82 -Z 360 "$fpath" --out "$pdir/$id.sm.jpg" >>"$LOG_FILE" 2>&1 || ok_prod=0
    "$SIPS_BIN" -s format jpeg -s formatOptions 82 -Z 720 "$fpath" --out "$pdir/$id.md.jpg" >>"$LOG_FILE" 2>&1 || ok_prod=0
    smf="$id.sm.jpg"; mdf="$id.md.jpg"

  elif [ "$kind" = video ]; then
    # v3.5.4：视频帖封面——所有分支用 ffmpeg 抽首帧（-ss 0.1）输出 poster.jpg；抽帧失败不致命
    if [ "$size" -gt "$VID_BYTES" ]; then
      # >50MB：压 720p，只回传压缩版；原视频不回传、不留缓存、不落 NAS 正式区
      if [ -n "$FFMPEG_BIN" ] && command -v "$FFMPEG_BIN" >/dev/null 2>&1; then
        "$FFMPEG_BIN" -y -i "$fpath" \
          -vf scale=-2:720 -c:v libx264 -preset veryfast -crf 28 \
          -c:a aac -b:a 128k -movflags +faststart "$pdir/$id.720.mp4" >>"$LOG_FILE" 2>&1 || ok_prod=0
        v720f="$id.720.mp4"
        grab_poster
      else
        ok_prod=0; fail_reason="ffmpeg_missing"
        log "video $id >50MB but ffmpeg unavailable; mark failed"
      fi
    elif [ "$ext" = mp4 ] || [ "$ext" = webm ]; then
      # ≤50MB 且浏览器原生可播容器（mp4/webm）：原样回传
      origf="$fname"
      cp "$fpath" "$pdir/$fname"
      grab_poster
    else
      # ≤50MB 非原生容器（mov/m4v/mkv/avi）：ffmpeg 统一转 mp4（H.264+AAC，faststart，原分辨率）
      if [ -n "$FFMPEG_BIN" ] && command -v "$FFMPEG_BIN" >/dev/null 2>&1; then
        "$FFMPEG_BIN" -y -i "$fpath" \
          -c:v libx264 -preset veryfast -crf 23 \
          -c:a aac -movflags +faststart "$pdir/$id.720.mp4" >>"$LOG_FILE" 2>&1 || ok_prod=0
        v720f="$id.720.mp4"
        grab_poster
      else
        ok_prod=0; fail_reason="ffmpeg_missing"
        log "video $id container-transcode but ffmpeg unavailable; mark failed"
      fi
    fi
  else # audio：不压缩，原样
    origf="$fname"
    cp "$fpath" "$pdir/$fname"
  fi

  # ---------- Mac 侧产物类型复核（file(1) mime），通过才回传 ----------
  if [ $ok_prod -eq 1 ]; then
    review_role() { # $1=role $2=file
      local pf="$pdir/$2" mt
      [ -f "$pf" ] || return 1
      mt=$(file --mime-type -b "$pf" 2>/dev/null)
      case "$1" in
        sm|md|poster) case "$mt" in image/jpeg) ;; *) return 1;; esac ;;
        video720) case "$mt" in video/mp4)  ;; *) return 1;; esac ;;
        orig)
          case "$kind" in
            image) case "$mt" in image/*) ;; *) return 1;; esac ;;
            video) case "$mt" in video/*) ;; *) return 1;; esac ;;
            audio) case "$mt" in audio/*) ;; *) return 1;; esac ;;
          esac ;;
      esac
      return 0
    }
    [ -n "$origf" ]    && { review_role orig "$origf"    || ok_prod=0; }
    [ -n "$smf" ]      && { review_role sm   "$smf"      || ok_prod=0; }
    [ -n "$mdf" ]      && { review_role md   "$mdf"      || ok_prod=0; }
    [ -n "$posterf" ]  && { review_role poster "$posterf" || posterf=""; }   # 封面抽帧失败不阻断（前端 onerror 兜底）
    [ -n "$v720f" ]    && { review_role video720 "$v720f" || ok_prod=0; }
  fi

  # ---------- 回传 + NAS 侧落位 ----------
  if [ $ok_prod -eq 1 ]; then
    ssh $SSH_OPTS "$NAS_HOST" "mkdir -p '$NAS_UPLOADS/_incoming/$id'" >>"$LOG_FILE" 2>&1
    # 逐文件原始二进制回传（ssh 'cat >' < local）；rsync over ssh 在本机 NAS 不可用
    for pf in "$origf" "$smf" "$mdf" "$posterf" "$v720f"; do
      [ -n "$pf" ] || continue
      ssh $SSH_OPTS "$NAS_HOST" "cat > '$NAS_UPLOADS/_incoming/$id/$pf'" \
        < "$pdir/$pf" >>"$LOG_FILE" 2>&1 || { ok_prod=0; fail_reason="upload_failed"; break; }
    done

    args=(--id "$id" --kind "$kind" --uploads "$NAS_UPLOADS"
          --incoming "$NAS_UPLOADS/_incoming/$id" --pending-file "$fname")
    [ -n "$origf" ] && args+=(--orig "$origf")
    [ -n "$smf" ]   && args+=(--sm "$smf")
    [ -n "$mdf" ]   && args+=(--md "$mdf")
    [ -n "$posterf" ] && args+=(--poster "$posterf")
    [ -n "$v720f" ] && { args+=(--video720 "$v720f" --discard-pending); }
    # 压缩视频：manifest 只记 video720；原 pending 原片由 NAS 侧删除（不进 done/）

    if [ $ok_prod -eq 1 ] && ssh $SSH_OPTS "$NAS_HOST" sudo -n "$REMOTE_PHP" "$REMOTE_TOOL" "${args[@]}" >>"$LOG_FILE" 2>&1; then
      ok_n=$((ok_n + 1))
    else
      [ -z "$fail_reason" ] && fail_reason="manifest_tool"
      ssh $SSH_OPTS "$NAS_HOST" sudo -n "$REMOTE_PHP" "$REMOTE_TOOL" \
        --id "$id" --kind "$kind" --uploads "$NAS_UPLOADS" \
        --pending-file "$fname" --fail "$fail_reason" >>"$LOG_FILE" 2>&1 || true
      fail_n=$((fail_n + 1))
    fi
  else
    # 产物不合格：通知 NAS 标 failed（不动正式区）
    [ -z "$fail_reason" ] && fail_reason="bad_product"
    ssh $SSH_OPTS "$NAS_HOST" sudo -n "$REMOTE_PHP" "$REMOTE_TOOL" \
      --id "$id" --kind "$kind" --uploads "$NAS_UPLOADS" \
      --pending-file "$fname" --fail "$fail_reason" >>"$LOG_FILE" 2>&1 || true
    fail_n=$((fail_n + 1))
  fi
  rm -rf "$pdir"
done

# ---------------- 顺手孤儿清理（pending>7天 failed+清；done>30天删） ----------------
ssh $SSH_OPTS "$NAS_HOST" sudo -n "$REMOTE_PHP" "$REMOTE_TOOL" \
  --uploads "$NAS_UPLOADS" --sweep >>"$LOG_FILE" 2>&1 || true

# ---------------- 批末清空 Mac 缓存 ----------------
rm -rf "$CACHE_DIR/pending/"* "$CACHE_DIR/prod/"* 2>/dev/null || true
exit 0
