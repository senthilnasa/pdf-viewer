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
        return Database::fetchOne('SELECT * FROM users WHERE id = ? AND status = ?', [$_SESSION['user_id'], 'active']);
    }

    public function requireLogin(string $redirect = ''): void
    {
        if (!$this->isLoggedIn()) {
            $url = $redirect ?: $this->config['base_url'] . '/admin/login.php';
            header('Location: ' . $url);
            exit;
        }
    }

    public function requireRole(string $minRole): void
    {
        $this->requireLogin();
        $hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $user = $this->currentUser();
        $userLevel = $hierarchy[$user['role']] ?? 0;
        $minLevel  = $hierarchy[$minRole] ?? 99;
        if ($userLevel < $minLevel) {
            http_response_code(403);
            die('Access denied.');
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
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Successful login
        $this->clearFailedAttempts($ip, $email);
        $this->createUserSession($user);
        Database::query('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

        return ['success' => true, 'user' => $user];
    }

    public function logout(): void
    {
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
        if (!$userInfo || !isset($userInfo['email'])) {
            return ['success' => false, 'error' => 'Failed to obtain user information.'];
        }

        // Domain restriction
        $allowedDomains = $this->config['google_allowed_domains'] ?? [];
        if (!empty($allowedDomains)) {
            $domain = substr(strrchr($userInfo['email'], '@'), 1);
            if (!in_array($domain, $allowedDomains, true)) {
                return ['success' => false, 'error' => 'Your email domain is not allowed.'];
            }
        }

        // Upsert user
        $existing = Database::fetchOne('SELECT * FROM users WHERE google_id = ? OR email = ?', [$userInfo['sub'], $userInfo['email']]);

        if ($existing) {
            Database::query('UPDATE users SET google_id = ?, avatar = ?, auth_provider = ?, last_login = NOW() WHERE id = ?', [
                $userInfo['sub'], $userInfo['picture'] ?? null, 'google', $existing['id'],
            ]);
            $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$existing['id']]);
        } else {
            $id = Database::insert(
                'INSERT INTO users (name, email, google_id, avatar, role, auth_provider, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userInfo['name'], $userInfo['email'], $userInfo['sub'], $userInfo['picture'] ?? null, 'viewer', 'google', 'active']
            );
            $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        }

        if ($user['status'] !== 'active') {
            return ['success' => false, 'error' => 'Your account is inactive.'];
        }

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
        $user = Database::fetchOne('SELECT id FROM users WHERE email = ? AND auth_provider = ?', [$email, 'local']);
        if (!$user) return null;

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        Database::query('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?', [$token, $expires, $user['id']]);

        return $token;
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
        $window  = date('Y-m-d H:i:s', time() - $this->config['login_rate_window']);
        $count   = (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > ?',
            [$ip, $window]
        );
        return $count >= $this->config['login_rate_limit'];
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

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || ($_SESSION['csrf_expires'] ?? 0) < time()) {
            $_SESSION['csrf_token']   = bin2hex(random_bytes(32));
            $_SESSION['csrf_expires'] = time() + $this->config['csrf_token_lifetime'];
        }
        return $_SESSION['csrf_token'];
    }

    public function validateCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token)
            && ($_SESSION['csrf_expires'] ?? 0) >= time();
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
