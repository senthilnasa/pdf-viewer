<?php
/**
 * Analytics tracking and reporting
 * PDF Viewer Platform
 */

class Analytics
{
    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    public static function recordVisit(int $pdfId): void
    {
        $ip        = self::getClientIp();
        $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $referrer  = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 512);
        $sessionId = session_id() ?: null;

        Database::query(
            'INSERT INTO pdf_views (pdf_id, visitor_ip, user_agent, referrer, user_id, session_id, visit_time)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$pdfId, $ip, $ua, $referrer, $_SESSION['user_id'] ?? null, $sessionId]
        );
    }

    public static function recordPageView(int $pdfId, int $pageNumber): void
    {
        $ip        = self::getClientIp();
        $sessionId = session_id() ?: null;

        Database::query(
            'INSERT INTO pdf_page_views (pdf_id, page_number, visitor_ip, session_id, viewed_at) VALUES (?, ?, ?, ?, NOW())',
            [$pdfId, $pageNumber, $ip, $sessionId]
        );
    }

    // -------------------------------------------------------------------------
    // Dashboard summary
    // -------------------------------------------------------------------------

    public static function getDashboardStats(): array
    {
        $totalViews = (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_views');
        $uniqueVisitors = (int)Database::fetchScalar('SELECT COUNT(DISTINCT visitor_ip) FROM pdf_views');
        $todayViews = (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_views WHERE DATE(visit_time) = CURDATE()');

        $topDoc = Database::fetchOne(
            'SELECT p.title, p.slug, COUNT(v.id) AS views
             FROM pdf_views v JOIN pdf_documents p ON p.id = v.pdf_id
             GROUP BY v.pdf_id ORDER BY views DESC LIMIT 1'
        );

        return [
            'total_views'     => $totalViews,
            'unique_visitors' => $uniqueVisitors,
            'today_views'     => $todayViews,
            'top_document'    => $topDoc ?: null,
        ];
    }

    // -------------------------------------------------------------------------
    // Views per day (last N days)
    // -------------------------------------------------------------------------

    public static function getViewsPerDay(int $days = 30, ?int $pdfId = null): array
    {
        $params = [$days];
        $pdfFilter = '';
        if ($pdfId) {
            $pdfFilter = 'AND pdf_id = ?';
            $params[]  = $pdfId;
        }

        $rows = Database::fetchAll(
            "SELECT DATE(visit_time) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_ip) AS unique_visitors
             FROM pdf_views
             WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY) {$pdfFilter}
             GROUP BY DATE(visit_time)
             ORDER BY day ASC",
            $params
        );

        // Fill gaps
        $map = [];
        foreach ($rows as $row) {
            $map[$row['day']] = $row;
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = [
                'day'             => $day,
                'views'           => (int)($map[$day]['views'] ?? 0),
                'unique_visitors' => (int)($map[$day]['unique_visitors'] ?? 0),
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Views per document
    // -------------------------------------------------------------------------

    public static function getViewsPerDocument(int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT p.id, p.title, p.slug,
                    COUNT(v.id) AS total_views,
                    COUNT(DISTINCT v.visitor_ip) AS unique_visitors,
                    MAX(v.visit_time) AS last_view
             FROM pdf_documents p
             LEFT JOIN pdf_views v ON v.pdf_id = p.id
             GROUP BY p.id
             ORDER BY total_views DESC
             LIMIT ?',
            [$limit]
        );
    }

    // -------------------------------------------------------------------------
    // Page-level analytics
    // -------------------------------------------------------------------------

    public static function getPageViews(int $pdfId): array
    {
        return Database::fetchAll(
            'SELECT page_number, COUNT(*) AS views
             FROM pdf_page_views
             WHERE pdf_id = ?
             GROUP BY page_number
             ORDER BY page_number ASC',
            [$pdfId]
        );
    }

    // -------------------------------------------------------------------------
    // Document detail analytics
    // -------------------------------------------------------------------------

    public static function getDocumentAnalytics(int $pdfId, int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $total = (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_views WHERE pdf_id = ?', [$pdfId]);
        $unique = (int)Database::fetchScalar('SELECT COUNT(DISTINCT visitor_ip) FROM pdf_views WHERE pdf_id = ?', [$pdfId]);
        $period = (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM pdf_views WHERE pdf_id = ? AND visit_time >= ?',
            [$pdfId, $since]
        );

        return [
            'total_views'     => $total,
            'unique_visitors' => $unique,
            'period_views'    => $period,
            'views_per_day'   => self::getViewsPerDay($days, $pdfId),
            'page_views'      => self::getPageViews($pdfId),
        ];
    }

    // -------------------------------------------------------------------------
    // Reports / export data
    // -------------------------------------------------------------------------

    public static function getReportData(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['pdf_id'])) {
            $where[]  = 'v.pdf_id = ?';
            $params[] = (int)$filters['pdf_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'v.visit_time >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'v.visit_time <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['visitor_ip'])) {
            $where[]  = 'v.visitor_ip = ?';
            $params[] = $filters['visitor_ip'];
        }

        return Database::fetchAll(
            'SELECT p.title AS document, p.slug, v.visitor_ip, v.user_agent,
                    v.referrer, v.visit_time, v.session_id
             FROM pdf_views v
             JOIN pdf_documents p ON p.id = v.pdf_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.visit_time DESC
             LIMIT 10000',
            $params
        );
    }

    public static function getReportSummary(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['pdf_id'])) {
            $where[]  = 'v.pdf_id = ?';
            $params[] = (int)$filters['pdf_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'v.visit_time >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'v.visit_time <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        return Database::fetchAll(
            'SELECT p.title AS document, p.slug,
                    COUNT(v.id) AS views,
                    COUNT(DISTINCT v.visitor_ip) AS unique_visitors,
                    DATE(MAX(v.visit_time)) AS last_visit
             FROM pdf_documents p
             LEFT JOIN pdf_views v ON v.pdf_id = p.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id
             ORDER BY views DESC',
            $params
        );
    }

    // -------------------------------------------------------------------------
    // User statistics
    // -------------------------------------------------------------------------

    public static function getUserStats(): array
    {
        $byRole = Database::fetchAll(
            "SELECT role, COUNT(*) AS count FROM users WHERE status = 'active'
             GROUP BY role ORDER BY FIELD(role,'admin','editor','viewer')"
        );
        $total    = (int)Database::fetchScalar("SELECT COUNT(*) FROM users");
        $active   = (int)Database::fetchScalar("SELECT COUNT(*) FROM users WHERE status = 'active'");
        $inactive = $total - $active;
        return ['by_role' => $byRole, 'total' => $total, 'active' => $active, 'inactive' => $inactive];
    }

    // -------------------------------------------------------------------------
    // Hourly activity heatmap (weekday 0=Sun…6=Sat  x  hour 0-23)
    // -------------------------------------------------------------------------

    public static function getHourlyHeatmap(int $days = 30): array
    {
        $rows = Database::fetchAll(
            "SELECT (DAYOFWEEK(visit_time)-1) AS weekday,
                    HOUR(visit_time)          AS hour,
                    COUNT(*)                  AS views
             FROM pdf_views
             WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY weekday, hour",
            [$days]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['weekday']][(int)$row['hour']] = (int)$row['views'];
        }

        $result = [];
        for ($w = 0; $w < 7; $w++) {
            for ($h = 0; $h < 24; $h++) {
                $result[] = ['weekday' => $w, 'hour' => $h, 'views' => $map[$w][$h] ?? 0];
            }
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Views per hour-of-day aggregate (for area sparkline)
    // -------------------------------------------------------------------------

    public static function getViewsPerHour(int $days = 30): array
    {
        $rows = Database::fetchAll(
            "SELECT HOUR(visit_time) AS hour, COUNT(*) AS views
             FROM pdf_views
             WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY hour ORDER BY hour ASC",
            [$days]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['hour']] = (int)$r['views'];
        }
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'views' => $map[$h] ?? 0];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function getClientIp(): string
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
