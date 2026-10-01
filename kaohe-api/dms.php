<?php
/* 考核小管家 v3.7.0 - 私信（DM）
 * actions: conversations / open_or_create / send / messages / unread / mark_read / hide_message
 * 数据文件 kaohe-data/dms.php {conversations, conversation_members, messages, message_user_state}（守卫头+JSON）。
 * dm 通知：send 在 db_transact_multi([DMS_FILE, NOTIFY_FILE]) 内联写 notify.php（type=dm）——
 *   按 (to=peer, type=dm, by=me, postId=convId) 未读去重更新 text(前20字)+ts；is_muted(peer,me) → 不写通知（消息仍送达）。
 * 未读数（契约 §2.1）：按消息在 messages 数组中的下标比较时序，禁止 id 字典序；排除本地删除消息。
 * 写一律走 db_transact_json / db_transact_multi，禁止裸读裸写。
 */
require_once __DIR__ . '/common.php';

define('DMS_FILE', DATA_DIR . 'dms.php');
if (!defined('NOTIFY_FILE')) define('NOTIFY_FILE', DATA_DIR . 'notify.php');

const DMS_DEFAULT    = ['conversations' => [], 'conversation_members' => [], 'messages' => [], 'message_user_state' => []];
const NOTIFY_DEFAULT = ['items' => []];

function dms_new_id() { return bin2hex(random_bytes(8)); }

// 对方公开卡片（仅 email/nickname/avatar，与契约响应对齐）；用户缺失兜底「已注销」
function dms_peer_card($email) {
    $users = load_users();
    if (isset($users['users'][$email]) && is_array($users['users'][$email])) {
        $u = $users['users'][$email];
        return ['email' => $email, 'nickname' => $u['nickname'] ?? '', 'avatar' => $u['avatar'] ?? 'zodiac:rat'];
    }
    return ['email' => $email, 'nickname' => '已注销', 'avatar' => 'zodiac:rat'];
}

// 找 a、b 双方都是成员（left_at=null）的 1:1 会话 id，幂等复用；找不到返回 null
function dms_find_pair_conv($dms, $a, $b) {
    $myConvIds = [];
    foreach ($dms['conversation_members'] ?? [] as $m) {
        if (($m['user_id'] ?? '') === $a && empty($m['left_at'])) $myConvIds[$m['conversation_id']] = true;
    }
    foreach (array_keys($myConvIds) as $cid) {
        $hasA = false; $hasB = false;
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['conversation_id'] ?? '') !== $cid) continue;
            if (($m['user_id'] ?? '') === $a && empty($m['left_at'])) $hasA = true;
            if (($m['user_id'] ?? '') === $b && empty($m['left_at'])) $hasB = true;
        }
        if ($hasA && $hasB) return $cid;
    }
    return null;
}

// 我在某会话的未读数（契约 §2.1：数组下标比较时序；sender≠me；排除 deleted_at / 本地删除）
function dms_unread_count($dms, $convId, $meEmail) {
    $L = null;
    foreach ($dms['conversation_members'] ?? [] as $m) {
        if (($m['conversation_id'] ?? '') === $convId && ($m['user_id'] ?? '') === $meEmail && empty($m['left_at'])) {
            $L = $m['last_read_message_id'] ?? null;
        }
    }
    $hidden = [];
    foreach ($dms['message_user_state'] ?? [] as $s) {
        if (($s['user_id'] ?? '') === $meEmail && empty($s['visible'])) $hidden[$s['message_id']] = true;
    }
    $lIdx = null;
    if (!empty($L)) {
        foreach ($dms['messages'] ?? [] as $i => $m) {
            if (($m['id'] ?? '') === $L) { $lIdx = $i; break; }
        }
    }
    $count = 0;
    foreach ($dms['messages'] ?? [] as $i => $m) {
        if (($m['conversation_id'] ?? '') !== $convId) continue;
        if (($m['sender_id'] ?? '') === $meEmail) continue;
        if (!empty($m['deleted_at'])) continue;
        if (isset($hidden[$m['id']])) continue;
        if (empty($L) || $lIdx === null) { $count++; continue; }   // 从未读 / L 指向缺失：保守计未读
        if ($i > $lIdx) $count++;
    }
    return $count;
}

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

