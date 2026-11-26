<?php
declare(strict_types=1);

/**
 * 系统更新：从 GitHub 仓库拉取最新代码
 *
 * 原则：
 *  - 只覆盖代码，不碰 app/config.php、data/ 数据库与日志
 *  - 更新前自动把当前代码打包备份到 data/backups/
 *  - 只认固定的仓库地址，避免被改成任意来源
 */

const UPDATE_REPO      = 'kangleizhui/vpn-shop';
const UPDATE_BRANCH    = 'main';
const UPDATE_CACHE_TTL = 300;   // 检查结果缓存 5 分钟
const UPDATE_SKIP      = ['app/config.php', 'data', '.git'];

function upd_http(string $url, int $timeout = 15): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'vpn-shop-updater/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (is_string($body) && $code >= 200 && $code < 300) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => 'vpn-shop-updater/1.0'],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** 本地版本信息 */
function upd_state(): array
{
    $ver = trim((string)@file_get_contents(APP_ROOT . '/VERSION'));
    $st  = json_decode((string)@file_get_contents(DATA_DIR . '/installed.json'), true);
    return [
        'version' => $ver !== '' ? $ver : '0.0.0',
        'sha'     => is_array($st) ? (string)($st['sha'] ?? '') : '',
        'time'    => is_array($st) ? (string)($st['time'] ?? '') : '',
    ];
}

/** 读缓存（不发请求） */
function upd_cached(): ?array
{
    $f = DATA_DIR . '/update_check.json';
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) && !empty($j['ok']) ? $j : null;
}

/** 检查 GitHub 最新提交 */
function upd_check(bool $force = false): array
{
    if (!$force) {
        $c = upd_cached();
        if ($c && (time() - (int)($c['checked_at'] ?? 0)) < UPDATE_CACHE_TTL) {
            $c['cached'] = true;
            return $c;
        }
    }

    $sha = '';
    $date = '';
    $note = '';
    $api = upd_http('https://api.github.com/repos/' . UPDATE_REPO . '/commits/' . UPDATE_BRANCH);
    if ($api !== null) {
        $j = json_decode($api, true);
        if (is_array($j)) {
            $sha  = (string)($j['sha'] ?? '');
            $date = (string)($j['commit']['committer']['date'] ?? '');
            $note = trim((string)($j['commit']['message'] ?? ''));
        }
    }

    // 版本号走 API（raw CDN 会缓存，刚推的改动可能读不到）
    $ver = '';
    $cj = upd_http('https://api.github.com/repos/' . UPDATE_REPO . '/contents/VERSION?ref=' . UPDATE_BRANCH, 10);
    if ($cj !== null) {
        $c = json_decode($cj, true);
        if (is_array($c) && !empty($c['content'])) {
            $ver = trim((string)base64_decode(str_replace(["\n", "\r"], '', (string)$c['content'])));
        }
    }
    if ($ver === '') {
        $ver = trim((string)upd_http(
            'https://raw.githubusercontent.com/' . UPDATE_REPO . '/' . UPDATE_BRANCH . '/VERSION',
            10
        ));
    }

    if ($sha === '' && $ver === '') {
        return ['ok' => false, 'msg' => '连接 GitHub 失败，请稍后重试', 'checked_at' => time()];
    }

    $info = [
        'ok'         => true,
        'sha'        => $sha,
        'version'    => $ver !== '' ? $ver : '未知',
        'date'       => $date,
        'note'       => strtok($note, "\n") ?: '',
        'checked_at' => time(),
    ];
    @file_put_contents(DATA_DIR . '/update_check.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    return $info;
}

function upd_has_new(array $st, array $chk): bool
{
    if (empty($chk['ok'])) return false;
    if ($st['sha'] === '') return true;              // 没记录过安装版本
    return $chk['sha'] !== '' && $chk['sha'] !== $st['sha'];
}

/** 递归复制代码（跳过配置/数据/版本库） */
function upd_copy(string $src, string $dst, string $rel = ''): int
{
    $n = 0;
    foreach ((array)scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $relPath = $rel === '' ? $item : $rel . '/' . $item;
        if (in_array($relPath, UPDATE_SKIP, true)) continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!is_dir($d) && !@mkdir($d, 0755, true)) continue;
            $n += upd_copy($s, $d, $relPath);
        } elseif (@copy($s, $d)) {
            @chmod($d, 0644);
            $n++;
        }
    }
    return $n;
}

