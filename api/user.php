<?php
/**
 * User API Endpoints
 * PDF Viewer Platform
 *
 * Endpoints:
 * - GET /api/user.php?action=profile
 * - GET /api/user.php?action=notifications
 * - GET /api/user.php?action=notifications_count
 * - POST /api/user.php?action=mark_notification_read
 * - POST /api/user.php?action=logout
 */

header('Content-Type: application/json; charset=utf-8');

define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/EmailProvider.php';
require ROOT . '/includes/EmailTemplate.php';
require ROOT . '/includes/AuditLog.php';
require ROOT . '/includes/Notification.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();

// Require authentication
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = $auth->currentUser();
$action = get('action', '');

switch ($action) {
    case 'profile':
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'avatar' => $user['avatar'],
                'last_login' => $user['last_login'],
                'created_at' => $user['created_at'],
            ],
        ]);
        break;

    case 'notifications':
        $limit = (int)get('limit', 10);
        $notifications = Notification::getAll($user['id'], $limit);
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'count' => count($notifications),
            'unread_count' => Notification::getUnreadCount($user['id']),
        ]);
        break;

    case 'notifications_count':
        echo json_encode([
            'success' => true,
            'unread_count' => Notification::getUnreadCount($user['id']),
        ]);
        break;

    case 'mark_notification_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST only']);
            exit;
        }
        verifyCsrf();
        $notifId = (int)post('notification_id');
        Notification::markAsRead($notifId, $user['id']);
        echo json_encode(['success' => true]);
        break;

    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST only']);
            exit;
        }
        verifyCsrf();
        $auth->logout();
        echo json_encode(['success' => true, 'message' => 'Logged out']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
