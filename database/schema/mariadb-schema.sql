/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_is_system` tinyint(1) NOT NULL DEFAULT 0,
  `action` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` bigint(20) unsigned DEFAULT NULL,
  `registration_id` bigint(20) unsigned DEFAULT NULL,
  `resulting_status` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_action_index` (`action`),
  KEY `activity_logs_user_id_index` (`user_id`),
  KEY `activity_logs_session_id_index` (`session_id`),
  KEY `activity_logs_actor_id_foreign` (`actor_id`),
  KEY `activity_logs_registration_id_foreign` (`registration_id`),
  CONSTRAINT `activity_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_logs_registration_id_foreign` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_logs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `apero_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `apero_flags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `registration_id` bigint(20) unsigned NOT NULL,
  `motif` varchar(140) DEFAULT NULL,
  `flagged_at` timestamp NOT NULL,
  `flagged_by` bigint(20) unsigned DEFAULT NULL,
  `parked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `apero_flags_session_id_user_id_unique` (`session_id`,`user_id`),
  KEY `apero_flags_flagged_by_foreign` (`flagged_by`),
  KEY `apero_flags_registration_id_foreign` (`registration_id`),
  KEY `apero_flags_user_id_foreign` (`user_id`),
  CONSTRAINT `apero_flags_flagged_by_foreign` FOREIGN KEY (`flagged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `apero_flags_registration_id_foreign` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apero_flags_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `apero_flags_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(255) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `target_type` varchar(255) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` bigint(20) unsigned DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_target_type_target_id_index` (`target_type`,`target_id`),
  KEY `audit_logs_session_id_index` (`session_id`),
  KEY `audit_logs_actor_id_foreign` (`actor_id`),
  CONSTRAINT `audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(255) NOT NULL,
  `provider_uid` varchar(255) NOT NULL,
  `email_at_link` varchar(255) DEFAULT NULL,
  `linked_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `auth_identities_provider_provider_uid_unique` (`provider`,`provider_uid`),
  KEY `auth_identities_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `age_min` smallint(5) unsigned NOT NULL,
  `age_max` smallint(5) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `club_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `club_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT 'Club',
  `tagline` varchar(120) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `icon_192_path` varchar(255) DEFAULT NULL,
  `icon_512_path` varchar(255) DEFAULT NULL,
  `icon_apple_path` varchar(255) DEFAULT NULL,
  `primary_color` varchar(9) DEFAULT NULL,
  `accent_color` varchar(9) DEFAULT NULL,
  `info_color` varchar(9) DEFAULT NULL,
  `season_start_month` tinyint(3) unsigned NOT NULL DEFAULT 9,
  `timezone` varchar(255) NOT NULL DEFAULT 'Europe/Paris',
  `invitation_link_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `season_rollover_at` timestamp NULL DEFAULT NULL,
  `notif_push_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notif_email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `auth_magic_link_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `auth_google_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `legal_publisher` varchar(500) DEFAULT NULL,
  `legal_host` varchar(500) DEFAULT NULL,
  `legal_director` varchar(255) DEFAULT NULL,
  `legal_contact_email` varchar(255) DEFAULT NULL,
  `legal_source_url` varchar(255) DEFAULT NULL,
  `legal_mail_provider` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `debriefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `debriefs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `author_id` bigint(20) unsigned DEFAULT NULL,
  `content_markdown` longtext NOT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `debriefs_session_id_author_id_unique` (`session_id`,`author_id`),
  KEY `debriefs_archived_by_foreign` (`archived_by`),
  KEY `debriefs_author_id_foreign` (`author_id`),
  CONSTRAINT `debriefs_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `debriefs_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `debriefs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `disciplines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `disciplines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gpx_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gpx_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `discipline_id` bigint(20) unsigned DEFAULT NULL,
  `gpx_path` varchar(255) NOT NULL,
  `gpx_hash` char(64) DEFAULT NULL,
  `gpx_original_name` varchar(255) DEFAULT NULL,
  `gpx_size_ko` int(10) unsigned DEFAULT NULL,
  `distance_km` decimal(6,1) DEFAULT NULL,
  `dplus_m` mediumint(8) unsigned DEFAULT NULL,
  `dmoins_m` mediumint(8) unsigned DEFAULT NULL,
  `alt_min_m` smallint(6) DEFAULT NULL,
  `alt_max_m` smallint(6) DEFAULT NULL,
  `point_count` int(10) unsigned DEFAULT NULL,
  `duration_min` int(10) unsigned DEFAULT NULL,
  `start_lat` decimal(10,7) DEFAULT NULL,
  `start_lng` decimal(10,7) DEFAULT NULL,
  `end_lat` decimal(10,7) DEFAULT NULL,
  `end_lng` decimal(10,7) DEFAULT NULL,
  `is_loop` tinyint(1) NOT NULL DEFAULT 0,
  `elongation` decimal(4,2) DEFAULT NULL,
  `bbox_min_lat` decimal(10,7) DEFAULT NULL,
  `bbox_min_lng` decimal(10,7) DEFAULT NULL,
  `bbox_max_lat` decimal(10,7) DEFAULT NULL,
  `bbox_max_lng` decimal(10,7) DEFAULT NULL,
  `bearing_deg` smallint(5) unsigned DEFAULT NULL,
  `sector` char(2) DEFAULT NULL,
  `polyline` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`polyline`)),
  `elevation_profile` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`elevation_profile`)),
  `openrunner_embed_url` varchar(500) DEFAULT NULL,
  `openrunner_public_url` varchar(500) DEFAULT NULL,
  `start_location_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gpx_routes_archived_at_sector_index` (`archived_at`,`sector`),
  KEY `gpx_routes_archived_at_distance_km_index` (`archived_at`,`distance_km`),
  KEY `gpx_routes_archived_at_elongation_index` (`archived_at`,`elongation`),
  KEY `gpx_routes_bbox_min_lat_bbox_max_lat_index` (`bbox_min_lat`,`bbox_max_lat`),
  KEY `gpx_routes_gpx_hash_index` (`gpx_hash`),
  KEY `gpx_routes_archived_by_foreign` (`archived_by`),
  KEY `gpx_routes_created_by_foreign` (`created_by`),
  KEY `gpx_routes_discipline_id_foreign` (`discipline_id`),
  KEY `gpx_routes_start_location_id_foreign` (`start_location_id`),
  CONSTRAINT `gpx_routes_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gpx_routes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gpx_routes_discipline_id_foreign` FOREIGN KEY (`discipline_id`) REFERENCES `disciplines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gpx_routes_start_location_id_foreign` FOREIGN KEY (`start_location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `http_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `http_sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `http_sessions_user_id_index` (`user_id`),
  KEY `http_sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `information_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `content_markdown` longtext DEFAULT NULL,
  `visibility` varchar(10) NOT NULL DEFAULT 'all',
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `information_pages_visibility_index` (`visibility`),
  KEY `information_pages_pinned_index` (`pinned`),
  KEY `information_pages_position_index` (`position`),
  KEY `information_pages_archived_by_foreign` (`archived_by`),
  KEY `information_pages_created_by_foreign` (`created_by`),
  CONSTRAINT `information_pages_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `information_pages_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invitation_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitation_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitation_tokens_token_hash_unique` (`token_hash`),
  KEY `invitation_tokens_user_id_foreign` (`user_id`),
  CONSTRAINT `invitation_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `kind` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `locations_created_by_foreign` (`created_by`),
  CONSTRAINT `locations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `magic_link_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `magic_link_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `magic_link_tokens_token_hash_unique` (`token_hash`),
  KEY `magic_link_tokens_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `available_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_outbox_status_available_at_index` (`status`,`available_at`),
  KEY `notification_outbox_user_id_foreign` (`user_id`),
  CONSTRAINT `notification_outbox_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `matrix` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`matrix`)),
  `paused` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_preferences_user_id_unique` (`user_id`),
  CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint` text NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `content_encoding` varchar(255) NOT NULL DEFAULT 'aesgcm',
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_subscriptions_endpoint_hash_unique` (`endpoint_hash`),
  KEY `push_subscriptions_user_id_index` (`user_id`),
  CONSTRAINT `push_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qualifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `qualifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quota_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quota_tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `max_per_week` smallint(5) unsigned NOT NULL DEFAULT 1,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quota_tags_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('participating','waitlist','cancelled') NOT NULL,
  `waitlist_reason` enum('capacity','quota_exceeded') DEFAULT NULL,
  `waitlist_position` int(10) unsigned DEFAULT NULL,
  `registered_at` timestamp NOT NULL,
  `promoted_at` timestamp NULL DEFAULT NULL,
  `promoted_by` bigint(20) unsigned DEFAULT NULL,
  `override_by` bigint(20) unsigned DEFAULT NULL,
  `override_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registrations_session_id_user_id_unique` (`session_id`,`user_id`),
  KEY `registrations_user_id_status_index` (`user_id`,`status`),
  KEY `registrations_session_id_status_registered_at_index` (`session_id`,`status`,`registered_at`),
  KEY `registrations_override_by_foreign` (`override_by`),
  KEY `registrations_promoted_by_foreign` (`promoted_by`),
  CONSTRAINT `registrations_override_by_foreign` FOREIGN KEY (`override_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `registrations_promoted_by_foreign` FOREIGN KEY (`promoted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `registrations_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `registrations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `session_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_category` (
  `session_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`session_id`,`category_id`),
  KEY `session_category_category_id_foreign` (`category_id`),
  CONSTRAINT `session_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_category_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `session_coach`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_coach` (
  `session_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`session_id`,`user_id`),
  KEY `session_coach_user_id_foreign` (`user_id`),
  CONSTRAINT `session_coach_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_coach_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `session_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `kind` enum('training','competition','club_event') NOT NULL,
  `discipline_id` bigint(20) unsigned DEFAULT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time_of_day` time NOT NULL,
  `duration_min` smallint(5) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `location_text` varchar(255) DEFAULT NULL,
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `quota_tag_id` bigint(20) unsigned DEFAULT NULL,
  `generation_start_date` date NOT NULL,
  `generation_end_date` date NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_templates_created_by_foreign` (`created_by`),
  KEY `session_templates_discipline_id_foreign` (`discipline_id`),
  KEY `session_templates_location_id_foreign` (`location_id`),
  KEY `session_templates_quota_tag_id_foreign` (`quota_tag_id`),
  CONSTRAINT `session_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `session_templates_discipline_id_foreign` FOREIGN KEY (`discipline_id`) REFERENCES `disciplines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `session_templates_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `session_templates_quota_tag_id_foreign` FOREIGN KEY (`quota_tag_id`) REFERENCES `quota_tags` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('training','competition','club_event') NOT NULL,
  `title` varchar(255) NOT NULL,
  `discipline_id` bigint(20) unsigned DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `duration_min` smallint(5) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `location_text` varchar(255) DEFAULT NULL,
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `visibility` varchar(255) NOT NULL DEFAULT 'all',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `source_template_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `quota_tag_id` bigint(20) unsigned DEFAULT NULL,
  `content_markdown` longtext DEFAULT NULL,
  `content_attachment_path` varchar(255) DEFAULT NULL,
  `event_type_id` bigint(20) unsigned DEFAULT NULL,
  `distance` varchar(255) DEFAULT NULL,
  `external_url` varchar(255) DEFAULT NULL,
  `photos_album_url` varchar(255) DEFAULT NULL,
  `agenda` longtext DEFAULT NULL,
  `route_openrunner_embed_url` varchar(255) DEFAULT NULL,
  `route_openrunner_public_url` varchar(255) DEFAULT NULL,
  `route_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_start_at_index` (`start_at`),
  KEY `sessions_kind_start_at_index` (`kind`,`start_at`),
  KEY `sessions_quota_tag_id_start_at_index` (`quota_tag_id`,`start_at`),
  KEY `sessions_cancelled_by_foreign` (`cancelled_by`),
  KEY `sessions_created_by_foreign` (`created_by`),
  KEY `sessions_discipline_id_foreign` (`discipline_id`),
  KEY `sessions_event_type_id_foreign` (`event_type_id`),
  KEY `sessions_location_id_foreign` (`location_id`),
  KEY `sessions_route_id_foreign` (`route_id`),
  KEY `sessions_source_template_id_foreign` (`source_template_id`),
  CONSTRAINT `sessions_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_discipline_id_foreign` FOREIGN KEY (`discipline_id`) REFERENCES `disciplines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_event_type_id_foreign` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_quota_tag_id_foreign` FOREIGN KEY (`quota_tag_id`) REFERENCES `quota_tags` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_route_id_foreign` FOREIGN KEY (`route_id`) REFERENCES `gpx_routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sessions_source_template_id_foreign` FOREIGN KEY (`source_template_id`) REFERENCES `session_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `template_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_category` (
  `session_template_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`session_template_id`,`category_id`),
  KEY `template_category_category_id_foreign` (`category_id`),
  CONSTRAINT `template_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `template_category_session_template_id_foreign` FOREIGN KEY (`session_template_id`) REFERENCES `session_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `template_coach`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_coach` (
  `session_template_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`session_template_id`,`user_id`),
  KEY `template_coach_user_id_foreign` (`user_id`),
  CONSTRAINT `template_coach_session_template_id_foreign` FOREIGN KEY (`session_template_id`) REFERENCES `session_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `template_coach_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_category` (
  `user_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`category_id`),
  KEY `user_category_category_id_foreign` (`category_id`),
  CONSTRAINT `user_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_category_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_qualification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_qualification` (
  `user_id` bigint(20) unsigned NOT NULL,
  `qualification_id` bigint(20) unsigned NOT NULL,
  `expires_at` date DEFAULT NULL,
  `attributed_at` timestamp NOT NULL,
  `attributed_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`,`qualification_id`),
  KEY `user_qualification_attributed_by_foreign` (`attributed_by`),
  KEY `user_qualification_qualification_id_foreign` (`qualification_id`),
  CONSTRAINT `user_qualification_attributed_by_foreign` FOREIGN KEY (`attributed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_qualification_qualification_id_foreign` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_qualification_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`roles`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `athlete_access_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `is_minor` tinyint(1) NOT NULL DEFAULT 0,
  `guardian_id` bigint(20) unsigned DEFAULT NULL,
  `guardianship_linked_at` timestamp NULL DEFAULT NULL,
  `deletion_requested_at` timestamp NULL DEFAULT NULL,
  `anonymized_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_guardian_id_index` (`guardian_id`),
  CONSTRAINT `users_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weather_cache_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `weather_cache_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `slot` datetime NOT NULL,
  `forecast` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`forecast`)),
  `fetched_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `weather_cache_entries_latitude_longitude_slot_unique` (`latitude`,`longitude`,`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
set autocommit=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_01_01_000010_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_01_01_000020_create_cache_locks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_01_01_000030_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_01_01_000040_create_club_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_01_01_000050_create_disciplines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_01_01_000060_create_event_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_01_01_000070_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_01_01_000080_create_http_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_01_01_000090_create_job_batches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_01_01_000100_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_01_01_000110_create_magic_link_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_01_01_000130_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_01_01_000140_create_qualifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_01_01_000150_create_quota_tags_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_01_01_000160_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_01_01_000170_create_weather_cache_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_01_01_000180_create_auth_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_01_01_000190_create_information_pages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_01_01_000200_create_invitation_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_01_01_000210_create_locations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_01_01_000220_create_notification_outbox_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_01_01_000230_create_notification_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_01_01_000240_create_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_01_000250_create_user_category_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_01_01_000260_create_user_qualification_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_01_01_000270_create_gpx_routes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_01_01_000280_create_session_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_01_01_000290_create_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_01_01_000300_create_template_category_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_01_01_000310_create_template_coach_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_01_01_000320_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_01_01_000330_create_debriefs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_01_01_000340_create_registrations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_01_01_000350_create_session_category_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_01_01_000360_create_session_coach_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_01_01_000370_create_activity_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_01_01_000380_create_apero_flags_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_09_000100_add_channel_and_auth_switches_to_club_settings_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_08_21_000000_add_pwa_icon_paths_to_club_settings',2);
commit;
