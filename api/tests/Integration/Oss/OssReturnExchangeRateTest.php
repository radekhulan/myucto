<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Oss;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přepočet do měny OSS podání — kurz ECB pro POSLEDNÍ DEN zdaňovacího období.
 *
 * Do konce roku 2026 se tady přepočítávalo denním kurzem ČNB k datu plnění každého
 * dokladu, tedy kurzem pro tuzemský základ daně. Pro OSS to je špatně: Finanční správa
 * k režimu EU uvádí, že se použije směnný kurz ECB zveřejněný pro poslední den
 * zdaňovacího období, a není-li pro ten den zveřejněn, pro nejbližší následující den
 * (shodně čl. 369h odst. 3 směrnice 2006/112/ES). Rozdíly jsou tři najednou — jiná banka,
 * jeden kurz na celé čtvrtletí, jiné rozhodné datum — a dopadají přímo na částku, kterou
 * zákazník podá.
 *
 * Testy proto seedují TŘI různé kurzy pro tentýž doklad: kurz ČNB k DUZP, kurz ECB k DUZP
 * a kurz ECB ke konci období. Každá ze tří možných (i chybných) implementací dá jinou
 * částku, takže se nedají splést.
 *
 * Kurzy jsou syntetické (25 / 28 / 30 za 1 EUR) a rok je fiktivní, aby test nikdy nesáhl
 * na síť ani nezávisel na skutečných datech ECB.
 */
