<?php
/* 考核小管家 v3.5.5 —— 一次性迁移脚本：social.php comments[] → posts.php 回复帖（schema_v:2）
 *
 * 用法（NAS 命令行一次性执行）：
 *   php kaohe-api/migrate_v355.php            # 执行迁移（自动备份→迁移→统计）
 *   php kaohe-api/migrate_v355.php --rollback # 从最近一次备份恢复 posts.php / social.php
 *
 * 本地自测（不改线上数据）：
 *   KAOHE_DATA_DIR=/path/to/mock-kaohe-data php kaohe-api/migrate_v355.php
 *
 * 语义依据：artifacts/v355-design-spec.md §4。
 * 幂等：posts.php 任一 item schema_v==2 或已存在 c_ 前缀 id → 输出"已迁移"并退出。
 * 可回滚：执行前自动备份 posts.php / social.php 到 kaohe-data/backups/v355-迁移-<时间戳>/。
 */

require_once __DIR__ . '/common.php';

// 仅允许 CLI 执行（防误经 Web 触发）
if (php_sapi_name() !== 'cli') {
    echo "请在命令行执行：php migrate_v355.php\n";
    exit(1);
}

// 数据目录：正式走 common.php 的 DATA_DIR；本地自测可用环境变量 KAOHE_DATA_DIR 覆盖
$_envDir = getenv('KAOHE_DATA_DIR');
$dataDir = ($_envDir !== false && $_envDir !== '') ? rtrim($_envDir, '/') . '/' : DATA_DIR;

$postsFile    = $dataDir . 'posts.php';
$socialFile   = $dataDir . 'social.php';
$manifestFile = $dataDir . 'uploads/manifest.php';
$backupRoot   = $dataDir . 'backups/';

const POSTS_DEFAULT_M   = ['items' => [], 'reqs' => []];
const SOCIAL_DEFAULT_M  = ['likes' => [], 'comments' => [], 'reposts' => [], 'bookmarks' => [],
                       'comment_likes' => [], 'comment_bookmarks' => []];

function println($s) { echo $s . "\n"; }

/* ---------- 回滚模式 ---------- */
if (in_array('--rollback', $argv, true)) {
    $dirs = glob($backupRoot . 'v355-迁移-*') ?: [];
    if (!$dirs) {
        println("[回滚失败] 未找到备份目录：{$backupRoot}v355-迁移-*");
        exit(1);
    }
    sort($dirs);            // 时间戳字典序 = 时间序
    $latest = end($dirs);
    println("[回滚] 从最近备份恢复：{$latest}");
    foreach (['posts.php', 'social.php'] as $fn) {
        $src = $latest . '/' . $fn;
        if (!is_file($src)) { println("[回滚失败] 备份缺文件：{$src}"); exit(1); }
        if (!copy($src, $dataDir . $fn)) { println("[回滚失败] 恢复写入失败：{$fn}"); exit(1); }
        println("[回滚] 已恢复 {$fn}");
    }
    println("[回滚] 完成。");
    exit(0);
}

/* ---------- 前置检查 ---------- */
if (!is_file($postsFile))  { println("[中止] 找不到 posts.php：{$postsFile}"); exit(1); }
if (!is_file($socialFile)) { println("[中止] 找不到 social.php：{$socialFile}"); exit(1); }

/* ---------- 幂等检查 ---------- */
$posts = db_read_json($postsFile, POSTS_DEFAULT_M);
foreach (($posts['items'] ?? []) as $p) {
    if ((int)($p['schema_v'] ?? 1) === 2 || strncmp($p['id'] ?? '', 'c_', 2) === 0) {
        println("已迁移：posts.php 中已存在 schema_v:2 / c_ 前缀帖子，跳过。");
        exit(0);
    }
}

/* ---------- 1. 备份 ---------- */
$ts      = date('Ymd-His');
$bakDir  = $backupRoot . "v355-迁移-{$ts}/";
if (!is_dir($bakDir) && !@mkdir($bakDir, 0775, true)) {
    println("[中止] 备份目录创建失败：{$bakDir}"); exit(1);
}
foreach (['posts.php', 'social.php'] as $fn) {
    if (!copy($dataDir . $fn, $bakDir . $fn)) {
        println("[中止] 备份失败：{$fn}"); exit(1);
    }
}
println("[备份] posts.php / social.php → {$bakDir}");

/* ---------- 2. 读取源数据 ---------- */
$social   = db_read_json($socialFile, SOCIAL_DEFAULT_M);
$comments = $social['comments'] ?? [];
$manifest = db_read_json($manifestFile, ['items' => []]);

