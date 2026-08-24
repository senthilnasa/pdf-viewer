# PDF Viewer Platform - Implementation Guide

## New Features Implemented

This document describes the comprehensive enhancements made to the PDF Viewer Platform to make it production-ready.

### 1. Email & SMTP System ✅

**Location**: `includes/EmailProvider.php`, `includes/EmailTemplate.php`

**Features**:
- Multi-provider email abstraction (SMTP, Null for testing)
- Pre-built email templates for common workflows
- SMTP configuration via environment variables
- Support for TLS/SSL encryption
- Fallback to null provider for development/demo

**Supported Flows**:
- Password reset emails
- Invitation emails
- Welcome emails
- Email verification
- Login notification emails

**Configuration** (in `config/app.php`):
```php
'email_provider'      => 'smtp',           // 'smtp' or 'null'
'email_from'          => 'noreply@example.com',
'email_from_name'     => 'PDF Viewer',
'smtp_host'           => 'smtp.mailtrap.io',
'smtp_port'           => 587,
'smtp_username'       => 'your_username',
'smtp_password'       => 'your_password',
'smtp_encryption'     => 'tls',            // 'tls' or 'ssl'
```

### 2. Audit Logging System ✅

**Location**: `includes/AuditLog.php`

**Features**:
- Complete action audit trail
- Tracks: logins, logouts, password changes, email changes, PDF operations, etc.
- IP address logging for security
- User agent tracking
- Metadata storage (JSON)
- CSV export capability

**Tracked Actions**:
- `login` - Successful login
- `logout` - User logout
- `login_failed` - Failed login attempt
- `password_changed` - User changed their password
- `password_reset` - Password reset initiated
- `email_changed` - Email address updated
- `profile_updated` - Profile information updated
- `user_created` - New user created
- `user_deleted` - User deleted
- `user_role_changed` - User role modified
- `invitation_sent` - User invited
- `pdf_uploaded` - PDF uploaded
- `pdf_updated` - PDF metadata updated
- `pdf_deleted` - PDF deleted
- `share_link_created` - Share link created
- And more...

**API Usage**:
```php
AuditLog::log(
    AuditLog::ACTION_LOGIN,
    $userId,
    'user',
    $userId,
    [],
    $_SERVER['REMOTE_ADDR']
);
```

**Admin Access**: `/admin/audit-logs.php`

### 3. Notification System ✅

**Location**: `includes/Notification.php`

**Features**:
- In-app notifications with read/unread status
- Notification types (info, success, warning, error, security)
- Auto-expiration of old notifications
- Email notification support
- Notification preferences per user

**Types**:
- `TYPE_INFO` - Informational
- `TYPE_SUCCESS` - Success message
- `TYPE_WARNING` - Warning message
- `TYPE_ERROR` - Error message
- `TYPE_SECURITY` - Security alert

**API Usage**:
```php
Notification::create(
    $userId,
    Notification::TYPE_INFO,
    'Notification Title',
    'Notification message content',
    '/path/to/action',
    30  // expires in 30 hours
);
```

**User Access**: `/admin/notifications.php`

### 4. User Account Management ✅

**Location**: `admin/profile.php`

**Features**:
- User profile page with account information
- Change password functionality
- Email address update
- Profile information editing
- Security audit trail for all changes

**Changes Logged**:
- Profile updates
- Password changes
- Email changes
- All actions tracked in audit log

**Access**: `/admin/profile.php`

### 5. Enhanced Authentication ✅

**Improvements to** `includes/Auth.php`:

**Features**:
- Automated password reset emails
- Login attempt tracking
- Failed login logging
- Logout logging
- Session audit trail

**Email Flows**:
- Password reset link emailed to user
- Invitation emails to new team members
- Welcome emails for new accounts
- Login notifications (optional)

### 6. Storage Abstraction Layer ✅

**Location**: `includes/Storage.php`

**Features**:
- Provider-independent storage interface
- Local filesystem support (default)
- AWS S3 support (framework ready)
- Cloudflare R2 support (framework ready)
- Extensible architecture for additional providers

**Supported Operations**:
- `put()` - Store file
- `putFile()` - Store uploaded file
- `get()` - Retrieve file
- `exists()` - Check existence
- `delete()` - Remove file
- `size()` - Get file size
- `url()` - Get public URL
- `temporaryUrl()` - Generate signed URL

