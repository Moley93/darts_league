-- =====================================================================
-- Crawley Darts League — Fresh Schema
-- Importable via phpMyAdmin into an existing `darts_league` database.
-- Drops all existing tables/procedures, recreates the schema with
-- empty data, and adds two new tables:
--   * admins           — admin login accounts
--   * player_requests  — pending player additions awaiting admin approval
--
-- After importing, visit /setup-admin.php ONCE in your browser to seed
-- the initial admin account, then delete setup-admin.php from the host.
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ---------------------------------------------------------------------
-- Drop everything for a clean slate
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS `advance_cup_winner`;
DROP PROCEDURE IF EXISTS `calculate_player_rankings`;
DROP PROCEDURE IF EXISTS `update_league_standings`;

DROP TABLE IF EXISTS `venues`;
DROP TABLE IF EXISTS `site_settings`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `player_requests`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `doubles_results`;
DROP TABLE IF EXISTS `singles_results`;
DROP TABLE IF EXISTS `high_finishes`;
DROP TABLE IF EXISTS `one_eighties`;
DROP TABLE IF EXISTS `league_standings`;
DROP TABLE IF EXISTS `matches`;
DROP TABLE IF EXISTS `team_captains`;
DROP TABLE IF EXISTS `players`;
DROP TABLE IF EXISTS `teams`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Stored procedures
-- ---------------------------------------------------------------------

DELIMITER $$

CREATE PROCEDURE `advance_cup_winner` (IN `match_id_param` INT)   BEGIN
    DECLARE winner_id INT;
    DECLARE next_match_id INT;
    DECLARE current_round VARCHAR(50);
    DECLARE division_param VARCHAR(10);

    SELECT
        CASE
            WHEN home_score > away_score THEN home_team_id
            WHEN away_score > home_score THEN away_team_id
            ELSE 0
        END,
        cup_round,
        division
    INTO winner_id, current_round, division_param
    FROM matches
    WHERE match_id = match_id_param
        AND match_type = 'cup'
        AND status = 'completed';

    IF winner_id > 0 THEN
        SELECT match_id INTO next_match_id
        FROM matches
        WHERE match_type = 'cup'
            AND division = division_param
            AND status = 'pending'
            AND (home_team_id = 0 OR away_team_id = 0)
            AND cup_round = (
                SELECT cup_round
                FROM matches
                WHERE match_type = 'cup'
                    AND division = division_param
                    AND match_date > (SELECT match_date FROM matches WHERE match_id = match_id_param)
                ORDER BY match_date ASC
                LIMIT 1
            )
        ORDER BY match_id ASC
        LIMIT 1;

        IF next_match_id IS NOT NULL THEN
            UPDATE matches
            SET
                home_team_id = CASE WHEN home_team_id = 0 THEN winner_id ELSE home_team_id END,
                away_team_id = CASE WHEN home_team_id != 0 AND away_team_id = 0 THEN winner_id ELSE away_team_id END,
                status = CASE WHEN (home_team_id != 0 OR home_team_id = winner_id) AND
                                   (away_team_id != 0 OR away_team_id = winner_id)
                         THEN 'scheduled' ELSE 'pending' END
            WHERE match_id = next_match_id;
        END IF;
    END IF;
END$$

CREATE PROCEDURE `calculate_player_rankings` (IN `division_param` VARCHAR(10), IN `match_type_param` VARCHAR(10))   BEGIN
    SELECT
        p.player_id,
        p.player_name,
        t.team_name,
        COUNT(sr.result_id) as played,
        SUM(CASE
            WHEN (sr.home_player_id = p.player_id AND sr.home_score > sr.away_score) OR
                 (sr.away_player_id = p.player_id AND sr.away_score > sr.home_score)
            THEN 1 ELSE 0
        END) as won,
        SUM(CASE
            WHEN (sr.home_player_id = p.player_id AND sr.home_score < sr.away_score) OR
                 (sr.away_player_id = p.player_id AND sr.away_score < sr.home_score)
            THEN 1 ELSE 0
        END) as lost,
        SUM(CASE
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 2 THEN 1
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 2 THEN 1
            WHEN t.division = 'a' AND sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 0 THEN 3
            WHEN t.division = 'a' AND sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 1 THEN 2
            WHEN t.division = 'a' AND sr.home_player_id = p.player_id AND sr.home_score = 1 AND sr.away_score = 2 THEN 1
            WHEN t.division = 'a' AND sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 0 THEN 3
            WHEN t.division = 'a' AND sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 1 THEN 2
            WHEN t.division = 'a' AND sr.away_player_id = p.player_id AND sr.away_score = 1 AND sr.home_score = 2 THEN 1
            ELSE 0
        END) as points
    FROM players p
    JOIN teams t ON p.team_id = t.team_id
    LEFT JOIN singles_results sr ON p.player_id = sr.home_player_id OR p.player_id = sr.away_player_id
    LEFT JOIN matches m ON sr.match_id = m.match_id
    WHERE t.division = division_param
      AND (match_type_param IS NULL OR m.match_type = match_type_param)
    GROUP BY p.player_id, p.player_name, t.team_name
    ORDER BY points DESC, won DESC;
