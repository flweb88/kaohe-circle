<?php
/* 考核小管家 v3.6.0 - 考核圈（社交动态）· X 帖文结构对齐 + 用户关系网叠加
 * actions: staff_card(无鉴权) / feed / post / del / like / unlike / comment / repost / unrepost /
 *          bookmark / unbookmark / follow / unfollow / following / followers / posts_of /
 *          search_users / score_cards / notify / notify_read / unread /
 *          reply_settings / hide_reply / unhide_reply /
 *          comment_like / comment_unlike / comment_bookmark / comment_unbookmark / comment_repost /
 *          user_profile / remove_follower
 * 约定：POST + JSON body，action 走 query string；除 staff_card 外一律 require_login。
 * 数据文件（kaohe-data/，守卫头+JSON，社交数据不进每用户文件）：
 *   posts.php {items:[Post], reqs:{client_req_id:postId}}   // v3.5.5：Post 统一原子（普通/回复/引用）
 *   social.php {likes:[],comments:[],reposts:[],bookmarks:[],comment_likes:[],comment_bookmarks:[]}
 *   follows.php {<follower>:[<followee>...]}
 *   blocks.php {<拉黑者>:[<被拉黑者>...]}   // v3.6.0：拉黑边（map，与 follows 同形态；写入在 blocks.php）
 *   mutes.php  {<静音者>:[<被静音者>...]}   // v3.6.0：静音边（map，与 follows 同形态；写入在 mutes.php）
 *   notify.php {items:[Notify]}
 *   uploads/manifest.php {items:{<id>:{...}}}
 * 所有写动作一律走 db_transact_json（持锁读改写）。
 * v3.6.0 关系层叠加（只读过滤 + 新互动 403，不改 posts/social schema）：
 *   - feed(recommend+follow) / posts_of / user_profile 叠加 Block/Mute 时间线与主页可见性过滤；
 *   - add_notify 写入侧过滤（互拉黑/被静音 → 不落库）；
 *   - like/comment/reply/repost/quote 等新互动写入前 can_interact() 403 err:blocked（取消类不阻断）；
 *   - follow 同 can_interact 阻断；following/followers 分页(cursor/limit)+relation 子对象；
 *   - user_profile 聚合主页（资料+counts+relation+帖）；remove_follower 单向移除粉丝（≠Block）。
 * v3.5.5 语义：
 *   - reply_to_post_id=直接父帖；conversation_id=根帖 id（普通/引用帖=自身 id，回复帖=根帖 id）；
 *     quoted_post_id=引用对象；纯转发=reposts[] 关系（不建帖）。
 *   - comments[] 迁移后清空为壳，新回复一律进 posts.php（schema_v:2）；comment_likes/comment_bookmarks 兼容旧 id。
 */
require_once __DIR__ . '/common.php';

