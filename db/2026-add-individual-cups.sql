-- =====================================================================
-- Migration: individual cup competitions (3-2-1, Singles, Pairs, Good
-- Friday, Paul/Terry, Memorial and Cap/Sec Cups)
--
-- Run ONCE in phpMyAdmin on the live database. Safe to re-run: every
-- table is created with IF NOT EXISTS. No existing data is changed.
--
-- Each cup can have one draw per division (Premier, A or Combined). A
-- draw is a random knockout of singles, pairs or trebles entries, with
-- byes in the first round and an optional losers bracket for the
-- first-round losers. Results are entered by an admin.
--
-- comp_draws          one row per cup and division: format, best of,
--                     losers bracket on or off, partners, draw date
-- comp_entries        the entries in a draw, in draw order, with their
--                     display name and team(s)
-- comp_entry_players  who makes up each entry: a registered player (the
--                     name is kept if the player is later deleted) or a
--                     guest typed in by name
-- comp_matches        every match in both brackets, linked to the match
--                     its winner (and a first-round loser) moves on to
--
-- Everything cascades from comp_draws, so deleting a draw removes its
-- entries and matches. Season Reset empties all four tables.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `comp_draws` (
  `draw_id`      int(11) NOT NULL AUTO_INCREMENT,
  `cup_slug`     varchar(40) NOT NULL,
  `division`     enum('premier','a','combined') NOT NULL,
  `format`       enum('singles','pairs','trebles') NOT NULL,
  `best_of`      tinyint(4) NOT NULL DEFAULT 3,
  `has_plate`    tinyint(1) NOT NULL DEFAULT 0,
  `partner_mode` enum('fixed','random') NOT NULL DEFAULT 'fixed',
  `draw_date`    date DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at`   timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`draw_id`),
  UNIQUE KEY `uq_comp_draws_cup_division` (`cup_slug`, `division`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `comp_entries` (
  `entry_id`   int(11) NOT NULL AUTO_INCREMENT,
  `draw_id`    int(11) NOT NULL,
  `draw_order` int(11) NOT NULL,
  `name`       varchar(255) NOT NULL,
  `team_name`  varchar(255) DEFAULT NULL,
  PRIMARY KEY (`entry_id`),
  KEY `idx_comp_entries_draw` (`draw_id`),
  CONSTRAINT `comp_entries_draw_fk` FOREIGN KEY (`draw_id`) REFERENCES `comp_draws` (`draw_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `comp_entry_players` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `entry_id`    int(11) NOT NULL,
  `player_id`   int(11) DEFAULT NULL,
  `player_name` varchar(100) NOT NULL,
  `team_name`   varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comp_entry_players_entry` (`entry_id`),
  KEY `idx_comp_entry_players_player` (`player_id`),
  CONSTRAINT `comp_entry_players_entry_fk`  FOREIGN KEY (`entry_id`)  REFERENCES `comp_entries` (`entry_id`) ON DELETE CASCADE,
  CONSTRAINT `comp_entry_players_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `comp_matches` (
  `match_id`            int(11) NOT NULL AUTO_INCREMENT,
  `draw_id`             int(11) NOT NULL,
  `bracket`             enum('main','plate') NOT NULL DEFAULT 'main',
  `round_no`            tinyint(4) NOT NULL,
  `match_no`            smallint(6) NOT NULL,
  `home_entry_id`       int(11) DEFAULT NULL,
  `away_entry_id`       int(11) DEFAULT NULL,
  `winner_entry_id`     int(11) DEFAULT NULL,
  `home_score`          tinyint(4) DEFAULT NULL,
  `away_score`          tinyint(4) DEFAULT NULL,
  `is_bye`              tinyint(1) NOT NULL DEFAULT 0,
  `next_match_id`       int(11) DEFAULT NULL,
  `next_slot`           enum('home','away') DEFAULT NULL,
  `loser_next_match_id` int(11) DEFAULT NULL,
  `loser_next_slot`     enum('home','away') DEFAULT NULL,
  `completed_at`        datetime DEFAULT NULL,
  PRIMARY KEY (`match_id`),
  KEY `idx_comp_matches_draw` (`draw_id`),
  CONSTRAINT `comp_matches_draw_fk` FOREIGN KEY (`draw_id`) REFERENCES `comp_draws` (`draw_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
