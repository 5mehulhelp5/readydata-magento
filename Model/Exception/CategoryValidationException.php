<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A single category entry failed validation and must be skipped. Carries a
 * machine-readable reason code (see CategorySyncResultInterface::REASON_*) so
 * the service can report it per category without aborting the whole sync.
 */
class CategoryValidationException extends LocalizedException
{
    public function __construct(
        private readonly string $reason,
        Phrase $phrase
    ) {
        parent::__construct($phrase);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
