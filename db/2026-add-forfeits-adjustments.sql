-- =====================================================================
-- Migration: forfeits (walkovers) + season-long points adjustments
--
-- Run ONCE in phpMyAdmin on the live database. Safe to re-run: every
-- statement is guarded with IF NOT EXISTS. No existing data is changed.
--
-- 1. matches.is_walkover / matches.forfeit_team_id
--    A walkover is stored as a normal completed league match carrying a
--    score (e.g. 9-0) but NO singles/doubles/180/high-finish rows. The
--    standings procedure counts the score as points, while player
--    rankings (computed from singles_results) are untouched.
--
-- 2. points_adjustments
--    Bonus/penalty points layered on top of earned points when the
--    league table is READ. update_league_standings() never touches this
--    table, so a deduction can't be wiped by a later score submission.
--    A deduction created alongside a walkover is linked to it via
--    match_id (ON DELETE CASCADE): deleting the walkover removes it too.
-- =====================================================================

ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `is_walkover`     tinyint(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `forfeit_team_id` int(11)    DEFAULT NULL;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'matches'
              AND CONSTRAINT_NAME = 'matches_forfeit_fk');
SET @sql := IF(@fk = 0,
    'ALTER TABLE `matches` ADD CONSTRAINT `matches_forfeit_fk` FOREIGN KEY (`forfeit_team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `points_adjustments` (
  `adjustment_id`       int(11) NOT NULL AUTO_INCREMENT,
  `team_id`             int(11) NOT NULL,
  `points`              int(11) NOT NULL,
  `reason`              varchar(255) NOT NULL,
  `match_id`            int(11) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at`          timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  KEY `idx_adj_team` (`team_id`),
  KEY `idx_adj_match` (`match_id`),
  CONSTRAINT `points_adjustments_team_fk`  FOREIGN KEY (`team_id`)             REFERENCES `teams` (`team_id`)    ON DELETE CASCADE,
  CONSTRAINT `points_adjustments_match_fk` FOREIGN KEY (`match_id`)            REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `points_adjustments_admin_fk` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
