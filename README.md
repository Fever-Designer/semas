# SEMAS / Student Event Management and Announcement System
UNIVERSITY · PHP / MySQL implementation

This is a real, runnable PHP 8 + MySQL codebase implementing the core SEMAS
features end-to-end: authentication with email verification, OTP, password
reset, HMAC-signed/encrypted QR attendance with GPS validation, role-based
dashboards (Administrator / Dean / HOD / Student), announcements with real
email + SMS delivery, an AI-style notification generator, and PDF/Excel
attendance exports.

**It has not been executed in the environment that wrote it** (no PHP
interpreter was available there, and there is no internet access to fetch
Composer packages or send real mail/SMS). Treat this as a careful first
implementation to run, test, and debug in your own XAMPP/LAMP environment /
not as something already verified working end-to-end.

## 1. Requirements

- PHP 7.2 or newer (this codebase avoids PHP 8-only syntax / arrow functions,
  union return types, `str_starts_with()`, typed properties, etc. / so it
  also runs on PHP 7.2/7.4, which is what many existing XAMPP installs ship
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

Create the first Principal account directly in the database or from your
trusted provisioning process. Do not keep public setup scripts in production.

## 3. Configure real email (.env)

Pick ONE block in `.env`:
- **Gmail**: enable 2-Step Verification on the Google account, then create an
  "App Password" (Google Account → Security → App passwords) and use that as
  `MAIL_PASSWORD`, not the normal login password.
- **Outlook/Office365**: `smtp.office365.com`, port 587, TLS.
- **University mail server**: ask your IT/mail administrator for the SMTP
  host, port, and whether it needs TLS or SSL.

Until you do this, `Mailer::send()` will log a `Failed` row to `email_logs`
with the real error message / it will not pretend to succeed.

## 4. Configure real SMS (.env)

Set `SMS_PROVIDER=africastalking` (with `AT_USERNAME` / `AT_API_KEY`) or
`SMS_PROVIDER=twilio` (with `TWILIO_SID` / `TWILIO_TOKEN` / `TWILIO_FROM_NUMBER`).
Same logging behavior as email / failures land in `sms_logs` with the
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
- Shared announcement category/priority options used consistently across forms

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
- OTP codes are hashed (bcrypt) before storage / the plaintext only ever exists
  in memory long enough to send it.
- `.env` is git-ignored by convention / never commit real credentials.

## 8. Folder structure

```
config/         configuration loader (.env → constants)
database/       schema.sql (run once to create all tables)
includes/       Database, Auth, Otp, QrService, GpsService, Mailer, Sms,
                 AuditLog, ReportQuery, ReportScope, shared form option helpers
templates/emails/  HTML email templates (one file per email type)
public/         the actual web app (point your server here)
  auth/         register, login, OTP, verify-email, forgot/reset password
  admin/        events, announcements, AI generator, QR display, users
  hod/          department-scoped student management
  reports/      filtered PDF/Excel export
  api/          checkin.php / the QR+GPS attendance endpoint
  student/      scan.php (camera+GPS), my-qr.php (personal QR)
```

## 9. Feature Increment: Profile, Sessions, Notifications, Suggestions, Capacity, QR Security

This adds the following on top of everything above, **without changing any existing table or removing
any feature** / run `database/migration_002.sql` once against your existing `semas` database first:

```
mysql -u root -p semas < database/migration_002.sql
```

**New, fully wired:**
- `public/profile.php` / every role can view their own info and edit phone/session/password/photo;
  email changes go through a confirmation link sent to the *new* address (`email_change_requests` table)
- `public/admin/users.php` / now supports full edit (name/email/phone/session/department/role for
  Administrators; contact-only for Dean/HOD), search/filter, and photo upload. Dean is scoped to their
  faculty's departments, HOD to their own department
- Real AJAX notification bell (`public/api/notifications.php` + the JS in `partials/layout_bottom.php`):
  unread count, 20s auto-refresh, mark read/unread, delete, grouped by category (Event/Announcement/
  Attendance/System)
- `includes/AudienceResolver.php` + `includes/Delivery.php` / the single place that decides who
  receives an announcement/notification/email. Every delivery path (admin announcement form, AI
  generator, event reminders) now goes through this, so the no-cross-role-leakage rule can't drift out
  of sync between modules. Supports University-wide, Specific Department, Specific Faculty, Day/Evening/
  Weekend session targeting, Staff, and Event Participants.
- `users.session_type` (Day/Evening/Weekend), editable from profile or admin edit modal
- `public/student/suggestions.php` + `public/admin/suggestions.php` / anonymous suggestion box.
  `suggestions.submitted_by_user_id` is stored for internal traceability but is never selected by any
  admin-facing query (`includes/Suggestion.php` enforces this by only exposing safe columns); students
  see their own submission history and replies on their own page, since they already know who they are
- Event capacity, waiting list (auto-promotion on cancellation), and registration deadlines
  (`public/student/events.php` / the registration UI that didn't exist before this increment)
- QR security upgrade: optional time-based rotation per event (`events.qr_rotation_seconds`,
  `public/api/qr-refresh.php`) so a photographed/screenshotted QR stops working after the configured
  interval, on top of the existing HMAC signing and expiry
- Staff-side attendance Methods 2 & 3 with mandatory preview-before-save
  (`public/admin/scan-student.php`, `public/api/admin-scan-preview.php`, `public/api/admin-scan-confirm.php`):
  scan a student's personal QR, or search by name/reg number, see their photo/department/faculty/session
  and whether they're already marked, then explicitly confirm or cancel
- `public/admin/event-participants.php` / view/search registrants, export PDF/Excel (reuses the
  existing report engine, scoped to one event), remove a participant, or mark attendance manually
- `cron/send_reminders.php` / real 24h/1h/at-start reminders via notification + email, with
  `event_reminders_sent` guaranteeing each (event, user, stage) triple only ever fires once. Schedule it
  with cron (Linux) or Task Scheduler (Windows), running every 5/10 minutes:
  `php /path/to/semas/cron/send_reminders.php`
- Dashboard widgets: today's events and pending-suggestions count (Administrator); "My Registered
  Events" with per-event attendance status (Student)

**Left as a lighter scaffold** (works, but simpler than a fully productionized version):
- Dean's suggestion-box scope currently shows all suggestions rather than being filtered to their
  faculty's departments / tighten `admin/suggestions.php`'s scope query the same way `admin/users.php`
  does if you need that
- "First Year" / "Final Year" student targeting falls back to "All Students" since there's no
  year-of-study column yet / add one and a branch in `AudienceResolver::resolve()` if you need it
- SMS reminders are not wired into `cron/send_reminders.php` (email + bell only) / `Sms::send()` is
  one line to add per stage if you want it
- The attendance time-window check in `admin-scan-confirm.php` is a fixed 30-minutes-either-side rule;
  make it configurable via `system_settings` if you want it tunable per event

## 10. Feature Increment: Role-Based User Management & Announcement Module + Campus Life

Run `database/migration_003.sql` once against your existing database first:

```bash
mysql -u root -p semas < database/migration_003.sql
```

This closes the gaps between the original spec and what existed before: HOD and Dean previously had
**no way to send an announcement at all**, and the Administrator had **no way to create an HOD/Dean
account** (only student self-registration existed). It also fixes a real scope bug in
`admin/users.php`: the old query restricted by `department_id` only, not by role / so a Dean whose
faculty contained an HOD's department could previously activate/deactivate/reset that HOD's password,
and an HOD had no way to see Dean accounts at all. Both are now role-aware.

**New, fully wired:**
- `includes/Announcement.php` / single place that creates an announcement row, snapshots the sender's
  full name/role/department-or-faculty *at send time* (so a later rename/role-change never rewrites
  history), fans it out via `Delivery`/`AudienceResolver`, and writes the audit log entry. Every
  send page (`admin/events.php`, `hod/announcements.php`, `dean/announcements.php`) goes through it.
- `public/hod/announcements.php` / HOD sends to their own department only: all students, by
  session (Day/Evening/Weekend), or by year of study. Drafts supported. (Increment 11 below adds
  sending to the department's Lecturers from this same page.)
- `public/dean/announcements.php` / same shape. (Increment 11 below changes this from
  faculty-scoped to university-wide / see that section; this paragraph originally described a
  faculty-scoped version that no longer exists.)
- `public/announcements/board.php` / a searchable/filterable/paginated announcement feed for every
  role, rendering the exact "UNIVERSITY / Sent by / Role / Date / Time" block from the spec
  (`public/partials/announcement_card.php`); the same partial is reused on `admin/events.php`,
  `hod/announcements.php`, and `dean/announcements.php` so the format never drifts between pages.
  The announcement email template (`templates/emails/announcement_notification.php`) shows the same
  sender block.
- `public/admin/users.php` / Administrator can create HOD or Dean accounts; HOD can additionally
  create Dean accounts (per the spec's optional hierarchy). A temporary password is generated and
  emailed (`Mailer::sendStaffAccountCreated`, `templates/emails/staff_account_created.php`); the new
  account is linked to its department (`departments.hod_user_id`) or faculty (`faculties.dean_user_id`)
  immediately so scoping works right away. (Increment 11 below adds Lecturer to the list of account
  types Administrator can create here, and changes Dean's own scope to university-wide.)
- `public/admin/audit-log.php` / searchable/filterable/paginated view of `audit_logs`, which was being
  written to since the first increment but never had a UI to read it back.
- `users.year_of_study` / lets "First Year Students" / "Final Year Students" announcement targeting
  in `AudienceResolver::resolve()` actually filter instead of falling back to All Students; also used
  by HOD/Dean's "by year" announcement scope. `Final Year` is approximated as
  `AudienceResolver::FINAL_YEAR_THRESHOLD` (default 4) since programme length varies / adjust if needed.

**Left as a lighter scaffold:**
- "By academic level" for HOD/Dean announcements reuses the same `year_of_study` column as "by year"
  rather than a separate level taxonomy (Certificate/Diploma/Bachelor's/Master's) / add a column and a
  branch in `AudienceResolver::resolveStudentsScoped()` if your institution needs that distinction.

## 11. Feature Increment: Lecturer role, Class Attendance, university-wide Dean, Analytics

Run `database/migration_004.sql` once against your existing database.

```bash
mysql -u root -p semas < database/migration_004.sql
```

**Role and scope changes:**
- **Dean is now university-wide.** A Dean sees and manages every student account regardless of
  department/faculty (`admin/users.php`'s scope logic), and `dean/announcements.php` always targets
  "All Students" (optionally filtered by session or year) instead of the Dean's faculty. Dean still
  cannot create or manage Administrator/HOD/Dean accounts / that restriction didn't change, only the
  *size* of the student population a Dean can reach did. `faculties.dean_user_id` is left in the
  schema for record-keeping (e.g. "Dean of School of Computing" on a printed slip) but no longer
  restricts anything in code.
- **HOD can now announce to Lecturers**, not just students. `hod/announcements.php` gained a
  "Send To: Students / Lecturers" switch; the Lecturers option resolves every Active lecturer whose
  `lecturers.department_id` matches the HOD's own department (`target_audience = 'Department
  Lecturers'`, a new ENUM value on `announcements`).
- **Lecturer is a new role.** `roles` gets a `Lecturer` row, and a one-row-per-user `lecturers` table
  holds department assignment plus optional `title`/`specialization` / this is intentionally a thin
  profile table (mirroring how `departments.hod_user_id`/`faculties.dean_user_id` already tie HOD/Dean
  to their scope) rather than a heavyweight HR record. Administrator creates Lecturer accounts from
  `admin/users.php`'s existing "Add Staff Account" modal (now offering HOD/Dean/Lecturer); a temp
  password is generated and emailed exactly like HOD/Dean account creation.

**Class Attendance:**
- `public/lecturer/modules.php` / a Lecturer creates their own modules (title + optional session
  type); HOD-side module assignment from the original master-prompt concept (HOD creates modules and
  assigns a lecturer) was intentionally **not** built / that's a materially bigger feature
  (timetabling, CAT/Exam scheduling, eligibility rules) than "replace Polls with Class Attendance"
  calls for. If you need it, `modules.lecturer_id` is already an FK to `lecturers`, so an HOD-facing
  "create module, pick lecturer" form is a straightforward addition on top of this.
- `public/lecturer/class-attendance.php` / start a class session (`class_sessions`, one open session
  per module at a time), display a QR code (same HMAC-signed/encrypted/expiring scheme as event QR
  codes / see `QrService::buildSessionPayload()`/`verifySessionPayload()`), search-and-confirm a
  student manually (explicit **Search** button → **Found: name (reg)** bar → **Confirm & View
  Profile** button → full profile card with photo/name/reg/department → **Confirm Attendance**), and
  a live roster that auto-refreshes every 10 seconds. "End Session" closes it.
- `public/student/class-scan.php` + `public/api/class-checkin.php` / student camera-scans the
  lecturer's QR (same flow shape as `student/scan.php` for events, minus GPS / a classroom doesn't
  need location verification the way an outdoor event does).
- Timing rule, centralized in `includes/ClassAttendance.php` so the self-scan path and the lecturer's
  manual-confirm path can never disagree: first 10 minutes of `class_sessions.start_time` → **Present**;
  10/20 minutes → **Late**; past 20 minutes → self-scan is rejected (`ClassAttendance::canSelfScan()`)
  and the student shows as **Absent** unless a lecturer manually records otherwise.
- `public/admin/scan-student.php` (event attendance, Method 3: manual search) was also tightened to
  match this same explicit flow / it previously searched-and-previewed instantly on keystroke with no
  separate "found" step; it now has a **Search** button, a **Found: name (reg)** confirmation bar, and
  only *then* the photo/name/reg profile + final **Confirm Attendance** button. Both the camera-scan
  path and the manual path show the identical profile card.

**Left as a lighter scaffold:**
- No student enrollment table for modules / any Active student whose `department_id` matches the
  module's department is eligible to scan in. If you need per-student opt-in/registration (the way
  `event_registrations` works for events), add a `module_enrollments` table and check it in
  `api/class-checkin.php` before accepting a scan.
- Class Attendance has no absence-counting/eligibility-blocking rule yet (the master-prompt's "2
  absences → AT RISK → not eligible for CAT/Exam" logic) since there's no CAT/Exam system in this
  codebase to block eligibility *for* / `class_attendance_logs` has everything needed to compute an
  absence count per student/module if you build that next.
- Analytics charts are simple aggregate counts, not drill-down/exportable reports / `reports/index.php`
  already has PDF/Excel export for event attendance if you need a downloadable version of similar data.

## 12. Feature Increment: Unified Dashboard, HOD-Centralized Academics, Module Registration

Run `database/migration_005.sql` (after `migration_004.sql`):

```bash
mysql -u root -p semas < database/migration_005.sql
```

This is a structural pivot, not just additive features. Summary of what changed and why:

**Unified Dashboard.** `public/dashboard.php` is now the single landing page for every role /
stats, quick links, and lists are now in one page. All the `/dashboard.php` redirects already hardcoded in `auth/login.php`, `auth/login-otp.php`,
and `index.php` needed no changes / the URL didn't move, only what's rendered there did.

**Administrator (Principal) scope reduced to User Management + System Config only.** Removed:
`admin/events.php`, `admin/qr.php`, `admin/event-participants.php`,
`admin/scan-student.php`, `reports/*` (all switched from `Auth::requireRole(['Administrator', ...])`
to `['HOD', 'Dean']` only) and their nav links/dashboard cards. Kept: Users & Roles, Audit Log,
Suggestion Box / none of those are "academic operations." The Administrator's
dashboard now shows only users-by-role/status/signup-trend charts and a recent-staff list.

**Event Management / grouped, HOD + Dean only.** The pages didn't move (still under `public/admin/`,
`public/reports/`, to avoid a mass file-rename and broken-link risk) but every one of them is now
gated to `['HOD', 'Dean']` and grouped under a single "Event Management" sidebar section for both
roles: Events, Participants, Event QR Codes, Scan/Mark Attendance,
Compliance Reports. `includes/ReportScope.php` no longer applies a per-faculty/per-department filter
for either role / both see every department's reports, matching their new university-wide remits.
Students are unaffected: `student/events.php`, `student/scan.php`, `student/my-qr.php` still work
exactly as before; only the *management* surface moved.

**HOD becomes the central academic authority across every department, not just their own.**
- `public/hod/modules.php` (new) / HOD creates modules, assigns a lecturer (any lecturer, any
  department), and sets session type, room, CAT date, and Exam date. Lecturers can no longer create
  modules themselves / `lecturer/modules.php` is now a read-only Ongoing/Completed view.
- `hod/announcements.php` rewritten: a department dropdown (default "All Departments") replaces the
  old hard-coded own-department scope, for both the Students and the new Lecturers audience modes.
- `modules` table gained `room`, `cat_date`, `exam_date`, `created_by`; its `status` enum was
  renamed `Active/Archived` -> `Ongoing/Completed` to match the language used everywhere else
  (data migrated automatically in `migration_005.sql`).
- "Eligibility rules and academic decisions" from the request is the one bullet intentionally left
  unbuilt / there's no concrete rule given (e.g. an absence threshold) to encode, and the closest
  prior art (the second master-prompt's "3 absences -> AT RISK -> ineligible for CAT/Exam") was
  explicitly not part of this increment's ask. `class_attendance_logs` has everything needed to
  compute it later.

**Lecturers only receive modules; they teach, take attendance, announce, and grade.**
- `lecturer/modules.php`: read-only, split into Ongoing/Completed, each module card links to
  Attendance / Announce / Assignments.
- `lecturer/announcements.php` (new): pick one of your modules, write a message / it's sent
  automatically to every student in `module_enrollments` for that module. No audience picker needed.
- `lecturer/assignments.php` (new): create an assignment (title, instructions, optional PDF/ZIP
  attachment, deadline) for a module; registered students are notified immediately; view/download
  submissions per assignment; extend a deadline.
- `lecturer/class-attendance.php` rewritten / see "Attendance is now fully lecturer-controlled" below.

**Module Registration replaces the old student-facing Class Attendance QR feature entirely.**
`public/student/class-scan.php` and `public/api/class-checkin.php` are deleted / a student never
sees or scans a QR code for class attendance anymore. Instead:
- `public/student/modules.php` (new) / three tabs: **Browse & Register** (Ongoing modules grouped by
  department, one-click register), **My Modules** (enrolled + Ongoing, with a live attendance tally
  and a link into assignments), **Completed** (enrolled + Completed, with a CAT/Exam slip link).
- `public/student/assignments.php` (new) / submit/resubmit a PDF/ZIP for any assignment in a module
  you're registered for, blocked automatically once the deadline passes.
- `public/student/slip.php` (new) / a print-friendly CAT/Exam entry slip (name, reg number,
  department, module, lecturer, CAT/Exam date, room, session) for any module that is both
  **Completed** and one you're **registered** for. No PDF library used / it's a plain print-styled
  HTML page (`window.print()`), since the existing `pdf` skill/Dompdf setup is for server-generated
  PDFs and this didn't need that weight.
- `module_enrollments` (new table) is the single source of truth for "is this student allowed to see
  attendance/assignments/announcements for this module" / `api/class-scan-preview.php`,
  `lecturer/announcements.php`, and `student/assignments.php` all check it directly.

**Attendance is now fully lecturer-controlled, on fixed real-world session windows (Africa/Cairo).**
This replaces the previous "lecturer clicks Start, a 2-hour window opens from that click" model.
- `includes/ClassAttendance.php` rewritten: four fixed windows / Day 08:00/11:30, Evening
  18:00/20:00 (weekdays), Weekend Morning 08:30/14:00, Weekend Afternoon 14:30/20:30 (Sat/Sun) / all
  evaluated with `new DateTime('now', new DateTimeZone('Africa/Cairo'))`, deliberately independent of
  the app's own default timezone (`Africa/Kigali`, set in `config/config.php`) so this can never
  silently drift if that ever changes. `ClassAttendance::currentWindow()` returns null outside all
  four windows / `lecturer/class-attendance.php` shows "no session window active" and disables
  attendance entirely in that case, which is the literal "block scans outside session hours" rule.
- A lecturer's only action is "Open Attendance for This Session", which finds-or-creates one
  `class_sessions` row per (module, today's date, window) / `class_sessions.window_name` is a new
  column enforcing that uniqueness at the DB level too.
- There is no more student-facing QR. The lecturer either **searches the roster manually** (Search ->
  "Found: Name (Reg)" -> Confirm & View Profile -> photo/name/reg/department card -> Confirm
  Attendance / the same explicit flow as `admin/scan-student.php`'s Method 3) or **scans the
  student's own personal QR** with the camera (the QR from `student/my-qr.php` / this re-purposes the
  `mode=qr` branch of `api/class-scan-preview.php`, which existed but was never wired to any UI in
  the previous increment). Both paths land on the identical profile-card confirmation step and the
  same `api/class-scan-confirm.php`, which now also re-checks that the fixed window hasn't closed
  since the page loaded, and that the student is actually in `module_enrollments` for that module.
- Present/Late/Absent thresholds are unchanged in spirit (≤10 min of the window's official start =
  Present, ≤20 min = Late, beyond that = Absent) but now measured against the *window's* start time,
  not whenever the lecturer happened to click a button.

**Left as a lighter scaffold:**
- No automatic end-of-window sweep marks unscanned students "Absent" in the database / only students
  the lecturer actually searched/scanned end up with a row in `class_attendance_logs`. A real
  "Absent" sweep needs a cron job (or a check at next-page-load time) iterating
  `module_enrollments` minus `class_attendance_logs` for a closed session; not added here since it's
  a scheduling concern outside a synchronous PHP request.
- Assignment submissions have no grading/feedback workflow / lecturers can view and download, not
  score, a submission. `assignment_submissions` has nowhere to put a grade yet.
## 13. Feature Increment: Announcement Scoping, Sign-In/Out Attendance, CAT/Exam Eligibility, Settings

Run `database/migration_006.sql` after `migration_005.sql`:

```bash
mysql -u root -p semas < database/migration_006.sql
```

This increment touches almost every role's nav and several core flows. Summary by topic:

**Announcement Board is now actually scoped per viewer.** Previously every published announcement
showed to everyone with board access / so the Principal could see a lecturer's assignment
announcement to one module's students, which was explicitly called out as wrong. Fixed properly:
- New `announcement_recipients` table records exactly who an announcement was delivered to.
- `includes/Delivery.php`'s `announce()` now returns the resolved recipient list (not just a count);
  `includes/Announcement.php::create()` persists that list (or the explicit pre-scoped list HOD/Dean/
  Lecturer pages already passed) into `announcement_recipients` every time, regardless of which path
  resolved the audience.
- `public/announcements/board.php` only shows a row where the viewer is in `announcement_recipients`
  OR is the sender. `public/admin/announcements.php` (new) gives the Principal a way to send genuinely
  system-wide notices (reuses the existing `'University Community'` audience, which already resolves
  to literally everyone / no new ENUM value needed).

**Class attendance: Sign In / Sign Out, self-scan + lecturer tools, one-scan-per-IP.**
- `class_attendance_logs` gained `attendance_type` ('Sign In'/'Sign Out') and `ip_address`; the unique
  key is now `(session_id, user_id, attendance_type)` plus a second unique key
  `(session_id, ip_address, attendance_type)` / that second key is the actual enforcement of "one IP
  can only Sign In once and Sign Out once per session," which stops one device/browser session being
  used to scan multiple different student accounts in for the same class.
- `public/student/attendance.php` (new) + `public/api/student-attendance-scan.php` (new) / a student
  sees their registered Ongoing modules, and a **Scan** button appears only when the module's session
  type matches the currently active fixed window. First scan = Sign In (Present/Late per the existing
  10/20-minute rule); a second scan = Sign Out. This coexists with / does not replace / the lecturer's
  manual-search/QR-of-student tools from increment 12; both funnel through the same
  `ClassAttendance::statusFor()` so they can never disagree. `lecturer/class-attendance.php`'s old
  "session QR for students to scan" was already removed in increment 12 and stays removed; the
  lecturer's two methods now are manual roster search and scanning the **student's own personal QR**
  (the `mode=qr` branch of `api/class-scan-preview.php` existed since increment 12 but was unused
  until now / it's wired into the page's camera reader).
- All of the analytics queries that aggregate `class_attendance_logs.status` now filter
  `attendance_type = 'Sign In'` / without that filter, a Sign Out row (always recorded as 'Present'
  for bookkeeping) would double-count every attended session.

**CAT/Exam eligibility engine with HOD approval** (`includes/Eligibility.php`,
`public/hod/eligibility.php`, `public/student/cat-exam-slips.php`, `public/student/slip-print.php`):
- A student who missed 2+ sessions before a module's `cat_date` gets a system-recommended "Not
  Allowed" for CAT; missing 2+ sessions *between* `cat_date` and `exam_date` (exclusive on both ends)
  does the same for Exam. "Missed" = no attendance row at all, or status = 'Absent' / Present/Late
  both count as attended.
- The system's decision is only ever a recommendation (`cat_exam_eligibility.system_decision`). HOD
  must explicitly **Generate/Refresh** the list per module per exam type, then **Approve** (locks in
  the system's call) or **Override** (HOD's own Allowed/Not Allowed, with a mandatory reason /
  e.g. a medical certificate) each row. Re-generating never silently overwrites an
  Approved/Overridden row, only a still-Pending one.
- A student's CAT/Exam slip (`student/cat-exam-slips.php` lists eligibility per module;
  `student/slip-print.php` is the actual printable page) is only printable once `hod_decision !=
  'Pending'` and `final_decision = 'Allowed'`. The old single generic `student/slip.php` from
  increment 12 (gated only on the module being "Completed") is removed / it didn't make sense for a
  CAT slip, which is needed *before* the module is Completed.
- `includes/Module.php::autoCompleteExpired()` / a module flips Ongoing -> Completed automatically
  once `exam_date` has passed, called lazily at the top of every module-listing page (no cron job in
  this stack).

**HOD ↔ Dean role realignment, per the explicit "Event Management is Dean-only now" /
"Departments are Administrator-only, HOD is read-only" directives:**
- `admin/events.php`, `admin/qr.php`, `admin/event-participants.php`,
  `admin/scan-student.php`, `reports/*`, and their `api/admin-scan-*`/`api/qr-refresh.php` backends all
  changed from `['HOD','Dean']` to `['Dean']` only / HOD no longer has Event Management at all.
- Department CRUD lives in `admin/departments.php` (Administrator-only). HOD still sees
  departments read-only where needed, but can no longer create or edit one.
- New HOD pages: `hod/eligibility.php` (above), `hod/holidays.php` (below).
- `modules.invigilator_id` (FK to `lecturers`) is now wired into `hod/modules.php`'s create/edit forms
  and shown on the printed slip / "Assign invigilators (must be selected from lecturers)" from both
  the Manage Modules and CAT/Exam sections of the spec map to this one field.

**Holidays & Umuganda** (`hod/holidays.php`, `includes/ClassAttendance.php`):
- A **Public Holiday** makes `ClassAttendance::currentWindow()` return null for the entire day /
  attendance (both self-scan and lecturer tools) is fully disabled, matching "no sign-in required."
- An **Umuganda** date replaces the normal Saturday/Sunday windows with its own override hours
  (default 13:30/16:30 / 17:00/20:30, editable per date) and automatically sends an announcement to
  every Active Weekend-session student via the same `Announcement::create()` path as everything else.

**Principal (Administrator) dashboard expanded** to the full requested stat set: Total
Students/Lecturers/HODs/Deans/Departments/Modules, Active Users, Pending Accounts, Storage Usage
(real `disk_free_space()`/`disk_total_space()` on the uploads volume / not a mocked number), System
Status (a simple "Operational" badge tied to the fact that the page already required a working DB
connection to render), Recent Logins (from `users.last_login_at`, already tracked since the very
first increment / just never surfaced anywhere before), and Recent Announcements (now meaningful
since System Announcements exist as their own page). Plus three brand-new Administrator-only pages:
- `admin/settings.php` / University Branding (name, logo, favicon, login background/banner, theme
  gold/ink colors / applied live via `includes/Settings.php` + a small inline `<style>` override in
  `layout_top.php`) and Academic Settings (year, semester). Email/SMS fields are shown **read-only**
  from `.env`/`config/config.php` constants, deliberately not editable here, so a typo can never
  silently break a working mail/SMS integration.
- `admin/backup-download.php` / a pure-PHP SQL export (every table, plain `INSERT` statements) since
  shared hosting often disables `shell_exec`/`mysqldump`. Fine for a course-project-scale database;
  swap for a real `mysqldump` cron job at production scale.
- `admin/roles.php` / a **read-only reference table** of who can do what. This is deliberately not a
  live permission editor: every permission in this codebase is enforced via `Auth::requireRole()` at
  the top of a PHP file, not read from a database row, so presenting an editable grid would be
  misleading about how access control actually works here.

**Student & Lecturer nav cleanup**, per the explicit "duplicate Events / unnecessary My QR Code" note:
- Student sidebar regrouped into **Academics** (Module Registration, Class Attendance, Assignments,
  CAT/Exam Slips) and **Events** (Events, plus "Event QR Code" / "Event Check-in" / renamed from "My
  QR Code" / "Scan to Check In" and nested under Events rather than removed, since those pages still
  do real work for event attendance specifically, not class attendance).
- `student/assignments.php` gained a no-`module_id` landing view (every assignment across every
  registered module) so the sidebar can link to one flat "Assignments" item instead of forcing a
  module choice first.
**Left as a lighter scaffold:**
- No automated "Umuganda is next Saturday" reminder / an HOD must add the date manually (one day
  before, per the spec) rather than the system auto-detecting "last Saturday of the month."
- Eligibility's absence count is computed from `class_sessions` actually held (rows in the table), not
  from a fixed expected-session-count / if a lecturer never opens attendance for a class that should
  have happened, that session simply doesn't count against anyone. A stricter implementation would
  need a separate "expected sessions" calendar independent of whether attendance was ever taken.
- Two-Factor Authentication was explicitly marked optional in the request and isn't built.
- Settings page doesn't (yet) let the Principal edit Email/SMS credentials or trigger a real
  `mysqldump`-based backup / both are flagged above as deliberate scope cuts, not oversights.

## 14. Hotfix + Adjustment: migration_006 column bug, Roles & Permissions moved, Module Reports added

**Bug fix:** `migration_006.sql`'s backfill step referenced `notifications.type`/`notifications.related_id`,
which don't exist (the real columns are `notifications.category` and
`notifications.related_announcement_id`). On a strict SQL client this stopped the whole script after
the first statement, silently skipping every table/column change that came after it (`holidays`,
`cat_exam_eligibility`, the `class_attendance_logs` attendance-type/IP columns,
`modules.invigilator_id`). Fixed and moved to the very end of the file, so even if it fails again for
any reason, every structural change above it has already been committed. **If you already ran the
broken version, just re-run the corrected `migration_006.sql` from this download / every statement in
it is safe to run again (`CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`), and since nothing after the
original failure point ever ran, this picks up exactly where it stopped.**

**Roles & Permissions** is no longer a standalone Administrator page / the same read-only reference
table now lives at the bottom of `admin/settings.php` (Settings & Branding), since it's reference
information about the system rather than a setting in its own right.

**New: `admin/module-reports.php`** / read-only oversight for the Principal: every module
university-wide with department, lecturer, registered student count, sessions held, and an
attendance rate (Present+Late Sign-Ins ÷ total Sign-Ins), filterable by department/status. This is
deliberately reporting-only / no edit, attendance-taking, or eligibility action renders here; that
stays with HOD/Lecturer. Linked from both the sidebar and the Administrator dashboard's quick actions.
