CREATE DATABASE IF NOT EXISTS `new`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `new`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `conversations`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `post_media`;
DROP TABLE IF EXISTS `post_likes`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `follows`;
DROP TABLE IF EXISTS `group_members`;
DROP TABLE IF EXISTS `posts`;
DROP TABLE IF EXISTS `gigs`;
DROP TABLE IF EXISTS `groups`;
DROP TABLE IF EXISTS `user_bans`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `role` ENUM('owner','admin','moderator','member') NOT NULL DEFAULT 'member',
  `avatar` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `skills` VARCHAR(255) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `timezone` VARCHAR(100) DEFAULT NULL,
  `link_tiktok` VARCHAR(255) DEFAULT NULL,
  `link_instagram` VARCHAR(255) DEFAULT NULL,
  `link_x` VARCHAR(255) DEFAULT NULL,
  `link_portfolio` VARCHAR(255) DEFAULT NULL,
  `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_bans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  `banned_by` INT UNSIGNED DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_user_bans_user_active` (`user_id`, `active`),
  KEY `idx_user_bans_banned_by` (`banned_by`),
  CONSTRAINT `fk_user_bans_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_bans_admin`
    FOREIGN KEY (`banned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(140) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `cover_image` VARCHAR(255) DEFAULT NULL,
  `is_code_only` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `price_month` DECIMAL(10,2) DEFAULT NULL,
  `invite_token` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_groups_slug` (`slug`),
  UNIQUE KEY `uniq_groups_invite_token` (`invite_token`),
  KEY `idx_groups_created_by` (`created_by`),
  CONSTRAINT `fk_groups_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `category` ENUM('post','highlight','project') NOT NULL DEFAULT 'post',
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_posts_user` (`user_id`),
  KEY `idx_posts_group` (`group_id`),
  KEY `idx_posts_category` (`category`),
  KEY `idx_posts_pinned_created` (`is_pinned`, `created_at`),
  CONSTRAINT `fk_posts_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_group`
    FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `group_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_group_members` (`group_id`, `user_id`),
  KEY `idx_group_members_user` (`user_id`),
  CONSTRAINT `fk_group_members_group`
    FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_members_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_created` (`post_id`, `created_at`),
  KEY `idx_comments_user` (`user_id`),
  CONSTRAINT `fk_comments_post`
    FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `post_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_likes` (`post_id`, `user_id`),
  KEY `idx_post_likes_user` (`user_id`),
  CONSTRAINT `fk_post_likes_post`
    FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_post_likes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `post_media` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `type` VARCHAR(32) NOT NULL DEFAULT 'image',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_media_post` (`post_id`),
  CONSTRAINT `fk_post_media_post`
    FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `follows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `follower_id` INT UNSIGNED NOT NULL,
  `following_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_follows_pair` (`follower_id`, `following_id`),
  KEY `idx_follows_following` (`following_id`),
  CONSTRAINT `fk_follows_follower`
    FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_follows_following`
    FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user1_id` INT UNSIGNED NOT NULL,
  `user2_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_conversations_pair` (`user1_id`, `user2_id`),
  KEY `idx_conversations_user2` (`user2_id`),
  CONSTRAINT `fk_conversations_user1`
    FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conversations_user2`
    FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_conversations_order`
    CHECK (`user1_id` < `user2_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_conversation_created` (`conversation_id`, `created_at`),
  KEY `idx_messages_sender` (`sender_id`),
  KEY `idx_messages_read_at` (`read_at`),
  CONSTRAINT `fk_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_user_id` INT UNSIGNED NOT NULL,
  `from_user_id` INT UNSIGNED DEFAULT NULL,
  `type` ENUM('like','comment','follow','message') NOT NULL,
  `ref_id` INT UNSIGNED NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_to_read_created` (`to_user_id`, `is_read`, `created_at`),
  KEY `idx_notifications_from` (`from_user_id`),
  KEY `idx_notifications_type_ref` (`type`, `ref_id`),
  CONSTRAINT `fk_notifications_to_user`
    FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_from_user`
    FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_id` INT UNSIGNED NOT NULL,
  `target_type` ENUM('post','comment','user') NOT NULL,
  `target_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `status` ENUM('open','handled','dismissed') NOT NULL DEFAULT 'open',
  `handled_by` INT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `action_taken` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_status_created` (`status`, `created_at`),
  KEY `idx_reports_reporter` (`reporter_id`),
  KEY `idx_reports_target` (`target_type`, `target_id`),
  KEY `idx_reports_handled_by` (`handled_by`),
  CONSTRAINT `fk_reports_reporter`
    FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reports_handled_by`
    FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_actor` (`actor_id`),
  KEY `idx_audit_log_action` (`action`),
  CONSTRAINT `fk_audit_log_actor`
    FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gigs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `price_from` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rating` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gigs_user` (`user_id`),
  CONSTRAINT `fk_gigs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
