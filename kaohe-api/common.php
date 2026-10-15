<?php
/* 考核小管家 v2 - 公共库
 * 提供：统一输出 / JSON 输入 / 守卫格式读写 / 配置 / 会话 / 验证码 / 邮件校验
 * 纯 PHP 8.1 标准库，无 Composer。
 */

// ---------- 路径 ----------
define('DATA_DIR', __DIR__ . '/../kaohe-data/');
define('USERS_FILE',   DATA_DIR . 'users.php');
define('CAPTCHA_FILE', DATA_DIR . 'captchas.php');
define('CONFIG_FILE',  DATA_DIR . 'config.php');
define('OLD_DATA_FILE', DATA_DIR . 'kaohe-data.json');   // 旧全家数据（迁移时读取，纯 JSON）
define('BACKUP_DIR',   DATA_DIR . 'backups/');
define('AVATAR_DIR',   DATA_DIR . 'avatars/');
define('UPLOAD_DIR',   DATA_DIR . 'uploads/');   // 帖子媒体：_pending/ 待处理 + done/ + 正式区
define('UPLOAD_PENDING_DIR', UPLOAD_DIR . '_pending/');
define('UPLOAD_DONE_DIR',    UPLOAD_DIR . 'done/');

// v3.6.0 用户关系网：拉黑 / 静音（与 follows.php 同形态：{<操作者小写邮箱>:[<目标邮箱>...]}）
define('BLOCKS_FILE', DATA_DIR . 'blocks.php');
define('MUTES_FILE',  DATA_DIR . 'mutes.php');

// 守卫前缀（§7）：.php 后缀 + exit 守卫，防 nginx 直接吐数据
define('GUARD', "<?php http_response_code(403); exit; ?>\n");

// 12 生肖内置 avatar 取值
const ZODIAC = ['rat','ox','tiger','rabbit','dragon','snake','horse','goat','monkey','rooster','dog','pig'];

// ---------- 统一输出 ----------
function json_out($arr, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function now() {
    return date('Y-m-d H:i:s');
}

// ---------- 读取 JSON body ----------
function req_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// ---------- 守卫格式读写（§7） ----------
function db_read_json($path, $default) {
    if (!is_file($path)) return $default;
    $c = @file_get_contents($path);
    if ($c === false || $c === '') return $default;
    // 若带守卫前缀，去掉前缀后再解析 JSON
    if (strncmp($c, '<?php', 5) === 0) {
        $pos = strpos($c, '?>');
        if ($pos !== false) {
            $c = substr($c, $pos + 2);
        }
    }
    $d = json_decode($c, true);
    return is_array($d) ? $d : $default;
}

function db_write_json($path, $arr) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $payload = GUARD . json_encode($arr, JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) return false;
    @chmod($tmp, 0664);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/*
 * 临界区事务：持锁 → 读 → 改 → 原子写 → 放锁。
 * 解决并发 read-modify-write 丢失更新（点赞/发帖/评论并发丢更新）。
 *  - $lockPath = {target}.lock，flock(LOCK_EX|LOCK_NB) 非阻塞抢锁，10ms 退避，约 2s(200次)超时返回 false
 *  - $mutator(array $data): 返回新的整份数据；返回 null 表示无改动、不落盘
 *  - true=成功(或无改动)；false=拿不到锁/写失败（调用方回 err:"busy"/"io"）
 * 注意：所有社交写动作必须走本函数，禁止「先读改完、再单独 db_write_json」。
 */
function db_transact_json($path, $default, callable $mutator) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lockPath = $path . '.lock';
    $lf = @fopen($lockPath, 'c+');
    if (!$lf) return false;
    $attempts = 0;
    $wouldBlock = 0;
    while (!flock($lf, LOCK_EX | LOCK_NB, $wouldBlock)) {
        if (++$attempts >= 200) {   // ~2s 超时
            fclose($lf);
            return false;
        }
        usleep(10000);              // 10ms 退避
    }
    try {
        $data = db_read_json($path, $default);
        if (!is_array($data)) $data = $default;
        $new = $mutator($data);
        if ($new === null) return true;   // 无改动
        return db_write_json($path, $new);
    } finally {
        flock($lf, LOCK_UN);
        fclose($lf);
    }
}

