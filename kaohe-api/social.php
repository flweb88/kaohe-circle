<?php
/* 考核小管家 v3 - 考核圈（社交动态）
 * actions: staff_card(无鉴权) / feed / post / del / like / unlike / comment / repost /
 *          bookmark / unbookmark / follow / unfollow / following / followers / posts_of /
 *          notify / notify_read / unread
 * 约定：POST + JSON body，action 走 query string；除 staff_card 外一律 require_login。
 * 数据文件：feed.php / follows.php / notify.php / bookmarks.php（首次写入自动生成）。
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

// ---------- 本模块数据文件 ----------
define('FEED_FILE',     DATA_DIR . 'feed.php');
define('FOLLOWS_FILE',  DATA_DIR . 'follows.php');
define('NOTIFY_FILE',   DATA_DIR . 'notify.php');
define('BOOKMARKS_FILE', DATA_DIR . 'bookmarks.php');

// 读：feed.php {items:[POST]}
function feed_load() {
    $d = db_read_json(FEED_FILE, ['items' => []]);
    if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
    return $d;
}
function feed_save($d) { db_write_json(FEED_FILE, $d); }

// 读：follows.php {<follower>:[<followee>...]}
function follows_load() { return db_read_json(FOLLOWS_FILE, []); }
function follows_save($d) { db_write_json(FOLLOWS_FILE, $d); }

// 读：notify.php {items:[...]}
function notify_load() {
    $d = db_read_json(NOTIFY_FILE, ['items' => []]);
    if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
    return $d;
}
function notify_save($d) { db_write_json(NOTIFY_FILE, $d); }

// 读：bookmarks.php {<email>:[postId...]}
function bookmarks_load() { return db_read_json(BOOKMARKS_FILE, []); }
function bookmarks_save($d) { db_write_json(BOOKMARKS_FILE, $d); }

// 生成 id：16 位 hex
function new_id() { return bin2hex(random_bytes(8)); }

// 追加一条提醒（to 收通知人邮箱；by 触发人邮箱）
function add_notify($to, $type, $by, $postId, $text) {
    $n = notify_load();
    $n['items'][] = [
        'id' => new_id(),
        'to' => $to,
        'type' => $type,
        'by' => $by,
        'postId' => $postId,
        'text' => (string)$text,
        'ts' => now(),
        'read' => false,
    ];
    notify_save($n);
}

// 按邮箱拿公开资料（不存在返回 null）
function pub_by_email($email) {
    $users = load_users();
    if (isset($users['users'][$email]) && is_array($users['users'][$email])) {
        return public_user($users['users'][$email]);
    }
    return null;
}

// 在 feed items 中按 id 找帖子（引用返回，用于就地修改）
function &find_post(&$items, $id) {
    $null = null;
    foreach ($items as $i => $p) {
        if (($p['id'] ?? '') === $id) return $items[$i];
    }
    return $null;
}

// 取作者公开信息兜底
function author_card($authorEmail) {
    $p = pub_by_email($authorEmail);
    if ($p) return $p;
    return ['email' => $authorEmail, 'nickname' => '已注销', 'avatar' => 'zodiac:rat', 'role' => 'user'];
}

// 把单帖附上当前用户交互态与统计计数
function decorate_post($p, $meEmail) {
    $bookmarks = bookmarks_load();
    $liked = in_array($meEmail, $p['likes'] ?? [], true);
    $reposted = in_array($meEmail, $p['reposts'] ?? [], true);
    $bookmarked = in_array($p['id'] ?? '', $bookmarks[$meEmail] ?? [], true);
    $a = author_card($p['author'] ?? '');
    return $p + [
        'authorNick' => $a['nickname'],
        'authorAvatar' => $a['avatar'],
        'liked' => $liked,
        'reposted' => $reposted,
        'bookmarked' => $bookmarked,
        'likeCount' => is_array($p['likes'] ?? null) ? count($p['likes']) : 0,
        'repostCount' => is_array($p['reposts'] ?? null) ? count($p['reposts']) : 0,
        'commentCount' => is_array($p['comments'] ?? null) ? count($p['comments']) : 0,
        'viewCount' => is_array($p['views'] ?? null) ? count($p['views']) : 0,
    ];
}

// ============================================================
// staff_card（无鉴权）：注册页拉管理员卡片
// ============================================================
if ($action === 'staff_card') {
    $users = load_users();
    $cards = [];
    foreach ($users['users'] as $e => $u) {
        if (($u['role'] ?? 'user') === 'admin' && empty($u['banned'])) {
            $cards[] = [
                'nickname' => $u['nickname'] ?? '',
                'avatar'   => $u['avatar'] ?? 'zodiac:rat',
                'role'     => 'admin',
                'email'    => $e,
            ];
        }
    }
    json_out(['ok' => true, 'cards' => $cards]);
}

// 以下全部需要登录
$token = isset($in['token']) ? trim((string)$in['token']) : '';
$me = require_login($token);
$meEmail = strtolower($me['email']);

// ---------- feed ----------
if ($action === 'feed') {
    $tab = isset($in['tab']) ? (string)$in['tab'] : 'recommend';
    $f = feed_load();
    $items = $f['items'];

    // 我关注的人
    $follows = follows_load();
    $myFollows = isset($follows[$meEmail]) && is_array($follows[$meEmail]) ? $follows[$meEmail] : [];

    $dirty = false;
    $today = date('Y-m-d');
    $out = [];
    foreach ($items as $p) {
        $author = $p['author'] ?? '';
        if ($tab === 'follow') {
            if ($author !== $meEmail && !in_array($author, $myFollows, true)) continue;
        }
        // 浏览去重计数：非作者本人 + 当日未看过
        if ($author !== $meEmail) {
            $vkey = $meEmail . '|' . $today;
            if (!isset($p['views'][$vkey])) {
                $p['views'][$vkey] = 1;
                $dirty = true;
            }
        }
        $out[] = $p;
    }
    // 写回浏览计数
    if ($dirty) feed_save($f);

    // 倒序（createdAt 为 Y-m-d H:i:s，字符串可比较）
    usort($out, function($a, $b) { return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''); });

    $posts = [];
    foreach ($out as $p) $posts[] = decorate_post($p, $meEmail);
    json_out(['ok' => true, 'posts' => $posts]);
}

// ---------- post ----------
if ($action === 'post') {
    $type = isset($in['type']) ? (string)$in['type'] : 'text';
    if (!in_array($type, ['text', 'score'], true)) {
        json_out(['ok' => false, 'err' => 'type', 'msg' => '动态类型不合法']);
    }
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    if ($text === '') {
        json_out(['ok' => false, 'err' => 'text', 'msg' => '内容不能为空']);
    }
    if (mb_strlen($text) > 500) {
        json_out(['ok' => false, 'err' => 'toolong', 'msg' => '内容须 ≤500 字']);
    }

    $score = null;
    if ($type === 'score') {
        $s = isset($in['score']) && is_array($in['score']) ? $in['score'] : null;
        if (!$s) {
            json_out(['ok' => false, 'err' => 'score', 'msg' => '成绩数据缺失']);
        }
        // 字段齐全且数值非负
        $need = ['habitName','weekCheckins','weekTotal','totalMinutes','dailyTarget','rewardMinutes','cycleStart','cycleEnd'];
        foreach ($need as $k) {
            if (!array_key_exists($k, $s)) {
                json_out(['ok' => false, 'err' => 'score', 'msg' => "成绩字段缺失：{$k}"]);
            }
        }
        foreach (['weekCheckins','weekTotal','totalMinutes','dailyTarget','rewardMinutes'] as $k) {
            if ((float)$s[$k] < 0) {
                json_out(['ok' => false, 'err' => 'score', 'msg' => '成绩数值不能为负']);
            }
        }
        $score = [
            'habitName'     => (string)$s['habitName'],
            'weekCheckins'  => (int)$s['weekCheckins'],
            'weekTotal'     => (int)$s['weekTotal'],
            'totalMinutes'  => (int)$s['totalMinutes'],
            'dailyTarget'   => (int)$s['dailyTarget'],
            'rewardMinutes' => (int)$s['rewardMinutes'],
            'cycleStart'    => (string)$s['cycleStart'],
            'cycleEnd'      => (string)$s['cycleEnd'],
        ];
    }

    $tags = isset($in['tags']) && is_array($in['tags']) ? array_values(array_unique($in['tags'])) : [];

    // @提及：按昵称精确匹配（排除自己）
    $mentions = [];
    if (preg_match_all('/@([^\s@，。,.!！?？:：;；、]+)/u', $text, $m)) {
        $users = load_users();
        foreach ($m[1] as $nickToken) {
            foreach ($users['users'] as $ee => $uu) {
                if ($ee === $meEmail) continue;
                if (($uu['nickname'] ?? '') === $nickToken) {
                    $mentions[$ee] = true;
                }
            }
        }
    }
    $mentions = array_keys($mentions);

    $post = [
        'id' => new_id(),
        'author' => $meEmail,
        'type' => $type,
        'text' => $text,
        'score' => $score,
        'tags' => $tags,
        'images' => [],
        'mentions' => $mentions,
        'likes' => [],
        'reposts' => [],
        'comments' => [],
        'views' => [],
        'createdAt' => now(),
    ];

    $f = feed_load();
    $f['items'][] = $post;
    feed_save($f);

    // 提及提醒（正文前 60 字）
    $snippet = mb_substr($text, 0, 60);
    foreach ($mentions as $ee) {
        add_notify($ee, 'mention', $meEmail, $post['id'], $snippet);
    }

    json_out(['ok' => true, 'post' => $post]);
}

// ---------- del ----------
if ($action === 'del') {
    $id = trim((string)($in['id'] ?? ''));
    $f = feed_load();
    $found = false;
    foreach ($f['items'] as $i => $p) {
        if (($p['id'] ?? '') === $id) { $found = true; break; }
    }
    if (!$found) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在或已删除']);
    $author = $f['items'][$i]['author'] ?? '';
    if ($author !== $meEmail && ($me['role'] ?? 'user') !== 'admin') {
        json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能删除自己的动态']);
    }
    array_splice($f['items'], $i, 1);
    feed_save($f);
    // 从所有人书签下移除
    $bm = bookmarks_load();
    foreach ($bm as $ee => $list) {
        if (is_array($list) && in_array($id, $list, true)) {
            $bm[$ee] = array_values(array_filter($list, function($x) use ($id) { return $x !== $id; }));
        }
    }
    bookmarks_save($bm);
    json_out(['ok' => true]);
}

// ---------- like / unlike ----------
if ($action === 'like') {
    $id = trim((string)($in['id'] ?? ''));
    $f = feed_load();
    $p =& find_post($f['items'], $id);
    if ($p === null) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在']);
    if (!isset($p['likes']) || !is_array($p['likes'])) $p['likes'] = [];
    if (!in_array($meEmail, $p['likes'], true)) {
        $p['likes'][] = $meEmail;
        if (($p['author'] ?? '') !== $meEmail) {
            add_notify($p['author'], 'like', $meEmail, $id, '赞了你的动态');
        }
    }
    feed_save($f);
    json_out(['ok' => true]);
}
if ($action === 'unlike') {
    $id = trim((string)($in['id'] ?? ''));
    $f = feed_load();
    $p =& find_post($f['items'], $id);
    if ($p === null) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在']);
    if (isset($p['likes']) && is_array($p['likes'])) {
        $p['likes'] = array_values(array_filter($p['likes'], function($x) use ($meEmail) { return $x !== $meEmail; }));
    }
    feed_save($f);
    json_out(['ok' => true]);
}

// ---------- comment ----------
if ($action === 'comment') {
    $id = trim((string)($in['id'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') json_out(['ok' => false, 'err' => 'text', 'msg' => '评论内容不能为空']);
    if (mb_strlen($text) > 200) json_out(['ok' => false, 'err' => 'toolong', 'msg' => '评论须 ≤200 字']);
    $f = feed_load();
    $p =& find_post($f['items'], $id);
    if ($p === null) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在']);
    $cm = ['id' => new_id(), 'author' => $meEmail, 'text' => $text, 'ts' => now()];
    $p['comments'][] = $cm;
    feed_save($f);
    if (($p['author'] ?? '') !== $meEmail) {
        add_notify($p['author'], 'comment', $meEmail, $id, $text);
    }
    json_out(['ok' => true, 'comment' => $cm]);
}

// ---------- repost（切换） ----------
if ($action === 'repost') {
    $id = trim((string)($in['id'] ?? ''));
    $f = feed_load();
    $p =& find_post($f['items'], $id);
    if ($p === null) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在']);
    if (!isset($p['reposts']) || !is_array($p['reposts'])) $p['reposts'] = [];
    $idx = array_search($meEmail, $p['reposts'], true);
    if ($idx === false) {
        $p['reposts'][] = $meEmail;
        if (($p['author'] ?? '') !== $meEmail) {
            add_notify($p['author'], 'repost', $meEmail, $id, '转发了你的动态');
        }
    } else {
        array_splice($p['reposts'], $idx, 1);
    }
    feed_save($f);
    json_out(['ok' => true, 'reposted' => $idx === false]);
}

// ---------- bookmark / unbookmark ----------
if ($action === 'bookmark' || $action === 'unbookmark') {
    $id = trim((string)($in['id'] ?? ''));
    $bm = bookmarks_load();
    if (!isset($bm[$meEmail]) || !is_array($bm[$meEmail])) $bm[$meEmail] = [];
    if ($action === 'bookmark') {
        if (!in_array($id, $bm[$meEmail], true)) $bm[$meEmail][] = $id;
    } else {
        $bm[$meEmail] = array_values(array_filter($bm[$meEmail], function($x) use ($id) { return $x !== $id; }));
    }
    bookmarks_save($bm);
    json_out(['ok' => true]);
}

// ---------- follow / unfollow ----------
if ($action === 'follow' || $action === 'unfollow') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    if ($target === $meEmail) {
        json_out(['ok' => false, 'err' => 'self', 'msg' => '不能关注自己']);
    }
    $p = pub_by_email($target);
    if (!$p) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '该用户不存在']);
    if (!empty($p['banned'])) json_out(['ok' => false, 'err' => 'banned', 'msg' => '该账号已停用']);

    $fl = follows_load();
    if (!isset($fl[$meEmail]) || !is_array($fl[$meEmail])) $fl[$meEmail] = [];
    if ($action === 'follow') {
        if (!in_array($target, $fl[$meEmail], true)) {
            $fl[$meEmail][] = $target;
            add_notify($target, 'follow', $meEmail, null, '关注了你');
        }
    } else {
        $fl[$meEmail] = array_values(array_filter($fl[$meEmail], function($x) use ($target) { return $x !== $target; }));
    }
    follows_save($fl);
    json_out(['ok' => true]);
}

// ---------- following / followers ----------
if ($action === 'following') {
    $fl = follows_load();
    $list = isset($fl[$meEmail]) && is_array($fl[$meEmail]) ? $fl[$meEmail] : [];
    $users = [];
    foreach ($list as $ee) { $c = pub_by_email($ee); if ($c) $users[] = $c; }
    json_out(['ok' => true, 'users' => $users]);
}
if ($action === 'followers') {
    $fl = follows_load();
    $followers = [];
    foreach ($fl as $follower => $list) {
        if (is_array($list) && in_array($meEmail, $list, true)) $followers[] = $follower;
    }
    $users = [];
    foreach ($followers as $ee) { $c = pub_by_email($ee); if ($c) $users[] = $c; }
    json_out(['ok' => true, 'users' => $users]);
}

// ---------- posts_of ----------
if ($action === 'posts_of') {
    $target = isset($in['email']) && trim((string)$in['email']) !== '' ? strtolower(trim((string)$in['email'])) : $meEmail;
    $f = feed_load();
    $out = [];
    foreach ($f['items'] as $p) {
        if (($p['author'] ?? '') === $target) $out[] = $p;
    }
    usort($out, function($a, $b) { return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''); });
    $posts = [];
    foreach ($out as $p) $posts[] = decorate_post($p, $meEmail);
    json_out(['ok' => true, 'posts' => $posts]);
}

// ---------- notify / notify_read ----------
if ($action === 'notify') {
    $n = notify_load();
    $mine = [];
    foreach ($n['items'] as $it) {
        if (($it['to'] ?? '') === $meEmail) $mine[] = $it;
    }
    // 倒序（按 ts 后追加）
    $mine = array_reverse($mine);
    $afterId = isset($in['afterId']) ? (string)$in['afterId'] : '';
    if ($afterId !== '') {
        $pos = null;
        foreach ($mine as $i => $it) { if (($it['id'] ?? '') === $afterId) { $pos = $i + 1; break; } }
        if ($pos !== null) $mine = array_slice($mine, $pos);
    }
    // 附 by 的公开信息
    foreach ($mine as &$it) {
        $c = author_card($it['by'] ?? '');
        $it['byNickname'] = $c['nickname'];
        $it['byAvatar'] = $c['avatar'];
    }
    unset($it);
    json_out(['ok' => true, 'items' => $mine]);
}
if ($action === 'notify_read') {
    $ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [];
    $n = notify_load();
    foreach ($n['items'] as &$it) {
        if (($it['to'] ?? '') !== $meEmail) continue;
        if (empty($ids) || in_array($it['id'] ?? '', $ids, true)) {
            $it['read'] = true;
        }
    }
    unset($it);
    notify_save($n);
    json_out(['ok' => true]);
}

// ---------- unread（badge）：notify 未读数 + dm 未读数 ----------
if ($action === 'unread') {
    $n = notify_load();
    $notifyUnread = 0;
    foreach ($n['items'] as $it) {
        if (($it['to'] ?? '') === $meEmail && empty($it['read'])) $notifyUnread++;
    }
    // dm 未读：复用 dm.php 数据文件（只读，不 include）
    $dm = db_read_json(DATA_DIR . 'dm.php', ['convos' => []]);
    $dmUnread = 0;
    if (isset($dm['convos']) && is_array($dm['convos'])) {
        foreach ($dm['convos'] as $cv) {
            if (!in_array($meEmail, $cv['users'] ?? [], true)) continue;
            $readId = $cv['read'][$meEmail] ?? null;
            $msgs = $cv['msgs'] ?? [];
            // 定位已读到的位置，其后 from!=我 的消息计入
            $startIdx = 0;
            if ($readId !== null) {
                foreach (array_values($msgs) as $i => $m) {
                    if (($m['id'] ?? '') === $readId) { $startIdx = $i + 1; break; }
                }
            }
            foreach (array_values($msgs) as $i => $m) {
                if ($i < $startIdx) continue;
                if (($m['from'] ?? '') !== $meEmail) $dmUnread++;
            }
        }
    }
    json_out(['ok' => true, 'dm' => $dmUnread, 'notify' => $notifyUnread]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
