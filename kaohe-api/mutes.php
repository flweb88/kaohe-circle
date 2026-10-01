<?php
/* 考核小管家 v3.6.0 - 用户关系网·静音（Mute）
 * actions: mute / unmute
 *  - mute   {token, email}：me 静音 target；不解除互关、不通知对方
 *  - unmute {token, email}：幂等解除
 * 数据形态与 follows.php 一致：{<操作者小写邮箱>:[<目标邮箱>...]}，守卫头由 db_write_json 自动加。
 * 写一律走事务函数（db_transact_multi），禁止「先读改完再单独写」。
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

if ($action === 'mute' || $action === 'unmute') {
    $token = isset($in['token']) ? trim((string)$in['token']) : '';
    $me = require_login($token);   // 未登录 → 401 {ok:false, err:"auth"}
    $meEmail = strtolower(trim($me['email']));
    $target  = strtolower(trim((string)($in['email'] ?? '')));

    if ($target === $meEmail) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能静音自己']);
    }
    // 目标必须存在
    $users = load_users();
    if (!isset($users['users'][$target]) || !is_array($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '用户不存在'], 404);
    }

    if ($action === 'mute') {
        // 幂等追加 mutes[me] = target；不删互关边、不通知对方
        $ok = db_transact_multi([MUTES_FILE => []], function ($dm) use ($meEmail, $target) {
            $m = $dm[MUTES_FILE];
            if (!isset($m[$meEmail]) || !is_array($m[$meEmail])) $m[$meEmail] = [];
            if (!in_array($target, $m[$meEmail], true)) $m[$meEmail][] = $target;
            $dm[MUTES_FILE] = $m;
            return $dm;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        json_out(['ok' => true, 'muted' => true]);
    }

    // unmute：幂等删除
    $ok = db_transact_multi([MUTES_FILE => []], function ($dm) use ($meEmail, $target) {
        $m = $dm[MUTES_FILE];
        if (!isset($m[$meEmail]) || !is_array($m[$meEmail])) return null;
        $before = $m[$meEmail];
        $after  = array_values(array_diff($before, [$target]));
        if ($after === $before) return null;   // 无改动
        $m[$meEmail] = $after;
        $dm[MUTES_FILE] = $m;
        return $dm;
    });
    if (!$ok) {
        json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
    }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
