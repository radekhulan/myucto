<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAnnualEvidenceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Roční zúčtování nad skutečnou databází.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se tu nevolá `settle()`
 * ─────────────────────────────────────────────────────────────────────────────
 * `settle()` si transakci OTEVÍRÁ SÁM (a musí — zápis dokladu, rejstříku
 * i souboru je jeden celek). Test by proto nemohl běžet ve vlastní transakci
 * a musel by po sobě uklízet; jenže roční revize i mzdové kumulace mají
 * `BEFORE DELETE` triggery, které mazání zakazují, takže by po každém běhu
 * zůstávaly v testovací DB neodstranitelné řádky.
 *
 * Testují se proto ty tři části, na kterých `settle()` stojí, a každá právě
 * tam, kde se může pokazit:
 *   - `preview()` — posouzení podmínek a výpočet nad reálnou evidencí,
 *   - `AnnualSettlementSnapshotBuilder` — zmrazení do neměnné roční revize,
 *   - `insertOutcome()` — jediný výsledek na rok, i při druhém spuštění.
 */
#[Group('integration')]
final class AnnualSettlementIntegrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    /** Hrubá mzda 42 137,00 Kč měsíčně — záměrně nekulatá. */
    private const MONTHLY_GROSS_MINOR = 4_213_700;

    /**
     * Zaměstnanec s jedním zaměstnavatelem a podepsaným prohlášením: přeplatek
     * vyjde, doklad vznikne, druhé spuštění nevytvoří druhý výsledek, a měsíční
     * data zůstanou nedotčená.
     */
    public function testCompleteYearProducesOneSettlementAndLeavesMonthsUntouched(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $snapshots = $container->get(AnnualSettlementSnapshotBuilder::class);
        $settlements = $container->get(PayrollAnnualSettlementRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $jmhzAnnualEvidence = $container->get(JmhzAnnualEvidenceService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $snapshots);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        self::assertInstanceOf(
            PayrollStatutoryAccumulatorRepository::class,
            $accumulators,
        );
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(JmhzAnnualEvidenceService::class, $jmhzAnnualEvidence);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId, $advanceTaxTotal] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );
            $monthsFingerprint = $this->monthsFingerprint($pdo, $supplierId, $employeeId);

            // Roční kumulace vidí VŠECH dvanáct měsíců včetně prosince —
            // běhové čtení jich smí mít nejvýš jedenáct.
            $state = $accumulators->stateForYear(
                $supplierId,
                $employeeId,
                self::YEAR,
                'income_tax',
            );
            self::assertSame('whole_year', $state['scope']);
            self::assertSame(12, $state['totals']['completed_months']);
            self::assertSame(
                self::MONTHLY_GROSS_MINOR * 12,
                $state['totals']['advance_base_minor_units'],
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );
            $result = $preview['result'];

            self::assertSame([], $result->blockerCodes());
            self::assertTrue($result->performed);
            self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);

            // Přeplatek na haléř: úhrn sražených záloh minus roční daň po slevách.
            self::assertSame(
                $advanceTaxTotal - $result->taxAfterAllCreditsMinorUnits,
                $result->taxDifferenceMinorUnits,
            );
            self::assertSame(
                $result->taxDifferenceMinorUnits,
                $result->settlementDifferenceMinorUnits,
            );
            self::assertSame(
                $result->settlementDifferenceMinorUnits,
                $result->payableMinorUnits,
            );
            self::assertCount(1, $preview['credit_rows']);

            // Zmrazení do neměnné roční revize.
            $prepared = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $result,
                sprintf('%04d-03-10', self::YEAR + 1),
                $preview['credit_rows'],
                $preview['child_rows'],
                null,
            );
            self::assertTrue($prepared['created']);
            self::assertSame(
                AnnualSettlementSnapshotBuilder::PURPOSE,
                $prepared['revision']['purpose'],
            );
            $revisionId = (int) $prepared['revision']['id'];

            // Druhé sestavení nad týmiž měsíci a týmž výsledkem vrátí tutéž
            // revizi — manifest zdrojů je shodný, takže není co zakládat.
            $again = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $result,
                sprintf('%04d-03-10', self::YEAR + 1),
                $preview['credit_rows'],
                $preview['child_rows'],
                null,
            );
            self::assertFalse($again['created']);
            self::assertSame($revisionId, (int) $again['revision']['id']);

            $stored = $settlements->insertOutcome(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $result->outcome->value,
                    'tax_difference_minor' => $result->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' =>
                        $result->settlementDifferenceMinorUnits,
                    'payable_minor' => $result->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => sprintf('%04d-03-10', self::YEAR + 1),
                ],
                null,
            );
            self::assertTrue($stored['created']);

            $frozenAnnual = $jmhzAnnualEvidence->snapshotsForPreparation(
                $supplierId,
                [$employeeId],
                self::YEAR + 1,
            )[$employeeId];
            self::assertSame('requested', $frozenAnnual['request']['status']);
            self::assertSame(
                'verified_request_row_under_unique_key_lock',
                $frozenAnnual['request_evidence']['proof'],
            );
            self::assertTrue($frozenAnnual['settlement_evidence']['performed']);
            self::assertSame(
                'verified_annual_outcome_and_document_revision',
                $frozenAnnual['settlement_evidence']['proof'],
            );
            self::assertSame($revisionId, $frozenAnnual['settlement']['revision_id']);
            self::assertTrue($frozenAnnual['settlement']['performed']);
            self::assertSame(
                $result->settlementDifferenceMinorUnits,
                $frozenAnnual['settlement']['settlement_difference_minor_units'],
            );
            self::assertSame($preview['child_rows'], $frozenAnnual['settlement']['child_rows']);
            self::assertNull($frozenAnnual['withholding_certificate']);

            // „Opakované spuštění nevytvoří druhý výsledek" — druhý zápis narazí
            // na unikátní klíč a vrátí ten původní řádek.
            $repeat = $settlements->insertOutcome(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $result->outcome->value,
                    'tax_difference_minor' => $result->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' =>
                        $result->settlementDifferenceMinorUnits,
                    'payable_minor' => $result->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => sprintf('%04d-03-11', self::YEAR + 1),
                ],
                null,
            );
            self::assertFalse($repeat['created']);
            self::assertSame(
                $stored['outcome']['id'],
                $repeat['outcome']['id'],
            );
            self::assertSame(
                sprintf('%04d-03-10', self::YEAR + 1),
                $repeat['outcome']['settled_on'],
            );
            self::assertSame(
                1,
                (int) $pdo->query(sprintf(
                    'SELECT COUNT(*) FROM payroll_annual_settlement_outcomes
                      WHERE supplier_id = %d AND employee_id = %d',
                    $supplierId,
                    $employeeId,
                ))->fetchColumn(),
            );

            // Roční zúčtování je JEN součet — historické měsíce se nesmí hnout.
            self::assertSame(
                $monthsFingerprint,
                $this->monthsFingerprint($pdo, $supplierId, $employeeId),
            );

            // A hotové zúčtování zablokuje další pokus.
            $second = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-11', self::YEAR + 1)),
            );
            self::assertFalse($second['result']->performed);
            self::assertContains(
                AnnualSettlementBlocker::AlreadySettled->value,
                $second['result']->blockerCodes(),
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Potvrzení od jiného plátce projde celou cestou: uloží se, vrátí se
     * z náhledu, neúplné zúčtování zastaví a úplné se přičte do úhrnu.
     *
     * Nejdůležitější je prostřední krok. Neúplné potvrzení se NESMÍ tiše
     * dopočítat nulou — z toho by vyšel přeplatek, který poplatníkovi
     * nenáleží (§ 38ch odst. 3 ve spojení s § 35d odst. 7).
     */
    public function testExternalCertificateRoundTripsAndOnlyCountsWhenComplete(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $snapshots = $container->get(AnnualSettlementSnapshotBuilder::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $snapshots);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_certificates')) {
            $this->markTestSkipped('Migrace potvrzení od jiného plátce neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $today = new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1));

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );

            $baseline = $service->preview($supplierId, $employeeId, self::YEAR, $today);
            self::assertTrue($baseline['result']->performed);
            self::assertSame([], $baseline['certificates']);

            // Krok 1 — potvrzení bez vyplacených bonusů a bez slev podle § 35c.
            // Dvě ze čtyř složek § 38ch odst. 3 chybí.
            $saved = $service->saveCertificates(
                $supplierId,
                $employeeId,
                self::YEAR,
                [[
                    'certificate_reference' => 'POT-2026-1',
                    'payer_name' => 'Předchozí plátce',
                    'received_on' => sprintf('%04d-02-10', self::YEAR + 1),
                    'gross_income_minor_units' => 30_000_00,
                    'advance_base_minor_units' => 30_000_00,
                    'advance_tax_minor_units' => 4_500_00,
                    'non_refundable_credit_minor_units' => 2_570_00,
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'synthetic-certificate-evidence',
                ]],
                null,
            );
            self::assertCount(1, $saved);
            self::assertSame(
                ['credit_35c', 'tax_bonus'],
                $saved[0]['missing_statutory_fields'],
            );

            $incomplete = $service->preview($supplierId, $employeeId, self::YEAR, $today);
            self::assertFalse($incomplete['result']->performed);
            self::assertContains(
                AnnualSettlementBlocker::ExternalCertificateIncomplete->value,
                $incomplete['result']->blockerCodes(),
            );
            // Nic se nedopočítalo — ani „aspoň z vlastních měsíců".
            self::assertSame(0, $incomplete['result']->payableMinorUnits);
            self::assertCount(1, $incomplete['certificates']);

            // Krok 2 — doplněné obě chybějící složky. Nula je platný údaj.
            $service->saveCertificates(
                $supplierId,
                $employeeId,
                self::YEAR,
                [[
                    'certificate_reference' => 'POT-2026-1',
                    'payer_name' => 'Předchozí plátce',
                    'received_on' => sprintf('%04d-02-10', self::YEAR + 1),
                    'gross_income_minor_units' => 30_000_00,
                    'advance_base_minor_units' => 30_000_00,
                    'advance_tax_minor_units' => 4_500_00,
                    'non_refundable_credit_minor_units' => 2_570_00,
                    'child_credit_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'synthetic-certificate-evidence',
                ]],
                null,
            );

            $complete = $service->preview($supplierId, $employeeId, self::YEAR, $today);
            self::assertSame([], $complete['result']->blockerCodes());
            self::assertTrue($complete['result']->performed);
            self::assertSame([], $complete['certificates'][0]['missing_statutory_fields']);

            // Úhrn se skutečně zvětšil o potvrzení (§ 38ch odst. 4) — základ
            // vzrostl a nová záloha se promítla do rozdílu na dani.
            self::assertGreaterThan(
                $baseline['result']->roundedTaxBaseMinorUnits,
                $complete['result']->roundedTaxBaseMinorUnits,
            );
            self::assertSame(
                30_000_00,
                $complete['result']->trace['external_certificates']['advance_base_minor_units'],
            );
            self::assertSame(
                4_500_00,
                $complete['result']->trace['external_certificates']['advance_tax_minor_units'],
            );

            // Doklad musí ukázat, z čeho se úhrn skládá — jinak by z něj nešlo
            // poznat, že část základu a záloh je od jiného zaměstnavatele.
            // A hlavně: rozdíl na dani musí sedět na ÚHRN záloh, ne jen na ty
            // vlastní. To si `AnnualSettlementDocumentData` vynucuje samo.
            $prepared = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $complete['result'],
                $today->format('Y-m-d'),
                $complete['credit_rows'],
                $complete['child_rows'],
                null,
            );
            $printed = $prepared['document']->toTemplateData();
            self::assertSame(1, $printed['external_certificate_count']);
            self::assertSame(30_000_00, $printed['external_advance_base_minor_units']);
            self::assertSame(4_500_00, $printed['external_advance_tax_minor_units']);
            self::assertSame(
                $printed['advance_base_minor_units'] + 30_000_00,
                $printed['total_advance_base_minor_units'],
            );
            self::assertSame(
                $printed['advance_tax_minor_units'] + 4_500_00,
                $printed['total_advance_tax_minor_units'],
            );

            // Krok 3 — nedoložené potvrzení do úhrnu nepatří (§ 38ch odst. 4).
            $service->saveCertificates(
                $supplierId,
                $employeeId,
                self::YEAR,
                [[
                    'certificate_reference' => 'POT-2026-1',
                    'gross_income_minor_units' => 30_000_00,
                    'advance_base_minor_units' => 30_000_00,
                    'advance_tax_minor_units' => 4_500_00,
                    'non_refundable_credit_minor_units' => 2_570_00,
                    'child_credit_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'evidence_status' => 'unverified',
                ]],
                null,
            );
            $unverified = $service->preview($supplierId, $employeeId, self::YEAR, $today);
            self::assertFalse($unverified['result']->performed);
            self::assertContains(
                AnnualSettlementBlocker::ExternalCertificateUnverified->value,
                $unverified['result']->blockerCodes(),
            );

            // Krok 4 — prázdný seznam evidenci uklidí a vrátí výchozí stav.
            self::assertSame(
                [],
                $service->saveCertificates($supplierId, $employeeId, self::YEAR, [], null),
            );
            $cleared = $service->preview($supplierId, $employeeId, self::YEAR, $today);
            self::assertTrue($cleared['result']->performed);
            self::assertSame(
                $baseline['result']->settlementDifferenceMinorUnits,
                $cleared['result']->settlementDifferenceMinorUnits,
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /** Zaměstnanec bez podepsaného prohlášení — zúčtování se neprovede a řekne proč. */
    public function testUnsignedDeclarationRefusesWithAReason(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                declarationStatus: 'not-signed',
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );

            self::assertFalse($preview['result']->performed);
            self::assertSame(
                [AnnualSettlementBlocker::DeclarationNotSigned->value],
                $preview['result']->blockerCodes(),
            );
            // Nedopočítalo se „aspoň částečně".
            self::assertSame(0, $preview['result']->taxBeforeCreditsMinorUnits);
            self::assertSame(0, $preview['result']->payableMinorUnits);
            self::assertNull($preview['result']->outcome);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Zaměstnanec, kterému zúčtování provést nelze (§ 38ch odst. 1 věta druhá):
     * odmítne se s vysvětlením a nic se nespočítá.
     */
    public function testEmployeeWhoMustFileTaxReturnIsRefused(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                filingObligation: 'required',
                filingReason: 'Příjmy podle § 7 nad 20 000 Kč.',
            );

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable(sprintf('%04d-03-10', self::YEAR + 1)),
            );

            self::assertFalse($preview['result']->performed);
            self::assertSame(
                [AnnualSettlementBlocker::MustFileTaxReturn->value],
                $preview['result']->blockerCodes(),
            );
            self::assertSame(0, $preview['result']->settlementDifferenceMinorUnits);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Prázdný stav není selhání: rok bez jediné žádosti vrátí seznam
     * zaměstnanců s nevyplněnou evidencí, ne chybu.
     */
    public function testYearWithoutRequestsListsEmployeesWithUnknownState(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $settlements = $container->get(PayrollAnnualSettlementRepository::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_annual_settlement_outcomes')) {
            $this->markTestSkipped('Migrace ročního zúčtování neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();

        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                withRequest: false,
            );

            $page = $settlements->listForYear($supplierId, self::YEAR, 25, 0);
            $items = $page['items'];
            self::assertCount(1, $items);
            self::assertSame(1, $page['total']);
            self::assertSame($employeeId, $items[0]['employee_id']);
            self::assertNull($items[0]['request_status']);
            self::assertNull($items[0]['outcome']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    public function testChildIdentityMonthsAndOtherCaregiverAreFrozenForJmhz(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $service = $container->get(AnnualTaxSettlementService::class);
        $snapshots = $container->get(AnnualSettlementSnapshotBuilder::class);
        $settlements = $container->get(PayrollAnnualSettlementRepository::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $jmhzAnnual = $container->get(JmhzAnnualEvidenceService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $snapshots);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(JmhzAnnualEvidenceService::class, $jmhzAnnual);

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );
            $pdo->prepare(
                'INSERT INTO payroll_dependants
                    (supplier_id, employee_id, relation, full_name, given_name,
                     family_name, birth_date, ztp_p, student, existence_from)
                 VALUES (?, ?, "child_own", "Anna Syntetická", "Anna",
                         "Syntetická", "2018-04-12", 0, 0, "2018-04-12")',
            )->execute([$supplierId, $employeeId]);
            $dependantId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_person_tax_child_claims
                    (supplier_id, employee_id, dependant_id, child_reference,
                     child_order, ztp_p, evidence_status, evidence_reference,
                     shared_household_confirmed, other_claimant_excluded,
                     effective_from, effective_to)
                 VALUES (?, ?, ?, ?, 1, 0, "verified", "synthetic-child",
                         1, 1, ?, ?)',
            )->execute([
                $supplierId,
                $employeeId,
                $dependantId,
                'dependant-' . $dependantId,
                self::YEAR . '-03-01',
                self::YEAR . '-08-31',
            ]);
            $request = $settlements->findRequest(
                $supplierId,
                $employeeId,
                self::YEAR,
            );
            self::assertIsArray($request);
            $savedRequest = $settlements->saveRequest(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'request_status' => $request['request_status'],
                    'requested_on' => $request['requested_on'],
                    'request_evidence_reference' => $request['request_evidence_reference'],
                    'prior_employers' => $request['prior_employers'],
                    'prior_documents_received_on' => $request['prior_documents_received_on'],
                    'filing_obligation' => $request['filing_obligation'],
                    'filing_obligation_reason' => $request['filing_obligation_reason'],
                    'annual_claims' => $request['annual_claims'],
                    'annual_claims_note' => $request['annual_claims_note'],
                    'other_household_caregiver_status' => 'present',
                    'other_household_caregivers' => [[
                        'given_name' => 'Petr',
                        'family_name' => 'Syntetický',
                        'birth_date' => '1987-02-03',
                        'months_mask' => 'AANNNNNNNNNN',
                    ]],
                    'note' => $request['note'],
                ],
                (int) $request['row_version'],
                null,
            );
            self::assertSame('present', $savedRequest['other_household_caregiver_status']);
            self::assertCount(1, $savedRequest['other_household_caregivers']);

            $preview = $service->preview(
                $supplierId,
                $employeeId,
                self::YEAR,
                new DateTimeImmutable((self::YEAR + 1) . '-03-10'),
            );
            self::assertSame([], $preview['result']->blockerCodes());
            self::assertSame([3, 4, 5, 6, 7, 8], $preview['child_rows'][0]['claimed_months']);

            $built = $snapshots->build(
                $supplierId,
                $employeeId,
                self::YEAR,
                $preview['result'],
                (self::YEAR + 1) . '-03-10',
                $preview['credit_rows'],
                $preview['child_rows'],
                null,
            );
            $revisionId = (int) $built['revision']['id'];
            $settlements->insertOutcome(
                $supplierId,
                $employeeId,
                self::YEAR,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $preview['result']->outcome->value,
                    'tax_difference_minor' => $preview['result']->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $preview['result']->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' =>
                        $preview['result']->settlementDifferenceMinorUnits,
                    'payable_minor' => $preview['result']->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => (self::YEAR + 1) . '-03-10',
                ],
                null,
            );

            $frozen = $jmhzAnnual->snapshotsForPreparation(
                $supplierId,
                [$employeeId],
                self::YEAR + 1,
            )[$employeeId]['settlement']['child_rows'][0];
            self::assertSame('Anna', $frozen['given_name']);
            self::assertSame('Syntetická', $frozen['family_name']);
            self::assertSame('2018-04-12', $frozen['birth_date']);
            self::assertNull($frozen['birth_number']);
            self::assertSame('NN111111NNNN', $frozen['order_months_mask']);
            self::assertTrue($frozen['other_household_caregiver']);
            self::assertSame(
                'AANNNNNNNNNN',
                $frozen['other_household_caregivers'][0]['months_mask'],
            );
            self::assertSame(
                'Petr',
                $frozen['other_household_caregivers'][0]['identity']['given_name'],
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * Otisk všech měsíčních dat zaměstnance. Změní-li se cokoli v mzdových
     * revizích, výsledcích osob nebo kumulacích, otisk se rozejde.
     */
    private function monthsFingerprint(PDO $pdo, int $supplierId, int $employeeId): string
    {
        $statement = $pdo->prepare(
            'SELECT entry.period_start, entry.values_json, entry.record_hash,
                    person.result_hash, person.status, revision.status AS revision_status
               FROM payroll_statutory_accumulator_entries entry
               JOIN payroll_run_persons person
                 ON person.supplier_id = entry.supplier_id
                AND person.revision_id = entry.revision_id
                AND person.employee_id = entry.employee_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = entry.supplier_id
                AND revision.id = entry.revision_id
              WHERE entry.supplier_id = ? AND entry.employee_id = ?
              ORDER BY entry.period_start, entry.id'
        );
        $statement->execute([$supplierId, $employeeId]);

        return hash('sha256', json_encode(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Zaměstnanec s dvanácti schválenými měsíci, podepsaným prohlášením
     * a nárokem na základní slevu na poplatníka.
     *
     * @return array{0:int,1:int,2:int} supplier, employee, úhrn sražených záloh
     */
    private function fixture(
        PDO $pdo,
        int $sourceSupplierId,
        PayrollSensitiveData $sensitive,
        string $declarationStatus = 'signed',
        string $filingObligation = 'none',
        ?string $filingReason = null,
        bool $withRequest = true,
    ): array {
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická společnost",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678",
                    street = "Testovací 12",
                    city = "Testov",
                    zip = "10000"
              WHERE id = ?',
        )->execute([$supplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická roční osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Syntetická roční osoba", "Syntetická", "Osoba", ?)',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);

        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext,
                 value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:synthetic", ?, "••••0009")',
        )->execute([$supplierId, $employeeId, random_bytes(32)]);
        $identifierId = (int) $pdo->lastInsertId();
        $sealed = $sensitive->seal(
            '0001010009',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
            $identifierId,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $supplierId,
            $identifierId,
        ]);

        // Zákonná evidence — prohlášení, rezidentství, nárok na slevu.
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, ?, ?, "synthetic-declaration")',
        )->execute([
            $supplierId,
            $employeeId,
            $declarationStatus,
            sprintf('%04d-01-01', self::YEAR),
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", ?, "synthetic-residence")',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", ?, "synthetic-credit")',
        )->execute([$supplierId, $employeeId, sprintf('%04d-01-01', self::YEAR)]);

        if ($withRequest) {
            $pdo->prepare(
                'INSERT INTO payroll_annual_settlement_requests
                    (supplier_id, employee_id, tax_year, request_status,
                     requested_on, request_evidence_reference, prior_employers,
                     filing_obligation, filing_obligation_reason, annual_claims)
                 VALUES (?, ?, ?, "requested", ?, "synthetic-request", "none",
                         ?, ?, "none")',
            )->execute([
                $supplierId,
                $employeeId,
                self::YEAR,
                sprintf('%04d-02-05', self::YEAR + 1),
                $filingObligation,
                $filingReason,
            ]);
        }

        // Opening balance ročních kumulací — bez něj se kumulace nečte vůbec.
        $openingValues = json_encode([
            'advance_base_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'applied_child_credit_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 0,
            'completed_months' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_accumulator_openings
                (supplier_id, employee_id, tax_year, calculation_kind,
                 values_json, source_reference, evidence_json,
                 idempotency_key_hash, record_hash)
             VALUES (?, ?, ?, "income_tax", ?, "synthetic-opening", "[]", ?, ?)',
        )->execute([
            $supplierId,
            $employeeId,
            self::YEAR,
            $openingValues,
            random_bytes(32),
            hash('sha256', "synthetic-opening-{$supplierId}-{$employeeId}"),
        ]);

        // Dvanáct schválených měsíců. Měsíční daň se počítá stejným pravidlem
        // jako v produkci (§ 38h odst. 1 až 4), aby roční přeplatek vycházel
        // z reálného, ne vymyšleného úhrnu záloh.
        $taxpayerCreditMonthly = 257_000;
        $advanceTaxTotal = 0;
        for ($month = 1; $month <= 12; $month++) {
            $periodStart = sprintf('%04d-%02d-01', self::YEAR, $month);
            $pdo->prepare(
                'INSERT INTO payroll_runs
                    (supplier_id, period_start, payment_date, status,
                     current_revision_no)
                 VALUES (?, ?, ?, "approved", 1)',
            )->execute([
                $supplierId,
                $periodStart,
                sprintf('%04d-%02d-15', self::YEAR, $month),
            ]);
            $runId = (int) $pdo->lastInsertId();

            $resultSnapshot = json_encode(
                ['schema_version' => 'payroll-run-result.v2', 'people' => []],
                JSON_THROW_ON_ERROR,
            );
            $inputSnapshot = json_encode(
                ['schema_version' => 'payroll-run-input.v2'],
                JSON_THROW_ON_ERROR,
            );
            $pdo->prepare(
                'INSERT INTO payroll_run_revisions
                    (supplier_id, run_id, revision_no, status, schema_version,
                     ruleset_manifest_hash, input_snapshot_json,
                     input_snapshot_hash, result_snapshot_json,
                     result_snapshot_hash, idempotency_key_hash, approved_at)
                 VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                         ?, ?, ?, ?, ?, ?, NOW())',
            )->execute([
                $supplierId,
                $runId,
                str_repeat('b', 64),
                $inputSnapshot,
                hash('sha256', $inputSnapshot),
                $resultSnapshot,
                hash('sha256', $resultSnapshot),
                random_bytes(32),
            ]);
            $revisionId = (int) $pdo->lastInsertId();

            $personResult = json_encode(
                ['employee_id' => $employeeId, 'period_start' => $periodStart],
                JSON_THROW_ON_ERROR,
            );
            $pdo->prepare(
                'INSERT INTO payroll_run_persons
                    (supplier_id, revision_id, employee_id, result_json,
                     result_hash, status)
                 VALUES (?, ?, ?, ?, ?, "calculated")',
            )->execute([
                $supplierId,
                $revisionId,
                $employeeId,
                $personResult,
                hash('sha256', $personResult),
            ]);

            // § 38h odst. 1: základ nad 100 Kč se zaokrouhluje na celé stokoruny
            // nahoru; § 38h odst. 2 a 3: 15 % a zaokrouhlení daně na koruny nahoru.
            $roundedBase = (int) (ceil(self::MONTHLY_GROSS_MINOR / 10_000) * 10_000);
            $taxBeforeCredits = (int) (ceil($roundedBase * 15 / 100 / 100) * 100);
            $advanceTax = max(0, $taxBeforeCredits - $taxpayerCreditMonthly);
            $advanceTaxTotal += $advanceTax;

            $entryValues = json_encode([
                'advance_base_minor_units' => self::MONTHLY_GROSS_MINOR,
                'advance_tax_minor_units' => $advanceTax,
                'applied_child_credit_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' =>
                    min($taxBeforeCredits, $taxpayerCreditMonthly),
                'bonus_qualifying_income_minor_units' => self::MONTHLY_GROSS_MINOR,
                'completed_months' => 1,
                'tax_bonus_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
            ], JSON_THROW_ON_ERROR);
            $pdo->prepare(
                'INSERT INTO payroll_statutory_accumulator_entries
                    (supplier_id, employee_id, tax_year, period_start,
                     revision_id, calculation_kind, values_json,
                     source_result_hash, record_hash)
                 VALUES (?, ?, ?, ?, ?, "income_tax", ?, ?, ?)',
            )->execute([
                $supplierId,
                $employeeId,
                self::YEAR,
                $periodStart,
                $revisionId,
                $entryValues,
                hash('sha256', $personResult),
                hash('sha256', "synthetic-entry-{$revisionId}"),
            ]);
        }

        return [$supplierId, $employeeId, $advanceTaxTotal];
    }
}
