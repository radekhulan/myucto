<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/** Neplatný rozsah účtů ve filtru; akce ho vrací jako 422 validation_failed. */
final class AccountCodeRangeException extends \InvalidArgumentException
{
}
