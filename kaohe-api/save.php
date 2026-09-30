<?php
/* 考核小管家 v2 - 数据保存（按用户路由）
 * POST {token, data}
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$u = require_login($token);

if (!array_key_exists('data', $in) || !is_array($in['data'])) {
    json_out(['ok' => false, 'err' => 'nodata', 'msg' => '缺少 data']);
}

$payload = ['ok' => true, 'savedAt' => now(), 'data' => $in['data']];
if (!db_write_json(user_data_file($u['email']), $payload)) {
    json_out(['ok' => false, 'err' => 'io', 'msg' => '保存失败，请稍后重试']);
}
json_out($payload);