END$$

CREATE PROCEDURE `update_league_standings` ()   BEGIN
    TRUNCATE TABLE league_standings;

    INSERT INTO league_standings (team_id, division, played, won, drawn, lost, games_for, games_against, points)
    SELECT
        t.team_id,
        t.division,
        COUNT(DISTINCT CASE WHEN m.status = 'completed' THEN m.match_id END) as played,
        COUNT(CASE WHEN m.status = 'completed' AND
            ((m.home_team_id = t.team_id AND m.home_score > m.away_score) OR
             (m.away_team_id = t.team_id AND m.away_score > m.home_score)) THEN 1 END) as won,
        COUNT(CASE WHEN m.status = 'completed' AND m.home_score = m.away_score THEN 1 END) as drawn,
        COUNT(CASE WHEN m.status = 'completed' AND
            ((m.home_team_id = t.team_id AND m.home_score < m.away_score) OR
             (m.away_team_id = t.team_id AND m.away_score < m.home_score)) THEN 1 END) as lost,
        COALESCE(SUM(CASE WHEN m.home_team_id = t.team_id THEN m.home_score ELSE 0 END), 0) +
        COALESCE(SUM(CASE WHEN m.away_team_id = t.team_id THEN m.away_score ELSE 0 END), 0) as games_for,
        COALESCE(SUM(CASE WHEN m.home_team_id = t.team_id THEN m.away_score ELSE 0 END), 0) +
        COALESCE(SUM(CASE WHEN m.away_team_id = t.team_id THEN m.home_score ELSE 0 END), 0) as games_against,
        COALESCE(SUM(CASE WHEN m.home_team_id = t.team_id THEN m.home_score ELSE 0 END), 0) +
        COALESCE(SUM(CASE WHEN m.away_team_id = t.team_id THEN m.away_score ELSE 0 END), 0) as points
    FROM teams t
    LEFT JOIN matches m ON (t.team_id = m.home_team_id OR t.team_id = m.away_team_id)
        AND m.match_type = 'league'
        AND m.status = 'completed'
    GROUP BY t.team_id, t.division;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- Core tables
-- ---------------------------------------------------------------------

