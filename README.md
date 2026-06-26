# SEMAS — Student Event Management & Announcement System
University of Kigali · PHP / MySQL implementation

This is a real, runnable PHP 8 + MySQL codebase implementing the core SEMAS
features end-to-end: authentication with email verification, OTP, password
reset, HMAC-signed/encrypted QR attendance with GPS validation, role-based
dashboards (Administrator / Dean / HOD / Student), announcements with real
email + SMS delivery, an AI-style notification generator, and PDF/Excel
attendance exports.

**It has not been executed in the environment that wrote it** (no PHP
interpreter was available there, and there is no internet access to fetch
Composer packages or send real mail/SMS). Treat this as a careful first
implementation to run, test, and debug in your own XAMPP/LAMP environment —
not as something already verified working end-to-end.

## 1. Requirements

- PHP 7.2 or newer (this codebase avoids PHP 8-only syntax — arrow functions,
  union return types, `str_starts_with()`, typed properties, etc. — so it
  also runs on PHP 7.2–7.4, which is what many existing XAMPP installs ship
  with, not just PHP 8+)
- PHP extensions: `pdo_mysql`, `openssl`, `curl`, `mbstring`, `gd`
- MySQL 8 or MariaDB 10.4+
- Composer (to install PHPMailer, DomPDF, PhpSpreadsheet)
- A real SMTP account (Gmail App Password, Outlook, or your university mail server)
- (Optional) Africa's Talking or Twilio account for real SMS

## 2. Install

```bash
cd semas-php
composer install                      # fetches PHPMailer, DomPDF, PhpSpreadsheet
cp .env.example .env                   # then edit .env with your real credentials
mysql -u root -p < database/schema.sql # creates the `semas` database and tables
```

Point your web server's document root at the `public/` folder (XAMPP: put
the whole `semas-php` folder under `htdocs/`, then your `APP_URL` in `.env`
should be `http://localhost/semas-php/public`).

Visit `APP_URL/install.php` once in your browser to create the first
Administrator account (this generates a real bcrypt hash via PHP's own
`password_hash()` — there is no hard-coded password anywhere in this
codebase). **Delete `public/install.php` immediately afterward.**

## 3. Configure real email (.env)

Pick ONE block in `.env`:
- **Gmail**: enable 2-Step Verification on the Google account, then create an
  "App Password" (Google Account → Security → App passwords) and use that as
  `MAIL_PASSWORD`, not the normal login password.
- **Outlook/Office365**: `smtp.office365.com`, port 587, TLS.
- **University mail server**: ask your IT/mail administrator for the SMTP
  host, port, and whether it needs TLS or SSL.

Until you do this, `Mailer::send()` will log a `Failed` row to `email_logs`
with the real error message — it will not pretend to succeed.

## 4. Configure real SMS (.env)

Set `SMS_PROVIDER=africastalking` (with `AT_USERNAME` / `AT_API_KEY`) or
`SMS_PROVIDER=twilio` (with `TWILIO_SID` / `TWILIO_TOKEN` / `TWILIO_FROM_NUMBER`).
Same logging behavior as email — failures land in `sms_logs` with the
provider's real error response, nothing is faked.

## 5. GPS campus boundary

Default center/radius live in the `system_settings` table
(`campus_latitude`, `campus_longitude`, `campus_radius_meters`). Update these
to the real coordinates of your campus, or set per-event `latitude`/`longitude`
when creating an event (Section 4.6 of the project report explains the
Haversine-distance check in `includes/GpsService.php`).

## 6. What's implemented vs. what's a starting point

**Implemented (real logic, not placeholders):**
- Registration, bcrypt password hashing, email verification with expiring tokens
- Login, password reset (link AND OTP paths), OTP generation/hashing/expiry/attempt-limiting
- HMAC-signed + AES-256 encrypted QR payloads with expiry (`QrService.php`)
- Haversine GPS distance validation against a configurable campus radius (`GpsService.php`)
- Anti-duplicate attendance via a database UNIQUE constraint, not just app logic
- Role-based dashboards and route guards (`Auth::requireRole()`) for all four roles
- Real PHPMailer SMTP integration with 9 HTML templates (one per email type requested)
- Real Africa's Talking / Twilio SMS integration over HTTP/cURL
- PDF export (DomPDF) and Excel export (PhpSpreadsheet) sharing one filtered query
- Full audit logging (`audit_logs`) for logins, role changes, exports, attendance denials, etc.
- The AI notification generator (rules, schema, categories) you supplied, as real PHP logic

**Left as a clearly-marked starting point** (the spec was very large; these
need a bit more of your own work, not because they're hard, just because of
time):
- The Dean dashboard's "attendance trends" charts (data is queried; charting
  library not wired in)
- Student self-registration for individual events (the `event_registrations`
  table and emails exist; build the "Register" button/page on top of them)
- A full notification "bell" UI for the `notifications` table (rows are
  created correctly; only a basic feed view exists)
- Rate-limiting login/OTP attempts by IP, beyond the per-OTP attempt counter

