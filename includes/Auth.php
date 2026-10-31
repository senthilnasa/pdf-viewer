<?php
/**
 * Authentication class
 * Handles local login, Google OAuth, sessions, rate limiting, and password reset.
 */

class Auth
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_strict_mode', '1');
            if ($this->config['base_url'] !== '' && str_starts_with($this->config['base_url'], 'https')) {
                ini_set('session.cookie_secure', '1');
            }
            session_name('pdfv_sess');
            session_start();
        }
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    public function currentUser(): ?array
    {
        if (!$this->isLoggedIn()) return null;
        // fetchOne() returns false for a deactivated/deleted user; returning
        // that from a ?array method threw a TypeError (500 on every page).
        return Database::fetchOne('SELECT * FROM users WHERE id = ? AND status = ?', [$_SESSION['user_id'], 'active']) ?: null;
    }

    public function requireLogin(string $redirect = ''): void
    {
        $url = $redirect ?: $this->config['base_url'] . '/admin/login.php';
        if (!$this->isLoggedIn()) {
            header('Location: ' . $url);
            exit;
        }
        // A session can outlive the account (user deactivated or deleted while
        // logged in). Treat that as logged out instead of letting pages run
        // with $user === false.
        if (!$this->currentUser()) {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . $url);
            exit;
        }
    }

    public function requireRole(string $minRole): void
    {
        $this->requireLogin();
        $hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $user = $this->currentUser();
        // Always use the role from the DB (not the session copy), so role
        // changes/demotions take effect immediately.
        $userLevel = $hierarchy[$user['role'] ?? ''] ?? 0;
        $minLevel  = $hierarchy[$minRole] ?? 99;
        if ($userLevel < $minLevel) {
            http_response_code(403);
            $errorMessage = 'You don\'t have sufficient permissions to access this page.';
            if (defined('ROOT') && file_exists(ROOT . '/errors/403.php')) {
                include ROOT . '/errors/403.php';
            } else {
                echo '<h1>403 — Access Denied</h1><p>' . htmlspecialchars($errorMessage) . '</p>';
            }
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Local authentication
    // -------------------------------------------------------------------------

    public function login(string $email, string $password, string $ip): array
    {
        // Rate limit check
        if ($this->isRateLimited($ip, $email)) {
            return ['success' => false, 'error' => 'Too many login attempts. Please try again later.'];
        }

        $user = Database::fetchOne('SELECT * FROM users WHERE email = ? AND status = ?', [$email, 'active']);

        if (!$user || !$user['password'] || !password_verify($password, $user['password'])) {
            $this->recordFailedAttempt($ip, $email);
            // Log failed attempt
            if ($user) {
                AuditLog::log(AuditLog::ACTION_LOGIN_FAILED, $user['id'], '', 0, [], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            }
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Successful login
        $this->clearFailedAttempts($ip, $email);
        $this->createUserSession($user);
        Database::query('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

        // Log successful login
        AuditLog::log(AuditLog::ACTION_LOGIN, $user['id'], '', 0, [], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');

        return ['success' => true, 'user' => $user];
    }

    public function logout(): void
    {
        // Log logout action
        if (isset($_SESSION['user_id'])) {
            AuditLog::log(AuditLog::ACTION_LOGOUT, $_SESSION['user_id'], '', 0);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // -------------------------------------------------------------------------
    // Google OAuth
    // -------------------------------------------------------------------------

    public function getGoogleAuthUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $params = http_build_query([
            'client_id'     => $this->config['google_oauth_client_id'],
            'redirect_uri'  => $this->config['google_oauth_redirect_uri'],
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public function handleGoogleCallback(string $code, string $state): array
    {
        if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $state) {
            return ['success' => false, 'error' => 'Invalid OAuth state.'];
        }
        unset($_SESSION['oauth_state']);

        // Exchange code for token
        $tokenData = $this->googleExchangeCode($code);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            return ['success' => false, 'error' => 'Failed to obtain access token.'];
        }

        // Get user info
        $userInfo = $this->googleGetUserInfo($tokenData['access_token']);
        if (!$userInfo || !isset($userInfo['email'], $userInfo['sub'])) {
            return ['success' => false, 'error' => 'Failed to obtain user information.'];
        }

        // Only trust Google-verified addresses — accounts are matched by email.
        $emailVerified = $userInfo['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            return ['success' => false, 'error' => 'Your Google email address is not verified.'];
        }

        // Domain restriction
        $allowedDomains = array_map('strtolower', (array)($this->config['google_allowed_domains'] ?? []));
        if (!empty($allowedDomains)) {
            $domain = strtolower(substr(strrchr($userInfo['email'], '@'), 1));
            if (!in_array($domain, $allowedDomains, true)) {
                return ['success' => false, 'error' => 'Your email domain is not allowed.'];
            }
        }

        // Upsert user
        $existing = Database::fetchOne('SELECT * FROM users WHERE google_id = ? OR email = ?', [$userInfo['sub'], $userInfo['email']]);

        if (!$existing) {
            return ['success' => false, 'error' => 'No account found for this email address. Please contact an administrator to get access.'];
        }

        // Check status BEFORE touching the row, so inactive/invited accounts
        // are not silently linked to a Google identity.
        if ($existing['status'] !== 'active') {
            return ['success' => false, 'error' => 'Your account is inactive.'];
        }

        // auth_provider is left unchanged: flipping it to "google" used to
        // lock local-password users out of the "forgot password" flow.
        Database::query('UPDATE users SET google_id = ?, avatar = ?, last_login = NOW() WHERE id = ?', [
            $userInfo['sub'], $userInfo['picture'] ?? null, $existing['id'],
        ]);
        $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$existing['id']]);

        $this->createUserSession($user);
        return ['success' => true, 'user' => $user];
    }

    private function googleExchangeCode(string $code): ?array
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => $this->config['google_oauth_client_id'],
                'client_secret' => $this->config['google_oauth_client_secret'],
                'redirect_uri'  => $this->config['google_oauth_redirect_uri'],
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? json_decode($resp, true) : null;
    }

    private function googleGetUserInfo(string $accessToken): ?array
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? json_decode($resp, true) : null;
    }

    // -------------------------------------------------------------------------
    // Password reset
    // -------------------------------------------------------------------------

    public function generateResetToken(string $email): ?string
    {
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE email = ? AND auth_provider = ? AND status = ?',
            [$email, 'local', 'active']
        );
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        Database::query(
            'UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?',
            [$token, $user['id']]
        );

        // Send reset email
        $this->sendPasswordResetEmail($user, $token);

        // Log action
        AuditLog::log(AuditLog::ACTION_PASSWORD_RESET, $user['id'], 'user', $user['id']);

        return $token;
    }

    private function sendPasswordResetEmail(array $user, string $token): void
    {
        try {
            $resetLink = $this->config['base_url'] . '/admin/forgot-password.php?step=reset&token=' . $token;
            $emailData = EmailTemplate::passwordResetEmail($user, $resetLink, getSetting('site_name', 'PDF Viewer'));

            EmailManager::send(
                $user['email'],
                $emailData['subject'],
                $emailData['html'],
                $emailData['plain'],
                [],
                emailProviderConfig()
            );
        } catch (Throwable $e) {
            error_log('Failed to send password reset email: ' . $e->getMessage());
        }
    }

    public function validateResetToken(string $token): array|false
    {
        return Database::fetchOne(
            'SELECT * FROM users WHERE reset_token = ? AND reset_expires > NOW() AND status = ?',
            [$token, 'active']
        );
    }

    public function resetPassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        Database::query(
            'UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?',
            [$hash, $userId]
        );
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    private function isRateLimited(string $ip, string $email): bool
    {
        $window = (int)($this->config['login_rate_window'] ?? 900);
        $limit  = (int)($this->config['login_rate_limit'] ?? 5);
        // Per IP, and per target account (so rotating IPs can't brute-force one
        // account). The per-account cap is more generous to limit lockout abuse.
        $byIp = (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$ip, $window]
        );
        $byEmail = (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$email, $window]
        );
        return $byIp >= $limit || $byEmail >= $limit * 3;
    }

    private function recordFailedAttempt(string $ip, string $email): void
    {
        Database::query('INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)', [$ip, $email]);
    }

    private function clearFailedAttempts(string $ip, string $email): void
    {
        Database::query('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?', [$ip, $email]);
    }

    // -------------------------------------------------------------------------
    // CSRF
    // -------------------------------------------------------------------------

    /**
     * Per-session synchronizer token with a SLIDING expiry.
     *
     * Previously the token hard-expired csrf_token_lifetime (1h) after it was
     * first issued and was then rotated, so any form left open for an hour —
     * typically the PDF upload page — failed with "403 Invalid CSRF token",
     * and every other open tab broke at the same moment. Now the expiry is
     * pushed forward whenever a page renders a token or one is validated.
     */
    public function generateCsrfToken(): string
    {
        $lifetime = max(60, (int)($this->config['csrf_token_lifetime'] ?? 3600));
        if (empty($_SESSION['csrf_token']) || ($_SESSION['csrf_expires'] ?? 0) < time()) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $_SESSION['csrf_expires'] = time() + max($lifetime, (int)($this->config['session_lifetime'] ?? 0));
        return $_SESSION['csrf_token'];
    }

    public function validateCsrfToken(string $token): bool
    {
        $ok = isset($_SESSION['csrf_token'])
            && $token !== ''
            && hash_equals($_SESSION['csrf_token'], $token)
            && ($_SESSION['csrf_expires'] ?? 0) >= time();
        if ($ok) {
            $this->generateCsrfToken(); // slide the expiry
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUserSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email']= $user['email'];
        $_SESSION['login_time']= time();
    }
}