$in = req_json();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($in['action']) ? $in['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

// ---------- 本模块数据文件 ----------
define('POSTS_FILE',     DATA_DIR . 'posts.php');
define('SOCIAL_FILE',    DATA_DIR . 'social.php');
define('FOLLOWS_FILE',   DATA_DIR . 'follows.php');
define('NOTIFY_FILE',    DATA_DIR . 'notify.php');
define('MANIFEST_FILE',  UPLOAD_DIR . 'manifest.php');

const POSTS_DEFAULT   = ['items' => [], 'reqs' => []];
const SOCIAL_DEFAULT  = ['likes' => [], 'comments' => [], 'reposts' => [], 'bookmarks' => [],
                         'comment_likes' => [], 'comment_bookmarks' => []];   // comment_likes/bookmarks 旧评论 id 兼容读取
const NOTIFY_DEFAULT  = ['items' => []];
const MANIFEST_DEFAULT= ['items' => []];

function new_id() { return bin2hex(random_bytes(8)); }

// 取作者公开资料兜底
function author_card($authorEmail) {
    $users = load_users();
    if (isset($users['users'][$authorEmail]) && is_array($users['users'][$authorEmail])) {
        return public_user($users['users'][$authorEmail]);
    }
    return ['email' => $authorEmail, 'nickname' => '已注销', 'avatar' => 'zodiac:rat', 'role' => 'user', 'banned' => false];
}

// 按 id 找帖子（只读返回；写操作一律走 db_transact_json）
function find_post_by_id($items, $id) {
    foreach (($items ?? []) as $p) {
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

// v3.5.5：旧帖 schema_v:1 缺省 conversation_id = 自身 id
function conv_id_of($p) {
    return !empty($p['conversation_id']) ? $p['conversation_id'] : ($p['id'] ?? '');
}

// v3.5.5：reply_policy 校验——作者本人豁免；following=作者关注的人可回；mentioned=被 @ 的人可回
function reply_policy_allows($targetPost, $meEmail) {
    $au = $targetPost['author'] ?? '';
    if ($meEmail === $au) return true;
    $policy = $targetPost['reply_policy'] ?? 'all';
    if ($policy === 'all') return true;
    if ($policy === 'following') {
        $follows = db_read_json(FOLLOWS_FILE, []);
        return in_array($meEmail, $follows[$au] ?? [], true);
    }
    if ($policy === 'mentioned') {
        return in_array($meEmail, $targetPost['mentions'] ?? [], true);
    }
    return true;
}

// 追加一条提醒（to/by 邮箱）；已存在同 (to,type,by,postId) 则不重复落库
function add_notify($to, $type, $by, $postId, $text) {
    if ($to === '' || $to === $by) return;
    // v3.6.0：通知写入侧过滤——互拉黑 / 收件人静音发件人 → 不再产生通知（只读判断，不落库；不动 notify.php 读取逻辑）
    if (has_blocked($to, $by) || blocked_by($to, $by) || is_muted($to, $by)) return;
    db_transact_json(NOTIFY_FILE, NOTIFY_DEFAULT, function($n) use ($to, $type, $by, $postId, $text) {
        if (!isset($n['items']) || !is_array($n['items'])) $n['items'] = [];
        foreach ($n['items'] as $it) {
            if (($it['to'] ?? '') === $to && ($it['type'] ?? '') === $type &&
                ($it['by'] ?? '') === $by && ($it['postId'] ?? null) === $postId && empty($it['read'])) {
                return null; // 已存在未读同类提醒，去重
            }
        }
        $n['items'][] = [
            'id' => new_id(), 'to' => $to, 'type' => $type, 'by' => $by,
            'postId' => $postId, 'text' => (string)$text, 'ts' => now(), 'read' => false,
        ];
        return $n;
    });
}

// 媒体 id → manifest 记录（只读）
function manifest_get() {
    $m = db_read_json(MANIFEST_FILE, MANIFEST_DEFAULT);
    return (isset($m['items']) && is_array($m['items'])) ? $m['items'] : [];
}

// 把单帖 media(id 列表) 解析为带 status/url 的结构
function resolve_media($mediaIds, $manifest) {
    $out = [];
    foreach (($mediaIds ?? []) as $mid) {
        if (!is_string($mid) || $mid === '') continue;
        $rec = $manifest[$mid] ?? null;
        if (!$rec) {
            $out[] = ['id' => $mid, 'kind' => 'image', 'mime' => '', 'size' => 0, 'status' => 'failed',
                      'url' => ['orig' => '']];
            continue;
        }
        $files = $rec['files'] ?? [];
        $urls = [];
        foreach (['orig','sm','md','video720','poster'] as $v) {
            if (!empty($files[$v])) $urls[$v] = 'media.php?action=raw&f=' . rawurlencode($mid) . '&v=' . $v;
        }
        $out[] = [
            'id' => $mid, 'kind' => $rec['kind'] ?? 'image', 'mime' => $rec['mime'] ?? '',
            'size' => (int)($rec['size'] ?? 0), 'status' => $rec['status'] ?? 'pending',
            'url' => $urls, 'files' => $files,   // 文件名映射（下载按钮 fn 参数）
        ];
    }
    return $out;
}

// v3.5.5：回复帖(Post) → v3.5.4 前端评论视图（comments_of / comment 返回共用）
// 互动计数 = likes[]/bookmarks[]（统一 Post 互动）+ comment_likes/comment_bookmarks（旧数据兼容合并）
function build_reply_view($rp, $rootId, $itemsById, $social, $users, $manifest, $meEmail) {
    $rid = $rp['id'] ?? '';
    $likeCount = $bookmarkCount = 0; $liked = $bookmarked = false;
    foreach (($social['likes'] ?? []) as $r)         { if (($r['post_id'] ?? '') === $rid) { $likeCount++;    if (($r['user'] ?? '') === $meEmail) $liked = true; } }
    foreach (($social['comment_likes'] ?? []) as $r) { if (($r['comment_id'] ?? '') === $rid) { $likeCount++;    if (($r['user'] ?? '') === $meEmail) $liked = true; } }
    foreach (($social['bookmarks'] ?? []) as $r)      { if (($r['post_id'] ?? '') === $rid) { $bookmarkCount++; if (($r['user'] ?? '') === $meEmail) $bookmarked = true; } }
    foreach (($social['comment_bookmarks'] ?? []) as $r) { if (($r['comment_id'] ?? '') === $rid) { $bookmarkCount++; if (($r['user'] ?? '') === $meEmail) $bookmarked = true; } }
    $authorEmail = $rp['author'] ?? '';
    $au = $users['users'][$authorEmail] ?? null;
    $parentId = $rp['reply_to_post_id'] ?? null;
    $replyUser = null; $replyNick = null;
    if ($parentId !== null && $parentId !== $rootId && isset($itemsById[$parentId])) {
        $replyUser = $itemsById[$parentId]['author'] ?? null;
        if ($replyUser !== null) {
            $ru = $users['users'][$replyUser] ?? null;
            $replyNick = $ru['nickname'] ?? '已注销';
        }
    }
    return [
        'id' => $rid, 'post_id' => $rootId, 'user' => $authorEmail, 'text' => $rp['text'] ?? '',
        'mentions' => $rp['mentions'] ?? [], 'reply_to' => $parentId,
        'reply_user' => $replyUser, 'replyNick' => $replyNick,
        'media' => resolve_media($rp['media_ids'] ?? [], $manifest),
        'at' => $rp['createdAt'] ?? '', 'status' => 'normal',
        'authorNick' => $au['nickname'] ?? '已注销',
        'authorAvatar' => $au['avatar'] ?? 'zodiac:rat',
        'authorCard' => author_card($authorEmail),
        'likeCount' => $likeCount, 'liked' => $liked,
        'bookmarkCount' => $bookmarkCount, 'bookmarked' => $bookmarked,
        'mine' => ($authorEmail === $meEmail),
        'hidden' => !empty($rp['hidden']),
    ];
}

// 批量 decorate：一次性读 social/manifest，给整批帖子附计数与交互态
// v3.5.5：quote=quoted_post_id ?? repost_of ?? quote_comment；replyCount/quoteCount/repostWrapper；
//         commentCount 口径=直接回复数（同 replyCount）
function decorate_posts($posts, $meEmail, $postsDoc = null) {
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $manifest = manifest_get();
    $users = load_users();
    $po = ($postsDoc !== null && isset($postsDoc['items']) && is_array($postsDoc['items'])) ? $postsDoc : db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $allItems = $po['items'] ?? [];
    foreach ($posts as &$p) {
        $au = $p['author'] ?? '';
        $a = author_card($au);
        $pid = $p['id'];
        $likeCount = $repostCount = 0;
        $liked = $reposted = $bookmarked = false;
        $latestRepost = null;
        foreach (($social['likes'] ?? []) as $r)    { if (($r['post_id'] ?? '') === $pid) { $likeCount++; if (($r['user'] ?? '') === $meEmail) $liked = true; } }
        foreach (($social['reposts'] ?? []) as $r)  {
            if (($r['post_id'] ?? '') === $pid) {
                $repostCount++;
                if (($r['user'] ?? '') === $meEmail) $reposted = true;
                if ($latestRepost === null || strcmp($r['at'] ?? '', $latestRepost['at'] ?? '') > 0) $latestRepost = $r;
            }
        }
        foreach (($social['bookmarks'] ?? []) as $r){ if (($r['post_id'] ?? '') === $pid && ($r['user'] ?? '') === $meEmail) $bookmarked = true; }
        $p['media'] = resolve_media($p['media_ids'] ?? [], $manifest);

        // v3.5.5：引用块 quoted_post_id ?? repost_of ?? quote_comment（引用式取当前状态）
        $p['quote'] = null;
        $ref = $p['quoted_post_id'] ?? $p['repost_of'] ?? $p['quote_comment'] ?? null;
        if ($ref !== null) {
            $isLegacyComment = !empty($p['quote_comment']) && empty($p['quoted_post_id']) && empty($p['repost_of']);
            $refKind = ($isLegacyComment || strncmp($ref, 'c_', 2) === 0) ? 'comment' : 'post';
            $q = find_post_by_id($allItems, $ref);
            if (!$q && $refKind === 'comment') {
                // 迁移前兼容兜底：旧 comments[] 快照
                foreach (($social['comments'] ?? []) as $cc) {
                    if (($cc['id'] ?? '') === $ref) { $q = ['author' => ($cc['user'] ?? ''), 'text' => $cc['text'] ?? '', 'media_ids' => $cc['media_ids'] ?? [], 'status' => $cc['status'] ?? 'normal']; break; }
                }
            }
            if ($q && ($q['status'] ?? 'normal') === 'normal') {
                $qa = author_card($q['author'] ?? ($q['user'] ?? ''));
                $p['quote'] = [
                    'kind' => $refKind, 'deleted' => false,
                    'text' => $q['text'] ?? '',
                    'media' => resolve_media($q['media_ids'] ?? [], $manifest),
                    'authorNick' => $qa['nickname'], 'authorAvatar' => $qa['avatar'],
                ];
            } else {
                $p['quote'] = ['kind' => $refKind, 'deleted' => true];
            }
        }

        // v3.5.5：replyCount=直接回复数（含 hidden）；quoteCount=被引用数
        $replyCount = $quoteCount = 0;
        foreach ($allItems as $it) {
            if (($it['status'] ?? 'normal') !== 'normal') continue;
            if (($it['reply_to_post_id'] ?? null) === $pid) $replyCount++;
            if (($it['quoted_post_id'] ?? null) === $pid)  $quoteCount++;
        }
        // v3.5.5：repostWrapper = 该帖最近一条纯转发 {byNickname, at}
        $p['repostWrapper'] = $latestRepost
            ? ['byNickname' => author_card($latestRepost['user'] ?? '')['nickname'], 'at' => $latestRepost['at'] ?? '']
            : null;
        $p['authorNick'] = $a['nickname'];
        $p['authorAvatar'] = $a['avatar'];
        $p['liked'] = $liked; $p['reposted'] = $reposted; $p['bookmarked'] = $bookmarked;
        $p['likeCount'] = $likeCount;
        $p['replyCount'] = $replyCount;
        $p['quoteCount'] = $quoteCount;
        $p['commentCount'] = $replyCount;   // v3.5.5：口径=直接回复数（同 replyCount）
        $p['repostCount'] = $repostCount;
        // 作者是否封禁/注销 → 前端降级
        $p['authorBanned'] = !empty($a['banned']) || !isset($users['users'][$au]);
    }
    unset($p);
    return $posts;
}

// 有效帖过滤：normal 且作者存在且未封禁
function filter_eligible($items) {
    $users = load_users();
    $out = [];
    foreach ($items as $p) {
        if (($p['status'] ?? 'normal') !== 'normal') continue;
        $au = $p['author'] ?? '';
        if (!isset($users['users'][$au]) || !empty($users['users'][$au]['banned'])) continue;
        $out[] = $p;
    }
    return $out;
}

// v3.6.0：互不可见 + 时间线过滤——排除「我拉黑的 / 拉黑我的 / 我静音的」作者的帖（只读，不写）。
// 自己的帖不滤；blocked_by 通过反查 blocks 边（谁的 target 列表里含 me）得出，避免逐帖重复读文件。
function filter_relations($items, $meEmail) {
    if ($meEmail === '' || empty($items)) return $items;
    $blocksAll = db_read_json(BLOCKS_FILE, []);
    $mutesAll  = db_read_json(MUTES_FILE, []);
    $myBlocks  = array_values(array_unique($blocksAll[$meEmail] ?? []));
    $myMutes   = array_values(array_unique($mutesAll[$meEmail] ?? []));
    $blockedBy = [];   // 拉黑了 me 的人
    foreach (($blocksAll ?? []) as $actor => $targets) {
        if (is_array($targets) && in_array($meEmail, $targets, true)) $blockedBy[$actor] = true;
    }
    return array_values(array_filter($items, function($p) use ($meEmail, $myBlocks, $myMutes, $blockedBy) {
        $au = strtolower((string)($p['author'] ?? ''));
        if ($au === '' || $au === $meEmail) return true;
        if (in_array($au, $myBlocks, true)) return false;   // 我拉黑的人
        if (isset($blockedBy[$au]))          return false;   // 拉黑我的人
        if (in_array($au, $myMutes, true))  return false;   // 我静音的人
        return true;
    }));
}

// v3.6.0：新互动准入——被拉黑/互拉黑/目标不存在或封禁时，403 err:blocked（取消类动作不调用本函数）。
function deny_if_blocked($actor, $targetEmail, $action) {
    if (!can_interact($actor, $targetEmail, $action)) {
        json_out(['ok' => false, 'err' => 'blocked', 'msg' => '对方已限制互动'], 403);
    }
}

// ============================================================
// staff_card（无鉴权）：注册页拉管理员卡片（不吐 email）
// ============================================================
if ($action === 'staff_card') {
    $users = load_users();
    $cards = [];
    foreach ($users['users'] as $e => $u) {
        if (($u['role'] ?? 'user') === 'admin' && empty($u['banned'])) {
            $cards[] = ['nickname' => $u['nickname'] ?? '', 'avatar' => $u['avatar'] ?? 'zodiac:rat', 'role' => 'admin'];
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
    $tab = isset($_GET['tab']) ? (string)$_GET['tab'] : (isset($in['tab']) ? (string)$in['tab'] : 'recommend');
    $limit = isset($in['limit']) ? max(1, min(50, (int)$in['limit'])) : 20;
    $cursor = isset($in['cursor']) ? (int)$in['cursor'] : 0;

    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $items = filter_eligible($postsDoc['items'] ?? []);
    $items = filter_relations($items, $meEmail);   // v3.6.0：Block/Mute 时间线过滤（recommend+follow 两 tab 共用，只读）
    // v3.5.5：回复帖（reply_to_post_id≠null）不进 feed 主列表
    $items = array_values(array_filter($items, fn($p) => empty($p['reply_to_post_id'])));

    if ($tab === 'follow') {
        $follows = db_read_json(FOLLOWS_FILE, []);
        $myFollows = isset($follows[$meEmail]) && is_array($follows[$meEmail]) ? $follows[$meEmail] : [];
        $out = [];
        foreach ($items as $p) {
            $author = $p['author'] ?? '';
            if ($author === $meEmail || in_array($author, $myFollows, true)) $out[] = $p;
        }
        usort($out, function($a,$b){ return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''); });
        $empty = empty($myFollows) ? 'no_follow' : null;
        $page = array_slice($out, $cursor, $limit);
        $next = ($cursor + $limit < count($out)) ? ($cursor + $limit) : null;
        $posts = decorate_posts($page, $meEmail, $postsDoc);
        json_out(['ok' => true, 'posts' => array_values($posts), 'nextCursor' => $next,
                  'empty' => $empty && empty($page) ? $empty : null]);
    }

    // recommend：全站有效帖伪随机 shuffle；每次请求重抽一批，前端 feedSeen 去重
    shuffle($items);
    $page = array_slice($items, 0, $limit);
    $posts = decorate_posts(array_values($page), $meEmail, $postsDoc);
    json_out(['ok' => true, 'posts' => array_values($posts), 'nextCursor' => null]);
}

// ---------- post（v3.5.5：统一建帖——普通/回复 reply_to_post_id/引用 quoted_post_id） ----------
if ($action === 'post') {
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    $mediaIds = isset($in['media']) && is_array($in['media']) ? array_values($in['media']) : [];
    $mediaIds = array_slice($mediaIds, 0, 4);
    $replyPolicy = isset($in['reply_policy']) ? (string)$in['reply_policy'] : 'all';
    if (!in_array($replyPolicy, ['all','following','mentioned'], true)) $replyPolicy = 'all';
    $reqId = isset($in['client_req_id']) ? substr(trim((string)$in['client_req_id']), 0, 64) : '';
    $replyToPostId = isset($in['reply_to_post_id']) ? trim((string)$in['reply_to_post_id']) : '';
    $quotedPostId = isset($in['quoted_post_id']) ? trim((string)$in['quoted_post_id']) : '';
    // 互斥校验：reply_to_post_id 与 quoted_post_id 不能同时非空（引用优先报错）
    if ($replyToPostId !== '' && $quotedPostId !== '') {
        json_out(['ok' => false, 'err' => 'mutex', 'msg' => '回复与引用不能同时使用']);
    }

    if ($text === '' && empty($mediaIds) && !isset($in['score'])) {
        json_out(['ok' => false, 'err' => 'empty', 'msg' => '内容不能为空']);
    }
    if ($text !== '' && mb_strlen($text) > 500) {
        json_out(['ok' => false, 'err' => 'toolong', 'msg' => '内容须 ≤500 字']);
    }

    // 目标帖解析：回复/引用校验目标存在且 normal；reply 校验 reply_policy
    $postsDoc0 = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $parentPost = null;   // 回复的直接父帖
    $quotedPost = null;   // 引用的目标帖
    $convId = '';
    if ($replyToPostId !== '') {
        $parentPost = find_post_by_id($postsDoc0['items'] ?? [], $replyToPostId);
        if (!$parentPost || ($parentPost['status'] ?? 'normal') !== 'normal') {
            json_out(['ok' => false, 'err' => 'notfound', 'msg' => '要回复的动态不存在或已删除'], 404);
        }
        deny_if_blocked($meEmail, $parentPost['author'] ?? '', 'reply');   // v3.6.0：回复前互动准入
        if (!reply_policy_allows($parentPost, $meEmail)) {
            json_out(['ok' => false, 'err' => 'forbidden_reply', 'msg' => '作者不允许你回复'], 403);
        }
        $convId = conv_id_of($parentPost);   // conversation_id 继承目标帖根帖
    }
    if ($quotedPostId !== '') {
        $quotedPost = find_post_by_id($postsDoc0['items'] ?? [], $quotedPostId);
        if (!$quotedPost || ($quotedPost['status'] ?? 'normal') !== 'normal') {
            json_out(['ok' => false, 'err' => 'notfound', 'msg' => '引用的动态不存在或已删除'], 404);
        }
        deny_if_blocked($meEmail, $quotedPost['author'] ?? '', 'quote');   // v3.6.0：引用前互动准入
        // 引用帖 conversation_id=自身 id（落库时填）
    }

    // 媒体校验：存在于 manifest 且为本人上传
    $manifest = manifest_get();
    foreach ($mediaIds as $mid) {
        $rec = $manifest[$mid] ?? null;
        if (!$rec || ($rec['uploader'] ?? '') !== $meEmail) {
            json_out(['ok' => false, 'err' => 'media', 'msg' => '媒体不存在或无权使用']);
        }
    }

    // 成绩卡
    $score = null;
    if (isset($in['score']) && is_array($in['score'])) {
        $s = $in['score'];
        $need = ['habitName','cycleStart','cycleEnd','weekCheckins','weekTotal','rewardMinutes'];
        foreach ($need as $k) { if (!array_key_exists($k, $s)) json_out(['ok'=>false,'err'=>'score','msg'=>"成绩字段缺失：{$k}"]); }
        $score = [
            'habitName'     => (string)$s['habitName'],
            'cycleStart'    => (string)$s['cycleStart'],
            'cycleEnd'      => (string)$s['cycleEnd'],
            'weekCheckins'  => (int)$s['weekCheckins'],
            'weekTotal'     => (int)$s['weekTotal'],
            'rewardMinutes' => (int)$s['rewardMinutes'],
        ];
    }

    // @提及：解析正文 @token，按昵称/邮箱匹配真实用户（排除自己），不存在则跳过
    $mentions = [];
    if ($text !== '' && preg_match_all('/@([^\s@，。,.!！?？:：;；、]+)/u', $text, $m)) {
        $users = load_users();
        foreach (array_unique($m[1]) as $token) {
            foreach ($users['users'] as $ee => $uu) {
                if ($ee === $meEmail) continue;
                if (!empty($uu['banned'])) continue;
                if (($uu['nickname'] ?? '') === $token || $ee === $token) $mentions[$ee] = true;
            }
        }
    }
    $mentions = array_keys($mentions);

    // 类型推导
    if ($score) $type = 'score';
    elseif (!empty($mediaIds)) {
        $k = $manifest[$mediaIds[0]]['kind'] ?? 'image';
        $type = in_array($k, ['image','video','audio'], true) ? $k : 'image';
    } else $type = 'text';

    $post = [
        'id' => new_id(), 'author' => $meEmail, 'type' => $type,
        'text' => $text, 'media_ids' => $mediaIds, 'share_card' => $score,
        'mentions' => $mentions, 'reply_policy' => $replyPolicy,
        'reply_to_post_id' => ($replyToPostId !== '' ? $replyToPostId : null),
        'conversation_id' => '',
        'quoted_post_id' => ($quotedPostId !== '' ? $quotedPostId : null),
        'hidden' => false,
        'repost_of' => null,
        'createdAt' => now(), 'status' => 'normal', 'schema_v' => 2,
    ];
    // 普通/引用帖 conversation_id=自身 id；回复帖=根帖 id
    $post['conversation_id'] = ($replyToPostId !== '') ? $convId : $post['id'];

    $dup = false;
    $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($post, $reqId, &$dup) {
        if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
        if (!isset($d['reqs']) || !is_array($d['reqs'])) $d['reqs'] = [];
        if ($reqId !== '' && isset($d['reqs'][$reqId])) { $dup = true; return null; }
        $d['items'][] = $post;
        if ($reqId !== '') $d['reqs'][$reqId] = $post['id'];
        return $d;
    });
    if ($dup) json_out(['ok' => false, 'err' => 'dup', 'msg' => '请勿重复提交']);
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '保存失败，请稍后重试'], 500);

    // 通知：reply→父帖作者；quote→原帖作者；mention→被 @ 者
    $snippet = mb_substr($text, 0, 60);
    if ($parentPost !== null) add_notify($parentPost['author'] ?? '', 'reply', $meEmail, $post['id'], $snippet);
    if ($quotedPost !== null) add_notify($quotedPost['author'] ?? '', 'quote', $meEmail, $post['id'], $snippet);
    foreach ($mentions as $ee) add_notify($ee, 'mention', $meEmail, $post['id'], $snippet);

    $freshDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $fresh = find_post_by_id($freshDoc['items'] ?? [], $post['id']);
    json_out(['ok' => true, 'post' => $fresh ? array_values(decorate_posts([$fresh], $meEmail, $freshDoc))[0] : $post]);
}

// ---------- del（软删帖/评论 + 清理媒体） ----------
if ($action === 'del') {
    $id = trim((string)($in['id'] ?? ''));
    $kind = isset($in['kind']) ? (string)$in['kind'] : 'post';

    if ($kind === 'comment') {
        // v3.5.5 兼容：查 posts.php c_ 前缀回复帖软删（旧前端调用；comments[] 已空）
        $state = 'notfound';
        $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($id, $meEmail, $me, &$state) {
            $items = $d['items'] ?? [];
            foreach ($items as $i => $p) {
                if (($p['id'] ?? '') === $id) {
                    if (($p['author'] ?? '') !== $meEmail && ($me['role'] ?? 'user') !== 'admin') { $state = 'forbidden'; return null; }
                    $items[$i]['status'] = 'deleted';
                    $d['items'] = $items;
                    $state = 'done'; return $d;
                }
            }
            return null;
        });
        if ($state === 'notfound') {
            // 迁移前兜底：旧 social.comments[]
            $ok = db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail, $me, &$state) {
                foreach ($s['comments'] ?? [] as $i => &$c) {
                    if (($c['id'] ?? '') === $id) {
                        if (($c['user'] ?? '') !== $meEmail && ($me['role'] ?? 'user') !== 'admin') { $state = 'forbidden'; return null; }
                        $c['status'] = 'deleted'; $state = 'done'; return $s;
                    }
                }
                return null;
            });
        }
        if ($state === 'notfound') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '评论不存在'], 404);
        if ($state === 'forbidden') json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能删除自己的评论'], 403);
        if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '删除失败'], 500);
        json_out(['ok' => true]);
    }

    // kind=post（v3.5.5：天然支持 c_ 前缀回复帖，删除规则不变=本人/admin）
    $state = 'notfound';
    $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($id, $meEmail, $me, &$state) {
        $items = $d['items'] ?? [];
        foreach ($items as $i => &$p) {
            if (($p['id'] ?? '') === $id) {
                if (($p['author'] ?? '') !== $meEmail && ($me['role'] ?? 'user') !== 'admin') { $state = 'forbidden'; return null; }
                $p['status'] = 'deleted'; $state = 'done'; $d['items'] = $items; return $d;
            }
        }
        return null;
    });
    if ($state === 'notfound') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在或已删除'], 404);
    if ($state === 'forbidden') json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能删除自己的动态'], 403);
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '删除失败'], 500);

    // 物理清理关联媒体文件 + manifest 记录
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if ($p) {
        foreach (($p['media_ids'] ?? []) as $mid) {
            foreach (glob(UPLOAD_PENDING_DIR . $mid . '.*') as $f) @unlink($f);
            foreach (glob(UPLOAD_DIR . $mid . '.*') as $f) @unlink($f);
            foreach (glob(UPLOAD_DONE_DIR . $mid . '.*') as $f) @unlink($f);
        }
        db_transact_json(MANIFEST_FILE, MANIFEST_DEFAULT, function($m) use ($p) {
            foreach (($p['media_ids'] ?? []) as $mid) unset($m['items'][$mid]);
            return $m;
        });
    }
    json_out(['ok' => true]);
}

