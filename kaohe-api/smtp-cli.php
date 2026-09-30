<?php
/* 考核小管家 - SMTP 发信 CLI 助手
 * 仅 CLI 调用（PHP-CLI 内置 openssl，可完成 ssl:// SMTP 会话）；
 * FPM（Web Station PHP 8.2 无 openssl）在 common.php::smtp_send 检测到无 ssl transport 时经 exec 调用本脚本。
 * 凭证读取自 kaohe-data/config.php（http 身份可读），本脚本不接收、不输出任何凭证。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/common.php';

$to    = isset($argv[1]) ? (string)$argv[1] : '';
$code6 = isset($argv[2]) ? (string)$argv[2] : '';
if ($to === '' || $code6 === '') { echo '0'; exit(1); }

$ok = smtp_send($to, $code6);
echo $ok ? '1' : '0';
exit($ok ? 0 : 1);
