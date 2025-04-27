-- Script to update team names in the Crawley Darts League
USE darts_league;

-- First, delete existing teams and related data
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE teams;
TRUNCATE TABLE players;
TRUNCATE TABLE team_captains;
TRUNCATE TABLE matches;
TRUNCATE TABLE singles_results;
TRUNCATE TABLE doubles_results;
TRUNCATE TABLE league_standings;
TRUNCATE TABLE one_eighties;
TRUNCATE TABLE high_finishes;
SET FOREIGN_KEY_CHECKS = 1;

-- Insert new teams
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
('Johnson Disappointers', 'a'),
('APV', 'a'),
('Ewhurst Woodys', 'a'),
('Pelham', 'a'),
('Division A Bye', 'a');

-- Add dummy players to each team (except bye teams)
INSERT INTO players (player_name, team_id) VALUES
-- The Unbearables (team_id: 1)
('John Smith', 1),
('Mike Johnson', 1),
('Chris Davis', 1),
('James Wilson', 1),
('Robert Brown', 1),
('David Miller', 1),
('Richard Garcia', 1),
('Thomas Anderson', 1),
('William Martinez', 1),
('Daniel Taylor', 1),

-- Jubilee Club (team_id: 2)
('Tom Wilson', 2),
('Steve Brown', 2),
('Dave Miller', 2),
('Paul Thompson', 2),
('Mark Robinson', 2),
('Kevin White', 2),
('Brian Harris', 2),
('Gary Lewis', 2),
('Eric Clark', 2),
('Jason Walker', 2),

-- Rebels (team_id: 3)
('Peter Moore', 3),
('Andrew Jackson', 3),
('Nicholas Hall', 3),
('Ryan Allen', 3),
('Justin Young', 3),
('Brandon King', 3),
('Samuel Wright', 3),
('Benjamin Scott', 3),
('Nathan Green', 3),
('Alexander Baker', 3),

-- Dark Side Of The Moon (team_id: 4)
('Matthew Adams', 4),
('Christopher Nelson', 4),
('Joshua Carter', 4),
('Joseph Mitchell', 4),
('Jonathan Perez', 4),
('Adam Roberts', 4),
('Tyler Turner', 4),
('Henry Phillips', 4),
('Aaron Campbell', 4),
('Jacob Parker', 4),

-- Dartbreakers (team_id: 5)
('George Evans', 5),
('Edward Edwards', 5),
('Ronald Collins', 5),
('Timothy Stewart', 5),
('Jerry Morris', 5),
('Dennis Rogers', 5),
('Frank Reed', 5),
('Scott Cook', 5),
('Roger Morgan', 5),
('Harold Bell', 5),

-- Rugby Club (team_id: 6)
('Carl Bailey', 6),
('Paul Rivera', 6),
('Larry Cooper', 6),
('Jeffrey Richardson', 6),
('Eugene Cox', 6),
('Russell Howard', 6),
('Albert Ward', 6),
('Fred Torres', 6),
('Keith Peterson', 6),
('Jeremy Gray', 6),

-- Grasshopper Premier (team_id: 7)
('Stephen James', 7),
('Gregory Watson', 7),
('Raymond Brooks', 7),
('Patrick Kelly', 7),
('Vincent Sanders', 7),
('Lawrence Price', 7),
('Terry Bennett', 7),
('Wayne Wood', 7),
('Joe Barnes', 7),
('Stanley Ross', 7),

-- Ewhurst Arrows (team_id: 9)
('Philip Butler', 9),
('Craig Simmons', 9),
('Alan Foster', 9),
('Shawn Gonzales', 9),
('Barry Bryant', 9),
('Clarence Alexander', 9),
('Glenn Russell', 9),
('Todd Griffin', 9),
('Ernest Diaz', 9),
('Norman Hayes', 9),

-- Dingles (team_id: 10)
('Allen Myers', 10),
('Francis Ford', 10),
('Curtis Hamilton', 10),
('Randall Graham', 10),
('Joel Sullivan', 10),
('Walter Wallace', 10),
('Gilbert Woods', 10),
('Wesley Cole', 10),
('Jerome West', 10),
('Joel Jordan', 10),

-- Johnson Disappointers (team_id: 11)
('Edwin Owens', 11),
('Maurice Reynolds', 11),
('Clifford Fisher', 11),
('Charlie Ellis', 11),
('Edgar Harrison', 11),
('Milton Gibson', 11),
('Chad McDonald', 11),
('Nathaniel Cruz', 11),
('Sidney Marshall', 11),
('Bernard Ortiz', 11),

-- APV (team_id: 12)
('Dale Gomez', 12),
('Frederick Murray', 12),
('Willard Freeman', 12),
('Darrell Wells', 12),
('Karl Webb', 12),
('Leo Simpson', 12),
('Alfred Stevens', 12),
('Oscar Tucker', 12),
('Warren Porter', 12),
('Douglas Hunter', 12),

-- Ewhurst Woodys (team_id: 13)
('Harold Hicks', 13),
('Arnold Crawford', 13),
('Harvey Henry', 13),
('Gerard Boyd', 13),
('Claude Mason', 13),
('Lewis Morales', 13),
('Luther Kennedy', 13),
('Vernon Warren', 13),
('Chester Dixon', 13),
('Lloyd Ramos', 13),

-- Pelham (team_id: 14)
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
(12, 'apv_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: apv123
(13, 'woodys_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password: woodys123
(14, 'pelham_captain', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); -- password: pelham123