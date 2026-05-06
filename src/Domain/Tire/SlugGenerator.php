<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Generates URL-friendly slugs from product names.
 *
 * Handles Polish character transliteration, lowercasing,
 * and replacement of non-alphanumeric characters with hyphens.
 */
class SlugGenerator
{
    /**
     * Polish character transliteration map.
     */
    private const TRANSLIT_MAP = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
        'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
    ];

    /**
     * Generate a URL-friendly slug from a product name.
     *
     * Steps:
     * 1. Lowercase the input (UTF-8 aware).
     * 2. Transliterate Polish diacritics to ASCII equivalents.
     * 3. Replace any non-alphanumeric character sequence with a single hyphen.
     * 4. Trim leading/trailing hyphens.
     * 5. Collapse consecutive hyphens.
     *
     * @param string $name the product name to slugify
     *
     * @return string the generated slug
     */
    public static function generate(string $name): string
    {
        // Lowercase (UTF-8 safe)
        $slug = mb_strtolower($name, 'UTF-8');

        // Transliterate Polish characters
        $slug = strtr($slug, self::TRANSLIT_MAP);

        // Replace any non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Trim leading and trailing hyphens
        $slug = trim($slug, '-');

        // Collapse consecutive hyphens (safety net)
        return preg_replace('/-{2,}/', '-', $slug);
    }
}
