<?php
/* 考核小管家 v2 - 管理员
 * actions: list / reset_password / ban / invite / config / role / del_user / transfer_admin / demote_self
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/account_cleanup.php';

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

if ($action === 'role') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    $newRole = trim((string)($in['role'] ?? ''));
    if (!in_array($newRole, ['admin', 'user'], true)) {
        json_out(['ok' => false, 'err' => 'role', 'msg' => '角色只能是 admin 或 user']);
    }
    if ($target === strtolower($admin['email'])) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能修改自己的角色']);
    }
    $users = load_users();
    if (!isset($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    // v3.8.3：取消管理员身份只能由本人在 demote_self 卸任；role action 一律拒绝把 admin 降为 user
    if ($newRole === 'user' && ($users['users'][$target]['role'] ?? 'user') === 'admin') {
        json_out(['ok' => false, 'err' => 'self_only', 'msg' => '管理员身份只能由管理员本人卸任']);
    }
    $users['users'][$target]['role'] = $newRole;
    save_users($users);
    json_out(['ok' => true]);
}

// v3.8.3：管理员删除账号（全量清理 + 备份）
if ($action === 'del_user') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    $adminEmail = strtolower(trim((string)$admin['email']));
    if (!isset($in['confirm']) || (string)$in['confirm'] !== '删除') {
        json_out(['ok' => false, 'err' => 'confirm', 'msg' => '需输入确认文案“删除”']);
    }
    if ($target === '') {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    if ($target === $adminEmail) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能删除管理员自己']);
    }
    $users = load_users();
    if (!isset($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    // 不能删除管理员：管理员须先由本人卸任（非 admin 目标不受影响）
    if (($users['users'][$target]['role'] ?? 'user') === 'admin') {
        json_out(['ok' => false, 'err' => 'admin_target', 'msg' => '不能删除管理员，管理员须先由本人卸任']);
    }
    try {
        $backupName = purge_user($target);
    } catch (\Throwable $e) {
        error_log('[kaohe] purge_user failed: ' . $e->getMessage());
        json_out(['ok' => false, 'err' => 'io', 'msg' => '账号删除失败，请重试'], 500);
    }
    json_out(['ok' => true, 'msg' => '账号已删除（数据已备份）', 'backup' => $backupName]);
}

// v3.8.3：委任管理员（纯提升：只加人不减人，原 admin 不因委任自动降级）
if ($action === 'transfer_admin') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    if ($target === '') {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    $users = load_users();
    if (!isset($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '目标用户不存在']);
    }
    if (($users['users'][$target]['role'] ?? 'user') === 'admin') {
        json_out(['ok' => true, 'noop' => true, 'msg' => '目标已是管理员']);
    }
    // 事务内复查：目标仍存在且仍非 admin → 仅提升目标为 admin（调用者保持 admin）
    $done = false;
    $okTx = db_transact_json(USERS_FILE, ['users' => [], 'invite' => '', 'reg_log' => [], 'migrated' => false], function($u) use ($target, &$done) {
        if (!isset($u['users'][$target]) || ($u['users'][$target]['role'] ?? 'user') === 'admin') return null;
        $u['users'][$target]['role'] = 'admin';
        $done = true;
        return $u;
    });
    if ($okTx === false) {
        json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试'], 500);
    }
    if (!$done) {
        json_out(['ok' => true, 'noop' => true, 'msg' => '目标已是管理员']);
    }
    json_out(['ok' => true, 'msg' => '已将 ' . $target . ' 设为管理员', 'target' => $target]);
}

// v3.8.3：本人卸任管理员（仅本人可卸任自己；最后一位 admin 拒绝）
if ($action === 'demote_self') {
    $adminEmail = strtolower(trim((string)$admin['email']));
    $users = load_users();
    if (!isset($users['users'][$adminEmail])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '账号不存在']);
    }
    // 预检查：最后一位 admin 直接拒绝（事务内再复查，并发安全）
    $adminCount = 0;
    foreach ($users['users'] as $uu) { if (($uu['role'] ?? 'user') === 'admin') $adminCount++; }
    if ($adminCount <= 1) {
        json_out(['ok' => false, 'err' => 'last_admin', 'msg' => '你是系统中最后一位管理员，请先委任他人后再卸任']);
    }
    // 事务内复查：仍是 admin 且系统仍 ≥2 admin → 自己置 user
    $done = false;
    $okTx = db_transact_json(USERS_FILE, ['users' => [], 'invite' => '', 'reg_log' => [], 'migrated' => false], function($u) use ($adminEmail, &$done) {
        if (!isset($u['users'][$adminEmail]) || ($u['users'][$adminEmail]['role'] ?? 'user') !== 'admin') return null;
        $cnt = 0;
        foreach ($u['users'] as $uu) { if (($uu['role'] ?? 'user') === 'admin') $cnt++; }
        if ($cnt <= 1) return null;
        $u['users'][$adminEmail]['role'] = 'user';
        $done = true;
        return $u;
    });
    if ($okTx === false) {
        json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试'], 500);
    }
    if (!$done) {
        $u2 = load_users();
        if (($u2['users'][$adminEmail]['role'] ?? 'user') !== 'admin') {
            json_out(['ok' => true, 'noop' => true, 'msg' => '你已是普通用户']);
        }
        json_out(['ok' => false, 'err' => 'last_admin', 'msg' => '你是系统中最后一位管理员，请先委任他人后再卸任']);
    }
    json_out(['ok' => true, 'msg' => '你已卸任管理员身份']);
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
