<?php
/* 考核小管家 v2 - 自定义头像上传（multipart/form-data）
 * fields: token, file
 */
require_once __DIR__ . '/common.php';

$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
$u = require_login($token);

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['ok' => false, 'err' => 'upload', 'msg' => '请选择图片上传']);
}
$f = $_FILES['file'];
if ($f['size'] > 2097152) {
    json_out(['ok' => false, 'err' => 'size', 'msg' => '图片大小须 ≤2MB']);
}

$info = @getimagesize($f['tmp_name']);
if ($info === false) {
    json_out(['ok' => false, 'err' => 'type', 'msg' => '必须上传真实图片']);
}
$mime = $info['mime'];
$extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($extMap[$mime])) {
    json_out(['ok' => false, 'err' => 'type', 'msg' => '仅支持 jpg/png/webp']);
}
$ext = $extMap[$mime];

// 扩展名同步校验
$origName = isset($f['name']) ? $f['name'] : '';
$origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($origExt, ['jpg','jpeg','png','webp'], true)) {
    json_out(['ok' => false, 'err' => 'type', 'msg' => '文件扩展名不合法']);
}

if (!is_dir(AVATAR_DIR)) {
    @mkdir(AVATAR_DIR, 0775, true);
}
$name = bin2hex(random_bytes(8)) . '.' . $ext; // 16 hex
$dest = AVATAR_DIR . $name;
if (!@move_uploaded_file($f['tmp_name'], $dest)) {
    json_out(['ok' => false, 'err' => 'move', 'msg' => '保存失败，请稍后重试']);
}
@chmod($dest, 0664);

json_out(['ok' => true, 'avatar' => 'custom:' . $name]);
