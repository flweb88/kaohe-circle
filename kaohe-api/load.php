<?php
/* 考核小管家 v2 - 数据读取（按用户路由）
 * POST {token}
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$u = require_login($token);

$file = user_data_file($u['email']);
if (is_file($file)) {
    $d = db_read_json($file, null);
    if (is_array($d)) {
        json_out($d);
    }
}
json_out(['ok' => true, 'savedAt' => null, 'data' => null]);
