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
    /**
     * @return bool|null
     */
    public function getEnabled(): ?bool;

    /**
     * @param bool|null $enabled
     * @return $this
     */
    public function setEnabled(?bool $enabled): self;

    /**
     * Whether the generated hooks are actually registered.
     *
     * An upgrade that skipped the generation step leaves a module that is
     * installed, enabled, configured — and silently emits nothing. This is the
     * field that says so out loud.
     *
     * @return bool|null
     */
    public function getHooked(): ?bool;

    /**
     * @param bool|null $hooked
     * @return $this
     */
    public function setHooked(?bool $hooked): self;

    /**
     * The id this store stamps on every event it emits.
     *
     * readydata's ingress refuses a batch whose instance does not match what it
     * expects, so registration has to read this rather than guess it — a guessed
     * value produces a store that delivers and an ingress that rejects, with
     * nothing but a 409 to say why.
     *
     * @return string|null
     */
    public function getInstanceId(): ?string;

    /**
     * @param string|null $instanceId
     * @return $this
     */
    public function setInstanceId(?string $instanceId): self;

    /**
     * @return int|null
     */
    public function getCatalogueSize(): ?int;

    /**
     * @param int|null $size
     * @return $this
     */
    public function setCatalogueSize(?int $size): self;

    /**
     * @return string|null
     */
    public function getSubscriberCode(): ?string;

    /**
     * @param string|null $code
     * @return $this
     */
    public function setSubscriberCode(?string $code): self;

    /**
     * @return int|null
     */
    public function getSubscriptionCount(): ?int;

    /**
     * @param int|null $count
     * @return $this
     */
    public function setSubscriptionCount(?int $count): self;

    /**
     * @return int|null
     */
    public function getWaiting(): ?int;

    /**
     * @param int|null $waiting
     * @return $this
     */
    public function setWaiting(?int $waiting): self;

    /**
     * @return int|null
     */
    public function getInProgress(): ?int;

    /**
     * @param int|null $inProgress
     * @return $this
     */
    public function setInProgress(?int $inProgress): self;

    /**
     * @return int|null
     */
    public function getSent(): ?int;

    /**
     * @param int|null $sent
     * @return $this
     */
    public function setSent(?int $sent): self;

    /**
     * @return int|null
     */
    public function getFailed(): ?int;

    /**
     * @param int|null $failed
     * @return $this
     */
    public function setFailed(?int $failed): self;

    /**
     * @return int|null
     */
    public function getDeadLettered(): ?int;

    /**
     * @param int|null $deadLettered
     * @return $this
     */
    public function setDeadLettered(?int $deadLettered): self;

    /**
     * UTC timestamp of the oldest event still awaiting delivery, or null.
     *
     * @return string|null
     */
    public function getOldestWaitingAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setOldestWaitingAt(?string $timestamp): self;
}
