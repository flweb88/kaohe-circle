<?php
/* 考核小管家 v3.8.3 - 账号删除与注销 · 全量清理库
 * 仅定义函数、不执行任何 endpoint 逻辑；由 admin.php（del_user）与 auth.php（resign）require。
 *
 * purge_user($email)：按序执行「备份 → 全量清理」。
 *   a) 备份（全部写成功才允许进入删除；任一失败即中止并抛异常，数据零改动）：
 *      BACKUP_DIR/{email}-{Ymd-His}/ 下写 user_entry.php / habits.php(若存在) / social_snapshot.php / manifest.php
 *   b~h) 跨 9 个数据文件的 db_transact_multi 原子事务：
 *      users 移除 entry（tokens 随账号移除=强制登出）→ posts 物理移除本人 items + 引用字段置 null
 *      → social 五类互动清理 → follows/blocks/mutes 双向全清 → dms 全局删除参与会话
 *      → notify 双向移除 → manifest 媒体登记清理
 *   i~j) 事务成功后 unlink：打卡数据文件 / custom 头像文件 / 被删帖媒体文件（复用 social.php del 的 glob 模式）
 *
 * 返回备份目录名（basename）；失败抛 RuntimeException（调用方回 500）。
 * 硬约束：所有数据写一律走 db_transact_json / db_transact_multi；本文件不裸改数据文件。
 */
require_once __DIR__ . '/common.php';

// social.php / dms.php 内联定义的路径常量（本库被 admin.php / auth.php require 时未加载它们，自行兜底定义）
if (!defined('POSTS_FILE'))     define('POSTS_FILE',     DATA_DIR . 'posts.php');
if (!defined('SOCIAL_FILE'))   define('SOCIAL_FILE',    DATA_DIR . 'social.php');
if (!defined('FOLLOWS_FILE')) define('FOLLOWS_FILE',   DATA_DIR . 'follows.php');
if (!defined('NOTIFY_FILE'))   define('NOTIFY_FILE',     DATA_DIR . 'notify.php');
if (!defined('DMS_FILE'))      define('DMS_FILE',        DATA_DIR . 'dms.php');
if (!defined('MANIFEST_FILE')) define('MANIFEST_FILE',    UPLOAD_DIR . 'manifest.php');

// 与 social.php / dms.php 保持一致的缺省结构
const AC_POSTS_DEFAULT   = ['items' => [], 'reqs' => []];
const AC_SOCIAL_DEFAULT = ['likes' => [], 'comments' => [], 'reposts' => [], 'bookmarks' => [],
                        'comment_likes' => [], 'comment_bookmarks' => []];
const AC_DMS_DEFAULT    = ['conversations' => [], 'conversation_members' => [], 'messages' => [], 'message_user_state' => []];
const AC_NOTIFY_DEFAULT = ['items' => []];
const AC_USERS_DEFAULT  = ['users' => [], 'invite' => '', 'reg_log' => [], 'forgot_log' => [], 'migrated' => false];

/**
 * 拆分 items：返回 [deletedIds(被删帖id=>true), purgedMedia(本人媒体id=>true), kept, cascadeReplies(级联移除的他人回复)]。
 * 级联规则：除 author==email 的本人帖外，reply_to_post_id / conversation_id 指向已删 id 的条目一并移除
 * （根帖消失后其回复树无展示场景；迭代至不动点，保证不留悬空回复树指针）。
 * 注意：purgedMedia 仅收集本人帖媒体；级联移除的他人回复媒体属于其他存活用户，不删除。
 */
