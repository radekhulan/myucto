<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\BankProposalRepository;
use MyInvoice\Service\Property\Payments\{PaymentException, UnitPaymentResolver};
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Tests\Support\{BankProposalFixture, ChargeIssuanceFixture};
use PHPUnit\Framework\TestCase;

/** Párování bankovních pohybů na jednotky SVJ: rozpoznání, fronta, plán a potvrzení. */
final class BankProposalTest extends TestCase
{
    use ChargeIssuanceFixture, BankProposalFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testVariableSymbolProposalIsConfirmedThroughTheManualWorkflow(): void
    {
        $case = $this->postedDebts($this->db, $this->f, ['U05']);
        $supplier = $this->f['supplier'];
        $tx = $this->incoming($this->db, $supplier, bcdiv($case['totals']['U05'], '100', 2), ['variable_symbol' => '0000000005']);
        $service = $this->proposalService($this->db);
        self::assertSame(1, $service->afterImport($supplier, [$tx]));

        $repository = new BankProposalRepository($this->db);
        $stored = $repository->find($supplier, $tx);
        self::assertSame('identified', $stored['status']);
        self::assertSame($this->f['units']['U05'], $stored['unit_id']);
        self::assertSame(100, $stored['score']);
        self::assertContains('payer_not_proven', $stored['resolution']['reasons']);
        self::assertSame($tx, $repository->queue($supplier)['items'][0]['bank_transaction_id']);

        $plan = $service->plan($supplier, $tx);
        self::assertNull($plan['review']);
        self::assertSame('ready', $plan['preview']['status']);
        $part = $plan['request']['parts'][0]['request'];
        self::assertSame([$case['debts']['U05']], $part['debts']);
        self::assertSame($case['totals']['U05'], $part['amount_cents']);
        self::assertSame($stored['resolution']['debtor_id'], $part['payment']['debtor_id']);

        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $result = $facade->confirm($supplier, $plan['request'], $plan['preview']['preview_hash'], 'bank-proposal-confirm', $this->f['actor']);
        self::assertSame($case['totals']['U05'], $result['settled_cents']);
        self::assertSame([], $repository->queue($supplier)['items'], 'Used transaction leaves the queue without touching the proposal.');
        $links = $repository->links($supplier, [$tx]);
        self::assertSame($result['id'], $links[$tx]['workflow_id']);
        self::assertArrayNotHasKey('proposal', $links[$tx]);
        self::assertSame(0, $service->afterImport($supplier, [$tx]), 'Repeated import must not propose a used transaction.');
        self::assertSame('used', $service->plan($supplier, $tx)['status']);
    }

    public function testStatementImportOfSvjCreatesProposalWithoutPosting(): void
    {
        $supplier = $this->f['supplier'];
        $parsed = (new \MyInvoice\Service\Bank\GpcParser())->parse(self::gpc('2000000042', '001', [['amount_cents' => '12500', 'vs' => '5', 'ref' => '51001']]));
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $result = $container->get(\MyInvoice\Service\Bank\StatementImporter::class)->importParsedPdf($parsed, 'synthetic-pdf-' . bin2hex(random_bytes(4)), 'synthetic.pdf', null, null, $supplier);
        self::assertSame(1, $result['transactions']);
        $q = $this->db->pdo()->prepare('SELECT id FROM bank_transactions WHERE statement_id=?');
        $q->execute([$result['statement_id']]);
        $tx = (int) $q->fetchColumn();
        $proposal = (new BankProposalRepository($this->db))->find($supplier, $tx);
        self::assertSame('identified', $proposal['status']);
        self::assertSame($this->f['units']['U05'], $proposal['unit_id']);
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_sources WHERE bank_transaction_id=?');
        $q->execute([$tx]);
        self::assertSame(0, (int) $q->fetchColumn(), 'Import only proposes, it never confirms.');
    }

    public function testOverpaymentAllocatesOpenDebtAndLeavesRemainderForDecision(): void
    {
        $case = $this->postedDebts($this->db, $this->f, ['U05']);
        $amount = bcadd($case['totals']['U05'], '5000', 0);
        $tx = $this->incoming($this->db, $this->f['supplier'], bcdiv($amount, '100', 2), ['variable_symbol' => '5']);
        $plan = $this->proposalService($this->db)->plan($this->f['supplier'], $tx);
        self::assertSame('ready', $plan['preview']['status']);
        self::assertSame($case['totals']['U05'], $plan['request']['parts'][0]['request']['amount_cents']);
        self::assertSame('5000', $plan['remaining_cents']);
        self::assertSame('5000', $plan['preview']['snapshot']['available_cents']);
    }

