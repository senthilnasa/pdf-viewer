<?php
/**
 * Email Provider Abstraction Layer
 * PDF Viewer Platform
 *
 * Supports multiple email providers via configuration.
 * All applications should depend on EmailProviderInterface, not concrete implementations.
 */

interface EmailProviderInterface
{
    /**
     * Send an email
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array;

    /**
     * Check if provider is configured and working
     */
    public function isHealthy(): bool;
}

/**
 * SMTP Provider — Generic SMTP/TLS/SSL support
 */
class SMTPEmailProvider implements EmailProviderInterface
{
    private array $config;
    private ?object $connection = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array {
        try {
            $from = $this->config['from_email'] ?? 'noreply@example.com';
            $fromName = $this->config['from_name'] ?? 'PDF Viewer';

            $boundary = md5(time());
            $headers = "From: {$fromName} <{$from}>\r\n";
            $headers .= "Reply-To: {$from}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "X-Mailer: PDF Viewer Platform\r\n";

            if (isset($options['cc'])) {
                $headers .= "Cc: {$options['cc']}\r\n";
            }

            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= ($plainTextBody ?: strip_tags($htmlBody)) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $success = mail($to, $subject, $body, $headers);

            return [
                'success' => $success,
                'message' => $success ? 'Email sent successfully' : 'Failed to send email',
                'provider' => 'smtp',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'provider' => 'smtp',
            ];
        }
    }

    public function isHealthy(): bool
    {
        return !empty($this->config['from_email']);
    }
}

/**
 * Null Provider — Logs emails without sending
 * Useful for testing/demo environments
 */
class NullEmailProvider implements EmailProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array {
        $logFile = $this->config['log_file'] ?? sys_get_temp_dir() . '/emails.log';
        $entry = sprintf(
            "[%s] TO: %s | SUBJECT: %s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject
        );
        @file_put_contents($logFile, $entry, FILE_APPEND);

        return [
            'success' => true,
            'message' => 'Email logged (null provider)',
            'provider' => 'null',
            'logged_to' => $logFile,
        ];
    }

    public function isHealthy(): bool
    {
        return true;
    }
}

/**
 * Email Manager Factory
 */
class EmailManager
{
    private static ?EmailProviderInterface $provider = null;

    public static function setProvider(EmailProviderInterface $provider): void
    {
        self::$provider = $provider;
    }

    public static function getProvider(array $config): EmailProviderInterface
    {
        if (self::$provider) {
            return self::$provider;
        }

        $providerType = $config['email_provider'] ?? 'smtp';

        return match ($providerType) {
            'null' => new NullEmailProvider($config),
            default => new SMTPEmailProvider($config),
        };
    }

    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = [],
        array $config = []
    ): array {
        $provider = self::getProvider($config);
        return $provider->send($to, $subject, $htmlBody, $plainTextBody, $options);
    }
}
