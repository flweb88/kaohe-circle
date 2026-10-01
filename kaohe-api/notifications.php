<?php
/* 考核小管家 v3.7.0 - 通知中心
 * actions: list / unread / mark_read
 *  - list      {token, after_id?, limit?, type?}：to=me 的通知按 ts 倒序；type 可选过滤；
 *              after_id=早于该 id 的下一页（游标，加载更早）；byNickname/byAvatar 联查 users；
 *              conversation_id = (type==='dm' ? postId : null)。list 只读，惰性清理不在此触发（D8）。
 *  - unread    {token}：badge 数据源 {notify:非 dm 通知未读数(type!=='dm'), dm:私信未读总数}；跨 dms.php+notify.php 只读。
 *  - mark_read {token, id?}：id 单条（校验 to=me）；缺省/"all" 全部已读；写时顺带惰性清理 90 天前条目（D8）。
 * 读取走 db_read_json；写走 db_transact_json。
 */
require_once __DIR__ . '/common.php';

define('NOTIFY_FILE', DATA_DIR . 'notify.php');
define('DMS_FILE', DATA_DIR . 'dms.php');

const NOTIFY_DEFAULT = ['items' => []];
const DMS_DEFAULT     = ['conversations' => [], 'conversation_members' => [], 'messages' => [], 'message_user_state' => []];

// 私信未读数（与 dms.php §2.1 同款算法；独立入口不可 require dms.php，故内联副本）
function notif_dms_unread_count($dms, $convId, $meEmail) {
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
        if (empty($L) || $lIdx === null) { $count++; continue; }
        if ($i > $lIdx) $count++;
    }
    return $count;
}

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

switch ($action) {

    // ---------- §3.6 通知列表（ts 倒序 + after_id 游标） ----------
    case 'list': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $type = trim((string)($in['type'] ?? ''));
        $afterId = trim((string)($in['after_id'] ?? ''));
        $limit = (int)($in['limit'] ?? 20);
        if ($limit <= 0) $limit = 20;
        if ($limit > 50) $limit = 50;

        $n = db_read_json(NOTIFY_FILE, NOTIFY_DEFAULT);
        $mine = [];
        foreach ($n['items'] ?? [] as $it) {
            if (($it['to'] ?? '') === $meEmail) $mine[] = $it;
        }
        if ($type !== '') {
            $mine = array_values(array_filter($mine, function ($it) use ($type) { return ($it['type'] ?? '') === $type; }));
        }
        usort($mine, function ($a, $b) { return strcmp($b['ts'] ?? '', $a['ts'] ?? ''); });
        if ($afterId !== '') {
            $cursorTs = null;
            foreach ($mine as $it) {
                if (($it['id'] ?? '') === $afterId) { $cursorTs = $it['ts'] ?? ''; break; }
            }
            if ($cursorTs !== null) {
                $mine = array_values(array_filter($mine, function ($it) use ($cursorTs) {
                    return strcmp($it['ts'] ?? '', $cursorTs) < 0;   // 早于游标：加载更早一页
                }));
            }
        }
        $page = array_slice($mine, 0, $limit);
        $users = load_users();
        $out = [];
        foreach ($page as $it) {
            $by = $it['by'] ?? '';
            $u = $users['users'][$by] ?? null;
            $out[] = [
                'id' => $it['id'] ?? '',
                'type' => $it['type'] ?? '',
                'by' => $by,
                'postId' => $it['postId'] ?? null,
                'text' => $it['text'] ?? '',
                'ts' => $it['ts'] ?? '',
                'read' => !empty($it['read']),
                'byNickname' => is_array($u) ? ($u['nickname'] ?? '已注销') : '已注销',
                'byAvatar' => is_array($u) ? ($u['avatar'] ?? 'zodiac:rat') : 'zodiac:rat',
                'conversation_id' => (($it['type'] ?? '') === 'dm' ? ($it['postId'] ?? null) : null),
            ];
        }
        json_out(['ok' => true, 'items' => $out, 'has_more' => (count($mine) > count($page))]);
        break;
    }

    // ---------- §3.6 badge 数据源：{notify, dm} 两值独立 ----------
    case 'unread': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));

        $n = db_read_json(NOTIFY_FILE, NOTIFY_DEFAULT);
        $notifyCnt = 0;
        foreach ($n['items'] ?? [] as $it) {
            if (($it['to'] ?? '') === $meEmail && empty($it['read']) && ($it['type'] ?? '') !== 'dm') $notifyCnt++;   // notify 计数排除 type=dm（dm 未读由 dm 字段按会话算法统计，避免双计数）
        }
        $dms = db_read_json(DMS_FILE, DMS_DEFAULT);
        $dmCnt = 0;
        $myConvIds = [];
        foreach ($dms['conversation_members'] ?? [] as $m) {
            if (($m['user_id'] ?? '') === $meEmail && empty($m['left_at'])) $myConvIds[$m['conversation_id']] = true;
        }
        foreach (array_keys($myConvIds) as $cid) {
            $dmCnt += notif_dms_unread_count($dms, $cid, $meEmail);
        }
        json_out(['ok' => true, 'notify' => $notifyCnt, 'dm' => $dmCnt]);
        break;
    }

    // ---------- §3.6 标记已读（单条/全部）+ 惰性清理 90 天前 ----------
    case 'mark_read': {
        $token = isset($in['token']) ? trim((string)$in['token']) : '';
        $me = require_login($token);
        $meEmail = strtolower(trim($me['email']));
        $id = trim((string)($in['id'] ?? ''));
        $clearAll = ($id === '' || $id === 'all');
        $cutoff = date('Y-m-d H:i:s', time() - 90 * 86400);   // D8：90 天保留期

        $ok = db_transact_json(NOTIFY_FILE, NOTIFY_DEFAULT, function ($n) use ($meEmail, $id, $clearAll, $cutoff) {
            if (!isset($n['items']) || !is_array($n['items'])) $n['items'] = [];
            $kept = [];
            foreach ($n['items'] as $it) {
                $ts = $it['ts'] ?? '';
                if ($ts !== '' && strcmp($ts, $cutoff) < 0) continue;   // 惰性清理：90 天前条目丢弃
                if (($it['to'] ?? '') === $meEmail) {
                    if ($clearAll) {
                        $it['read'] = true;
                    } else if (($it['id'] ?? '') === $id) {
                        $it['read'] = true;   // 单条：仅 to=me 的才会被置已读
                    }
                }
                $kept[] = $it;
            }
            $n['items'] = $kept;
            return $n;
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
