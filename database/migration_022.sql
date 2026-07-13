-- =====================================================================
-- SEMAS - Migration 022: Lecturer-controlled attendance phases
-- ---------------------------------------------------------------------
-- Existing automatic timetable sessions keep demo_controlled = 0.
-- =====================================================================

USE semas;

ALTER TABLE class_sessions
    ADD COLUMN attendance_phase ENUM('Inactive','SignIn','SignOut') NOT NULL DEFAULT 'Inactive' AFTER status,
    ADD COLUMN demo_controlled TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_phase,
    ADD COLUMN phase_started_at DATETIME NULL AFTER demo_controlled,
    ADD COLUMN phase_closed_at DATETIME NULL AFTER phase_started_at;
