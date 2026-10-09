<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Fixtures\Premier\DbfWriter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanci a mzdy z PREMIER nad skutečnou DB: osoby a vztahy (jednatelka, skončená
 * DPP, pracovní poměr z dalšího roku), převzaté měsíce jako evidence bez účetních zápisů,
 * počáteční stavy kumulací, rekonciliace mezd proti deníku, opakovaný převod a zkouška
 * nanečisto. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierPayrollImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
    private PayrollRegistrationIdentityService $identities;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PremierImporter::class);
            $this->identities = $container->get(PayrollRegistrationIdentityService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        foreach (['premier_import_map', 'payroll_migration_reference_totals', 'payroll_employments', 'payroll_offices'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_pay_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testEmployeesAndMonthsWithoutJournalEntriesAndRerunIsIdempotent(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true]);

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertTrue($first->get('reconciliation')[0]['ok'], $this->explain($first));
        $counts = self::stepCounts($first, 'payroll');
        self::assertSame([2, 2, 16, 1], [$counts['employees_created'] ?? 0, $counts['employments_created'] ?? 0, $counts['months'] ?? 0, $counts['employees_later'] ?? 0],
            $this->explain($first));
        self::assertArrayNotHasKey('details_failed', $counts, $this->explain($first));
        self::assertArrayNotHasKey('deductions_not_converted', $counts, 'Bez srážek ve zdroji žádné upozornění.');
        self::assertArrayNotHasKey('absences_not_converted', $counts);
        self::assertArrayNotHasKey('failed', $counts, $this->explain($first));
        self::assertSame([2, 2, 2, 1], [$counts['tax_residence'] ?? 0, $counts['tax_declarations'] ?? 0, $counts['social_jurisdiction'] ?? 0, $counts['ended'] ?? 0]);

        self::assertSame([
            ['1', 'statutory_body', 'active', null, 'Jana Fiktivní'],
            ['2', 'dpp', 'ended', '2025-06-30', 'Petr Zkušební'],
        ], $this->fetch('SELECT e.code, e.relation_type, e.status, e.end_date, p.full_name FROM payroll_employments e
                           JOIN payroll_employees p ON p.id = e.employee_id WHERE e.supplier_id = ? ORDER BY e.code', $supplierId), $this->explain($first));
        self::assertSame([['S', '600000']], $this->fetch("SELECT t.activity_code, t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '1'", $supplierId));
        // Jednatelka je zdaněná srážkou: `NEZD_A` bez `POD_DAN` podepsané prohlášení není.
        self::assertSame([['not-signed']], $this->fetch("SELECT DISTINCT d.status FROM payroll_person_tax_declarations d
            JOIN payroll_employments e ON e.employee_id = d.employee_id AND e.supplier_id = d.supplier_id
            WHERE e.supplier_id = ? AND e.code = '1'", $supplierId), $this->explain($first));

        // Převzaté měsíce: evidence předchozího systému, žádné účetní zápisy.
        self::assertSame([['16', '8400000']], $this->fetch("SELECT COUNT(*), SUM(gross_minor) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND source = 'other'", $supplierId));
        self::assertSame([['4', '0', '0']], $this->fetch("SELECT COUNT(*), MAX(pension_participation), MAX(insurance_days) FROM payroll_migration_reference_totals
            WHERE supplier_id = ? AND relation_type = 'dpp'", $supplierId), 'DPP do 4 000 Kč nezakládá účast na pojištění.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = ?
            AND e.source_type NOT IN ('opening', 'closing') AND NOT EXISTS (SELECT 1 FROM premier_import_map m
                WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)", $supplierId),
            'Převod mezd nezaložil vlastní účetní zápis.');

        $payroll = $first->get('payroll_reconciliation');
        self::assertCount(12, $payroll[0]['months'], $this->explain($first));
        self::assertTrue($payroll[0]['ok'], $this->explain($first));
        self::assertContains('payroll_reconciled', $this->messageCodes($first));

        // Karta osoby: adresa, zákonná evidence a neověřený výplatní účet.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ?", $supplierId), $this->explain($first));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'payroll');
        self::assertSame([0, 2, 0, 16], [$counts['employees_created'] ?? 0, $counts['existing'] ?? 0, $counts['months'] ?? 0, $counts['months_existing'] ?? 0],
            $this->explain($again));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(16, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ?", $supplierId));

        // Další rok: nový zaměstnanec, změna odměny, měsíce od začátku vedení mezd se nepřebírají,
        // za leden (před začátkem 2/2026) vzniknou počáteční stavy kumulací.
        $next = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($next->hasErrors(), $this->explain($next));
        $counts = self::stepCounts($next, 'payroll');
        self::assertSame([1, 1, 2, 1, 1], [$counts['employees_created'] ?? 0, $counts['months'] ?? 0, $counts['months_after_start'] ?? 0,
            $counts['openings'] ?? 0, $counts['wage_changes'] ?? 0], $this->explain($next));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(17, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND period_start >= '2026-02-01'", $supplierId));
        self::assertSame([['600000'], ['650000']], $this->fetch("SELECT t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '1' ORDER BY t.effective_from", $supplierId));
        self::assertTrue($next->get('payroll_reconciliation')[0]['ok'], $this->explain($next));

        // Opakovaný převod roku 2026: počáteční stavy se shodnými čísly nevytvoří novou verzi.
        $repeat = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($repeat->hasErrors(), $this->explain($repeat));
        $counts = self::stepCounts($repeat, 'payroll');
        self::assertSame([0, 1, 0, 0], [$counts['openings'] ?? 0, $counts['openings_existing'] ?? 0, $counts['employees_created'] ?? 0, $counts['wage_changes'] ?? 0],
            $this->explain($repeat));
    }

    /**
     * Mzdové zápisy deníku dávají návrh kontací mezd stejnou cestou jako převod z PAMICA:
     * uloží se jen návrh, nastavení zaměstnavatele se nemění.
     */
    public function testPostingMapProposalFromPayrollJournal(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare('SELECT source, status, source_year, proposal_json FROM payroll_posting_map_proposals WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $stored = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $stored, $this->explain($protocol));
        self::assertSame(['other', 'draft', 2025], [$stored[0]['source'], $stored[0]['status'], (int) $stored[0]['source_year']]);
        $keys = [];
        foreach (json_decode((string) $stored[0]['proposal_json'], true)['keys'] as $key) {
            $keys[$key['key']] = [$key['status'], $key['suggested_code']];
        }
        $expected = [
            'employment_gross_debit' => ['unambiguous', '521.100'],
            'employment_gross_credit' => ['unambiguous', '331.100'],
            'social_insurance_credit' => ['unambiguous', '336.100'],
            'health_insurance_credit' => ['unambiguous', '336.200'],
            'employer_insurance_debit' => ['unambiguous', '524.100'],
            'withholding_tax_credit' => ['unambiguous', '342.200'],
            // Záloha na daň v roce 2025 nikdo neměl, v deníku pro ni nic není.
            'income_tax_credit' => ['missing', null],
        ];
        $actual = array_intersect_key($keys, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, $this->explain($protocol));
        self::assertSame([], json_decode((string) $stored[0]['proposal_json'], true)['unmapped']);
        self::assertContains('posting_map', $this->messageCodes($protocol));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_posting_map_proposals WHERE supplier_id = ? AND status = 'confirmed'", $supplierId));
    }

    /**
     * Srážky a vyloučené doby PREMIER nese jen jako částky a počty dnů za měsíc; převod
     * je nezakládá, ale musí to říct s osobními čísly, jinak by o nich mlčel.
     */
    public function testDeductionsAndExcludedDaysAreReportedAsNotConverted(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backupWithDeductions(), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $messages = [];
        foreach (self::step($protocol, 'payroll')['messages'] as $m) {
            $messages[$m['code']] = $m;
        }
        self::assertArrayHasKey('deductions_not_converted', $messages, $this->explain($protocol));
        self::assertSame('warning', $messages['deductions_not_converted']['level'] ?? null);
        self::assertStringContainsString('osobní čísla 1 (celkem 4 500,00 Kč)', $messages['deductions_not_converted']['text']);
        self::assertArrayHasKey('absences_not_converted', $messages, $this->explain($protocol));
        self::assertStringContainsString('1 (naposledy 2025-11)', $messages['absences_not_converted']['text']);
        $counts = self::stepCounts($protocol, 'payroll');
        self::assertSame([1, 1], [$counts['deductions_not_converted'] ?? 0, $counts['absences_not_converted'] ?? 0]);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_absences a JOIN payroll_employments e ON e.id = a.employment_id WHERE e.supplier_id = ?', $supplierId));
    }

    public function testDetailedPayrollImportPreservesPersonEvidence(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);

        $this->assertApprenticeIsReportedInsteadOfCreatedAsEmployment($supplierId, $protocol);
        $this->assertAgreedWageFromRateByWageType($supplierId, $protocol);
        $this->assertStatutoryEvidenceStartsAtMonthOfMidMonthStart($supplierId, $protocol);
        $this->assertHealthInsurerHistory($supplierId, $protocol);
        $this->assertForeignResidenceCountryFromName($supplierId, $protocol);
        $this->assertMailingAddressFromAdditionalAddresses($supplierId, $protocol);
        $this->assertReferenceTotalsDeductionsIncludeWageAdvance($supplierId, $protocol);
        $this->assertJmhzEvidenceIdentifiersAndChecklist($supplierId, $protocol);
        $this->assertPersonCardChildrenAccountsAndInstitutions($supplierId, $protocol);
    }

    /** Učeň nesmí vzniknout jako pracovní poměr; převod ho nezaloží a řekne to. */
    private function assertApprenticeIsReportedInsteadOfCreatedAsEmployment(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND code = '4'", $supplierId), $this->explain($protocol));
        self::assertContains('relation_apprentice', $this->messageCodes($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'payroll')['apprentices'] ?? 0);
    }

    /** Sjednaná mzda vztahu ze sazby podle typu mzdy, ne z `MZDA_MES`. */
    private function assertAgreedWageFromRateByWageType(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['2025-01-15', '3000000'], ['2025-07-01', '3200000']], $this->fetch("SELECT t.effective_from, t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '5' ORDER BY t.effective_from", $supplierId), $this->explain($protocol));
    }

    /**
     * Zákonná evidence má účinnost po celých měsících. Vztah s nástupem uprostřed měsíce
     * ji dřív nedostal vůbec: uložení celé evidence odmítlo den nástupu jako začátek řady.
     */
    private function assertStatutoryEvidenceStartsAtMonthOfMidMonthStart(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        foreach (['payroll_person_tax_residences', 'payroll_person_social_jurisdictions', 'payroll_person_tax_declarations'] as $table) {
            self::assertSame([['2025-01-01']], $this->fetch("SELECT MIN(x.effective_from) FROM {$table} x
                JOIN payroll_employments e ON e.employee_id = x.employee_id AND e.supplier_id = x.supplier_id WHERE e.supplier_id = ? AND e.code = '5'", $supplierId),
                $table . ' ' . $this->explain($protocol));
        }
    }

    /** Změna zdravotní pojišťovny v PREMIER se převede jako historie, ne jen poslední stav. */
    private function assertHealthInsurerHistory(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['111', '2025-01-01', '2025-06-30'], ['201', '2025-07-01', null]], $this->fetch("SELECT h.insurer_code, h.effective_from, h.effective_to
            FROM payroll_person_health_coverage_history h JOIN payroll_employments e ON e.employee_id = h.employee_id AND e.supplier_id = h.supplier_id
            WHERE e.supplier_id = ? AND e.code = '5' ORDER BY h.effective_from", $supplierId), $this->explain($protocol));
    }

    /** Adresa se státem zapsaným názvem (mimo české varianty) se dřív nezapsala vůbec. */
    private function assertForeignResidenceCountryFromName(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['residence', 'SK']], $this->fetch("SELECT a.address_type, a.country_code FROM payroll_person_addresses a
            JOIN payroll_employments e ON e.employee_id = a.employee_id AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '6'", $supplierId),
            $this->explain($protocol));
    }

    /** Korespondenční adresa z `PER_ADR` se doplní vedle trvalé. */
    private function assertMailingAddressFromAdditionalAddresses(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['residence', '60200', 'CZ'], ['mailing', '77900', 'CZ']], $this->fetch("SELECT a.address_type, a.postal_code, a.country_code FROM payroll_person_addresses a
            JOIN payroll_employments e ON e.employee_id = a.employee_id AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '5' ORDER BY a.address_type", $supplierId),
            $this->explain($protocol));
    }

    /** Převzaté měsíce nesou srážky ze složek mezd, ne jen ze sloupců `SR_*`. */
    private function assertReferenceTotalsDeductionsIncludeWageAdvance(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['2025-10-01', '100000'], ['2025-11-01', '300000'], ['2025-12-01', '100000']], $this->fetch("SELECT r.period_start, r.deductions_minor
            FROM payroll_migration_reference_totals r JOIN payroll_employments e ON e.id = r.employment_id
            WHERE r.supplier_id = ? AND e.code = '5' AND r.deductions_minor > 0 ORDER BY r.period_start", $supplierId), $this->explain($protocol));
    }

    /**
     * Evidence JMHZ z PREMIER: OIČ a ID PPV z formuláře, který přijala ČSSZ, pracoviště,
     * CZ-ISCO, týdenní doba a doklady k Zákonným termínům. OIČ bez přijatého formuláře
     * zůstává k ověření.
     */
    private function assertJmhzEvidenceIdentifiersAndChecklist(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, 'payroll');
        self::assertSame([1, 1, 1, 1, 1], [$counts['workplace'] ?? 0, $counts['cz_isco'] ?? 0, $counts['oic'] ?? 0, $counts['id_ppv'] ?? 0,
            $counts['identifiers_unconfirmed'] ?? 0], $this->explain($protocol));
        self::assertSame([['582786', 'CZ', 'Brno', '25120', '38.75']], $this->fetch("SELECT t.jmhz_workplace_municipality_code, t.jmhz_workplace_country_code, t.work_place,
            t.cz_isco_code, t.weekly_hours FROM payroll_employment_terms t JOIN payroll_employments e ON e.id = t.employment_id
            WHERE e.supplier_id = ? AND e.code = '5' ORDER BY t.effective_from DESC LIMIT 1", $supplierId));
        self::assertSame([['ik_mpsv', 'verified_manual_import']], $this->fetch("SELECT x.identifier_type, x.source_kind FROM payroll_person_external_ids x
            JOIN payroll_employments e ON e.employee_id = x.employee_id AND e.supplier_id = x.supplier_id WHERE e.supplier_id = ? AND e.code = '5'", $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_person_external_ids x
            JOIN payroll_employments e ON e.employee_id = x.employee_id AND e.supplier_id = x.supplier_id WHERE e.supplier_id = ? AND e.code = '6'", $supplierId),
            'OIČ jen z karty osoby bez přijatého formuláře se nepřevezme.');
        // Nástup i skončení před začátkem vedení mezd v MyÚčtu vyřídil PREMIER:
        // povinnosti se nezakládají vůbec, takže nic nečeká na odškrtnutí.
        self::assertSame([], $this->fetch("SELECT c.item_key FROM payroll_employment_checklist_items c JOIN payroll_employments e ON e.id = c.employment_id
            WHERE e.supplier_id = ? AND e.code IN ('5', '6') AND c.status = 'pending' AND c.item_key <> 'legacy_start_date'", $supplierId), $this->explain($protocol));
    }

    /**
     * Karta osoby z PREMIER: dítě s uplatněným zvýhodněním, sleva důchodce, výplatní účty
     * s historií a účty institucí z registru pojišťoven a nastavení mezd.
     */
    private function assertPersonCardChildrenAccountsAndInstitutions(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, 'payroll');
        self::assertArrayNotHasKey('details_failed', $counts, $this->explain($protocol));

        self::assertSame([['1', '2025-01-01', null]], $this->fetch("SELECT c.child_order, c.effective_from, c.effective_to FROM payroll_person_tax_child_claims c
            JOIN payroll_employments e ON e.employee_id = c.employee_id AND e.supplier_id = c.supplier_id WHERE e.supplier_id = ? AND e.code = '5'", $supplierId),
            $this->explain($protocol));
        self::assertSame(1, $counts['children_without_credit'] ?? 0);
        self::assertSame(1, $counts['children_other_caregiver'] ?? 0);

        self::assertSame([['1', '1'], ['0', '0']], $this->fetch("SELECT a.is_active, a.allocation_basis_points > 0 FROM payroll_person_accounts a
            JOIN payroll_employments e ON e.employee_id = a.employee_id AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '5' ORDER BY a.id", $supplierId),
            'Aktuální účet je výplatní, dřívější z historie zůstává bez výplat.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_person_accounts a JOIN payroll_employments e ON e.employee_id = a.employee_id
            AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '6'", $supplierId), 'Výplata v hotovosti účet nezakládá.');

        self::assertSame([['not_claimed']], $this->fetch("SELECT d.status FROM payroll_person_social_discount_claims d
            JOIN payroll_employments e ON e.employee_id = d.employee_id AND e.supplier_id = d.supplier_id WHERE e.supplier_id = ? AND e.code = '6'", $supplierId));
        self::assertSame(1, $counts['pensioners_without_discount'] ?? 0);

        self::assertSame([['health_insurer', '111', 'institution_notice'], ['health_insurer', '201', 'institution_notice'], ['social_security', 'P', 'imported'],
            ['tax_office', 'ADVANCE_TAX', 'imported'], ['tax_office', 'WITHHOLDING_TAX', 'imported']],
            $this->fetch('SELECT i.institution_type, i.institution_code, a.source_kind FROM payroll_institution_accounts a
                JOIN payroll_institutions i ON i.id = a.institution_id WHERE a.supplier_id = ? ORDER BY i.institution_type, i.institution_code', $supplierId),
            $this->explain($protocol));
        self::assertContains('institution_accounts_unconfirmed', $this->messageCodes($protocol));
    }

    /**
     * Časové evidence z PREMIER: nepřítomnosti s daty (neschopnost prodloužená do konce
     * případu eNeschopenky), průměry čtvrtletí a zůstatek dovolené v hodinách. Opakovaný
     * převod nic nezdvojí.
     */
    public function testAbsencesAveragesAndLeave(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $absences = "SELECT a.absence_type, a.date_from, a.date_to, a.status FROM payroll_absences a JOIN payroll_employments e ON e.id = a.employment_id
            WHERE e.supplier_id = ? AND e.code = '5' ORDER BY a.date_from";
        self::assertSame([['vacation', '2025-08-04', '2025-08-08', 'approved'], ['dpn', '2025-11-10', '2026-01-20', 'approved']],
            $this->fetch($absences, $supplierId), $this->explain($protocol));
        self::assertSame([['2025', '1', '18050'], ['2025', '2', '19025'], ['2025', '3', '19025'], ['2025', '4', '19025']],
            $this->fetch("SELECT s.applicable_year, s.applicable_quarter, s.average_hourly_minor FROM payroll_average_earning_snapshots s
                JOIN payroll_employments e ON e.id = s.employment_id WHERE e.supplier_id = ? AND e.code = '5' AND s.status = 'approved'
                ORDER BY s.applicable_year, s.applicable_quarter", $supplierId), $this->explain($protocol));
        self::assertSame([['2024-10-01', '2024-12-31']], $this->fetch("SELECT s.decisive_from, s.decisive_to FROM payroll_average_earning_snapshots s
            JOIN payroll_employments e ON e.id = s.employment_id WHERE e.supplier_id = ? AND e.code = '5' AND s.applicable_quarter = 1", $supplierId),
            'Pravděpodobný výdělek bez rozhodného období ve zdroji dostane předchozí čtvrtletí.');
        self::assertSame([['2025', 'carryover', '9300']], $this->fetch("SELECT l.leave_year, l.entry_type, l.minutes_delta FROM payroll_leave_ledger l
            JOIN payroll_employments e ON e.id = l.employment_id WHERE e.supplier_id = ? AND e.code = '5' AND l.entry_type = 'carryover'", $supplierId));
        self::assertContains('leave_carryover', $this->messageCodes($protocol));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertCount(2, $this->fetch($absences, $supplierId), 'Opakovaný převod nepřítomnosti nezdvojí.');
    }

    /**
     * Neschopnost přes konec roku: převod po letech ji zapíše jako dvě nepřítomnosti
     * (do 31. 12. a od 1. 1.). Druhá musí navázat na okno náhrady mzdy první, jinak by
     * MyÚčto od 1. 1. vyplatilo čtrnáct dnů náhrady znovu.
     */
    public function testSicknessAcrossYearEndContinuesCompensationWindow(): void
    {
        $flags = ['payroll' => true, 'payroll_detail' => true];
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_sickness_year_end';
        SyntheticPremierBackup::writeDir($dir, false, $flags);
        $tables = SyntheticPremierBackup::tables(false, $flags);
        // Bez případu eNeschopenky: rok 2025 skončí neschopnost 31. 12., leden nese rok 2026.
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'MZ_HDPN.DBF', $tables['MZ_HDPN'][0], []);
        [$fields, $rows] = $tables['DNY'];
        $rows[] = ['INTER' => 5, 'DATUM_OD' => '2026-01-01', 'DATUM_DO' => '2026-01-20', 'KOD' => '600', 'CASTKA' => 0, 'N_DNY' => 20,
            'DNY_ROK' => 2026, 'DNY_MES' => 1, 'TYP' => 3, 'ID' => 'D5-SY-2026-1'];
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'DNY.DBF', $fields, $rows);
        $backup = PremierBackup::open($dir);

        $supplierId = $this->supplier(true);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $next = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($next->hasErrors(), $this->explain($next));

        self::assertSame([['2025-11-10', '2025-12-31', '0'], ['2026-01-01', '2026-01-20', '14']],
            $this->fetch("SELECT a.date_from, a.date_to, a.sickness_window_carried_days FROM payroll_absences a JOIN payroll_employments e ON e.id = a.employment_id
                WHERE e.supplier_id = ? AND e.code = '5' AND a.absence_type = 'dpn' ORDER BY a.date_from", $supplierId), $this->explain($next));
        self::assertSame(1, self::stepCounts($next, 'payroll')['sickness_window_continued'] ?? 0);
    }

    /**
     * Příznak jednatele proti druhu činnosti z hlášení JMHZ přijatého ČSSZ: vztah vznikne
     * podle hlášení a protokol řekne, jaký druh zvolil, podle čeho a proč.
     */
    public function testStatutoryFlagAgainstAcceptedJmhzExplainsChosenType(): void
    {
        $flags = ['payroll' => true, 'payroll_detail' => true];
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_statutory_flag';
        SyntheticPremierBackup::writeDir($dir, false, $flags);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, $flags)['PERSONAL'];
        foreach ($rows as $i => $row) {
            if ($row['INTER'] === 5) {
                $rows[$i]['JEDNATEL'] = true;
            }
        }
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'PERSONAL.DBF', $fields, $rows);

        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, PremierBackup::open($dir), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['employment']], $this->fetch("SELECT relation_type FROM payroll_employments WHERE supplier_id = ? AND code = '5'", $supplierId));
        $messages = array_values(array_filter(self::step($protocol, 'payroll')['messages'], static fn (array $m): bool => $m['code'] === 'relation_type_statutory_flag'));
        self::assertCount(1, $messages, $this->explain($protocol));
        self::assertStringContainsString('vykazuje druh činnosti 1 (pracovní poměr). Vztah je založený jako pracovní poměr', $messages[0]['text']);
        self::assertStringContainsString('podle něj vztah eviduje ČSSZ', $messages[0]['text']);
        self::assertSame(['employment', '1'], [$messages[0]['context']['relation_type'] ?? null, $messages[0]['context']['jmhz_activity'] ?? null]);
    }

    /**
     * Osoba bez evidované identity je nekonzistence, ne důvod údaj mlčky přeskočit: převod
     * z PREMIER ji ohlásí stejně jako převod z PAMICA a zbytek osoby převede.
     */
    public function testMissingIdentityIsReportedNotSkippedSilently(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true]);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $this->db->pdo()->prepare("DELETE h FROM payroll_person_identity_history h JOIN payroll_employments e ON e.employee_id = h.employee_id AND e.supplier_id = h.supplier_id
            WHERE e.supplier_id = ? AND e.code = '5'")->execute([$supplierId]);

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $failed = array_values(array_filter(self::step($again, 'payroll')['messages'],
            static fn (array $m): bool => $m['code'] === 'detail_failed' && str_contains($m['text'], 'nemá evidovanou identitu')));
        self::assertCount(1, $failed, $this->explain($again));
        self::assertStringStartsWith('Osobní číslo 5: Údaje o narození a občanství se nepřevzal', $failed[0]['text']);
    }

    /**
     * Dovolená rozepsaná po měsících se spojí v jednu nepřítomnost, která přejde přes konec
     * čtvrtletí. Evidence takovou náhradu odmítne (průměr se zjišťuje ke čtvrtletí), takže
     * se dřív tiše nezapsala vůbec. Rozdělí se na hranici čtvrtletí a obě části se schválí.
     */
    public function testAbsenceAcrossQuarterEndIsSplitAtQuarterBoundary(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backupWithQuarterVacation(), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['vacation', '2025-09-29', '2025-09-30', 'approved'], ['vacation', '2025-10-01', '2025-10-03', 'approved']],
            $this->fetch("SELECT a.absence_type, a.date_from, a.date_to, a.status FROM payroll_absences a JOIN payroll_employments e ON e.id = a.employment_id
                WHERE e.supplier_id = ? AND e.code = '5' AND a.absence_type = 'vacation' AND a.date_from >= '2025-09-01' ORDER BY a.date_from", $supplierId),
            $this->explain($protocol));
        self::assertArrayNotHasKey('absences_rejected', self::stepCounts($protocol, 'payroll'), $this->explain($protocol));
    }

    /**
     * Nepřítomnost, kterou evidence odmítne (peněžitá pomoc v mateřství bez dne porodu),
     * se dřív vynechala bez počtu i hlášky. Protokol ji musí spočítat a říct, u koho.
     */
    public function testRejectedAbsenceIsCountedAndReported(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backupWithQuarterVacation([[2, '2025-02-03', '2025-02-07', '603']]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'payroll')['absences_rejected'] ?? 0, $this->explain($protocol));
        $messages = array_values(array_filter(self::step($protocol, 'payroll')['messages'], static fn (array $m): bool => $m['code'] === 'absences_rejected'));
        self::assertCount(1, $messages, $this->explain($protocol));
        self::assertSame('warning', $messages[0]['level']);
        self::assertStringContainsString(': 1 u osobních čísel 5.', $messages[0]['text']);
    }

    /**
     * Trvalé srážky z PREMIER: exekuce jako nedoložený exekuční případ se zbývající
     * pohledávkou, odbory jako dohoda o srážkách, skončené spoření jen v počtu. Zakládají se
     * až v běhu roku posledních zpracovaných mezd.
     */
    public function testDeductionsFromStandingDeductionCards(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true]);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertArrayNotHasKey('deductions_not_converted', self::stepCounts($first, 'payroll'), 'Trvalé srážky ze zálohy převod zakládá, nehlásí je jako nepřevedené.');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_enforcement_cases WHERE supplier_id = ?', $supplierId), 'Rok 2025 ještě není konec zpracovaných mezd.');

        $next = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($next->hasErrors(), $this->explain($next));
        $counts = self::stepCounts($next, 'payroll');
        self::assertSame([1, 1, 1, 1], [$counts['enforcement_cases'] ?? 0, $counts['deduction_agreements'] ?? 0, $counts['deductions_ended'] ?? 0,
            $counts['recipient_accounts'] ?? 0], $this->explain($next));
        self::assertSame([['non_priority', '4700000']], $this->fetch('SELECT c.category, c.outstanding_minor_units FROM payroll_enforcement_claims c
            JOIN payroll_enforcement_cases k ON k.id = c.case_id WHERE k.supplier_id = ?', $supplierId));
        self::assertSame([['contribution', '15000', 'active']], $this->fetch('SELECT deduction_kind, requested_minor, status FROM payroll_deduction_agreements
            WHERE supplier_id = ?', $supplierId));
        self::assertContains('deductions_evidence_pending', $this->messageCodes($next));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(2, self::stepCounts($again, 'payroll')['deductions_existing'] ?? 0, 'Opakovaný převod srážky nezdvojí.');
    }

    /**
     * Osobní ohodnocení z karty vztahu (`MZ_SRAZ` 303) je opakovaná složka pravidelné odměny
     * pro měsíce počítané MyÚčtem; opakovaný převod předpis nezdvojí. Nerezident dostane stát
     * rezidence z karty `PER_NERZ` a protokol ho nehlásí k ručnímu doplnění.
     */
    public function testRecurringIncomeAndNonResidentCountry(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true, 'payroll_nonresident' => true]);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(1, self::stepCounts($first, 'payroll')['recurring_components'] ?? 0, $this->explain($first));
        self::assertSame([['PREMIER_303', 'bonus', 'regular', '200000', '2025-01-15', null, 'hours']], $this->fetch('SELECT d.code, d.component_kind, d.frequency_kind,
                r.amount_minor, r.valid_from, r.valid_to, r.allocation_rule
              FROM payroll_recurring_components r
              JOIN payroll_component_definitions d ON d.supplier_id = r.supplier_id AND d.id = r.component_id
              JOIN payroll_employments e ON e.supplier_id = r.supplier_id AND e.id = r.employment_id
             WHERE r.supplier_id = ? AND e.code = \'5\' AND d.code LIKE \'PREMIER\_%\'', $supplierId));
        self::assertSame([['non-resident', 'SK']], $this->fetch('SELECT t.residence, t.country_code FROM payroll_person_tax_residences t
              JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.employee_id = t.employee_id
             WHERE t.supplier_id = ? AND e.code = \'6\'', $supplierId));
        self::assertNotContains('tax_residence_manual', $this->messageCodes($first));
        // Zahraniční DIČ na kartu osoby, doklad totožnosti do profilu registrace A1 vztahu.
        self::assertSame(1, self::stepCounts($first, 'payroll')['person_identifiers'] ?? 0, $this->explain($first));
        self::assertSame(1, self::stepCounts($first, 'payroll')['proof_identity'] ?? 0, $this->explain($first));
        self::assertSame([['foreign_tax_identifier']], $this->fetch('SELECT i.identifier_type FROM payroll_person_identifiers i
              JOIN payroll_employments e ON e.supplier_id = i.supplier_id AND e.employee_id = i.employee_id
             WHERE i.supplier_id = ? AND e.code = \'6\' AND i.identifier_type = \'foreign_tax_identifier\'', $supplierId));
        $employmentId = (int) $this->fetch('SELECT id FROM payroll_employments WHERE supplier_id = ? AND code = \'6\'', $supplierId)[0][0];
        $a1 = $this->identities->a1ProfileView($supplierId, $employmentId);
        self::assertSame(['P', 'XX0000001', 'SK'], [$a1['profile']['proof_identity']['type_code'] ?? null,
            $a1['profile']['proof_identity']['number'] ?? null, $a1['profile']['proof_identity']['country_code'] ?? null]);

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(1, self::stepCounts($again, 'payroll')['recurring_components_existing'] ?? 0, 'Opakovaný převod předpis nezdvojí.');
        self::assertArrayNotHasKey('proof_identity', self::stepCounts($again, 'payroll'), 'Opakovaný převod doklad nepřepíše.');
        self::assertArrayNotHasKey('person_identifiers', self::stepCounts($again, 'payroll'));
    }

    /**
     * Trvalé příjmy karty vztahu (`MZ_SRAZ` 422, 712, 862): penzijní připojištění na výchozí
     * složku koše § 6 odst. 9 písm. p), stravenkový paušál jako předpis k ručnímu určení
     * částky a příspěvek na praní jako vlastní složka mimo daň, pojistné i JMHZ. Převzaté
     * měsíce nesou příspěvek na penzijní připojištění do čerpání koše.
     */
    public function testRecurringBenefitsAndTakenOverOldAgeSavings(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true, 'payroll_benefits' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(4, self::stepCounts($protocol, 'payroll')['recurring_components'] ?? 0, $this->explain($protocol));
        self::assertSame([
            ['PREMIER_303', 'fixed_amount', '200000', 'hours'],
            ['PREMIER_862', 'fixed_amount', '50000', 'calendar_days'],
            ['PRISPEVEK_PENZE_ZIVOTNI', 'fixed_amount', '100000', 'calendar_days'],
            ['PRISPEVEK_STRAVOVANI', 'manual_review', null, 'calendar_days'],
        ], $this->fetch('SELECT d.code, r.calculation_kind, r.amount_minor, r.allocation_rule
              FROM payroll_recurring_components r
              JOIN payroll_component_definitions d ON d.supplier_id = r.supplier_id AND d.id = r.component_id
              JOIN payroll_employments e ON e.supplier_id = r.supplier_id AND e.id = r.employment_id
             WHERE r.supplier_id = ? AND e.code = \'5\' ORDER BY d.code', $supplierId));
        self::assertSame([['other', 'regular', 'exempt', 'not_subject_to_tax', 'excluded', 'excluded', 'excluded']], $this->fetch('SELECT component_kind,
                frequency_kind, tax_treatment, exemption_basis, social_treatment, health_treatment, jmhz_treatment
              FROM payroll_component_definitions WHERE supplier_id = ? AND code = \'PREMIER_862\'', $supplierId));
        $taken = $this->fetch('SELECT COUNT(*), SUM(t.old_age_savings_contribution_minor) FROM payroll_migration_reference_totals t
              JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
             WHERE t.supplier_id = ? AND e.code = \'5\' AND t.old_age_savings_contribution_minor > 0', $supplierId);
        self::assertGreaterThan(0, (int) $taken[0][0]);
        self::assertSame((int) $taken[0][0] * 100000, (int) $taken[0][1]);
    }

    public function testLedgerMismatchIsAWarningNotAnError(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_mismatch' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('warning', self::step($protocol, 'payroll')['status'] ?? null);
        $messages = array_values(array_filter(self::step($protocol, 'payroll')['messages'], static fn (array $m): bool => $m['code'] === 'payroll_ledger_diff'));
        self::assertCount(1, $messages, $this->explain($protocol));
        self::assertSame('2025-05', $messages[0]['context']['period'] ?? null);
        self::assertFalse($protocol->get('payroll_reconciliation')[0]['ok']);
        self::assertSame(1, self::stepCounts($protocol, 'payroll')['reconciliation_diffs'] ?? 0);
    }

    /**
     * Firma bez mezd a bez mzdové účtárny: převod modul zapne, založí nastavení
     * zaměstnavatele s účtárnou, nastaví začátek vedení mezd za posledním měsícem mezd
     * v záloze (2/2026 → 3/2026) a zaměstnance převezme. Údaje z rozhodnutí úřadů
     * nevymýšlí, jen je vypíše k doplnění.
     */
    public function testWithoutPayrollModuleTheImportEnablesItAndTakesPayrollOver(): void
    {
        $supplierId = $this->supplier(false);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('payroll_module_will_enable', array_column($protocol->get('preflight'), 'code'));
        self::assertNotContains('payroll_module_missing', $this->messageCodes($protocol), $this->explain($protocol));
        self::assertContains('payroll_module_enabled', $this->messageCodes($protocol));
        self::assertContains('payroll_setup_incomplete', $this->messageCodes($protocol));

        self::assertSame(1, $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', $supplierId));
        self::assertSame([['setup', '2026-03-01']], $this->fetch('SELECT status, start_period FROM payroll_module_state WHERE supplier_id = ?', $supplierId));
        self::assertSame([['MZDY', null, '1']], $this->fetch('SELECT o.code, o.social_security_variable_symbol, o.is_active FROM payroll_employer_settings s
            JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id WHERE s.supplier_id = ?', $supplierId));

        $counts = self::stepCounts($protocol, 'payroll');
        self::assertSame([2, 16], [$counts['employees_created'] ?? 0, $counts['months'] ?? 0], $this->explain($protocol));
        self::assertArrayNotHasKey('months_skipped', $counts);
        self::assertTrue($protocol->get('payroll_reconciliation')[0]['ok'], $this->explain($protocol));

        // Opakovaný převod už nic nezapíná ani nepřepisuje.
        $again = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertNotContains('payroll_module_enabled', $this->messageCodes($again));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ?', $supplierId));
    }

    /** Zapnutý modul s vlastním začátkem vedení mezd převod nechá, jak je. */
    public function testExistingPayrollSetupIsNeverOverwritten(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNotContains('payroll_module_enabled', $this->messageCodes($protocol));
        self::assertSame([['setup', '2026-02-01']], $this->fetch('SELECT status, start_period FROM payroll_module_state WHERE supplier_id = ?', $supplierId));
        self::assertSame([['PRM']], $this->fetch('SELECT code FROM payroll_offices WHERE supplier_id = ?', $supplierId));
    }

    public function testDryRunWritesNothing(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'payroll')['employees_created'] ?? 0, $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind LIKE 'payroll%'", $supplierId));
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . md5((string) json_encode($flags));
        if (!is_dir($dir)) {
            SyntheticPremierBackup::writeDir($dir, false, $flags);
        }
        return PremierBackup::open($dir);
    }

    /**
     * Záloha s mzdami, kde jednatelka má v 9-11/2025 srážku 1 500 Kč (`SR_VYZI`) a v 11/2025
     * 5 kalendářních dnů nemoci (`DNY_NEKA`; `VYL_DND` je v zálohách PREMIER vždy prázdný).
     * Syntetická data jen tohoto testu.
     */
    private function backupWithDeductions(): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_deductions';
        SyntheticPremierBackup::writeDir($dir, false, ['payroll' => true]);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, ['payroll' => true])['MZDY'];
        $fields[] = ['SR_VYZI', 'N', 12, 2];
        foreach ($rows as $i => $row) {
            if ($row['INTER'] === 1 && $row['ROK'] === 2025 && $row['MESIC'] >= 9 && $row['MESIC'] <= 11) {
                $rows[$i]['SR_VYZI'] = 1500;
            }
            if ($row['INTER'] === 1 && $row['ROK'] === 2025 && $row['MESIC'] === 11) {
                $rows[$i]['DNY_NEKA'] = 5;
                $rows[$i]['VYL_DND'] = 0;
            }
        }
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'MZDY.DBF', $fields, $rows);
        return PremierBackup::open($dir);
    }

    /**
     * Záloha s podrobnými mzdami, kde vztah INTER 5 čerpá dovolenou 29. 9. - 3. 10. 2025,
     * v `DNY` rozepsanou po měsících (září a říjen), a volitelně další nepřítomnosti
     * (měsíc, od, do, kód). Syntetická data jen těchto testů.
     *
     * @param list<array{0:int,1:string,2:string,3:string}> $extra
     */
    private function backupWithQuarterVacation(array $extra = []): PremierBackup
    {
        $flags = ['payroll' => true, 'payroll_detail' => true];
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_quarter_vacation_' . md5((string) json_encode($extra));
        SyntheticPremierBackup::writeDir($dir, false, $flags);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, $flags)['DNY'];
        foreach ([[9, '2025-09-29', '2025-09-30', '500'], [10, '2025-10-01', '2025-10-03', '500'], ...$extra] as [$month, $from, $to, $code]) {
            $rows[] = ['INTER' => 5, 'DATUM_OD' => $from, 'DATUM_DO' => $to, 'KOD' => $code, 'CASTKA' => 2000, 'DNY_ROK' => 2025, 'DNY_MES' => $month,
                'TYP' => 2, 'ID' => "D5-QV-{$month}-{$code}"];
        }
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'DNY.DBF', $fields, $rows);
        return PremierBackup::open($dir);
    }

    /** Izolovaná firma; `$payroll` = zapnuté mzdy, výchozí účtárna a začátek vedení mezd 2/2026. */
    private function supplier(bool $payroll): int
    {
        $pdo = $this->db->pdo();
        $ico = SyntheticPremierBackup::ICO;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([SyntheticPremierBackup::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        if (!$payroll) {
            return $id;
        }
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$id]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-02-01", ?, NOW())',
        )->execute([$id, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "PRM", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$id]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2025-01-01", "1234567890", "synthetic:premier-payroll")',
        )->execute([$id, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$id, $officeId]);
        return $id;
    }

    private function scalar(string $sql, int ...$params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<list<mixed>> */
    private function fetch(string $sql, int ...$params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $row), $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @return array<string,mixed> */
    private static function step(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        return [];
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        return self::step($protocol, $key)['counts'] ?? [];
    }

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        return $codes;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
