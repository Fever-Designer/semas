-- =====================================================================
-- SEMAS - Migration 020: Remove retired campus-life item tables
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_020.sql
-- =====================================================================

USE semas;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS lost_found_claims;
DROP TABLE IF EXISTS lost_found_items;

SET FOREIGN_KEY_CHECKS = 1;
