<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\TakenOverPayment;
use PHPUnit\Framework\TestCase;

/**
 * Platba zadaná ručně (source manual, např. účetní přepisuje lednovou úhradu z výpisu
 * a do poznámky zkopíruje text z Money) není platba převodu Money: zůstává v kontrole
 * DDKP i v CRM. Převzatou platbu pozná predikát podle zdroje a mapy převodu, ne jen
 * podle poznámky.
 */
final class TakenOverPaymentManualNoteTest extends TestCase
{
    public function testManualPaymentWithMoneyLikeNoteIsNotTaxDeclaredByOrigin(): void
    {
        self::assertFalse(TakenOverPayment::isTaxDeclaredByOrigin(['source' => 'manual', 'note' => 'Převzato z Money S3, úhrada BV270012']));
    }
}
