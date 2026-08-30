<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Service\Oss\OssEvidenceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidence § 110f ZDPH / čl. 63c nařízení (EU) č. 282/2011 — pravidla, která se dají
 * ověřit bez databáze: lhůta uchování a tvar exportu.
 *
 * Nejdůležitější je {@see testRetentionRunsFromTheYearOfSupplyNotOfFiling()}. Lhůta se
 * počítá od konce roku, ve kterém bylo PLNĚNÍ uskutečněno — ne od podání. U opravy
 * staršího období (VetaO) se tyhle dva roky liší a odvození od podání by evidenci
 * nechalo doběhnout dřív, než skončí zákonná povinnost.
 */
#[Group('unit')]
final class OssEvidenceServiceTest extends TestCase
{
    public function testRetentionRunsFromTheYearOfSupplyNotOfFiling(): void
    {
        // Plnění 2026 → uchovat do konce 2036 (§ 110f/2/a: 10 let od KONCE roku plnění).
        self::assertSame('2036-12-31', OssEvidenceService::retainUntil('2026-03-15'));
        // Poslední den roku spadá pořád do téhož roku — hraniční případ, na kterém by
        // se dvě kopie pravidla „+10 let" rozešly.
        self::assertSame('2036-12-31', OssEvidenceService::retainUntil('2026-12-31'));
        // Oprava plnění z roku 2022 podaná v roce 2026 se drží roku PLNĚNÍ.
        self::assertSame('2032-12-31', OssEvidenceService::retainUntil('2022-01-01'));
    }

    public function testRetentionIsTenYears(): void
    {
        self::assertSame(10, OssEvidenceService::RETENTION_YEARS);

        $year = 2030;
        self::assertSame(
            sprintf('%04d-12-31', $year + OssEvidenceService::RETENTION_YEARS),
            OssEvidenceService::retainUntil($year . '-07-01'),
        );
    }

    /** Lhůta se čte z roční sady (volající ji předá) — literál je jen fallback default. */
    public function testRetentionYearsComesFromExplicitParameterNotLiteral(): void
    {
        self::assertSame('2041-12-31', OssEvidenceService::retainUntil('2026-03-15', 15));
    }