// ---------- like / unlike ----------
if ($action === 'like' || $action === 'unlike') {
    $id = trim((string)($in['id'] ?? ''));
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$p || ($p['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);

    if ($action === 'like') deny_if_blocked($meEmail, $p['author'] ?? '', 'like');   // v3.6.0：新点赞前互动准入（unlike 不阻断）

    $action === 'like' ?
        db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
            foreach (($s['likes'] ?? []) as $r) if (($r['post_id']??'')===$id && ($r['user']??'')===$meEmail) return null;
            $s['likes'][] = ['post_id'=>$id,'user'=>$meEmail,'at'=>now()]; return $s;
        }) :
        db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
            $s['likes'] = array_values(array_filter($s['likes'] ?? [], fn($r) => !(($r['post_id']??'')===$id && ($r['user']??'')===$meEmail)));
            return $s;
        });

    if ($action === 'like') add_notify($p['author'], 'like', $meEmail, $id, '赞了你的动态');
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $likeCount = 0; foreach (($social['likes']??[]) as $r) if (($r['post_id']??'')===$id) $likeCount++;
    json_out(['ok' => true, 'likeCount' => $likeCount]);
}

// ---------- comment（v3.5.5 兼容重定向：按 reply 语义创建回复帖进 posts.php） ----------
if ($action === 'comment') {
    $id = trim((string)($in['id'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    $mediaIds = isset($in['media']) && is_array($in['media']) ? array_values($in['media']) : [];
    $mediaIds = array_slice($mediaIds, 0, 4);
    $replyTo = isset($in['reply_to']) ? trim((string)$in['reply_to']) : '';
    if ($text === '' && empty($mediaIds)) json_out(['ok' => false, 'err' => 'text', 'msg' => '评论内容不能为空']);
    if ($text !== '' && mb_strlen($text) > 200) json_out(['ok' => false, 'err' => 'toolong', 'msg' => '评论须 ≤200 字']);

    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$p || ($p['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);

    // reply_policy 服务端校验（绕过前端直调 API 同样拦截）
    if (!reply_policy_allows($p, $meEmail)) json_out(['ok' => false, 'err' => 'forbidden_reply', 'msg' => '作者不允许你回复'], 403);

    // 评论媒体校验：存在于 manifest 且为本人上传
    $manifest = manifest_get();
    foreach ($mediaIds as $mid) {
        $mrec = $manifest[$mid] ?? null;
        if (!$mrec || ($mrec['uploader'] ?? '') !== $meEmail) {
            json_out(['ok' => false, 'err' => 'media', 'msg' => '媒体不存在或无权使用']);
        }
    }

    // 父帖解析：reply_to 传评论 id(c_xxx 或旧 id) → 指向该回复帖；否则挂根帖
    // （回复的回复归并：前端约束最多 2 层；此处校验必须属于同一 conversation）
    $parentId = $id;
    if ($replyTo !== '') {
        $cand = find_post_by_id($postsDoc['items'] ?? [], $replyTo);
        if (!$cand && strncmp($replyTo, 'c_', 2) !== 0) $cand = find_post_by_id($postsDoc['items'] ?? [], 'c_' . $replyTo);
        if ($cand && ($cand['status'] ?? 'normal') === 'normal' &&
            conv_id_of($cand) === conv_id_of($p) && !empty($cand['reply_to_post_id'])) {
            $parentId = $cand['id'];
        }
    }
    // v3.6.0：回复前互动准入——目标作者=直接父帖作者（回复的回复取被回复者，而非根帖作者）
    $directParent = ($parentId === $id) ? $p : find_post_by_id($postsDoc['items'] ?? [], $parentId);
    deny_if_blocked($meEmail, $directParent['author'] ?? '', 'reply');

    // 评论内 @提及
    $mentions = [];
    if (preg_match_all('/@([^\s@，。,.!！?？:：;；、]+)/u', $text, $m)) {
        $users = load_users();
        foreach (array_unique($m[1]) as $token) {
            foreach ($users['users'] as $ee => $uu) {
                if ($ee !== $meEmail && !empty($uu) && (($uu['nickname']??'')===$token || $ee===$token)) $mentions[$ee]=true;
            }
        }
    }
    $mentions = array_keys($mentions);

    $ctype = 'text';
    if (!empty($mediaIds)) {
        $k = $manifest[$mediaIds[0]]['kind'] ?? 'image';
        $ctype = in_array($k, ['image','video','audio'], true) ? $k : 'image';
    }
    $rp = [
        'id' => new_id(), 'author' => $meEmail, 'type' => $ctype,
        'text' => $text, 'media_ids' => $mediaIds, 'share_card' => null,
        'mentions' => $mentions, 'reply_policy' => 'all',
        'reply_to_post_id' => $parentId,
        'conversation_id' => conv_id_of($p),
        'quoted_post_id' => null, 'hidden' => false, 'repost_of' => null,
        'createdAt' => now(), 'status' => 'normal', 'schema_v' => 2,
    ];
    $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($rp) {
        if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
        $d['items'][] = $rp; return $d;
    });
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '评论失败'], 500);

    // 通知：type=reply 给直接父帖作者（根评论=根帖作者；嵌套=被回复者）
    $parentPost = ($parentId === $id) ? $p : find_post_by_id($postsDoc['items'] ?? [], $parentId);
    add_notify($parentPost['author'] ?? '', 'reply', $meEmail, $rp['id'], mb_substr($text, 0, 60));
    foreach ($mentions as $ee) add_notify($ee, 'mention', $meEmail, $id, mb_substr($text, 0, 60));

    // 返回 v3.5.4 兼容评论视图 + 直接回复数
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $users = load_users();
    $freshDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $itemsById = []; foreach (($freshDoc['items'] ?? []) as $it) $itemsById[$it['id']] = $it;
    $comment = build_reply_view($rp, $id, $itemsById, $social, $users, $manifest, $meEmail);
    $cc = 0;
    foreach (($freshDoc['items'] ?? []) as $it) {
        if (($it['status'] ?? 'normal') === 'normal' && ($it['reply_to_post_id'] ?? null) === $id) $cc++;
    }
    json_out(['ok' => true, 'comment' => $comment, 'commentCount' => $cc]);
}

// ---------- 评论点赞/收藏（v3.5.5 兼容：互动统一落 likes[]/bookmarks[]，计数合并旧 comment_likes/bookmarks） ----------
if ($action === 'comment_like' || $action === 'comment_unlike' || $action === 'comment_bookmark' || $action === 'comment_unbookmark') {
    $cid = trim((string)($in['id'] ?? ''));
    $isLike = ($action === 'comment_like' || $action === 'comment_unlike');
    $on = ($action === 'comment_like' || $action === 'comment_bookmark');

    // 目标：优先 posts.php c_ 回复帖；迁移前兜底旧 comments[]
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $target = find_post_by_id($postsDoc['items'] ?? [], $cid);
    $author = $target['author'] ?? null;
    if (!$target) {
        $soc0 = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
        foreach (($soc0['comments'] ?? []) as $c0) {
            if (($c0['id'] ?? '') === $cid && ($c0['status'] ?? 'normal') === 'normal') { $author = $c0['user'] ?? null; $target = $c0; break; }
        }
    }
    if (!$target) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '评论不存在'], 404);

    // v3.6.0：新互动阻断——给评论点赞前检查评论作者与我互动准入（取消点赞/收藏类动作不阻断）
    if ($on && $isLike && $author !== null) deny_if_blocked($meEmail, $author, 'like');

    $arrKey = $isLike ? 'likes' : 'bookmarks';
    db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($arrKey, $cid, $meEmail, $on) {
        if ($on) {
            foreach (($s[$arrKey] ?? []) as $r) if (($r['post_id']??'')===$cid && ($r['user']??'')===$meEmail) return null;
            $s[$arrKey][] = ['post_id'=>$cid,'user'=>$meEmail,'at'=>now()]; return $s;
        }
        $s[$arrKey] = array_values(array_filter($s[$arrKey] ?? [], fn($r) => !(($r['post_id']??'')===$cid && ($r['user']??'')===$meEmail)));
        return $s;
    });
    if ($on && $isLike && $author !== null) add_notify($author, 'like', $meEmail, $cid, '赞了你的回复');

    // 计数 = 统一 likes[]/bookmarks[] + 旧 comment_likes/comment_bookmarks 合并
    $soc = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $cnt = 0;
    foreach (($soc['likes'] ?? []) as $r)        if (($r['post_id'] ?? '') === $cid) $cnt++;
    if ($isLike) foreach (($soc['comment_likes'] ?? []) as $r) if (($r['comment_id'] ?? '') === $cid) $cnt++;
    else         foreach (($soc['comment_bookmarks'] ?? []) as $r) if (($r['comment_id'] ?? '') === $cid) $cnt++;
    if ($isLike) json_out(['ok' => true, 'likeCount' => $cnt]);
    json_out(['ok' => true, 'bookmarkCount' => $cnt]);
}

