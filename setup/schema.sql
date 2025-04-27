-- Create the database
DROP DATABASE IF EXISTS darts_league;
CREATE DATABASE darts_league;
USE darts_league;

-- Teams table
CREATE TABLE teams (
    team_id INT PRIMARY KEY AUTO_INCREMENT,
    team_name VARCHAR(100) NOT NULL,
    division ENUM('premier', 'a') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Players table
CREATE TABLE players (
    player_id INT PRIMARY KEY AUTO_INCREMENT,
    player_name VARCHAR(100) NOT NULL,
    team_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(team_id)
);

-- Team captains (for login)
CREATE TABLE team_captains (
    captain_id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(team_id)
);

-- Matches table
CREATE TABLE matches (
    match_id INT PRIMARY KEY AUTO_INCREMENT,
    home_team_id INT,
    away_team_id INT,
    match_date DATE,
    match_type ENUM('league', 'cup') NOT NULL,
    division ENUM('premier', 'a') NOT NULL,
    home_score INT DEFAULT 0,
    away_score INT DEFAULT 0,
    status ENUM('scheduled', 'completed', 'postponed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (home_team_id) REFERENCES teams(team_id),
    FOREIGN KEY (away_team_id) REFERENCES teams(team_id)
);

-- Singles results table
CREATE TABLE singles_results (
    result_id INT PRIMARY KEY AUTO_INCREMENT,
    match_id INT,
    home_player_id INT,
    away_player_id INT,
    home_score INT,
    away_score INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(match_id),
    FOREIGN KEY (home_player_id) REFERENCES players(player_id),
    FOREIGN KEY (away_player_id) REFERENCES players(player_id)
);

-- Doubles results table
CREATE TABLE doubles_results (
    result_id INT PRIMARY KEY AUTO_INCREMENT,
    match_id INT,
    home_player1_id INT,
    home_player2_id INT,
    away_player1_id INT,
    away_player2_id INT,
    home_score INT,
    away_score INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(match_id),
    FOREIGN KEY (home_player1_id) REFERENCES players(player_id),
    FOREIGN KEY (home_player2_id) REFERENCES players(player_id),
    FOREIGN KEY (away_player1_id) REFERENCES players(player_id),
    FOREIGN KEY (away_player2_id) REFERENCES players(player_id)
);

-- 180s table
CREATE TABLE one_eighties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    match_id INT,
    player_id INT,
    count INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(match_id),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    INDEX idx_one_eighties_match (match_id),
    INDEX idx_one_eighties_player (player_id)
);

-- High finishes table
CREATE TABLE high_finishes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    match_id INT,
    player_id INT,
    finish_value INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(match_id),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    INDEX idx_high_finishes_match (match_id),
    INDEX idx_high_finishes_player (player_id),
    INDEX idx_high_finishes_value (finish_value)
);

-- League standings table (optional, can be calculated from matches)
CREATE TABLE league_standings (
    standing_id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT,
    division ENUM('premier', 'a') NOT NULL,
    played INT DEFAULT 0,
    won INT DEFAULT 0,
    drawn INT DEFAULT 0,
    lost INT DEFAULT 0,
    games_for INT DEFAULT 0,
    games_against INT DEFAULT 0,
    points INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(team_id)
);

-- Insert sample data
INSERT INTO teams (team_name, division) VALUES
('The Unbearables', 'premier'),
('Jubilee Club', 'premier'),
('Rebels', 'premier'),
('Dark Side Of The Moon', 'premier'),
('Dartbreakers', 'premier'),
('Rugby Club', 'premier'),
('Grasshopper Premier', 'premier'),
('Bye', 'premier'),
('Ewhurst Arrows', 'a'),
('Dingles', 'a'),
('Johnson', 'a'),
('Disappointers', 'a'),
('APV', 'a'),
('Ewhurst Woodys', 'a'),
('Pelham', 'a'),
('Division A Bye', 'a');

-- Insert some sample players
INSERT INTO players (player_name, team_id) VALUES
('John Smith', 1),
('Mike Johnson', 1),
('Chris Davis', 1),
('Tom Wilson', 2),
('Steve Brown', 2),
('Dave Miller', 2);

-- Insert sample team captains (password should be hashed in production)
INSERT INTO team_captains (team_id, username, password_hash) VALUES
(1, 'unbearables_captain', 'hashed_password_here'),
(2, 'jubilee_captain', 'hashed_password_here');

-- Create indexes for better performance
CREATE INDEX idx_match_date ON matches(match_date);
CREATE INDEX idx_player_team ON players(team_id);
CREATE INDEX idx_match_division ON matches(division);
CREATE INDEX idx_singles_result_match ON singles_results(match_id);

-- Stored procedure to calculate player rankings
DELIMITER //
CREATE PROCEDURE calculate_player_rankings(IN division_param VARCHAR(10))
BEGIN
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
            -- Premier Division scoring (best of 5)
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 2 THEN 1
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 0 THEN 5
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 1 THEN 3
            WHEN t.division = 'premier' AND sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 2 THEN 1
            -- A Division scoring (best of 3)
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
    GROUP BY p.player_id, p.player_name, t.team_name
    ORDER BY points DESC, won DESC;
END //
DELIMITER ;

-- Stored procedure to update league standings
DELIMITER //
CREATE PROCEDURE update_league_standings()
BEGIN
    -- Clear existing standings
    TRUNCATE TABLE league_standings;
    
    -- Insert updated standings (each game won counts as 1 point)
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
END //
DELIMITER ;

-- Generate league fixtures (home and away for each team)
DELIMITER //
CREATE PROCEDURE generate_league_fixtures(IN division_param VARCHAR(10))
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE team1_id INT;
    DECLARE team2_id INT;
    DECLARE cur1 CURSOR FOR SELECT team_id FROM teams WHERE division = division_param;
    DECLARE cur2 CURSOR FOR SELECT team_id FROM teams WHERE division = division_param;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur1;
    
    read_loop1: LOOP
        FETCH cur1 INTO team1_id;
        IF done THEN
            LEAVE read_loop1;
        END IF;
        
        OPEN cur2;
        read_loop2: LOOP
            FETCH cur2 INTO team2_id;
            IF done THEN
                SET done = FALSE;
                LEAVE read_loop2;
            END IF;
            
            IF team1_id != team2_id THEN
                -- Home fixture
                INSERT INTO matches (home_team_id, away_team_id, match_type, division, status)
                VALUES (team1_id, team2_id, 'league', division_param, 'scheduled');
                
                -- Away fixture
                INSERT INTO matches (home_team_id, away_team_id, match_type, division, status)
                VALUES (team2_id, team1_id, 'league', division_param, 'scheduled');
            END IF;
        END LOOP;
        CLOSE cur2;
    END LOOP;
    CLOSE cur1;
END //
DELIMITER ;