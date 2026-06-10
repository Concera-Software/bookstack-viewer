CREATE TABLE public_docs (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    book_id BIGINT UNSIGNED NULL,
    chapter_id BIGINT UNSIGNED NULL,

    book_name VARCHAR(255) NULL,
    book_slug VARCHAR(255) NULL,

    chapter_name VARCHAR(255) NULL,
    chapter_slug VARCHAR(255) NULL,

    page_name VARCHAR(255) NOT NULL,
    page_slug VARCHAR(255) NOT NULL,

    url_path VARCHAR(768) NOT NULL,

    html LONGTEXT NOT NULL,
    text_content LONGTEXT NOT NULL,

    description TEXT NULL,
    tags TEXT NULL,

    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    INDEX idx_book_slug (book_slug),
    INDEX idx_chapter_slug (chapter_slug),
    INDEX idx_page_slug (page_slug),
    INDEX idx_updated_at (updated_at),
    FULLTEXT INDEX ft_public_docs (page_name, text_content, description, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
