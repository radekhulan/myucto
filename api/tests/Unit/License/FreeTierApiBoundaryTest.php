<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\CommercialFeatureAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Co musí fungovat bez licence a co je za ní.
 *
 * MIT základ převzatý z MyInvoice (fakturace, klienti, banka, dokumenty, DPH
 * přiznání i NASTAVENÍ FIRMY) zůstává plně funkční i s prošlou nebo žádnou
 * licencí — jinak by instalace po vypršení trialu skončila jako nepoužitelná
 * a uživatel by nemohl ani opravit e-mail firmy.
 */
final class FreeTierApiBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function freePaths(): iterable
    {
        yield 'nastavení firmy'   => ['/api/settings/supplier'];
        yield 'plátcovství DPH'   => ['/api/settings/vat-status-history'];
        yield 'vydané faktury'    => ['/api/invoices'];
        yield 'přijaté faktury'   => ['/api/purchase-invoices'];
        yield 'klienti'           => ['/api/clients'];
        yield 'dokumenty'         => ['/api/documents'];
        yield 'bankovní výpisy'   => ['/api/bank-statements'];
        yield 'všechny pohyby'    => ['/api/bank-transactions'];
        yield 'pokladna'          => ['/api/accounting/cash-documents'];
        yield 'pokladní doklad'   => ['/api/accounting/cash-documents/12'];
        yield 'pokladny'          => ['/api/accounting/cash-registers'];
        yield 'bankovní účty'     => ['/api/accounting/bank-accounts'];
        yield 'přiznání k DPH'    => ['/api/reports/dph'];
        yield 'kontrolní hlášení' => ['/api/reports/kh'];
        yield 'sazby OSS'         => ['/api/codebooks/oss-member-state-rates'];
    }

    /** @return iterable<string, array{string}> */
    public static function commercialPaths(): iterable
    {
        yield 'sklad'               => ['/api/stock/items'];
        yield 'e-shop'              => ['/api/eshop/orders'];
        yield 'mzdy'                => ['/api/payroll/runs'];
        yield 'mzdy — capabilities' => ['/api/payroll/capabilities'];
        yield 'OSS přiznání'        => ['/api/reports/oss'];
        yield 'OSS — hromadné zařazení' => ['/api/invoices/bulk-oss'];
        yield 'deník'               => ['/api/accounting/journal'];
        yield 'zaúčtování faktury'  => ['/api/invoices/12/book'];
        yield 'výdejka k faktuře'   => ['/api/invoices/12/stock-documents'];
        yield 'zaúčtování banky'    => ['/api/bank-transactions/5/post'];
        // Vést pokladnu je zdarma, zaúčtovat doklad je účetnictví — zakládá
        // zápis v deníku, který si zákazník bez licence nesmí ani přečíst.
        yield 'zaúčtování pokladny' => ['/api/accounting/cash-documents/12/post'];
        yield 'storno zaúčtování'   => ['/api/accounting/cash-documents/12/reverse'];
        yield 'automatizace'        => ['/api/automation/queue'];
        yield 'přehled firem'       => ['/api/portfolio/summary'];
        yield 'daňová evidence'     => ['/api/tax-evidence/cash-journal'];
        yield 'aktivace účetnictví' => ['/api/settings/accounting-activation'];
        yield 'oprava odpočtu §74b' => ['/api/reports/s74b'];
    }

    #[DataProvider('freePaths')]
    public function testFreeTierKeepsWorking(string $path): void
    {
        self::assertFalse(
            CommercialFeatureAccess::restrictsApiPath($path),
            "{$path} musí fungovat i bez licence — patří do bezplatného základu.",
        );
    }

    #[DataProvider('commercialPaths')]
    public function testCommercialModulesStayBehindTheLicence(string $path): void
    {
        self::assertTrue(
            CommercialFeatureAccess::restrictsApiPath($path),
            "{$path} je komerční modul a bez licence se nesmí obsloužit.",
        );
    }
}