#[Group('integration')]
final class OssReturnExchangeRateTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Q2 fiktivního roku. 30. 6. 2096 je PÁTEK, 31. 3. 2096 je SOBOTA — obojí se hodí. */
    private const YEAR = 2096;

    private Connection $db;
    private OssLedgerService $oss;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $rateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->oss = $c->get(OssLedgerService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if (!$this->db->hasTable('ecb_exchange_rates')) {
            $this->markTestSkipped('Chybí migrace 1299 (kurzy ECB).');
        }

        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czkId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $this->eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn() ?: 0);
        $this->rateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->czkId === 0 || $this->eurId === 0 || $this->rateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / měny / sazba 21 %).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier
                SET oss_enabled = 1, oss_valid_from = '2090-01-01', oss_valid_to = NULL,
                    oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Jádro opravy: rozhoduje kurz ECB ke konci kvartálu, ne k datu plnění a ne ČNB.
     *
     * 100 000 Kč / 25,000 = 4 000,00 EUR. Kurz ECB k DUZP (28) by dal 3 571,43,
     * kurz ČNB k DUZP (30) 3 333,33 — obě čísla by vypadala stejně věrohodně.
     */
    public function testPeriodEndEcbRateWinsOverTaxDateAndOverCnb(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);
        $this->seedEcbDay('2096-05-15', 28.0);
        $this->seedCnbDay('2096-05-15', 30.0);
        $this->ossSale('2096-05-15', 100_000.0);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(4000.0, $preview['summary']['total_base']);
        self::assertSame('2096-06-30', $preview['summary']['return_rate_date']);
        self::assertSame(0, $preview['summary']['conversion_missing_count']);
    }

    /** Jeden kurz na CELÉ období — dva doklady z různých měsíců se přepočtou stejně. */
    public function testWholePeriodSharesOneRate(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);
        $this->seedEcbDay('2096-04-10', 20.0);
        $this->seedEcbDay('2096-06-20', 40.0);
        $this->ossSale('2096-04-10', 50_000.0);
        $this->ossSale('2096-06-20', 50_000.0);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(4000.0, $preview['summary']['total_base'], '2× 50 000 Kč jedním kurzem 25.');
    }

    /**
     * „Není-li kurz pro poslední den zveřejněn, použije se nejbližší NÁSLEDUJÍCÍ den."
     * 31. 3. 2096 je sobota, takže ECB kurz zveřejní až v pondělí 2. 4.
     */
    public function testUnpublishedPeriodEndFallsForwardToNextPublicationDay(): void
    {
        $this->markDay('2096-03-31', published: false);
        $this->markDay('2096-04-01', published: false);
        $this->seedEcbDay('2096-04-02', 25.0);
        // Kurz PŘED koncem období nesmí vyhrát — pravidlo míří dopředu, ne dozadu.
        $this->seedEcbDay('2096-03-30', 50.0);
        $this->ossSale('2096-02-20', 100_000.0);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 1);

        self::assertSame('2096-04-02', $preview['summary']['return_rate_date']);
        self::assertSame(4000.0, $preview['summary']['total_base']);
    }

    /**
     * Chybějící kurz se NEnahrazuje. Kdyby se tiše sáhlo po ČNB nebo po starším dni,
     * podání by vypadalo hotově a bylo by špatně — proto nula, varování a stopka pro XML.
     */
    public function testMissingEcbRateWarnsInsteadOfSubstitutingCnb(): void
    {
        $this->seedCnbDay('2096-05-15', 30.0);
        $this->ossSale('2096-05-15', 100_000.0);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(0.0, $preview['summary']['total_base']);
        self::assertSame(1, $preview['summary']['conversion_missing_count']);
        self::assertNull($preview['summary']['return_rate_date']);

        $warnings = implode("\n", $preview['warnings']);
        self::assertStringContainsString('Kurz ECB pro poslední den období', $warnings);
        self::assertStringContainsString('30. 6. 2096', $warnings);
        self::assertStringContainsString('zatím nebyl zveřejněn', $warnings, 'Období v budoucnu má vlastní hlášku.');
    }

    /** Ruční kurz na položce má přednost před kurzem období — je to rozhodnutí účetního. */
    public function testManualRateOnItemStillWins(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);
        $invoiceId = $this->ossSale('2096-05-15', 100_000.0);
        $this->db->pdo()->prepare('UPDATE invoice_items SET oss_exchange_rate = 0.05 WHERE invoice_id = ?')
            ->execute([$invoiceId]);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(5000.0, $preview['summary']['total_base']);
    }

    /** Ruční částka má přednost i před ručním kurzem a kurz období vůbec nepotřebuje. */
    public function testManualReturnAmountNeedsNoRateAtAll(): void
    {
        $invoiceId = $this->ossSale('2096-05-15', 100_000.0);
        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET oss_taxable_amount_return = 1234.56, oss_vat_amount_return = 259.26
              WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(1234.56, $preview['summary']['total_base']);
        self::assertSame(0, $preview['summary']['conversion_missing_count']);
        self::assertStringNotContainsString(
            'Kurz ECB',
            implode("\n", $preview['warnings']),
            'Ručně přepočtený řádek kurz období nepotřebuje, takže o něm nesmí varovat.',
        );
    }

    /** Doklad rovnou v měně podání se nepřepočítává a kurz nikdo nehledá. */
    public function testInvoiceAlreadyInReturnCurrencyNeedsNoRate(): void
    {
        $this->ossSale('2096-05-15', 4000.0, currencyId: $this->eurId);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(4000.0, $preview['summary']['total_base']);
        self::assertSame(0, $preview['summary']['conversion_missing_count']);
    }

    public function testImportedForeignInvoiceKeepsSourceCzkInCzkOssReturn(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET oss_return_currency = 'CZK' WHERE id = ?")->execute([$this->supplierId]);
        $invoiceId = $this->ossSale('2096-05-15', 100.0, currencyId: $this->eurId);
        $pdo->prepare('UPDATE invoices SET exchange_rate = 25.12 WHERE id = ?')->execute([$invoiceId]);
        $pdo->prepare('UPDATE invoice_items SET import_tax_base_czk = 2512.01, import_tax_vat_czk = 477.28 WHERE invoice_id = ?')
            ->execute([$invoiceId]);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);
        self::assertSame(2512.01, $preview['summary']['total_base']);
        self::assertSame(477.28, $preview['summary']['total_vat']);
        self::assertSame(0, $preview['summary']['conversion_missing_count']);
    }

    // ── opravy minulých období (VetaO) ───────────────────────────────────────

    /**
     * Jádro opravy VetaO: oprava jde proti kurzu OPRAVOVANÉHO kvartálu, ne běžného.
     *
     * Vrácených 100 000 Kč se za Q1 podalo kurzem 20 (5 000 € základu, 950 € daně).
     * Kurzem běžného Q2 (25) by oprava vyšla na −760 € a od podaných −950 € by se
     * nikdy neodečetla — 190 € by v podání zůstalo natrvalo.
     */
    public function testCorrectionUsesRateOfTheCorrectedPeriodNotOfTheCurrentOne(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);  // běžný kvartál — past
        $this->seedEcbDay('2096-03-31', 20.0);  // opravovaný kvartál
        $this->ossCorrection('2096-05-15', -100_000.0, '2096Q1');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(0, $preview['summary']['invalid_correction_count']);
        self::assertSame(-950.0, $preview['summary']['total_corrections'], 'Kurz Q1 (20), ne Q2 (25).');
        self::assertSame('2096-03-31', $preview['corrections'][0]['rate_date']);
        self::assertSame(-5000.0, $preview['corrections'][0]['rows'][0]['base_return']);

        $correctionRates = $preview['summary']['correction_rates'];
        self::assertCount(1, $correctionRates);
        self::assertSame('2096Q1', $correctionRates[0]['period']);
        self::assertSame('ecb', $correctionRates[0]['sources']['CZK']);
    }

    /**
     * Když archiv (evidence § 110f podání za opravované období) kurz NESE, vyhrává nad
     * dopočtem z tabulky ECB: oprava se musí vyrovnat proti tomu, co v podání JE.
     * 19 000 Kč × 0,02 = 380 € — ani kurz ECB Q1 (950 €), ani Q2 (760 €).
     */
    public function testArchivedRateOfTheCorrectedPeriodWinsOverRecomputedEcbRate(): void
    {
        if (!$this->db->hasTable('oss_filing_evidence')) {
            self::markTestSkipped('Chybí migrace 1300 (oss_filing_evidence).');
        }
        $this->seedEcbDay('2096-06-30', 25.0);
        $this->seedEcbDay('2096-03-31', 20.0);
        $this->seedArchivedEvidenceRate(2096, 1, 'CZK', 0.02, '2096-03-30');
        $this->ossCorrection('2096-05-15', -100_000.0, '2096Q1');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(-380.0, $preview['summary']['total_corrections']);
        self::assertSame('2096-03-30', $preview['corrections'][0]['rate_date']);
        self::assertSame('archive', $preview['summary']['correction_rates'][0]['sources']['CZK']);
    }

    /**
     * Bez kurzu opravovaného období se NEPŘEPOČÍTÁVÁ. Kurz běžného kvartálu je k dispozici
     * (a byl by to nejsnadnější tichý fallback), ale dal by opravu, která se od původního
     * podání nikdy neodečte — proto radši nula, varování a stopka pro XML.
     */
    public function testCorrectionWithoutRateForTheCorrectedPeriodIsNotConvertedAtAll(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);
        $this->ossCorrection('2096-05-15', -100_000.0, '2096Q1');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(0.0, $preview['summary']['total_corrections']);
        self::assertSame(1, $preview['summary']['invalid_correction_count']);
        self::assertSame([], $preview['corrections']);

        $warnings = implode("\n", $preview['warnings']);
        self::assertStringContainsString('Opravu období Q1 2096 nelze přepočíst', $warnings);
        self::assertStringContainsString('31. 3. 2096', $warnings, 'Hláška jmenuje konec OPRAVOVANÉHO období.');
    }

    /**
     * Kurz opravovaného období nesmí zasahovat do běžných plnění téhož přiznání. Doklad
     * z Q2 se dál počítá kurzem Q2, i když ve stejném přiznání leží oprava Q1.
     */
    public function testOrdinaryRowsKeepTheCurrentPeriodRateAlongsideCorrections(): void
    {
        $this->seedEcbDay('2096-06-30', 25.0);
        $this->seedEcbDay('2096-03-31', 20.0);
        $this->ossSale('2096-05-15', 100_000.0);
        $this->ossCorrection('2096-06-01', -100_000.0, '2096Q1');

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        self::assertSame(4000.0, $preview['summary']['total_base'], 'Běžné plnění kurzem Q2 (25).');
        self::assertSame(-950.0, $preview['summary']['total_corrections'], 'Oprava kurzem Q1 (20).');
        self::assertSame('2096-06-30', $preview['summary']['return_rate_date']);
    }

    /** Ruční kurz na položce přebíjí i kurz opravovaného období — je to volba účetního. */
    public function testManualRateStillWinsOnCorrectionRows(): void
    {
        $this->seedEcbDay('2096-03-31', 20.0);
        $invoiceId = $this->ossCorrection('2096-05-15', -100_000.0, '2096Q1');
        $this->db->pdo()->prepare('UPDATE invoice_items SET oss_exchange_rate = 0.1 WHERE invoice_id = ?')
            ->execute([$invoiceId]);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, 2);

        // −19 000 Kč × 0,1 = −1 900 €. Kurz ECB opravovaného období (0,05) by dal −950 €.
        self::assertSame(-1900.0, $preview['summary']['total_corrections']);
        self::assertSame('manual', $preview['corrections'][0]['rows'][0]['exchange_rate_source']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function seedEcbDay(string $date, float $czkPerEur): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ecb_exchange_rates (rate_date, currency_code, units_per_eur) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE units_per_eur = VALUES(units_per_eur)'
        )->execute([$date, 'CZK', $czkPerEur]);
        $this->markDay($date, published: true);
    }

    private function markDay(string $date, bool $published): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ecb_exchange_rate_days (rate_date, published) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE published = VALUES(published)'
        )->execute([$date, $published ? 1 : 0]);
    }

    /** Kurz ČNB je tu jen jako past: nová implementace ho pro OSS nesmí použít. */
    private function seedCnbDay(string $date, float $czkPerEur): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, 'EUR', $czkPerEur]);
    }

    /**
     * Evidence § 110f zapsaná k podání za opravované období — archivní zdroj kurzu.
     * Vkládá se přímo, ne přes `capture()`: testu jde o KURZ, ne o cestu, kterou se do
     * evidence dostal, a syntetický řádek se dá postavit s kurzem, který se neplete
     * s žádným kurzem ECB v tomhle testu.
     */
    private function seedArchivedEvidenceRate(int $year, int $quarter, string $currency, float $rate, string $rateDate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO oss_filing_evidence
                (supplier_id, submission_id, period_year, period_quarter, seq,
                 consumption_country, supply_description, supply_date,
                 taxable_amount, taxable_currency, taxable_amount_return, return_currency,
                 exchange_rate, exchange_rate_date, adjusted_period,
                 vat_rate, vat_amount, vat_amount_return,
                 invoice_snapshot_json, place_evidence_json, completeness_json, retain_until)
             VALUES (?, 999999, ?, ?, 1, "DE", "Plnění", ?, 1000, ?, 20, "EUR", ?, ?, NULL,
                     19.00, 190, 3.80, "{}", "{}", "{}", ?)'
        )->execute([
            $this->supplierId, $year, $quarter,
            sprintf('%04d-%02d-15', $year, ($quarter - 1) * 3 + 1),
            $currency, $rate, $rateDate,
            sprintf('%04d-12-31', $year + 10),
        ]);
    }

    /**
     * Dobropis opravující starší kvartál: záporné částky + `oss_original_period`, tedy
     * přesně to, z čeho v podání vzniká VetaO.
     */
    private function ossCorrection(string $taxDate, float $base, string $originalPeriod): int
    {
        $invoiceId = $this->ossSale($taxDate, $base, invoiceType: 'credit_note');
        $this->db->pdo()->prepare('UPDATE invoice_items SET oss_original_period = ? WHERE invoice_id = ?')
            ->execute([$originalPeriod, $invoiceId]);

        return $invoiceId;
    }

    private function ossSale(string $taxDate, float $base, ?int $currencyId = null, string $invoiceType = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT IGNORE INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)'
        )->execute([$taxDate, 'EUR', 30.0]);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Německo není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Mesto", "11000", ?, "c@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Spotřebitel DE', $countryId, $this->eurId]);
        $clientId = (int) $pdo->lastInsertId();

        $vat = round($base * 0.19, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, "issued", ?)'
        )->execute([
            $this->supplierId, $clientId, substr(md5($taxDate . $base . $clientId), 0, 10),
            $invoiceType, $taxDate, $taxDate, $taxDate, $currencyId ?? $this->czkId,
            $base, $vat, $base + $vat, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                 order_index, oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type)
             VALUES (?, "Plnění", 1, ?, ?, 19.00, ?, ?, ?, 1, 1, "DE", "standard", "services")'
        )->execute([$invoiceId, $base, $this->rateId, $base, $vat, $base + $vat]);

        return $invoiceId;
    }
}
