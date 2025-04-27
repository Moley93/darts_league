-- Script to generate league fixtures where each team plays every other team twice
USE darts_league;

-- First, clear existing league fixtures
DELETE FROM matches WHERE match_type = 'league';

-- Generate fixtures for Premier Division
INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date)
SELECT 
    t1.team_id AS home_team_id,
    t2.team_id AS away_team_id,
    'league' AS match_type,
    'premier' AS division,
    'scheduled' AS status,
    DATE_ADD(CURDATE(), INTERVAL FLOOR(RAND() * 180) DAY) AS match_date
FROM teams t1
CROSS JOIN teams t2
WHERE t1.division = 'premier' 
AND t2.division = 'premier'
AND t1.team_id != t2.team_id
AND t1.team_name != 'Bye'
AND t2.team_name != 'Bye'
ORDER BY RAND();

-- Generate fixtures for Division A
INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date)
SELECT 
    t1.team_id AS home_team_id,
    t2.team_id AS away_team_id,
    'league' AS match_type,
    'a' AS division,
    'scheduled' AS status,
    DATE_ADD(CURDATE(), INTERVAL FLOOR(RAND() * 180) DAY) AS match_date
FROM teams t1
CROSS JOIN teams t2
WHERE t1.division = 'a' 
AND t2.division = 'a'
AND t1.team_id != t2.team_id
AND t1.team_name != 'Division A Bye'
AND t2.team_name != 'Division A Bye'
ORDER BY RAND();

-- Update dates to spread them out more evenly throughout the season
UPDATE matches 
SET match_date = DATE_ADD(
    CURDATE(), 
    INTERVAL (
        FLOOR((ROW_NUMBER() OVER (PARTITION BY division ORDER BY RAND()) - 1) / 6) * 7 + 
        FLOOR(RAND() * 7)
    ) DAY
)
WHERE match_type = 'league';

-- Verify the fixtures created
SELECT 
    division,
    COUNT(*) as total_fixtures,
    MIN(match_date) as first_match,
    MAX(match_date) as last_match
FROM matches
WHERE match_type = 'league'
GROUP BY division;

-- Show sample fixtures
SELECT 
    m.match_id,
    m.division,
    th.team_name as home_team,
    ta.team_name as away_team,
    m.match_date
FROM matches m
JOIN teams th ON m.home_team_id = th.team_id
JOIN teams ta ON m.away_team_id = ta.team_id
WHERE m.match_type = 'league'
ORDER BY m.division, m.match_date
LIMIT 20;