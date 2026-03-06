<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$action = get('action', '');

// Overlay DB-stored OAuth settings onto config so Auth class picks them up
$dbClientId     = getSetting('google_client_id', '');
$dbClientSecret = getSetting('google_client_secret', '');
$dbRedirectUri  = getSetting('google_redirect_uri', '');
if ($dbClientId)     $config['google_oauth_client_id']     = $dbClientId;
if ($dbClientSecret) $config['google_oauth_client_secret'] = $dbClientSecret;
if ($dbRedirectUri)  $config['google_oauth_redirect_uri']  = $dbRedirectUri;

// Rebuild auth with updated config
$auth = new Auth($config);
$auth->startSession();

switch ($action) {
    case 'google_login':
        if (!getSetting('google_oauth_enabled', false) || empty($config['google_oauth_client_id'])) {
            redirect($config['base_url'] . '/admin/login.php');
        }
        $url = $auth->getGoogleAuthUrl();
        redirect($url);
        break;

    case 'google_callback':
        $code  = get('code', '');
        $state = get('state', '');
        $result = $auth->handleGoogleCallback($code, $state);
        if ($result['success']) {
            redirect($config['base_url'] . '/admin/');
        } else {
            $err = urlencode($result['error']);
            redirect($config['base_url'] . '/admin/login.php?error=' . $err);
        }
        break;

    case 'logout':
        $auth->logout();
        redirect($config['base_url'] . '/admin/login.php');
        break;

    default:
        http_response_code(400);
        echo 'Invalid action.';
}
