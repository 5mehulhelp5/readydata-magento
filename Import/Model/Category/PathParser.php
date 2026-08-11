<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Category;

/**
 * Normalizes and classifies one entry of a product's "categories" payload
 * field: either a numeric category ID or a full category path from the
 * root category name ("Default Category/Men/Shirts").
 *
 * "/" separates segments only when unescaped: "\/" is a literal slash
 * inside a name ("Default Category/Wo\/Men"), "\\" a literal backslash,
 * and "\" before any other character yields that character; a trailing
 * lone "\" is a literal backslash. Segments are trimmed after decoding,
 * so names with leading/trailing whitespace remain unsupported.
 */
final class PathParser
{
    public const TYPE_ID = 'id';
    public const TYPE_PATH = 'path';
    public const SEPARATOR = '/';
    public const ESCAPE = '\\';

    /**
     * @return array{type: string, id?: int, segments?: string[]}|null
     *         null when the entry is empty or unusable after normalization
     */
    public function parse(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        // Raw entry, before unescaping — so "\42" is a path segment "42",
        // making digits-only names referenceable by path.
        if (ctype_digit($reference)) {
            return ['type' => self::TYPE_ID, 'id' => (int)$reference];
        }

        // Byte-wise scan is UTF-8-safe: "/" and "\" never occur inside
        // multibyte sequences.
        $raw = [];
        $current = '';
        $length = strlen($reference);
        for ($i = 0; $i < $length; $i++) {
            $char = $reference[$i];
            if ($char === self::ESCAPE) {
                $current .= $i + 1 < $length ? $reference[++$i] : self::ESCAPE;
            } elseif ($char === self::SEPARATOR) {
                $raw[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $raw[] = $current;

        $segments = [];
        foreach ($raw as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        if (!$segments) {
            return null;
        }

        return ['type' => self::TYPE_PATH, 'segments' => $segments];
    }

    public static function escapeSegment(string $segment): string
    {
        // Backslash FIRST: str_replace applies the pairs sequentially, and
        // the reverse order would double the escape added for "/".
        return str_replace(
            [self::ESCAPE, self::SEPARATOR],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . self::SEPARATOR],
            $segment
        );
    }

    /**
     * Canonical escaped form of a segment list — collision-free where a
     * plain implode is not: ["a/b"] becomes "a\/b", ["a","b"] stays "a/b".
     *
     * @param string[] $segments
     */
    public static function buildKey(array $segments): string
    {
        return implode(self::SEPARATOR, array_map([self::class, 'escapeSegment'], $segments));
    }
}
