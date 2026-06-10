-- =====================================================================
-- Migration: add the announcements table
-- Apply this in phpMyAdmin AFTER you have already imported fresh-schema.sql
-- on a previous occasion. If you are about to re-import fresh-schema.sql
-- from scratch you can skip this — the fresh schema now includes the
-- `announcements` table directly.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_announcements_created` (`created_at`),
  KEY `idx_announcements_admin` (`created_by_admin_id`),
  CONSTRAINT `announcements_admin_fk` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
