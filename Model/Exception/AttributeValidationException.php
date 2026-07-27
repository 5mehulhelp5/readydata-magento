<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A single attribute definition failed validation and must be skipped. Carries
 * a machine-readable reason code (see AttributeSyncResultInterface::REASON_*)
 * so the service can report it per attribute without aborting the whole sync.
 */
class AttributeValidationException extends LocalizedException
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
