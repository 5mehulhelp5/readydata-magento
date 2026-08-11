<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReadyData\Import\Model\Config;

class IndexingMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::INDEXING_MODE_PARTIAL, 'label' => __('Partial Reindex (imported products only)')],
            ['value' => Config::INDEXING_MODE_INVALIDATE, 'label' => __('Invalidate Indexers')],
            ['value' => Config::INDEXING_MODE_NONE, 'label' => __('None')],
        ];
    }
}