// ---------- 评论转发（v3.5.5 兼容：查 posts.php c_ 回复帖 → 生成引用帖 quoted_post_id=c_xxx） ----------
if ($action === 'comment_repost') {
    $cid = trim((string)($in['id'] ?? ''));
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $qc = find_post_by_id($postsDoc['items'] ?? [], $cid);
    if (!$qc || ($qc['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '评论不存在或已删除'], 404);
    deny_if_blocked($meEmail, $qc['author'] ?? '', 'quote');   // v3.6.0：引用评论前互动准入

    $mentions = [];
    if ($text !== '' && preg_match_all('/@([^\s@，。,.!！?？:：;；、]+)/u', $text, $m)) {
        $users = load_users();
        foreach (array_unique($m[1]) as $token) {
            foreach ($users['users'] as $ee => $uu) {
                if ($ee === $meEmail || !empty($uu['banned'])) continue;
                if (($uu['nickname'] ?? '') === $token || $ee === $token) $mentions[$ee] = true;
            }
        }
    }
    $newPost = [
        'id' => new_id(), 'author' => $meEmail, 'type' => 'text', 'text' => $text, 'media_ids' => [],
        'share_card' => null, 'mentions' => array_keys($mentions), 'reply_policy' => 'all',
        'reply_to_post_id' => null, 'conversation_id' => '',
        'quoted_post_id' => $cid, 'hidden' => false, 'repost_of' => null,
        'createdAt' => now(), 'status' => 'normal', 'schema_v' => 2,
    ];
    $newPost['conversation_id'] = $newPost['id'];   // 引用帖独立 conversation
    $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($newPost) {
        if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
        $d['items'][] = $newPost; return $d;
    });
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '转发失败'], 500);

    add_notify($qc['author'] ?? '', 'quote', $meEmail, $newPost['id'], mb_substr($text, 0, 60));
    $freshDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $fresh = find_post_by_id($freshDoc['items'] ?? [], $newPost['id']);
    json_out(['ok' => true, 'post' => $fresh ? array_values(decorate_posts([$fresh], $meEmail, $freshDoc))[0] : $newPost]);
}

