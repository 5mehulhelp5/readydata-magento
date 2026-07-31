<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Exception;

/**
 * A single media reference could not be resolved and must be skipped.
 *
 * Deliberately a plain \RuntimeException carrying a ready-to-report sentence
 * rather than a LocalizedException: FileResolver never lets it out, it converts
 * it into the per-product warning the import response shows. It marks the
 * expected failures (bad path, missing file, refused download) so genuinely
 * unexpected throwables can still be told apart and logged.
 */
class MediaReferenceException extends \RuntimeException
{
}
