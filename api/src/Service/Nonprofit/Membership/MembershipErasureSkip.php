<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

/** Člen se od schválení návrhu změnil; výmaz ho přeskočí s důvodem (kód pro UI). */
final class MembershipErasureSkip extends \RuntimeException
{
    public function __construct(public readonly string $outcome, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
