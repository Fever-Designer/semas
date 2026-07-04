-- =====================================================================
-- SEMAS - Migration 020: Remove retired feature tables
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_020.sql
-- =====================================================================


SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS lost_found_claims;
DROP TABLE IF EXISTS lost_found_items;
DROP TABLE IF EXISTS poll_votes;
DROP TABLE IF EXISTS poll_options;
DROP TABLE IF EXISTS polls;
DROP TABLE IF EXISTS ai_notifications;

SET FOREIGN_KEY_CHECKS = 1;
