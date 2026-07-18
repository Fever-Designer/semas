-- SEMAS - Migration 026: Registrar-controlled active semester
USE semas;

ALTER TABLE modules ADD COLUMN semester_id INT NULL AFTER module_id;
ALTER TABLE class_sessions ADD COLUMN semester_id INT NULL AFTER session_id;
ALTER TABLE cat_exam_schedules ADD COLUMN semester_id INT NULL AFTER schedule_id;
ALTER TABLE assignments ADD COLUMN semester_id INT NULL AFTER assignment_id;
ALTER TABLE events ADD COLUMN semester_id INT NULL AFTER event_id;
ALTER TABLE module_enrollments ADD COLUMN semester_id INT NULL AFTER enrollment_id;
ALTER TABLE class_attendance_logs ADD COLUMN semester_id INT NULL AFTER attendance_id;
ALTER TABLE cat_exam_attendance_logs ADD COLUMN semester_id INT NULL AFTER cat_attendance_id;
ALTER TABLE cat_exam_eligibility ADD COLUMN semester_id INT NULL AFTER eligibility_id;
ALTER TABLE module_attendance_submissions ADD COLUMN semester_id INT NULL AFTER submission_id;
ALTER TABLE assignment_submissions ADD COLUMN semester_id INT NULL AFTER submission_id;

UPDATE modules m SET semester_id = (
    SELECT sc.id FROM semester_calendars sc
    WHERE DATE(m.created_at) BETWEEN sc.start_date AND sc.end_date
    ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1
);
UPDATE class_sessions cs SET semester_id = (
    SELECT sc.id FROM semester_calendars sc
    WHERE cs.session_date BETWEEN sc.start_date AND sc.end_date
    ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1
);
UPDATE cat_exam_schedules ces SET semester_id = (
    SELECT sc.id FROM semester_calendars sc
    WHERE ces.scheduled_date BETWEEN sc.start_date AND sc.end_date
    ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1
);
UPDATE assignments a SET semester_id = (
    SELECT sc.id FROM semester_calendars sc
    WHERE DATE(a.created_at) BETWEEN sc.start_date AND sc.end_date
    ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1
);
UPDATE events e SET semester_id = (
    SELECT sc.id FROM semester_calendars sc
    WHERE e.event_date BETWEEN sc.start_date AND sc.end_date
    ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1
);
UPDATE module_enrollments me
JOIN modules m ON m.module_id = me.module_id
SET me.semester_id = m.semester_id;
UPDATE class_attendance_logs cal
JOIN class_sessions cs ON cs.session_id = cal.session_id
SET cal.semester_id = cs.semester_id;
UPDATE cat_exam_attendance_logs ceal
JOIN cat_exam_schedules ces ON ces.schedule_id = ceal.schedule_id
SET ceal.semester_id = ces.semester_id;
UPDATE cat_exam_eligibility ce
JOIN modules m ON m.module_id = ce.module_id
SET ce.semester_id = m.semester_id;
UPDATE module_attendance_submissions mas
JOIN modules m ON m.module_id = mas.module_id
SET mas.semester_id = m.semester_id;
UPDATE assignment_submissions ass
JOIN assignments a ON a.assignment_id = ass.assignment_id
SET ass.semester_id = a.semester_id;

ALTER TABLE modules ADD CONSTRAINT fk_modules_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE class_sessions ADD CONSTRAINT fk_class_sessions_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE cat_exam_schedules ADD CONSTRAINT fk_cat_exam_schedules_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE assignments ADD CONSTRAINT fk_assignments_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE events ADD CONSTRAINT fk_events_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE module_enrollments ADD CONSTRAINT fk_module_enrollments_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE class_attendance_logs ADD CONSTRAINT fk_class_attendance_logs_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE cat_exam_attendance_logs ADD CONSTRAINT fk_cat_exam_attendance_logs_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE cat_exam_eligibility ADD CONSTRAINT fk_cat_exam_eligibility_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE module_attendance_submissions ADD CONSTRAINT fk_module_attendance_submissions_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;
ALTER TABLE assignment_submissions ADD CONSTRAINT fk_assignment_submissions_semester FOREIGN KEY (semester_id) REFERENCES semester_calendars(id) ON DELETE RESTRICT;