switch ($action) {

    // ---------- §3.1 会话列表：peer 资料 / last_message / unread_count / updated_at 倒序 / can_send ----------
    case 'conversations': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);

        $myConvIds = [];
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['user_id'] ?? '') === $meEmail && empty($m['left_at'])) $myConvIds[$m['conversation_id']] = true;
        }
        $items = [];
        foreach ($dms['conversations'] ?? [] as $c) {
            $cid = $c['id'] ?? '';
            if (!isset($myConvIds[$cid])) continue;
            $peer = '';
            foreach ($dms['conversation_members'] ?? [] as $m) {
                if (($m['conversation_id'] ?? '') === $cid && ($m['user_id'] ?? '') !== $meEmail && empty($m['left_at'])) { $peer = $m['user_id']; break; }
            }
            $last = null;
            foreach ($dms['messages'] ?? [] as $m) {
                if (($m['conversation_id'] ?? '') === $cid && empty($m['deleted_at'])) $last = $m;
            }
            $items[] = [
                'conversation_id' => $cid,
                'peer' => dms_peer_card($peer),
                'last_message' => $last ? [
                    'id' => $last['id'], 'body' => $last['body'],
                    'ts' => $last['created_at'], 'sender_id' => $last['sender_id'],
                ] : null,
                'unread_count' => dms_unread_count($dms, $cid, $meEmail),
                'updated_at' => $c['updated_at'] ?? '',
                'can_send' => can_interact($meEmail, $peer),
            ];
        }
        usort($items, function ($a, $b) { return strcmp($b['updated_at'], $a['updated_at']); });
        json_out(['ok' => true, 'items' => $items]);
        break;
    }

    // ---------- §3.2 发起/获取会话（幂等） ----------
    case 'open_or_create': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $peer = strtolower(trim((string)($in['email'] ?? '')));

        if (!email_ok($peer)) {
            json_out(['ok' => false, 'err' => 'notfound', 'msg' => '用户不存在'], 404);
        }
        $users = load_users();
        if (!isset($users['users'][$peer]) || !is_array($users['users'][$peer])) {
            json_out(['ok' => false, 'err' => 'notfound', 'msg' => '用户不存在'], 404);
        }
        if ($peer === $meEmail) {
            json_out(['ok' => false, 'err' => 'self', 'msg' => '不能给自己发起私信']);
        }
        if (!can_interact($meEmail, $peer)) {
            json_out(['ok' => false, 'err' => 'blocked', 'msg' => '对方已限制互动'], 403);
        }

        // 快速路径：已有会话直接复用
        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);
        $existing = dms_find_pair_conv($dms, $meEmail, $peer);
        if ($existing) {
            json_out(['ok' => true, 'conversation_id' => $existing, 'created' => false, 'peer' => dms_peer_card($peer)]);
        }

        // 单文件事务新建；事务内复查幂等（防并发重复建会话）
        $foundCid = null; $createdCid = null;
        $ok = db_transact_json(DMS_FILE, DMS_DEFAULT, function ($d) use ($meEmail, $peer, &$foundCid, &$createdCid) {
            $cid = dms_find_pair_conv($d, $meEmail, $peer);
            if ($cid) { $foundCid = $cid; return null; }
            $now = now();
            $newId = dms_new_id();
            $d['conversations'][] = ['id' => $newId, 'type' => 'dm', 'created_by' => $meEmail, 'created_at' => $now, 'updated_at' => $now];
            $d['conversation_members'][] = ['conversation_id' => $newId, 'user_id' => $meEmail, 'joined_at' => $now, 'left_at' => null, 'last_read_message_id' => null];
            $d['conversation_members'][] = ['conversation_id' => $newId, 'user_id' => $peer, 'joined_at' => $now, 'left_at' => null, 'last_read_message_id' => null];
            $createdCid = $newId;
            return $d;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        $cid = $foundCid ?: $createdCid;
        json_out(['ok' => true, 'conversation_id' => $cid, 'created' => (bool)$createdCid, 'peer' => dms_peer_card($peer)]);
        break;
    }

    // ---------- §3.3 发消息（跨 dms.php + notify.php 事务） ----------
    case 'send': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $convId = trim((string)($in['conversation_id'] ?? ''));
        $body = trim((string)($in['body'] ?? ''));

        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);
        $members = [];
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['conversation_id'] ?? '') === $convId && empty($m['left_at'])) $members[] = $m['user_id'];
        }
        if (!in_array($meEmail, $members, true)) {
            json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '无权访问该会话'], 403);
        }
        $peer = '';
        foreach ($members as $x) { if ($x !== $meEmail) { $peer = $x; break; } }
        if ($peer === '') {
            json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '无权访问该会话'], 403);
        }
        if (!can_interact($meEmail, $peer)) {
            json_out(['ok' => false, 'err' => 'blocked', 'msg' => '对方已限制互动'], 403);
        }
        if ($body === '') {
            json_out(['ok' => false, 'err' => 'empty', 'msg' => '消息不能为空'], 400);
        }
        if (mb_strlen($body) > 2000) {
            json_out(['ok' => false, 'err' => 'too_long', 'msg' => '消息过长'], 400);
        }

        $msgId = dms_new_id();
        $now = now();
        $notifyText = mb_substr($body, 0, 20);
        $peerMutedMe = is_muted($peer, $meEmail);   // 接收方静音了我 → 消息仍送达，但不写 dm 通知
        $ok = db_transact_multi([DMS_FILE => DMS_DEFAULT, NOTIFY_FILE => NOTIFY_DEFAULT], function ($dm) use ($convId, $meEmail, $peer, $body, $msgId, $now, $notifyText, $peerMutedMe) {
            $d = $dm[DMS_FILE];
            $n = $dm[NOTIFY_FILE];
            // ① append message（attachments 恒 []、reply_to/edited_at/deleted_at 恒 null）
            $d['messages'][] = [
                'id' => $msgId, 'conversation_id' => $convId, 'sender_id' => $meEmail, 'body' => $body,
                'attachments' => [], 'reply_to' => null, 'created_at' => $now, 'edited_at' => null, 'deleted_at' => null,
            ];
            // ② 刷新 conversation.updated_at
            foreach ($d['conversations'] as &$c) {
                if (($c['id'] ?? '') === $convId) $c['updated_at'] = $now;
            }
            unset($c);
            // ③ dm 通知：未读同键去重更新；否则 append
            if (!$peerMutedMe) {
                if (!isset($n['items']) || !is_array($n['items'])) $n['items'] = [];
                $hit = false;
                foreach ($n['items'] as &$it) {
                    if (($it['to'] ?? '') === $peer && ($it['type'] ?? '') === 'dm' && ($it['by'] ?? '') === $meEmail
                        && ($it['postId'] ?? null) === $convId && empty($it['read'])) {
                        $it['text'] = $notifyText;
                        $it['ts'] = $now;
                        $hit = true;
                        break;
                    }
                }
                unset($it);
                if (!$hit) {
                    $n['items'][] = [
                        'id' => dms_new_id(), 'to' => $peer, 'type' => 'dm', 'by' => $meEmail,
                        'postId' => $convId, 'text' => $notifyText, 'ts' => $now, 'read' => false,
                    ];
                }
            }
            $dm[DMS_FILE] = $d;
            $dm[NOTIFY_FILE] = $n;
            return $dm;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        json_out(['ok' => true, 'message' => [
            'id' => $msgId, 'conversation_id' => $convId, 'sender_id' => $meEmail, 'body' => $body, 'created_at' => $now,
        ]]);
        break;
    }

    // ---------- §3.4 拉取消息（before_id 游标，created_at 倒序） ----------
    case 'messages': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $convId = trim((string)($in['conversation_id'] ?? ''));
        $beforeId = trim((string)($in['before_id'] ?? ''));
        $limit = (int)($in['limit'] ?? 20);
        if ($limit <= 0) $limit = 20;
        if ($limit > 50) $limit = 50;

        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);
        $convExists = false;
        foreach ($dms['conversations'] ?? [] as $c) {
            if (($c['id'] ?? '') === $convId) { $convExists = true; break; }
        }
        if (!$convExists) {
            json_out(['ok' => false, 'err' => 'notfound', 'msg' => '会话不存在'], 404);
        }
        $members = [];
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['conversation_id'] ?? '') === $convId && empty($m['left_at'])) $members[] = $m['user_id'];
        }
        if (!in_array($meEmail, $members, true)) {
            json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '无权访问该会话'], 403);
        }

        $hidden = [];
        foreach ($dms['message_user_state'] ?? [] as $s) {
            if (($s['user_id'] ?? '') === $meEmail && empty($s['visible'])) $hidden[$s['message_id']] = true;
        }
        $list = [];
        foreach ($dms['messages'] ?? [] as $m) {
            if (($m['conversation_id'] ?? '') !== $convId) continue;
            if (!empty($m['deleted_at'])) continue;
            if (isset($hidden[$m['id']])) continue;   // 本地删除的消息对我不可见
            $list[] = $m;
        }
        if ($beforeId !== '') {
            $idx = null;
            foreach ($list as $i => $m) {
                if (($m['id'] ?? '') === $beforeId) { $idx = $i; break; }
            }
            $list = ($idx === null) ? [] : array_slice($list, 0, $idx);
        }
        $total = count($list);
        $page = array_slice($list, -$limit);
        $out = [];
        foreach (array_reverse($page) as $m) {   // 最新在前
            $out[] = [
                'id' => $m['id'], 'sender_id' => $m['sender_id'], 'body' => $m['body'],
                'created_at' => $m['created_at'], 'mine' => ($m['sender_id'] === $meEmail),
            ];
        }
        json_out(['ok' => true, 'items' => $out, 'has_more' => ($total > count($page))]);
        break;
    }

    // ---------- §3.5 未读总数 ----------
    case 'unread': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);
        $total = 0;
        $myConvIds = [];
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['user_id'] ?? '') === $meEmail && empty($m['left_at'])) $myConvIds[$m['conversation_id']] = true;
        }
        foreach (array_keys($myConvIds) as $cid) {
            $total += dms_unread_count($dms, $cid, $meEmail);
        }
        json_out(['ok' => true, 'dm' => $total]);
        break;
    }

    // ---------- §3.5 标记已读（跨 dms.php + notify.php 事务） ----------
    case 'mark_read': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $convId = trim((string)($in['conversation_id'] ?? ''));
        $msgId = trim((string)($in['message_id'] ?? ''));

        $ok = db_transact_multi([DMS_FILE => DMS_DEFAULT, NOTIFY_FILE => NOTIFY_DEFAULT], function ($dm) use ($convId, $meEmail, $msgId) {
            $d = $dm[DMS_FILE];
            $n = $dm[NOTIFY_FILE];
            // 缺省 = 该会话最新一条消息 id
            $target = $msgId;
            if ($target === '') {
                foreach ($d['messages'] ?? [] as $m) {
                    if (($m['conversation_id'] ?? '') === $convId) $target = $m['id'];
                }
            }
            foreach ($d['conversation_members'] as &$m) {
                if (($m['conversation_id'] ?? '') === $convId && ($m['user_id'] ?? '') === $meEmail
                    && empty($m['left_at']) && $target !== '') {
                    $m['last_read_message_id'] = $target;
                }
            }
            unset($m);
            // 同事务把我该会话未读的 dm 通知全部置 read=true
            if (!isset($n['items']) || !is_array($n['items'])) $n['items'] = [];
            foreach ($n['items'] as &$it) {
                if (($it['to'] ?? '') === $meEmail && ($it['type'] ?? '') === 'dm'
                    && ($it['postId'] ?? null) === $convId && empty($it['read'])) {
                    $it['read'] = true;
                }
            }
            unset($it);
            $dm[DMS_FILE] = $d;
            $dm[NOTIFY_FILE] = $n;
            return $dm;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        json_out(['ok' => true]);
        break;
    }

    // ---------- §3.5 本地删除（不校验 sender，双方都能删自己视图） ----------
    case 'hide_message': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $msgId = trim((string)($in['message_id'] ?? ''));

        $ok = db_transact_json(DMS_FILE, DMS_DEFAULT, function ($d) use ($msgId, $meEmail) {
            if (!isset($d['message_user_state']) || !is_array($d['message_user_state'])) $d['message_user_state'] = [];
            $now = now();
            $found = false;
            foreach ($d['message_user_state'] as &$s) {
                if (($s['message_id'] ?? '') === $msgId && ($s['user_id'] ?? '') === $meEmail) {
                    $s['visible'] = false;
                    $s['hidden_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($s);
            if (!$found) {
                $d['message_user_state'][] = ['message_id' => $msgId, 'user_id' => $meEmail, 'visible' => false, 'hidden_at' => $now, 'read_at' => null];
            }
            return $d;
        });
        if (!$ok) {
            json_out(['ok' => false, 'err' => 'busy', 'msg' => '系统繁忙，请稍后重试']);
        }
        json_out(['ok' => true]);
        break;
    }

    default:
        json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
}
