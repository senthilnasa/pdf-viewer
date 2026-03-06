<?php
/**
 * PDF Viewer Platform - Installer
 * Run this once to set up the database and admin account.
 * DELETE or RENAME this file after installation.
 */

define('INSTALLER', true);
define('ROOT', __DIR__);

session_start();

$step  = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

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

    if (!$dbName || !$dbUser || !$adminEmail || !$adminPass || !$baseUrl) {
        $error = 'All fields are required.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid admin email address.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Admin password must be at least 8 characters.';
    } else {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Run schema
            $sql = file_get_contents(ROOT . '/database.sql');
            // Split on semicolons (rough but works for this schema)
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt) {
                    $pdo->exec($stmt);
                }
            }

            // Insert admin user
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, auth_provider, status)
                 VALUES (?, ?, ?, 'admin', 'local', 'active')
                 ON DUPLICATE KEY UPDATE role='admin', password=VALUES(password)"
            );
            $stmt->execute([$adminName ?: 'Administrator', $adminEmail, $hash]);

            // Write config files
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
            file_put_contents(ROOT . '/config/database.php', $dbConfigContent);

            // Update app config base_url and site_name
            $appConfigPath = ROOT . '/config/app.php';
            if (!file_exists($appConfigPath)) {
                copy(ROOT . '/config/app.php.example', $appConfigPath);
            }
            $appConfig = file_get_contents($appConfigPath);
            $appConfig = preg_replace(
                "/'base_url'\s*=>\s*'[^']*'/",
                "'base_url' => " . var_export($baseUrl, true),
                $appConfig
            );
            $appConfig = preg_replace(
                "/'site_name'\s*=>\s*'[^']*'/",
                "'site_name' => " . var_export($siteName, true),
                $appConfig
            );
            file_put_contents($appConfigPath, $appConfig);

            $_SESSION['install_done'] = true;
            header('Location: install.php?step=4');
            exit;

        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        } catch (Exception $e) {
            $error = 'Error: ' . htmlspecialchars($e->getMessage());
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
        <div class="alert alert-error" style="margin-bottom:1.5rem;">
            <strong>Security Notice:</strong> Delete or rename <code>install.php</code> immediately to prevent unauthorized re-installation.
        </div>
        <a href="admin/" class="btn btn-success">Go to Admin Panel &rarr;</a>
    <?php endif; ?>

    </div>
</div>
</body>
</html>