function ac_split_posts(array $items, string $email) {
    $deletedIds  = [];
    $purgedMedia = [];
    $kept = [];
    $cascade = [];
    foreach ($items as $p) {
        if (($p['author'] ?? '') === $email) {
            $pid = (string)($p['id'] ?? '');
            if ($pid !== '') $deletedIds[$pid] = true;
            foreach (($p['media_ids'] ?? []) as $mid) {
                $mid = (string)$mid;
                if ($mid !== '') $purgedMedia[$mid] = true;
            }
        } else {
            $kept[] = $p;
        }
    }
    do {
        $changed = false;
        $still = [];
        foreach ($kept as $p) {
            $rto = (string)($p['reply_to_post_id'] ?? '');
            $cid = (string)($p['conversation_id'] ?? '');
            if (($rto !== '' && isset($deletedIds[$rto])) || ($cid !== '' && isset($deletedIds[$cid]))) {
                $pid = (string)($p['id'] ?? '');
                if ($pid !== '') $deletedIds[$pid] = true;
                $cascade[] = $p;
                $changed = true;
            } else {
                $still[] = $p;
            }
        }
        $kept = $still;
    } while ($changed);
    return [$deletedIds, $purgedMedia, $kept, $cascade];
}

/**
 * 全量注销账号。返回备份目录名；失败抛 RuntimeException。
 */
