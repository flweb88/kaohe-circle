<?php
/* 考核小管家 v2 - 意见反馈 → Synology Chat webhook
 * body: {token, content}
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$u = require_login($token);

$content = isset($in['content']) ? trim((string)$in['content']) : '';
if ($content === '') {
    json_out(['ok' => false, 'err' => 'empty', 'msg' => '反馈内容不能为空']);
}
if (mb_strlen($content) > 1000) {
    json_out(['ok' => false, 'err' => 'toolong', 'msg' => '反馈内容须 ≤1000 字']);
}

$cfg = load_config();
$webhook = trim((string)($cfg['chat_webhook'] ?? ''));
if ($webhook === '') {
    json_out(['ok' => false, 'err' => 'webhook_not_configured', 'msg' => '意见反馈通道尚未配置，请联系管理员']);
}

$text = "【考核小管家反馈】\n"
      . "昵称: " . $u['nickname'] . "\n"
      . "邮箱: " . $u['email'] . "\n"
      . "时间: " . now() . "\n"
      . "内容: " . $content;

$json = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
// 本固件 Chat 入站接口要求 form-urlencoded 的 payload=<urlencode(json)>
$body = 'payload=' . urlencode($json);
$ok = https_post($webhook, $body, 15);
if (!$ok) {
    json_out(['ok' => false, 'err' => 'webhook', 'msg' => '反馈发送失败，请稍后重试']);
}
json_out(['ok' => true]);