    /**
     * Export musí být „za každé jednotlivé plnění" (čl. 63c odst. 3), tedy jeden řádek
     * na záznam, a hlavička musí u každého sloupce nést písmeno bodu čl. 63c — jinak
     * se při kontrole nedá doložit, který údaj kterou povinnost plní.
     */
    public function testCsvHeaderNamesTheArticlePoints(): void
    {
        $rows = OssEvidenceService::csvRows([]);

        self::assertCount(1, $rows, 'Bez záznamů zůstane jen hlavička.');
        $header = $rows[0];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k'] as $point) {
            self::assertNotEmpty(
                array_filter($header, static fn (string $col): bool => str_contains($col, "63c(1)({$point})")),
                "Hlavička neoznačuje bod čl. 63c odst. 1 písm. {$point}.",
            );
        }
        self::assertContains('uchovat_do', $header);
        self::assertContains('nenaplnene_body', $header);
    }

    public function testCsvRowCarriesBothCurrenciesAndTheUnfulfilledPoints(): void
    {
        $rows = OssEvidenceService::csvRows([self::record()]);

        self::assertCount(2, $rows);
        $header = $rows[0];
        $row = array_combine($header, $rows[1]);

        self::assertSame('PL', $row['63c(1)(a) stat_spotreby']);
        self::assertSame('2026-02-10', $row['63c(1)(c) datum_plneni']);
        // Bod d) žádá „taxable amount INDICATING THE CURRENCY USED" — měna dokladu se
        // proto nesmí ztratit přepočtem do měny podání; v evidenci jsou obě.
        self::assertSame('25000.00', $row['63c(1)(d) zaklad_dane']);
        self::assertSame('CZK', $row['63c(1)(d) mena_dokladu']);
        self::assertSame('1000.00', $row['63c(1)(d) zaklad_dane_v_mene_podani']);
        self::assertSame('EUR', $row['63c(1)(d) mena_podani']);
        self::assertSame('2026-03-05 5750.00 CZK', $row['63c(1)(h) uhrady']);
        self::assertSame('2036-12-31', $row['uchovat_do']);
        self::assertStringContainsString('l', $row['nenaplnene_body']);
    }

    /**
     * Kurz a jeho datum patří k bodu d): bez nich se nedá doložit, jak částka v měně
     * podání z částky na dokladu vznikla. Export je musí vydat, ne jen uložit do DB.
     */
    public function testCsvCarriesTheExchangeRateOfPointD(): void
    {
        $rows = OssEvidenceService::csvRows([
            self::record() + ['exchange_rate' => '0.04000000', 'exchange_rate_date' => '2026-03-31'],
        ]);
        $row = array_combine($rows[0], $rows[1]);

        self::assertSame('0.04000000', $row['63c(1)(d) kurz']);
        self::assertSame('2026-03-31', $row['63c(1)(d) datum_kurzu']);
    }

    /** Nedoložitelný kurz zůstane prázdný — vymyšlená hodnota by se četla jako doklad. */
    public function testCsvLeavesTheExchangeRateEmptyWhenItCannotBeEvidenced(): void
    {
        $rows = OssEvidenceService::csvRows([
            self::record() + ['exchange_rate' => null, 'exchange_rate_date' => null],
        ]);
        $row = array_combine($rows[0], $rows[1]);

        self::assertSame('', $row['63c(1)(d) kurz']);
        self::assertSame('', $row['63c(1)(d) datum_kurzu']);
    }

    /**
     * Nenaplněné body se PŘIZNÁVAJÍ. Kdyby konstanta zmizela nebo se vyprázdnila,
     * export by mlčky tvrdil, že evidence pokrývá celý čl. 63c — což nepokrývá.
     */
    public function testUnsupportedPointsAreDeclaredAndExplained(): void
    {
        self::assertNotSame([], OssEvidenceService::UNSUPPORTED_POINTS);
        foreach (OssEvidenceService::UNSUPPORTED_POINTS as $point => $reason) {
            self::assertIsString($point);
            self::assertNotSame('', trim($reason), "Bod {$point} nemá vysvětlení, proč chybí.");
        }
        self::assertArrayHasKey('i', OssEvidenceService::UNSUPPORTED_POINTS);
        self::assertArrayHasKey('l', OssEvidenceService::UNSUPPORTED_POINTS);
    }

    public function testLegalBasisNamesBothTheActAndTheRegulation(): void
    {
        self::assertStringContainsString('110f', OssEvidenceService::LEGAL_BASIS);
        self::assertStringContainsString('63c', OssEvidenceService::LEGAL_BASIS);
    }

    /** @return array<string,mixed> */
    private static function record(): array
    {
        return [
            'seq'                   => 1,
            'consumption_country'   => 'PL',
            'supply_type'           => 'goods',
            'supply_description'    => 'Testovací zboží',
            'supply_quantity'       => '2.000',
            'supply_unit'           => 'ks',
            'supply_date'           => '2026-02-10',
            'taxable_amount'        => '25000.00',
            'taxable_currency'      => 'CZK',
            'taxable_amount_return' => '1000.00',
            'return_currency'       => 'EUR',
            'adjusted_period'       => null,
            'vat_rate'              => '23.00',
            'vat_rate_type'         => 'standard',
            'vat_amount'            => '5750.00',
            'vat_amount_return'     => '230.00',
            'payments'              => [['paid_on' => '2026-03-05', 'amount' => '5750.00', 'currency' => 'CZK']],
            'invoice_id'            => 42,
            'invoice_item_id'       => 77,
            'invoice_snapshot'      => ['doc_number' => 'TEST-2026-001'],
            'customer_name'         => 'Testovací odběratel',
            'place_evidence'        => ['customer_country' => 'PL', 'customer_vat_id' => null],
            'completeness'          => OssEvidenceService::UNSUPPORTED_POINTS,
            'retain_until'          => '2036-12-31',
        ];
    }
}
