<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/** Doklad, který {@see PohodaOtherLiabilityRepair} nepřevede; zpráva je důvod pro výpis. */
final class PohodaOtherLiabilityRefused extends \RuntimeException
{
}