    public function testSharedPayerAccountNeverPicksOneOfTheUnits(): void
    {
        $owner = $this->f['states']['U03']['ownerships'][0]['members'][0];
        $this->db->pdo()->prepare("INSERT INTO client_bank_accounts (supplier_id,client_id,account_number,bank_code,account_key,bank_key,source_manual) VALUES (?,?,'1000000005','0100','1000000005','0100',1)")
            ->execute([$this->f['supplier'], $owner]);
        $tx = $this->incoming($this->db, $this->f['supplier'], '500.00', ['counterparty_account' => '0000001000000005', 'counterparty_bank' => '0100']);
        $resolution = (new UnitPaymentResolver($this->db))->resolveMany($this->f['supplier'], (new BankProposalRepository($this->db))->freeIncoming($this->f['supplier'], [$tx]))[$tx];
        self::assertSame('review_required', $resolution['status']);
        self::assertSame(['multiple_units'], $resolution['reasons']);
        self::assertNull($resolution['unit_id']);
        self::assertEqualsCanonicalizing([$this->f['units']['U03'], $this->f['units']['U04']], array_column($resolution['candidates'], 'unit_id'));
        $service = $this->proposalService($this->db);
        self::assertSame('unit_required', $service->plan($this->f['supplier'], $tx)['review']['code']);
        $chosen = $service->plan($this->f['supplier'], $tx, ['unit_id' => $this->f['units']['U03']]);
        self::assertSame($this->f['units']['U03'], $chosen['resolution']['unit_id']);
        self::assertContains('unit_chosen_manually', $chosen['resolution']['reasons']);
        self::assertSame('no_open_debts', $chosen['review']['code']);
    }

    public function testCoOwnedUnitRequiresDebtorChoice(): void
    {
        $tx = $this->incoming($this->db, $this->f['supplier'], '100.00', ['variable_symbol' => '2']);
        $resolution = $this->proposalService($this->db)->plan($this->f['supplier'], $tx)['resolution'];
        self::assertSame('review_required', $resolution['status']);
        self::assertSame($this->f['units']['U02'], $resolution['unit_id']);
        self::assertContains('debtor_choice_required', $resolution['reasons']);
        self::assertNull($resolution['debtor_id']);
        try {
            $this->proposalService($this->db)->plan($this->f['supplier'], $tx, ['debtor_id' => $this->f['clients'][1]]);
            self::fail('Debtor must be an owner of the chosen unit.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_debtor_not_owner', $e->errorCode);
        }
    }

    public function testIdentifiersAreEvaluatedAtThePaymentDate(): void
    {
        $tx = $this->incoming($this->db, $this->f['supplier'], '100.00', ['variable_symbol' => '5', 'posted_at' => '2025-12-31']);
        $rows = (new BankProposalRepository($this->db))->freeIncoming($this->f['supplier'], [$tx]);
        self::assertSame('unidentified', (new UnitPaymentResolver($this->db))->resolveMany($this->f['supplier'], $rows)[$tx]['status']);
        self::assertSame(0, $this->proposalService($this->db)->afterImport($this->f['supplier'], [$tx]), 'Profile is not effective before 2026.');
    }

    public function testForeignSupplierTransactionIsInvisible(): void
    {
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->f['supplier']);
        $tx = $this->incoming($this->db, $other, '100.00', ['variable_symbol' => '5']);
        $service = $this->proposalService($this->db);
        self::assertSame(0, $service->afterImport($this->f['supplier'], [$tx]));
        self::assertSame([], (new BankProposalRepository($this->db))->links($this->f['supplier'], [$tx]));
        try {
            $service->plan($this->f['supplier'], $tx);
            self::fail('Foreign transaction must not be planned.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_bank_transaction_unavailable', $e->errorCode);
        }
    }

    public function testRepeatedImportKeepsOneProposalAndOnlyBumpsVersionOnChange(): void
    {
        $tx = $this->incoming($this->db, $this->f['supplier'], '100.00', ['variable_symbol' => '5']);
        $service = $this->proposalService($this->db);
        $service->afterImport($this->f['supplier'], [$tx]);
        $service->afterImport($this->f['supplier'], [$tx, $tx]);
        $repository = new BankProposalRepository($this->db);
        $first = $repository->find($this->f['supplier'], $tx);
        self::assertSame(1, $first['row_version']);
        $changed = $first['resolution'];
        $changed['reasons'][] = 'synthetic_change';
        $repository->save($this->f['supplier'], [$changed]);
        self::assertSame(2, $repository->find($this->f['supplier'], $tx)['row_version']);
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_bank_proposals WHERE bank_transaction_id=?');
        $q->execute([$tx]);
        self::assertSame(1, (int) $q->fetchColumn());
    }

    public function testSupplierWithoutPropertyProfileReadsOnlyTheProfile(): void
    {
        $plain = $this->createIsolatedSupplier($this->db->pdo(), $this->f['supplier']);
        $ids = [];
        for ($i = 0; $i < 5; $i++) $ids[] = $this->incoming($this->db, $plain, '100.00', ['variable_symbol' => '5']);
        $pdo = $this->db->pdo();
        $status = static fn (string $name): int => (int) $pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(\PDO::FETCH_NUM)[1];
        $service = $this->proposalService($this->db);
        $selects = $status('Com_select');
        $writes = $status('Com_insert') + $status('Com_update');
        self::assertSame(0, $service->afterImport($plain, $ids));
        self::assertSame(1, $status('Com_select') - $selects, 'Only the profile is read.');
        self::assertSame($writes, $status('Com_insert') + $status('Com_update'));
    }
}
