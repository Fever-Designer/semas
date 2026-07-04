-- =====================================================================
-- SEMAS - Migration 017: Year-coded intake values
--   Allows intake values such as JAN26, MAY26, SEPT26 across students,
--   module intake scoping, and semester calendars.
-- =====================================================================

USE semas;

ALTER TABLE users
    MODIFY COLUMN intake VARCHAR(10) NULL;

ALTER TABLE module_intakes
    MODIFY COLUMN intake VARCHAR(10) NOT NULL;

ALTER TABLE semester_calendars
    MODIFY COLUMN intake VARCHAR(10) NOT NULL;