/** 把当前代码打包备份 */
function upd_backup(): string
{
    $dir = DATA_DIR . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/code_' . date('Ymd_His') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return '';
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(APP_ROOT) + 1));
        foreach (UPDATE_SKIP as $skip) {
            if ($rel === $skip || str_starts_with($rel, $skip . '/')) continue 2;
        }
        $zip->addFile($f->getPathname(), $rel);
    }
    $zip->close();

    // 只保留最近 10 份，避免长期占用磁盘
    $olds = glob($dir . '/code_*.zip') ?: [];
    usort($olds, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($olds, 10) as $old) @unlink($old);

    return is_file($file) ? $file : '';
}

function upd_rmtree(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * 执行更新
 * @return array{ok:bool,msg:string,files:int,backup:string,version:string}
 */
function upd_apply(): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'msg' => '服务器缺少 zip 扩展，无法更新', 'files' => 0, 'backup' => '', 'version' => ''];
    }
    if (!is_writable(APP_ROOT)) {
        return ['ok' => false, 'msg' => '站点目录不可写，请检查权限', 'files' => 0, 'backup' => '', 'version' => ''];
    }

    $tmp = DATA_DIR . '/tmp_update_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0755, true)) {
        return ['ok' => false, 'msg' => '无法创建临时目录', 'files' => 0, 'backup' => '', 'version' => ''];
    }

    try {
        // 先拿到确切提交，再按提交下载，避免分支 zip 的 CDN 缓存与记录的版本不一致
        $info = upd_check(true);
        if (empty($info['ok'])) {
            return ['ok' => false, 'msg' => '无法获取 GitHub 最新版本', 'files' => 0, 'backup' => '', 'version' => ''];
        }
        $ref  = (string)($info['sha'] ?? '');
        if ($ref === '') $ref = UPDATE_BRANCH;
        $body = upd_http('https://codeload.github.com/' . UPDATE_REPO . '/zip/' . rawurlencode($ref), 90);
        if ($body === null || strlen($body) < 1000) {
            return ['ok' => false, 'msg' => '下载更新包失败，请检查服务器网络', 'files' => 0, 'backup' => '', 'version' => ''];
        }
        $zipFile = $tmp . '/src.zip';
        file_put_contents($zipFile, $body);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return ['ok' => false, 'msg' => '更新包解压失败', 'files' => 0, 'backup' => '', 'version' => ''];
        }
        $zip->extractTo($tmp . '/src');
        $zip->close();

        $dirs = glob($tmp . '/src/*', GLOB_ONLYDIR) ?: [];
        if (!$dirs) {
            return ['ok' => false, 'msg' => '更新包内容不完整', 'files' => 0, 'backup' => '', 'version' => ''];
        }
        $srcRoot = $dirs[0];

        // 校验：必须是我们自己的项目结构
        if (!is_file($srcRoot . '/public/index.php') || !is_file($srcRoot . '/app/bootstrap.php')) {
            return ['ok' => false, 'msg' => '更新包结构异常，已取消更新', 'files' => 0, 'backup' => '', 'version' => ''];
        }

        $backup = upd_backup();
        if ($backup === '') {
            return ['ok' => false, 'msg' => '备份当前代码失败，已取消更新', 'files' => 0, 'backup' => '', 'version' => ''];
        }

        $n = upd_copy($srcRoot, APP_ROOT);

        $ver = (string)($info['version'] ?? '');
        $sha = (string)($info['sha'] ?? '');
        @file_put_contents(DATA_DIR . '/installed.json', json_encode([
            'sha'     => $sha,
            'version' => $ver,
            'time'    => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
        // 保留检查结果，页面直接显示“已是最新”
        if (!empty($info['ok'])) {
            @file_put_contents(DATA_DIR . '/update_check.json', json_encode($info, JSON_UNESCAPED_UNICODE));
        } else {
            @unlink(DATA_DIR . '/update_check.json');
        }
        if (function_exists('opcache_reset')) @opcache_reset();

        return [
            'ok'      => true,
            'msg'     => '更新完成',
            'files'   => $n,
            'backup'  => $backup,
            'version' => $ver,
        ];
    } finally {
        upd_rmtree($tmp);
    }
}
