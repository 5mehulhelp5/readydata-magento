<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

/**
 * Signs a delivery so the subscriber can prove it came from this store.
 *
 * Stripe's scheme: `t=<unix>,v1=<hex hmac-sha256 of "t.body">`. The timestamp is
 * inside the signed material, which is what stops a captured payload being
 * replayed forever — a receiver rejects anything outside its clock-skew
 * tolerance, and an attacker cannot move the timestamp without invalidating the
 * signature.
 *
 * Adobe authenticates to its own bus with OAuth server-to-server; we post
 * directly to the subscriber, so a shared secret over a signed body is both
 * sufficient and considerably less machinery.
 */
class Signer
{
    public const HEADER = 'X-ReadyData-Signature';

    private const ALGORITHM = 'sha256';

    public function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, $this->digest($body, $secret, $timestamp));
    }

    public function digest(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac(self::ALGORITHM, $timestamp . '.' . $body, $secret);
    }

    /**
     * Provided so the contract is testable from this side, and because whoever
     * implements the ReadyData end should be able to read the verification in
     * the same file that produces the signature.
     */
    public function verify(string $header, string $body, string $secret, int $tolerance = 300): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        if (!isset($parts['t'], $parts['v1']) || !ctype_digit((string)$parts['t'])) {
            return false;
        }

        $timestamp = (int)$parts['t'];
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Constant time: a fast-failing comparison leaks how much of a forged
        // signature was correct, which is enough to reconstruct it byte by byte.
        return hash_equals($this->digest($body, $secret, $timestamp), (string)$parts['v1']);
    }
}
