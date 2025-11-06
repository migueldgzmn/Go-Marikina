-- Add moderation workflow columns to reports table
-- Run this once on your MySQL database

ALTER TABLE reports
  ADD COLUMN moderation_status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN moderated_by INT NULL AFTER moderation_status,
  ADD COLUMN moderated_at DATETIME NULL AFTER moderated_by,
  ADD COLUMN moderation_notes TEXT NULL AFTER moderated_at,
  ADD INDEX idx_moderation_status (moderation_status),
  ADD INDEX idx_moderated_by (moderated_by);

-- Optional: if you maintain a users table, you may add a foreign key for moderated_by
-- ALTER TABLE reports
--   ADD CONSTRAINT fk_reports_moderator
--   FOREIGN KEY (moderated_by) REFERENCES users(id)
--   ON DELETE SET NULL;