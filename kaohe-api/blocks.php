<?php
/* 考核小管家 v3.6.0 - 用户关系网·拉黑（Block）
 * actions: block / unblock
 *  - block   {token, email}：me 拉黑 target；同一原子事务内写 blocks 边 + 删 follows 互关边
 *  - unblock {token, email}：幂等解除；不恢复任何关注边（Unblock ≠ 重新关注）
 * 数据形态与 follows.php 一致：{<操作者小写邮箱>:[<目标邮箱>...]}，守卫头由 db_write_json 自动加。
 * 写一律走 db_transact_multi（多文件原子），禁止「先读改完再单独写」。
 */
require_once __DIR__ . '/common.php';
// FOLLOWS_FILE 现定义在 social.php（关系层共享路径，本端点独立入口，守卫式补齐避免依赖 social.php）
if (!defined('FOLLOWS_FILE')) define('FOLLOWS_FILE', DATA_DIR . 'follows.php');

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

if ($action === 'block' || $action === 'unblock') {
    $token = isset($in['token']) ? trim((string)$in['token']) : '';
    $me = require_login($token);   // 未登录 → 401 {ok:false, err:"auth"}
    $meEmail = strtolower(trim($me['email']));
    $target  = strtolower(trim((string)($in['email'] ?? '')));

    if ($target === $meEmail) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能拉黑自己']);
    }
    // 目标必须存在（banned 用户允许被拉黑——系统已封禁，放行）
    $users = load_users();
    if (!isset($users['users'][$target]) || !is_array($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '用户不存在'], 404);
    }

    if ($action === 'block') {
        // 原子事务：写 blocks 边 + 删 follows 互关边，必须同一临界区
        $ok = db_transact_multi([BLOCKS_FILE => [], FOLLOWS_FILE => []], function ($dm) use ($meEmail, $target) {
            $blocks  = $dm[BLOCKS_FILE];
            $follows = $dm[FOLLOWS_FILE];
            // (a) blocks[me] 幂等追加 target
            if (!isset($blocks[$meEmail]) || !is_array($blocks[$meEmail])) $blocks[$meEmail] = [];
            if (!in_array($target, $blocks[$meEmail], true)) $blocks[$meEmail][] = $target;
            // (b) 删除互关边：follows[me] 移除 target、follows[target] 移除 me（键存在才动）
            if (isset($follows[$meEmail]) && is_array($follows[$meEmail])) {
                $follows[$meEmail] = array_values(array_diff($follows[$meEmail], [$target]));
            }
            if (isset($follows[$target]) && is_array($follows[$target])) {
                $follows[$target] = array_values(array_diff($follows[$target], [$meEmail]));
            }
            $dm[BLOCKS_FILE]  = $blocks;
            $dm[FOLLOWS_FILE] = $follows;
            return $dm;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        json_out(['ok' => true, 'blocked' => true]);
    }

    // unblock：幂等删除 blocks[me] 里的 target（不存在也返回 ok:true）；不恢复关注边
    $ok = db_transact_multi([BLOCKS_FILE => []], function ($dm) use ($meEmail, $target) {
        $blocks = $dm[BLOCKS_FILE];
        if (!isset($blocks[$meEmail]) || !is_array($blocks[$meEmail])) return null;
        $before = $blocks[$meEmail];
        $after  = array_values(array_diff($before, [$target]));
        if ($after === $before) return null;   // 无改动
        $blocks[$meEmail] = $after;
        $dm[BLOCKS_FILE] = $blocks;
        return $dm;
    });
    if (!$ok) {
        json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
    }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
