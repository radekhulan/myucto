<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementBalanceService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výpis z bankovního API stažený v průběhu dne nenese konečný stav toho dne.
 *
 * Automatická synchronizace stahuje období do dneška a banka vrátí jako konečný zůstatek
 * stav v okamžiku stažení. Pozdější pohyb téhož dne ho změní, takže dva výpisy téhož dne
 * nesou různý „konečný“ stav (dřív `balance_conflict`), nebo jediný ranní stav
 * nesouhlasí s pohyby celého dne (dřív falešný `mismatch`).
 *
 * Syntetický účet a rok 2099, vše v transakci, která se vrátí.
 */
#[Group('integration')]
final class StatementBalanceIntradayTest extends TestCase
{
    private const ACCOUNT = '9990562432';
    private Connection $db;
    private PDO $pdo;
    private StatementBalanceService $service;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->service = new StatementBalanceService($this->db);
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testLaterDownloadOfSameDayIsNotBalanceConflict(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        $morning = $this->download('2099-10-31', '2099-10-31 00:00:00', '1000.00', '1000.00');
        $noon = $this->download('2099-10-31', '2099-10-31 10:15:00', '1000.00', '900.00', [['2099-10-31', '-100.00']]);

        $result = $this->service->summaries($this->supplierId, [$morning, $noon]);
        self::assertSame('confirmed', $result[$noon]['status']);
        self::assertEquals(1000, $result[$noon]['opening']);
        self::assertEquals(900, $result[$noon]['closing']);
        self::assertEquals(900, $result[$noon]['confirmed_closing']);
        self::assertSame($result[$noon], $this->service->summary($this->supplierId, $noon));
        self::assertSame($result[$morning], $this->service->summary($this->supplierId, $morning));
    }

    public function testMorningBalanceIsCompletedByMovementsArrivingNextDay(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        $morning = $this->download('2099-10-31', '2099-10-31 00:00:00', '1000.00', '1000.00');
        $this->download('2099-11-01', '2099-11-01 00:00:00', '1000.00', '900.00', [['2099-10-31', '-100.00']]);

        $result = $this->service->summary($this->supplierId, $morning);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(900, $result['closing']);
        self::assertEquals(0, $result['difference']);
    }

    public function testMissingMovementStillShowsDifference(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Banka hlásí 900, pohyb −100 ale v evidenci chybí.
        $noon = $this->download('2099-10-31', '2099-10-31 10:15:00', '1000.00', '900.00');

        $result = $this->service->summary($this->supplierId, $noon);
        self::assertSame('mismatch', $result['status']);
        self::assertEquals(-100, $result['difference']);
    }

    public function testDifferentMorningBalanceWithoutLaterMovementRemainsConflict(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        $this->download('2099-10-31', '2099-10-31 00:00:00', '1000.00', '1000.00');
        // Pozdější stažení hlásí 900, pohyb, který by rozdíl vysvětlil, ale v evidenci není.
        $noon = $this->download('2099-10-31', '2099-10-31 10:15:00', '1000.00', '900.00');

        self::assertSame('unavailable', $this->service->summary($this->supplierId, $noon)['status']);
    }

    public function testAutomaticDownloadsOnlyCarryBalanceIntoNextMonth(): void
    {
        // Každé automatické stažení končí dneškem: žádný výpis nevznikl po skončení svého dne.
        $this->download('2099-09-20', '2099-09-20 08:00:00', '1000.00', '1000.00');
        $this->download('2099-09-30', '2099-09-30 00:00:00', '1000.00', '1000.00');
        $this->download('2099-10-05', '2099-10-05 00:15:00', '1000.00', '900.00', [['2099-09-30', '-100.00']]);
        $this->download('2099-10-31', '2099-10-31 10:00:00', '900.00', '850.00', [['2099-10-31', '-50.00']]);
        $october = $this->statement('bank_api', '2099-10-31', '2099-10-31 10:00:00', null, null, null, []);

        $result = $this->service->summary($this->supplierId, $october);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(900, $result['opening']);
        self::assertEquals(850, $result['closing']);
        self::assertEquals(0, $result['difference']);
    }

    public function testDifferentFinalBalancesOfSameDayRemainConflict(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        $this->download('2099-10-31', '2099-11-01 00:00:00', '1000.00', '1000.00');
        $other = $this->download('2099-10-31', '2099-11-01 00:15:00', '1000.00', '900.00');

        self::assertSame('unavailable', $this->service->summary($this->supplierId, $other)['status']);
    }

    public function testFirstOpeningOfNewAccountComesFromIntradayDownload(): void
    {
        $first = $this->download('2099-10-15', '2099-10-15 09:00:00', '500.00', '450.00', [['2099-10-15', '-50.00']]);

        $result = $this->service->summary($this->supplierId, $first);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(500, $result['opening']);
        self::assertEquals(450, $result['closing']);
    }

    public function testUploadedGpcMovementIsNotAddedToDownloadedBalance(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Banka pohyb −100 do stavu započetla, API ho ale nevrátilo. Doplní ho až nahrané GPC.
        $this->download('2099-10-31', '2099-10-31 10:15:00', '1000.00', '900.00');
        $gpc = $this->statement('gpc', '2099-10-31', '2099-11-03 09:00:00', '1000.00', '900.00', 'x', [['2099-10-31', '-100.00']]);

        $result = $this->service->summary($this->supplierId, $gpc);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(900, $result['closing']);
        self::assertEquals(0, $result['difference']);
    }

