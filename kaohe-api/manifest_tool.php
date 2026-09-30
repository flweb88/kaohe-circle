<?php
/* ============================================================
 * manifest_tool.php — 考核小管家 MVP-5 NAS 侧 CLI 工具
 *
 * 职责：接收 Mac 压缩工人回传到 uploads/_incoming/{id}/ 的产物，
 *   1) 校验产物扩展名白名单 + finfo 真实 MIME；
 *   2) 校验通过 → 移入 uploads/ 正式区，manifest.php 记录 status:"ready"；
 *   3) 校验不通过 → 不落正式区，manifest.php 标 failed + failReason；
 *   4) 将 _pending/{pending-file} 移入 done/；
 *   5) --sweep 孤儿清理：_pending 超 7 天标 failed 并删、done/ 超 30 天删。
 *
 * 安全：
 *   - 仅 CLI 调用（Mac 工人经 ssh php82 执行）；非 web 请求一律 403。
 *   - 不入 deploy.sh 白名单，不暴露给 web；即使误放到 web 根也不可执行（CLI 守卫）。
 *   - id 限 hex；所有文件名 basename 防路径穿越。
 *
 * 写约定：沿用 common.php 的 GUARD 守卫头 + db_read_json/db_write_json（tmp+rename 原子写）；
 *   临界区自加 flock(LOCK_EX|LOCK_NB) 10ms 退避约 2s（对应方案 §6 db_transact_json 语义）。
 *
 * 用法：
 *   php82 manifest_tool.php --id <hex> --kind image|video|audio --uploads <dir> \
 *        --incoming <dir> --pending-file <fname> \
 *        [--orig f] [--sm f] [--md f] [--video720 f]
 *   php82 manifest_tool.php --id <hex> --kind k --uploads <dir> --pending-file <f> --fail <reason>
 *   php82 manifest_tool.php --uploads <dir> --sweep
 * ============================================================ */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('forbidden'); }

require __DIR__ . '/common.php';

// ---------------- 参数 ----------------
$opts = getopt('', [
    'id:', 'kind:', 'uploads:', 'incoming:', 'pending-file:',
    'orig:', 'sm:', 'md:', 'video720:', 'fail::', 'sweep', 'discard-pending',
]);

$uploads = rtrim($opts['uploads'] ?? (DATA_DIR . 'uploads/'), '/');
$pendingDir  = "$uploads/_pending";
$doneDir     = "$uploads/done";
$incomingDirBase = "$uploads/_incoming";
$manifestFile = "$uploads/manifest.php";

// id 安全：仅 hex
$id = isset($opts['id']) ? trim($opts['id']) : '';
if ($id !== '' && !preg_match('/^[a-f0-9]{8,64}$/', $id)) {
    fwrite(STDERR, "bad id\n"); exit(2);
}

// 角色校验规则：返回 [允许扩展名数组, 允许的 MIME 前缀数组]
function role_rules(string $role, string $kind): array {
    switch ($role) {
        case 'orig':
            if ($kind === 'image') return [['jpg','jpeg','png','webp','heic'], ['image/']];
            if ($kind === 'video') return [['mp4','mov','m4v'], ['video/']];
            if ($kind === 'audio') return [['mp3','m4a','aac','ogg'], ['audio/']];
            return [[], []];
        case 'sm':
        case 'md':
            return [['jpg'], ['image/jpeg']];
        case 'video720':
            return [['mp4'], ['video/mp4']];
    }
    return [[], []];
}

