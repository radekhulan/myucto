<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\Reports\StatementNotesService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `TaxReturnService::buildStatementNotesAttachment()` (private — volaná z
 * `buildDppoAppendix()`) — konec cesty pro EPO chybu 2602 „Není vložena příloha
 * účetní závěrky" (viz AUDIT-DPPO-XML.md dodatek 9.4c/13): appendix VetaUA/UB/UD/UZ
 * chybu neřeší, chce se skutečně přiložený soubor (`Prilohy/ObecnaPriloha`).
 *
 * DB integrační test (v transakci, rollback v tearDown — stejný vzor jako
 * {@see \MyInvoice\Tests\Integration\Accounting\StatementNotesTest}), protože
 * `StatementNotesService`/`StatementNotesPdfRenderer` jsou `final` a nejdou mockovat;
 * jediný způsob, jak ověřit „kompletní → příloha se PŘIPOJÍ" a „nekompletní →
 * NEPŘIPOJÍ se + warning", je reálný běh přes DB.
 */
#[Group('integration')]
final class DppoStatementNotesAttachmentTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private TaxReturnService $service;
    private AccountingPeriodRepository $periods;
    private StatementNotesService $notes;
    private AccountingSupplierSettingsRepository $settings;
    private int $supplierId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->service  = $c->get(TaxReturnService::class);
            $this->periods  = $c->get(AccountingPeriodRepository::class);
            $this->notes    = $c->get(StatementNotesService::class);
            $this->settings = $c->get(AccountingSupplierSettingsRepository::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'statement_notes'")->fetch() === false) {
            self::markTestSkipped('Migrace 1154 neproběhla.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            self::markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic,
                                   default_currency_id, default_vat_rate_id)
             VALUES (?, "Příloha 5", "Praha", "11000", ?, ?, "12345678", "CZ12345678", ?, ?)'
        )->execute(['Příloha-EPO s.r.o.', $czId, 'priloha-epo@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->periodId = $this->periods->create(
            $this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'
        );
        // average_employees (auto sekce SCOPE_ALL) se vyplní jen s avg_employees > 0.
        $this->settings->upsert($this->supplierId, 3, null, false);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
        }
    }

    /** @return array{file:?array{content:string,filename:string,label:string},warning:?string} */
    private function invoke(array $period): array
    {
        $method = new ReflectionMethod($this->service, 'buildStatementNotesAttachment');
        return $method->invoke($this->service, $this->supplierId, $this->periodId, $period);
    }

    private function period(): array
    {
        return ['starts_on' => self::YEAR . '-01-01', 'ends_on' => self::YEAR . '-12-31'];
    }

    /** Manuální sekce § 39 (SCOPE_ALL, ne-auto) — vyplní všechny, ať $notes['missing'] === []. */
    private function fillAllManualSections(): void
    {
        foreach ([
            'accounting_principles', 'valuation_methods', 'fx_policy', 'balance_pl_details',
            'receivables_payables_over_5y', 'board_loans_advances', 'extraordinary_items',
            'off_balance_commitments', 'subsequent_events',
        ] as $key) {
            $this->notes->saveSection($this->supplierId, self::YEAR, $key, 'Text sekce ' . $key . ' pro test.', null);
        }
    }

    public function testIncompleteNotesDoNotAttachAndWarnWithPath(): void
    {
        // Žádná sekce vyplněná — příloha je „poloprázdná/prázdná", nesmí se připojit.
        $result = $this->invoke($this->period());

        self::assertNull($result['file']);
        self::assertNotNull($result['warning']);
        self::assertStringContainsString('není vyplněná celá', $result['warning']);
        self::assertStringContainsString('/accounting/periods/' . $this->periodId . '/statement-notes', $result['warning']);
    }

    public function testCompleteNotesAttachPdfWithinSizeLimit(): void
    {
        $this->fillAllManualSections();

        $result = $this->invoke($this->period());

        self::assertNull($result['warning']);
        self::assertNotNull($result['file']);
        self::assertSame(sprintf('priloha-ucetni-zaverky-%d.pdf', self::YEAR), $result['file']['filename']);
        self::assertStringContainsString((string) self::YEAR, $result['file']['label']);
        // %PDF hlavička — potvrzuje, že jde o skutečně vyrenderovaný PDF obsah, ne text/zástupný řetězec.
        self::assertStringStartsWith('%PDF', $result['file']['content']);
        self::assertLessThanOrEqual(10_240 * 1024, strlen($result['file']['content']));
    }

    public function testMissingPeriodIdCausesErrorWarningNotSilentDrop(): void
    {
        // period id 0 → StatementNotesService::build() hodí ReportException (period_not_found),
        // buildStatementNotesAttachment ji musí proměnit ve warning, ne nechat propadnout tiše.
        $method = new ReflectionMethod($this->service, 'buildStatementNotesAttachment');
        $result = $method->invoke($this->service, $this->supplierId, 0, $this->period());

        self::assertNull($result['file']);
        self::assertNotNull($result['warning']);
    }
}
