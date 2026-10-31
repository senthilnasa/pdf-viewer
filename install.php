<?php
/**
 * PDF Viewer Platform - Installer
 * Run this once to set up the database and admin account.
 * DELETE or RENAME this file after installation.
 */

define('INSTALLER', true);
define('ROOT', __DIR__);
require ROOT . '/includes/Installer.php';

session_name('pdfv_install');
session_start();

// ------------------------------------------------------------------
// Installation lock: once installed, the installer is disabled for every
// request (it used to stay fully usable, so anyone could re-run step 3 to
// create a new admin, reset the existing admin's password or repoint the DB).
// Pre-lock installs are detected and locked on the spot.
// ------------------------------------------------------------------
$justInstalled = !empty($_SESSION['install_done']);
if (!Installer::isLocked() && !$justInstalled && Installer::detectExistingInstall()) {
    Installer::writeLock(['note' => 'auto-locked: existing installation detected']);
}
if (Installer::isLocked() && !($justInstalled && (int)($_GET['step'] ?? 0) === 4)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Already installed</title><style>body{font-family:system-ui,sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.c{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);max-width:520px;padding:2rem;margin:1rem}code{background:#f1f5f9;padding:.1rem .3rem;border-radius:4px}</style></head>'
       . '<body><div class="c"><h1 style="margin-top:0">Already installed</h1>'
       . '<p>This application is already installed, so the installer is locked.</p>'
       . '<p style="color:#64748b;font-size:.9rem">To intentionally reinstall, a server administrator must delete <code>config/install.lock</code> '
       . 'and run the installer against an <strong>empty</strong> database. Existing databases are never overwritten.</p>'
       . '<p><a href="admin/">Go to the admin panel &rarr;</a></p></div></body></html>';
    exit;
}

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$step  = (int)($_GET['step'] ?? 1);
$step  = ($step >= 1 && $step <= 4) ? $step : 1;
$error = '';
$success = '';

// Step 4 is only reachable right after a successful install in this session
if ($step === 4 && !$justInstalled) {
    $step = 1;
}

// Step 3: process installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $dbHost   = trim($_POST['db_host'] ?? 'localhost');
    $dbPort   = (int)($_POST['db_port'] ?? 3306);
    $dbName   = trim($_POST['db_name'] ?? '');
    $dbUser   = trim($_POST['db_user'] ?? '');
    $dbPass   = $_POST['db_pass'] ?? '';
    $baseUrl  = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $siteName = trim($_POST['site_name'] ?? 'PDF Viewer');
    $adminName  = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';

    if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif (!$dbName || !$dbUser || !$adminEmail || !$adminPass || !$baseUrl) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
        // The name is interpolated into CREATE DATABASE / USE statements
        $error = 'Database name may only contain letters, numbers and underscores.';
    } elseif (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $dbHost) || $dbPort < 1 || $dbPort > 65535) {
        $error = 'Invalid database host or port.';
    } elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
        $error = 'Base URL must be a full http(s):// URL.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid admin email address.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Admin password must be at least 8 characters.';
    } elseif (mb_strlen($adminName) > 120) {
        $error = 'Admin name must be 120 characters or fewer.';
    } elseif (!is_writable(ROOT . '/config')) {
        $error = 'The config/ directory is not writable by the web server.';
    } else {
        $dbCfg = ['host' => $dbHost, 'port' => $dbPort, 'name' => $dbName, 'username' => $dbUser, 'password' => $dbPass];
        try {
            $pdo = Installer::connect($dbCfg, false);

            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Never overwrite an existing installation's data.
            if (Installer::databaseHasUsers($pdo)) {
                throw new RuntimeException(
                    'This database already contains user accounts (an existing installation). '
                    . 'Use an empty database, or remove the existing tables first if you really want to reinstall.'
                );
            }

            // 1) Schema + migrations (all idempotent: safe to repeat after an
            //    interrupted install)
            Installer::runSqlFile($pdo, ROOT . '/database.sql');
            if (is_file(ROOT . '/database-migrations.sql')) {
                Installer::runSqlFile($pdo, ROOT . '/database-migrations.sql');
            }

            // 2) Config files (atomic writes)
            $dbConfigContent = "<?php\nreturn [\n"
                . "    'host'     => " . var_export($dbHost, true) . ",\n"
                . "    'port'     => {$dbPort},\n"
                . "    'name'     => " . var_export($dbName, true) . ",\n"
                . "    'username' => " . var_export($dbUser, true) . ",\n"
                . "    'password' => " . var_export($dbPass, true) . ",\n"
                . "    'charset'  => 'utf8mb4',\n"
                . "    'options'  => [\n"
                . "        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
                . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                . "        PDO::ATTR_EMULATE_PREPARES   => false,\n"
                . "    ],\n"
                . "];\n";
            if (!Installer::atomicWrite(ROOT . '/config/database.php', $dbConfigContent)) {
                throw new RuntimeException('Could not write config/database.php.');
            }

            $appConfigPath = ROOT . '/config/app.php';
            $appConfig = file_exists($appConfigPath)
                ? file_get_contents($appConfigPath)
                : file_get_contents(ROOT . '/config/app.php.example');
            $appConfig = Installer::setConfigValue($appConfig, 'base_url', $baseUrl);
            $appConfig = Installer::setConfigValue($appConfig, 'site_name', $siteName);
            if (!Installer::atomicWrite($appConfigPath, $appConfig)) {
                throw new RuntimeException('Could not write config/app.php.');
            }

            // 3) Admin account + site name (in one transaction)
            $pdo->beginTransaction();
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, auth_provider, status)
                 VALUES (?, ?, ?, 'admin', 'local', 'active')"
            );
            $stmt->execute([$adminName ?: 'Administrator', $adminEmail, $hash]);
            $pdo->prepare(
                "INSERT INTO settings (`key`, `value`, `type`) VALUES ('site_name', ?, 'string')
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
            )->execute([$siteName]);
            $pdo->commit();

            // 4) Lock — last step. If this write fails the install is still
            //    complete and the next installer hit auto-locks it.
            Installer::writeLock(['base_url' => $baseUrl]);

            $_SESSION['install_done'] = true;
            unset($_SESSION['install_csrf']);
            header('Location: install.php?step=4');
            exit;

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = ($e instanceof PDOException ? 'Database error: ' : 'Error: ') . htmlspecialchars($e->getMessage());
        }
    }
}

