<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

/**
 * Generates URL keys from product names. Framework-free so it stays
 * trivially unit-testable.
 */
class UrlKeyGenerator
{
    /**
     * Basic transliteration map for common Latin-1 accents; anything else
     * non-alphanumeric collapses into dashes.
     */
    private const TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a', 'ã' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ];

    public function generate(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = strtr($key, self::TRANSLITERATION);
        $key = preg_replace('/[^a-z0-9]+/u', '-', $key) ?? '';

        return trim($key, '-');
    }
}
