<?php
/* 考核小管家 v2 - 忘记密码
 * actions: request / reset
 * SMTP 发信由 common.php::smtp_send 承担（FPM 无 openssl 时自动经 CLI 助手发信）
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

if ($action === 'request') {
    $cfg = load_config();
    $smtp = $cfg['smtp'] ?? [];
    $smtpReady = !empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass']);

    // SMTP 未配置：统一报错，不探测邮箱
    if (!$smtpReady) {
        json_out(['ok' => false, 'err' => 'smtp_not_configured', 'msg' => '忘记密码邮件服务尚未配置，请联系管理员']);
    }

    $email = strtolower(trim((string)($in['email'] ?? '')));

    // 限速：同 IP 10 分钟最多 5 次
    $users = load_users();
    if (!isset($users['forgot_log']) || !is_array($users['forgot_log'])) $users['forgot_log'] = [];
    $ip = get_ip();
    $nowTs = time();
    $fl = $users['forgot_log'];
    $blocked = false;
    if (isset($fl[$ip]) && is_array($fl[$ip]) && ($nowTs - ($fl[$ip]['first'] ?? 0) < 600)) {
        if (($fl[$ip]['count'] ?? 0) >= 5) {
            $blocked = true;
        } else {
            $fl[$ip]['count'] = ($fl[$ip]['count'] ?? 0) + 1;
        }
    } else {
        $fl[$ip] = ['count' => 1, 'first' => $nowTs];
    }
    $users['forgot_log'] = $fl;
    save_users($users);

    if ($blocked) {
        json_out(['ok' => false, 'err' => 'rate', 'msg' => '请求过于频繁，请 10 分钟后再试']);
    }

    // 防探测：无论邮箱是否存在、无论 SMTP 是否发送成功，恒返回已发送
    if (email_ok($email) && isset($users['users'][$email]) && empty($users['users'][$email]['banned'])) {
        $code6 = (string)random_int(100000, 999999);
        $users['users'][$email]['reset_code'] = ['code' => $code6, 'expires' => $nowTs + 1800];
        save_users($users);
        $okSend = smtp_send($email, $code6);
        if (!$okSend) {
            // 发送失败：记录日志、清掉已生成的码，但仍对前端统一返回「已发送」（防探测）
            error_log('[kaohe] forgot password smtp send failed for ' . $email);
            $users = load_users();
            $users['users'][$email]['reset_code'] = null;
            save_users($users);
        }
    }

    json_out(['ok' => true, 'msg' => '已发送']);
}

if ($action === 'reset') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $code  = (string)($in['code'] ?? '');
    $new   = (string)($in['new_password'] ?? '');
    if ($new === '' || strlen($new) > 200) {
        json_out(['ok' => false, 'err' => 'code', 'msg' => '验证码无效或已过期']);
    }
    $users = load_users();
    $u = isset($users['users'][$email]) ? $users['users'][$email] : null;
    $rc = $u ? ($u['reset_code'] ?? null) : null;
    $ok = false;
    if (is_array($rc) && !empty($rc['code']) && !empty($rc['expires'])) {
        if (hash_equals((string)$rc['code'], $code) && time() <= (int)$rc['expires']) {
            $ok = true;
        }
    }
    if (!$ok) {
        json_out(['ok' => false, 'err' => 'code', 'msg' => '验证码无效或已过期']);
    }
    $users['users'][$email]['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
    $users['users'][$email]['reset_code'] = null; // 清空，保留 tokens
    save_users($users);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
