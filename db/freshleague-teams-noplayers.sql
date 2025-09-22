-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 13, 2025 at 12:01 PM
-- Server version: 10.6.21-MariaDB-0ubuntu0.22.04.2
-- PHP Version: 8.4.5

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `darts_league`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`darts_league`@`%` PROCEDURE `advance_cup_winner` (IN `match_id_param` INT)   BEGIN
    DECLARE winner_id INT;
    DECLARE next_match_id INT;
    DECLARE current_round VARCHAR(50);
    DECLARE division_param VARCHAR(10);
    
    -- Get match details
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
        -- Find the next round match that needs a participant
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
        
        -- Update the next match with the winner
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

CREATE DEFINER=`darts_league`@`%` PROCEDURE `calculate_player_rankings` (IN `division_param` VARCHAR(10), IN `match_type_param` VARCHAR(10))   BEGIN
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
            -- Premier Division scoring (best of 5 for singles)
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 2 THEN 1
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 2 THEN 1
            -- A Division scoring (best of 3 for singles)
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

CREATE DEFINER=`darts_league`@`%` PROCEDURE `update_league_standings` ()   BEGIN
    -- Clear existing standings
    TRUNCATE TABLE league_standings;
    
    -- Insert updated standings
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

-- --------------------------------------------------------

--
-- Table structure for table `doubles_results`
--

