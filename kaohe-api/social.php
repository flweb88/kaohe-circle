<?php
/* 考核小管家 v3.5.0 - 考核圈（社交动态）· MVP-5 动态核心
 * actions: staff_card(无鉴权) / feed / post / del / like / unlike / comment / repost /
 *          bookmark / unbookmark / follow / unfollow / following / followers / posts_of /
 *          search_users / score_cards / notify / notify_read / unread
 * 约定：POST + JSON body，action 走 query string；除 staff_card 外一律 require_login。
 * 数据文件（kaohe-data/，守卫头+JSON，社交数据不进每用户文件）：
 *   posts.php {items:[Post], reqs:{client_req_id:postId}}
 *   social.php {likes:[],comments:[],reposts:[],bookmarks:[]}
 *   follows.php {<follower>:[<followee>...]}
 *   notify.php {items:[Notify]}
 *   uploads/manifest.php {items:{<id>:{...}}}
 * 所有写动作一律走 db_transact_json（持锁读改写）。
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
const SOCIAL_DEFAULT  = ['likes' => [], 'comments' => [], 'reposts' => [], 'bookmarks' => []];
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

// 追加一条提醒（to/by 邮箱）；已存在同 (to,type,by,postId) 则不重复落库
function add_notify($to, $type, $by, $postId, $text) {
    if ($to === '' || $to === $by) return;
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
        foreach (['orig','sm','md','video720'] as $v) {
            if (!empty($files[$v])) $urls[$v] = 'media.php?action=raw&f=' . rawurlencode($mid) . '&v=' . $v;
        }
        $out[] = [
            'id' => $mid, 'kind' => $rec['kind'] ?? 'image', 'mime' => $rec['mime'] ?? '',
            'size' => (int)($rec['size'] ?? 0), 'status' => $rec['status'] ?? 'pending',
            'url' => $urls,
        ];
    }
    return $out;
}

// 批量 decorate：一次性读 social/manifest，给整批帖子附计数与交互态
function decorate_posts($posts, $meEmail) {
    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $manifest = manifest_get();
    $users = load_users();
    foreach ($posts as &$p) {
        $au = $p['author'] ?? '';
        $a = author_card($au);
        $pid = $p['id'];
        $likeCount = $commentCount = $repostCount = 0;
        $liked = $reposted = $bookmarked = false;
        foreach (($social['likes'] ?? []) as $r)    { if (($r['post_id'] ?? '') === $pid) { $likeCount++; if (($r['user']??'') === $meEmail) $liked = true; } }
        foreach (($social['comments'] ?? []) as $r) { if (($r['post_id'] ?? '') === $pid && ($r['status'] ?? 'normal') === 'normal') $commentCount++; }
        foreach (($social['reposts'] ?? []) as $r)  { if (($r['post_id'] ?? '') === $pid) { $repostCount++; if (($r['user']??'') === $meEmail) $reposted = true; } }
        foreach (($social['bookmarks'] ?? []) as $r){ if (($r['post_id'] ?? '') === $pid && ($r['user']??'') === $meEmail) $bookmarked = true; }
        $p['media'] = resolve_media($p['media_ids'] ?? [], $manifest);
        $p['authorNick'] = $a['nickname'];
        $p['authorAvatar'] = $a['avatar'];
        $p['liked'] = $liked; $p['reposted'] = $reposted; $p['bookmarked'] = $bookmarked;
        $p['likeCount'] = $likeCount; $p['commentCount'] = $commentCount; $p['repostCount'] = $repostCount;
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
        $posts = decorate_posts($page, $meEmail);
        json_out(['ok' => true, 'posts' => array_values($posts), 'nextCursor' => $next,
                  'empty' => $empty && empty($page) ? $empty : null]);
    }

    // recommend：全站有效帖伪随机 shuffle；每次请求重抽一批，前端 feedSeen 去重
    shuffle($items);
    $page = array_slice($items, 0, $limit);
    $posts = decorate_posts(array_values($page), $meEmail);
    json_out(['ok' => true, 'posts' => array_values($posts), 'nextCursor' => null]);
}

// ---------- post ----------
if ($action === 'post') {
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    $mediaIds = isset($in['media']) && is_array($in['media']) ? array_values($in['media']) : [];
    $mediaIds = array_slice($mediaIds, 0, 4);
    $replyPolicy = isset($in['reply_policy']) ? (string)$in['reply_policy'] : 'all';
    if (!in_array($replyPolicy, ['all','following','mentioned'], true)) $replyPolicy = 'all';
    $reqId = isset($in['client_req_id']) ? substr(trim((string)$in['client_req_id']), 0, 64) : '';

    if ($text === '' && empty($mediaIds) && !isset($in['score'])) {
        json_out(['ok' => false, 'err' => 'empty', 'msg' => '内容不能为空']);
    }
    if ($text !== '' && mb_strlen($text) > 500) {
        json_out(['ok' => false, 'err' => 'toolong', 'msg' => '内容须 ≤500 字']);
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
        'repost_of' => null,
        'createdAt' => now(), 'status' => 'normal', 'schema_v' => 1,
    ];

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

    $snippet = mb_substr($text, 0, 60);
    foreach ($mentions as $ee) add_notify($ee, 'mention', $meEmail, $post['id'], $snippet);

    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $fresh = find_post_by_id($postsDoc['items'] ?? [], $post['id']);
    json_out(['ok' => true, 'post' => $fresh ? array_values(decorate_posts([$fresh], $meEmail))[0] : $post]);
}

// ---------- del（软删帖/评论 + 清理媒体） ----------
if ($action === 'del') {
    $id = trim((string)($in['id'] ?? ''));
    $kind = isset($in['kind']) ? (string)$in['kind'] : 'post';

    if ($kind === 'comment') {
        $state = 'notfound';
        $ok = db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail, $me, &$state) {
            $comments = $s['comments'] ?? [];
            foreach ($comments as $i => &$c) {
                if (($c['id'] ?? '') === $id) {
                    if (($c['user'] ?? '') !== $meEmail && ($me['role'] ?? 'user') !== 'admin') { $state = 'forbidden'; return null; }
                    $c['status'] = 'deleted'; $state = 'done'; $s['comments'] = $comments; return $s;
                }
            }
            return null;
        });
        if ($state === 'notfound') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '评论不存在'], 404);
        if ($state === 'forbidden') json_out(['ok' => false, 'err' => 'forbidden', 'msg' => '只能删除自己的评论'], 403);
        if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '删除失败'], 500);
        json_out(['ok' => true]);
    }

    // kind=post
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

// ---------- comment（服务端强制 reply_policy） ----------
if ($action === 'comment') {
    $id = trim((string)($in['id'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') json_out(['ok' => false, 'err' => 'text', 'msg' => '评论内容不能为空']);
    if (mb_strlen($text) > 200) json_out(['ok' => false, 'err' => 'toolong', 'msg' => '评论须 ≤200 字']);

    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$p || ($p['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);

    // reply_policy 服务端校验（绕过前端直调 API 同样拦截）
    $au = $p['author'] ?? '';
    $policy = $p['reply_policy'] ?? 'all';
    $allowed = ($meEmail === $au);
    if (!$allowed && $policy === 'all') $allowed = true;
    if (!$allowed && $policy === 'following') {
        $follows = db_read_json(FOLLOWS_FILE, []);
        if (in_array($meEmail, $follows[$au] ?? [], true)) $allowed = true;
    }
    if (!$allowed && $policy === 'mentioned') {
        if (in_array($meEmail, $p['mentions'] ?? [], true)) $allowed = true;
    }
    if (!$allowed) json_out(['ok' => false, 'err' => 'forbidden_reply', 'msg' => '作者不允许你回复'], 403);

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
    $cm = ['id'=>new_id(),'post_id'=>$id,'user'=>$meEmail,'text'=>$text,
           'mentions'=>array_keys($mentions),'reply_to'=>null,'at'=>now(),'status'=>'normal'];
    $ok = db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($cm) {
        $s['comments'][] = $cm; return $s;
    });
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '评论失败'], 500);

    add_notify($au, 'comment', $meEmail, $id, $text);
    foreach (array_keys($mentions) as $ee) add_notify($ee, 'mention', $meEmail, $id, mb_substr($text,0,60));

    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $cc = 0; foreach (($social['comments']??[]) as $r) if (($r['post_id']??'')===$id && ($r['status']??'normal')==='normal') $cc++;
    json_out(['ok' => true, 'comment' => $cm, 'commentCount' => $cc]);
}

// ---------- repost（生成引用原帖的新帖） ----------
if ($action === 'repost') {
    $id = trim((string)($in['id'] ?? ''));
    $text = isset($in['text']) ? trim((string)$in['text']) : '';
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $orig = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$orig || ($orig['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '原动态不存在或已删除'], 404);

    $newPost = [
        'id'=>new_id(),'author'=>$meEmail,'type'=>'repost','text'=>$text,'media_ids'=>[],
        'share_card'=>null,'mentions'=>[],'reply_policy'=>'all','repost_of'=>$id,
        'createdAt'=>now(),'status'=>'normal','schema_v'=>1,
    ];
    $ok = db_transact_json(POSTS_FILE, POSTS_DEFAULT, function($d) use ($newPost) {
        $d['items'][] = $newPost; return $d;
    });
    // reposts 关系落 social.php
    db_transact_json(SOCIAL_FILE, SOCIAL_DEFAULT, function($s) use ($id, $meEmail) {
        foreach (($s['reposts'] ?? []) as $r) if (($r['post_id']??'')===$id && ($r['user']??'')===$meEmail) return null;
        $s['reposts'][] = ['post_id'=>$id,'user'=>$meEmail,'at'=>now()]; return $s;
    });
    if ($ok === false) json_out(['ok' => false, 'err' => 'io', 'msg' => '转发失败'], 500);

    add_notify($orig['author'], 'repost', $meEmail, $id, '转发了你的动态');
    foreach (($orig['mentions'] ?? []) as $ee) add_notify($ee, 'mention', $meEmail, $newPost['id'], $text);

    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $rc = 0; foreach (($social['reposts']??[]) as $r) if (($r['post_id']??'')===$id) $rc++;
    json_out(['ok' => true, 'post' => $newPost, 'repostCount' => $rc]);
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

// ---------- following / followers ----------
if ($action === 'following') {
    $fl = db_read_json(FOLLOWS_FILE, []);
    $list = $fl[$meEmail] ?? [];
    $users = load_users(); $out = [];
    foreach ($list as $ee) { if (isset($users['users'][$ee]) && empty($users['users'][$ee]['banned'])) $out[] = public_user($users['users'][$ee]); }
    json_out(['ok' => true, 'users' => $out]);
}
if ($action === 'followers') {
    $fl = db_read_json(FOLLOWS_FILE, []);
    $users = load_users(); $out = [];
    foreach ($fl as $follower => $list) {
        if (is_array($list) && in_array($meEmail, $list, true) && isset($users['users'][$follower]) && empty($users['users'][$follower]['banned'])) {
            $out[] = public_user($users['users'][$follower]);
        }
    }
    json_out(['ok' => true, 'users' => $out]);
}

// ---------- posts_of ----------
if ($action === 'posts_of') {
    $target = isset($in['email']) && trim((string)$in['email']) !== '' ? strtolower(trim((string)$in['email'])) : $meEmail;
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $out = [];
    foreach (filter_eligible($postsDoc['items'] ?? []) as $p) {
        if (($p['author'] ?? '') === $target) $out[] = $p;
    }
    usort($out, function($a,$b){ return strcmp($b['createdAt']??'', $a['createdAt']??''); });
    json_out(['ok' => true, 'posts' => array_values(decorate_posts($out, $meEmail))]);
}

// ---------- comments_of（评论展开：历史评论 + 分页） ----------
if ($action === 'comments_of') {
    $id = trim((string)($in['id'] ?? ''));
    $postsDoc = db_read_json(POSTS_FILE, POSTS_DEFAULT);
    $p = find_post_by_id($postsDoc['items'] ?? [], $id);
    if (!$p || ($p['status'] ?? 'normal') !== 'normal') json_out(['ok' => false, 'err' => 'notfound', 'msg' => '动态不存在'], 404);

    $social = db_read_json(SOCIAL_FILE, SOCIAL_DEFAULT);
    $users = load_users();
    // 该帖有效评论（软删过滤），按 at 时间正序（旧→新）
    $all = [];
    foreach (($social['comments'] ?? []) as $c) {
        if (($c['post_id'] ?? '') !== $id) continue;
        if (($c['status'] ?? 'normal') !== 'normal') continue;
        $all[] = $c;
    }
    usort($all, function($a,$b){ return strcmp($a['at'] ?? '', $b['at'] ?? ''); });
    $commentCount = count($all);

    // cursor：取 at 游标（>cursor 的部分）；缺省从头
    $cursor = isset($in['cursor']) ? (string)$in['cursor'] : '';
    if ($cursor !== '') $all = array_values(array_filter($all, fn($c) => ($c['at'] ?? '') > $cursor));

    $limit = 30;
    $page = array_slice($all, 0, $limit);
    $nextCursor = count($all) > $limit ? ($page[count($page)-1]['at'] ?? null) : null;

    $out = [];
    foreach ($page as $c) {
        $au = $users['users'][$c['user'] ?? ''] ?? null;
        $out[] = [
            'id' => $c['id'], 'post_id' => $id, 'user' => $c['user'], 'text' => $c['text'],
            'mentions' => $c['mentions'] ?? [], 'reply_to' => $c['reply_to'] ?? null,
            'at' => $c['at'], 'status' => 'normal',
            'authorNick' => $au['nickname'] ?? '已注销',
            'authorAvatar' => $au['avatar'] ?? 'zodiac:rat',
        ];
    }
    json_out(['ok' => true, 'comments' => array_values($out), 'commentCount' => $commentCount, 'nextCursor' => $nextCursor]);
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
