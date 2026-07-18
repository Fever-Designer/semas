-- SEMAS - Migration 027: Click-through destinations for notifications
USE semas;

ALTER TABLE notifications
    ADD COLUMN action_url VARCHAR(500) NULL AFTER related_announcement_id;
