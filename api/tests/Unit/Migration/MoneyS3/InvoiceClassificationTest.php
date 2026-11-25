<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use MyInvoice\Service\Migration\MoneyS3\Ms3VatCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daňová povaha faktury z Money: druh dokladu v MyÚčtu, nárok na odpočet, kód zařazení
 * do přiznání a co jde ke ruční kontrole. Členění DPH (`KodDPH`) nese řádky přiznání,
 * na které Money doklad zařadilo, a příponu (krácený odpočet, majetek, splátky…).
 */
final class InvoiceClassificationTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,bool,float,bool,string,?string,string}> */
    public static function cases(): iterable
    {
        yield 'tuzemská přijatá' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full', null, 'invoice'];
        yield 'krácený odpočet § 76' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41 K'], false, 210.0, false, 'reduced', null, 'invoice'];
        yield 'majetek, krácený odpočet' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41MK'], false, 210.0, false, 'reduced', null, 'invoice'];
        yield 'majetek aktivovaný později' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41P'], false, 210.0, false, 'full', null, 'invoice'];
        yield 'poměrný nárok § 75' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41MR'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'tuzemská vydaná' => [['Druh' => 'N', 'KodDPH' => '19Ř01,02'], true, 210.0, false, 'full', null, 'invoice'];
        yield 'vydaná cestovní služba' => [['Druh' => 'N', 'KodDPH' => '19Ř01,02_C'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'bez členění a bez daně' => [['Druh' => 'N', 'KodDPH' => ''], false, 0.0, false, 'full', null, 'invoice'];
        yield 'přijatá mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00P'], false, 504.0, false, 'none', null, 'invoice'];
        yield 'vydaná mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00U'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'přenesená daňová povinnost na vstupu' => [['Druh' => 'N', 'KodDPH' => '19Ř10,43'], false, 0.0, true, 'full', null, 'invoice'];
        yield 'dodání zboží do EU' => [['Druh' => 'N', 'KodDPH' => '19Ř20'], true, 0.0, false, 'full', '20', 'invoice'];
        yield 'služba do EU' => [['Druh' => 'N', 'KodDPH' => '19Ř21'], true, 0.0, false, 'full', '22', 'invoice'];
        yield 'přenesení povinnosti, odpad' => [['Druh' => 'N', 'KodDPH' => '19Ř25'], true, 0.0, false, 'full', '25s5', 'invoice'];
        yield 'přenesení povinnosti, stavba' => [['Druh' => 'N', 'KodDPH' => '19Ř25_S'], true, 0.0, false, 'full', '25s', 'invoice'];
        yield 'osvobozené bez nároku' => [['Druh' => 'N', 'KodDPH' => '19Ř50'], true, 0.0, false, 'full', '3', 'invoice'];
        yield 'osvobozené mimo koeficient' => [['Druh' => 'N', 'KodDPH' => '19Ř51BN'], true, 0.0, false, 'full', '3m', 'invoice'];
        yield 'dodání do EU s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř20'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'neznámý tvar členění' => [['Druh' => 'N', 'KodDPH' => 'PD'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'daň bez členění' => [['Druh' => 'N', 'KodDPH' => ''], false, 210.0, true, 'full', null, 'invoice'];
        yield 'přijatá zálohová' => [['Druh' => 'L', 'KodDPH' => ''], false, 0.0, false, 'full', null, 'advance'];
        yield 'starší zálohová' => [['Druh' => 'Z', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full', null, 'advance'];
        yield 'vydaná zálohová' => [['Druh' => 'F', 'KodDPH' => ''], true, 210.0, false, 'full', null, 'proforma'];
        yield 'daňový doklad k platbě přijatý' => [['Druh' => 'D', 'KodDPH' => '19Ř40,41 K'], false, 210.0, false, 'reduced', null, 'tax_document'];
        yield 'daňový doklad k platbě vydaný' => [['Druh' => 'D', 'KodDPH' => '19Ř01,02'], true, 210.0, false, 'full', null, 'tax_document'];
        yield 'dobropis' => [['Druh' => 'N', 'Dobropis' => 1, 'KodDPH' => '19Ř40,41'], false, -105.0, false, 'full', null, 'credit_note'];
        yield 'dobropis zálohy' => [['Druh' => 'L', 'Dobropis' => 1, 'KodDPH' => ''], false, 0.0, true, 'full', null, 'advance'];
        yield 'neznámý druh' => [['Druh' => 'X', 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'storno' => [['Druh' => 'N', 'Storno' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'neúčtovat' => [['Druh' => 'N', 'Neuctovat' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'cizí měna' => [['Druh' => 'N', 'Mena' => 'EUR', 'Kurs' => 25.0, 'KodDPH' => '19Ř40,41'], false, 525.0, false, 'full', null, 'invoice'];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('cases')]
    public function testClassification(array $row, bool $issued, float $vat, bool $review, string $deduction, ?string $code, string $kind): void
    {
        $class = InvoiceImporter::classify($row, $issued, $vat);

        $info = json_encode($class, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertSame($review, $class['reasons'] !== [], $info);
        self::assertSame($deduction, $class['vat_deduction'], $info);
        self::assertSame($code, $class['code'], $info);
        self::assertSame($kind, $class['kind'], $info);
    }

    /** Přípona M/P/MK/PK = odpočet u pořízení majetku: doklad nese příznak pro ř. 47. */
    public function testFixedAssetSuffixesAreFlagged(): void
    {
        foreach (['19Ř40,41M' => true, '19Ř40,41P' => true, '19Ř40,41MK' => true, '19Ř40,41PK' => true, '19Ř40,41' => false, '19Ř40,41 K' => false, '19Ř40,41_S' => false] as $code => $asset) {
            self::assertSame($asset, InvoiceImporter::classify(['Druh' => 'N', 'KodDPH' => $code], false, 210.0)['fixed_asset'], $code);
        }
        self::assertFalse(InvoiceImporter::classify(['Druh' => 'N', 'KodDPH' => '19Ř01,02'], true, 210.0)['fixed_asset']);
    }

    public function testCashCodesUseTheSameResolver(): void
    {
        self::assertSame(['in_return' => true, 'code' => null, 'deduction' => 'reduced', 'fixed_asset' => false], Ms3VatCode::resolve('19Ř40,41 K', false));
        self::assertSame(['in_return' => true, 'code' => null, 'deduction' => 'full', 'fixed_asset' => true], Ms3VatCode::resolve('19Ř40,41M', false));
        self::assertSame(['in_return' => true, 'code' => null, 'deduction' => 'reduced', 'fixed_asset' => true], Ms3VatCode::resolve('19Ř40,41PK', false));
        self::assertSame(['in_return' => false, 'code' => null, 'deduction' => 'none', 'fixed_asset' => false], Ms3VatCode::resolve('19Ř00P', false));
        self::assertNull(Ms3VatCode::resolve('19Ř43,44', false));
        self::assertNull(Ms3VatCode::resolve('19Ř40,41', true), 'Řádek odpočtu na výstupní straně je chyba členění.');
    }

    /**
     * Předmět plnění tuzemského přenosu si agenda nastavuje u členění sama: `19Ř25` výchozího
     * číselníku je odpad, firma vykupující elektřinu ho má jako dodání elektřiny (19).
     * Předmět, pro který MyÚčto nemá klasifikaci, jde k ruční kontrole místo cizího kódu.
     */
    public function testDomesticReverseSaleFollowsAgendaSubject(): void
    {
        $sale = static fn (string $code, array $subjects): array => InvoiceImporter::classify(['Druh' => 'N', 'KodDPH' => $code], true, 0.0, $subjects);
        self::assertSame('25s19', $sale('19Ř25', ['19Ř25' => '19'])['code']);
        self::assertSame('25s20', $sale('19Ř25', ['19Ř25' => '20'])['code']);
        self::assertSame('25s5', $sale('19Ř25', ['19Ř25_S' => '19'])['code'], 'Předmět jiného členění se nepřebírá.');
        self::assertSame('25s5', $sale('19Ř25', [])['code'], 'Bez číselníku agendy platí výchozí odpad.');
        self::assertSame('25s', $sale('19Ř25_S', ['19Ř25_S' => '4'])['code']);
        self::assertNotSame([], $sale('19Ř25', ['19Ř25' => '13'])['reasons'], 'Kovy (13) MyÚčto nezná, doklad jde ke kontrole.');
        self::assertSame(['code' => '5s19', 'deduction' => 'full', 'fixed_asset' => false], Ms3VatCode::reverseCharge('19Ř10,11', '19Ř43,44', '19'));
    }

    public function testReverseChargeFromInternalDocumentLines(): void
    {
        self::assertSame(['code' => '24e', 'deduction' => 'reduced', 'fixed_asset' => false], Ms3VatCode::reverseCharge('19Ř05,06', '19Ř43,44 K'));
        self::assertSame(['code' => '23', 'deduction' => 'full', 'fixed_asset' => false], Ms3VatCode::reverseCharge('19Ř03,04', '19Ř43,44'));
        self::assertSame(['code' => '5', 'deduction' => 'full', 'fixed_asset' => true], Ms3VatCode::reverseCharge('19Ř10,11_S', '19Ř43,44P', '4'));
        self::assertSame(['code' => '24', 'deduction' => 'none', 'fixed_asset' => false], Ms3VatCode::reverseCharge('19Ř12,13', null));
        self::assertNull(Ms3VatCode::reverseCharge('19Ř10,11_S', '19Ř42   P', '4'), 'Odpočet z dovozu k tuzemskému přenosu převod neodhaduje.');
        self::assertNull(Ms3VatCode::reverseCharge('19Ř40,41', '19Ř43,44'));
        self::assertTrue(Ms3VatCode::isReverseChargeOutput('19Ř10,11_S'));
        self::assertFalse(Ms3VatCode::isReverseChargeOutput('19Ř43,44 K'));
    }

    public function testPaymentMethodFromMoneyLabel(): void
    {
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod('převodem'));
        self::assertSame('card', InvoiceImporter::paymentMethod('platební kartou'));
        self::assertSame('cash', InvoiceImporter::paymentMethod('v hotovosti'));
        self::assertSame('cash_on_delivery', InvoiceImporter::paymentMethod('dobírkou'));
        self::assertSame('direct_debit', InvoiceImporter::paymentMethod('inkasem'));
        self::assertSame('offset', InvoiceImporter::paymentMethod('zápočtem'));
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod(''));
        self::assertSame('other', InvoiceImporter::paymentMethod('šekem'));
    }
}
