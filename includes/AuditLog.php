<?php
/**
 * Audit Logging System
 * PDF Viewer Platform
 *
 * Tracks all significant user actions for compliance and security.
 */

class AuditLog
{
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_LOGIN_FAILED = 'login_failed';
    public const ACTION_PASSWORD_CHANGED = 'password_changed';
    public const ACTION_PASSWORD_RESET = 'password_reset';
    public const ACTION_EMAIL_CHANGED = 'email_changed';
    public const ACTION_PROFILE_UPDATED = 'profile_updated';
    public const ACTION_USER_CREATED = 'user_created';
    public const ACTION_USER_DELETED = 'user_deleted';
    public const ACTION_USER_ROLE_CHANGED = 'user_role_changed';
    public const ACTION_USER_ACTIVATED = 'user_activated';
    public const ACTION_USER_DEACTIVATED = 'user_deactivated';
    public const ACTION_INVITATION_SENT = 'invitation_sent';
    public const ACTION_INVITATION_ACCEPTED = 'invitation_accepted';
    public const ACTION_INVITATION_CANCELLED = 'invitation_cancelled';
    public const ACTION_PDF_UPLOADED = 'pdf_uploaded';
    public const ACTION_PDF_UPDATED = 'pdf_updated';
    public const ACTION_PDF_DELETED = 'pdf_deleted';
    public const ACTION_PDF_DOWNLOADED = 'pdf_downloaded';
    public const ACTION_SHARE_LINK_CREATED = 'share_link_created';
    public const ACTION_SHARE_LINK_DELETED = 'share_link_deleted';
    public const ACTION_SETTINGS_CHANGED = 'settings_changed';
    public const ACTION_PERMISSION_DENIED = 'permission_denied';

    public static function log(
        string $action,
        int $userId,
        string $resourceType = '',
        int $resourceId = 0,
        array $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            $ip = $ip ?? self::getClientIp();
            $userAgent = $userAgent ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

            Database::query(
                'INSERT INTO audit_logs (action, user_id, resource_type, resource_id, metadata, ip_address, user_agent, logged_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $action,
                    $userId,
                    $resourceType,
                    $resourceId,
                    !empty($metadata) ? json_encode($metadata) : null,
                    $ip,
                    $userAgent,
                ]
            );
        } catch (Throwable $e) {
            error_log("AuditLog::log failed: " . $e->getMessage());
        }
    }

    public static function getForUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        return Database::fetchAll(
            'SELECT * FROM audit_logs WHERE user_id = ? ORDER BY logged_at DESC LIMIT ? OFFSET ?',
            [$userId, $limit, $offset]
        );
    }

    public static function getForResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        return Database::fetchAll(
            'SELECT al.*, u.name, u.email FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.resource_type = ? AND al.resource_id = ?
             ORDER BY al.logged_at DESC
             LIMIT ?',
            [$resourceType, $resourceId, $limit]
        );
    }

    public static function getRecentActions(int $hours = 24, int $limit = 100): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        return Database::fetchAll(
            'SELECT al.*, u.name, u.email FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.logged_at >= ?
             ORDER BY al.logged_at DESC
             LIMIT ?',
            [$since, $limit]
        );
    }

    public static function exportCsv(array $filters = []): string
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['days'])) {
            $days = (int)$filters['days'];
            $where[] = 'logged_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $rows = Database::fetchAll(
            'SELECT al.*, u.name, u.email FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY al.logged_at DESC',
            $params
        );

        $csv = "Timestamp,User,Email,Action,Resource,Resource ID,IP Address,Metadata\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%d,%s,%s\n",
                $row['logged_at'],
                $row['name'] ?? 'Unknown',
                $row['email'] ?? '',
                $row['action'],
                $row['resource_type'],
                $row['resource_id'],
                $row['ip_address'],
                $row['metadata'] ?? ''
            );
        }
        return $csv;
    }

    private static function getClientIp(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