// ---------- repost（v3.5.5：纯转发，只记 reposts[] 关系，不建帖；UNIQUE 幂等） ----------
if ($action === 'repost') {
    $id = trim((string)($in['id'] ?? ''));
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $orig = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$orig || ($orig['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '原动态不存在或已删除'], 404);
    deny_if_blocked($meEmail, $orig['author'] ?? '', 'repost');   // v3.6.0：纯转发前互动准入（unrepost 不阻断）

    db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
        foreach (($s['reposts'] ?? []) as $r) if (($r['post_id']??'')===$id && ($r['user']??'')===$meEmail) return null;  // 幂等
        $s['reposts'][] = ['post_id'=>$id,'user'=>$meEmail,'at'=>now()]; return $s;
    });

    add_notify($orig['author'] ?? '', 'repost', $meEmail, $id, '转发了你的动态');

    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $rc = 0; foreach (($social['reposts']??[]) as $r) if (($r['post_id']??'')===$id) $rc++;
    json_out(['ok' => true, 'reposted' => true, 'repostCount' => $rc]);
}

// ---------- unrepost（v3.5.5：取消纯转发） ----------
if ($action === 'unrepost') {
    $id = trim((string)($in['id'] ?? ''));
    db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
        $s['reposts'] = array_values(array_filter($s['reposts'] ?? [], fn($r) => !(($r['post_id']??'')===$id && ($r['user']??'')===$meEmail)));
        return $s;
    });
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $rc = 0; foreach (($social['reposts']??[]) as $r) if (($r['post_id']??'')===$id) $rc++;
    json_out(['ok' => true, 'reposted' => false, 'repostCount' => $rc]);
}

