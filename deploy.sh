#!/bin/bash
# ============================================
# 考核小管家 v3 部署脚本（Mac 上运行，SMB 本地源码推 NAS）
# 用法: bash deploy.sh
# 作用: 推送 kaohe.html + kaohe-api（13 个 PHP + 12 生肖头像）+ uploads 目录 + .gitignore 到 NAS，
#       自动 git 提交版本，并验证外部地址 HTTP 200
# ============================================
set -e
NAS="flweb@192.168.50.2"
SSH="ssh -o StrictHostKeyChecking=no"
SRC_DIR="/Volumes/no2/项目/豆包/考核网站/考核小管家"
NAS_WEB="/var/services/web"
PHP_FILES="common.php auth.php profile.php admin.php forgot.php feedback.php avatar.php load.php save.php follow.php social.php media.php manifest_tool.php migrate_v355.php blocks.php mutes.php"

echo "[1/5] NAS 侧 php -l 语法检查（php84 优先，php82/默认 php 回退；可用 KAOHE_PHP_LINT 环境变量覆盖）..."
$SSH "$NAS" "rm -rf /tmp/kaohe-lint && mkdir -p /tmp/kaohe-lint"
tar -C "$SRC_DIR/kaohe-api" -cf - $PHP_FILES | $SSH "$NAS" "tar -C /tmp/kaohe-lint -xf -"
LINT_PHP="${KAOHE_PHP_LINT:-}"
$SSH "$NAS" "if [ -n \"$LINT_PHP\" ]; then LP=\"$LINT_PHP\"; else for c in /var/packages/PHP8.4/target/usr/local/bin/php84 /var/packages/PHP8.2/target/usr/local/bin/php82 /usr/bin/php php; do if [ -x \"\$c\" ]; then LP=\"\$c\"; break; fi; done; fi; echo \"lint-php=\$LP\"; for f in /tmp/kaohe-lint/*.php; do \"\$LP\" -l \"\$f\" >/dev/null || { echo \"LINT FAIL: \$f\"; exit 1; }; done && echo LINT_ALL_OK"

echo "[2/5] 推送 kaohe.html 与 kaohe-api ..."
$SSH "$NAS" "cat > $NAS_WEB/kaohe.html" < "$SRC_DIR/kaohe.html"
for f in $PHP_FILES; do
  $SSH "$NAS" "cat > $NAS_WEB/kaohe-api/$f" < "$SRC_DIR/kaohe-api/$f"
done

echo "[2.5/5] 创建 uploads 媒体目录并推送 .htaccess（禁脚本执行）..."
$SSH "$NAS" "mkdir -p $NAS_WEB/kaohe-data/uploads/_pending $NAS_WEB/kaohe-data/uploads/done 2>/dev/null || true"
$SSH "$NAS" "cat > $NAS_WEB/kaohe-data/uploads/.htaccess" < "$SRC_DIR/kaohe-data/uploads/.htaccess"
$SSH "$NAS" "chmod 644 $NAS_WEB/kaohe-data/uploads/.htaccess 2>/dev/null || true"

echo "[3/5] 推送 12 生肖头像 ..."
$SSH "$NAS" "mkdir -p $NAS_WEB/kaohe-api/avatars/zodiac"
tar --exclude='._*' -C "$SRC_DIR/kaohe-api/avatars/zodiac" -cf - . | $SSH "$NAS" "tar -C $NAS_WEB/kaohe-api/avatars/zodiac -xf -"

echo "[4/5] 更新 .gitignore 并 git 提交 ..."
$SSH "$NAS" "cat > $NAS_WEB/.gitignore" < "$SRC_DIR/.gitignore"
MSG="deploy $(date +%Y-%m-%d_%H%M)"
$SSH "$NAS" "cd $NAS_WEB && git add -A && (git diff --cached --quiet || git commit -m \"$MSG\") && git log --oneline -3"

echo "[5/5] 验证页面 ..."
curl -s --max-time 10 -o /dev/null -w "HTTP %{http_code}\n" "http://flweb.synology.me:8081/kaohe.html"
echo "Done."
