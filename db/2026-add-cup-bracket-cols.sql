-- =====================================================================
-- Migration: add bracket-linking columns to the matches table so cup
-- winners can be advanced to the correct slot of the next round.
--
-- Run this in phpMyAdmin AFTER you have already imported fresh-schema.sql
-- previously. New imports of fresh-schema.sql already include these
-- columns and do not need this migration.
-- =====================================================================

ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `next_match_id`    int(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `next_match_slot`  enum('home','away') DEFAULT NULL;

-- Self-referential FK: if a downstream match is deleted, blank the
-- pointer rather than failing.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'matches'
              AND CONSTRAINT_NAME = 'matches_next_fk');
SET @sql := IF(@fk = 0,
    'ALTER TABLE `matches` ADD CONSTRAINT `matches_next_fk` FOREIGN KEY (`next_match_id`) REFERENCES `matches` (`match_id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
