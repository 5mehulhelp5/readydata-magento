<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReadyData\Import\Model\Config;

class UrlRewriteConflict implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::URL_CONFLICT_APPEND, 'label' => __('Append Suffix (-1, -2, ...)')],
            ['value' => Config::URL_CONFLICT_SKIP, 'label' => __('Skip URL Rewrite')],
            ['value' => Config::URL_CONFLICT_ERROR, 'label' => __('Report Error')],
        ];
    }
}