**Usage**:
```php
$storage = StorageFactory::getProvider($config);
$result = $storage->putFile('pdfs/document.pdf', $_FILES['pdf']);
```

### 7. Task Scheduler / Background Jobs ✅

**Location**: `includes/Scheduler.php`

**Features**:
- Database-backed task queue
- Task executor registration
- Job status tracking (pending, running, completed, failed)
- Automatic cleanup of old tasks
- Built-in task executors

**Built-in Tasks**:
- `send_email` - Send queued emails
- `cleanup_notifications` - Remove expired notifications
- `cleanup_invitations` - Remove expired invitations

**Job Management**:
- Enqueue tasks for later execution
- Schedule tasks for specific times
- Track execution status
- Automatic retry logic
- Error logging

**Usage**:
```php
Scheduler::enqueue('send_email', ['email_id' => 123]);
Scheduler::enqueue('cleanup_tasks', [], '2024-12-25 00:00:00');
```

**Cron Setup** (every 5 minutes):
```bash
*/5 * * * * cd /path/to/pdf-viewer && php -r "require 'includes/Database.php'; require 'includes/Scheduler.php'; Scheduler::processQueue();"
```

### 8. Email Queue System ✅

**Database Table**: `email_queue`

**Features**:
- Asynchronous email delivery
- Retry logic (up to 3 attempts)
- Status tracking (pending, sent, failed)
- Error message logging
- Batch processing

**Status Flow**:
1. Email created with `pending` status
2. Scheduler picks up pending emails
3. Email sent successfully → `sent` status
4. Email fails → retry up to 3 times
5. After 3 failures → `failed` status

### 9. User Sessions Management ✅

**Database Table**: `user_sessions`

**Features** (Framework Ready):
- Track active sessions per user
- IP address tracking
- User agent logging
- Session expiration tracking
- Support for logout all devices

### 10. Email Verification System ✅

**Database Table**: `email_verifications`

**Features** (Framework Ready):
- Email verification token generation
- Verification tracking
- Token expiration (24 hours)
- Support for email re-verification

### 11. User Preferences ✅

**Database Table**: `user_preferences`

**Features**:
- Per-user preference storage
- Notification preferences
- Theme preferences
- Email frequency settings

**Usage**:
```php
setSetting('user:' . $userId . ':notify_email_login', true);
```

### 12. User API Endpoints ✅

**Location**: `api/user.php`

**Endpoints**:
- `GET /api/user.php?action=profile` - Get current user profile
- `GET /api/user.php?action=notifications` - List notifications
- `GET /api/user.php?action=notifications_count` - Get unread count
- `POST /api/user.php?action=mark_notification_read` - Mark notification as read
- `POST /api/user.php?action=logout` - Logout user

**Example**:
```javascript
fetch('/api/user.php?action=profile')
  .then(r => r.json())
  .then(data => console.log(data.user));
```

### 13. Database Schema Enhancements ✅

**New Tables**:
1. `audit_logs` - Complete audit trail
2. `user_sessions` - Active session tracking
3. `email_verifications` - Email verification tokens
4. `notifications` - In-app notifications
5. `email_queue` - Email delivery queue
6. `user_preferences` - User preferences
7. `scheduled_tasks` - Background job queue

**Column Additions**:
- `users.email_verified` - Email verification flag
- `users.email_verified_at` - Email verification timestamp

### 14. Admin Features

**New Admin Pages**:
- `/admin/profile.php` - User account management
- `/admin/notifications.php` - View notifications
- `/admin/audit-logs.php` - Security audit trail

**Admin Navigation**:
All new features added to admin sidebar for easy access.

---

## Configuration Guide

### 1. Email Setup

**Option A: Mailtrap (for testing)**:
```php
'email_provider'    => 'smtp',
'smtp_host'         => 'smtp.mailtrap.io',
'smtp_port'         => 587,
'smtp_username'     => 'your_mailtrap_id',
'smtp_password'     => 'your_mailtrap_token',
'smtp_encryption'   => 'tls',
```

**Option B: Gmail SMTP**:
```php
'smtp_host'         => 'smtp.gmail.com',
'smtp_port'         => 587,
'smtp_username'     => 'your_gmail@gmail.com',
'smtp_password'     => 'your_app_password',
'smtp_encryption'   => 'tls',
```

