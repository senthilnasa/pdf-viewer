<?php
/**
 * Task Scheduler
 * PDF Viewer Platform
 *
 * Framework for scheduling and executing background tasks.
 * Uses database queue for job storage and cron-based execution.
 */

class Scheduler
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Enqueue a task for execution
     */
    public static function enqueue(
        string $taskName,
        array $payload = [],
        ?string $scheduledFor = null
    ): int {
        $id = Database::insert(
            'INSERT INTO scheduled_tasks (task_name, payload, scheduled_for, status, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [
                $taskName,
                json_encode($payload),
                $scheduledFor,
                self::STATUS_PENDING,
            ]
        );
        return (int)$id;
    }

    /**
     * Get pending tasks ready to run
     */
    public static function getPendingTasks(int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT * FROM scheduled_tasks
             WHERE status = ? AND (scheduled_for IS NULL OR scheduled_for <= NOW())
             ORDER BY created_at ASC
             LIMIT ?',
            [self::STATUS_PENDING, $limit]
        );
    }

    /**
     * Mark task as running
     */
    /**
     * Atomically claim a pending task. Returns false if another worker (an
     * overlapping cron run) already claimed it — previously both workers ran
     * the same task, e.g. sending the same email twice.
     */
    public static function markRunning(int $taskId): bool
    {
        $stmt = Database::query(
            'UPDATE scheduled_tasks SET status = ?, started_at = NOW() WHERE id = ? AND status = ?',
            [self::STATUS_RUNNING, $taskId, self::STATUS_PENDING]
        );
        return $stmt->rowCount() === 1;
    }

    /**
     * Put tasks that have been "running" for too long (worker crashed /
     * container restarted mid-task) back in the queue.
     */
    public static function recoverStaleTasks(int $minutes = 30): int
    {
        return Database::query(
            'UPDATE scheduled_tasks SET status = ?, started_at = NULL
             WHERE status = ? AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [self::STATUS_PENDING, self::STATUS_RUNNING, $minutes]
        )->rowCount();
    }

    /**
     * Mark task as completed
     */
    public static function markCompleted(int $taskId, string $result = ''): void
    {
        Database::query(
            'UPDATE scheduled_tasks SET status = ?, completed_at = NOW(), result = ? WHERE id = ?',
            [self::STATUS_COMPLETED, $result !== '' ? $result : null, $taskId] // JSON column: '' is invalid
        );
    }

    /**
     * Mark task as failed
     */
    public static function markFailed(int $taskId, string $error): void
    {
        Database::query(
            'UPDATE scheduled_tasks SET status = ?, failed_at = NOW(), error = ? WHERE id = ?',
            [self::STATUS_FAILED, $error, $taskId]
        );
    }

    /**
     * Cleanup old completed tasks (default: 30 days)
     */
    public static function cleanupOldTasks(int $daysOld = 30): int
    {
        // Failed tasks have completed_at = NULL, so use failed_at for them;
        // return the number of deleted rows (was lastInsertId(), always "0").
        return Database::query(
            'DELETE FROM scheduled_tasks
             WHERE status IN (?, ?) AND COALESCE(completed_at, failed_at) < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [self::STATUS_COMPLETED, self::STATUS_FAILED, $daysOld]
        )->rowCount();
    }

    /**
     * Register a task executor
     */
    public static function registerExecutor(string $taskName, callable $executor): void
    {
        // Store in a registry (could be expanded to use a more sophisticated system)
        if (!isset($GLOBALS['task_executors'])) {
            $GLOBALS['task_executors'] = [];
        }
        $GLOBALS['task_executors'][$taskName] = $executor;
    }

    /**
     * Execute a registered task
     */
    public static function executeTask(string $taskName, array $payload): array
    {
        if (!isset($GLOBALS['task_executors'][$taskName])) {
            return ['success' => false, 'error' => "No executor registered for task: {$taskName}"];
        }

        try {
            $executor = $GLOBALS['task_executors'][$taskName];
            $result = $executor($payload);
            return ['success' => true, 'result' => $result];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process queue - called by cron
     * Example cron: every 5 minutes via crontab
     * 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /var/www/html/includes/process-queue.php
     */
    public static function processQueue(int $limit = 10): array
    {
        $results = [];
        self::recoverStaleTasks();
        $tasks = self::getPendingTasks($limit);

        foreach ($tasks as $task) {
            if (!self::markRunning((int)$task['id'])) {
                continue; // claimed by another worker
            }
            $results[] = self::processTask($task);
        }

        return $results;
    }

    private static function processTask(array $task): array
    {
        try {
            $payload = json_decode($task['payload'], true) ?? [];
            $execution = self::executeTask($task['task_name'], $payload);

            if ($execution['success']) {
                self::markCompleted($task['id'], json_encode($execution['result'] ?? []));
                return ['id' => $task['id'], 'status' => 'completed'];
            } else {
                self::markFailed($task['id'], $execution['error']);
                return ['id' => $task['id'], 'status' => 'failed', 'error' => $execution['error']];
            }
        } catch (Throwable $e) {
            self::markFailed($task['id'], $e->getMessage());
            return ['id' => $task['id'], 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
}

/**
 * Built-in task: Send pending emails
 */
Scheduler::registerExecutor('send_email', function (array $payload) {
    $emailId = $payload['email_id'] ?? 0;
    if (!$emailId) {
        throw new Exception('Missing email_id');
    }

    $email = Database::fetchOne('SELECT * FROM email_queue WHERE id = ?', [$emailId]);
    if (!$email) {
        throw new Exception('Email not found');
    }

    if ($email['status'] === 'sent') {
        return ['skipped' => 'already sent']; // idempotent on retries
    }

    // Use the SMTP settings from Settings (was an empty config → mail() from
    // noreply@example.com), and tolerate a NULL plain_body (TypeError before).
    $result = EmailManager::send(
        $email['to_email'],
        $email['subject'],
        $email['html_body'],
        $email['plain_body'] ?? '',
        [],
        emailProviderConfig()
    );

    if ($result['success']) {
        Database::query(
            'UPDATE email_queue SET status = ?, sent_at = NOW() WHERE id = ?',
            ['sent', $emailId]
        );
        return $result;
    } else {
        $attempts = (int)$email['attempts'] + 1;
        $error = $result['message'] ?? 'Unknown error';
        $giveUp = $attempts >= 3;
        Database::query(
            'UPDATE email_queue SET status = ?, attempts = ?, last_attempt_at = NOW(), error_message = ? WHERE id = ?',
            [$giveUp ? 'failed' : 'pending', $attempts, $error, $emailId]
        );
        if (!$giveUp) {
            // Actually schedule the retry (backoff 5, 10 min) — the row used to
            // stay "pending" with no task that would ever pick it up again.
            Scheduler::enqueue('send_email', ['email_id' => $emailId], date('Y-m-d H:i:s', time() + 300 * $attempts));
        }
        throw new Exception($error);
    }
});

/**
 * Built-in task: Cleanup expired notifications
 */
Scheduler::registerExecutor('cleanup_notifications', function (array $payload) {
    $count = Database::query('DELETE FROM notifications WHERE expires_at < NOW()')->rowCount();
    return ['deleted' => $count];
});

/**
 * Built-in task: Cleanup expired invitations
 */
Scheduler::registerExecutor('cleanup_invitations', function (array $payload) {
    $count = Database::query(
        "DELETE FROM users WHERE status = ?
           AND ((invite_expires IS NOT NULL AND invite_expires < NOW())
             OR (invite_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)))",
        ['invited']
    )->rowCount();
    return ['deleted' => $count];
});
