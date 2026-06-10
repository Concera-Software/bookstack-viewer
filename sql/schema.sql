SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `public_access_codes` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `public_access_log` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_path` varchar(768) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referer` varchar(768) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `public_docs` (
  `id` bigint UNSIGNED NOT NULL,
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_page_id` bigint UNSIGNED NOT NULL,
  `book_id` bigint UNSIGNED DEFAULT NULL,
  `chapter_id` bigint UNSIGNED DEFAULT NULL,
  `book_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `book_slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chapter_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chapter_slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_path` varchar(768) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `text_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `source_updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `public_doc_exclusions` (
  `id` bigint UNSIGNED NOT NULL,
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_page_id` bigint UNSIGNED NOT NULL,
  `excluded` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `created_by_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE `public_access_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_token_hash` (`token_hash`);

ALTER TABLE `public_access_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_url_path` (`url_path`);

ALTER TABLE `public_docs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_source_page` (`source_key`,`source_page_id`),
  ADD KEY `idx_book_slug` (`book_slug`),
  ADD KEY `idx_chapter_slug` (`chapter_slug`),
  ADD KEY `idx_page_slug` (`page_slug`),
  ADD KEY `idx_updated_at` (`updated_at`),
  ADD KEY `idx_source_key` (`source_key`),
  ADD KEY `idx_source_url` (`source_url`(255)),
  ADD KEY `idx_url_path` (`url_path`(255));
ALTER TABLE `public_docs` ADD FULLTEXT KEY `ft_public_docs` (`page_name`,`text_content`,`description`,`tags`);

ALTER TABLE `public_doc_exclusions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_source_page_exclusion` (`source_key`,`source_page_id`),
  ADD KEY `idx_excluded` (`excluded`),
  ADD KEY `idx_source_page` (`source_key`,`source_page_id`);


ALTER TABLE `public_access_codes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `public_access_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `public_docs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `public_doc_exclusions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