// 旧评论 id → 记录索引（用于回复归并到根评论）
$byId = [];
foreach ($comments as $c) { $byId[$c['id']] = $c; }

// 每条评论的根评论 old id（v3.5.4 起 reply_to 已归并指向根；此处再上溯兜底历史 2 层数据）
$rootOf = [];
foreach ($comments as $c) {
    $rt = $c['reply_to'] ?? null;
    if (empty($rt)) { $rootOf[$c['id']] = $c['id']; continue; }
    $cur = $rt; $seen = [];
    while (isset($byId[$cur]) && !empty($byId[$cur]['reply_to']) && !isset($seen[$cur])) {
        $seen[$cur] = true;
        $cur = $byId[$cur]['reply_to'];
    }
    $rootOf[$c['id']] = $cur;
}

/* ---------- 3. 构建迁移帖 ---------- */
$newItems   = [];
$rootCount  = 0; $replyCount = 0; $deletedCount = 0; $mediaCmts = 0;
foreach ($comments as $c) {
    $oldId  = $c['id'];
    $postId = $c['post_id'];                 // 根帖 id（conversation 根）
    $isRoot = empty($c['reply_to'] ?? null);

    // type 按 media_ids[0] 在 manifest 中的 kind 推导（与 social.php 发帖逻辑一致）
    $mids = is_array($c['media_ids'] ?? null) ? array_values($c['media_ids']) : [];
    $type = 'text';
    if (!empty($mids)) {
        $mediaCmts++;
        $kind = $manifest['items'][$mids[0]]['kind'] ?? 'image';
        $type = in_array($kind, ['image', 'video', 'audio'], true) ? $kind : 'image';
    }

    $item = [
        'id'               => 'c_' . $oldId,
        'author'           => $c['user'] ?? '',
        'type'             => $type,
        'text'             => $c['text'] ?? '',
        'media_ids'        => $mids,
        'share_card'       => null,
        'mentions'         => is_array($c['mentions'] ?? null) ? array_values($c['mentions']) : [],
        'reply_policy'     => 'all',
        'reply_to_post_id' => $isRoot ? $postId : ('c_' . $rootOf[$oldId]),
        'conversation_id'  => $postId,
        'quoted_post_id'   => null,
        'hidden'           => false,
        'repost_of'        => null,
        'quote_comment'    => null,
        'createdAt'        => $c['at'] ?? '',
        'status'           => (($c['status'] ?? 'normal') === 'deleted') ? 'deleted' : 'normal',
        'schema_v'         => 2,
    ];
    if ($item['status'] === 'deleted') $deletedCount++;
    $isRoot ? $rootCount++ : $replyCount++;
    $newItems[] = $item;
}

/* ---------- 4. 落盘：先 posts.php，再清空 social.php comments[] ---------- */
$ok = db_transact_json($postsFile, POSTS_DEFAULT_M, function($p) use ($newItems) {
    if (!isset($p['items']) || !is_array($p['items'])) $p['items'] = [];
    if (!isset($p['reqs']) || !is_array($p['reqs']))   $p['reqs'] = [];
    foreach ($newItems as $ni) $p['items'][] = $ni;
    return $p;
});
if ($ok === false) { println("[中止] posts.php 写入失败（备份已保留，可 --rollback）"); exit(1); }

$ok = db_transact_json($socialFile, SOCIAL_DEFAULT_M, function($s) {
    $s['comments'] = [];   // 清空数据保留键；likes/reposts/bookmarks/comment_likes/comment_bookmarks 不动
    return $s;
});
if ($ok === false) { println("[中止] posts.php 已迁移但 social.php 清空失败，请手动处理或 --rollback"); exit(1); }

/* ---------- 5. 自检 + 统计 ---------- */
$after = db_read_json($postsFile, POSTS_DEFAULT_M);
$moved = 0;
foreach (($after['items'] ?? []) as $p) if (strncmp($p['id'] ?? '', 'c_', 2) === 0) $moved++;

println("[完成] 迁移统计：");
println("  旧评论总数    : " . count($comments));
println("  根评论        : {$rootCount}");
println("  回复评论      : {$replyCount}");
println("  软删评论      : {$deletedCount}");
println("  含媒体评论    : {$mediaCmts}（media_ids 非空）");
println("  新 c_ 帖数    : {$moved}（自检：与旧评论总数" . (count($comments) === $moved ? "一致 ✓" : "不一致 ✗") . "）");
println("  social.php comments[] 已清空；comment_likes/comment_bookmarks/likes/bookmarks/reposts 未动。");
println("  回滚命令：php " . basename(__FILE__) . " --rollback");
