<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReadyData\Import\Model\Config;

class CategoryReplaceScope implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::REPLACE_SCOPE_ALL_ROOTS, 'label' => __('Whole Catalog')],
            ['value' => Config::REPLACE_SCOPE_PAYLOAD_ROOTS, 'label' => __('Only the Roots the Payload Names')],
        ];
    }
}
