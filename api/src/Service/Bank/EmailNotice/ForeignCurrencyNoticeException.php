<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/** Avízo nese částku jen v jiné měně, než je vedený účet (#390). */
final class ForeignCurrencyNoticeException extends \RuntimeException
{
}
