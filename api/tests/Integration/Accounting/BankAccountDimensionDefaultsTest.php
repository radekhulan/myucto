<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionDefaultsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\System\GlobalSeedTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Výchozí dimenze vlastního bankovního účtu (migrace 2233): řádek banky a u pohybu bez
 * dokladu (úrok, srážková daň) i protiúčet dostanou při zaúčtování dimenze účtu, na kterém
 * pohyb leží. Saldokonto hrazených dokladů a 261 převodu je nedostanou. Pořadí typ po typu:
 * dimenze pohybu > hrazené doklady > výchozí hodnota pravidla dimenzí > výchozí dimenze
 * účtu. Vše v jedné transakci nad izolovanými firmami, tearDown rollbackne.
 */
#[Group('integration')]
final class BankAccountDimensionDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2096;
    private const SAVINGS = '3000000004';
    private const CURRENT = '1000000005';

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private DimensionDefaultsAction $action;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $userId = 0;
    private int $projectType = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->action = $container->get(DimensionDefaultsAction::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id IN (?, ?)")
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->dimensions->setEnabled($this->otherSupplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
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
    }

    // ── uložení, izolace a API ───────────────────────────────────────────────

    public function testSaveReadAndCascadeOnAccountDelete(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $center = $this->value($this->centerType, 'BA-C');
        $saved = $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);
        self::assertSame([$this->centerType => $center], $saved);
        self::assertSame($saved, $this->dimensions->entityDefaults($this->supplierId, 'bank_account', $account));

        $this->assertDimensionError('not_found', fn () => $this->dimensions->entityDefaults($this->otherSupplierId, 'bank_account', $account));
        $this->assertDimensionError('not_found', fn () => $this->dimensions->saveEntityDefaults($this->otherSupplierId, 'bank_account', $account, []));

        $this->db->pdo()->prepare('DELETE FROM supplier_bank_accounts WHERE id = ?')->execute([$account]);
        $left = $this->db->pdo()->prepare('SELECT COUNT(*) FROM dimension_defaults WHERE supplier_bank_account_id = ?');
        $left->execute([$account]);
        self::assertSame(0, (int) $left->fetchColumn(), 'Smazání účtu výchozí dimenze uklidí.');
    }

    public function testRouteNeedsAccountingAndDoubleEntry(): void
    {
        $map = new RoutePermissionMap();
        $read = $map->match('GET', '/api/accounting/bank-accounts/5/dimensions');
        $write = $map->match('PUT', '/api/accounting/bank-accounts/5/dimensions');
        self::assertSame(['accounting', AccessLevel::READ], [$read?->key, $read?->minimum]);
        self::assertSame(['accounting', AccessLevel::WRITE], [$write?->key, $write?->minimum]);

        $account = $this->ownAccount(self::SAVINGS);
        $center = $this->value($this->centerType, 'BA-API');
        $denied = $this->action->saveBankAccount($this->request('PUT', $this->supplierId, ['accounting' => 1], ['dimensions' => [$this->centerType => $center]]), $this->response(), ['id' => (string) $account]);
        self::assertSame(403, $denied->getStatusCode(), 'Čtenář účetnictví výchozí dimenze účtu neuloží.');

        $saved = $this->action->saveBankAccount($this->request('PUT', $this->supplierId, ['accounting' => 2], ['dimensions' => [$this->centerType => $center]]), $this->response(), ['id' => (string) $account]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $read = $this->action->getBankAccount($this->request('GET', $this->supplierId, ['accounting' => 1]), $this->response(), ['id' => (string) $account]);
        self::assertEquals([(string) $this->centerType => $center], $this->json($read)['dimensions']);

        $foreign = $this->action->getBankAccount($this->request('GET', $this->otherSupplierId, ['accounting' => 1]), $this->response(), ['id' => (string) $account]);
        self::assertSame(404, $foreign->getStatusCode(), 'Účet cizí firmy neexistuje.');

        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $taxEvidence = $this->action->getBankAccount($this->request('GET', $this->supplierId, ['accounting' => 1]), $this->response(), ['id' => (string) $account]);
        self::assertSame(403, $taxEvidence->getStatusCode());
        self::assertSame('wrong_accounting_mode', $this->json($taxEvidence)['error']['code'] ?? null);
        $taxEvidenceSave = $this->action->saveBankAccount($this->request('PUT', $this->supplierId, ['accounting' => 2], ['dimensions' => []]), $this->response(), ['id' => (string) $account]);
        self::assertSame(403, $taxEvidenceSave->getStatusCode(), 'V daňové evidenci se výchozí dimenze účtu neukládají.');
        self::assertSame('wrong_accounting_mode', $this->json($taxEvidenceSave)['error']['code'] ?? null);
        self::assertSame([$this->centerType => $center], $this->dimensions->entityDefaults($this->supplierId, 'bank_account', $account));
    }

    public function testResetKeepingSupplierKeepsBankAccountDefaultsOnly(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $vendor = (int) $this->db->pdo()->query('SELECT vendor_id FROM purchase_invoices WHERE id = ' . $this->advancePurchase('BA-RESET', 100.00))->fetchColumn();
        $center = $this->value($this->centerType, 'BA-RST');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->centerType => $center]);

        $mode = GlobalSeedTables::resetKeepUsersSupplier(false);
        self::assertNotContains('dimension_defaults', $mode['keep'], 'Výchozí dimenze klienta reset maže.');
        self::assertArrayHasKey('dimension_defaults', $mode['partial'], 'Výchozí dimenze ponechaného účtu reset nesmaže celé.');
        // reset.php maže smíšenou tabulku přes všechny firmy; tady jen nad testovací firmou.
        $this->db->pdo()->prepare("DELETE FROM dimension_defaults WHERE supplier_id = ? AND ({$mode['partial']['dimension_defaults']})")
            ->execute([$this->supplierId]);

        self::assertSame([$this->centerType => $center], $this->dimensions->entityDefaults($this->supplierId, 'bank_account', $account));
        self::assertSame([], $this->dimensions->entityDefaults($this->supplierId, 'client', $vendor));
    }

    // ── účtování ─────────────────────────────────────────────────────────────

    public function testInterestWithoutDocumentPassesRequiredRuleOnlyWithAccountDefault(): void
    {
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '5, 6', 'enforcement' => 'error',
        ]);
        $account = $this->ownAccount(self::SAVINGS);
        $interest = $this->transaction(self::SAVINGS, 120.00);

        try {
            $this->postInterest($interest);
            self::fail('Bez výchozí dimenze účtu pohyb bez dokladu povinnou dimenzi nemá.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }

        $center = $this->value($this->centerType, 'BA-SAV');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);
        $entryId = $this->postInterest($interest);
        self::assertSame([$center], $this->centersOf($entryId), 'Úrok i banka nesou středisko účtu.');

        $origins = $this->assignments->entryLineOrigins($this->supplierId, $entryId);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            self::assertSame(
                ['origin' => 'entity_default', 'ref' => 'bank_account:' . $account],
                $origins[$line['id']][$this->centerType] ?? null,
                'Původ hodnoty ukazuje na bankovní účet.',
            );
        }
    }

    public function testTwoAccountsGiveSameMovementTypeTheirOwnValue(): void
    {
        $savings = $this->ownAccount(self::SAVINGS);
        $current = $this->ownAccount(self::CURRENT);
        $a = $this->value($this->centerType, 'BA-A');
        $b = $this->value($this->centerType, 'BA-B');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $savings, [$this->centerType => $a]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $current, [$this->centerType => $b]);

        self::assertSame([$a], $this->centersOf($this->postInterest($this->transaction(self::SAVINGS, 120.00))));
        self::assertSame([$b], $this->centersOf($this->postInterest($this->transaction(self::CURRENT, 120.00))));
    }

    public function testMovementOwnDimensionWinsOverAccountDefault(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $accountCenter = $this->value($this->centerType, 'BA-ACC');
        $accountProject = $this->value($this->projectType, 'BA-PRJ');
        $ownCenter = $this->value($this->centerType, 'BA-OWN');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $accountCenter, $this->projectType => $accountProject]);
        $tx = $this->transaction(self::SAVINGS, 120.00);
        $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $tx, [$this->centerType => $ownCenter], []);

        $lines = $this->linesByAmount($this->postInterest($tx));
        self::assertEquals([$this->projectType => $accountProject, $this->centerType => $ownCenter], $lines['credit|120.00']['dims'],
            'Dimenze pohybu přebije účet, chybějící typ doplní účet.');
    }

    public function testPaidDocumentWinsOverAccountDefault(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $accountCenter = $this->value($this->centerType, 'BA-ACC2');
        $accountProject = $this->value($this->projectType, 'BA-PRJ2');
        $invoiceCenter = $this->value($this->centerType, 'BA-INV');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $accountCenter, $this->projectType => $accountProject]);
        $purchase = $this->advancePurchase('BA-PF', 500.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $invoiceCenter], []);
        $tx = $this->transaction(self::SAVINGS, -500.00);
        $this->match($tx, [$purchase => 500.00]);

        $lines = $this->linesByAmount($this->postPayment($tx, [500.00]));
        self::assertEquals([$this->centerType => $invoiceCenter], $lines['debit|500.00']['dims'], 'Saldokonto nese jen dimenze faktury.');
        self::assertEquals([$this->projectType => $accountProject, $this->centerType => $invoiceCenter], $lines['credit|500.00']['dims'],
            'Na řádku banky středisko faktury přebije účet, projekt doplní účet.');
    }

    public function testTypeSplitAcrossPaidDocumentsKeepsSplitAndOthersGetAccountDefault(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $accountCenter = $this->value($this->centerType, 'BA-ACC3');
        $accountProject = $this->value($this->projectType, 'BA-PRJ3');
        $a = $this->value($this->centerType, 'BA-SA');
        $b = $this->value($this->centerType, 'BA-SB');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $accountCenter, $this->projectType => $accountProject]);
        $pfA = $this->advancePurchase('BA-PF-A', 600.00);
        $pfB = $this->advancePurchase('BA-PF-B', 400.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfA, [$this->centerType => $a], []);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfB, [$this->centerType => $b], []);
        $tx = $this->transaction(self::SAVINGS, -1_000.00);
        $this->match($tx, [$pfA => 600.00, $pfB => 400.00]);

        $lines = $this->linesByAmount($this->postPayment($tx, [600.00, 400.00]));
        self::assertEquals([$this->centerType => $a], $lines['debit|600.00']['dims']);
        self::assertEquals([$this->centerType => $b], $lines['debit|400.00']['dims']);
        self::assertEquals([$this->projectType => $accountProject], $lines['credit|1000.00']['dims'],
            'Středisko, ve kterém se faktury liší, účet na řádku banky nedoplní.');
        self::assertEqualsWithDelta([$a => 0.6, $b => 0.4], $lines['credit|1000.00']['splits'][$this->centerType] ?? [], 1e-9,
            'Banka nese středisko jako rozpad v poměru alokací.');
    }

    public function testPaidDocumentWithoutTypeGetsAccountDefaultOnlyOnBankLine(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $accountCenter = $this->value($this->centerType, 'BA-ACC4');
        $a = $this->value($this->centerType, 'BA-PA');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $accountCenter]);
        $pfA = $this->advancePurchase('BA-PF-C', 600.00);
        $pfB = $this->advancePurchase('BA-PF-D', 400.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfA, [$this->centerType => $a], []);
        $tx = $this->transaction(self::SAVINGS, -1_000.00);
        $this->match($tx, [$pfA => 600.00, $pfB => 400.00]);

        $lines = $this->linesByAmount($this->postPayment($tx, [600.00, 400.00]));
        self::assertEquals([$this->centerType => $a], $lines['debit|600.00']['dims']);
        self::assertEquals([], $lines['debit|400.00']['dims'], 'Saldokonto faktury bez střediska středisko účtu nedostane.');
        self::assertEquals([$this->centerType => $accountCenter], $lines['credit|1000.00']['dims'], 'Řádek banky ano.');
    }

    public function testMovementOnAccountWithoutDefaultsStaysWithoutDimensions(): void
    {
        $this->ownAccount(self::SAVINGS);
        $other = $this->ownAccount(self::CURRENT);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $other, [$this->centerType => $this->value($this->centerType, 'BA-OTHER')]);

        $entryId = $this->postInterest($this->transaction(self::SAVINGS, 120.00));
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Výchozí dimenze jiného účtu se nepoužijí.');
    }

    public function testInactiveAccountDoesNotApplyItsDefaults(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $this->value($this->centerType, 'BA-OFF')]);
        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ?')->execute([$account]);

        $entryId = $this->postInterest($this->transaction(self::SAVINGS, 120.00));
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId),
            'Neaktivní účet se nepáruje stejně jako u analytiky 221, jeho výchozí dimenze se nepoužijí.');
    }

    public function testArchivedCreditCardAccountStillAppliesItsDefaults(): void
    {
        $card = $this->ownAccount(self::SAVINGS, 'credit_card');
        $center = $this->value($this->centerType, 'BA-CC');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $card, [$this->centerType => $center]);
        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ?')->execute([$card]);

        self::assertSame([$center], $this->centersOf($this->postInterest($this->transaction(self::SAVINGS, 120.00))),
            'Úvěrový účet kreditní karty se páruje i archivovaný (jako analytika 231), jeho výchozí dimenze platí dál.');
    }

    public function testRestampAfterPaidDocumentChangeKeepsAccountDefault(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $accountProject = $this->value($this->projectType, 'BA-PRJ5');
        $first = $this->value($this->centerType, 'BA-R1');
        $moved = $this->value($this->centerType, 'BA-R2');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->projectType => $accountProject]);
        $purchase = $this->advancePurchase('BA-PF-R', 500.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $first], []);
        $tx = $this->transaction(self::SAVINGS, -500.00);
        $this->match($tx, [$purchase => 500.00]);
        $entryId = $this->postPayment($tx, [500.00]);

        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $moved], []);
        $lines = $this->linesByAmount($entryId);
        self::assertEquals([$this->centerType => $moved], $lines['debit|500.00']['dims']);
        self::assertEquals([$this->projectType => $accountProject, $this->centerType => $moved], $lines['credit|500.00']['dims'],
            'Přerazítkování úhrady po změně faktury drží na řádku banky projekt z účtu.');
    }

    public function testSavingDefaultsDoesNotRestampPostedMovement(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $entryId = $this->postInterest($this->transaction(self::SAVINGS, 120.00));
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $this->value($this->centerType, 'BA-LATE')]);
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Zaúčtované pohyby se samy nepřepisují.');
    }

    public function testOwnTransferLeavesTransitAccountWithoutAccountDimensions(): void
    {
        $savings = $this->ownAccount(self::SAVINGS);
        $current = $this->ownAccount(self::CURRENT);
        $a = $this->value($this->centerType, 'BA-TA');
        $b = $this->value($this->centerType, 'BA-TB');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $savings, [$this->centerType => $a]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $current, [$this->centerType => $b]);

        $out = $this->linesByAccount($this->postLines($this->transaction(self::SAVINGS, -1_000.00), [
            ['account_code' => '261', 'side' => 'debit', 'amount' => 1_000.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1_000.00],
        ]));
        $in = $this->linesByAccount($this->postLines($this->transaction(self::CURRENT, 1_000.00), [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1_000.00],
            ['account_code' => '261', 'side' => 'credit', 'amount' => 1_000.00],
        ]));

        self::assertSame([$this->centerType => $a], $out['221|credit']);
        self::assertSame([$this->centerType => $b], $in['221|debit']);
        self::assertSame([], $out['261|debit'], 'Peníze na cestě dimenzi odesílajícího účtu nedostanou.');
        self::assertSame([], $in['261|credit'], 'Peníze na cestě dimenzi přijímajícího účtu nedostanou.');
        self::assertSame([], $this->transitBalanceByCenter(), '261 se po středisku vyrovná.');
    }

    public function testIssuedInvoicePaymentKeepsReceivableWithoutAccountDimension(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $center = $this->value($this->centerType, 'BA-INC');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);
        $tx = $this->transaction(self::SAVINGS, 800.00);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET matched_invoice_id = ? WHERE id = ?')
            ->execute([$this->issuedInvoice('2096001', 800.00), $tx]);

        $lines = $this->linesByAccount($this->postLines($tx, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 800.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 800.00],
        ]));
        self::assertSame([$this->centerType => $center], $lines['221|debit'], 'Řádek banky nese středisko účtu.');
        self::assertSame([], $lines['311|credit'], 'Saldokonto hrazené faktury středisko účtu nedostane.');
    }

    public function testFeeWithoutDocumentGivesAccountDimensionToBankAndCounterLine(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $center = $this->value($this->centerType, 'BA-FEE');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);

        $lines = $this->linesByAccount($this->postLines($this->transaction(self::SAVINGS, -50.00), [
            ['account_code' => '568', 'side' => 'debit', 'amount' => 50.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 50.00],
        ]));
        self::assertSame([$this->centerType => $center], $lines['568|debit']);
        self::assertSame([$this->centerType => $center], $lines['221|credit']);
    }

    public function testRuleDefaultOnAccountWinsOverBankAccountDefaultOnApproval(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $ruleValue = $this->value($this->centerType, 'BA-RULE');
        $accountValue = $this->value($this->centerType, 'BA-ACCT');
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '568', 'enforcement' => 'none', 'default_value_id' => $ruleValue,
        ]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $accountValue]);
        $tx = $this->transaction(self::SAVINGS, -50.00, 'Poplatek za vedení účtu');
        $this->container->get(BankPostingRuleRepository::class)->insert($this->supplierId, [
            'name' => 'Poplatek', 'direction' => 'outgoing', 'message_contains' => 'poplatek za vedeni',
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'suggest',
            'is_active' => 1, 'priority' => 100, 'applies_currency' => 'CZK',
        ], $this->userId);
        $bank = $this->container->get(BankPostingService::class);
        $handled = $bank->handleTransaction($tx, $this->userId);
        $suggestion = (int) ($handled['suggestion_id'] ?? 0);
        self::assertGreaterThan(0, $suggestion, 'Pravidlo pohybu vytvoří návrh: ' . json_encode($handled));

        $entryId = $bank->approveSuggestion($this->supplierId, $suggestion, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $lines = $this->linesByAccount($entryId);
        self::assertSame([$this->centerType => $ruleValue], $lines['568|debit'], 'Výchozí hodnota pravidla na 568 má přednost před účtem.');
        $bankLine = array_values(array_filter($lines, static fn (string $key): bool => str_starts_with($key, '221'), ARRAY_FILTER_USE_KEY));
        self::assertSame([[$this->centerType => $accountValue]], $bankLine, 'Řádek banky dostane středisko účtu.');
    }

    public function testRestampAfterAccountDeactivationKeepsAccountDimension(): void
    {
        $account = $this->ownAccount(self::SAVINGS);
        $center = $this->value($this->centerType, 'BA-DEACT');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->centerType => $center]);
        $tx = $this->transaction(self::SAVINGS, 120.00);
        $entryId = $this->postInterest($tx);
        self::assertSame([$center], $this->centersOf($entryId));

        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ?')->execute([$account]);
        // Nová instance = jiný požadavek, bez cache shody účtu ze zaúčtování.
        (new DimensionStamper($this->db))->restamp($this->supplierId, 'bank', $tx);
        self::assertSame([$center], $this->centersOf($entryId), 'Přerazítkování po deaktivaci účtu dimenzi zaúčtovaného pohybu neodebere.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $lines */
    private function postLines(int $txId, array $lines): int
    {
        return (int) $this->posting->postDocument($this->supplierId, 'bank', $txId, $lines,
            ['entry_date' => self::YEAR . '-05-31', 'posted_by' => $this->userId]);
    }

    /** @return array<string,array<int,int>> kód účtu|strana => dimenze */
    private function linesByAccount(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.side, c.account_code FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ?'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line) {
            $d = $dims[(int) $line['id']] ?? [];
            ksort($d);
            $out[$line['account_code'] . '|' . $line['side']] = $d;
        }
        return $out;
    }

    /** @return array<string,float> středisko => zůstatek 261 (jen nenulové) */
    private function transitBalanceByCenter(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id, l.entry_id, l.side, l.amount FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND c.account_code LIKE '261%'"
        );
        $stmt->execute([$this->supplierId]);
        $balance = [];
        $dims = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $dims[(int) $r['entry_id']] ??= $this->assignments->entryLineDimensions($this->supplierId, (int) $r['entry_id']);
            $key = (string) ($dims[(int) $r['entry_id']][(int) $r['id']][$this->centerType] ?? '-');
            $balance[$key] = round(($balance[$key] ?? 0.0) + ($r['side'] === 'debit' ? 1 : -1) * (float) $r['amount'], 2);
        }
        return array_filter($balance, static fn (float $v): bool => abs($v) > 0.001);
    }

    private function issuedInvoice(string $number, float $total): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "odberatel@example.invalid", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Odběratel ' . $number, $countryId, $currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $date = self::YEAR . '-05-01';
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                                   currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                                   paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $number, $clientId, $date, $date, $date, $currencyId, $total, $total, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function ownAccount(string $number, string $kind = 'savings'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, '0100', '0100', 'CZK', ?, ?, 'manual', 1)"
        )->execute([$this->supplierId, 'Účet ' . $number, $number, ltrim($number, '0'), $kind]);
        return (int) $pdo->lastInsertId();
    }

    private function transaction(string $account, float $amount, string $description = 'Pohyb na vlastním účtu'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count, matched_count)
             VALUES (?, ?, ?, ?, '0100', 'CZK', ?, 1, 0)"
        )->execute([$this->supplierId, 'bad-' . uniqid() . '.gpc', sha1(uniqid('', true)), $account, self::YEAR . '-05-31']);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$statementId, self::YEAR . '-05-31', $amount, $description]);
        return (int) $pdo->lastInsertId();
    }

    private function postInterest(int $txId): int
    {
        return (int) $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 120.00],
            ['account_code' => '662', 'side' => 'credit', 'amount' => 120.00],
        ], ['entry_date' => self::YEAR . '-05-31', 'posted_by' => $this->userId]);
    }

    /** Zálohová přijatá faktura: úhrada jde na 314 bez zaúčtovaného předpisu. */
    private function advancePurchase(string $number, float $total): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "dodavatel@example.invalid", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, 'Dodavatel ' . $number, $countryId, $currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $date = self::YEAR . '-05-01';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, created_by)
             VALUES (?, ?, ?, "advance", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $currencyId, $total, $total, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<int,float> $allocations přijatá faktura => částka */
    private function match(int $txId, array $allocations): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, 'manual')"
        );
        foreach ($allocations as $purchaseId => $amount) {
            $stmt->execute([$this->supplierId, $txId, $purchaseId, $amount]);
        }
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$txId]);
    }

    /** @param list<float> $allocations řádky 314 MD, banka D za součet */
    private function postPayment(int $txId, array $allocations): int
    {
        $lines = array_map(static fn (float $a): array => ['account_code' => '314', 'side' => 'debit', 'amount' => $a], $allocations);
        $lines[] = ['account_code' => '221', 'side' => 'credit', 'amount' => array_sum($allocations)];
        return (int) $this->posting->postDocument($this->supplierId, 'bank', $txId, $lines,
            ['entry_date' => self::YEAR . '-05-31', 'posted_by' => $this->userId]);
    }

    /** @return list<int|null> hodnoty střediska na řádcích zápisu (unikátní) */
    private function centersOf(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $out[] = $dims[$line['id']][$this->centerType] ?? null;
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @return array<string,array{dims:array<int,int>, splits:array<int,array<int,float>>}> strana|částka => dimenze */
    private function linesByAmount(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $d = $dims[$line['id']] ?? [];
            ksort($d);
            $out[$line['side'] . '|' . number_format((float) $line['amount'], 2, '.', '')] = [
                'dims' => $d,
                'splits' => $splits[$line['id']] ?? [],
            ];
        }
        return $out;
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    private function assertDimensionError(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Očekávána DimensionException {$code}.");
        } catch (DimensionException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @param array<string,int> $permissions
     * @param array<string,mixed>|null $body
     */
    private function request(string $method, int $supplierId, array $permissions, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $permissions));
        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
