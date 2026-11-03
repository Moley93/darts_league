-- =====================================================================
-- Migration: cup match games (trebles, singles and the 1001 team game)
--
-- Run ONCE in phpMyAdmin on the live database. Safe to re-run: both
-- tables are created with IF NOT EXISTS. No existing data is changed.
--
-- Cup matches have their own format – 2 trebles, 6 singles and, at
-- 4-4, a 1001 team game – and must not count towards players'
-- individual stats. Their games are therefore stored here instead of
-- in singles_results / doubles_results, which the player rankings read.
-- 180s and high finishes from cup matches still go into one_eighties /
-- high_finishes so they show on the 180s & High Finishes page.
--
-- cup_games         one row per game played: type, number, leg score
-- cup_game_players  who played in each game, and for which side
--
-- Both cascade from matches, so a new cup draw (which deletes the old
-- cup matches) clears them automatically.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `cup_games` (
  `game_id`    int(11) NOT NULL AUTO_INCREMENT,
  `match_id`   int(11) NOT NULL,
  `game_type`  enum('trebles','singles','team1001') NOT NULL,
  `game_no`    tinyint(4) NOT NULL,
  `home_score` int(11) NOT NULL DEFAULT 0,
  `away_score` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`game_id`),
  KEY `idx_cup_games_match` (`match_id`),
  CONSTRAINT `cup_games_match_fk` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `cup_game_players` (
  `id`        int(11) NOT NULL AUTO_INCREMENT,
  `game_id`   int(11) NOT NULL,
  `side`      enum('home','away') NOT NULL,
  `player_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cup_game_players_game` (`game_id`),
  KEY `idx_cup_game_players_player` (`player_id`),
  CONSTRAINT `cup_game_players_game_fk`   FOREIGN KEY (`game_id`)   REFERENCES `cup_games` (`game_id`)   ON DELETE CASCADE,
  CONSTRAINT `cup_game_players_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