## 7. Security notes

- All SQL goes through PDO prepared statements.
- CSRF tokens are required on every state-changing form (`csrf_field()` / `csrf_verify()`).
- Passwords are hashed with bcrypt (`PASSWORD_BCRYPT`), never stored or logged in plaintext.
- OTP codes are hashed (bcrypt) before storage — the plaintext only ever exists
  in memory long enough to send it.
- `.env` is git-ignored by convention — never commit real credentials.

## 8. Folder structure

```
config/         configuration loader (.env → constants)
database/       schema.sql (run once to create all tables)
includes/       Database, Auth, Otp, QrService, GpsService, Mailer, Sms,
                 NotificationGenerator, AuditLog, ReportQuery, ReportScope
templates/emails/  HTML email templates (one file per email type)
public/         the actual web app (point your server here)
  auth/         register, login, OTP, verify-email, forgot/reset password
  admin/        events, announcements, AI generator, QR display, users
  hod/          department-scoped student management
  reports/      filtered PDF/Excel export
  api/          checkin.php — the QR+GPS attendance endpoint
  student/      scan.php (camera+GPS), my-qr.php (personal QR)
```

## 9. Feature Increment: Profile, Sessions, Notifications, Suggestions, Capacity, QR Security

This adds the following on top of everything above, **without changing any existing table or removing
any feature** — run `database/migration_002.sql` once against your existing `semas` database first:

```
mysql -u root -p semas < database/migration_002.sql
```

**New, fully wired:**
- `public/profile.php` — every role can view their own info and edit phone/session/password/photo;
  email changes go through a confirmation link sent to the *new* address (`email_change_requests` table)
- `public/admin/users.php` — now supports full edit (name/email/phone/session/department/role for
  Administrators; contact-only for Dean/HOD), search/filter, and photo upload. Dean is scoped to their
  faculty's departments, HOD to their own department
- Real AJAX notification bell (`public/api/notifications.php` + the JS in `partials/layout_bottom.php`):
  unread count, 20s auto-refresh, mark read/unread, delete, grouped by category (Event/Announcement/
  Attendance/System)
- `includes/AudienceResolver.php` + `includes/Delivery.php` — the single place that decides who
  receives an announcement/notification/email. Every delivery path (admin announcement form, AI
  generator, event reminders) now goes through this, so the no-cross-role-leakage rule can't drift out
  of sync between modules. Supports University-wide, Specific Department, Specific Faculty, Day/Evening/
  Weekend session targeting, Staff, and Event Participants.
- `users.session_type` (Day/Evening/Weekend), editable from profile or admin edit modal
- `public/student/suggestions.php` + `public/admin/suggestions.php` — anonymous suggestion box.
  `suggestions.submitted_by_user_id` is stored for internal traceability but is never selected by any
  admin-facing query (`includes/Suggestion.php` enforces this by only exposing safe columns); students
  see their own submission history and replies on their own page, since they already know who they are
- Event capacity, waiting list (auto-promotion on cancellation), and registration deadlines
  (`public/student/events.php` — the registration UI that didn't exist before this increment)
- QR security upgrade: optional time-based rotation per event (`events.qr_rotation_seconds`,
  `public/api/qr-refresh.php`) so a photographed/screenshotted QR stops working after the configured
  interval, on top of the existing HMAC signing and expiry
- Staff-side attendance Methods 2 & 3 with mandatory preview-before-save
  (`public/admin/scan-student.php`, `public/api/admin-scan-preview.php`, `public/api/admin-scan-confirm.php`):
  scan a student's personal QR, or search by name/reg number, see their photo/department/faculty/session
  and whether they're already marked, then explicitly confirm or cancel
- `public/admin/event-participants.php` — view/search registrants, export PDF/Excel (reuses the
  existing report engine, scoped to one event), remove a participant, or mark attendance manually
- `cron/send_reminders.php` — real 24h/1h/at-start reminders via notification + email, with
  `event_reminders_sent` guaranteeing each (event, user, stage) triple only ever fires once. Schedule it
  with cron (Linux) or Task Scheduler (Windows), running every 5–10 minutes:
  `php /path/to/semas/cron/send_reminders.php`
- Dashboard widgets: today's events and pending-suggestions count (Administrator); "My Registered
  Events" with per-event attendance status (Student)

**Left as a lighter scaffold** (works, but simpler than a fully productionized version):
- Dean's suggestion-box scope currently shows all suggestions rather than being filtered to their
  faculty's departments — tighten `admin/suggestions.php`'s scope query the same way `admin/users.php`
  does if you need that
- "First Year" / "Final Year" student targeting falls back to "All Students" since there's no
  year-of-study column yet — add one and a branch in `AudienceResolver::resolve()` if you need it
- SMS reminders are not wired into `cron/send_reminders.php` (email + bell only) — `Sms::send()` is
  one line to add per stage if you want it
- The attendance time-window check in `admin-scan-confirm.php` is a fixed 30-minutes-either-side rule;
  make it configurable via `system_settings` if you want it tunable per event
