<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-attribute sync outcome.
 *
 * @api
 */
interface AttributeSyncResultInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const STATUS = 'status';
    public const REASON = 'reason';
    public const MESSAGES = 'messages';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    /**
     * Machine-readable reason codes accompanying skipped/error outcomes.
     */
    public const REASON_STRUCTURAL_CHANGE_REQUIRED = 'structural_change_required';
    public const REASON_UNSUPPORTED_TYPE = 'unsupported_type';
    public const REASON_INVALID_DEFINITION = 'invalid_definition';
    public const REASON_DISABLED = 'disabled';

    /**
     * @return string
     */
    public function getAttributeCode(): string;

    /**
     * @param string $attributeCode
     * @return $this
     */
    public function setAttributeCode(string $attributeCode): self;

    /**
     * One of: created, updated, unchanged, skipped, error.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Machine-readable reason code, or null. For structural_change_required
     * the have/requested values are described in getMessages().
     *
     * @return string|null
     */
    public function getReason(): ?string;

    /**
     * @param string|null $reason
     * @return $this
     */
    public function setReason(?string $reason): self;

    /**
     * Warnings and errors collected for this attribute.
     *
     * @return string[]
     */
    public function getMessages(): array;

    /**
     * @param string[] $messages
     * @return $this
     */
    public function setMessages(array $messages): self;
}
