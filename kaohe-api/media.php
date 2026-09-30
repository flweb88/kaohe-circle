<?php
/* 考核小管家 v3.5.0 - 媒体上传/回放（MVP-5）
 * action=upload  multipart: token, file（单文件；图片≤10MB / 音频≤50MB / 视频≤200MB）
 * action=raw     query: f=<mediaId>&v=orig|thumbSm|thumbMd（仅 ready 可回放）
 *
 * 安全：finfo 真实 MIME + 客户端扩展名双白名单；随机 16hex 文件名；
 *       写入 uploads/_pending/ 并记 manifest(status:pending)；
 *       raw 回放二次白名单 + basename/realpath 防穿越；目录 .htaccess 禁脚本执行。
 */
require_once __DIR__ . '/common.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// MIME 白名单：mime => [kind, ext]
const MIME_WHITELIST = [
    'image/jpeg' => ['image', 'jpg'],
    'image/png'  => ['image', 'png'],
    'image/webp' => ['image', 'webp'],
    'image/gif'  => ['image', 'gif'],
    'video/mp4'  => ['video', 'mp4'],
    'video/webm' => ['video', 'webm'],
    'audio/mpeg' => ['audio', 'mp3'],
    'audio/mp4'  => ['audio', 'm4a'],
    'audio/x-m4a'=> ['audio', 'm4a'],
    'audio/wav'  => ['audio', 'wav'],
    'audio/x-wav'=> ['audio', 'wav'],
];
const KIND_MAX_BYTES = ['image' => 10485760, 'audio' => 52428800, 'video' => 209715200];

// ---------- upload ----------
if ($action === 'upload') {
    $token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
    $u = require_login($token);
    $meEmail = strtolower($u['email']);

    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'err' => 'upload', 'msg' => '上传失败或已中断，请重试']);
    }
    $f = $_FILES['file'];
    if (!is_uploaded_file($f['tmp_name'])) {
        json_out(['ok' => false, 'err' => 'upload', 'msg' => '非法上传']);
    }

    // 先按全局最大(200MB)拦一道，避免大文件继续
    if ((int)$f['size'] > KIND_MAX_BYTES['video']) {
        json_out(['ok' => false, 'err' => 'size', 'msg' => '文件过大']);
    }

    // finfo 真实 MIME
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $realMime = $finfo ? finfo_file($finfo, $f['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    // 兜底：图片用 getimagesize 复核
    if (!$realMime || !isset(MIME_WHITELIST[$realMime])) {
        $info = @getimagesize($f['tmp_name']);
        if ($info && isset($info['mime']) && isset(MIME_WHITELIST[$info['mime']])) $realMime = $info['mime'];
    }
    if (!isset(MIME_WHITELIST[$realMime])) {
        json_out(['ok' => false, 'err' => 'type', 'msg' => '不支持的文件类型']);
    }
    list($kind, $ext) = MIME_WHITELIST[$realMime];

    // 客户端扩展名双校验（允许 jpg/jpeg 等同义）
    $origExt = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    $extAlias = ['jpg'=>['jpg','jpeg'], 'mp3'=>['mp3'], 'wav'=>['wav'], 'm4a'=>['m4a','mp4a'],
                 'png'=>['png'], 'webp'=>['webp'], 'gif'=>['gif'], 'mp4'=>['mp4'], 'webm'=>['webm']];
    $allow = $extAlias[$ext] ?? [$ext];
    if (!in_array($origExt, $allow, true)) {
        json_out(['ok' => false, 'err' => 'type', 'msg' => '文件扩展名与内容不符']);
    }

    // 按类型大小上限
    if ((int)$f['size'] > KIND_MAX_BYTES[$kind]) {
        $lim = $kind === 'image' ? '图片 ≤10MB' : ($kind === 'audio' ? '音频 ≤50MB' : '视频 ≤200MB');
        json_out(['ok' => false, 'err' => 'size', 'msg' => $lim]);
    }

    // 随机文件名，绝不使用客户端名
    $id = bin2hex(random_bytes(16));
    if (!is_dir(UPLOAD_PENDING_DIR)) @mkdir(UPLOAD_PENDING_DIR, 0775, true);
    $dest = UPLOAD_PENDING_DIR . $id . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        json_out(['ok' => false, 'err' => 'upload', 'msg' => '保存失败，请重试']);
    }
    @chmod($dest, 0664);

    $ok = db_transact_json(DATA_DIR . 'uploads/manifest.php', ['items' => []], function($m) use ($id, $meEmail, $kind, $realMime, $ext, $dest) {
        if (!isset($m['items']) || !is_array($m['items'])) $m['items'] = [];
        $m['items'][$id] = [
            'id' => $id, 'uploader' => $meEmail, 'kind' => $kind, 'mime' => $realMime,
            'size' => (int)filesize($dest), 'status' => 'pending',
            'createdAt' => now(), 'files' => ['orig' => basename($dest)],
        ];
        return $m;
    });
    if ($ok === false) { @unlink($dest); json_out(['ok' => false, 'err' => 'io', 'msg' => '登记失败']); }

    json_out(['ok' => true, 'media' => ['id' => $id, 'kind' => $kind, 'mime' => $realMime,
              'size' => (int)filesize($dest), 'status' => 'pending']]);
}

// ---------- raw（回放） ----------
if ($action === 'raw') {
    $token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
    // 也允许 query 带 token（<img>/<video> 标签无法发 body）
    if ($token === '' && isset($_GET['token'])) $token = trim((string)$_GET['token']);
    require_login($token);

    $id = isset($_GET['f']) ? basename((string)$_GET['f']) : '';
    $v = isset($_GET['v']) ? (string)$_GET['v'] : 'orig';
    if (!in_array($v, ['orig','sm','md','video720'], true)) $v = 'orig';

    $m = db_read_json(DATA_DIR . 'uploads/manifest.php', ['items' => []]);
    $rec = $m['items'][$id] ?? null;
    if (!$rec) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '媒体不存在'], 404);
    if (($rec['status'] ?? 'pending') !== 'ready') {
        json_out(['ok' => false, 'err' => 'media_pending', 'msg' => '媒体处理中'], 404);
    }
    $fname = $rec['files'][$v] ?? ($rec['files']['orig'] ?? null);
    if (!$fname) json_out(['ok' => false, 'err' => 'notfound', 'msg' => '版本不存在'], 404);

    // 防穿越：真实路径必须落在 UPLOAD_DIR 内
    $real = realpath(UPLOAD_DIR . basename($fname));
    $root = realpath(UPLOAD_DIR);
    if (!$real || !$root || strpos($real, $root) !== 0 || !is_file($real)) {
        json_out(['ok' => false, 'err' => 'notfound', 'msg' => '文件不存在'], 404);
    }
    // 二次类型校验：扩展名必须在白名单派生集合内
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $allowExt = ['jpg','jpeg','png','webp','gif','mp4','webm','mp3','m4a','wav'];
    if (!in_array($ext, $allowExt, true)) json_out(['ok' => false, 'err' => 'type', 'msg' => '类型非法'], 415);

    if (!headers_sent()) {
        header('Content-Type: ' . ($rec['mime'] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
    }
    @readfile($real);
    exit;
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
