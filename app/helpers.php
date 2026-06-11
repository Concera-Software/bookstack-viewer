<?php

/**
 * Escape a value for safe HTML output.
 *
 * @param ?string $value
 *
 * @return string
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convert HTML content to plain text.
 *
 * @param string $html
 *
 * @return string
 */
function plain_text(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Create a shortened plain-text excerpt.
 *
 * @param string $text
 * @param int $length
 *
 * @return string
 */
function excerpt(string $text, int $length = 220): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));

    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length - 1) . '…';
}

/**
 * Build an absolute canonical URL for a path.
 *
 * @param array $config
 * @param string $path
 *
 * @return string
 */
function canonical_url(array $config, string $path): string
{
    return rtrim($config['base_url'], '/') . '/' . ltrim($path, '/');
}

/**
 * Normalize text for SEO meta fields.
 *
 * @param string $text
 * @return string
 */
function seo_clean_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Shorten text without cutting words in the middle when possible.
 *
 * @param string $text
 * @param int $maxLength
 * @return string
 */
function seo_limit_text(string $text, int $maxLength): string
{
    $text = seo_clean_text($text);

    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }

    $cut = mb_substr($text, 0, max(1, $maxLength - 1));
    $lastSpace = mb_strrpos($cut, ' ');

    if ($lastSpace !== false && $lastSpace > 30) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }

    return rtrim($cut, " \t\n\r\0\x0B.,;:-") . '…';
}

/**
 * Build a page title that fits better in search results.
 *
 * @param string $pageName
 * @param string $bookName
 * @param int $maxLength
 * @return string
 */
function seo_page_title(string $pageName, string $bookName = '', int $maxLength = 58): string
{
    $pageName = seo_clean_text($pageName);
    $bookName = seo_clean_text($bookName);

    /*
     * Prefer the page title only. The global app name is already appended
     * by render_layout(), so do not make this part too long.
     */
    if ($pageName !== '') {
        return seo_limit_text($pageName, $maxLength);
    }

    if ($bookName !== '') {
        return seo_limit_text($bookName, $maxLength);
    }

    return 'Documentation';
}

/**
 * Build a compact SEO meta description.
 *
 * @param string $description
 * @param string $fallbackText
 * @param int $maxLength
 * @return string
 */
function seo_meta_description(string $description, string $fallbackText = '', int $maxLength = 155): string
{
    $description = seo_clean_text($description);

    if ($description === '') {
        $description = seo_clean_text($fallbackText);
    }

    return seo_limit_text($description, $maxLength);
}
