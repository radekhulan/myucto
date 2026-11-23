<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Service\Payroll\Submission\Regzel\RegzelTaxOfficeCode;
use MyInvoice\Service\Tax\TaxOfficeBankAccounts;
use MyInvoice\Service\Tax\TaxOfficeCatalog;
use PHPUnit\Framework\TestCase;

final class TaxOfficeCatalogTest extends TestCase
{
    public function testEveryOfficeHasBankAccountAndEveryRegionalOfficeHasWorkplaces(): void
    {
        $offices = TaxOfficeCatalog::offices();
        self::assertCount(15, $offices);
        foreach ($offices as $office) {
            $account = TaxOfficeBankAccounts::account($office['code'], TaxOfficeBankAccounts::PREFIX_VAT);
            self::assertNotNull($account, $office['code']);
            self::assertSame($office['name'], $account['office_name']);
            $workplaces = TaxOfficeCatalog::workplaces($office['code']);
            if ($office['code'] === TaxOfficeCatalog::SPECIALIZED_OFFICE) {
                self::assertSame([], $workplaces);
            } else {
                self::assertNotSame([], $workplaces, $office['code']);
            }
        }
    }

    public function testEveryWorkplaceBelongsToKnownOffice(): void
    {
        $workplaces = TaxOfficeCatalog::workplaces();
        self::assertCount(124, $workplaces);
        foreach ($workplaces as $workplace) {
            self::assertNotNull(TaxOfficeCatalog::officeName($workplace['office_code']), $workplace['code']);
            self::assertSame($workplace['office_code'], TaxOfficeCatalog::officeOfWorkplace($workplace['code']));
        }
        self::assertSame('Územní pracoviště pro Prahu 1', TaxOfficeCatalog::workplaceName('2001'));
        self::assertSame('461', TaxOfficeCatalog::officeOfWorkplace('3004'));
        self::assertNull(TaxOfficeCatalog::officeOfWorkplace('2304'));
    }

    public function testRegzelCodesFollowCatalog(): void
    {
        self::assertSame('3000', TaxOfficeCatalog::regzelOfficeCode('461'));
        self::assertSame('4000', TaxOfficeCatalog::regzelOfficeCode('13'));
        self::assertSame('461', TaxOfficeCatalog::officeForRegzelCode('3000'));
        foreach (RegzelTaxOfficeCode::officeCodes() as $regzel) {
            $office = TaxOfficeCatalog::officeForRegzelCode($regzel);
            self::assertNotNull($office, $regzel);
            self::assertSame(
                array_column(TaxOfficeCatalog::workplaces($office), 'code'),
                RegzelTaxOfficeCode::workplacesForOffice($regzel),
            );
        }
    }

    public function testOfficeCodeWithLeadingZeroIsRecognised(): void
    {
        self::assertSame('Finanční úřad pro hlavní město Prahu', TaxOfficeCatalog::officeName('0451'));
        self::assertSame(['2001'], array_slice(array_column(TaxOfficeCatalog::workplaces(' 0451 '), 'code'), 0, 1));
        self::assertNull(TaxOfficeCatalog::officeName(''));
        self::assertNull(TaxOfficeCatalog::officeName('999'));
    }
}
