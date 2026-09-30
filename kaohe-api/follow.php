<?php
/* 考核小管家 v2 - 关注（注册成功后关注管理员的最小闭环）
 * actions: info / follow
 *  - info   无鉴权：返回首个 role=admin 的管理员公开信息
 *  - follow body {token}：当前登录用户把管理员邮箱写入自己数据文件的 data.followed（去重、幂等）
 * 落库形态与 save.php 一致：{ok:true, savedAt, data:{...}}，守卫头由 db_write_json 自动加。
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

// 取首个管理员（按 users 表遍历顺序）
function &first_admin(&$users) {
    $null = null;
    foreach ($users['users'] as &$uu) {
        if (($uu['role'] ?? 'user') === 'admin') return $uu;
    }
    unset($uu);
    return $null;
}

if ($action === 'info') {
    $users = load_users();
    $admin = &first_admin($users);
    json_out(['ok' => true, 'admin' => $admin ? public_user($admin) : null]);
}

if ($action === 'follow') {
    $token = isset($in['token']) ? trim((string)$in['token']) : '';
    $u = require_login($token);   // 未登录 → 401 {ok:false, err:"auth"}

    $users = load_users();
    $admin = &first_admin($users);
    if (!$admin) {
        json_out(['ok' => false, 'err' => 'noadmin', 'msg' => '暂无管理员可关注']);
    }
    $adminEmail = strtolower($admin['email'] ?? '');

    // 读当前用户自己的数据文件；空态按 load.php/save.php 的信封结构初始化再写
    $file = user_data_file($u['email']);
    $d = db_read_json($file, null);
    if (!is_array($d)) $d = ['ok' => true, 'savedAt' => null, 'data' => []];
    if (!isset($d['ok'])) $d['ok'] = true;
    if (!isset($d['data']) || !is_array($d['data'])) $d['data'] = [];

    $followed = isset($d['data']['followed']) && is_array($d['data']['followed']) ? $d['data']['followed'] : [];

    // 幂等：已关注则不重复写盘
    if (!in_array($adminEmail, $followed, true)) {
        $followed[] = $adminEmail;
        $d['data']['followed'] = array_values($followed);
        $d['savedAt'] = now();
        if (!db_write_json($file, $d)) {
            json_out(['ok' => false, 'err' => 'io', 'msg' => '保存失败，请稍后重试']);
        }
    }

    json_out(['ok' => true, 'followed' => array_values($followed), 'admin' => public_user($admin)]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
