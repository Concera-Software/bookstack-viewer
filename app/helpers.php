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