// ---------- bookmark / unbookmark ----------
if ($action === 'bookmark' || $action === 'unbookmark') {
    $id = trim((string)($in['id'] ?? ''));
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$p || ($p['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);

    $action === 'bookmark' ?
        db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
            foreach (($s['bookmarks'] ?? []) as $r) if (($r['post_id']??'')===$id && ($r['user']??'')===$meEmail) return null;
            $s['bookmarks'][] = ['post_id'=>$id,'user'=>$meEmail,'at'=>now()]; return $s;
        }) :
        db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
            $s['bookmarks'] = array_values(array_filter($s['bookmarks'] ?? [], fn($r) => !(($r['post_id']??'')===$id && ($r['user']??'')===$meEmail)));
            return $s;
        });
    json_out(['ok' => true, 'bookmarked' => $action === 'bookmark']);
}

// ---------- follow / unfollow（canonical 落 follows.php） ----------
if ($action === 'follow' || $action === 'unfollow') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    if ($target === $meEmail) json_out(['ok' => false, 'err' => 'self', 'msg' => '不能关注自己']);
    $users = load_users();
    if (!isset($users['users'][$target])) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '该用户不存在'], 404);
    if (!empty($users['users'][$target]['banned'])) json_out(['ok' => false, 'err' => 'banned', 'msg' => '该账号已停用']);

    // v3.6.0：follow 前互动准入（unfollow 不阻断；互拉黑时双向 403）
    if ($action === 'follow') deny_if_blocked($meEmail, $target, 'follow');

    $action === 'follow' ?
        db_transact_json(FOLLOWS_FILE, [], function($fl) use ($meEmail, $target) {
            if (!isset($fl[$meEmail]) || !is_array($fl[$meEmail])) $fl[$meEmail] = [];
            if (!in_array($target, $fl[$meEmail], true)) $fl[$meEmail][] = $target;
            return $fl;
        }) :
        db_transact_json(FOLLOWS_FILE, [], function($fl) use ($meEmail, $target) {
            $fl[$meEmail] = array_values(array_filter($fl[$meEmail] ?? [], fn($x) => $x !== $target));
            return $fl;
        });
    if ($action === 'follow') add_notify($target, 'follow', $meEmail, null, '关注了你');
    json_out(['ok' => true]);
}

// ---------- following / followers（v3.6.0：分页 cursor/limit + 每项 relation 子对象；支持查看他人列表） ----------
if ($action === 'following' || $action === 'followers') {
    // 目标：默认查自己；传 email=查他人主页的关注/粉丝列表
    $targetEmail = isset($in['email']) && trim((string)$in['email']) !== '' ? strtolower(trim((string)$in['email'])) : $meEmail;
    $fl = db_read_json(FOLLOWS_FILE, []);
    $users = load_users();
    $blocksAll = db_read_json(BLOCKS_FILE, []);
    $mutesAll  = db_read_json(MUTES_FILE, []);
    $myBlocks = array_values(array_unique($blocksAll[$meEmail] ?? []));
    $myMutes  = array_values(array_unique($mutesAll[$meEmail] ?? []));

    // 权限边界：对方拉黑了我 → 看不到其关注/粉丝列表（X 语义，与 user_profile/posts_of 一致）
    if ($targetEmail !== $meEmail && blocked_by($meEmail, $targetEmail)) {
        json_out(['ok' => true, 'users' => [], 'nextCursor' => null]);
    }

    // 目标邮箱列表（保持既有顺序；仅保留存在且未 banned）
    $targets = [];
    if ($action === 'following') {
        foreach (($fl[$targetEmail] ?? []) as $ee) {
            $ee = strtolower((string)$ee);
            if (isset($users['users'][$ee]) && empty($users['users'][$ee]['banned'])) $targets[] = $ee;
        }
    } else {
        foreach ($fl as $follower => $list) {
            if (is_array($list) && in_array($targetEmail, $list, true) &&
                isset($users['users'][$follower]) && empty($users['users'][$follower]['banned'])) {
                $targets[] = strtolower((string)$follower);
            }
        }
    }

    // 分页：cursor=int 偏移（默认0），limit（默认20，max50）
    $cursor = isset($in['cursor']) ? max(0, (int)$in['cursor']) : 0;
    $limit  = isset($in['limit'])  ? max(1, min(50, (int)$in['limit'])) : 20;
    $total = count($targets);
    $page = array_slice($targets, $cursor, $limit);
    $next = ($cursor + $limit < $total) ? ($cursor + $limit) : null;

    // relation 一律查看者(我)视角，不恒 true：
    //   following    = 我(查看者)是否关注了列表项
    //   following_me = 列表项是否关注了我(查看者)
    $out = [];
    foreach ($page as $ee) {
        $pu = public_user($users['users'][$ee]);   // 已含 bio
        $pu['relation'] = [
            'following'    => in_array($ee, $fl[$meEmail] ?? [], true),
            'following_me' => in_array($meEmail, $fl[$ee] ?? [], true),
            'blocked'      => in_array($ee, $myBlocks, true),
            'muted'        => in_array($ee, $myMutes, true),
        ];
        $out[] = $pu;
    }
    json_out(['ok' => true, 'users' => $out, 'nextCursor' => $next]);
}

// ---------- remove_follower（v3.6.0：移除粉丝 ≠ Block；只删 follows[对方] 里对我的边，不写 blocks、不动我的关注） ----------
if ($action === 'remove_follower') {
    $target = strtolower(trim((string)($in['email'] ?? '')));
    // 空/自己/对方本就没关注我 → 幂等 ok:true；只删对方关注列表里我的那条边
    if ($target !== '' && $target !== $meEmail) {
        $ok = db_transact_json(FOLLOWS_FILE, [], function($fl) use ($meEmail, $target) {
            if (!isset($fl[$target]) || !is_array($fl[$target])) return null;   // 对方关注列表不存在 → 无改动
            $before = $fl[$target];
            $after  = array_values(array_diff($before, [$meEmail]));
            if ($after === $before) return null;   // 对方本就没关注我 → 无改动（幂等 ok）
            $fl[$target] = $after;
            return $fl;
        });
        if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '操作失败'], 500);
    }
    json_out(['ok' => true]);
}