/*
 * 多文件临界区事务（v3.6.0）：与 db_transact_json 同风格，但跨多个数据文件原子。
 * 用途：Block 写 blocks 边 + 删 follows 互关边必须同一原子事务（硬约束，不得拆成两次 db_transact_json）。
 *  - $files = [path => default, ...]
 *  - 按路径字符串升序逐个 flock(LOCK_EX|LOCK_NB)，10ms 退避，约 2s(200次) 超时返回 false；
 *    统一加锁顺序避免多文件交叉死锁；任一文件拿不到锁 → 释放已持锁并返回 false。
 *  - 全部拿到锁后读取全部数据进 $dataMap[path=>data] → 调 $mutator($dataMap)：
 *      返回新的 $dataMap（只返回被改动的键即可）；返回 null 表示无改动、不落盘。
 *  - 全部 db_write_json 落盘；finally 全部放锁。
 *  - true=成功(或无改动)；false=拿不到锁/写失败（调用方回 err:"busy"/"io"）。
 */
function db_transact_multi(array $files, callable $mutator) {
    $paths = array_keys($files);
    sort($paths, SORT_STRING);   // 统一加锁顺序，防多文件交叉死锁

    $locked = [];   // path => lock handle
    foreach ($paths as $p) {
        $dir = dirname($p);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lf = @fopen($p . '.lock', 'c+');
        if (!$lf) {
            foreach ($locked as $_lf) { flock($_lf, LOCK_UN); fclose($_lf); }
            return false;
        }
        $attempts = 0;
        $wouldBlock = 0;
        while (!flock($lf, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (++$attempts >= 200) {   // ~2s 超时
                fclose($lf);
                foreach ($locked as $_lf) { flock($_lf, LOCK_UN); fclose($_lf); }
                return false;
            }
            usleep(10000);              // 10ms 退避
        }
        $locked[$p] = $lf;
    }

    try {
        $dataMap = [];
        foreach ($paths as $p) {
            $d = db_read_json($p, $files[$p]);
            $dataMap[$p] = is_array($d) ? $d : $files[$p];
        }
        $newMap = $mutator($dataMap);
        if ($newMap === null) return true;   // 无改动
        $ok = true;
        foreach ($paths as $p) {
            if (array_key_exists($p, $newMap)) {
                if (!db_write_json($p, $newMap[$p])) $ok = false;
            }
        }
        return $ok;
    } finally {
        foreach ($locked as $_lf) {
            flock($_lf, LOCK_UN);
            fclose($_lf);
        }
    }
}

// ---------- 用户关系网：拉黑 / 静音（v3.6.0） ----------
// 数据形态与 follows.php 一致：{<操作者小写邮箱>:[<目标邮箱>...]}
// 关系辅助读函数（可重复调用，幂等去重）。

// 该用户拉黑的所有人（幂等去重后）
function block_list($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return [];
    $all = db_read_json(BLOCKS_FILE, []);
    $list = $all[$email] ?? [];
    return is_array($list) ? array_values(array_unique($list)) : [];
}

// 该用户静音的所有人（幂等去重后）
function mute_list($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return [];
    $all = db_read_json(MUTES_FILE, []);
    $list = $all[$email] ?? [];
    return is_array($list) ? array_values(array_unique($list)) : [];
}

// me 是否拉黑了 other
function has_blocked($me, $other) {
    return in_array(strtolower(trim((string)$other)), block_list($me), true);
}

// me 是否被 other 拉黑（即 other 拉黑了 me）
function blocked_by($me, $other) {
    return in_array(strtolower(trim((string)$me)), block_list($other), true);
}

// me 是否静音了 other
function is_muted($me, $other) {
    return in_array(strtolower(trim((string)$other)), mute_list($me), true);
}

/*
 * 互动准入判断（对齐 X 规格 §51，供 MVP-5/6/7 复用）。
 * 判断顺序（短-circuit，命中即 false）：
 *   ① Account status：$target 不存在于 users 或 banned → false
 *   ② Block：has_blocked(actor,target) 或 blocked_by(actor,target)（Block 双向阻断）→ false
 *   ③ DM Block：本期无独立 DM Block（MVP-7 再扩展），跳过
 *   ④ Privacy：本产品无私密账号概念，跳过
 *   ⑤ Follow：本期无"必须关注才能互动"的全局规则（reply_policy 是作者级设置，不在此处），跳过
 *   ⑥ 用户设置 / 内容安全：本期无对应配置，跳过
 *   其余 → true
 * 扩展点：DM Block（MVP-7）、私密账号、follow-gate、作者级 reply_policy 之后的内容安全过滤。
 * $action 仅作语义标签留存，本期不参与判断（各动作阻断规则一致）。
 */
function can_interact($actor, $target, $action = '') {
    $actor  = strtolower(trim((string)$actor));
    $target = strtolower(trim((string)$target));
    if ($actor === '' || $target === '') return false;

    // ① Account status：目标必须存在且未被封禁
    $users = load_users();
    if (!isset($users['users'][$target]) || !is_array($users['users'][$target])) return false;
    if (!empty($users['users'][$target]['banned'])) return false;

    // ② Block：双向阻断（任一方拉黑对方都不可互动）
    if (has_blocked($actor, $target)) return false;
    if (blocked_by($actor, $target)) return false;

    // ③ DM Block：本期无独立 DM Block（MVP-7 扩展），跳过
    // ④ Privacy：本产品无私密账号概念，跳过
    // ⑤ Follow：本期无全局 follow-gate（reply_policy 属作者级，不在此处），跳过
    // ⑥ 用户设置 / 内容安全：本期无对应配置，跳过

    return true;
}

// ---------- 配置 ----------
function load_config() {
    if (!is_file(CONFIG_FILE)) return [];
    $c = @include CONFIG_FILE;
    return is_array($c) ? $c : [];
}

// ---------- 邮箱 ----------
function email_ok($email) {
    if (!is_string($email)) return false;
    $email = trim($email);
    if (strlen($email) > 254) return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function get_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

// ---------- 用户库 ----------
function load_users() {
    return db_read_json(USERS_FILE, ['users' => [], 'invite' => '', 'reg_log' => new stdClass(), 'forgot_log' => new stdClass(), 'migrated' => false]);
}

function save_users($u) {
    return db_write_json(USERS_FILE, $u);
}

// 按 email 找用户（email 已小写）
function &find_user(&$users, $email) {
    $null = null;
    if (isset($users['users'][$email]) && is_array($users['users'][$email])) {
        return $users['users'][$email];
    }
    return $null;
}

// 会话校验：hash_equals 遍历 tokens；banned 用户视为失效
function session_user($token) {
    if (!is_string($token) || strlen($token) < 16) return null;
    $users = load_users();
    foreach ($users['users'] as $email => &$u) {
        if (empty($u['tokens']) || !is_array($u['tokens'])) continue;
        foreach ($u['tokens'] as $t) {
            if (is_string($t) && hash_equals($t, $token)) {
                if (!empty($u['banned'])) return null;
                return $u;
            }
        }
    }
    unset($u);
    return null;
}

function require_login($token) {
    $u = session_user($token);
    if (!$u) {
        json_out(['ok' => false, 'err' => 'auth', 'msg' => '登录已失效，请重新登录'], 401);
    }
    return $u;
}

function require_admin($token) {
    $u = require_login($token);
    if (($u['role'] ?? 'user') !== 'admin') {
        json_out(['ok' => false, 'err' => 'admin', 'msg' => '需要管理员权限'], 403);
    }
    return $u;
}

function public_user($u) {
    return [
        'email'    => $u['email'] ?? '',
        'nickname' => $u['nickname'] ?? '',
        'avatar'   => $u['avatar'] ?? 'zodiac:rat',
        'bio'      => $u['bio'] ?? '',   // v3.6.0：个人简介（≤100字），老用户缺省兜底空串
        'role'     => $u['role'] ?? 'user',
        'banned'   => !empty($u['banned']),
        'created_at' => $u['created_at'] ?? '',
    ];
}

// 写用户的 tokens：仅按 email 定位（session_user 只做读校验）
function update_user_tokens($email, $tokens) {
    $users = load_users();
    if (isset($users['users'][$email])) {
        $users['users'][$email]['tokens'] = $tokens;
        save_users($users);
    }
}

// ---------- 算术验证码 ----------
function captcha_cleanup($cap, $nowTs) {
    foreach ($cap as $id => $item) {
        if (is_array($item) && isset($item['created']) && ($nowTs - $item['created']) > 600) {
            unset($cap[$id]);
        }
    }
    return $cap;
}

function captcha_new() {
    $cap = db_read_json(CAPTCHA_FILE, []);
    if (!is_array($cap)) $cap = [];
    $nowTs = time();
    $cap = captcha_cleanup($cap, $nowTs);

    $a = random_int(1, 20);
    $b = random_int(1, 20);
    $op = random_int(0, 1); // 0:+, 1:-
    if ($op === 1 && $b > $a) { list($a, $b) = [$b, $a]; }
    $answer = $op === 0 ? ($a + $b) : ($a - $b);
    $opChar = $op === 0 ? '+' : '-';

    $id = bin2hex(random_bytes(4)); // 8 位 hex
    $cap[$id] = ['a' => $a, 'b' => $b, 'op' => $opChar, 'answer' => $answer, 'created' => $nowTs];
    db_write_json(CAPTCHA_FILE, $cap);

    return ['id' => $id, 'q' => "$a $opChar $b = ?"];
}

// 一次性校验：无论对错都消费
function captcha_check($id, $ans) {
    if (!is_string($id) || $id === '') return false;
    $cap = db_read_json(CAPTCHA_FILE, []);
    if (!is_array($cap)) return false;
    $ok = false;
    if (isset($cap[$id]) && is_array($cap[$id])) {
        $item = $cap[$id];
        $age = time() - ($item['created'] ?? 0);
        if ($age <= 300 && isset($item['answer']) && (int)$ans === (int)$item['answer']) {
            $ok = true;
        }
        unset($cap[$id]); // 一次性
        db_write_json(CAPTCHA_FILE, captcha_cleanup($cap, time()));
    }
    return $ok;
}

// 每用户数据文件路径（小写邮箱，守卫 .php）
function user_data_file($email) {
    return DATA_DIR . strtolower($email) . '.php';
}

// 平摊时序用的 dummy bcrypt hash（"password" 的合法 bcrypt，确保走完整 bcrypt 计算）
const DUMMY_HASH = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

// ---------- SMTP 发信（忘记密码） ----------
// FPM（DSM Web Station PHP 8.2）未加载 openssl 扩展、无 ssl transport，
// 此时自动回退为经 PHP-CLI（/usr/bin/php 8.1，内置 openssl）执行 smtp-cli.php 发信。
// 凭证始终读取自 kaohe-data/config.php，不接收、不输出明文。

function smtp_read($fp) {
    $code = null;
    while (($line = fgets($fp, 515)) !== false) {
        if (strlen($line) >= 3) {
            $code = (int)substr($line, 0, 3);
            // 第4个字符为空格 => 最终响应行
            if (isset($line[3]) && $line[3] === ' ') break;
        }
    }
    return $code;
}

function smtp_cmd($fp, $cmd, $expect) {
    if ($cmd !== '') {
        fwrite($fp, $cmd . "\r\n");
    }
    $code = smtp_read($fp);
    if ($code === null) return false;
    foreach ((array)$expect as $e) {
        if ((int)$code === (int)$e) return true;
    }
    return false;
}

function smtp_send($to, $code6) {
    $transports = function_exists('stream_get_transports') ? @stream_get_transports() : [];
    if (!in_array('ssl', $transports, true)) {
        // 无 openssl → 经 CLI 助手发信（CLI 内置 openssl）；凭证由 CLI 从 config.php 自行读取
        if (!function_exists('exec')) return false;
        $cli = '/usr/bin/php ' . escapeshellarg(__DIR__ . '/smtp-cli.php');
        exec($cli . ' ' . escapeshellarg($to) . ' ' . escapeshellarg($code6) . ' 2>&1', $out, $rc);
        return $rc === 0 && trim(implode('', $out)) === '1';
    }
    $cfg = load_config();
    $smtp = $cfg['smtp'] ?? [];
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 465);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    if ($host === '' || $user === '' || $pass === '') return false;

    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 10);

    // 220 greeting
    if (smtp_read($fp) !== 220) { fclose($fp); return false; }
    if (!smtp_cmd($fp, "EHLO {$host}", 250)) { fclose($fp); return false; }
    if (!smtp_cmd($fp, 'AUTH LOGIN', 334)) { fclose($fp); return false; }
    if (!smtp_cmd($fp, base64_encode($user), 334)) { fclose($fp); return false; }
    if (!smtp_cmd($fp, base64_encode($pass), 235)) { fclose($fp); return false; }
    if (!smtp_cmd($fp, "MAIL FROM:<{$user}>", 250)) { fclose($fp); return false; }
    if (!smtp_cmd($fp, "RCPT TO:<{$to}>", [250, 251])) { fclose($fp); return false; }
    if (!smtp_cmd($fp, 'DATA', 354)) { fclose($fp); return false; }

    $subj = '=?UTF-8?B?' . base64_encode('考核小管家 - 密码重置码') . '?=';
    $body = "您的验证码是：{$code6}\r\n有效期 30 分钟。如非本人操作请忽略本邮件。\r\n";
    $mail = "From: {$user}\r\n"
          . "To: {$to}\r\n"
          . "Subject: {$subj}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=utf-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n"
          . "\r\n"
          . $body . "\r\n.";
    fwrite($fp, $mail . "\r\n");
    $final = smtp_read($fp);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $final === 250;
}

