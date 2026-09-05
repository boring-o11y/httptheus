<?php

namespace BoringO11y\Httptheus\Recording;

/**
 * One transfer's recording state, shared between the `on_stats` hook and the
 * promise fallback so that exactly one of them records it.
 */
class TransferState
{
    public bool $recorded = false;

    public bool $inFlight = false;

    public readonly float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Wall clock since the middleware was entered.
     *
     * Not the same thing as transfer time: in a request pool this includes the
     * time the request spent queued behind the concurrency limit. Only the
     * fallback path uses it, and only when nothing better was offered.
     */
    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }
}