// ---------- posts_of（v3.5.5：作者全部有效帖含回复帖 + 本人 reposts[] 转发的帖，带 repostWrapper） ----------
if ($action === 'posts_of') {
    $target = isset($in['email']) && trim((string)$in['email']) !== '' ? strtolower(trim((string)$in['email'])) : $meEmail;
    // v3.6.0：Block 可见性——对方拉黑了我 → 其主页帖不可见（与 user_profile 一致）；我拉黑对方仍可看
    if ($target !== $meEmail && blocked_by($meEmail, $target)) {
        json_out(['ok' => true, 'posts' => []]);
    }
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $users = load_users();
    $rows = [];   // [sortAt, post, wrapperOverride|null]
    foreach (filter_eligible($postsDoc['items'] ?? []) as $p) {
        if (($p['author'] ?? '') === $target) $rows[] = [$p['createdAt'] ?? '', $p, null];
    }
    foreach (($social['reposts'] ?? []) as $r) {
        if (($r['user'] ?? '') !== $target) continue;
        $orig = find_post_by_id($postsDoc['items'] ?? [], $r['post_id'] ?? '');
        if (!$orig || ($orig['status'] ?? 'normal') !== 'normal') continue;   // 原帖软删→转发不展示
        $oa = $orig['author'] ?? '';
        if (!isset($users['users'][$oa]) || !empty($users['users'][$oa]['banned'])) continue;
        $rows[] = [$r['at'] ?? '', $orig, ['byNickname' => author_card($target)['nickname'], 'at' => $r['at'] ?? '']];
    }
    usort($rows, fn($a, $b) => strcmp($b[0], $a[0]));   // 时间倒序（转发按转发时间）
    $out = [];
    foreach ($rows as $row) $out[] = $row[1];
    $decorated = decorate_posts($out, $meEmail, $postsDoc);
    foreach ($rows as $i => $row) {
        if ($row[2] !== null) $decorated[$i]['repostWrapper'] = $row[2];   // 包装层=主页主人转发
    }
    json_out(['ok' => true, 'posts' => array_values($decorated)]);
}

// ---------- user_profile（v3.6.0：他人/自己主页聚合——公开资料(含bio+counts) + relation + 其有效帖） ----------
if ($action === 'user_profile') {
    $target = isset($in['email']) && trim((string)$in['email']) !== '' ? strtolower(trim((string)$in['email'])) : $meEmail;
    $users = load_users();
    if (!isset($users['users'][$target]) || !is_array($users['users'][$target])) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '用户不存在'], 404);
    }
    $tu = $users['users'][$target];
    $targetBanned = !empty($tu['banned']);

    $fl = db_read_json(FOLLOWS_FILE, []);
    // counts：只算有效（存在且未 banned）
    $followingCount = 0;
    foreach (($fl[$target] ?? []) as $ee) {
        if (isset($users['users'][$ee]) && empty($users['users'][$ee]['banned'])) $followingCount++;
    }
    $followerCount = 0;
    foreach ($fl as $actor => $list) {
        if (is_array($list) && in_array($target, $list, true) &&
            isset($users['users'][$actor]) && empty($users['users'][$actor]['banned'])) $followerCount++;
    }

    // relation：自己查自己时 following/following_me 均 false（不能关注自己）
    $following = ($target !== $meEmail) && in_array($target, $fl[$meEmail] ?? [], true);
    $followingMe = false;
    if ($target !== $meEmail) {
        foreach ($fl as $actor => $list) {
            if (is_array($list) && $actor === $target && in_array($meEmail, $list, true)) { $followingMe = true; break; }
        }
    }
    $blocked   = has_blocked($meEmail, $target);    // 我拉黑了对方
    $blockedBy = blocked_by($meEmail, $target);     // 对方拉黑了我
    $muted    = is_muted($meEmail, $target);
    $mutual   = $following && $followingMe;

    $pu = public_user($tu);   // 含 bio
    $user = [
        'email' => $pu['email'], 'nickname' => $pu['nickname'], 'avatar' => $pu['avatar'],
        'bio' => $pu['bio'], 'role' => $pu['role'], 'banned' => $pu['banned'],
        'created_at' => $pu['created_at'],
        'following_count' => $followingCount, 'follower_count' => $followerCount,
    ];
    $relation = [
        'following' => $following, 'following_me' => $followingMe, 'mutual' => $mutual,
        'blocked' => $blocked, 'blocked_by' => $blockedBy, 'muted' => $muted,
    ];

    // Block 可见性：对方拉黑我 / 目标已 banned → posts 空（前端降级）；我拉黑对方仍可看主页帖
    $posts = [];
    if (!$blockedBy && !$targetBanned) {
        $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
        $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
        $rows = [];
        foreach (filter_eligible($postsDoc['items'] ?? []) as $p) {
            if (($p['author'] ?? '') === $target) $rows[] = [$p['createdAt'] ?? '', $p, null];
        }
        foreach (($social['reposts'] ?? []) as $r) {
            if (($r['user'] ?? '') !== $target) continue;
            $orig = find_post_by_id($postsDoc['items'] ?? [], $r['post_id'] ?? '');
            if (!$orig || ($orig['status'] ?? 'normal') !== 'normal') continue;
            $oa = $orig['author'] ?? '';
            if (!isset($users['users'][$oa]) || !empty($users['users'][$oa]['banned'])) continue;
            $rows[] = [$r['at'] ?? '', $orig, ['byNickname' => author_card($target)['nickname'], 'at' => $r['at'] ?? '']];
        }
        usort($rows, fn($a, $b) => strcmp($b[0], $a[0]));
        $out = [];
        foreach ($rows as $row) $out[] = $row[1];
        $decorated = decorate_posts($out, $meEmail, $postsDoc);
        foreach ($rows as $i => $row) {
            if ($row[2] !== null) $decorated[$i]['repostWrapper'] = $row[2];
        }
        $posts = array_values($decorated);
    }

    json_out(['ok' => true, 'user' => $user, 'relation' => $relation, 'posts' => $posts, 'empty' => null]);
}

// ---------- comments_of（v3.5.5：按 conversation_id=id 返回回复树；hidden 默认过滤，include_hidden=1 给 hiddenComments） ----------
if ($action === 'comments_of') {
    $id = trim((string)($in['id'] ?? ''));
    $includeHidden = isset($in['include_hidden']) && (string)$in['include_hidden'] === '1';
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $items = $postsDoc['items'] ?? [];
    $root = find_post_by_id($items, $id);
    if (!$root || ($root['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);
    $convId = conv_id_of($root);

    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $users = load_users();
    $manifest = manifest_get();
    $itemsById = []; foreach ($items as $it) $itemsById[$it['id']] = $it;

    // 本 conversation 全部 normal 回复帖
    $rootDirect = []; $byParent = [];
    foreach ($items as $it) {
        if (($it['status'] ?? 'normal') !== 'normal') continue;
        if (empty($it['reply_to_post_id'])) continue;            // 非回复帖
        if (conv_id_of($it) !== $convId) continue;
        if (($it['reply_to_post_id'] ?? '') === $id) $rootDirect[] = $it;
        else $byParent[$it['reply_to_post_id']][] = $it;
    }
    $visibleRoots = []; $hiddenRoots = [];
    foreach ($rootDirect as $rp) { if (!empty($rp['hidden'])) $hiddenRoots[] = $rp; else $visibleRoots[] = $rp; }
    usort($visibleRoots, fn($a, $b) => strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? ''));
    usort($hiddenRoots, fn($a, $b) => strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? ''));

    $buildGroups = function($rootsArr) use ($byParent, $itemsById, $social, $users, $manifest, $meEmail, $id) {
        $out = [];
        foreach ($rootsArr as $rc) {
            $kids = $byParent[$rc['id']] ?? [];
            usort($kids, fn($a, $b) => strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? ''));
            $replies = [];
            foreach ($kids as $kp) $replies[] = build_reply_view($kp, $id, $itemsById, $social, $users, $manifest, $meEmail);
            $out[] = ['root' => build_reply_view($rc, $id, $itemsById, $social, $users, $manifest, $meEmail), 'replies' => $replies];
        }
        return $out;
    };
    $rootComments = $buildGroups($visibleRoots);
    $hiddenComments = $includeHidden ? $buildGroups($hiddenRoots) : [];
    $commentCount = count($rootDirect);   // 直接回复数（含 hidden，与 replyCount 口径一致）
    json_out(['ok' => true, 'rootComments' => array_values($rootComments), 'commentCount' => $commentCount,
              'hiddenComments' => array_values($hiddenComments), 'nextCursor' => null]);
}

