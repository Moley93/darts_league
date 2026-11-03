-- =====================================================================
-- Data update: venues, team venues and team secretaries (November 2026)
--
-- Run ONCE in phpMyAdmin on the live database: select the darts_league
-- database, open the SQL tab, paste this file and click Go.
-- Safe to re-run. Nothing is deleted.
--
-- 1. Adds the 8 league venues with their phone numbers, skipping any
--    venue whose name is already in the venues table.
-- 2. Sets each team's venue, secretary and secretary phone from the
--    2026/27 team sheets. Each UPDATE matches the team_id AND the team's
--    current name on the site, so it can only change the intended team;
--    a row whose name no longer matches is simply left alone.
--
-- Where the two team sheets disagreed, the league confirmed:
--   Jubilee Club Three Bridges 01293 530249, Rugby Club Langley Green
--   01293 533995.
-- The Knockout Cup page shows the home team's venue on every fixture,
-- so it updates as soon as this has run.
-- =====================================================================

INSERT INTO venues (venue_name, address, phone)
SELECT 'Grasshopper Tilgate', NULL, '01293 535653' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Grasshopper Tilgate');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Jubilee Club Three Bridges', NULL, '01293 530249' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Jubilee Club Three Bridges');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Ewhurst Wood Social Club', NULL, '01293 517983' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Ewhurst Wood Social Club');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Pelham Buckle Ifield', NULL, '01293 343333' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Pelham Buckle Ifield');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Apple Tree West Green', NULL, '01293 664404' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Apple Tree West Green');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Rugby Club Langley Green', NULL, '01293 533995' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Rugby Club Langley Green');

INSERT INTO venues (venue_name, address, phone)
SELECT 'New Moon Southgate', NULL, '01293 203347' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'New Moon Southgate');

INSERT INTO venues (venue_name, address, phone)
SELECT 'Dr Samuel Johnson Langley Green', NULL, '07940 328182' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE venue_name = 'Dr Samuel Johnson Langley Green');

-- Premier Division
UPDATE teams SET venue = 'Grasshopper Tilgate',             secretary_name = 'Paul Chesworth',   secretary_phone = '07577 589735' WHERE team_id = 2  AND team_name = 'The Grasshopper A';
UPDATE teams SET venue = 'Jubilee Club Three Bridges',      secretary_name = 'John Nesbitt',     secretary_phone = '07754 873907' WHERE team_id = 1  AND team_name = 'Jubilee club';
UPDATE teams SET venue = 'Ewhurst Wood Social Club',        secretary_name = 'Pete Stevenson',   secretary_phone = '07889 742694' WHERE team_id = 6  AND team_name = 'Rebels';
UPDATE teams SET venue = 'Ewhurst Wood Social Club',        secretary_name = 'Stuart Campbell',  secretary_phone = '07469 363687' WHERE team_id = 15 AND team_name = 'Dingles';
UPDATE teams SET venue = 'Jubilee Club Three Bridges',      secretary_name = 'Dave Chamberlain', secretary_phone = '07976 078535' WHERE team_id = 4  AND team_name = 'The Unbearables';
UPDATE teams SET venue = 'New Moon Southgate',              secretary_name = 'Nick Webb',        secretary_phone = '07477 600316' WHERE team_id = 16 AND team_name = 'Darkside of the Moon';
UPDATE teams SET venue = 'Dr Samuel Johnson Langley Green', secretary_name = 'Darren Ayling',    secretary_phone = '07305 152889' WHERE team_id = 5  AND team_name = 'The Johnson';
UPDATE teams SET venue = 'Rugby Club Langley Green',        secretary_name = 'Chris White',      secretary_phone = '07540 544117' WHERE team_id = 3  AND team_name = 'Rugby Club';

-- A Division
UPDATE teams SET venue = 'Grasshopper Tilgate',             secretary_name = 'Adam Goburdan',    secretary_phone = '07923 274555' WHERE team_id = 10 AND team_name = 'Grasshopper B';
UPDATE teams SET venue = 'Jubilee Club Three Bridges',      secretary_name = 'Oscar Flack',      secretary_phone = '07534 834355' WHERE team_id = 9  AND team_name = 'Disappointers';
UPDATE teams SET venue = 'Ewhurst Wood Social Club',        secretary_name = 'Kevin Grantham',   secretary_phone = '07514 411200' WHERE team_id = 7  AND team_name = 'Ewhurst Woodys';
UPDATE teams SET venue = 'Ewhurst Wood Social Club',        secretary_name = 'Andy Salmon',      secretary_phone = '07913 261673' WHERE team_id = 11 AND team_name = 'Ewhurst Arrows';
UPDATE teams SET venue = 'Jubilee Club Three Bridges',      secretary_name = 'Mark Fluck',       secretary_phone = '07807 550328' WHERE team_id = 8  AND team_name = 'APV';
UPDATE teams SET venue = 'Pelham Buckle Ifield',            secretary_name = 'Paige Gurd',       secretary_phone = '07807 372329' WHERE team_id = 12 AND team_name = 'The Pelhams';
UPDATE teams SET venue = 'Apple Tree West Green',           secretary_name = 'Ashley Comper',    secretary_phone = '07411 353614' WHERE team_id = 13 AND team_name = 'Dartbreakers';
UPDATE teams SET venue = 'Rugby Club Langley Green',        secretary_name = 'Connor Fountain',  secretary_phone = '07599 050713' WHERE team_id = 14 AND team_name = 'Wonky Arrows';
