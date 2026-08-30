<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function test auto-klasifikace prodejních položek pro DPH přiznání / KH
 * (InvoiceRepository::defaultSaleClassificationCode). Pokrývá tuzemsko vs.
 * reverse charge do zahraničí (EU služby / vývoz mimo EU).
 */
final class SaleClassificationCodeTest extends TestCase
{
    public function testDomesticStandardRate(): void
    {
        // Tuzemská základní sazba → '1' (ř. 1 přiznání, KH A.4/A.5).
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, 'CZ', null, 21.0));
    }

    public function testDomesticReducedRate(): void
    {
        // Tuzemská snížená sazba (12/15 %) → '2'.
        self::assertSame('2', InvoiceRepository::defaultSaleClassificationCode(12.0, false, 'CZ', null, 21.0));
    }

    public function testDomesticZeroRate(): void
    {
        // Tuzemsko, nulová sazba → BEZ kódu. Osvobozené plnění § 51 ('3', ř. 50) si účetní
        // označí sama; stejně často je nulový řádek přeúčtování nákladů, náhrada škody nebo
        // smluvní pokuta, tedy plnění mimo předmět daně, které na ř. 50 nepatří a nafukovalo
        // by jmenovatel koeficientu § 76 (audit VAT klasifikací, H-1).
        self::assertNull(InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'CZ', null, 21.0));
    }

    public function testRateBandBelowStandardIsNotMapped(): void
    {
        // Pásmo 16 % až pod základní sazbu (historická CZ 20 %, cizí sazby mimo OSS) nemá
        // českou klasifikaci — dřív propadlo na '3' (osvobozeno) a šlo na ř. 50 (H-2).
        self::assertNull(InvoiceRepository::defaultSaleClassificationCode(19.0, false, 'CZ', null, 21.0));
        self::assertNull(InvoiceRepository::defaultSaleClassificationCode(20.0, false, 'CZ', null, 21.0));
        // Se změněnou základní sazbou se hranice posune s ní, ne s natvrdo zapsanou 21.
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(20.0, false, 'CZ', null, 20.0));
    }

    public function testReverseChargeToEuZeroRateGoesToEuServices(): void
    {
        // Reverse charge DO ZAHRANIČÍ (EU), nulová sazba → '22' (poskytnutí služeb do EU).
        self::assertSame('22', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'DE', null, 21.0));
        self::assertSame('22', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'SK', null, 21.0));
    }

    public function testZeroRateOutsideEuSplitsGoodsFromServices(): void
    {
        // Vývoz ZBOŽÍ do 3. země → '26' (§ 66, ř. 22 pln_vyvoz).
        self::assertSame('26', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'US', 'kg', 21.0));
        self::assertSame('26', InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'CH', 'paleta', 21.0));
        // SLUŽBA pro klienta ze 3. země žádný vývoz zboží není — je to plnění s místem
        // plnění mimo tuzemsko s nárokem na odpočet, tedy '26s' → ř. 26 (pln_ost).
        self::assertSame('26s', InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'US', 'h', 21.0));
        self::assertSame('26s', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'CH', 'měsíc', 21.0));
        // Bez signálu ('ks' je defaultní hodnota sloupce, nic neříká) platí týž statistický
        // default jako u EU větve — služba. U typického uživatele (IT, poradenství) jsou
        // přeshraniční služby častější než vývoz zboží (audit VAT klasifikací, H-3).
        self::assertSame('26s', InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'US', 'ks', 21.0));
    }

    public function testDomesticReverseChargeGoesToSection92a(): void
    {
        // Reverse charge V ČR (§ 92a — stavební práce, odpad, zlato) → '25s', tedy ř. 25
        // (pln_rez_pren) + věta KH A.1. Dřív takový doklad propadl na '3' (osvobozeno,
        // ř. 50) a z kontrolního hlášení úplně zmizel, přestože hlavička od
        // VatClassificationDefaulter dostávala správné '25s' (audit VAT klasifikací, H-1).
        self::assertSame('25s', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'CZ', null, 21.0));
    }

    public function testForeignCustomerWithCzechRateUsesDomesticBucket(): void
    {
        // B2C cizinec s českou DPH sazbou (ne nulová) → klasifikuje se jako tuzemská sazba.
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, 'DE', null, 21.0));
    }

    public function testNullCountryDefaultsToDomestic(): void
    {
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, null, null, 21.0));
    }
}
