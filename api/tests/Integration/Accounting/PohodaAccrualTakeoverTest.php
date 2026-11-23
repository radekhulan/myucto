<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualItemRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualReconciliationService;
use MyInvoice\Service\Accounting\Accrual\PohodaAccrualTakeoverService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převzetí časového rozlišení z POHODY nad syntetickými převzatými zápisy: ruční zápisy
 * řady xxCR, souhrnný počáteční stav 384, interní doklad ukončující dvě smlouvy a smlouva
 * vzniklá v roce převodu. Ověřuje rozpad PS (rekonstrukce z faktury a rytmu), navázání
 * převzatých zápisů, alokaci ukončení, položku nerozepsaného zůstatku, odsouhlasení na
 * haléř, idempotenci a to, že převzetí nic neúčtuje.
 */
#[Group('integration')]
final class PohodaAccrualTakeoverTest extends TestCase
{
    private const YEAR = 2092;

    private Connection $db;
    private PostingService $posting;
    private PohodaAccrualTakeoverService $takeover;
    private AccrualReconciliationService $reconciliation;
    private AccrualItemRepository $items;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->takeover = $container->get(PohodaAccrualTakeoverService::class);
        $this->reconciliation = $container->get(AccrualReconciliationService::class);
        $this->items = $container->get(AccrualItemRepository::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)')
            ->execute(['Převzetí ČR test s.r.o.', $czId, 'prevzeti-cr@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $container->get(AccountingPeriodRepository::class);
        $periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
                       VALUES (?, "Syntetický nájemce s.r.o.", "Test 1", "Praha", "11000", ?, "najemce@example.com", "cs", ?, 1, 0)')
            ->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        // A: smlouva 2090, 146 000 Kč, 100 Kč/den (1 460 dní do 30. 12. 2093), roční rozpuštění 2092.
        $this->invoice('SYN2401', '2090-01-01', 146_000.00);
        // B1, B2: smlouvy 2091 po 73 000 Kč (730 dní), rozpuštěné do 31. 3. a ukončené jedním dokladem.
        $this->invoice('SYN2402', '2091-01-01', 73_000.00);
        $this->invoice('SYN2403', '2091-01-01', 73_000.00);
        // C: smlouva roku převodu, 36 600 Kč od 10. 1. 2092 na 366 dní, měsíční rozpuštění.
        $c = $this->invoice('SYN2604', '2092-01-10', 36_600.00);
        $this->posting->postDocument($this->supplierId, 'invoice', $c, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36_600.00],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 36_600.00],
        ], ['entry_date' => '2092-01-10', 'posted' => true, 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        // Souhrnný počáteční stav 384: A 73 000 + B1 36 500 + B2 36 500 + nerozepsaný zbytek 1 234,56.
        $this->posting->postDocument($this->supplierId, 'opening', $periodId, [
            ['account_code' => '701', 'side' => 'debit', 'amount' => 147_234.56],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 147_234.56],
        ], ['entry_date' => '2092-01-01', 'description' => 'Počáteční stavy 2092 (převzato z Pohody)', 'posted' => true, 'user_id' => $this->userId]);

        $this->cr('90CR0001', 'SYN2401', '2092-12-31', 36_600.00);
        $this->cr('91CR0002', 'SYN2402', '2092-03-31', 9_100.00);
        $this->cr('91CR0003', 'SYN2403', '2092-03-31', 9_100.00);
        $this->cr('92CR0001', 'SYN2604', '2092-01-31', 2_200.00);
        $this->cr('92CR0001', 'SYN2604', '2092-02-29', 2_900.00);
        $this->manual('92IN0001', '2092-03-31', 'Interní doklady 92IN0001 — Vyřazení z časového rozlišení', '384', '641', 54_800.00);
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

