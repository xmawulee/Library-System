-- ============================================================
--  V2__add_book_copies.sql
--  Non-breaking migration: adds multi-copy support to books
--  Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

-- 1. Add total_copies column (admin-specified stock)
ALTER TABLE books
    ADD COLUMN total_copies INT UNSIGNED NOT NULL DEFAULT 1
    AFTER shelf_location;

-- 2. Add available_copies column (live decremented count)
ALTER TABLE books
    ADD COLUMN available_copies INT UNSIGNED NOT NULL DEFAULT 1
    AFTER total_copies;

-- 3. Extend the status ENUM to include 'All Issued'
ALTER TABLE books
    MODIFY COLUMN status ENUM('Available','Borrowed','Missing','All Issued') DEFAULT 'Available';

-- 4. Backfill existing rows based on current status
--    Books currently Borrowed: total_copies=1, available_copies=0
--    Books currently Available: both stay at 1 (already DEFAULT 1)
UPDATE books SET available_copies = 0 WHERE status = 'Borrowed';

-- 5. Index on available_copies for fast filtering
ALTER TABLE books
    ADD INDEX idx_available_copies (available_copies);

-- ============================================================
--  Trigger: validate available_copies bounds
--  MySQL 5.7-compatible — uses SIGNAL instead of CHECK constraint
--  (CHECK constraints are not enforced in MySQL 5.7)
-- ============================================================
DELIMITER $$

DROP TRIGGER IF EXISTS trg_books_copies_before_update $$
CREATE TRIGGER trg_books_copies_before_update
BEFORE UPDATE ON books
FOR EACH ROW
BEGIN
    -- Prevent available_copies from exceeding total_copies
    IF NEW.available_copies > NEW.total_copies THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'available_copies cannot exceed total_copies';
    END IF;
    -- Prevent available_copies from going below 0
    -- (UNSIGNED already prevents this at the storage level, but explicit guard)
    IF NEW.available_copies < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'available_copies cannot be negative';
    END IF;
END $$

-- ============================================================
--  Trigger: auto-sync status when available_copies changes
--  Named trg_books_sync_status as required.
--  Uses BEFORE UPDATE so we can set NEW.status directly —
--  avoids the MariaDB/MySQL 5.7 restriction on updating the
--  triggering table from an AFTER trigger.
-- ============================================================
DROP TRIGGER IF EXISTS trg_books_sync_status $$
CREATE TRIGGER trg_books_sync_status
BEFORE UPDATE ON books
FOR EACH ROW
BEGIN
    -- Only sync when available_copies actually changed, and the book
    -- is not manually marked as Missing (preserve that status).
    IF NEW.available_copies <> OLD.available_copies AND NEW.status <> 'Missing' THEN
        IF NEW.available_copies = 0 THEN
            SET NEW.status = 'All Issued';
        ELSEIF NEW.available_copies < NEW.total_copies THEN
            SET NEW.status = 'Borrowed';
        ELSE
            SET NEW.status = 'Available';
        END IF;
    END IF;
END $$

DELIMITER ;