DELIMITER $$

CREATE TRIGGER trg_modules_active_semester BEFORE INSERT ON modules FOR EACH ROW
BEGIN
    SET NEW.semester_id = (
        SELECT id FROM semester_calendars
        WHERE start_date <= CURDATE() AND end_date >= CURDATE()
        ORDER BY start_date DESC, id DESC LIMIT 1
    );
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active semester is currently available.'; END IF;
END$$

CREATE TRIGGER trg_class_sessions_active_semester BEFORE INSERT ON class_sessions FOR EACH ROW
BEGIN
    SET NEW.semester_id = (
        SELECT id FROM semester_calendars
        WHERE start_date <= CURDATE() AND end_date >= CURDATE()
        ORDER BY start_date DESC, id DESC LIMIT 1
    );
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active semester is currently available.'; END IF;
END$$

CREATE TRIGGER trg_cat_exam_schedules_active_semester BEFORE INSERT ON cat_exam_schedules FOR EACH ROW
BEGIN
    SET NEW.semester_id = (
        SELECT id FROM semester_calendars
        WHERE start_date <= CURDATE() AND end_date >= CURDATE()
        ORDER BY start_date DESC, id DESC LIMIT 1
    );
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active semester is currently available.'; END IF;
END$$

CREATE TRIGGER trg_assignments_active_semester BEFORE INSERT ON assignments FOR EACH ROW
BEGIN
    SET NEW.semester_id = (
        SELECT id FROM semester_calendars
        WHERE start_date <= CURDATE() AND end_date >= CURDATE()
        ORDER BY start_date DESC, id DESC LIMIT 1
    );
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active semester is currently available.'; END IF;
END$$

CREATE TRIGGER trg_events_active_semester BEFORE INSERT ON events FOR EACH ROW
BEGIN
    SET NEW.semester_id = (
        SELECT id FROM semester_calendars
        WHERE start_date <= CURDATE() AND end_date >= CURDATE()
        ORDER BY start_date DESC, id DESC LIMIT 1
    );
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active semester is currently available.'; END IF;
END$$

CREATE TRIGGER trg_module_enrollments_semester BEFORE INSERT ON module_enrollments FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM modules WHERE module_id = NEW.module_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The module is not linked to an active semester.'; END IF;
END$$

CREATE TRIGGER trg_class_attendance_logs_semester BEFORE INSERT ON class_attendance_logs FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM class_sessions WHERE session_id = NEW.session_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The attendance session is not linked to an active semester.'; END IF;
END$$

CREATE TRIGGER trg_cat_exam_attendance_logs_semester BEFORE INSERT ON cat_exam_attendance_logs FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM cat_exam_schedules WHERE schedule_id = NEW.schedule_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assessment is not linked to an active semester.'; END IF;
END$$

CREATE TRIGGER trg_cat_exam_eligibility_semester BEFORE INSERT ON cat_exam_eligibility FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM modules WHERE module_id = NEW.module_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The eligibility record is not linked to an active semester.'; END IF;
END$$

CREATE TRIGGER trg_module_attendance_submissions_semester BEFORE INSERT ON module_attendance_submissions FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM modules WHERE module_id = NEW.module_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The attendance submission is not linked to an active semester.'; END IF;
END$$

CREATE TRIGGER trg_assignment_submissions_semester BEFORE INSERT ON assignment_submissions FOR EACH ROW
BEGIN
    SET NEW.semester_id = (SELECT semester_id FROM assignments WHERE assignment_id = NEW.assignment_id);
    IF NEW.semester_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assignment is not linked to an active semester.'; END IF;
END$$

DELIMITER ;