// 临界区事务写：锁文件 + flock 轮询 + 读改写（对齐 §6 db_transact_json 语义）
function mt_transact(string $path, callable $mutator): bool {
    @mkdir(dirname($path), 0775, true);
    $lock = $path . '.lock';
    $fp = @fopen($lock, 'c');
    if (!$fp) return false;
    $got = false;
    for ($i = 0; $i < 200; $i++) {           // 10ms * 200 ≈ 2s 超时
        if (flock($fp, LOCK_EX | LOCK_NB)) { $got = true; break; }
        usleep(10000);
    }
    if (!$got) { fclose($fp); return false; }
    try {
        $data = db_read_json($path, ['items' => []]);
        if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
        $data = $mutator($data);
        $ok = db_write_json($path, $data);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $ok;
}

// 扩展名 + finfo MIME 双校验
function mt_product_ok(string $file, array $allowedExt, array $mimePrefixes): bool {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return false;
    if (!function_exists('finfo_open')) return false;
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if (!$fi) return false;
    $mime = (string) finfo_file($fi, $file);
    finfo_close($fi);
    foreach ($mimePrefixes as $p) {
        if ($p !== '' && strncmp($mime, $p, strlen($p)) === 0) return true;
    }
    return false;
}

// ---------------- --sweep 孤儿清理 ----------------
if (isset($opts['sweep'])) {   // getopt 无参 flag 返回 false，必须用 isset 判定
    $week = 7 * 86400; $month = 30 * 86400; $now = time();
    $pendingCount = 0;
    foreach ((glob("$pendingDir/*") ?: []) as $p) {
        if (!is_file($p)) continue;
        $pendingCount++;
        if (($now - filemtime($p)) > $week) {
            $i = preg_replace('/\.[^.]+$/', '', basename($p));
            mt_transact($manifestFile, function ($d) use ($i) {
                if (isset($d['items'][$i]) && is_array($d['items'][$i])) {
                    $d['items'][$i]['status'] = 'failed';
                    $d['items'][$i]['failReason'] = 'orphan_pending_timeout';
                }
                return $d;
            });
            @unlink($p);
        }
    }
    foreach ((glob("$doneDir/*") ?: []) as $p) {
        if (is_file($p) && ($now - filemtime($p)) > $month) @unlink($p);
    }
    echo json_encode(['ok' => true, 'pendingSeen' => $pendingCount]) . "\n";
    exit(0);
}

$kind = isset($opts['kind']) ? $opts['kind'] : '';
$pendingFile = isset($opts['pending-file']) ? basename($opts['pending-file']) : '';
$srcPending = "$pendingDir/$pendingFile";

// ---------------- --fail 模式 ----------------
if (isset($opts['fail'])) {
    $reason = substr((string)$opts['fail'], 0, 200);
    mt_transact($manifestFile, function ($d) use ($id, $reason) {
        if (!isset($d['items'][$id]) || !is_array($d['items'][$id])) {
            $d['items'][$id] = ['id' => $id, 'status' => 'failed'];
        }
        $d['items'][$id]['status'] = 'failed';
        $d['items'][$id]['failReason'] = $reason;
        return $d;
    });
    if (is_file($srcPending)) {
        @mkdir($doneDir, 0775, true);
        @rename($srcPending, "$doneDir/$pendingFile");
    }
    echo json_encode(['ok' => true, 'failed' => $id, 'reason' => $reason]) . "\n";
    exit(0);
}

// ---------------- complete 模式 ----------------
$incoming = isset($opts['incoming']) ? rtrim($opts['incoming'], '/') : '';
if ($id === '' || $kind === '' || $incoming === '' || $pendingFile === '') {
    fwrite(STDERR, "missing args\n"); exit(2);
}

$roles = [
    'orig'      => $opts['orig'] ?? '',
    'sm'        => $opts['sm'] ?? '',
    'md'        => $opts['md'] ?? '',
    'video720'  => $opts['video720'] ?? '',
];

$products = [];
$bad = null;
foreach ($roles as $role => $fname) {
    if ($fname === '') continue;
    $fname = basename($fname);
    $local = "$incoming/$fname";
    if (!is_file($local)) { $bad = "missing:$role"; break; }
    list($extOk, $mimeOk) = role_rules($role, $kind);
    if (!mt_product_ok($local, $extOk, $mimeOk)) { $bad = "badmime:$role:$fname"; break; }
    $products[$role] = $fname;
}

// 校验不过：不落正式区、标 failed、清 incoming
if ($bad !== null) {
    mt_transact($manifestFile, function ($d) use ($id, $bad) {
        if (!isset($d['items'][$id]) || !is_array($d['items'][$id])) {
            $d['items'][$id] = ['id' => $id, 'status' => 'failed'];
        }
        $d['items'][$id]['status'] = 'failed';
        $d['items'][$id]['failReason'] = 'product_' . $bad;
        return $d;
    });
    foreach ((glob("$incoming/*") ?: []) as $x) { if (is_file($x)) @unlink($x); }
    @rmdir($incoming);
    if (is_file($srcPending)) {
        @mkdir($doneDir, 0775, true);
        @rename($srcPending, "$doneDir/$pendingFile");
    }
    echo json_encode(['ok' => false, 'err' => 'bad_product', 'detail' => $bad]) . "\n";
    exit(1);
}

// 落位：incoming → uploads/ 正式区
$filesMap = [];
$moveErr = null;
foreach ($products as $role => $fname) {
    $dest = "$uploads/$fname";
    if (!@rename("$incoming/$fname", $dest)) {
        if (!@copy("$incoming/$fname", $dest)) { $moveErr = "movefail:$fname"; break; }
        @unlink("$incoming/$fname");
    }
    if ($role === 'orig')        $filesMap['orig'] = $fname;
    elseif ($role === 'sm')      $filesMap['sm'] = $fname;
    elseif ($role === 'md')      $filesMap['md'] = $fname;
    elseif ($role === 'video720') $filesMap['video720'] = $fname;
}
if ($moveErr !== null) {
    echo json_encode(['ok' => false, 'err' => $moveErr]) . "\n";
    exit(1);
}

// manifest → ready（保留 uploader/kind/mime/size/createdAt 等既有字段）
$ok = mt_transact($manifestFile, function ($d) use ($id, $filesMap) {
    if (!isset($d['items'][$id]) || !is_array($d['items'][$id])) {
        $d['items'][$id] = ['id' => $id];
    }
    $d['items'][$id]['status'] = 'ready';
    $d['items'][$id]['readyAt'] = now();
    $d['items'][$id]['files'] = $filesMap;
    return $d;
});
if (!$ok) {
    echo json_encode(['ok' => false, 'err' => 'busy']) . "\n";
    exit(1);
}

// pending 处置：压缩视频（--discard-pending）直接删除原片；其余移入 done/ 存档
@mkdir($doneDir, 0775, true);
if (is_file($srcPending)) {
    if (!empty($opts['discard-pending'])) {
        @unlink($srcPending);            // 原视频不保留，省 NAS 磁盘
    } else {
        @rename($srcPending, "$doneDir/$pendingFile");
    }
}
@rmdir($incoming);

echo json_encode(['ok' => true, 'ready' => $id, 'files' => $filesMap]) . "\n";
exit(0);
