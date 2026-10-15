<?php
/* 考核小管家 v2 - 认证
 * actions: captcha / register / login / logout / check / resign
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/account_cleanup.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

// GET captcha（无 body，无鉴权）
if ($action === 'captcha') {
    $c = captcha_new();
    json_out(['ok' => true, 'q' => $c['q'], 'id' => $c['id']]);
}

$token = isset($in['token']) ? trim((string)$in['token']) : '';

if ($action === 'register') {
    $users = load_users();
    $ip = get_ip();
    $nowTs = time();

    // 1) 字段校验
    $email = isset($in['email']) ? trim((string)$in['email']) : '';
    $email = strtolower($email);
    $pwd   = isset($in['password']) ? (string)$in['password'] : '';
    $nick  = isset($in['nickname']) ? trim((string)$in['nickname']) : '';
    $avatar = isset($in['avatar']) ? trim((string)$in['avatar']) : '';

    if (!email_ok($email)) {
        save_users($users);
        json_out(['ok' => false, 'err' => 'email', 'msg' => '邮箱格式不正确']);
    }
    if ($pwd === '' || strlen($pwd) > 200) {
        save_users($users);
        json_out(['ok' => false, 'err' => 'pwd', 'msg' => '密码不能为空且长度须 ≤200']);
    }
    if ($nick === '') $nick = '小笨猪';
    if (mb_strlen($nick) > 20) {
        save_users($users);
        json_out(['ok' => false, 'err' => 'nick', 'msg' => '昵称长度须 ≤20 字']);
    }
    // 头像：zodiac:xxx 之一或空
    if ($avatar !== '') {
        if (strncmp($avatar, 'zodiac:', 7) !== 0 || !in_array(substr($avatar, 7), ZODIAC, true)) {
            $avatar = '';
        }
    }
    if ($avatar === '') $avatar = 'zodiac:rat';

    // 2) 注册限速：基础字段校验通过后才计数（无效请求不消耗配额）
    //    10 分钟 ≥5 次；计数键 = IP + UA 指纹（家庭多设备共享同一外网 IP 时互不挤占，防脚本按 UA 批量）
    if (!isset($users['reg_log']) || !is_array($users['reg_log'])) $users['reg_log'] = [];
    $rl = $users['reg_log'];
    $rlKey = $ip . '|' . substr(md5((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 8);
    if (isset($rl[$rlKey]) && is_array($rl[$rlKey]) && ($nowTs - ($rl[$rlKey]['first'] ?? 0) < 600)) {
        if (($rl[$rlKey]['count'] ?? 0) >= 5) {
            json_out(['ok' => false, 'err' => 'rate', 'msg' => '注册过于频繁，请 10 分钟后再试']);
        }
        $rl[$rlKey]['count'] = ($rl[$rlKey]['count'] ?? 0) + 1;
    } else {
        $rl[$rlKey] = ['count' => 1, 'first' => $nowTs];
    }
    $users['reg_log'] = $rl;

    // 3) 邀请码（提前判定是否首个用户：首个注册者跳过邀请码校验，保证全新部署可初始化）
    $isFirst = count($users['users']) === 0;
    $inviteIn = isset($in['invite']) ? (string)$in['invite'] : '';
    if (!$isFirst) {
        $sysInvite = (string)($users['invite'] ?? '');
        if ($sysInvite === '') {
            // 系统未设置邀请码（管理员未开放）→ 一律拒绝，绝不允许空码/空==空放行
            save_users($users);
            json_out(['ok' => false, 'err' => 'invite', 'msg' => '注册暂未开放，请联系管理员']);
        }
        if (!hash_equals($sysInvite, $inviteIn)) {
            save_users($users);
            json_out(['ok' => false, 'err' => 'invite', 'msg' => '邀请码错误']);
        }
    }

    // 4) 算术验证码（一次性消费）
    $cid  = isset($in['captcha_id']) ? (string)$in['captcha_id'] : '';
    $cans = isset($in['captcha_answer']) ? $in['captcha_answer'] : '';
    if (!captcha_check($cid, $cans)) {
        save_users($users);
        json_out(['ok' => false, 'err' => 'captcha', 'msg' => '验证码错误']);
    }

    // 5) 已存在
    if (isset($users['users'][$email])) {
        save_users($users);
        json_out(['ok' => false, 'err' => 'exists', 'msg' => '该邮箱已注册，请直接登录']);
    }

    // 6) 创建用户（$isFirst 已在第 3 步邀请码校验前计算，此处复用）
    $newToken = bin2hex(random_bytes(32));
    $users['users'][$email] = [
        'email' => $email,
        'nickname' => $nick,
        'password_hash' => password_hash($pwd, PASSWORD_BCRYPT),
        'avatar' => $avatar,
        'bio' => '',   // v3.6.0：注册默认空简介（public_user 亦兜底，此处仅为数据整洁）
        'role' => $isFirst ? 'admin' : 'user',
        'banned' => false,
        'created_at' => now(),
        'reset_code' => null,
        'tokens' => [$newToken],
    ];

    // 7) 数据迁移（未迁移 + 旧文件存在 + 存在 admin 目标）
    //    目标 admin：当前注册者（若为首用户=admin）否则已有用户中第一个 role=admin
    $adminEmail = null;
    if ($isFirst) {
        $adminEmail = $email;
    } else {
        foreach ($users['users'] as $ee => $uu) {
            if (($uu['role'] ?? 'user') === 'admin') { $adminEmail = $ee; break; }
        }
    }
    $needMigrate = empty($users['migrated']) && is_file(OLD_DATA_FILE) && $adminEmail !== null;
    if ($needMigrate) {
        try {
            $raw = @file_get_contents(OLD_DATA_FILE);
            $old = $raw !== false ? json_decode($raw, true) : null;
            $oldData = (is_array($old) && isset($old['data'])) ? $old['data'] : null;
            $oldSavedAt = (is_array($old) && isset($old['savedAt'])) ? $old['savedAt'] : null;

            if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775, true);
            $ts = date('Ymd-His');
            $okBackup = db_write_json(BACKUP_DIR . "pre-migration-{$ts}.php", is_array($old) ? $old : new stdClass());
            $okWrite  = db_write_json(user_data_file($adminEmail), [
                'ok' => true,
                'savedAt' => $oldSavedAt,
                'data' => $oldData,
                'migratedFrom' => 'kaohe-data.json',
                'migratedAt' => now(),
            ]);

            // 仅当备份与新数据文件都写成功，才重写旧文件并置 migrated=true（失败则下次注册重试）
            if ($okBackup && $okWrite) {
                db_write_json(DATA_DIR . 'kaohe-data.json.migrated.php', is_array($old) ? $old : new stdClass());
                @unlink(OLD_DATA_FILE);
                $users['migrated'] = true;
            } else {
                error_log('[kaohe] migration write failed, will retry next registration');
            }
        } catch (\Throwable $e) {
            error_log('[kaohe] migration failed: ' . $e->getMessage());
            // 不中断注册；migrated 保持 false，下次注册重试
        }
    }

    save_users($users);
    $u = $users['users'][$email];
    json_out([
        'ok' => true,
        'token' => $newToken,
        'user' => public_user($u),
    ]);
}

if ($action === 'login') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pwd   = (string)($in['password'] ?? '');
    $users = load_users();
    $u = isset($users['users'][$email]) ? $users['users'][$email] : null;

    if (!$u) {
        // 平摊时序
        password_verify($pwd, DUMMY_HASH);
        json_out(['ok' => false, 'err' => 'login', 'msg' => '邮箱或密码错误']);
    }
    if (!empty($u['banned'])) {
        json_out(['ok' => false, 'err' => 'banned', 'msg' => '账号已停用，请联系管理员']);
    }
    if (!password_verify($pwd, $u['password_hash'])) {
        json_out(['ok' => false, 'err' => 'login', 'msg' => '邮箱或密码错误']);
    }

    $tokens = isset($u['tokens']) && is_array($u['tokens']) ? $u['tokens'] : [];
    $tokens[] = bin2hex(random_bytes(32));
    $users['users'][$email]['tokens'] = $tokens;
    save_users($users);

    json_out(['ok' => true, 'token' => end($tokens), 'user' => public_user($users['users'][$email])]);
}

if ($action === 'logout') {
    if ($token === '') json_out(['ok' => false, 'err' => 'auth', 'msg' => '登录已失效，请重新登录'], 401);
    $users = load_users();
    foreach ($users['users'] as $email => &$u) {
        if (!empty($u['tokens']) && is_array($u['tokens'])) {
            $u['tokens'] = array_values(array_filter($u['tokens'], function($t) use ($token) {
                return !(is_string($t) && hash_equals($t, $token));
            }));
        }
    }
    unset($u);
    save_users($users);
    json_out(['ok' => true]);
}

if ($action === 'check') {
    $u = require_login($token);
    json_out(['ok' => true, 'user' => public_user($u)]);
}

// v3.8.3：本人注销账号（全量清理 + 备份；admin 须先本人卸任）
if ($action === 'resign') {
    $me = require_login($token);   // 校验失败 401
    $meEmail = strtolower(trim((string)$me['email']));
    if (!isset($in['confirm']) || (string)$in['confirm'] !== '删除') {
        json_out(['ok' => false, 'err' => 'confirm', 'msg' => '需输入确认文案“删除”']);
    }
    if (($me['role'] ?? 'user') === 'admin') {
        json_out(['ok' => false, 'err' => 'admin_first', 'msg' => '管理员请先卸任管理员身份后再注销（若为最后一位管理员请先委任他人）']);
    }
    try {
        purge_user($meEmail);
    } catch (\Throwable $e) {
        error_log('[kaohe] purge_user(resign) failed: ' . $e->getMessage());
        json_out(['ok' => false, 'err' => 'io', 'msg' => '账号注销失败，请重试'], 500);
    }
    json_out(['ok' => true, 'msg' => '账号已注销']);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
