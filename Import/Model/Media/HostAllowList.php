<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media;

use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Exception\MediaReferenceException;

/**
 * The download host allow-list, in one place.
 *
 * Deliberately a collaborator rather than an inline check: the list has to be
 * applied at TWO points that are easy to let drift apart — the URL the payload
 * gave us (FileResolver::planDownload) and every redirect hop Guzzle would
 * follow (PooledDownloader). Enforcing it only on the first meant a permitted
 * host could bounce the fetch onto an address the store can reach but the
 * operator never allowed, which is exactly what the setting exists to prevent.
 *
 * An EMPTY list means any host. That is the shipped default and is documented as
 * such in system.xml; keeping the rule here means it is decided once.
 */
class HostAllowList
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @throws MediaReferenceException when the host is outside a configured list
     */
    public function assert(string $host): void
    {
        $allowedHosts = $this->config->getMediaAllowedHosts();
        if ($allowedHosts && !in_array(mb_strtolower($host), $allowedHosts, true)) {
            throw new MediaReferenceException(
                sprintf('Media URL host "%s" is not in the allowed download hosts; skipped.', $host)
            );
        }
    }
}
