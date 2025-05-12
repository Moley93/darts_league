-- These SQL statements will update your database to support the cup automation functionality

-- 1. Add match_number column to the matches table if it doesn't exist
ALTER TABLE matches ADD COLUMN IF NOT EXISTS match_number INT;

-- 2. Create the venues table if it doesn't exist
CREATE TABLE IF NOT EXISTS venues (
  venue_id INT AUTO_INCREMENT PRIMARY KEY,
  venue_name VARCHAR(100) NOT NULL,
  address VARCHAR(255),
  postcode VARCHAR(10),
  phone VARCHAR(20),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. Add venue_id to teams table if it doesn't exist
ALTER TABLE teams ADD COLUMN IF NOT EXISTS venue_id INT;

-- 4. Update stored procedure for cup advancement
DELIMITER //

DROP PROCEDURE IF EXISTS advance_cup_winner //

CREATE PROCEDURE advance_cup_winner(IN p_match_id INT)
BEGIN
    DECLARE v_home_score INT;
    DECLARE v_away_score INT;
    DECLARE v_home_team_id INT;
    DECLARE v_away_team_id INT;
    DECLARE v_winner_team_id INT;
    DECLARE v_cup_round VARCHAR(50);
    DECLARE v_match_number INT;
    DECLARE v_division VARCHAR(50);
    DECLARE v_next_round VARCHAR(50);
    DECLARE v_next_match_number INT;