<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$configFile = CONFIG_FILE;
$lockFile   = APP_ROOT . '/install.lock';
$installed  = is_installed();
$errors     = [];
$done       = false;

/* ---------- 已安装 ---------- */
if ($installed && is_file($lockFile) && !isset($_GET['force'])) {
    installer_head('已安装');
    echo '<div class="panel narrow"><h2>系统已安装</h2>
      <div class="alert ok">检测到系统已经完成安装。</div>
      <p class="muted small">如需重新安装，请先删除 <code>app/config.php</code> 和 <code>install.lock</code>，然后刷新本页。</p>
      <div class="actions"><a class="btn" href="/">进入网站</a><a class="btn ghost" href="/admin/login">后台登录</a></div>
      </div>';
    installer_foot();
    exit;
}

/* ---------- 提交安装 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = ($_POST['db_type'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    $siteName = trim((string)($_POST['site_name'] ?? '云商城'));
    $siteUrl  = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if ($adminUser === '') $errors[] = '管理员账号不能为空';
    if (strlen($adminPass) < 8) $errors[] = '管理员密码至少 8 位';

    $db = ['type' => $type];
    if ($type === 'mysql') {
        $db = [
            'type' => 'mysql',
            'host' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim((string)($_POST['db_name'] ?? '')),
            'user' => trim((string)($_POST['db_user'] ?? '')),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        if ($db['name'] === '' || $db['user'] === '') $errors[] = '请填写数据库名和用户名';

        if (!$errors) {
            try {
                if (!empty($_POST['db_create'])) {
                    $root = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
                                    $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $root->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $db['name']) . "` DEFAULT CHARACTER SET utf8mb4");
                }
                $test = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
                                $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $test->query('SELECT 1');
            } catch (Throwable $e) {
                $errors[] = '数据库连接失败：' . $e->getMessage();
            }
        }
    } else {
        if (!is_writable(APP_ROOT)) $errors[] = '项目根目录不可写，无法创建 data 目录';
    }

    if (!$errors) {
        $cfg = [
            'db' => $db,
            'site_key' => bin2hex(random_bytes(16)),
            'installed_at' => date('Y-m-d H:i:s'),
        ];
        $php = "<?php\n// 由安装程序生成，请勿泄露此文件\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($configFile, $php) === false) {
            $errors[] = '无法写入 app/config.php，请检查 app 目录权限';
        }
    }

    if (!$errors) {
        try {
            app_config(true); // 重新加载刚写入的 config.php
            db(); // 建表
            set_setting('site_name', $siteName !== '' ? $siteName : '云商城');
            set_setting('site_url', $siteUrl !== '' ? $siteUrl : installer_guess_url());
            set_setting('test_mode', '1');
            set_setting('admin_user', $adminUser);
            set_setting('admin_hash', password_hash($adminPass, PASSWORD_DEFAULT));
            @file_put_contents($lockFile, date('Y-m-d H:i:s'));
            $done = true;
        } catch (Throwable $e) {
            $errors[] = '初始化数据表失败：' . $e->getMessage();
            @unlink($configFile);
        }
    }
}

function installer_guess_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function installer_head(string $title): void {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' - 安装向导</title>';
    echo '<link rel="stylesheet" href="/assets/style.css?v=' . (@filemtime(__DIR__ . '/assets/style.css') ?: '1') . '">';
    echo '<style>.panel.narrow{max-width:640px}.steps-bar{display:flex;gap:8px;margin:0 0 18px}.steps-bar span{flex:1;height:4px;border-radius:99px;background:rgba(255,255,255,.15)}.steps-bar span.on{background:var(--accent)}.req{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed var(--line);font-size:14px}</style>';
    echo '</head><body><main class="wrap" style="padding-top:48px">';
}
function installer_foot(): void {
    echo '</main></body></html>';
}

installer_head('安装向导');
echo '<div class="panel narrow">';
echo '<h2>云商城商城 · 安装向导</h2>';
echo '<p class="muted small">上传解压 → 访问本页 → 填数据库 → 完成。全程 1 分钟。</p>';

if ($done) {
    $url = e(installer_guess_url());
    echo '<div class="alert ok">安装成功！数据表已创建，管理员账号已设置。</div>';
    echo '<div class="kv"><span>网站首页</span><b><a href="/">' . $url . '/</a></b></div>';
    echo '<div class="kv"><span>管理后台</span><b><a href="/admin/login">' . $url . '/admin/login</a></b></div>';
    echo '<div class="kv"><span>管理员账号</span><b>' . e($_POST['admin_user'] ?? 'admin') . '</b></div>';
    echo '<div class="alert warn"><b>安全提示：</b>请立即删除服务器上的 <code>public/install.php</code> 文件，防止他人重装。</div>';
    echo '<div class="actions"><a class="btn" href="/admin/login">进入后台</a><a class="btn ghost" href="/">打开首页</a></div>';
    echo '</div>';
    installer_foot();
    exit;
}

if ($errors) {
    echo '<div class="alert err">' . implode('<br>', array_map('e', $errors)) . '</div>';
}

/* ---------- 环境检查 ---------- */
$checks = [
    ['PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
    ['PDO 扩展', extension_loaded('pdo'), extension_loaded('pdo') ? '已启用' : '未启用'],
    ['PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已启用' : '未启用'],
    ['PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? '已启用（可选）' : '未启用（可选）'],
    ['cURL 扩展', extension_loaded('curl'), extension_loaded('curl') ? '已启用' : '未启用'],
    ['mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已启用' : '未启用'],
    ['openssl', extension_loaded('openssl'), extension_loaded('openssl') ? '已启用' : '未启用'],
    ['app 目录可写', is_writable(APP_ROOT), is_writable(APP_ROOT) ? '可写' : '不可写'],
];
$bad = 0;
foreach ($checks as $c) if (!$c[1] && !str_contains($c[0], 'SQLite')) $bad++;

echo '<h3>1. 环境检查</h3>';
foreach ($checks as $c) {
    echo '<div class="req"><span>' . e($c[0]) . '</span><b style="color:' . ($c[1] ? '#7fbf95' : '#e8a49d') . '">' . ($c[1] ? '✓ ' : '✗ ') . e($c[2]) . '</b></div>';
}
if ($bad) {
    echo '<div class="alert err">有 ' . $bad . ' 项必需条件不满足，请先在宝塔「软件商店 → PHP 设置」里安装对应扩展。</div>';
}

/* ---------- 表单 ---------- */
$v = fn(string $k, string $d = '') => e((string)($_POST[$k] ?? $d));
echo '<h3>2. 数据库</h3>';
echo '<form method="post" autocomplete="off">';
echo '<label>数据库类型</label>';
echo '<div class="regionpick">';
echo '<label class="pick"><input type="radio" name="db_type" value="mysql" ' . (($_POST['db_type'] ?? 'mysql') === 'mysql' ? 'checked' : '') . '><span><b>MySQL</b><em>宝塔推荐</em></span></label>';
echo '<label class="pick"><input type="radio" name="db_type" value="sqlite" ' . (($_POST['db_type'] ?? '') === 'sqlite' ? 'checked' : '') . '><span><b>SQLite</b><em>免配置单文件</em></span></label>';
echo '</div>';

echo '<div class="row2"><label>数据库地址<input type="text" name="db_host" value="' . $v('db_host', '127.0.0.1') . '"></label>';
echo '<label>端口<input type="number" name="db_port" value="' . $v('db_port', '3306') . '"></label></div>';
echo '<div class="row2"><label>数据库名<input type="text" name="db_name" value="' . $v('db_name') . '" placeholder="vpnshop"></label>';
echo '<label>用户名<input type="text" name="db_user" value="' . $v('db_user') . '" placeholder="vpnshop"></label></div>';
echo '<label>数据库密码<input type="password" name="db_pass" value="' . $v('db_pass') . '"></label>';
echo '<label class="row" style="margin-top:12px"><input type="checkbox" name="db_create" value="1"> 数据库不存在时自动创建（需账号有建库权限）</label>';

echo '<h3>3. 站点与管理员</h3>';
echo '<label>站点名称<input type="text" name="site_name" value="' . $v('site_name', '云商城') . '"></label>';
echo '<label>站点地址（留空自动识别）<input type="text" name="site_url" value="' . $v('site_url') . '" placeholder="https://你的域名"></label>';
echo '<div class="row2"><label>管理员账号<input type="text" name="admin_user" value="' . $v('admin_user', 'admin') . '"></label>';
echo '<label>管理员密码（≥8位）<input type="password" name="admin_pass" value="' . $v('admin_pass') . '"></label></div>';

echo '<button class="btn wide" type="submit">开始安装</button>';
echo '</form>';
echo '<p class="muted small center" style="margin-top:14px">安装完成后记得删除 public/install.php</p>';
echo '</div>';
installer_foot();