// ---------- HTTPS POST（Synology Chat 入站 webhook） ----------
// FPM（Web Station PHP 8.2）未加载 openssl 扩展、无 ssl transport，
// 此时自动回退为经 PHP-CLI（/usr/bin/php 8.1，内置 openssl）执行 http-post-cli.php 发送。
// Synology 自签证书 → 关闭证书校验；仅当响应 JSON 含 success:true 才视为成功。
// 注意：本固件的 Chat 入站接口不解析原始 JSON body，需以 form-urlencoded 提交
// `payload=<urlencode(JSON)>`（实测 success:true）。$body 由调用方按此格式拼好传入。

function https_post($url, $body, $timeout = 15) {
    $transports = function_exists('stream_get_transports') ? @stream_get_transports() : [];
    if (!in_array('ssl', $transports, true)) {
        // 无 openssl → 经 CLI 助手发送（CLI 内置 openssl）
        if (!function_exists('exec')) return false;
        $cli = '/usr/bin/php ' . escapeshellarg(__DIR__ . '/http-post-cli.php');
        exec($cli . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($body) . ' ' . (int)$timeout . ' 2>&1', $out, $rc);
        return $rc === 0 && trim(implode('', $out)) === '1';
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => (int)$timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false || $resp === '') return false;
    $d = json_decode($resp, true);
    return is_array($d) && !empty($d['success']);
}
