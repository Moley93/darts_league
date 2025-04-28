-- ========================================
-- DARTS LEAGUE DATABASE SCHEMA
-- ========================================

-- Create the database
DROP DATABASE IF EXISTS darts_league;
CREATE DATABASE darts_league;
USE darts_league;

-- ========================================
-- TABLE DEFINITIONS
-- ========================================

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

-- Matches table (adding cup_round column)
CREATE TABLE matches (
    match_id INT PRIMARY KEY AUTO_INCREMENT,
    home_team_id INT,
    away_team_id INT,
    match_date DATE,
    match_type ENUM('league', 'cup') NOT NULL,
    division ENUM('premier', 'a') NOT NULL,
    home_score INT DEFAULT 0,
    away_score INT DEFAULT 0,
    status ENUM('scheduled', 'completed', 'postponed', 'pending') DEFAULT 'scheduled',
    cup_round VARCHAR(50),
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

-- League standings table
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

-- ========================================
-- INDEXES
-- ========================================

CREATE INDEX idx_match_date ON matches(match_date);
CREATE INDEX idx_player_team ON players(team_id);
CREATE INDEX idx_match_division ON matches(division);
CREATE INDEX idx_singles_result_match ON singles_results(match_id);

-- ========================================
-- INITIAL DATA SETUP
-- ========================================

-- Insert teams
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

-- Add players for each team

-- The Unbearables (team_id: 1)
INSERT INTO players (player_name, team_id) VALUES
('John Smith', 1),
('Mike Johnson', 1),
('Chris Davis', 1),
('James Wilson', 1),
('Robert Brown', 1),
('David Miller', 1),
('Richard Garcia', 1),
('Thomas Anderson', 1),
('William Martinez', 1),
('Daniel Taylor', 1);

-- Jubilee Club (team_id: 2)
INSERT INTO players (player_name, team_id) VALUES
('Tom Wilson', 2),
('Steve Brown', 2),
('Dave Miller', 2),
('Paul Thompson', 2),
('Mark Robinson', 2),
('Kevin White', 2),
('Brian Harris', 2),
('Gary Lewis', 2),
('Eric Clark', 2),
('Jason Walker', 2);

-- Rebels (team_id: 3)
INSERT INTO players (player_name, team_id) VALUES
('Peter Moore', 3),
('Andrew Jackson', 3),
('Nicholas Hall', 3),
('Ryan Allen', 3),
('Justin Young', 3),
('Brandon King', 3),
('Samuel Wright', 3),
('Benjamin Scott', 3),
('Nathan Green', 3),
('Alexander Baker', 3);

-- Dark Side Of The Moon (team_id: 4)
INSERT INTO players (player_name, team_id) VALUES
('Matthew Adams', 4),
('Christopher Nelson', 4),
('Joshua Carter', 4),
('Joseph Mitchell', 4),
('Jonathan Perez', 4),
('Adam Roberts', 4),
('Tyler Turner', 4),
('Henry Phillips', 4),
('Aaron Campbell', 4),
('Jacob Parker', 4);

-- Dartbreakers (team_id: 5)
INSERT INTO players (player_name, team_id) VALUES
('George Evans', 5),
('Edward Edwards', 5),
('Ronald Collins', 5),
('Timothy Stewart', 5),
('Jerry Morris', 5),
('Dennis Rogers', 5),
('Frank Reed', 5),
('Scott Cook', 5),
('Roger Morgan', 5),
('Harold Bell', 5);

-- Rugby Club (team_id: 6)
INSERT INTO players (player_name, team_id) VALUES
('Carl Bailey', 6),
('Paul Rivera', 6),
('Larry Cooper', 6),
('Jeffrey Richardson', 6),
('Eugene Cox', 6),
('Russell Howard', 6),
('Albert Ward', 6),
('Fred Torres', 6),
('Keith Peterson', 6),
('Jeremy Gray', 6);

-- Grasshopper Premier (team_id: 7)
INSERT INTO players (player_name, team_id) VALUES
('Stephen James', 7),
('Gregory Watson', 7),
('Raymond Brooks', 7),
('Patrick Kelly', 7),
('Vincent Sanders', 7),
('Lawrence Price', 7),
('Terry Bennett', 7),
('Wayne Wood', 7),
('Joe Barnes', 7),
('Stanley Ross', 7);

-- Ewhurst Arrows (team_id: 9)
INSERT INTO players (player_name, team_id) VALUES
('Philip Butler', 9),
('Craig Simmons', 9),
('Alan Foster', 9),
('Shawn Gonzales', 9),
('Barry Bryant', 9),
('Clarence Alexander', 9),
('Glenn Russell', 9),
('Todd Griffin', 9),
('Ernest Diaz', 9),
('Norman Hayes', 9);

-- Dingles (team_id: 10)
INSERT INTO players (player_name, team_id) VALUES
('Allen Myers', 10),
('Francis Ford', 10),
('Curtis Hamilton', 10),
('Randall Graham', 10),
('Joel Sullivan', 10),
('Walter Wallace', 10),
('Gilbert Woods', 10),
('Wesley Cole', 10),
('Jerome West', 10),
('Joel Jordan', 10);

-- Johnson (team_id: 11)
INSERT INTO players (player_name, team_id) VALUES
('Edwin Owens', 11),
('Maurice Reynolds', 11),
('Clifford Fisher', 11),
('Charlie Ellis', 11),
('Edgar Harrison', 11),
('Milton Gibson', 11),
('Chad McDonald', 11),
('Nathaniel Cruz', 11),
('Sidney Marshall', 11),
('Bernard Ortiz', 11);

-- Disappointers (team_id: 12)
INSERT INTO players (player_name, team_id) VALUES
('Dale Gomez', 12),
('Frederick Murray', 12),
('Willard Freeman', 12),
('Darrell Wells', 12),
('Karl Webb', 12),
('Leo Simpson', 12),
('Alfred Stevens', 12),
('Oscar Tucker', 12),
('Warren Porter', 12),
('Douglas Hunter', 12);

-- APV (team_id: 13)
INSERT INTO players (player_name, team_id) VALUES
('Harold Hicks', 13),
('Arnold Crawford', 13),
('Harvey Henry', 13),
('Gerard Boyd', 13),
('Claude Mason', 13),
('Lewis Morales', 13),
('Luther Kennedy', 13),
('Vernon Warren', 13),
('Chester Dixon', 13),
('Lloyd Ramos', 13);

-- Ewhurst Woodys (team_id: 14)
INSERT INTO players (player_name, team_id) VALUES
('Neil Reyes', 14),
('Ivan Burns', 14),
('Marion Gordon', 14),
('Terrance Shaw', 14),
('Emanuel Holmes', 14),
('Hector Rice', 14),
('Clyde Robertson', 14),
('Rick Hunt', 14),
('Duane Black', 14),
('Marshall Daniels', 14);

-- Pelham (team_id: 15)
INSERT INTO players (player_name, team_id) VALUES
('Hubert Palmer', 15),
('Roland Jordan', 15),
('Lyle Mendoza', 15),
('Phillip Ruiz', 15),
('Alvin Hughes', 15),
('Michael Knight', 15),
('Victor Ferguson', 15),
('Dean Rose', 15),
('Sherman Stone', 15),
('Pete Hawkins', 15);

-- Add team captains
INSERT INTO team_captains (team_id, username, password_hash) VALUES
(1, 'unbearables_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: unbearables123
(2, 'jubilee_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: jubilee123
(3, 'rebels_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: rebels123
(4, 'darkmoon_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: darkmoon123
(5, 'dartbreakers_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: dartbreakers123
(6, 'rugby_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: rugby123
(7, 'grasshopper_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: grasshopper123
(9, 'arrows_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: arrows123
(10, 'dingles_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: dingles123
(11, 'johnson_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: johnson123
(12, 'disappointers_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: disappointers123
(13, 'apv_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: apv123
(14, 'woodys_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: woodys123
(15, 'pelham_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); -- password: pelham123

-- ========================================
-- STORED PROCEDURES
-- ========================================

DELIMITER //

-- Procedure to calculate player rankings
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

-- Procedure to update league standings
CREATE PROCEDURE update_league_standings()
BEGIN
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
END //

-- Procedure to advance cup winner
CREATE PROCEDURE advance_cup_winner(IN match_id_param INT)
BEGIN
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
END //

DELIMITER ;

-- ========================================
-- VERIFICATION QUERIES
-- ========================================

-- Verify teams and players
SELECT t.team_name, COUNT(p.player_id) as player_count 
FROM teams t 
LEFT JOIN players p ON t.team_id = p.team_id 
GROUP BY t.team_id, t.team_name 
ORDER BY t.team_id;

-- ========================================
-- END OF SCHEMA
-- ========================================