    public function testTakeoverSplitsOpeningLinksEntriesAndReconcilesToTheCent(): void
    {
        $entriesBefore = $this->entryCount();
        $preview = $this->takeover->preview($this->supplierId);
        self::assertSame('2092-01-01', $preview['opening_date']);
        self::assertSame(4, $preview['summary']['items']);
        self::assertSame(1_234.56, $preview['summary']['opening_remainder']);
        $termination = $preview['terminations'][0];
        self::assertSame(['91CR0002' => 27_400.00, '91CR0003' => 27_400.00], $termination['allocations']);
        self::assertSame('641', $termination['counter_account_code']);

        $result = $this->takeover->apply($this->supplierId, $this->userId);
        self::assertSame(4, $result['created']);
        self::assertSame($entriesBefore, $this->entryCount(), 'Převzetí nesmí nic zaúčtovat.');

        $a = $this->item('pohoda:90CR0001');
        self::assertSame('yearly', $a['method']);
        self::assertSame('2090-01-01', $a['period_from']);
        self::assertSame('2093-12-30', $a['period_to']);
        self::assertSame(73_000.00, $a['opening_balance']);
        self::assertSame('active', $a['status']);
        self::assertSame('invoice', $a['source_type']);
        $schedule = $this->items->schedule($this->supplierId, $a['id']);
        self::assertSame('linked', $this->rowOn($schedule, '2092-12-31')['status']);
        self::assertSame(36_400.00, round(array_sum(array_column(array_filter($schedule, static fn (array $r): bool => $r['status'] === 'planned'), 'amount_planned')), 2));

        foreach (['pohoda:91CR0002', 'pohoda:91CR0003'] as $key) {
            $b = $this->item($key);
            self::assertSame('terminated', $b['status']);
            self::assertSame('2092-03-31', $b['terminated_on']);
            self::assertSame(36_500.00, $b['opening_balance']);
            self::assertSame(0.0, $this->balance($b['id'], '2092-12-31'));
        }

        $c = $this->item('pohoda:92CR0001');
        self::assertSame('monthly_days', $c['method']);
        self::assertSame('2093-01-09', $c['period_to']);
        self::assertNull($c['opening_balance']);
        self::assertSame(36_600.00 - 5_100.00, $this->balance($c['id'], '2092-12-31'));

        $ps = $this->item('pohoda:ps:384:2092-01-01');
        self::assertSame('manual', $ps['method']);
        self::assertSame(1_234.56, $this->balance($ps['id'], '2092-12-31'));

        foreach (['2092-01-01', '2092-03-31', '2092-06-30', '2092-12-31'] as $date) {
            $rec = $this->reconciliation->reconcile($this->supplierId, $date);
            self::assertSame(0.0, $rec['totals']['difference'], 'Odsouhlasení k ' . $date);
        }

        $again = $this->takeover->apply($this->supplierId, $this->userId);
        self::assertSame(0, $again['created'], 'Opakované převzetí nezakládá nové položky.');
        self::assertSame($entriesBefore, $this->entryCount());
        self::assertSame(0.0, $this->reconciliation->reconcile($this->supplierId, '2092-12-31')['totals']['difference']);
        // Storno převzatého zápisu (např. při ukončení v agendě) nemění rozpad počátečního stavu.
        $crEntry = (int) $this->db->pdo()->query("SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND document_no = '90CR0001'")->fetchColumn();
        $this->posting->reverse($this->supplierId, $crEntry, ['entry_date' => '2092-12-31', 'user_id' => $this->userId]);
        self::assertSame(1_234.56, $this->takeover->preview($this->supplierId)['summary']['opening_remainder']);
        $links = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM accrual_entry_links WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        $this->takeover->apply($this->supplierId, $this->userId);
        self::assertSame($links, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM accrual_entry_links WHERE supplier_id = ' . $this->supplierId)->fetchColumn());
    }

    /** Nerozepsaný zbytek PS klesne na nulu: položka zbytku se zruší a její vazba zmizí. */
    public function testRemainderDropsToZeroOnRepeatedTakeover(): void
    {
        $this->takeover->apply($this->supplierId, $this->userId);
        self::assertSame(0.0, $this->reconciliation->reconcile($this->supplierId, '2092-12-31')['totals']['difference']);
        // Druhá dávka převodu: zápis položky, jejíž doklad v datech chybí, rozpustí přesně nerozepsaný zbytek PS.
        $this->manual('89CR0009', '2092-05-31', 'Časové rozlišení 89CR0009 — Časové rozlišení daňového dokladu č. NEEXISTUJE1', '384', '602', 1_234.56);
        $preview = $this->takeover->preview($this->supplierId);
        self::assertSame(0.0, $preview['summary']['opening_remainder']);
        $this->takeover->apply($this->supplierId, $this->userId);
        $ps = $this->item('pohoda:ps:384:2092-01-01');
        $rec = $this->reconciliation->reconcile($this->supplierId, '2092-12-31');
        self::assertSame('cancelled', $ps['status'], 'Položka nerozepsaného zbytku bez zbytku se zruší.');
        self::assertSame(0.0, $this->balance($ps['id'], '2092-12-31'));
        self::assertSame(0.0, $rec['totals']['difference']);
    }


    /** Opakované převzetí nemění položku, kterou mezitím ukončila agenda. */
    public function testReapplyKeepsAgendaTermination(): void
    {
        $this->takeover->apply($this->supplierId, $this->userId);
        $c = $this->item('pohoda:92CR0001');
        $svc = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Accrual\AccrualPostingService::class);
        $svc->setToday('2092-12-31');
        $after = $svc->terminate($this->supplierId, $c['id'], ['date' => '2092-06-30', 'termination_account_code' => '602', 'reverse_future' => true, 'confirm_linked' => true], ['user_id' => $this->userId]);
        self::assertSame('terminated', $after['status']);
        $this->takeover->apply($this->supplierId, $this->userId);
        $again = $this->item('pohoda:92CR0001');
        self::assertSame('terminated', $again['status']);
        self::assertSame('2092-06-30', $again['terminated_on']);
    }

    private function invoice(string $number, string $date, float $base): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                                   total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "sent", "1", ?)'
        )->execute([$this->supplierId, $number, $this->clientId, $date, $date, $date, $this->currencyId, $base, $base, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cr(string $docNo, string $source, string $date, float $amount): void
    {
        $this->manual($docNo, $date, 'Časové rozlišení ' . $docNo . ' — Časové rozlišení daňového dokladu č. ' . $source, '384', '602', $amount);
    }

    private function manual(string $docNo, string $date, string $description, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'document_no' => $docNo, 'description' => $description, 'posted' => true, 'user_id' => $this->userId]);
    }

    /** @return array<string,mixed> */
    private function item(string $key): array
    {
        return $this->items->findByImportKey($this->supplierId, $key) ?? self::fail('Položka ' . $key . ' chybí.');
    }

    private function balance(int $itemId, string $date): float
    {
        $item = $this->items->find($this->supplierId, $itemId);
        $raw = $this->items->balances($this->supplierId, [$itemId], $date)[$itemId]['balance'] ?? 0.0;
        return round($raw * ($item['kind'] === 'deferred_revenue' ? -1 : 1), 2);
    }

    /** @param list<array<string,mixed>> $schedule @return array<string,mixed> */
    private function rowOn(array $schedule, string $due): array
    {
        foreach ($schedule as $r) {
            if ($r['due_on'] === $due && $r['kind'] === 'release') {
                return $r;
            }
        }
        self::fail('Řádek ' . $due . ' chybí.');
    }

    private function entryCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
    }
}
