<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Delivery;

use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Delivery\Signer;

class SignerTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new Signer();
    }

    public function testSignatureRoundTrips(): void
    {
        $body = '{"events":[]}';
        $header = $this->signer->sign($body, 'sh-secret');

        self::assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
        self::assertTrue($this->signer->verify($header, $body, 'sh-secret'));
    }

    public function testATamperedBodyFailsVerification(): void
    {
        $header = $this->signer->sign('{"amount":10}', 'sh-secret');

        self::assertFalse($this->signer->verify($header, '{"amount":10000}', 'sh-secret'));
    }

    public function testTheWrongSecretFailsVerification(): void
    {
        $header = $this->signer->sign('{}', 'sh-secret');

        self::assertFalse($this->signer->verify($header, '{}', 'other-secret'));
    }

    /**
     * The timestamp is inside the signed material precisely so a captured
     * payload cannot be replayed forever.
     */
    public function testAnOldSignatureIsRejected(): void
    {
        $body = '{}';
        $stale = time() - 3600;
        $header = $this->signer->sign($body, 'sh-secret', $stale);

        self::assertFalse($this->signer->verify($header, $body, 'sh-secret', 300));
        self::assertTrue(
            $this->signer->verify($header, $body, 'sh-secret', 7200),
            'It is the tolerance that rejects it, not a broken digest.'
        );
    }

    /** Moving the timestamp invalidates the digest, so replay cannot be faked. */
    public function testTheTimestampCannotBeMovedWithoutBreakingTheSignature(): void
    {
        $body = '{}';
        $header = $this->signer->sign($body, 'sh-secret', time() - 3600);
        $forged = preg_replace('/^t=\d+/', 't=' . time(), $header);

        self::assertFalse($this->signer->verify((string)$forged, $body, 'sh-secret'));
    }

    public function testMalformedHeadersAreRejected(): void
    {
        foreach (['', 'garbage', 't=abc,v1=def', 'v1=deadbeef', 't=' . time()] as $header) {
            self::assertFalse($this->signer->verify($header, '{}', 'sh-secret'), $header);
        }
    }
}
