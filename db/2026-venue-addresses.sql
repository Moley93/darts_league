-- =====================================================================
-- Data update: venue addresses, and the Dr Samuel Johnson's phone number
-- (November 2026)
--
-- Run ONCE in phpMyAdmin on the live database: select the darts_league
-- database, open the SQL tab, paste this file and click Go.
-- Safe to re-run. Nothing is deleted.
--
-- Fills in the address of each of the 8 league venues, but only where the
-- address is still empty, so an address an admin has since typed in on the
-- admin Venues tab is never overwritten. The addresses show on the
-- Contacts & Venues page and on each Knockout Cup fixture.
--
-- Also swaps the Dr Samuel Johnson's phone number from the mobile
-- 07940 328182 to the pub's landline 01293 456530, but only while it is
-- still that mobile number.
--
-- Each address was checked against at least two independent sources
-- (the venue's own website, CAMRA, Crawley Borough Council licensing, the
-- Food Standards Agency register, Companies House) and its postcode
-- against the national postcode register.
--   - Ewhurst Wood Social Club is on Ifield Avenue, not Ewhurst Road.
--   - The Apple Tree's entrance is on Ewhurst Road, at the corner of West
--     Green Drive; a sat nav given only the postcode may stop further down
--     West Green Drive.
-- =====================================================================

UPDATE venues SET address = 'Ashdown Drive, Tilgate, Crawley RH10 5EJ'
WHERE venue_name = 'Grasshopper Tilgate' AND (address IS NULL OR address = '');

UPDATE venues SET address = '3 Jubilee Walk, Three Bridges, Crawley RH10 1LQ'
WHERE venue_name = 'Jubilee Club Three Bridges' AND (address IS NULL OR address = '');

UPDATE venues SET address = 'Ifield Avenue, West Green, Crawley RH11 7AJ'
WHERE venue_name = 'Ewhurst Wood Social Club' AND (address IS NULL OR address = '');

UPDATE venues SET address = '216 Ifield Drive, Ifield, Crawley RH11 0DQ'
WHERE venue_name = 'Pelham Buckle Ifield' AND (address IS NULL OR address = '');

UPDATE venues SET address = 'West Green Drive (corner of Ewhurst Road), West Green, Crawley RH11 7DL'
WHERE venue_name = 'Apple Tree West Green' AND (address IS NULL OR address = '');

UPDATE venues SET address = 'Willoughby Fields, Ifield Avenue, Langley Green, Crawley RH11 7LX'
WHERE venue_name = 'Rugby Club Langley Green' AND (address IS NULL OR address = '');

UPDATE venues SET address = 'Brighton Road, Southgate, Crawley RH10 6SZ'
WHERE venue_name = 'New Moon Southgate' AND (address IS NULL OR address = '');

UPDATE venues SET address = 'Langley Parade, Langley Green, Crawley RH11 7RS'
WHERE venue_name = 'Dr Samuel Johnson Langley Green' AND (address IS NULL OR address = '');

-- The Dr Samuel Johnson: show the pub's landline instead of the mobile.
UPDATE venues SET phone = '01293 456530'
WHERE venue_name = 'Dr Samuel Johnson Langley Green' AND phone = '07940 328182';
