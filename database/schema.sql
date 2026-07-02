-- =====================================================================
-- SEMAS / Student Event Management and Announcement System
-- Full production database schema (MySQL 8+ / MariaDB 10.4+)
-- University of Kigali
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS semas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE semas;

-- ---------------------------------------------------------------------
-- Lookup tables
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    role_id     INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(50) NOT NULL UNIQUE      -- Administrator, Dean, HOD, Student
) ENGINE=InnoDB;

CREATE TABLE faculties (
    faculty_id      INT AUTO_INCREMENT PRIMARY KEY,
    faculty_name    VARCHAR(150) NOT NULL,
    faculty_code    VARCHAR(20) NOT NULL UNIQUE,
    dean_user_id    INT NULL                      -- FK added after users table exists
) ENGINE=InnoDB;

CREATE TABLE departments (
    department_id    INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id        INT NOT NULL,
    department_name   VARCHAR(150) NOT NULL,
    department_code   VARCHAR(20) NOT NULL UNIQUE,
    hod_user_id        INT NULL,                  -- FK added after users table exists
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id            INT AUTO_INCREMENT PRIMARY KEY,
    role_id            INT NOT NULL,
    department_id      INT NULL,
    reg_number         VARCHAR(30) NULL UNIQUE,        -- students only
    full_name          VARCHAR(150) NOT NULL,
    email              VARCHAR(150) NOT NULL UNIQUE,
    phone_number       VARCHAR(20) NULL,
    password_hash      VARCHAR(255) NOT NULL,
    photo_path         VARCHAR(255) NULL,
    status             ENUM('Pending','Active','Deactivated') NOT NULL DEFAULT 'Pending',
    email_verified_at  DATETIME NULL,
    last_login_at      DATETIME NULL,
    sms_opt_in         TINYINT(1) NOT NULL DEFAULT 1,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE faculties
    ADD CONSTRAINT fk_faculties_dean FOREIGN KEY (dean_user_id) REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE departments
    ADD CONSTRAINT fk_departments_hod FOREIGN KEY (hod_user_id) REFERENCES users(user_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Email verification / password reset / OTP
-- ---------------------------------------------------------------------
CREATE TABLE email_verifications (
    verification_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    token_hash       VARCHAR(255) NOT NULL,
    expires_at       DATETIME NOT NULL,
    verified_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    reset_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    token_hash   VARCHAR(255) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE otp_codes (
    otp_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    purpose       ENUM('login','password_reset','email_verify') NOT NULL,
    code_hash     VARCHAR(255) NOT NULL,
    channel       ENUM('email','sms') NOT NULL DEFAULT 'email',
    attempts      TINYINT NOT NULL DEFAULT 0,
    max_attempts  TINYINT NOT NULL DEFAULT 5,
    expires_at    DATETIME NOT NULL,
    consumed_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Events, announcements, registrations, attendance
-- ---------------------------------------------------------------------
CREATE TABLE events (
    event_id        INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    venue           VARCHAR(150) NOT NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    event_date      DATE NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    department_id   INT NULL,
    created_by      INT NOT NULL,
    qr_secret       VARCHAR(64) NOT NULL,        -- per-event HMAC secret for signed QR payloads
    status          ENUM('Scheduled','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    qr_expires_at   DATETIME NULL,               -- optional QR expiration
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE event_registrations (
    registration_id   INT AUTO_INCREMENT PRIMARY KEY,
    event_id          INT NOT NULL,
    user_id           INT NOT NULL,
    registered_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_user (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcements (
    announcement_id  INT AUTO_INCREMENT PRIMARY KEY,
    event_id         INT NULL,
    title            VARCHAR(200) NOT NULL,
    category         ENUM('Academic','Examination','Event','Registration','Scholarship',
                           'Sports','General Notice','Emergency','Workshop','Career Opportunity')
                           NOT NULL DEFAULT 'General Notice',
    priority         ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
    target_audience  ENUM('All Students','First Year Students','Final Year Students',
                           'Specific Department','Staff','Event Participants','University Community')
                           NOT NULL DEFAULT 'All Students',
    department_id    INT NULL,                   -- used when target_audience = Specific Department
    message          TEXT NOT NULL,
    posted_by        INT NOT NULL,
    posted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (posted_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE attendance_logs (
    attendance_id        INT AUTO_INCREMENT PRIMARY KEY,
    event_id             INT NOT NULL,
    user_id              INT NOT NULL,
    checkin_time         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verification_method  ENUM('QR','Manual') NOT NULL DEFAULT 'QR',
    latitude             DECIMAL(10,7) NULL,
    longitude            DECIMAL(10,7) NULL,
    distance_meters      DECIMAL(8,2) NULL,
    gps_passed           TINYINT(1) NOT NULL DEFAULT 0,
    device_info          VARCHAR(255) NULL,
    UNIQUE KEY uniq_attendance_event_user (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Notifications, email & SMS logs
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    title            VARCHAR(200) NOT NULL,
    body             TEXT NOT NULL,
    is_read          TINYINT(1) NOT NULL DEFAULT 0,
    related_announcement_id INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (related_announcement_id) REFERENCES announcements(announcement_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE email_logs (
    email_log_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NULL,
    to_email       VARCHAR(150) NOT NULL,
    subject        VARCHAR(255) NOT NULL,
    template_name  VARCHAR(100) NOT NULL,
    status         ENUM('Queued','Sent','Failed') NOT NULL DEFAULT 'Queued',
    error_message  TEXT NULL,
    sent_at        DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_queue (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    to_email       VARCHAR(255)  NOT NULL,
    user_id        INT           NULL,
    subject        VARCHAR(500)  NOT NULL,
    template_name  VARCHAR(100)  NOT NULL,
    vars_json      MEDIUMTEXT    NOT NULL,
    status         ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts       TINYINT       NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at   DATETIME      NULL,
    INDEX idx_status_attempts (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sms_logs (
    sms_log_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NULL,
    to_phone       VARCHAR(20) NOT NULL,
    message        VARCHAR(320) NOT NULL,
    provider       ENUM('africastalking','twilio') NOT NULL,
    status         ENUM('Queued','Sent','Failed') NOT NULL DEFAULT 'Queued',
    error_message  TEXT NULL,
    sent_at        DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Audit logs & system settings
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    audit_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NULL,
    action        VARCHAR(100) NOT NULL,          -- e.g. 'LOGIN', 'CREATE_EVENT', 'DEACTIVATE_USER'
    entity_type   VARCHAR(50) NULL,
    entity_id     INT NULL,
    details       TEXT NULL,
    ip_address    VARCHAR(45) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key    VARCHAR(100) PRIMARY KEY,
    setting_value  TEXT NOT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------
INSERT INTO roles (role_name) VALUES ('Administrator'), ('Dean'), ('HOD'), ('Student');

INSERT INTO faculties (faculty_name, faculty_code) VALUES
    ('School of Computing and Information Technology', 'SCIT'),
    ('School of Business and Economics', 'SBE');

INSERT INTO departments (faculty_id, department_name, department_code) VALUES
    (1, 'Information Technology', 'IT'),
    (1, 'Software Engineering', 'SE'),
    (2, 'Accounting and Finance', 'ACCFIN');

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('campus_latitude', '-1.953600'),
    ('campus_longitude', '30.094700'),
    ('campus_radius_meters', '300'),
    ('otp_expiry_minutes', '5'),
    ('verification_link_expiry_hours', '24'),
    ('password_reset_expiry_minutes', '30'),
    ('qr_default_expiry_hours', '6');

-- The default Principal account is intentionally not inserted here with a
-- hard-coded password hash. Create the first Principal account through a
-- trusted provisioning process for your environment.