**Option C: Development (no emails sent)**:
```php
'email_provider'    => 'null',  // Logs emails to /tmp/emails.log
```

### 2. Database Migration

Run these SQL files to add new tables:

```bash
mysql -u username -p database_name < database.sql
```

Or execute the migrations manually from `database-migrations.sql`.

### 3. Scheduler Setup

Add cron job for background processing:

```bash
# Edit crontab
crontab -e

# Add this line (run every 5 minutes):
*/5 * * * * cd /var/www/pdf-viewer && php -r "define('ROOT', '/var/www/pdf-viewer'); require 'includes/Database.php'; require 'includes/Scheduler.php'; Scheduler::processQueue();" >> /var/log/pdf-viewer-scheduler.log 2>&1
```

### 4. Storage Configuration

For cloud storage (future implementation):

```php
// S3
'storage_driver'    => 's3',
's3_key'            => 'your_aws_key',
's3_secret'         => 'your_aws_secret',
's3_bucket'         => 'pdf-viewer-bucket',
's3_region'         => 'us-east-1',

// Cloudflare R2
'storage_driver'    => 'cloudflare_r2',
'cloudflare_r2_token' => 'your_token',
'cloudflare_r2_bucket' => 'bucket-name',
'cloudflare_r2_domain' => 'domain.example.com',
```

---

## Testing

### Email Testing

Use the null provider in development:

```php
'email_provider' => 'null',
```

Emails will be logged to `/tmp/emails.log`.

### API Testing

```bash
# Get notifications
curl "http://localhost/pdf-viewer/api/user.php?action=notifications" \
  -H "Cookie: pdfv_sess=YOUR_SESSION_ID"

# Get profile
curl "http://localhost/pdf-viewer/api/user.php?action=profile" \
  -H "Cookie: pdfv_sess=YOUR_SESSION_ID"
```

---

## Security Considerations

### 1. Audit Logging
All sensitive actions are logged with IP addresses and user agents for forensic analysis.

### 2. Email Security
- Passwords and tokens are hashed
- Reset links expire in 1 hour
- Invitation links are one-time use

### 3. Database
- Foreign key constraints prevent orphaned records
- Proper indexes for performance
- JSON fields for flexible metadata

### 4. CSRF Protection
All forms include CSRF token validation.

---

## Maintenance

### Cleanup Tasks

**Expired Notifications** (runs automatically):
```php
Notification::deleteExpired();
```

**Old Audit Logs** (periodic):
```bash
# Delete audit logs older than 90 days
DELETE FROM audit_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

**Completed Tasks** (automatic via cron):
```php
Scheduler::cleanupOldTasks(30); // Delete tasks older than 30 days
```

---

## Future Enhancements

### Phase 2 (Recommended):
1. AWS S3 integration implementation
2. Cloudflare R2 implementation
3. Two-factor authentication (2FA)
4. Advanced session management UI
5. Email delivery status tracking
6. Webhook system for external integrations
7. API rate limiting per user/IP
8. Advanced search and filtering

### Phase 3:
1. Machine learning-based anomaly detection
2. Automated backup system
3. Advanced reporting engine
4. Custom notification templates
5. SMS notifications
6. Slack integration

---

## Troubleshooting

### Emails not sending?
1. Check SMTP configuration in `config/app.php`
2. Verify firewall allows outbound SMTP (port 587/465)
3. Check email provider credentials
4. Review error logs in `email_queue.error_message`
5. Test with `email_provider: 'null'` to verify system

### Scheduler not running?
1. Check cron job is installed: `crontab -l`
2. Verify database connection works
3. Check scheduler log file for errors
4. Manually test: `php -r "require 'includes/Database.php'; require 'includes/Scheduler.php'; print_r(Scheduler::processQueue());"`

### Notifications not appearing?
1. Verify `notifications` table exists
2. Check user ID is correct
3. Verify notification hasn't expired
4. Check browser console for JavaScript errors

---

## Support & Documentation

- **GitHub**: https://github.com/senthilnasa/pdf-viewer
- **Email**: Support email configured in settings
- **Issues**: https://github.com/senthilnasa/pdf-viewer/issues

---

## Version History

- **v1.1.0** - Email system, audit logging, notifications, storage abstraction, task scheduling
- **v1.0.0** - Initial release with PDF viewer, authentication, analytics

---

**Last Updated**: 2024
