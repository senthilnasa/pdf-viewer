<?php
/**
 * Helper functions
 * PDF Viewer Platform
 */

// -------------------------------------------------------------------------
// Bootstrap — call once at entry points
// -------------------------------------------------------------------------

function bootstrap(): array
{
    $appConfig = ROOT . '/config/app.php';
    if (!file_exists($appConfig)) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $installer = $base . '/install.php';
        header('Location: ' . $installer);
        exit;
    }
    $config = require $appConfig;
    date_default_timezone_set($config['timezone'] ?? 'UTC');

    // Start auth / session
    global $auth;
    $auth = new Auth($config);
    $auth->startSession();

    return $config;
}

// -------------------------------------------------------------------------
// Output helpers
// -------------------------------------------------------------------------

function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// -------------------------------------------------------------------------
// Request helpers
// -------------------------------------------------------------------------

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = ''): mixed
{
    return $_GET[$key] ?? $default;
}

function isPost(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function csrfField(): string
{
    global $auth;
    $token = $auth->generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

function verifyCsrf(): void
{
    global $auth;
    $token = post('csrf_token') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$auth->validateCsrfToken($token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// -------------------------------------------------------------------------
// Pagination
// -------------------------------------------------------------------------

function paginate(int $total, int $perPage, int $currentPage, string $urlPattern): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => ($currentPage - 1) * $perPage,
        'url_pattern'  => $urlPattern,
    ];
}

// -------------------------------------------------------------------------
// Settings DB helpers
// -------------------------------------------------------------------------

function getSetting(string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $row = Database::fetchOne('SELECT value, type FROM settings WHERE `key` = ?', [$key]);
        if ($row === false) {
            $cache[$key] = $default;
        } else {
            $cache[$key] = castSetting($row['value'], $row['type']);
        }
    }
    return $cache[$key];
}

function setSetting(string $key, mixed $value, string $type = 'string'): void
{
    $strVal = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
    Database::query(
        'INSERT INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = ?, `type` = ?',
        [$key, $strVal, $type, $strVal, $type]
    );
}

function castSetting(string $value, string $type): mixed
{
    return match ($type) {
        'boolean' => (bool)(int)$value,
        'integer' => (int)$value,
        'json'    => json_decode($value, true),
        default   => $value,
    };
}

// -------------------------------------------------------------------------
// Admin nav helper
// -------------------------------------------------------------------------

function adminNav(string $current): string
{
    $items = [
        'dashboard'  => ['Dashboard',    'admin/index.php',    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        'pdfs'       => ['PDF Manager',  'admin/pdfs.php',     'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        'analytics'  => ['Analytics',    'admin/analytics.php','M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
        'reports'    => ['Reports',      'admin/reports.php',  'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        'team'       => ['Team',         'admin/team.php',     'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        'settings'   => ['Settings',     'admin/settings.php', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
    ];

    $html = '';
    foreach ($items as $key => [$label, $href, $icon]) {
        $active = $current === $key ? 'active' : '';
        $html .= <<<HTML
<a href="{$href}" class="nav-item {$active}">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{$icon}"/>
    </svg>
    <span>{$label}</span>
</a>
HTML;
    }
    return $html;
}

// -------------------------------------------------------------------------
// Date formatting
// -------------------------------------------------------------------------

function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'just now';
    if ($diff < 3600)     return floor($diff / 60) . 'm ago';
    if ($diff < 86400)    return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000)  return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function formatDate(string $datetime, string $format = 'M j, Y'): string
{
    return date($format, strtotime($datetime));
}

// -------------------------------------------------------------------------
// CSV export
// -------------------------------------------------------------------------

function exportCsv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}
