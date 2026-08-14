-- ============================================================
-- Migration 009: Expand destinations.status enum to support closed/maintenance
-- ============================================================

-- The admin destinations manager offers active/inactive/closed/maintenance
-- statuses (and the JSON AJAX list + set_status action use them), but the
-- column was originally defined as ENUM('active','inactive'). In non-strict
-- mode invalid values silently stored as '' leaving rows in a broken state.
ALTER TABLE destinations
    MODIFY COLUMN status ENUM('active','inactive','closed','maintenance')
        NOT NULL DEFAULT 'active';