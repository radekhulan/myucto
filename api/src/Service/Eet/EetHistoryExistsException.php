<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/** Mazání dokladu s historií EET ({@see EetHistoryGuard}); HTTP 409 `eet_history_exists`. */
final class EetHistoryExistsException extends \RuntimeException
{
}
