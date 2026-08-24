<?php
/**
 * Email Templates
 * PDF Viewer Platform
 */

class EmailTemplate
{
    public static function passwordResetEmail(array $user, string $resetLink, string $siteName): array
    {
        $subject = "Reset Your Password - {$siteName}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f5f5f5; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 20px 0; font-weight: 600; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; border-radius: 4px; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Password Reset Request</h1>
    </div>
    <div class="content">
        <p>Hi {$user['name']},</p>
        <p>We received a request to reset your password. Click the button below to create a new password:</p>
        <a href="{$resetLink}" class="button">Reset Password</a>
        <p style="margin-top: 30px; font-size: 14px; color: #666;">Or copy and paste this link in your browser:</p>
        <p style="word-break: break-all; font-size: 12px; background: white; padding: 12px; border-radius: 4px; border-left: 3px solid #667eea;"><code>{$resetLink}</code></p>
        <div class="warning">
            <strong>⚠️ Security Notice:</strong> This link will expire in 1 hour. If you didn't request this, ignore this email.
        </div>
        <p style="margin-top: 30px; font-size: 14px;">Questions? Contact support.</p>
    </div>
    <div class="footer">
        <p>&copy; {$siteName} — All rights reserved</p>
    </div>
</div>
</body>
</html>
HTML;

        $plain = "Hi {$user['name']},\n\nWe received a request to reset your password.\n\nClick here to reset: {$resetLink}\n\nThis link will expire in 1 hour.\n\nIf you didn't request this, ignore this email.";

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
    }

    public static function invitationEmail(array $user, string $inviteLink, string $siteName, string $inviterName = ''): array
    {
        $subject = "{$inviterName} invited you to {$siteName}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f5f5f5; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 20px 0; font-weight: 600; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        .role-badge { display: inline-block; background: #e8e8ff; color: #667eea; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-top: 10px; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>You're Invited!</h1>
    </div>
    <div class="content">
        <p>Hi {$user['name']},</p>
        <p>{$inviterName} has invited you to join <strong>{$siteName}</strong>.</p>
        <p>Role: <span class="role-badge">{$user['role']}</span></p>
        <p>Accept the invitation by clicking the button below:</p>
        <a href="{$inviteLink}" class="button">Accept Invitation</a>
        <p style="margin-top: 30px; font-size: 14px; color: #666;">Or copy and paste this link:</p>
        <p style="word-break: break-all; font-size: 12px; background: white; padding: 12px; border-radius: 4px; border-left: 3px solid #667eea;"><code>{$inviteLink}</code></p>
        <p style="margin-top: 30px; font-size: 14px;">Questions? Contact support.</p>
    </div>
    <div class="footer">
        <p>&copy; {$siteName} — All rights reserved</p>
    </div>
</div>
</body>
</html>
HTML;

        $plain = "Hi {$user['name']},\n\n{$inviterName} has invited you to join {$siteName}.\n\nRole: {$user['role']}\n\nAccept the invitation: {$inviteLink}\n\nQuestions? Contact support.";

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
    }

    public static function welcomeEmail(array $user, string $siteName): array
    {
        $subject = "Welcome to {$siteName}!";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f5f5f5; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Welcome, {$user['name']}!</h1>
    </div>
    <div class="content">
        <p>Your account on <strong>{$siteName}</strong> has been successfully created.</p>
        <p>You can now:</p>
        <ul>
            <li>Browse and view documents</li>
            <li>Manage your account settings</li>
            <li>Access shared documents via secure links</li>
        </ul>
        <p style="margin-top: 30px; font-size: 14px; color: #666;">Need help? Check out our documentation or contact support.</p>
    </div>
    <div class="footer">
        <p>&copy; {$siteName} — All rights reserved</p>
    </div>
</div>
</body>
</html>
HTML;

        $plain = "Welcome, {$user['name']}!\n\nYour account on {$siteName} has been successfully created.\n\nYou can now browse documents, manage settings, and access shared content.\n\nNeed help? Contact support.";

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
    }

    public static function emailVerificationEmail(array $user, string $verificationLink, string $siteName): array
    {
        $subject = "Verify Your Email - {$siteName}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f5f5f5; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 20px 0; font-weight: 600; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Verify Your Email</h1>
    </div>
    <div class="content">
        <p>Hi {$user['name']},</p>
        <p>Please verify your email address to complete your account setup:</p>
        <a href="{$verificationLink}" class="button">Verify Email</a>
        <p style="margin-top: 30px; font-size: 14px; color: #666;">This link will expire in 24 hours.</p>
    </div>
    <div class="footer">
        <p>&copy; {$siteName} — All rights reserved</p>
    </div>
</div>
</body>
</html>
HTML;

        $plain = "Hi {$user['name']},\n\nPlease verify your email address:\n\n{$verificationLink}\n\nThis link will expire in 24 hours.";

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
    }

    public static function loginNotificationEmail(array $user, string $siteName, string $ip, string $ua): array
    {
        $subject = "New Login - {$siteName}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f5f5f5; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .info-box { background: white; padding: 15px; border-radius: 4px; border-left: 4px solid #667eea; margin: 15px 0; font-family: monospace; font-size: 12px; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>Login Alert</h1>
    </div>
    <div class="content">
        <p>Hi {$user['name']},</p>
        <p>Someone logged into your account. If this wasn't you, please secure your account immediately.</p>
        <div class="info-box">
            <strong>Time:</strong> {$user['login_time']}<br>
            <strong>IP Address:</strong> {$ip}<br>
            <strong>Device:</strong> {$ua}
        </div>
        <p style="margin-top: 30px; font-size: 14px; color: #666;">If you didn't authorize this login, change your password immediately.</p>
    </div>
    <div class="footer">
        <p>&copy; {$siteName} — All rights reserved</p>
    </div>
</div>
</body>
</html>
HTML;

        $plain = "Hi {$user['name']},\n\nSomeone logged into your account.\n\nTime: {$user['login_time']}\nIP: {$ip}\nDevice: {$ua}\n\nIf this wasn't you, change your password immediately.";

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
    }
}