function purge_user($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') throw new RuntimeException('email empty');

    // ---------- 前置读取（清理前快照；此阶段只读，不改任何数据） ----------
    $users    = db_read_json(USERS_FILE, AC_USERS_DEFAULT);
    $userEnt  = $users['users'][$email] ?? null;
    if (!is_array($userEnt)) throw new RuntimeException('user not found: ' . $email);

    $postsDoc = db_read_json(POSTS_FILE, AC_POSTS_DEFAULT);
    $social   = db_read_json(SOCIAL_FILE, AC_SOCIAL_DEFAULT);
    $follows  = db_read_json(FOLLOWS_FILE, []);
    $blocks   = db_read_json(BLOCKS_FILE, []);
    $mutes    = db_read_json(MUTES_FILE, []);
    $dms      = db_read_json(DMS_FILE, AC_DMS_DEFAULT);
    $notify   = db_read_json(NOTIFY_FILE, AC_NOTIFY_DEFAULT);

    // ---------- a) 备份：先于一切删除动作，全部写成功才继续 ----------
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0775, true)) {
        throw new RuntimeException('backup dir not writable: ' . BACKUP_DIR);
    }
    $backupName = $email . '-' . date('Ymd-His');
    $backupDir  = BACKUP_DIR . $backupName . '/';
    if (!@mkdir($backupDir, 0775, true)) {
        throw new RuntimeException('backup subdir not writable: ' . $backupDir);
    }

    // 备份 1：用户 entry（含 tokens；守卫头保护，Web 不可直读）
    if (!db_write_json($backupDir . 'user_entry.php', $userEnt)) {
        throw new RuntimeException('backup write failed: user_entry');
    }
    // 备份 2：打卡数据文件（存在才写）
    $habitsFile = user_data_file($email);
    if (is_file($habitsFile)) {
        $habits = db_read_json($habitsFile, new stdClass());
        if (!db_write_json($backupDir . 'habits.php', $habits)) {
            throw new RuntimeException('backup write failed: habits');
        }
    }
    // 备份 3：该用户相关社交/关系/私信/通知摘要
    // follows/blocks/mutes 中"入边"= 其他键的值数组含 email 的边
    $inboundEdges = function(array $map, $email) {
        $out = [];
        foreach ($map as $k => $arr) {
            if (is_array($arr) && in_array($email, $arr, true)) $out[$k] = $arr;
        }
        return $out;
    };
    [, , , $cascadeReplies] = ac_split_posts(is_array($postsDoc['items'] ?? null) ? $postsDoc['items'] : [], $email);
    $snapshot = [
        'email'           => $email,
        'posts_authored'  => array_values(array_filter($postsDoc['items'] ?? [], fn($p) => ($p['author'] ?? '') === $email)),
        'deleted_related_replies' => $cascadeReplies,   // 根帖被删后级联移除的他人回复（可恢复）
        'likes'           => array_values(array_filter($social['likes'] ?? [],             fn($r) => ($r['user'] ?? '') === $email)),
        'comments_legacy'  => array_values(array_filter($social['comments'] ?? [],         fn($c) => ($c['user'] ?? '') === $email)),
        'reposts'         => array_values(array_filter($social['reposts'] ?? [],           fn($r) => ($r['user'] ?? '') === $email)),
        'bookmarks'       => array_values(array_filter($social['bookmarks'] ?? [],          fn($r) => ($r['user'] ?? '') === $email)),
        'comment_likes'   => array_values(array_filter($social['comment_likes'] ?? [],     fn($r) => ($r['user'] ?? '') === $email)),
        'comment_bookmarks'=> array_values(array_filter($social['comment_bookmarks'] ?? [], fn($r) => ($r['user'] ?? '') === $email)),
        'follows_out'     => is_array($follows) ? ($follows[$email] ?? []) : [],
        'follows_in'      => $inboundEdges(is_array($follows) ? $follows : [], $email),
        'blocks_out'      => is_array($blocks) ? ($blocks[$email] ?? []) : [],
        'blocks_in'       => $inboundEdges(is_array($blocks) ? $blocks : [], $email),
        'mutes_out'       => is_array($mutes) ? ($mutes[$email] ?? []) : [],
        'mutes_in'        => $inboundEdges(is_array($mutes) ? $mutes : [], $email),
        'notify'          => array_values(array_filter($notify['items'] ?? [], fn($it) => ($it['to'] ?? '') === $email || ($it['by'] ?? '') === $email)),
    ];
    // dms 摘要：该用户参与的会话 → 整会话相关行全量备份（全局删除后可恢复）
    $myConvIds = [];
    foreach ($dms['conversation_members'] ?? [] as $m) {
        $cid = (string)($m['conversation_id'] ?? '');
        if ($cid !== '' && ($m['user_id'] ?? '') === $email) $myConvIds[$cid] = true;
    }
    $convHasLocal = function($c, $map) { $c = (string)$c; return $c !== '' && isset($map[$c]); };
    $snapshot['dms'] = [
        'conversations'        => array_values(array_filter($dms['conversations'] ?? [],        fn($c) => $convHasLocal($c['id'] ?? '', $myConvIds))),
        'conversation_members' => array_values(array_filter($dms['conversation_members'] ?? [], fn($m) => ($m['user_id'] ?? '') === $email || $convHasLocal($m['conversation_id'] ?? '', $myConvIds))),
        'messages'             => array_values(array_filter($dms['messages'] ?? [],             fn($m) => ($m['sender_id'] ?? '') === $email || $convHasLocal($m['conversation_id'] ?? '', $myConvIds))),
    ];
    $myMsgIds = [];
    foreach ($snapshot['dms']['messages'] as $m) { $mid = (string)($m['id'] ?? ''); if ($mid !== '') $myMsgIds[$mid] = true; }
    $snapshot['dms']['message_user_state'] = array_values(array_filter($dms['message_user_state'] ?? [], function($s) use ($email, $myMsgIds) {
        if (($s['user_id'] ?? '') === $email) return true;
        $mid = (string)($s['message_id'] ?? '');
        return $mid !== '' && isset($myMsgIds[$mid]);
    }));
    if (!db_write_json($backupDir . 'social_snapshot.php', $snapshot)) {
        throw new RuntimeException('backup write failed: social_snapshot');
    }
    // 备份 4：清单
    if (!db_write_json($backupDir . 'manifest.php', [
        'email' => $email, 'backed_up_at' => now(), 'action' => 'purge_user',
        'note'  => '账号注销/删除前快照。user_entry=users.php 该用户完整 entry；habits=打卡数据；social_snapshot=社交/关系/私信/通知相关行。',
    ])) {
        throw new RuntimeException('backup write failed: manifest');
    }
    // 备份全部写成功 → 进入清理阶段

    // ---------- b~h) 跨文件原子事务清理 ----------
    $files = [
        USERS_FILE     => AC_USERS_DEFAULT,
        POSTS_FILE     => AC_POSTS_DEFAULT,
        SOCIAL_FILE    => AC_SOCIAL_DEFAULT,
        FOLLOWS_FILE   => [],
        BLOCKS_FILE    => [],
        MUTES_FILE     => [],
        DMS_FILE       => AC_DMS_DEFAULT,
        NOTIFY_FILE    => AC_NOTIFY_DEFAULT,
        MANIFEST_FILE  => ['items' => []],
    ];
    $tx = ['deleted_media' => [], 'avatar' => (string)($userEnt['avatar'] ?? '')]; // 事务外 unlink 用
    $ok = db_transact_multi($files, function($dm) use ($email, &$tx) {
        $usersM = $dm[USERS_FILE];
        $postsM = $dm[POSTS_FILE];
        $socM   = $dm[SOCIAL_FILE];
        $dmsM   = $dm[DMS_FILE];
        $notiM  = $dm[NOTIFY_FILE];
        $manM   = $dm[MANIFEST_FILE];

        // posts：物理移除本人 items（含本人回复/引用帖）+ 回复树字段指向被删帖的他人回复级联移除
        $items = is_array($postsM['items'] ?? null) ? $postsM['items'] : [];
        [$deletedIds, $deletedMedia, $kept] = ac_split_posts($items, $email);
        // 引用一致性：剩余 items 中引用了被删帖/被删评论的，引用字段置 null（前端对无引用对象渲染正常，不留悬空）
        foreach ($kept as &$p) {
            foreach (['quoted_post_id', 'repost_of', 'quote_comment'] as $f) {
                $v = $p[$f] ?? null;
                if (is_string($v) && $v !== '' && isset($deletedIds[$v])) $p[$f] = null;
            }
        }
        unset($p);
        $postsM['items'] = array_values($kept);
        // reqs（旧客户端幂等壳 {client_req_id:postId}）：过滤指向被删帖的值，保留原键
        $reqs = is_array($postsM['reqs'] ?? null) ? $postsM['reqs'] : [];
        $postsM['reqs'] = array_filter($reqs, fn($v) => !is_string($v) || !isset($deletedIds[$v]));

        // social：本人互动全清 + post_id 指向被删帖的悬空互动全清（保持一致性）
        foreach (['likes', 'reposts', 'bookmarks', 'comment_likes', 'comment_bookmarks'] as $arrKey) {
            $socM[$arrKey] = array_values(array_filter($socM[$arrKey] ?? [], function($r) use ($email, $deletedIds) {
                if (($r['user'] ?? '') === $email) return false;
                $pid = (string)($r['post_id'] ?? '');
                if ($pid !== '' && isset($deletedIds[$pid])) return false;
                return true;
            }));
        }
        // 旧评论壳：本人评论移除；post_id 指向被删根帖的旧评论一并清理
        $socM['comments'] = array_values(array_filter($socM['comments'] ?? [], function($c) use ($email, $deletedIds) {
            if (($c['user'] ?? '') === $email) return false;
            $pid = (string)($c['post_id'] ?? '');
            if ($pid !== '' && isset($deletedIds[$pid])) return false;
            return true;
        }));

        // follows / blocks / mutes：移除本人键 + 所有其他键值数组中过滤掉本人（双向全清）
        $cleanRelMap = function($map) use ($email) {
            if (!is_array($map)) return [];
            unset($map[$email]);
            foreach ($map as $k => $arr) {
                if (is_array($arr)) {
                    $map[$k] = array_values(array_filter($arr, fn($v) => $v !== $email));
                }
            }
            return $map;
        };
        $dm[FOLLOWS_FILE] = $cleanRelMap($dm[FOLLOWS_FILE]);
        $dm[BLOCKS_FILE]   = $cleanRelMap($dm[BLOCKS_FILE]);
        $dm[MUTES_FILE]    = $cleanRelMap($dm[MUTES_FILE]);

        // dms：全局删除 email 参与的全部会话（对方侧也不可见，MVP7 契约口径）
        $convIds = [];
        foreach ($dmsM['conversation_members'] ?? [] as $m) {
            if (($m['user_id'] ?? '') === $email) {
                $cid = (string)($m['conversation_id'] ?? '');
                if ($cid !== '') $convIds[$cid] = true;
            }
        }
        $convHasTx = function($c) use ($convIds) { $c = (string)$c; return $c !== '' && isset($convIds[$c]); };
        $dmsM['conversations'] = array_values(array_filter($dmsM['conversations'] ?? [], fn($c) => !$convHasTx($c['id'] ?? '')));
        $dmsM['conversation_members'] = array_values(array_filter($dmsM['conversation_members'] ?? [], function($m) use ($email, $convHasTx) {
            if (($m['user_id'] ?? '') === $email) return false;
            return !$convHasTx($m['conversation_id'] ?? '');
        }));
        $removedMsgIds = [];
        $keptMsgs = [];
        foreach ($dmsM['messages'] ?? [] as $m) {
            if ($convHasTx($m['conversation_id'] ?? '')) {
                $mid = (string)($m['id'] ?? '');
                if ($mid !== '') $removedMsgIds[$mid] = true;
            } else {
                $keptMsgs[] = $m;
            }
        }
        $dmsM['messages'] = $keptMsgs;
        $msgHasTx = function($mid) use ($removedMsgIds) { $mid = (string)$mid; return $mid !== '' && isset($removedMsgIds[$mid]); };
        $dmsM['message_user_state'] = array_values(array_filter($dmsM['message_user_state'] ?? [], function($s) use ($email, $msgHasTx) {
            if (($s['user_id'] ?? '') === $email) return false;
            return !$msgHasTx($s['message_id'] ?? '');
        }));

        // notify：to/by 任一为本人的通知移除
        $notiM['items'] = array_values(array_filter($notiM['items'] ?? [], fn($it) => ($it['to'] ?? '') !== $email && ($it['by'] ?? '') !== $email));

        // manifest：被删帖的媒体登记移除
        if (is_array($manM['items'] ?? null)) {
            foreach (array_keys($deletedMedia) as $mid) unset($manM['items'][$mid]);
        }

        // users：移除 entry（全部 tokens 随账号移除 = 强制登出）
        unset($usersM['users'][$email]);

        $dm[USERS_FILE]    = $usersM;
        $dm[POSTS_FILE]    = $postsM;
        $dm[SOCIAL_FILE]   = $socM;
        $dm[DMS_FILE]      = $dmsM;
        $dm[NOTIFY_FILE]   = $notiM;
        $dm[MANIFEST_FILE] = $manM;

        $tx['deleted_media'] = array_keys($deletedMedia);
        return $dm;
    });
    if ($ok === false) {
        throw new RuntimeException('purge transaction failed (busy/io)');
    }

    // ---------- i~j) 事务成功后的文件系统清理（容错 unlink，与 social.php del 同模式） ----------
    // i) 打卡数据文件
    if (is_file($habitsFile)) @unlink($habitsFile);

    // 自定义头像：avatar="custom:xxx" → unlink AVATAR_DIR.xxx
    $avatar = (string)($tx['avatar'] ?? '');
    if (strncmp($avatar, 'custom:', 7) === 0) {
        $fname = basename(substr($avatar, 7));
        if ($fname !== '') @unlink(AVATAR_DIR . $fname);
    }

    // j) 被删帖媒体文件：pending / 正式区 / done 三区 glob 清理（与 social.php del action 一致）
    foreach (($tx['deleted_media'] ?? []) as $mid) {
        foreach (glob(UPLOAD_PENDING_DIR . $mid . '.*') as $f) @unlink($f);
        foreach (glob(UPLOAD_DIR . $mid . '.*') as $f) @unlink($f);
        foreach (glob(UPLOAD_DONE_DIR . $mid . '.*') as $f) @unlink($f);
    }

    return $backupName;
}