CREATE TABLE `doubles_results` (
  `result_id` int(11) NOT NULL,
  `match_id` int(11) DEFAULT NULL,
  `home_player1_id` int(11) DEFAULT NULL,
  `home_player2_id` int(11) DEFAULT NULL,
  `away_player1_id` int(11) DEFAULT NULL,
  `away_player2_id` int(11) DEFAULT NULL,
  `home_score` int(11) DEFAULT NULL,
  `away_score` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `high_finishes`
--

CREATE TABLE `high_finishes` (
  `id` int(11) NOT NULL,
  `match_id` int(11) DEFAULT NULL,
  `player_id` int(11) DEFAULT NULL,
  `finish_value` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `league_standings`
--

CREATE TABLE `league_standings` (
  `standing_id` int(11) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `division` enum('premier','a') NOT NULL,
  `played` int(11) DEFAULT 0,
  `won` int(11) DEFAULT 0,
  `drawn` int(11) DEFAULT 0,
  `lost` int(11) DEFAULT 0,
  `games_for` int(11) DEFAULT 0,
  `games_against` int(11) DEFAULT 0,
  `points` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `matches`
--

CREATE TABLE `matches` (
  `match_id` int(11) NOT NULL,
  `home_team_id` int(11) DEFAULT NULL,
  `away_team_id` int(11) DEFAULT NULL,
  `match_date` date DEFAULT NULL,
  `match_type` enum('league','cup') NOT NULL,
  `division` enum('premier','a') NOT NULL,
  `home_score` int(11) DEFAULT 0,
  `away_score` int(11) DEFAULT 0,
  `status` enum('scheduled','completed','postponed','pending') DEFAULT 'scheduled',
  `cup_round` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `one_eighties`
--

CREATE TABLE `one_eighties` (
  `id` int(11) NOT NULL,
  `match_id` int(11) DEFAULT NULL,
  `player_id` int(11) DEFAULT NULL,
  `count` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `players`
--

CREATE TABLE `players` (
  `player_id` int(11) NOT NULL,
  `player_name` varchar(100) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `singles_results`
--

CREATE TABLE `singles_results` (
  `result_id` int(11) NOT NULL,
  `match_id` int(11) DEFAULT NULL,
  `home_player_id` int(11) DEFAULT NULL,
  `away_player_id` int(11) DEFAULT NULL,
  `home_score` int(11) DEFAULT NULL,
  `away_score` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teams`
--

CREATE TABLE `teams` (
  `team_id` int(11) NOT NULL,
  `team_name` varchar(100) NOT NULL,
  `division` enum('premier','a') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `teams`
--

INSERT INTO `teams` (`team_id`, `team_name`, `division`, `created_at`) VALUES
(1, 'The Unbearables', 'premier', '2025-05-07 11:02:40'),
(2, 'Jubilee Club', 'premier', '2025-05-07 11:02:40'),
(3, 'Rebels', 'premier', '2025-05-07 11:02:40'),
(4, 'Dark Side Of The Moon', 'premier', '2025-05-07 11:02:40'),
(5, 'Dartbreakers', 'premier', '2025-05-07 11:02:40'),
(6, 'Rugby Club', 'premier', '2025-05-07 11:02:40'),
(7, 'Grasshopper', 'premier', '2025-05-07 11:02:40'),
(8, 'Bye', 'premier', '2025-05-07 11:02:40'),
(9, 'Ewhurst Arrows', 'a', '2025-05-07 11:02:40'),
(10, 'Dingles', 'a', '2025-05-07 11:02:40'),
(11, 'Johnson', 'a', '2025-05-07 11:02:40'),
(12, 'Disappointers', 'a', '2025-05-07 11:02:40'),
(13, 'APV', 'a', '2025-05-07 11:02:40'),
(14, 'Ewhurst Woodys', 'a', '2025-05-07 11:02:40'),
(15, 'Pelham', 'a', '2025-05-07 11:02:40'),
(16, 'Division A Bye', 'a', '2025-05-07 11:02:40');

-- --------------------------------------------------------

--
-- Table structure for table `team_captains`
--

CREATE TABLE `team_captains` (
  `captain_id` int(11) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `team_captains`
--

INSERT INTO `team_captains` (`captain_id`, `team_id`, `username`, `password_hash`, `created_at`) VALUES
(15, 1, 'unbearables', '$2y$10$4iPe7BCmGP6C6ysvju8yv.bmvYuvQ0qPuBs.bnHtxoHYTT8cm0IFa', '2025-05-07 11:48:49'),
(16, 2, 'jubileeclub', '$2y$10$iER28W/neqKXf5w7ErNBBu3Bo1aA/.XX6.og4T675YTS2lk8bFhD.', '2025-05-07 11:48:49'),
(17, 3, 'rebels', '$2y$10$pBl7U9F8lCeDl/eXGHnh6OHFGaFl9uw9xiSETwVBH6BgUF7siAy3G', '2025-05-07 11:48:49'),
(18, 4, 'dsotm', '$2y$10$gQJ/S0MHE0YIiEMxy9bZOuFWG/DjB/Cijz5O7AUaviZOTFxdjy5cS', '2025-05-07 11:48:49'),
(19, 5, 'dartbreakers', '$2y$10$qfnu2cDb6K55nexHIsmDj.81UQGPrffqDigAFWqEhNNwAJ9KGF08K', '2025-05-07 11:48:49'),
(20, 6, 'rugbyclub', '$2y$10$HCdan4YAdb/QYvP/XSJUJO/8xSOFEynNTm3PW7vkItKi8VPoFaAqy', '2025-05-07 11:48:49'),
(21, 7, 'grasshopper', '$2y$10$WYL1i5VzMUybdYWK6IPZ5OpydrlM/vd55wXQPi.yPwVcK53gErcmC', '2025-05-07 11:48:49'),
(22, 8, 'premier_bye', '$2y$10$Tkj1tp00zaIk0goPx90pv.bsdU28JwpsOGBWD5lcgLKy.LMIeCr/y', '2025-05-07 11:48:49'),
(23, 9, 'ewhurstarrows', '$2y$10$SzBrufnAJ3LjZ7xNZn8/XOV/olBnIDPjHrb56.91/BXWY0p0wGPv6', '2025-05-07 11:48:49'),
(24, 10, 'dingles', '$2y$10$LlOSq2W6rW9KLTS9Oq0jZ.Iz/s4fG/NJnGQKwXdfPtJRA5jrPiUCa', '2025-05-07 11:48:50'),
(25, 11, 'johnson', '$2y$10$mL7PJYLfz85eD6aJb0PCW.USDcyW4U4yHvPDfGaF8H0xdaq8iR76C', '2025-05-07 11:48:50'),
(26, 12, 'disappointers', '$2y$10$ZOdG.Z86WuFso2kPnWkBQO6veB264qKadWGzXCmKQiQqxlbBHfOHK', '2025-05-07 11:48:50'),
(27, 13, 'apv', '$2y$10$q1sciwOj0wRHwN7WokSQKO9LqqawUMFsY1okxz226mdzZz5OhIKhS', '2025-05-07 11:48:50'),
(28, 14, 'ewhurstwoodys', '$2y$10$.hXtkRivvwSYeI0PplShNOVphqlgQJ3G8yZf66I/BQY3Jup94E6je', '2025-05-07 11:48:50'),
(29, 15, 'pelham', '$2y$10$V6nE2jlKr7xXH6ceV.yQgeSk4SmIUDikxFS.tO6p6ow3g1Mu8qZ7S', '2025-05-07 11:48:50'),
(30, 16, 'division_a_bye', '$2y$10$ozaJrB0fdTBlP4eivs29Be5SuykUME29YeSWKRCCP2JHfqRaR4GH6', '2025-05-07 11:48:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `doubles_results`
--
ALTER TABLE `doubles_results`
  ADD PRIMARY KEY (`result_id`),
  ADD KEY `match_id` (`match_id`),
  ADD KEY `home_player1_id` (`home_player1_id`),
  ADD KEY `home_player2_id` (`home_player2_id`),
  ADD KEY `away_player1_id` (`away_player1_id`),
  ADD KEY `away_player2_id` (`away_player2_id`);

--
-- Indexes for table `high_finishes`
--
ALTER TABLE `high_finishes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_high_finishes_match` (`match_id`),
  ADD KEY `idx_high_finishes_player` (`player_id`),
  ADD KEY `idx_high_finishes_value` (`finish_value`);

--
-- Indexes for table `league_standings`
--
ALTER TABLE `league_standings`
  ADD PRIMARY KEY (`standing_id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `matches`
--
ALTER TABLE `matches`
  ADD PRIMARY KEY (`match_id`),
  ADD KEY `home_team_id` (`home_team_id`),
  ADD KEY `away_team_id` (`away_team_id`),
  ADD KEY `idx_match_date` (`match_date`),
  ADD KEY `idx_match_division` (`division`);

--
-- Indexes for table `one_eighties`
--
ALTER TABLE `one_eighties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_one_eighties_match` (`match_id`),
  ADD KEY `idx_one_eighties_player` (`player_id`);

--
-- Indexes for table `players`
--
ALTER TABLE `players`
  ADD PRIMARY KEY (`player_id`),
  ADD KEY `idx_player_team` (`team_id`);

--
-- Indexes for table `singles_results`
--
ALTER TABLE `singles_results`
  ADD PRIMARY KEY (`result_id`),
  ADD KEY `home_player_id` (`home_player_id`),
  ADD KEY `away_player_id` (`away_player_id`),
  ADD KEY `idx_singles_result_match` (`match_id`);

--
-- Indexes for table `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`team_id`);

--
-- Indexes for table `team_captains`
--
ALTER TABLE `team_captains`
  ADD PRIMARY KEY (`captain_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `team_id` (`team_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `doubles_results`
--
ALTER TABLE `doubles_results`
  MODIFY `result_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `high_finishes`
--
ALTER TABLE `high_finishes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `league_standings`
--
ALTER TABLE `league_standings`
  MODIFY `standing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `matches`
--
ALTER TABLE `matches`
  MODIFY `match_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `one_eighties`
--
ALTER TABLE `one_eighties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `players`
--
ALTER TABLE `players`
  MODIFY `player_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `singles_results`
--
ALTER TABLE `singles_results`
  MODIFY `result_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `teams`
--
ALTER TABLE `teams`
  MODIFY `team_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `team_captains`
--
ALTER TABLE `team_captains`
  MODIFY `captain_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `doubles_results`
--
ALTER TABLE `doubles_results`
  ADD CONSTRAINT `doubles_results_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`),
  ADD CONSTRAINT `doubles_results_ibfk_2` FOREIGN KEY (`home_player1_id`) REFERENCES `players` (`player_id`),
  ADD CONSTRAINT `doubles_results_ibfk_3` FOREIGN KEY (`home_player2_id`) REFERENCES `players` (`player_id`),
  ADD CONSTRAINT `doubles_results_ibfk_4` FOREIGN KEY (`away_player1_id`) REFERENCES `players` (`player_id`),
  ADD CONSTRAINT `doubles_results_ibfk_5` FOREIGN KEY (`away_player2_id`) REFERENCES `players` (`player_id`);

--
-- Constraints for table `high_finishes`
--
ALTER TABLE `high_finishes`
  ADD CONSTRAINT `high_finishes_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`),
  ADD CONSTRAINT `high_finishes_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`);

--
-- Constraints for table `league_standings`
--
ALTER TABLE `league_standings`
  ADD CONSTRAINT `league_standings_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`);

--
-- Constraints for table `matches`
--
ALTER TABLE `matches`
  ADD CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`team_id`);

--
-- Constraints for table `one_eighties`
--
ALTER TABLE `one_eighties`
  ADD CONSTRAINT `one_eighties_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`),
  ADD CONSTRAINT `one_eighties_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`);

--
-- Constraints for table `players`
--
ALTER TABLE `players`
  ADD CONSTRAINT `players_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`);

--
-- Constraints for table `singles_results`
--
ALTER TABLE `singles_results`
  ADD CONSTRAINT `singles_results_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`),
  ADD CONSTRAINT `singles_results_ibfk_2` FOREIGN KEY (`home_player_id`) REFERENCES `players` (`player_id`),
  ADD CONSTRAINT `singles_results_ibfk_3` FOREIGN KEY (`away_player_id`) REFERENCES `players` (`player_id`);

--
-- Constraints for table `team_captains`
--
ALTER TABLE `team_captains`
  ADD CONSTRAINT `team_captains_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
