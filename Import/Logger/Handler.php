<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/readydata_import.log';

    /**
     * @var int
     */
    protected $loggerType = MonologLogger::DEBUG;
}
