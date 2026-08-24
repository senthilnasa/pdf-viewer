<?php
/**
 * Notification System
 * PDF Viewer Platform
 *
 * Manages in-app notifications with email delivery support.
 */

class Notification
{
    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';
    public const TYPE_SECURITY = 'security';

    public static function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?int $expiresInHours = 30
    ): int {
        $expiresAt = $expiresInHours ? date('Y-m-d H:i:s', time() + ($expiresInHours * 3600)) : null;

        $id = Database::insert(
            'INSERT INTO notifications (user_id, type, title, message, action_url, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $type, $title, $message, $actionUrl, $expiresAt]
        );

        return (int)$id;
    }

    public static function getUnread(int $userId): array
    {
        return Database::fetchAll(
            'SELECT * FROM notifications
             WHERE user_id = ? AND read_at IS NULL
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC',
            [$userId]
        );
    }

    public static function getAll(int $userId, int $limit = 50): array
    {
        return Database::fetchAll(
            'SELECT * FROM notifications
             WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public static function getUnreadCount(int $userId): int
    {
        return (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND read_at IS NULL
             AND (expires_at IS NULL OR expires_at > NOW())',
            [$userId]
        );
    }

    public static function markAsRead(int $notificationId, int $userId): bool
    {
        Database::query(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
        return true;
    }

    public static function markAllAsRead(int $userId): bool
    {
        Database::query(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
        return true;
    }

    public static function delete(int $notificationId, int $userId): bool
    {
        Database::query(
            'DELETE FROM notifications WHERE id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
        return true;
    }

    public static function deleteExpired(): int
    {
        Database::query('DELETE FROM notifications WHERE expires_at < NOW()');
        return Database::getInstance()->lastInsertId();
    }

    public static function sendEmail(
        int $userId,
        string $subject,
        string $htmlBody,
        string $plainBody = ''
    ): array {
        $user = Database::fetchOne('SELECT email FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }

        return EmailManager::send(
            $user['email'],
            $subject,
            $htmlBody,
            $plainBody,
            [],
            [
                'email_provider' => getSetting('email_provider', 'smtp'),
                'from_email' => getSetting('email_from', 'noreply@example.com'),
                'from_name' => getSetting('site_name', 'PDF Viewer'),
            ]
        );
    }
}