// ---------- reply_settings（v3.5.5：作者改自己帖 reply_policy，非作者 403） ----------
if ($action === 'reply_settings') {
    $id = trim((string)($in['id'] ?? ''));
    $policy = isset($in['reply_policy']) ? (string)$in['reply_policy'] : 'all';
    if (!in_array($policy, ['all','following','mentioned'], true)) {
        json_out(['ok' => false, 'err' => 'policy', 'msg' => '回复范围不合法']);
    }
    $state = 'notfound';
    db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($id, $policy, $meEmail, &$state) {
        $items = $d['items'] ?? [];
        foreach ($items as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                if (($p['author'] ?? '') !== $meEmail) { $state = 'forbidden'; return null; }
                $items[$i]['reply_policy'] = $policy;
                $d['items'] = $items;
                $state = 'done'; return $d;
            }
        }
        return null;
    });
    if ($state === 'notfound') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);
    if ($state === 'forbidden') json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能修改自己的动态'], 403);
    json_out(['ok' => true, 'reply_policy' => $policy]);
}

// ---------- hide_reply / unhide_reply（v3.5.5：根帖作者隐藏/取消隐藏某回复，非作者 403） ----------
if ($action === 'hide_reply' || $action === 'unhide_reply') {
    $id = trim((string)($in['id'] ?? ''));        // 根帖 id
    $replyId = trim((string)($in['reply_id'] ?? ''));
    $setHidden = ($action === 'hide_reply');
    $state = 'notfound';
    db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($id, $replyId, $meEmail, $setHidden, &$state) {
        $root = find_post_by_id($d['items'] ?? [], $id);
        if (!$root) return null;
        if (($root['author'] ?? '') !== $meEmail) { $state = 'forbidden'; return null; }
        $conv = conv_id_of($root);
        $items = $d['items'] ?? [];
        foreach ($items as $i => $rp) {
            if (($rp['id'] ?? '') === $replyId) {
                if (empty($rp['reply_to_post_id']) || conv_id_of($rp) !== $conv) { $state = 'badreply'; return null; }
                $items[$i]['hidden'] = $setHidden;
                $d['items'] = $items;
                $state = 'done'; return $d;
            }
        }
        return null;
    });
    if ($state === 'notfound')  json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);
    if ($state === 'forbidden') json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能由原帖作者操作'], 403);
    if ($state === 'badreply')  json_out(['ok' => false, 'err' => 'notfound', 'msg' => '回复不存在'], 404);
    json_out(['ok' => true, 'hidden' => $setHidden]);
}

// ---------- search_users（@提及搜索） ----------
if ($action === 'search_users') {
    $q = trim((string)($in['q'] ?? ''));
    if ($q === '') json_out(['ok' => true, 'users' => []]);
    $users = load_users(); $out = [];
    foreach ($users['users'] as $ee => $u) {
        if ($ee === $meEmail || !empty($u['banned'])) continue;
        $nick = (string)($u['nickname'] ?? '');
        if (mb_strpos($nick, $q) !== false || mb_strpos($ee, $q) !== false) {
            $out[] = ['email' => $ee, 'nickname' => $nick, 'avatar' => $u['avatar'] ?? 'zodiac:rat'];
        }
        if (count($out) >= 20) break;
    }
    json_out(['ok' => true, 'users' => $out]);
}

// ---------- score_cards（我的打卡记录 → 成绩卡候选，只回卡片字段） ----------
if ($action === 'score_cards') {
    $file = user_data_file($me['email']);
    $d = db_read_json($file, null);
    $data = (is_array($d) && isset($d['data']) && is_array($d['data'])) ? $d['data'] : null;
    $cards = [];
    if ($data && !empty($data['habits']) && !empty($data['checkins'])) {
        $today = date('Y-m-d');
        foreach ($data['habits'] as $h) {
            if (empty($h['enabled']) || !empty($h['deleted'])) continue;
            $anchor = (string)($h['anchorDate'] ?? $today);
            $cycleDays = max(1, (int)($h['cycleDays'] ?? 7));
            $diffDays = (int)floor((strtotime($today) - strtotime($anchor)) / 86400);
            if ($diffDays < 0) continue;
            $k = intdiv($diffDays, $cycleDays);
            $start = date('Y-m-d', strtotime($anchor . " +{$k} days"));
            $end = date('Y-m-d', strtotime($start . " +" . ($cycleDays - 1) . " days"));
            $weekCheckins = 0; $weekTotal = 0;
            foreach ($data['checkins'] as $c) {
                if (($c['habitId'] ?? '') !== ($h['id'] ?? '')) continue;
                $cd = (string)($c['date'] ?? '');
                if ($cd < $start || $cd > $end) continue;
                $min = (int)($c['minutes'] ?? 0);
                $needMin = (int)($h['minMinutes'] ?? 0);
                $valid = ($needMin > 0) ? ($min >= $needMin) : true;
                if ($valid) { $weekCheckins++; $weekTotal += $min; }
            }
            // 奖励分钟：触发值 +（达标且全部超阈值则 boost）
            $n = (int)($h['triggerDays'] ?? $h['requiredCount'] ?? $cycleDays);
            $rewardMinutes = 0;
            if ($weekCheckins >= $n) {
                $tv = (int)($h['triggerValue'] ?? 0);
                if (($h['type'] ?? 'check') === 'time' && $tv < 0) $tv = 0;
                $rewardMinutes += $tv;
                if (!empty($h['boostEnabled']) && $weekCheckins > 0) {
                    $th = (int)($h['boostThresholdMin'] ?? 60);
                    $allBoost = true;
                    foreach ($data['checkins'] as $c) {
                        if (($c['habitId']??'')===($h['id']??'') && ($c['date']??'')>=$start && ($c['date']??'')<=$end && (int)($c['minutes']??0) < $th) { $allBoost = false; break; }
                    }
                    if ($allBoost) $rewardMinutes += (int)($h['boostRewardMin'] ?? 0);
                }
            }
            $cards[] = [
                'habitId' => $h['id'] ?? '', 'habitName' => (string)($h['name'] ?? '未命名'),
                'cycleStart' => $start, 'cycleEnd' => $end,
                'weekCheckins' => $weekCheckins, 'weekTotal' => $weekTotal,
                'rewardMinutes' => $rewardMinutes,
            ];
        }
    }
    json_out(['ok' => true, 'cards' => $cards]);
}

// ---------- notify / notify_read / unread ----------
if ($action === 'notify') {
    $n = db_read_json(NOTIFY_FILE, NOTIFY_DEFAULT);
    $mine = [];
    foreach ($n['items'] ?? [] as $it) if (($it['to'] ?? '') === $meEmail) $mine[] = $it;
    $mine = array_reverse($mine);
    $afterId = isset($in['afterId']) ? (string)$in['afterId'] : '';
    if ($afterId !== '') {
        $pos = null;
        foreach ($mine as $i => $it) if (($it['id'] ?? '') === $afterId) { $pos = $i + 1; break; }
        if ($pos !== null) $mine = array_slice($mine, $pos);
    }
    foreach ($mine as &$it) { $a = author_card($it['by'] ?? ''); $it['byNickname'] = $a['nickname']; $it['byAvatar'] = $a['avatar']; }
    unset($it);
    json_out(['ok' => true, 'items' => $mine]);
}
if ($action === 'notify_read') {
    $ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [];
    db_transact_json(NOTIFY_FILE, NOTIFY_DEFAULT, function($n) use ($meEmail, $ids) {
        $items = $n['items'] ?? [];
        foreach ($items as &$it) {
            if (($it['to'] ?? '') !== $meEmail) continue;
            if (empty($ids) || in_array($it['id'] ?? '', $ids, true)) $it['read'] = true;
        }
        unset($it); $n['items'] = $items; return $n;
    });
    json_out(['ok' => true]);
}
if ($action === 'unread') {
    $n = db_read_json(NOTIFY_FILE, NOTIFY_DEFAULT);
    $cnt = 0;
    foreach ($n['items'] ?? [] as $it) if (($it['to'] ?? '') === $meEmail && empty($it['read'])) $cnt++;
    json_out(['ok' => true, 'notify' => $cnt, 'dm' => 0]);
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
