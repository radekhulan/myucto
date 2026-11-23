<?php

declare(strict_types=1);

namespace MyInvoice\Service\WorkReport;

/** Výkaz a přenesené položky se rozešly; vystavení musí skončit konfliktem. */
final class WorkReportBillingConflict extends \DomainException {}
