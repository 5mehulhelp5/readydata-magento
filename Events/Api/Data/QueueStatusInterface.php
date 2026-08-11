<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * Health of the eventing pipeline on this store.
 *
 * The failure this most needs to expose is silence. A cron that stopped looks
 * exactly like "nothing changed" from ReadyData's side, so the answer carries
 * the oldest waiting event's age as well as the counts — a deep queue whose
 * oldest entry is minutes old is a busy store, and one whose oldest entry is
 * days old is a broken cron.
 *
 * @api
 */
interface QueueStatusInterface
{
    public function getEnabled(): ?bool;

    public function setEnabled(?bool $enabled): self;

    /**
     * Whether the generated hooks are actually registered.
     *
     * An upgrade that skipped the generation step leaves a module that is
     * installed, enabled, configured — and silently emits nothing. This is the
     * field that says so out loud.
     */
    public function getHooked(): ?bool;

    public function setHooked(?bool $hooked): self;

    public function getCatalogueSize(): ?int;

    public function setCatalogueSize(?int $size): self;

    public function getSubscriberCode(): ?string;

    public function setSubscriberCode(?string $code): self;

    public function getSubscriptionCount(): ?int;

    public function setSubscriptionCount(?int $count): self;

    public function getWaiting(): ?int;

    public function setWaiting(?int $waiting): self;

    public function getInProgress(): ?int;

    public function setInProgress(?int $inProgress): self;

    public function getSent(): ?int;

    public function setSent(?int $sent): self;

    public function getFailed(): ?int;

    public function setFailed(?int $failed): self;

    public function getDeadLettered(): ?int;

    public function setDeadLettered(?int $deadLettered): self;

    /** UTC timestamp of the oldest event still awaiting delivery, or null. */
    public function getOldestWaitingAt(): ?string;

    public function setOldestWaitingAt(?string $timestamp): self;
}
