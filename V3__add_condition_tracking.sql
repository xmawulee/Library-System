-- ============================================================
--  V3__add_condition_tracking.sql
--  Adds per-copy condition tracking to borrow_records,
--  a full condition history log, and an auto-escalation trigger.
--  Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

-- ── 1. Expand books.condition_status ENUM ────────────────────
-- Existing 'Good' and 'Damaged' values map exactly to the same
-- labels in the new ENUM — no data conversion required.
ALTER TABLE books
    MODIFY COLUMN condition_status
        ENUM('Perfect','Good','Mildly Torn','Torn','Damaged')
        NOT NULL DEFAULT 'Good';

-- ── 2. Add condition columns to borrow_records ────────────────
ALTER TABLE borrow_records
    ADD COLUMN condition_on_borrow
        ENUM('Perfect','Good','Mildly Torn','Torn','Damaged')
        NOT NULL DEFAULT 'Good'
        AFTER notes,
    ADD COLUMN condition_on_return
        ENUM('Perfect','Good','Mildly Torn','Torn','Damaged')
        DEFAULT NULL                  -- NULL until book is actually returned
        AFTER condition_on_borrow;

-- ── 3. Create book_condition_log (append-only) ────────────────
CREATE TABLE IF NOT EXISTS book_condition_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id         INT UNSIGNED NOT NULL,
    record_id       INT UNSIGNED DEFAULT NULL,   -- NULL for manual inspections
    event_type      ENUM('Borrowed','Returned','Manual Inspection') NOT NULL,
    condition_noted ENUM('Perfect','Good','Mildly Torn','Torn','Damaged') NOT NULL,
    noted_by        INT UNSIGNED DEFAULT NULL,
    remarks         TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id)   REFERENCES books(id)          ON DELETE CASCADE,
    FOREIGN KEY (record_id) REFERENCES borrow_records(id) ON DELETE SET NULL,
    FOREIGN KEY (noted_by)  REFERENCES users(id)          ON DELETE SET NULL,
    INDEX idx_bcl_book_id   (book_id),
    INDEX idx_bcl_event_type(event_type)
) ENGINE=InnoDB;

-- ── 4. Condition escalation trigger ──────────────────────────
-- Fires BEFORE UPDATE on borrow_records so we can read the
-- final condition_on_return value and update books in the same
-- statement (MariaDB restriction: can't UPDATE a table from an
-- AFTER trigger on itself, but we CAN update a *different* table
-- from an AFTER trigger — so AFTER is safe here because we are
-- updating `books`, not `borrow_records`).
--
-- Severity scale (matches PHP conditionSeverity()):
--   Perfect=1  Good=2  Mildly Torn=3  Torn=4  Damaged=5
-- ─────────────────────────────────────────────────────────────
DELIMITER $$

DROP TRIGGER IF EXISTS trg_condition_escalate $$
CREATE TRIGGER trg_condition_escalate
AFTER UPDATE ON borrow_records
FOR EACH ROW
BEGIN
    DECLARE sev_borrow  TINYINT DEFAULT 0;
    DECLARE sev_return  TINYINT DEFAULT 0;

    -- Only fire when condition_on_return transitions from NULL → value
    IF OLD.condition_on_return IS NULL AND NEW.condition_on_return IS NOT NULL THEN

        -- Map borrow condition to severity integer
        SET sev_borrow = CASE NEW.condition_on_borrow
            WHEN 'Perfect'    THEN 1
            WHEN 'Good'       THEN 2
            WHEN 'Mildly Torn' THEN 3
            WHEN 'Torn'       THEN 4
            WHEN 'Damaged'    THEN 5
            ELSE 2
        END;

        -- Map return condition to severity integer
        SET sev_return = CASE NEW.condition_on_return
            WHEN 'Perfect'    THEN 1
            WHEN 'Good'       THEN 2
            WHEN 'Mildly Torn' THEN 3
            WHEN 'Torn'       THEN 4
            WHEN 'Damaged'    THEN 5
            ELSE 2
        END;

        -- Escalate only if returned in worse condition than borrowed
        IF sev_return > sev_borrow THEN
            UPDATE books
            SET condition_status = NEW.condition_on_return
            WHERE id = NEW.book_id;
        END IF;

    END IF;
END $$

DELIMITER ;