    public function testEarlierMovementFromLongerLaterDownloadIsNotAddedTwice(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Automatické stažení 17.–30. 10.: stav už obsahuje pohyb z 5. 10., ten ale v jeho období není.
        $this->download('2099-10-30', '2099-10-30 08:15:00', '900.00', '900.00');
        // Ruční synchronizace celého října ten pohyb přinese poprvé.
        $manual = $this->download('2099-10-30', '2099-10-30 10:28:00', '1000.00', '900.00', [['2099-10-05', '-100.00']]);

        $result = $this->service->summary($this->supplierId, $manual);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(900, $result['closing']);
        self::assertEquals(0, $result['difference']);
    }

    public function testOnlyStatementMovementsCompleteBalance(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        $morning = $this->download('2099-10-31', '2099-10-31 00:00:00', '1000.00', '1000.00');
        $next = $this->download('2099-11-01', '2099-11-01 00:00:00', '1000.00', '1000.00');
        $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
            VALUES ('email_notice', ?, '2099-10-31', -100, 'CZK', ?)")->execute([$next, hash('sha256', 'notice' . $next)]);

        $result = $this->service->summary($this->supplierId, $morning);
        self::assertSame('confirmed', $result['status']);
        self::assertEquals(1000, $result['confirmed_closing']);
    }

    public function testMonthWithAttachedPdfBalancesIsNotCompleted(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Měsíc z API s přiloženým PDF výpisem: zůstatky jsou z PDF, ne ze stažení.
        $month = $this->statement('bank_api', '2099-10-31', '2099-10-31 00:00:00', '1000.00', '1000.00', null, []);
        $this->pdo->prepare('UPDATE bank_statements SET pdf_content = ? WHERE id = ?')->execute(['x', $month]);
        $this->download('2099-11-01', '2099-11-01 00:00:00', '1000.00', '900.00', [['2099-10-31', '-100.00']]);

        $result = $this->service->summary($this->supplierId, $month);
        self::assertSame('mismatch', $result['status']);
        self::assertEquals(100, $result['difference']);
    }

    public function testDownloadAfterItsDayEndedIsNotCompleted(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Ruční stažení října až 2. 11.: banka vrátila stav po skončení dne 31. 10.
        $late = $this->download('2099-10-31', '2099-11-02 09:00:00', '1000.00', '900.00', [['2099-10-31', '-100.00']]);
        // Pohyb s datem 31. 10., který banka do toho stavu nezapočetla, přišel až později.
        $this->download('2099-11-05', '2099-11-05 00:00:00', '900.00', '850.00', [['2099-10-31', '-50.00']]);

        $result = $this->service->summary($this->supplierId, $late);
        self::assertSame('mismatch', $result['status']);
        self::assertEquals(50, $result['difference']);
    }

    public function testDownloadWithOwnTotalsIsNotCompleted(): void
    {
        $this->download('2099-09-30', '2099-10-01 08:00:00', '1000.00', '1000.00');
        // Stažení s obraty od banky (formát GPC): jeho rovnici zůstatků hlídá výpočet.
        $morning = $this->download('2099-10-31', '2099-10-31 08:00:00', '1000.00', '900.00', [['2099-10-31', '-100.00']], ['0.00', '100.00']);
        $this->download('2099-11-01', '2099-11-01 00:00:00', '900.00', '850.00', [['2099-10-31', '-50.00']]);

        // Doplněný stav by rozbil rovnici výpisu a skončil jako konflikt. Rozdíl má zůstat vidět.
        $result = $this->service->summary($this->supplierId, $morning);
        self::assertSame('mismatch', $result['status']);
        self::assertEquals(50, $result['difference']);
    }

    /**
     * @param list<array{0:string,1:string}> $moves
     * @param array{0:string,1:string}|null $totals obraty od banky [příjmy, výdaje]
     */
    private function download(string $date, string $importedAt, string $prev, string $curr, array $moves = [], ?array $totals = null): int
    {
        $id = $this->statement('bank_api', $date, $importedAt, $prev, $curr, '{}', $moves);
        if ($totals !== null) {
            $this->pdo->prepare('UPDATE bank_statements SET credit_total = ?, debit_total = ? WHERE id = ?')->execute([$totals[0], $totals[1], $id]);
        }
        return $id;
    }

    /** @param list<array{0:string,1:string}> $moves */
    private function statement(string $source, string $date, string $importedAt, ?string $prev, ?string $curr, ?string $file, array $moves): int
    {
        $this->pdo->prepare("INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content, account_number, bank_code, currency,
                 statement_date, prev_balance, curr_balance, transaction_count, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, '2010', 'CZK', ?, ?, ?, ?, ?)")
            ->execute([
                $this->supplierId, $source, 'test-' . $date . '.json', hash('sha256', $source . $importedAt . random_int(0, PHP_INT_MAX)),
                $file, self::ACCOUNT, $date, $prev, $curr, count($moves), $importedAt,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($moves as [$posted, $amount]) {
            $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
                VALUES ('statement', ?, ?, ?, 'CZK', ?)")->execute([$id, $posted, $amount, hash('sha256', $id . $posted . $amount)]);
        }
        return $id;
    }
}
