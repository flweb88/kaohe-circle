<?php
/* 考核小管家 - HTTPS POST CLI 助手
 * 仅 CLI 调用（PHP-CLI 内置 openssl，可发起 https 请求）；
 * FPM（Web Station PHP 8.2 无 openssl）在 common.php::https_post 检测到无 ssl transport 时经 exec 调用本脚本。
 * 用法: http-post-cli.php <url> <formBody> [timeout]
 * formBody 为已拼好的请求体（如 "payload=<urlencode(json)>"），按 form-urlencoded 提交。
 * 忽略自签证书校验；仅当响应 JSON 含 success:true 时 echo '1' 并退出 0，否则 echo '0' 退出 1。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$url     = isset($argv[1]) ? (string)$argv[1] : '';
$body    = isset($argv[2]) ? (string)$argv[2] : '';
$timeout = isset($argv[3]) ? (int)$argv[3] : 15;
if ($url === '' || $body === '') { echo '0'; exit(1); }

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ],
]);
$resp = @file_get_contents($url, false, $ctx);
if ($resp === false || $resp === '') { echo '0'; exit(1); }
$d = json_decode($resp, true);
$ok = is_array($d) && !empty($d['success']);
echo $ok ? '1' : '0';
exit($ok ? 0 : 1);
