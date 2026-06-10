-- =====================================================================
-- Migration: site settings, venues, and team contact fields.
--
-- Apply this in phpMyAdmin AFTER importing fresh-schema.sql previously.
-- Fresh imports of fresh-schema.sql already include these tables and
-- columns, so this migration is only needed when an existing install
-- is being upgraded in place.
-- =====================================================================

-- Key-value store for things the admin wants to change without us
-- touching the codebase: Google Sheets / Doc embed URLs, etc.
CREATE TABLE IF NOT EXISTS `site_settings` (
  `setting_key`   varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at`    timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Venue list used by contactinfo.html.
CREATE TABLE IF NOT EXISTS `venues` (
  `venue_id`   int(11) NOT NULL AUTO_INCREMENT,
  `venue_name` varchar(100) NOT NULL,
  `address`    varchar(255) DEFAULT NULL,
  `phone`      varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`venue_id`),
  KEY `idx_venue_name` (`venue_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Team contact info now lives on the team row itself: which venue they
-- play out of, their secretary's name, and a contact phone for that
-- secretary. Phone is gated behind a captain login when shown publicly.
ALTER TABLE `teams`
  ADD COLUMN IF NOT EXISTS `venue`           varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `secretary_name`  varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `secretary_phone` varchar(50)  DEFAULT NULL;
