<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\PostingRuleLineScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostingRuleLineScopeTest extends TestCase
{
    /** @return iterable<string,array{string,?string,?string,bool,bool}> */
    public static function rules(): iterable
    {
        yield 'služby přijaté' => ['invoice.services.received', '518', '321', true, false];
        yield 'DHM přijaté' => ['invoice.dhm.received', '042', '321', true, false];
        yield 'služby vydané' => ['invoice.services.issued', '311', '602', false, true];
        yield 'penalizace vydaná' => ['invoice.penalty.issued', '311', '644', false, true];
        yield 'přepsání systémového klíče jinými účty' => ['invoice.services.received', '549', '324', true, false];
        yield 'příjmy příštích období' => ['accrual.accrued.revenue', '385', null, false, false];
        yield 'výdaje příštích období' => ['accrual.accrued.expense', null, '383', false, false];
        yield 'komplexní náklady' => ['accrual.complex.defer', '382', '555', false, false];
        yield 'dohadná pasivní' => ['estimate.liability', null, '389', false, false];
        yield 'zúčtování přijaté zálohy' => ['advance.received.settlement', '324', '311', false, false];
        yield 'DDKP k přijaté záloze' => ['advance.received.vatdocument', '324', '343.200', false, false];
        yield 'úhrada vydané' => ['payment.receivable.bank', '221', '311', false, false];
        yield 'úhrada přijaté' => ['payment.payable.bank', '321', '221', false, false];
        yield 'OSS' => ['oss.output.vat', null, '345.100', false, false];
        yield 'DPH na vstupu' => ['invoice.vat.input', '343.100', '321', false, false];
        yield 'vlastní pravidlo přijaté podle účtů' => ['custom.leasing', '518', '321', true, false];
        yield 'vlastní pravidlo vydané podle účtů' => ['custom.najem', '311', '602', false, true];
        yield 'vlastní pravidlo bez faktury' => ['custom.bankovni', '568', '221', false, false];
        yield 'vlastní pravidlo na zálohu' => ['custom.zaloha', '324', '311', false, false];
    }

    #[DataProvider('rules')]
    public function testScope(string $key, ?string $debit, ?string $credit, bool $purchase, bool $sale): void
    {
        self::assertSame(['purchase' => $purchase, 'sale' => $sale], PostingRuleLineScope::forRule($key, $debit, $credit));
    }
}