// Detect base URL
$detectedBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

$pageTitle = 'PDF Viewer — Installer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f0f4f8; color: #1a202c; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.1); width: 100%; max-width: 540px; overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #4f46e5, #7c3aed); padding: 2rem; color: #fff; }
        .card-header h1 { font-size: 1.5rem; font-weight: 700; }
        .card-header p { margin-top: .25rem; opacity: .85; font-size: .9rem; }
        .steps { display: flex; padding: 1rem 2rem 0; gap: 0; }
        .step { flex: 1; text-align: center; font-size: .75rem; padding-bottom: .75rem; border-bottom: 3px solid #e2e8f0; color: #94a3b8; }
        .step.active { border-bottom-color: #4f46e5; color: #4f46e5; font-weight: 600; }
        .step.done { border-bottom-color: #10b981; color: #10b981; }
        .card-body { padding: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .4rem; color: #374151; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=url] {
            width: 100%; padding: .6rem .85rem; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: .9rem; transition: border .2s;
        }
        input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.1); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: .65rem 1.5rem; background: #4f46e5; color: #fff; border: none; border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .2s; width: 100%; }
        .btn:hover { background: #4338ca; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
        .alert { padding: .85rem 1rem; border-radius: 8px; font-size: .88rem; margin-bottom: 1.25rem; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .check { color: #10b981; font-weight: 700; }
        .cross { color: #ef4444; font-weight: 700; }
        ul.req { list-style: none; margin-bottom: 1.5rem; }
        ul.req li { padding: .3rem 0; font-size: .88rem; }
        .section-title { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; font-weight: 700; margin-bottom: .75rem; padding-bottom: .5rem; border-bottom: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>PDF Viewer Platform</h1>
        <p>Installation Wizard</p>
    </div>
    <div class="steps">
        <?php
        $steps = ['Welcome', 'Requirements', 'Configure', 'Done'];
        foreach ($steps as $i => $label):
            $n = $i + 1;
            $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
        ?>
        <div class="step <?= $cls ?>"><?= $n ?>. <?= $label ?></div>
        <?php endforeach; ?>
    </div>
    <div class="card-body">

    <?php if ($step === 1): ?>
        <p>Welcome to the <strong>PDF Viewer</strong> installer. This wizard will guide you through setting up the database, admin account, and configuration.</p>
        <br>
        <div class="alert alert-info">Ensure your <code>config/</code> and <code>uploads/</code> directories are writable by the web server before continuing.</div>
        <a href="install.php?step=2" class="btn">Get Started &rarr;</a>

    <?php elseif ($step === 2): ?>
        <?php
        $checks = [
            'PHP 8.0+'       => version_compare(PHP_VERSION, '8.0', '>='),
            'PDO MySQL'      => extension_loaded('pdo_mysql'),
            'FileInfo'       => extension_loaded('fileinfo'),
            'JSON'           => extension_loaded('json'),
            'config/ writable' => is_writable(ROOT . '/config'),
            'uploads/ writable'=> is_writable(ROOT . '/uploads'),
            'database.sql exists' => file_exists(ROOT . '/database.sql'),
        ];
        $allPass = !in_array(false, $checks, true);
        ?>
        <p class="section-title">System Requirements</p>
        <ul class="req">
        <?php foreach ($checks as $label => $pass): ?>
            <li><span class="<?= $pass ? 'check' : 'cross' ?>"><?= $pass ? '✓' : '✗' ?></span> <?= htmlspecialchars($label) ?></li>
        <?php endforeach; ?>
        </ul>
        <?php if (!$allPass): ?>
            <div class="alert alert-error">Some requirements are not met. Please fix the issues above before continuing.</div>
        <?php else: ?>
            <a href="install.php?step=3" class="btn">Continue &rarr;</a>
        <?php endif; ?>

    <?php elseif ($step === 3): ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST" action="install.php?step=3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'] ?? '') ?>">
            <p class="section-title">Database Connection</p>
            <div class="row">
                <div class="form-group">
                    <label>DB Host</label>
                    <input type="text" name="db_host" value="<?= htmlspecialchars(getenv('DB_HOST') ?: 'localhost') ?>" required>
                </div>
                <div class="form-group">
                    <label>DB Port</label>
                    <input type="number" name="db_port" value="<?= htmlspecialchars(getenv('DB_PORT') ?: '3306') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars(getenv('DB_NAME') ?: '') ?>" placeholder="pdf_viewer" required>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>DB Username</label>
                    <input type="text" name="db_user" value="<?= htmlspecialchars(getenv('DB_USER') ?: '') ?>" placeholder="root" required>
                </div>
                <div class="form-group">
                    <label>DB Password</label>
                    <input type="password" name="db_pass" value="<?= htmlspecialchars(getenv('DB_PASS') ?: '') ?>">
                </div>
            </div>
            <p class="section-title">Site Settings</p>
            <div class="form-group">
                <label>Site Name</label>
                <input type="text" name="site_name" value="PDF Viewer" required>
            </div>
            <div class="form-group">
                <label>Base URL (no trailing slash)</label>
                <input type="url" name="base_url" value="<?= htmlspecialchars($detectedBase) ?>" required>
            </div>
            <p class="section-title">Admin Account</p>
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="admin_name" placeholder="Administrator" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="admin_email" required>
            </div>
            <div class="form-group">
                <label>Password (min 8 characters)</label>
                <input type="password" name="admin_pass" minlength="8" required>
            </div>
            <button type="submit" class="btn">Install Now &rarr;</button>
        </form>

    <?php elseif ($step === 4): ?>
        <div style="text-align:center;padding:1rem 0;">
            <div style="font-size:3rem;">🎉</div>
            <h2 style="margin:.75rem 0 .5rem;color:#10b981;">Installation Complete!</h2>
            <p style="color:#6b7280;margin-bottom:1.5rem;">PDF Viewer has been successfully installed.</p>
        </div>
        <div class="alert alert-info" style="margin-bottom:1.5rem;">
            <strong>Installer locked.</strong> <code>config/install.lock</code> was created, so this installer can no longer be used.
            You can also delete <code>install.php</code> as an extra precaution.
        </div>
        <?php unset($_SESSION['install_done']); ?>
        <a href="admin/" class="btn btn-success">Go to Admin Panel &rarr;</a>
    <?php endif; ?>

    </div>
</div>
</body>
</html>