CREATE TABLE `teams` (
  `team_id` int(11) NOT NULL AUTO_INCREMENT,
  `team_name` varchar(100) NOT NULL,
  `division` enum('premier','a') NOT NULL,
  `venue` varchar(100) DEFAULT NULL,
  `secretary_name` varchar(100) DEFAULT NULL,
  `secretary_phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `players` (
  `player_id` int(11) NOT NULL AUTO_INCREMENT,
  `player_name` varchar(100) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`player_id`),
  KEY `idx_player_team` (`team_id`),
  CONSTRAINT `players_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `team_captains` (
  `captain_id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`captain_id`),
  UNIQUE KEY `username` (`username`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `team_captains_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `matches` (
  `match_id` int(11) NOT NULL AUTO_INCREMENT,
  `home_team_id` int(11) DEFAULT NULL,
  `away_team_id` int(11) DEFAULT NULL,
  `match_date` date DEFAULT NULL,
  `match_type` enum('league','cup') NOT NULL,
  `division` enum('premier','a') NOT NULL,
  `home_score` int(11) DEFAULT 0,
  `away_score` int(11) DEFAULT 0,
  `status` enum('scheduled','completed','postponed','pending') DEFAULT 'scheduled',
  `cup_round` varchar(50) DEFAULT NULL,
  `submitted_by_captain_id` int(11) DEFAULT NULL,
  -- Cup bracket linkage: when a cup match completes its winner is
  -- written into `next_match_id`'s `next_match_slot` (home or away).
  -- Both are NULL for league matches and for the final round.
  `next_match_id` int(11) DEFAULT NULL,
  `next_match_slot` enum('home','away') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`match_id`),
  KEY `home_team_id` (`home_team_id`),
  KEY `away_team_id` (`away_team_id`),
  KEY `idx_match_date` (`match_date`),
  KEY `idx_match_division` (`division`),
  KEY `idx_submitted_by` (`submitted_by_captain_id`),
  KEY `idx_next_match` (`next_match_id`),
  CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`team_id`),
  CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`team_id`),
  CONSTRAINT `matches_ibfk_3` FOREIGN KEY (`submitted_by_captain_id`) REFERENCES `team_captains` (`captain_id`) ON DELETE SET NULL,
  CONSTRAINT `matches_next_fk` FOREIGN KEY (`next_match_id`) REFERENCES `matches` (`match_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `singles_results` (
  `result_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) DEFAULT NULL,
  `home_player_id` int(11) DEFAULT NULL,
  `away_player_id` int(11) DEFAULT NULL,
  `home_score` int(11) DEFAULT NULL,
  `away_score` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`result_id`),
  KEY `home_player_id` (`home_player_id`),
  KEY `away_player_id` (`away_player_id`),
  KEY `idx_singles_result_match` (`match_id`),
  CONSTRAINT `singles_results_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `singles_results_ibfk_2` FOREIGN KEY (`home_player_id`) REFERENCES `players` (`player_id`),
  CONSTRAINT `singles_results_ibfk_3` FOREIGN KEY (`away_player_id`) REFERENCES `players` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `doubles_results` (
  `result_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) DEFAULT NULL,
  `home_player1_id` int(11) DEFAULT NULL,
  `home_player2_id` int(11) DEFAULT NULL,
  `away_player1_id` int(11) DEFAULT NULL,
  `away_player2_id` int(11) DEFAULT NULL,
  `home_score` int(11) DEFAULT NULL,
  `away_score` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`result_id`),
  KEY `match_id` (`match_id`),
  KEY `home_player1_id` (`home_player1_id`),
  KEY `home_player2_id` (`home_player2_id`),
  KEY `away_player1_id` (`away_player1_id`),
  KEY `away_player2_id` (`away_player2_id`),
  CONSTRAINT `doubles_results_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `doubles_results_ibfk_2` FOREIGN KEY (`home_player1_id`) REFERENCES `players` (`player_id`),
  CONSTRAINT `doubles_results_ibfk_3` FOREIGN KEY (`home_player2_id`) REFERENCES `players` (`player_id`),
  CONSTRAINT `doubles_results_ibfk_4` FOREIGN KEY (`away_player1_id`) REFERENCES `players` (`player_id`),
  CONSTRAINT `doubles_results_ibfk_5` FOREIGN KEY (`away_player2_id`) REFERENCES `players` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `high_finishes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) DEFAULT NULL,
  `player_id` int(11) DEFAULT NULL,
  `finish_value` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_high_finishes_match` (`match_id`),
  KEY `idx_high_finishes_player` (`player_id`),
  KEY `idx_high_finishes_value` (`finish_value`),
  CONSTRAINT `high_finishes_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `high_finishes_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `one_eighties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) DEFAULT NULL,
  `player_id` int(11) DEFAULT NULL,
  `count` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_one_eighties_match` (`match_id`),
  KEY `idx_one_eighties_player` (`player_id`),
  CONSTRAINT `one_eighties_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `one_eighties_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `league_standings` (
  `standing_id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) DEFAULT NULL,
  `division` enum('premier','a') NOT NULL,
  `played` int(11) DEFAULT 0,
  `won` int(11) DEFAULT 0,
  `drawn` int(11) DEFAULT 0,
  `lost` int(11) DEFAULT 0,
  `games_for` int(11) DEFAULT 0,
  `games_against` int(11) DEFAULT 0,
  `points` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`standing_id`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `league_standings_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------------------------------
-- NEW: admin login table
-- ---------------------------------------------------------------------

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------------------------------
-- NEW: player addition requests from team captains
-- Statuses: 'pending' (default), 'approved', 'denied'.
-- On approval, the api inserts a row into `players` and sets the
-- request to 'approved'. On denial, no player row is created.
-- ---------------------------------------------------------------------

CREATE TABLE `player_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `requested_by_captain_id` int(11) DEFAULT NULL,
  `player_name` varchar(100) NOT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `reviewed_by_admin_id` int(11) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_pr_team` (`team_id`),
  KEY `idx_pr_status` (`status`),
  KEY `idx_pr_captain` (`requested_by_captain_id`),
  KEY `idx_pr_admin` (`reviewed_by_admin_id`),
  CONSTRAINT `player_requests_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
  CONSTRAINT `player_requests_captain_fk` FOREIGN KEY (`requested_by_captain_id`) REFERENCES `team_captains` (`captain_id`) ON DELETE SET NULL,
  CONSTRAINT `player_requests_admin_fk` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------------------------------
-- NEW: League announcements posted by admins and shown on index.html.
-- ---------------------------------------------------------------------

-- Key-value store for admin-editable settings: Google Sheets / Doc URLs
-- consumed by leaguefixtures.html, rules.html, agm-minutes.html.
CREATE TABLE `site_settings` (
  `setting_key`   varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at`    timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Venue directory used by contactinfo.html.
CREATE TABLE `venues` (
  `venue_id`   int(11) NOT NULL AUTO_INCREMENT,
  `venue_name` varchar(100) NOT NULL,
  `address`    varchar(255) DEFAULT NULL,
  `phone`      varchar(50)  DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`venue_id`),
  KEY `idx_venue_name` (`venue_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_announcements_created` (`created_at`),
  KEY `idx_announcements_admin` (`created_by_admin_id`),
  CONSTRAINT `announcements_admin_fk` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------------------------------
-- Done. After importing, visit /setup-admin.php once to create the
-- initial admin user (username: admin / password: changeme123).
-- Then DELETE setup-admin.php from your host for security.
-- ---------------------------------------------------------------------

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
