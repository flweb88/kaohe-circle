<?php
/* 考核小管家 v2 - 管理员
 * actions: list / reset_password / ban / invite / config
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$admin = require_admin($token);

if ($action === 'list') {
    $users = load_users();
    $out = [];
    foreach ($users['users'] as $e => $u) {
        $out[] = public_user($u);
    }
    json_out(['ok' => true, 'users' => $out]);
}

if ($action === 'reset_password') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    $new = (string)($in['new_password'] ?? '');
    if ($new === '' || strlen($new) > 200) {
        json_out(['ok' => false, 'err' => 'pwd', 'msg' => '新密码不能为空且长度须 ≤200']);
    }
    $users = load_users();
    if (!isset($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    $users['users'][$target]['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
    $users['users'][$target]['reset_code'] = null;
    save_users($users);
    json_out(['ok' => true]);
}

if ($action === 'ban') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    $banned = !empty($in['banned']);
    if ($target === strtolower($admin['email'])) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能封禁自己']);
    }
    $users = load_users();
    if (!isset($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    $users['users'][$target]['banned'] = $banned;
    save_users($users);
    json_out(['ok' => true]);
}

if ($action === 'invite') {
    $ni = trim((string)($in['new_invite'] ?? ''));
    if ($ni === '' || mb_strlen($ni) > 32) {
        json_out(['ok' => false, 'err' => 'invite', 'msg' => '邀请码不能为空且长度须 ≤32']);
    }
    $users = load_users();
    $users['invite'] = $ni;
    save_users($users);
    json_out(['ok' => true, 'invite' => $ni]);
}

if ($action === 'config') {
    $cfg = load_config();
    if (!is_array($cfg) || !isset($cfg['smtp']) || !is_array($cfg['smtp'])) $cfg['smtp'] = [];

    // 若传入了保存字段则保存；否则视为读取
    $hasSave = isset($in['smtp_host']) || isset($in['smtp_user']) || isset($in['chat_webhook']);
    if ($hasSave) {
        if (isset($in['smtp_host']))    $cfg['smtp']['host'] = trim((string)$in['smtp_host']);
        if (isset($in['smtp_port']))    $cfg['smtp']['port'] = (int)$in['smtp_port'];
        if (isset($in['smtp_ssl']))     $cfg['smtp']['ssl'] = !empty($in['smtp_ssl']);
        if (isset($in['smtp_user']))    $cfg['smtp']['user'] = trim((string)$in['smtp_user']);
        // smtp_pass 留空表示不修改
        if (isset($in['smtp_pass']) && trim((string)$in['smtp_pass']) !== '') {
            $cfg['smtp']['pass'] = (string)$in['smtp_pass'];
        }
        if (isset($in['chat_webhook'])) $cfg['chat_webhook'] = trim((string)$in['chat_webhook']);

        $code = "<?php\n// 由管理员面板生成，请勿提交 git\nreturn " . var_export($cfg, true) . ";\n";
        @file_put_contents(CONFIG_FILE, $code, LOCK_EX);
        @chmod(CONFIG_FILE, 0664);
        clearstatcache(true);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
    }

    $smtp = $cfg['smtp'] ?? [];
    json_out([
        'ok' => true,
        'config' => [
            'smtp' => [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? 465,
                'ssl'  => !empty($smtp['ssl']),
                'user' => $smtp['user'] ?? '',
                'has_pass' => !empty($smtp['pass']),
            ],
            'chat_webhook' => $cfg['chat_webhook'] ?? '',
        ],
    ]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
