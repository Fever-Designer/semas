-- SEMAS - Migration 031: Suggestion resolver integrity
USE semas;

ALTER TABLE suggestions
    ADD CONSTRAINT fk_suggestions_resolved_by
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL;
