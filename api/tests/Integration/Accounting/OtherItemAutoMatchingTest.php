<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\TransactionRematcher;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Automatické párování bankovních pohybů s ostatními pohledávkami a závazky
 * (StatementMatcher → OtherItemAutoMatcher, migrace 2239) ve firmě v daňové evidenci.
 * Syntetická firma s vlastním účtem výpisu, účet protistrany 1000000005/0100, rok 2099.
 */
#[Group('integration')]
final class OtherItemAutoMatchingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PARTNER_ACCOUNT = '1000000005';
    private const PARTNER_BANK = '0100';
    private const VS = '2099001';

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private StatementMatcher $matcher;
    private int $supplierId;
    private int $statementId;
    private string $ownAccount;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(OtherItemService::class);
        $this->schedules = $this->container->get(OtherItemScheduleService::class);
        $this->matcher = $this->container->get(StatementMatcher::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence', auto_match_other_items = 1 WHERE id = ?")
            ->execute([$this->supplierId]);
        [$this->ownAccount, $this->statementId] = $this->ownStatement($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testSureVariableSymbolAndAccountAllocatesOnImportAndRematchIsIdempotent(): void
    {
        $item = $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('unmatched', $result['status']);
        self::assertSame('other_item_allocated', $result['reason']);
        self::assertSame($item, $result['other_item_id']);
        self::assertSame('other_item_vs_account', $result['other_item_match']);
        self::assertSame([[1200.0, 'other_item_vs_account']], $this->allocations($txId));
        self::assertSame(['unmatched', 'other_item_allocated'], $this->txState($txId),
            'Pohyb zůstává nespárovaný s fakturou, důvod říká, že ho spotřebovala ostatní položka.');
        self::assertSame(1, $this->activityCount($item, 'other_item.payment_auto_matched'));

        $again = $this->matcher->matchBatch([$txId])[$txId];
        self::assertArrayNotHasKey('other_item_id', $again, 'Přepárování nic nového nepřiřadí.');
        self::assertSame([[1200.0, 'other_item_vs_account']], $this->allocations($txId));
        $single = $this->matcher->match($txId);
        self::assertSame('other_item_allocated', $single['reason']);
        self::assertCount(1, $this->allocations($txId));
    }

    public function testAccountAndNextInstallmentAllocatesOnlyWithinDueWindow(): void
    {
        $item = $this->confirmed(['counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK],
            [['2099-03-10', 1000], ['2099-04-10', 2000]]);
        // Každý na jiném výpisu: dva stejné odchozí pohyby na jednom výpisu rozhodne už párování
        // podle částky a data jako nejednoznačné a k ostatním položkám se nedostanou.
        $late = $this->transaction(-1000, '2099-05-20', null, true, $this->ownStatement($this->supplierId)[1]);
        $onTime = $this->transaction(-1000, '2099-03-14', null, true);

        $results = $this->matcher->matchBatch([$late, $onTime]);

        self::assertSame('other_item_candidates', $results[$late]['reason'], 'Mimo okno splatnosti jen nabídka ve Spárovat.');
        self::assertSame([], $this->allocations($late));
        self::assertSame('other_item_candidates', $this->txState($late)[1]);
        self::assertSame($item, $results[$onTime]['other_item_id'] ?? null);
        self::assertSame([[1000.0, 'other_item_account_installment']], $this->allocations($onTime));
        self::assertSame(['paid', 'unpaid'], array_column($this->items->installmentCoverage($this->supplierId, $item), 'status'));
    }

    public function testAmbiguousOrOverpaidCandidatesStayForManualChoice(): void
    {
        $account = self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK;
        $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => $account]);
        $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => $account, 'amount' => 900]);
        $ambiguous = $this->transaction(-500, '2099-03-12', self::VS, true);
        $single = $this->confirmed(['variable_symbol' => '2099002', 'counterparty_account' => $account]);
        $overpaid = $this->transaction(-1500, '2099-03-12', '2099002', true);

        $results = $this->matcher->matchBatch([$ambiguous, $overpaid]);

        self::assertSame('other_item_candidates', $results[$ambiguous]['reason']);
        self::assertSame('other_item_candidates', $results[$overpaid]['reason'], 'Přeplatek se nepřiřazuje.');
        self::assertSame([], $this->allocations($ambiguous));
        self::assertSame([], $this->allocations($overpaid));
        self::assertEqualsWithDelta(1200.0, (float) $this->items->get($this->supplierId, $single)['remaining_amount'], 0.001);
    }

    public function testIssuedInvoiceWinsOverOtherItemWithSameVariableSymbol(): void
    {
        $item = $this->confirmed(['side' => 'receivable', 'kind' => 'claim', 'variable_symbol' => self::VS,
            'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $invoice = $this->issuedInvoice(self::VS, 1200);
        $txId = $this->transaction(1200, '2099-03-12', self::VS, true);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('auto_exact', $result['status']);
        self::assertSame($invoice, $result['invoice_id']);
        self::assertSame([], $this->allocations($txId));
        self::assertEqualsWithDelta(1200.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);
    }

    public function testFlagOffKeepsPreviousResult(): void
    {
        $this->pdo->prepare('UPDATE supplier SET auto_match_other_items = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('unmatched', $result['status']);
        self::assertNotSame('other_item_allocated', $result['reason'] ?? null);
        self::assertNotSame('other_item_candidates', $result['reason'] ?? null);
        self::assertSame([], $this->allocations($txId));
    }

    public function testUnlinkedAllocationIsNotRestoredByRematch(): void
    {
        $item = $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);
        $this->matcher->matchBatch([$txId]);
        $allocation = (int) $this->items->allocations($this->supplierId, $item)[0]['id'];

        $this->items->unallocate($this->supplierId, $item, $allocation);
        $result = $this->container->get(TransactionRematcher::class)->rematch([$txId], null);

        self::assertSame(0, $result['newly_matched']);
        self::assertSame([], $this->allocations($txId), 'Zrušené přiřazení automatika neobnoví.');
    }

    public function testRematcherCountsOtherItemAllocationAsMatched(): void
    {
        $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);

        $result = $this->container->get(TransactionRematcher::class)->rematch([$txId], null);

        self::assertSame(1, $result['newly_matched']);
        self::assertSame(0, $result['still_unmatched']);
    }

    public function testReleaseReversesAllocationAndRematchDoesNotRestoreIt(): void
    {
        $item = $this->confirmed(['variable_symbol' => self::VS, 'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK]);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);
        $this->matcher->matchBatch([$txId]);

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $txId, BankTransactionReleaseService::MODE_UNMATCH);

        $stmt = $this->pdo->prepare('SELECT reversed_on IS NOT NULL FROM other_item_allocations WHERE bank_transaction_id = ?');
        $stmt->execute([$txId]);
        self::assertSame([1], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), 'Uvolnění pohybu přiřazení stornuje.');
        self::assertEqualsWithDelta(1200.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);
        $this->matcher->matchBatch([$txId]);
        self::assertSame([], $this->allocations($txId), 'Stornované přiřazení se automaticky neobnoví.');
    }

    public function testOtherCompanyItemIsNeverAllocated(): void
    {
        $other = $this->createIsolatedSupplier($this->pdo, (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence', auto_match_other_items = 1 WHERE id = ?")
            ->execute([$other]);
        $foreignItem = $this->confirmed(['variable_symbol' => self::VS,
            'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK], [], $other);
        $txId = $this->transaction(-1200, '2099-03-12', self::VS, true);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertArrayNotHasKey('other_item_id', $result);
        self::assertSame([], $this->allocations($txId));
        self::assertEqualsWithDelta(1200.0, (float) $this->items->get($other, $foreignItem)['remaining_amount'], 0.001);
    }

    public function testCounterpartyAccountOfConfirmedItemCanBeChanged(): void
    {
        $item = $this->confirmed(['variable_symbol' => self::VS]);
        $result = $this->items->updateCounterpartyAccount($this->supplierId, $item,
            ['counterparty_account' => '19-1000000005/0100']);

        self::assertSame(['19-1000000005', '0100'], [$result['item']['counterparty_account'], $result['item']['counterparty_bank_code']]);
        self::assertSame(['counterparty_account' => null, 'counterparty_bank_code' => null], $result['before']);

        $draft = (int) $this->items->create($this->supplierId, $this->input([]), null)['id'];
        try {
            $this->items->updateCounterpartyAccount($this->supplierId, $draft, ['counterparty_account' => '1000000005/0100']);
            self::fail('Koncept se upravuje celý, ne touto cestou.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame('counterparty_not_editable', $e->errorCode);
        }
        try {
            $this->items->updateCounterpartyAccount($this->supplierId, $item, ['counterparty_account' => '1000000006/0100']);
            self::fail('Neplatný účet je validační chyba.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame('invalid_counterparty_account', $e->errorCode);
        }
    }

    /** @return array{0:string,1:int} vlastní účet firmy a výpis na něm */
    private function ownStatement(int $supplierId): array
    {
        $account = '2099' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
             VALUES (?, "CZK", "Kč", "Kč", "koruna česká", "Czech koruna", ?, "0800")'
        )->execute([$supplierId, $account]);
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, "0800", ?, "CZK")'
        )->execute([$supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), $account, '2099-03-31']);
        return [$account, (int) $this->pdo->lastInsertId()];
    }

    /**
     * @param array<string,mixed> $changes
     * @param list<array{0:string,1:float|int}> $installments
     */
    private function confirmed(array $changes, array $installments = [], ?int $supplierId = null): int
    {
        $supplierId ??= $this->supplierId;
        if ($installments !== []) $changes['amount'] ??= array_sum(array_column($installments, 1));
        $id = (int) $this->items->create($supplierId, $this->input($changes), null)['id'];
        if ($installments !== []) {
            $this->schedules->setInstallments($supplierId, $id, array_map(
                static fn (array $row): array => ['due_on' => $row[0], 'amount' => $row[1]], $installments));
        }
        $this->items->post($supplierId, $id, null);
        return $id;
    }

    /** @param array<string,mixed> $changes */
    private function input(array $changes): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Syntetický úvěr',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-12-31', 'currency' => 'CZK', 'amount' => 1200,
        ], $changes);
    }

    private function transaction(float $amount, string $date, ?string $vs, bool $partnerAccount, ?int $statementId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, variable_symbol, counterparty_account,
                 counterparty_bank, counterparty_name, description)
             VALUES (?, ?, ?, "CZK", "unmatched", ?, ?, ?, "Syntetický partner", "Syntetická platba")'
        )->execute([$statementId ?? $this->statementId, $date, $amount, $vs,
            $partnerAccount ? '000000' . self::PARTNER_ACCOUNT : null, $partnerAccount ? self::PARTNER_BANK : null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function issuedInvoice(string $vs, float $total): int
    {
        $currency = (int) $this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language,
                currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický odběratel", "Test 1", "Praha", "11000", ?, "t@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $country, $currency]);
        $client = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code)
             VALUES (?, ?, "invoice", ?, "2099-03-01", "2099-03-01", "2099-03-15", ?, 0, ?, 0, ?, 0, "issued", "1")'
        )->execute([$this->supplierId, $vs, $client, $currency, $total, $total]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array{0:float,1:?string}> aktivní přiřazení pohybu (částka, kód automatické shody) */
    private function allocations(int $txId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT amount, auto_match_reason FROM other_item_allocations
              WHERE bank_transaction_id = ? AND reversed_on IS NULL ORDER BY id'
        );
        $stmt->execute([$txId]);
        return array_map(static fn (array $row): array => [(float) $row['amount'], $row['auto_match_reason']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0:string,1:?string} */
    private function txState(int $txId): array
    {
        $stmt = $this->pdo->prepare('SELECT match_status, match_reason FROM bank_transactions WHERE id = ?');
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [(string) $row['match_status'], $row['match_reason']];
    }

    private function activityCount(int $item, string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE entity_type = ? AND entity_id = ? AND action = ?');
        $stmt->execute(['other_item', $item, $action]);
        return (int) $stmt->fetchColumn();
    }
}
