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

// MIME 白名单：mime => [kind, ext]（v3.5.3 扩展：HEIC/HEIF/BMP/AVIF 图片、M4V/MKV/AVI 视频、OGG/OPUS/FLAC/AAC 音频）
const MIME_WHITELIST = [
    'image/jpeg' => ['image', 'jpg'],
    'image/png'  => ['image', 'png'],
    'image/webp' => ['image', 'webp'],
    'image/gif'  => ['image', 'gif'],
    'image/heic' => ['image', 'heic'],        // iPhone 相册 HEIC（Chrome 不直接支持，工人 orig 一律转 jpg）
    'image/heif' => ['image', 'heic'],        // finfo 对 HEIF 可能返回 image/heif
    'image/bmp'  => ['image', 'bmp'],
    'image/x-ms-bmp' => ['image', 'bmp'],     // 实测 finfo 对 BMP 返回 x-ms-bmp（getimagesize 兜底保留作双保险）
    'image/avif' => ['image', 'avif'],
    'video/mp4'  => ['video', 'mp4'],
    'video/webm' => ['video', 'webm'],
    'video/quicktime' => ['video', 'mov'],   // iPhone/Mac 默认 .mov（HEVC/H.264），工人压缩时统一转 mp4
    'video/x-m4v' => ['video', 'm4v'],
    'video/x-matroska' => ['video', 'mkv'],
    'video/x-msvideo' => ['video', 'avi'],
    'audio/mpeg' => ['audio', 'mp3'],
    'audio/mp4'  => ['audio', 'm4a'],
    'audio/x-m4a'=> ['audio', 'm4a'],
    'audio/wav'  => ['audio', 'wav'],
    'audio/x-wav'=> ['audio', 'wav'],
    'audio/ogg'  => ['audio', 'ogg'],
    'audio/opus' => ['audio', 'opus'],
    'audio/flac' => ['audio', 'flac'],
    'audio/x-flac'=> ['audio', 'flac'],      // 部分环境 finfo 对 FLAC 返回 x-flac 别名
    'audio/aac'  => ['audio', 'aac'],
    'audio/x-hx-aac-adts' => ['audio', 'aac'], // 实测 ADTS 裸 AAC（iPhone 导出）finfo 返回该 MIME
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

    // 客户端扩展名双校验（允许 jpg/jpeg、mov/qt、heic/heif 等同义）
    $origExt = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    $extAlias = ['jpg'=>['jpg','jpeg'], 'mp3'=>['mp3'], 'wav'=>['wav'], 'm4a'=>['m4a','mp4a'],
                 'png'=>['png'], 'webp'=>['webp'], 'gif'=>['gif'], 'mp4'=>['mp4'], 'webm'=>['webm'],
                 'mov'=>['mov','qt'], 'heic'=>['heic','heif'], 'bmp'=>['bmp'], 'avif'=>['avif'],
                 'm4v'=>['m4v'], 'mkv'=>['mkv'], 'avi'=>['avi'],
                 'ogg'=>['ogg','opus'], 'opus'=>['opus'], 'flac'=>['flac'], 'aac'=>['aac']];
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
    if (!in_array($v, ['orig','sm','md','video720','poster'], true)) $v = 'orig';
    // v3.5.4：下载文件名参数（Content-Disposition inline，保住 iOS 长按保存能力）
    $fn = isset($_GET['fn']) ? basename((string)$_GET['fn']) : '';
    if (strlen($fn) > 128) $fn = '';

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
    $allowExt = ['jpg','jpeg','png','webp','gif','heic','bmp','avif','mp4','webm','mov','m4v','mkv','avi','mp3','m4a','wav','ogg','opus','flac','aac'];
    if (!in_array($ext, $allowExt, true)) json_out(['ok' => false, 'err' => 'type', 'msg' => '类型非法'], 415);

    if (!headers_sent()) {
        // video720 一律按 mp4 输出（工人压缩产物统一 mp4 容器，避免 quicktime Content-Type 干扰播放）
        $ct = ($v === 'video720') ? 'video/mp4' : ($rec['mime'] ?? 'application/octet-stream');
        // v3.5.3：HEIC 图片 orig 已被工人转 jpg、部分容器/格式经处理，Content-Type 跟随文件实际类型（finfo），
        //           否则 nosniff 下 Chrome 会因 image/heic 头拒绝显示 jpg 数据
        if ($v !== 'video720' && function_exists('finfo_open')) {
            $fi2 = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi2) { $m2 = finfo_file($fi2, $real); finfo_close($fi2); if ($m2) $ct = $m2; }
        }
        header('Content-Type: ' . $ct);
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        // v3.5.4：下载文件名（inline 而非 attachment，保住 iOS 长按保存能力）
        if ($fn !== '') {
            header("Content-Disposition: inline; filename=\"{$fn}\"; filename*=UTF-8''" . rawurlencode($fn));
        }
    }
    @readfile($real);
    exit;
}

json_out(['ok' => false, 'err' => 'action', 'msg' => '未知操作']);
