<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Activation;

use MyInvoice\Action\Settings\AccountingActivationAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Activation\BackfillService;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class AccountingActivationTest extends TestCase
{
    private const STARTS_ON = '2099-01-01';

    private Connection $db;
    private AccountingActivationAction $action;
    private SettingsAction $settings;
    private AccountingBackfillJobRepository $jobs;
    private OpeningBalanceService $opening;
    private BackfillService $backfill;
    private AccountingPeriodRepository $periods;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(AccountingActivationAction::class);
            $this->settings = $container->get(SettingsAction::class);
            $this->jobs = $container->get(AccountingBackfillJobRepository::class);
            $this->opening = $container->get(OpeningBalanceService::class);
            $this->backfill = $container->get(BackfillService::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $this->periods = $periods;
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('DELETE FROM accounting_backfill_jobs WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_opening_balances WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM journal_entries WHERE supplier_id = ? AND source_type = 'opening'")->execute([$this->supplierId]);
        $pdo->prepare(
            "UPDATE supplier SET accounting_mode = 'tax_evidence', accounting_starts_on = ?,
                    accounting_activation_status = 'draft', taxpayer_type = 'fo' WHERE id = ?"
        )->execute([self::STARTS_ON, $this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->ensureOpenPeriodFor($this->supplierId, self::STARTS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->db->close();
        }
    }

    public function testDryRunWritesNothingAndRepeatedExecuteIsIdempotent(): void
    {
        $draft = $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00, 'note' => 'Syntetický test'],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00, 'note' => 'Syntetický test'],
        ]);
        self::assertTrue($draft['totals']['balanced']);

        $dryId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($dryId);
        $dry = $this->jobs->find($dryId, $this->supplierId);
        self::assertSame('completed', $dry['status']);
        self::assertSame(0, $dry['report_json']['failed_total']);
        self::assertSame(0, $this->openingEntryCount(), 'Dry-run nezapsal otevírací zápis.');
        self::assertSame('tax_evidence', $this->supplierMode(), 'Dry-run nepřepnul účetní režim.');

        $firstId = $this->jobs->create($this->supplierId, 'execute', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($firstId);
        $firstEntryId = $this->openingEntryId();
        self::assertGreaterThan(0, $firstEntryId);
        self::assertSame('completed', $this->jobs->find($firstId, $this->supplierId)['status']);
        self::assertArrayHasKey(
            'advance_settlements',
            $this->jobs->find($firstId, $this->supplierId)['report_json']['phases'],
            'Aktivace po zaúčtování banky znovu přepočítá finální doklady navázané na zálohy.',
        );
        self::assertSame('double_entry', $this->supplierMode());
        self::assertSame('completed', $this->supplierActivationStatus());
        self::assertSame(0, $this->account701DifferenceCents($firstEntryId));

        $secondId = $this->jobs->create($this->supplierId, 'execute', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($secondId);
        self::assertSame('completed', $this->jobs->find($secondId, $this->supplierId)['status']);
        self::assertSame(1, $this->openingEntryCount(), 'Opakovaný execute nevytvořil duplicitní opening.');
        self::assertSame($firstEntryId, $this->openingEntryId(), 'Opening se přepsal in-place.');
    }

    /**
     * Kontrola nanečisto má ostrému běhu předejít, ne ho pustit do chyby. Nad zavřeným
     * obdobím zahájení hlásila `failed_total: 0` a teprve execute skončil `failed`
     * s hláškou „Období zahájení účetnictví není otevřené."
     */
    public function testDryRunFailsWhenStartingPeriodIsNotOpen(): void
    {
        $draft = $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
        ]);
        $period = $this->periods->findForDate($this->supplierId, self::STARTS_ON);
        self::assertNotNull($period);
        self::assertTrue($this->periods->setStatus((int) $period['id'], $this->supplierId, 'closed'));

        $dryId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        try {
            $this->backfill->run($dryId);
        } catch (\Throwable) {
            // Worker výjimku loguje; pro test rozhoduje uložený stav jobu.
        }

        $dry = $this->jobs->find($dryId, $this->supplierId);
        self::assertSame('failed', $dry['status'], 'Kontrola nanečisto nesmí nad zavřeným obdobím projít.');
        self::assertSame('period_not_open', $dry['report_json']['fatal_error']);
        self::assertGreaterThan(0, (int) $dry['report_json']['failed_total']);
        self::assertSame(0, $this->openingEntryCount(), 'Kontrola nanečisto nic nezapsala.');
    }

    /** Prázdná rozvaha nemá co postovat — zavřené období jí nevadí a kontrola projde. */
    public function testDryRunWithoutOpeningRowsIgnoresClosedPeriod(): void
    {
        $period = $this->periods->findForDate($this->supplierId, self::STARTS_ON);
        self::assertNotNull($period);
        $this->periods->setStatus((int) $period['id'], $this->supplierId, 'closed');

        $dryId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $this->opening->draft($this->supplierId)['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($dryId);

        $dry = $this->jobs->find($dryId, $this->supplierId);
        self::assertSame('completed', $dry['status']);
        self::assertSame('skipped', $dry['report_json']['phases']['opening']['status']);
    }

    /**
     * Po dokončené aktivaci musí zůstat cesta zpět k otevírací rozvaze, dokud je cílové
     * období otevřené — jinak chybějící počáteční stavy nejde doplnit už nikdy.
     */
    public function testOpeningStaysEditableAfterCompletedActivation(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_mode = 'double_entry', accounting_activation_status = 'completed' WHERE id = ?"
        )->execute([$this->supplierId]);

        $payload = $this->json($this->action->status($this->request('GET'), new Psr7Response()));
        self::assertTrue($payload['opening']['editable'], 'Rozvahu lze doplnit i po aktivaci.');
        self::assertFalse($payload['opening']['posted'], 'Přeskočená rozvaha nemá otevírací zápis.');
        self::assertNull($payload['opening']['blocked_reason']);

        $period = $this->periods->findForDate($this->supplierId, self::STARTS_ON);
        $this->periods->setStatus((int) $period['id'], $this->supplierId, 'closed');

        $closed = $this->json($this->action->status($this->request('GET'), new Psr7Response()));
        self::assertFalse($closed['opening']['editable'], 'Nad zavřeným obdobím už rozvahu doplnit nejde.');
        self::assertSame('period_not_open', $closed['opening']['blocked_reason']);
    }

    /** Zaúčtovanou rozvahu průvodce nenabízí doplnit — `posted` to rozliší od přeskočené. */
    public function testStatusReportsPostedOpeningEntry(): void
    {
        $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 250.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 250.00],
        ]);
        $this->opening->post($this->supplierId, self::STARTS_ON, ['user_id' => $this->userId]);

        $payload = $this->json($this->action->status($this->request('GET'), new Psr7Response()));
        self::assertTrue($payload['opening']['posted']);
        self::assertTrue($payload['opening']['editable']);
    }

    /**
     * U rozvahy o víc řádcích je konkrétní důvod jediná použitelná informace — server
     * proto vedle zprávy vrací i index vadného řádku, aby ho rozhraní umělo ukázat.
     */
    public function testInvalidOpeningRowReturnsReasonAndRowIndex(): void
    {
        $request = $this->request('PUT')->withParsedBody(['rows' => [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 0],
        ]]);
        $response = $this->action->saveOpening($request, new Psr7Response());
        $payload = $this->json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertSame(1, $payload['error']['row'], 'Chyba ukazuje na druhý řádek.');
        self::assertStringContainsString('částka musí být kladná', $payload['error']['message']);
    }

    /** Dvakrát tentýž účet na téže straně hlásí SVŮJ kód a SVŮJ řádek, ne obecné „údaje nejsou platné". */
    public function testDuplicateAccountSideReturnsAccountCodeAndRowIndex(): void
    {
        $request = $this->request('PUT')->withParsedBody(['rows' => [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '211', 'side' => 'debit', 'amount' => 50.00],
        ]]);
        $payload = $this->json($this->action->saveOpening($request, new Psr7Response()));

        self::assertSame(1, $payload['error']['row']);
        self::assertStringContainsString('211', $payload['error']['message']);
    }

    public function testUnbalancedOpeningBlocksExecuteBeforeJobCreation(): void
    {
        $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
        ]);
        $response = $this->action->execute($this->request('POST'), new Psr7Response());
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('opening_unbalanced', $payload['error']['code']);
        self::assertSame(0, $this->jobCount());
    }

    public function testDirectModeSwitchWithPendingHistoryReturnsBackfillRequired(): void
    {
        $pdo = $this->db->pdo();
        $name = 'E2 test ' . bin2hex(random_bytes(4));
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, account_code, is_default, is_active)
             VALUES (?, ?, '211E2', 0, 1)"
        )->execute([$this->supplierId, $name]);
        $registerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description,
                 vat_mode, total_amount, currency_code, status, created_by)
             VALUES (?, ?, 'in', 'other', ?, ?, 'Syntetický test aktivace',
                     'none', 100.00, 'CZK', 'posted', ?)"
        )->execute([$this->supplierId, $registerId, 'PPD-E2-' . bin2hex(random_bytes(3)), date('Y-m-d'), $this->userId]);

        $request = $this->request('PUT')->withParsedBody([
            'accounting_mode' => 'double_entry',
            'accounting_mode_effective_from' => date('Y-01-01'),
        ]);
        $response = $this->settings->updateSupplier($request, new Psr7Response());
        $payload = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('backfill_required', $payload['error']['code']);
        self::assertGreaterThanOrEqual(1, $payload['error']['cash_documents']);
        self::assertSame('tax_evidence', $this->supplierMode());
    }

    public function testJobIsTenantScopedAndMutationRequiresAdmin(): void
    {
        $jobId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => hash('sha256', 'none'),
        ], $this->userId);

        $foreign = $this->request('GET', $this->supplierId + 999);
        $notFound = $this->action->job($foreign, new Psr7Response(), ['id' => (string) $jobId]);
        self::assertSame(404, $notFound->getStatusCode());

        $readonly = $this->request('POST')->withAttribute(AuthMiddleware::ATTR_USER, [
            'id' => $this->userId,
            'role' => 'readonly',
        ]);
        $forbidden = $this->action->start($readonly->withParsedBody(['starts_on' => date('Y-m-d')]), new Psr7Response());
        self::assertSame(403, $forbidden->getStatusCode());
    }

    public function testJobHistoryIsPaginatedWithoutSilentLimit(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $id = $this->jobs->create($this->supplierId, 'dry_run', [
                'starts_on' => self::STARTS_ON,
                'opening_hash' => 'page-' . $index,
                'with_rules' => false,
            ], $this->userId);
            $this->jobs->markCompleted($id);
        }

        $request = $this->request('GET')->withQueryParams(['page' => '2', 'per_page' => '2']);
        $response = $this->action->jobs($request, new Psr7Response());
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['per_page']);
        self::assertCount(1, $body['items']);
    }

    public function testOpeningBankBalanceIgnoresForeignCurrencyTransactions(): void
    {
        $method = new \ReflectionMethod($this->opening, 'bankBalance');
        $asOf = '2098-12-31';
        $before = (float) $method->invoke($this->opening, $this->supplierId, $asOf);
        $pdo = $this->db->pdo();

        $insertCurrency = $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?)'
        );
        $insertStatement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, ?, ?, ?)'
        );

        foreach ([['CZK', '9990981001', 100.00], ['EUR', '9990981002', 250.00]] as [$currency, $account, $amount]) {
            $insertCurrency->execute([
                $this->supplierId, $currency, "TEST opening {$currency}", $currency, $currency, $currency,
                $account, '0100',
            ]);
            $insertStatement->execute([
                $this->supplierId,
                "__test_opening_{$currency}.gpc",
                hash('sha256', "opening-{$this->supplierId}-{$currency}"),
                $account,
                '0100',
                $currency,
                $asOf,
            ]);
            $insertTx->execute([(int) $pdo->lastInsertId(), $asOf, $amount, $currency]);
        }

        $after = (float) $method->invoke($this->opening, $this->supplierId, $asOf);
        self::assertEqualsWithDelta(100.00, $after - $before, 0.001);
    }

    /**
     * Počáteční stav banky se rozpadá na analytiky vlastních účtů (#35). Zdroj dat to
     * unese: každá transakce visí na hlavičce výpisu, a ta nese číslo vlastního účtu —
     * tutéž dvojici, ze které bankovní nohu odvozuje běžný provoz.
     */
    public function testOpeningBankBalanceSplitsPerOwnAccountAnalytic(): void
    {
        $asOf = '2098-12-31';
        $before = $this->bankSplit($asOf);

        // 1) Firma s JEDINÝM bankovním účtem — i ta musí dostat vlastní analytiku.
        $first = $this->ownBankAccount('9990981017');
        $this->bankStatementWithTx('9990981017', 100.00, $asOf);
        $single = $this->bankSplit($asOf);
        $firstCode = $this->analyticCode($first);
        self::assertNotNull($firstCode, 'Jediný bankovní účet dostal analytiku.');
        self::assertArrayHasKey($firstCode, $single, 'Počáteční stav leží na analytice účtu.');
        self::assertEqualsWithDelta(100.00, $single[$firstCode]['amount'] - ($before[$firstCode]['amount'] ?? 0.0), 0.001);
        self::assertEqualsWithDelta(
            $before['221']['amount'] ?? 0.0,
            $single['221']['amount'] ?? 0.0,
            0.001,
            'Zůstatek přiřazeného účtu se na syntetice 221 neobjeví.',
        );

        // 2) Druhý účet nesmí skončit ve stejném kbelíku — jinak by se zůstatky promíchaly.
        $second = $this->ownBankAccount('9990981021');
        $this->bankStatementWithTx('9990981021', 250.00, $asOf);
        $both = $this->bankSplit($asOf);
        $secondCode = $this->analyticCode($second);
        self::assertNotNull($secondCode);
        self::assertNotSame($firstCode, $secondCode, 'Každý bankovní účet má vlastní analytiku.');
        self::assertEqualsWithDelta(100.00, $both[$firstCode]['amount'] - ($before[$firstCode]['amount'] ?? 0.0), 0.001);
        self::assertEqualsWithDelta(250.00, $both[$secondCode]['amount'] - ($before[$secondCode]['amount'] ?? 0.0), 0.001);
    }

    /**
     * Výpis, ke kterému vlastní účet dohledat nejde, se NEROZDĚLUJE odhadem — zůstane
     * na syntetice, ale s poznámkou, že ho účetní musí rozúčtovat ručně.
     */
    public function testUnmatchedStatementStaysOnSyntheticWithManualSplitNote(): void
    {
        $asOf = '2098-12-31';
        $before = $this->bankSplit($asOf);
        $this->bankStatementWithTx('9990981036', 70.00, $asOf);
        $after = $this->bankSplit($asOf);

        self::assertArrayHasKey('221', $after, 'Nepřiřazený výpis zůstává na syntetice 221.');
        self::assertEqualsWithDelta(70.00, $after['221']['amount'] - ($before['221']['amount'] ?? 0.0), 0.001);
        self::assertStringContainsString(
            'rozúčtujte ručně',
            (string) $after['221']['note'],
            'Souhrn na syntetice nesmí být tichý — poznámka volá po ručním rozúčtování.',
        );
    }

    /** Rozpad nesmí žádný pohyb ztratit ani přidat — součet sedí na plochý souhrn. */
    public function testBankSplitSumsToFlatBalance(): void
    {
        $asOf = '2098-12-31';
        $this->ownBankAccount('9990981040');
        $this->bankStatementWithTx('9990981040', 1234.50, $asOf);
        $this->bankStatementWithTx('9990981055', -34.50, $asOf);

        $flat = (float) (new \ReflectionMethod($this->opening, 'bankBalance'))
            ->invoke($this->opening, $this->supplierId, $asOf);
        $split = array_sum(array_column($this->bankSplit($asOf), 'amount'));

        self::assertEqualsWithDelta($flat, $split, 0.011);
    }

    /** Firma bez bankovních dat: žádný bankovní řádek, žádná analytika navíc. */
    public function testSupplierWithoutBankAccountsHasNoBankOpeningRow(): void
    {
        $empty = (int) $this->db->pdo()->query('SELECT MAX(id) + 1000 FROM supplier')->fetchColumn();
        self::assertSame([], $this->bankSplit('2098-12-31', $empty));
    }

    /** Konec konců rozhoduje draft: prefill uloží bankovní stav na analytiku, ne na 221. */
    public function testPrefillWritesBankOpeningToOwnAccountAnalytic(): void
    {
        $asOf = '2098-12-31';
        $account = $this->ownBankAccount('9990981074');
        $this->bankStatementWithTx('9990981074', 500.00, $asOf);

        $draft = $this->opening->prefill($this->supplierId, $asOf);
        $bankRows = array_values(array_filter(
            $draft['rows'],
            static fn (array $row): bool => preg_match('/^221[.][0-9]+$/', (string) $row['account_code']) === 1
                && abs((float) $row['amount'] - 500.00) < 0.005,
        ));

        self::assertCount(1, $bankRows, 'Počáteční stav banky patří na analytiku vlastního účtu, ne na plochou 221.');
        self::assertSame($this->analyticCode($account), $bankRows[0]['account_code']);
        self::assertSame('debit', $bankRows[0]['side']);
        self::assertSame('transition_report', $bankRows[0]['source']);
        self::assertStringContainsString('9990981074', (string) $bankRows[0]['note'], 'Poznámka pojmenuje účet.');
    }

    /**
     * Počáteční stav pokladen se rozpadá na analytiky jednotlivých pokladen, stejně jako
     * banka. Zdroj dat to unese: doklad visí na registru a registr nese svůj account_code
     * — týž účet, na který doklad zaúčtuje běžný provoz a nad kterým jede pokladní kniha.
     * Bez opravy skončil celý zůstatek jedním řádkem na holé syntetice 211.
     */
    public function testOpeningCashBalanceSplitsPerRegisterAnalytic(): void
    {
        $asOf = '2098-12-31';
        $before = $this->cashSplit($asOf);

        $first = $this->cashRegister('TEST pokladna A', '211.941');
        $this->cashDocument($first, 'in', 1000.00, $asOf);
        $single = $this->cashSplit($asOf);
        self::assertArrayHasKey('211.941', $single, 'Počáteční stav leží na analytice pokladny.');
        self::assertEqualsWithDelta(1000.00, $single['211.941']['amount'] - ($before['211.941']['amount'] ?? 0.0), 0.001);
        self::assertEqualsWithDelta(
            $before['211']['amount'] ?? 0.0,
            $single['211']['amount'] ?? 0.0,
            0.001,
            'Zůstatek přiřazené pokladny se na syntetice 211 neobjeví.',
        );

        $second = $this->cashRegister('TEST pokladna B', '211.942');
        $this->cashDocument($second, 'in', 700.00, $asOf);
        $this->cashDocument($second, 'out', 250.00, $asOf);
        $both = $this->cashSplit($asOf);
        self::assertEqualsWithDelta(1000.00, $both['211.941']['amount'] - ($before['211.941']['amount'] ?? 0.0), 0.001);
        self::assertEqualsWithDelta(450.00, $both['211.942']['amount'] - ($before['211.942']['amount'] ?? 0.0), 0.001);
        self::assertStringContainsString('TEST pokladna A', (string) $both['211.941']['note']);
        self::assertStringContainsString('TEST pokladna B', (string) $both['211.942']['note']);
    }

    /**
     * Analytika, kterou si uživatel v osnově VYPNUL, nesmí shodit prefill — replace() by
     * nad neaktivním účtem hodil validation_failed. Zůstatek spadne na syntetiku 211
     * s poznámkou volající po ručním rozúčtování.
     */
    public function testCashRegisterWithDisabledAnalyticFallsBackToSynthetic(): void
    {
        $asOf = '2098-12-31';
        $before = $this->cashSplit($asOf);
        $register = $this->cashRegister('TEST pokladna vypnutá', '211.943');
        $this->db->pdo()->prepare(
            'UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = ?'
        )->execute([$this->supplierId, '211.943']);
        $this->cashDocument($register, 'in', 320.00, $asOf);

        $after = $this->cashSplit($asOf);
        self::assertArrayNotHasKey('211.943', $after, 'Vypnutá analytika se do rozvahy nedostane.');
        self::assertEqualsWithDelta(320.00, $after['211']['amount'] - ($before['211']['amount'] ?? 0.0), 0.001);
        self::assertStringContainsString('rozúčtujte ručně', (string) $after['211']['note']);
    }

    /**
     * Dvě pokladny, které spadnou na TÝŽ účet, se musí sečíst do jednoho řádku — dva
     * řádky se stejným account_code na téže straně by replace() odmítl jako duplicitu
     * a prefill by spadl.
     *
     * UNIQUE (supplier_id, account_code) drží číselník, takže na jedné ANALYTICE dvě
     * pokladny neskončí; sejít se ale můžou ve fallbacku na syntetice 211 — proto se
     * kbelíkuje podle KÓDU, ne podle registru.
     */
    public function testRegistersFallingToSameAccountProduceSingleRow(): void
    {
        $asOf = '2098-12-31';
        $before = $this->cashSplit($asOf);
        foreach ([['TEST pokladna C1', '211.944', 100.00], ['TEST pokladna C2', '211.945', 60.00]] as [$name, $code, $amount]) {
            $register = $this->cashRegister($name, $code);
            $this->db->pdo()->prepare(
                'UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = ?'
            )->execute([$this->supplierId, $code]);
            $this->cashDocument($register, 'in', $amount, $asOf);
        }

        $after = $this->cashSplit($asOf);
        $rows = (new \ReflectionMethod($this->opening, 'cashBalancesByRegister'))
            ->invoke($this->opening, $this->supplierId, $asOf);
        $onCode = array_filter($rows, static fn (array $r): bool => $r['account_code'] === '211');

        self::assertCount(1, $onCode, 'Jeden účet = jeden řádek rozvahy.');
        self::assertEqualsWithDelta(160.00, $after['211']['amount'] - ($before['211']['amount'] ?? 0.0), 0.001);
        self::assertStringContainsString('TEST pokladna C1', (string) $after['211']['note']);
        self::assertStringContainsString('TEST pokladna C2', (string) $after['211']['note']);
    }

    /** Pokladna bez dokladů nemá co do rozvahy přinést — žádný nulový řádek. */
    public function testRegisterWithoutDocumentsHasNoOpeningRow(): void
    {
        $asOf = '2098-12-31';
        $this->cashRegister('TEST pokladna prázdná', '211.946');
        self::assertArrayNotHasKey('211.946', $this->cashSplit($asOf));
    }

    /** Rozpad nesmí žádný doklad ztratit ani přidat — součet sedí na plochý souhrn. */
    public function testCashSplitSumsToFlatBalance(): void
    {
        $asOf = '2098-12-31';
        $this->cashDocument($this->cashRegister('TEST pokladna D', '211.947'), 'in', 1234.50, $asOf);
        $this->cashDocument($this->cashRegister('TEST pokladna E', '211.948'), 'out', 34.50, $asOf);

        $flat = (float) (new \ReflectionMethod($this->opening, 'cashBalance'))
            ->invoke($this->opening, $this->supplierId, $asOf);
        $split = array_sum(array_column($this->cashSplit($asOf), 'amount'));

        self::assertEqualsWithDelta($flat, $split, 0.011);
    }

    /**
     * Valutová pokladna: `total_amount` je v DB už CZK ekvivalent (migrace 1114), kurzem
     * se NEPŘEPOČÍTÁVÁ podruhé — jinak by se 100 EUR / 25 Kč zaúčtovalo jako 62 500 Kč.
     */
    public function testForeignCurrencyRegisterIsNotConvertedTwice(): void
    {
        $asOf = '2098-12-31';
        $register = $this->cashRegister('TEST pokladna EUR', '211.949', 'EUR');
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, issue_date, description,
                 total_amount, currency_code, fx_rate, amount_foreign, status)
             VALUES (?, ?, 'in', 'other', ?, 'TEST valuta', 2500.00, 'EUR', 25.000000, 100.00, 'posted')"
        )->execute([$this->supplierId, $register, $asOf]);

        $split = $this->cashSplit($asOf);
        self::assertEqualsWithDelta(2500.00, $split['211.949']['amount'], 0.001);
    }

    /** Konec konců rozhoduje draft: prefill uloží pokladní stav na analytiku, ne na 211. */
    public function testPrefillWritesCashOpeningToRegisterAnalytic(): void
    {
        $asOf = '2098-12-31';
        // Test tvrdí „na 211 NIC nezůstalo" — cizí pokladní doklady v testovací DB by ho
        // rozhodovaly za nás. Běží v transakci, která se v tearDown odrolluje.
        $this->db->pdo()->prepare('DELETE FROM cash_documents WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->cashDocument($this->cashRegister('TEST pokladna F', '211.950'), 'in', 500.00, $asOf);
        $this->cashDocument($this->cashRegister('TEST pokladna G', '211.951'), 'in', 800.00, $asOf);

        $draft = $this->opening->prefill($this->supplierId, $asOf);
        $cashRows = array_values(array_filter(
            $draft['rows'],
            static fn (array $row): bool => str_starts_with((string) $row['account_code'], '211'),
        ));
        $byCode = array_column($cashRows, null, 'account_code');

        self::assertArrayNotHasKey('211', $byCode, 'Počáteční stav pokladen nepatří na plochou syntetiku 211.');
        self::assertArrayHasKey('211.950', $byCode);
        self::assertArrayHasKey('211.951', $byCode);
        self::assertEqualsWithDelta(500.00, (float) $byCode['211.950']['amount'], 0.001);
        self::assertEqualsWithDelta(800.00, (float) $byCode['211.951']['amount'], 0.001);
        self::assertSame('debit', $byCode['211.950']['side']);
        self::assertSame('transition_report', $byCode['211.950']['source']);
        self::assertStringContainsString('TEST pokladna F', (string) $byCode['211.950']['note'], 'Poznámka pojmenuje pokladnu.');
    }

    /**
     * Rozpad počátečního stavu pokladen podle registrů.
     *
     * @return array<string, array{account_code:string, amount:float, note:string}>
     */
    private function cashSplit(string $asOf, ?int $supplierId = null): array
    {
        $rows = (new \ReflectionMethod($this->opening, 'cashBalancesByRegister'))
            ->invoke($this->opening, $supplierId ?? $this->supplierId, $asOf);
        return array_column($rows, null, 'account_code');
    }

    /** Pokladna s vlastní analytikou 211.xxx (účet se rovnou založí v osnově). */
    private function cashRegister(string $name, string $accountCode, string $currency = 'CZK'): int
    {
        $pdo = $this->db->pdo();
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '211'"
        )->fetchColumn();
        $pdo->prepare(
            "INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, 'asset', 'debit', 0, ?, 1)"
        )->execute([$this->supplierId, $accountCode, $name, $parentId ?: null]);
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, is_active)
             VALUES (?, ?, ?, ?, 0, 1)'
        )->execute([$this->supplierId, $name, $currency, $accountCode]);
        return (int) $pdo->lastInsertId();
    }

    private function cashDocument(int $registerId, string $type, float $amount, string $asOf): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, issue_date, description, total_amount, status)
             VALUES (?, ?, ?, 'other', ?, 'TEST rozpad pokladen', ?, 'posted')"
        )->execute([$this->supplierId, $registerId, $type, $asOf, $amount]);
    }

    /**
     * Rozpad počátečního stavu banky podle vlastních účtů.
     *
     * @return array<string, array{account_code:string, amount:float, note:string}>
     */
    private function bankSplit(string $asOf, ?int $supplierId = null): array
    {
        $rows = (new \ReflectionMethod($this->opening, 'bankBalancesByAccount'))
            ->invoke($this->opening, $supplierId ?? $this->supplierId, $asOf);
        return array_column($rows, null, 'account_code');
    }

    /** Vlastní bankovní účet firmy BEZ analytiky — tu má přidělit sám mechanismus. */
    private function ownBankAccount(string $account, string $bank = '0100'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, "CZK", ?, "current", "manual", 1)'
        )->execute([$this->supplierId, 'TEST opening ' . $account, $account, $bank, $bank, $account]);
        return (int) $pdo->lastInsertId();
    }

    private function bankStatementWithTx(string $account, float $amount, string $asOf, string $bank = '0100'): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, "CZK", ?)'
        )->execute([
            $this->supplierId,
            "__test_split_{$account}_{$amount}.gpc",
            hash('sha256', "split-{$this->supplierId}-{$account}-{$amount}"),
            $account,
            $bank,
            $asOf,
        ]);
        $pdo->prepare('INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, ?, ?, "CZK")')
            ->execute([(int) $pdo->lastInsertId(), $asOf, $amount]);
    }

    private function analyticCode(int $bankAccountId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT analytic_suffix FROM supplier_bank_accounts WHERE id = ?');
        $stmt->execute([$bankAccountId]);
        $suffix = (string) ($stmt->fetchColumn() ?: '');
        return $suffix === '' ? null : '221.' . $suffix;
    }

    private function request(string $method, ?int $supplierId = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/settings/accounting-activation')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function supplierMode(): string
    {
        return (string) $this->db->pdo()->query('SELECT accounting_mode FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();
    }

    private function supplierActivationStatus(): string
    {
        return (string) $this->db->pdo()->query('SELECT accounting_activation_status FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();
    }

    private function openingEntryCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening'"
        )->fetchColumn();
    }

    private function openingEntryId(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
    }

    private function account701DifferenceCents(int $entryId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END), 0) * 100) AS SIGNED)
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE jel.entry_id = {$entryId} AND coa.account_code = '701'"
        )->fetchColumn();
    }

    /**
     * ⚠️ Regrese ze sdíleného hostingu: worker se spouštěl starším PHP, umřel
     * na první řádce composeru a job zůstal `queued`. Obrazovka aktivace pak
     * hlásila „Čeká" — a protože prošlost se počítala až od čtvrt hodiny,
     * trvalo to čtvrt hodiny. Nepřihlášený worker je jistota, ne pomalý běh.
     */
    public function testUnclaimedJobIsFailedSoonerThanAStalledOne(): void
    {
        // Unikátní index pustí k jednomu dodavateli jen jeden aktivní job,
        // takže se obě situace zkoušejí po sobě, ne vedle sebe.
        $queued = $this->jobs->create($this->supplierId, 'dry_run', [], 1);
        $this->age($queued, 3);

        self::assertSame(1, $this->jobs->reapStale($this->supplierId));
        $dead = $this->jobs->find($queued, $this->supplierId);
        self::assertSame('failed', $dead['status']);
        self::assertStringContainsString('nerozběhlo', (string) $dead['last_error']);

        // Běžící worker může nad velkou historií chvíli mlčet — ten se čeká dál.
        $running = $this->jobs->create($this->supplierId, 'dry_run', [], 1);
        $this->db->pdo()->prepare("UPDATE accounting_backfill_jobs SET status = 'running', started_at = NOW() WHERE id = ?")
            ->execute([$running]);
        $this->age($running, 3);

        self::assertSame(0, $this->jobs->reapStale($this->supplierId));
        self::assertSame('running', $this->jobs->find($running, $this->supplierId)['status']);
    }

    public function testStalledRunningJobIsStillReapedAfterTheLongerGrace(): void
    {
        $running = $this->jobs->create($this->supplierId, 'dry_run', [], 1);
        $this->db->pdo()->prepare("UPDATE accounting_backfill_jobs SET status = 'running', started_at = NOW() WHERE id = ?")
            ->execute([$running]);
        $this->age($running, 30);

        self::assertSame(1, $this->jobs->reapStale($this->supplierId));
        self::assertSame('failed', $this->jobs->find($running, $this->supplierId)['status']);
    }

    /** Posune `updated_at` do minulosti — `NOW()` se v testu jinak neposouvá. */
    private function age(int $jobId, int $minutes): void
    {
        $this->db->pdo()
            ->prepare('UPDATE accounting_backfill_jobs SET updated_at = NOW() - INTERVAL ? MINUTE WHERE id = ?')
            ->execute([$minutes, $jobId]);
    }

    private function jobCount(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM accounting_backfill_jobs WHERE supplier_id = ' . $this->supplierId
        )->fetchColumn();
    }
}
