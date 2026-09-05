<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Charakterizační (golden baseline) testy pro Dashboard\SummaryAction — ZAMYKAJÍ
 * současný výstup per-currency agregací obratu PŘED přepisem SQL 10.6→11.8 (Epic
 * SQL fáze 2, R1 = invoices.effective_tax_date). Přepis COALESCE(tax_date, issue_date)
 * na generovaný sloupec musí být behavior-preserving → tyto testy musí dál procházet
 * beze změny čísel.
 *
 * Klíčové pokrytí COALESCE sémantiky: řádek s tax_date ≠ issue_date (DUZP jiný než
 * datum vystavení) a řádek s tax_date = NULL (fallback na issue_date). Právě tyto dva
 * případy definici gen-sloupce COALESCE(tax_date, issue_date) prověřují.
 *
 * Metoda: vše v transakci (rollback v tearDown → DB netknutá). Používá se DELTA proti
 * baseline (výstup metody před vložením vs. po), takže případná reálná data testovací
 * DB výsledek neovlivní. Roky 2000/2001 leží mimo rolling okna (CURDATE), rolling/
 * měsíční testy sázejí data relativně k dnešku. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class SummaryActionRevenueTest extends TestCase
{
    private Connection $db;
    private SummaryAction $action;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(SummaryAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country.');
        }
        $this->czkId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->eurId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Chybí CZK měna pro dodavatele.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
        // Deterministický režim plátce DPH → revenueCol = total_without_vat (net).
        $this->pdo->exec("UPDATE supplier SET is_vat_payer = 1 WHERE id = {$this->supplierId}");
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * revenueByYear: rok se určuje z YEAR(COALESCE(tax_date, issue_date)).
     * Prověřuje tax_date≠issue_date (přesun do jiného roku) i tax_date=NULL (fallback).
     */
    public function testRevenueByYearPinsCoalesceYearAttribution(): void
    {
        $base2001 = $this->yearTotal($this->revByYear(), 2001, 'CZK');
        $base2000 = $this->yearTotal($this->revByYear(), 2000, 'CZK');
        $baseCnt2001 = $this->yearCount($this->revByYear(), 2001, 'CZK');
        $client = $this->client('Golden Klient A');

        // Normální řádek 2001.
        $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 1210.0);
        // tax_date v 2001, issue_date v 2000 → COALESCE→tax_date→rok 2001 (ne 2000).
        $this->invoice($client, '2000-12-28', '2001-01-05', 500.0, 605.0);
        // tax_date NULL → COALESCE→issue_date→rok 2001.
        $this->invoice($client, '2001-03-10', null, 250.0, 302.5);
        // Kontrolní čistý řádek 2000 (nesmí do něj spadnout nic z 2001).
        $this->invoice($client, '2000-06-15', '2000-06-15', 999.0, 1208.79);

        $after = $this->revByYear();
        self::assertEqualsWithDelta($base2001 + 1750.0, $this->yearTotal($after, 2001, 'CZK'), 0.01, 'Rok 2001 CZK = 1000+500+250 (net, plátce).');
        self::assertEqualsWithDelta($base2000 + 999.0, $this->yearTotal($after, 2000, 'CZK'), 0.01, 'Rok 2000 CZK = jen kontrolní 999.');
        self::assertSame($baseCnt2001 + 3, $this->yearCount($after, 2001, 'CZK'), 'Do roku 2001 spadají 3 doklady.');
    }

    /** kpi(): per-currency this_year / prev_year / prev_year_ytd + počty. */
    public function testKpiPerCurrencyYtdTotals(): void
    {
        $baseThis = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'this_year');
        $basePrev = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'prev_year');
        $basePrevYtd = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'prev_year_ytd');
        $baseCnt = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'this_year_invoice_count');
        $client = $this->client('Golden Klient KPI');

        $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 1210.0);
        $this->invoice($client, '2001-08-20', '2001-08-20', 500.0, 605.0);
        $this->invoice($client, '2000-05-10', '2000-05-10', 800.0, 968.0);

        $after = $this->kpi(2001, 2000);
        self::assertEqualsWithDelta($baseThis + 1500.0, $this->kpiCur($after, 'CZK', 'this_year'), 0.01, 'this_year (2001) = 1000+500.');
        self::assertEqualsWithDelta($basePrev + 800.0, $this->kpiCur($after, 'CZK', 'prev_year'), 0.01, 'prev_year (2000) = 800.');
        self::assertEqualsWithDelta($basePrevYtd + 800.0, $this->kpiCur($after, 'CZK', 'prev_year_ytd'), 0.01, 'prev_year_ytd (2000, <= dnes−1r) = 800.');
        self::assertEqualsWithDelta($baseCnt + 2.0, $this->kpiCur($after, 'CZK', 'this_year_invoice_count'), 0.01, 'this_year_invoice_count = 2.');
    }

    /**
     * kpi().total_czk sčítá VŠECHNY měny přepočtené kurzem dokladu.
     *
     * Vzniklo z reklamace (2026-09): zákazník srovnával graf tržeb s Pohodou a chybělo
     * mu 44 918 Kč — přesně jeho cizoměnné faktury, které v CZK řadě nejsou a nikde
     * v UI nebylo celkové číslo. Test proto hlídá právě ten rozdíl: per_currency CZK
     * naroste JEN o korunovou fakturu, total_czk o obě.
     */
    public function testKpiTotalCzkSumsAllCurrencies(): void
    {
        if ($this->eurId === 0) {
            self::markTestSkipped('Bez EUR měny nelze ověřit součet napříč měnami.');
        }
        $baseTotal = (float) $this->kpi(2001, 2000)['total_czk']['this_year'];
        $baseCzk = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'this_year');
        $client = $this->client('Golden Klient TotalCzk');

        // CZK 1000 → 1000 Kč; EUR 100 × kurz 25 → 2500 Kč. Dohromady 3500 Kč.
        $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 1210.0, $this->czkId, 1.0);
        $this->invoice($client, '2001-07-15', '2001-07-15', 100.0, 121.0, $this->eurId, 25.0);

        $after = $this->kpi(2001, 2000);
        self::assertEqualsWithDelta(
            $baseCzk + 1000.0,
            $this->kpiCur($after, 'CZK', 'this_year'),
            0.01,
            'CZK řada zůstává v měně dokladu — eurová faktura do ní nepatří.',
        );
        self::assertEqualsWithDelta(
            $baseTotal + 3500.0,
            (float) $after['total_czk']['this_year'],
            0.01,
            'total_czk = CZK net + EUR net×kurz (1000 + 100×25).',
        );
        self::assertGreaterThanOrEqual(
            2,
            (int) $after['total_czk']['currency_count'],
            'currency_count hlásí, kolik měn se do součtu sešlo — UI podle něj dlaždici zobrazuje.',
        );
    }

    /** topClients(): přepočet na CZK přes exchange_rate + agregace přes měny na klienta. */
    public function testTopClientsCzkConversionForFreshClient(): void
    {
        $client = $this->client('Golden Klient Multi');
        // CZK: net 1000 → 1000 CZK. EUR: net 100 × kurz 25 → 2500 CZK. Součet 3500 CZK.
        $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 1210.0, $this->czkId, 1.0);
        if ($this->eurId > 0) {
            $this->invoice($client, '2001-07-15', '2001-07-15', 100.0, 121.0, $this->eurId, 25.0);
        }

        $row = $this->clientRow($this->topClients(2001), $client);
        self::assertNotNull($row, 'Čerstvý klient je v žebříčku top klientů roku 2001.');
        $expected = $this->eurId > 0 ? 3500.0 : 1000.0;
        self::assertEqualsWithDelta($expected, (float) $row['total_czk'], 0.01, 'total_czk = CZK net + EUR net×kurz.');
        self::assertSame($this->eurId > 0 ? 2 : 1, (int) $row['invoice_count'], 'Počet faktur klienta.');
        self::assertSame($this->eurId > 0 ? 'CZK,EUR' : 'CZK', (string) $row['currencies'], 'Seznam měn klienta (abecedně).');
    }

    /** revenueByMonth: měsíční bucket = DATE_FORMAT(COALESCE(tax_date, issue_date)). */
    public function testRevenueByMonthCurrentMonthBucket(): void
    {
        $ym = date('Y-m');
        $firstOfMonth = date('Y-m-01');
        $prevMonthDay = (new \DateTimeImmutable($firstOfMonth))->modify('-5 days')->format('Y-m-d');
        $base = $this->monthSlot($this->revByMonth(), 'CZK', $ym);
        $client = $this->client('Golden Klient Month');

        // issue=tax v aktuálním měsíci.
        $this->invoice($client, $firstOfMonth, $firstOfMonth, 300.0, 363.0);
        // issue v minulém měsíci, tax v aktuálním → COALESCE→aktuální měsíc.
        $this->invoice($client, $prevMonthDay, $firstOfMonth, 200.0, 242.0);

        $after = $this->monthSlot($this->revByMonth(), 'CZK', $ym);
        self::assertNotNull($after, "Slot pro aktuální měsíc {$ym} existuje.");
        self::assertEqualsWithDelta(($base ?? 0.0) + 500.0, $after, 0.01, 'Aktuální měsíc CZK = 300+200 (obě přes COALESCE).');
    }

    /** rolling12mRevenue: okno posledních 12 měsíců + předchozích 12 měsíců (dle COALESCE). */
    public function testRolling12mWindows(): void
    {
        $today = date('Y-m-d');
        $mid = (new \DateTimeImmutable('today'))->modify('-18 months')->format('Y-m-d');
        $base = $this->rolling();
        $baseTotal = $this->rollingVal($base, 'CZK', 'total');
        $basePrev = $this->rollingVal($base, 'CZK', 'prev_period_total');
        $client = $this->client('Golden Klient Rolling');

        $this->invoice($client, $today, $today, 400.0, 484.0);
        $this->invoice($client, $mid, $mid, 150.0, 181.5);

        $after = $this->rolling();
        self::assertEqualsWithDelta($baseTotal + 400.0, $this->rollingVal($after, 'CZK', 'total'), 0.01, 'Rolling 12m += dnešní 400.');
        self::assertEqualsWithDelta($basePrev + 150.0, $this->rollingVal($after, 'CZK', 'prev_period_total'), 0.01, 'Předchozích 12m (−18 měsíců) += 150.');
    }

    // ── reflection wrappery nad private metodami SummaryAction ──────────────────

    /** @return list<array<string,mixed>> */
    private function revByYear(): array
    {
        return $this->call('revenueByYear', [$this->pdo, $this->supplierId, true]);
    }

    /** @return array<string,mixed> */
    private function kpi(int $year, int $prevYear): array
    {
        return $this->call('kpi', [$this->pdo, $year, $prevYear, $this->supplierId, true]);
    }

    /** @return list<array<string,mixed>> */
    private function topClients(int $year): array
    {
        return $this->call('topClients', [$this->pdo, $year, $this->supplierId, true]);
    }

    /** @return list<array<string,mixed>> */
    private function revByMonth(): array
    {
        return $this->call('revenueByMonth', [$this->pdo, $this->supplierId, true]);
    }

    /** @return list<array<string,mixed>> */
    private function rolling(): array
    {
        return $this->call('rolling12mRevenue', [$this->pdo, $this->supplierId, true]);
    }

    private function call(string $method, array $args): mixed
    {
        $m = new ReflectionMethod($this->action, $method);
        return $m->invokeArgs($this->action, $args);
    }

    // ── extraktory z výstupních struktur ───────────────────────────────────────

    /** @param list<array<string,mixed>> $rows */
    private function yearTotal(array $rows, int $year, string $cur): float
    {
        foreach ($rows as $r) {
            if ((int) $r['year'] === $year && $r['currency'] === $cur) {
                return (float) $r['total'];
            }
        }
        return 0.0;
    }

    /** @param list<array<string,mixed>> $rows */
    private function yearCount(array $rows, int $year, string $cur): int
    {
        foreach ($rows as $r) {
            if ((int) $r['year'] === $year && $r['currency'] === $cur) {
                return (int) $r['invoice_count'];
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $kpi */
    private function kpiCur(array $kpi, string $cur, string $key): float
    {
        foreach ($kpi['per_currency'] as $r) {
            if ($r['currency'] === $cur) {
                return (float) $r[$key];
            }
        }
        return 0.0;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function clientRow(array $rows, int $clientId): ?array
    {
        foreach ($rows as $r) {
            if ((int) $r['client_id'] === $clientId) {
                return $r;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function monthSlot(array $rows, string $cur, string $ym): ?float
    {
        foreach ($rows as $r) {
            if ($r['currency'] !== $cur) {
                continue;
            }
            foreach ($r['months'] as $m) {
                if ($m['ym'] === $ym) {
                    return (float) $m['total'];
                }
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function rollingVal(array $rows, string $cur, string $key): float
    {
        foreach ($rows as $r) {
            if ($r['currency'] === $cur) {
                return (float) $r[$key];
            }
        }
        return 0.0;
    }

    // ── seed helpers ───────────────────────────────────────────────────────────

    private function client(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(
        int $clientId,
        string $issueDate,
        ?string $taxDate,
        float $net,
        float $gross,
        ?int $currencyId = null,
        float $exchangeRate = 1.0,
        string $status = 'issued',
        string $type = 'invoice',
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, 'GOLD-' . uniqid(), $type, $clientId, $issueDate, $taxDate, $issueDate,
            $currencyId ?? $this->czkId, $exchangeRate, $net, round($gross - $net, 2), $gross, $status, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
