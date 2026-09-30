<?php
/* 考核小管家 v2 - 个人资料
 * actions: update / avatar / password
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$u = require_login($token);
$email = $u['email'];

// 读最新 users 并按 email 定位引用
function users_self() {
    $users = load_users();
    return $users;
}

if ($action === 'update') {
    $nick = isset($in['nickname']) ? trim((string)$in['nickname']) : '';
    if ($nick === '') $nick = '小笨猪';
    if (mb_strlen($nick) > 20) {
        json_out(['ok' => false, 'err' => 'nick', 'msg' => '昵称长度须 ≤20 字']);
    }
    $users = users_self();
    $users['users'][$email]['nickname'] = $nick;
    save_users($users);
    json_out(['ok' => true, 'user' => public_user($users['users'][$email])]);
}

if ($action === 'avatar') {
    $av = isset($in['avatar']) ? trim((string)$in['avatar']) : '';
    $valid = false;
    if (strncmp($av, 'zodiac:', 7) === 0 && in_array(substr($av, 7), ZODIAC, true)) {
        $valid = true;
    } elseif (preg_match('/^custom:([0-9a-f]{16})\.(jpg|jpeg|png|webp)$/', $av, $m)) {
        $fname = $m[1] . '.' . $m[2];
        if (is_file(AVATAR_DIR . $fname)) $valid = true;
    }
    if (!$valid) {
        json_out(['ok' => false, 'err' => 'avatar', 'msg' => '头像不合法']);
    }
    $users = users_self();
    $users['users'][$email]['avatar'] = $av;
    save_users($users);
    json_out(['ok' => true, 'user' => public_user($users['users'][$email])]);
}

if ($action === 'password') {
    $old = (string)($in['old_password'] ?? '');
    $new = (string)($in['new_password'] ?? '');
    if ($new === '' || strlen($new) > 200) {
        json_out(['ok' => false, 'err' => 'pwd', 'msg' => '新密码不能为空且长度须 ≤200']);
    }
    $users = users_self();
    if (!password_verify($old, $users['users'][$email]['password_hash'])) {
        json_out(['ok' => false, 'err' => 'oldpwd', 'msg' => '原密码不正确']);
    }
    $users['users'][$email]['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
    // tokens 保留（多设备不掉线）
    save_users($users);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
