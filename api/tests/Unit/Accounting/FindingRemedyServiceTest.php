<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\FindingRemedyService;
use MyInvoice\Service\Accounting\FxSettlementPosting;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\TestCase;

/**
 * Kdy systém smí nabídnout doúčtování nálezu — a kdy naopak musí mlčet.
 *
 * Podstatná část těchhle testů ověřuje, že návrh NEVZNIKNE. Nabídnout předvyplněný
 * zápis tam, kde správnou odpověď z dat nelze určit, není pomoc: uživatel ho odklepne
 * a v účetnictví zůstane schválená chyba, která se hledá hůř než neopravený nález.
 */
final class FindingRemedyServiceTest extends TestCase
{
    private function service(string $documentCurrency, ?string $saldo = null): FindingRemedyService
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($documentCurrency, $saldo): \PDOStatement {
            $stmt = $this->createStub(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn($documentCurrency);
            $stmt->method('fetch')->willReturn(false);
            $rows = match (true) {
                $saldo === null => [],
                str_contains($sql, 'FROM journal_document_postings jdp') => [
                    ['posting_doc_id' => 42, 'id' => 1, 'grouped' => true, 'source_type' => 'manual', 'source_id' => null],
                ],
                str_contains($sql, 'FROM journal_entry_lines l') => [
                    ['entry_id' => 1, 'account_code' => $saldo, 'currency_code' => null, 'fx_rate' => null],
                ],
                default => [],
            };
            $stmt->method('fetchAll')->willReturn($rows);
            return $stmt;
        });

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $rules = $this->createStub(PostingRuleRepository::class);
        $rules->method('resolve')->willReturnCallback(static fn (int $s, string $key): ?array => match ($key) {
            'fx.loss' => ['debit_account_code' => '563', 'credit_account_code' => null],
            'fx.gain' => ['debit_account_code' => null, 'credit_account_code' => '663'],
            default   => null,
        });

        $journal = new JournalEntryRepository($db, new ActivityLogger($db));
        $settlement = new FxSettlementPosting($db, $rules, $journal);
        return new FindingRemedyService($db, $rules, $settlement);
    }

    /**
     * Cizoměnová přijatá faktura zaplacená LEVNĚJI, než činil předpis v korunách:
     * závazek se vypořádal za méně → kurzový ZISK na 663, protiúčet je saldo 321.
     */
    public function testProposesFxGainOnCheaperSettlementOfForeignPurchase(): void
    {
        $p = $this->service('EUR')->propose(1, 'purchase_invoice', 42, 'amount_mismatch', [
            'expected' => 1000.0, 'actual' => 990.0, 'diff' => -10.0, 'fx_booked' => 0,
        ]);

        self::assertNotNull($p);
        self::assertSame('fx_difference', $p['kind']);
        self::assertSame(
            [
                ['account_code' => '321', 'side' => 'debit',  'amount' => 10.0],
                ['account_code' => '663', 'side' => 'credit', 'amount' => 10.0],
            ],
            $p['lines'],
        );
    }

    /** Dražší vypořádání závazku = kurzová ZTRÁTA na 563 proti saldu 321. */
    public function testProposesFxLossOnMoreExpensiveSettlementOfForeignPurchase(): void
    {
        $p = $this->service('EUR')->propose(1, 'purchase_invoice', 42, 'amount_mismatch', [
            'diff' => 10.0, 'fx_booked' => 0,
        ]);

        self::assertNotNull($p);
        self::assertSame(
            [
                ['account_code' => '563', 'side' => 'debit',  'amount' => 10.0],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 10.0],
            ],
            $p['lines'],
        );
    }

    /** U pohledávky se strany obracejí: víc přijatých korun je kurzový zisk. */
    public function testGainDirectionIsMirroredForReceivables(): void
    {
        $p = $this->service('EUR')->propose(1, 'invoice', 7, 'amount_mismatch', [
            'diff' => 25.0, 'fx_booked' => 0,
        ]);

        self::assertNotNull($p);
        self::assertSame('311', $p['lines'][0]['account_code']);
        self::assertSame('663', $p['lines'][1]['account_code']);
    }

    public function testFxRemedyUsesPredpisAnalytic(): void
    {
        $proposal = $this->service('EUR', '321.200')->propose(1, 'purchase_invoice', 42, 'amount_mismatch', [
            'diff' => -10.0, 'fx_booked' => 0,
        ]);

        self::assertSame('321.200', $proposal['lines'][0]['account_code'] ?? null);
    }

    public function testNoProposalForAmbiguousPredpisAccount(): void
    {
        $service = $this->service('EUR', '325');
        self::assertNull($service->propose(1, 'purchase_invoice', 42, 'amount_mismatch', ['diff' => -10.0]));
    }

    /**
     * TUZEMSKÝ doklad — rozdíl je přeplatek nebo částečná úhrada, ne kurzový rozdíl.
     *
     * Tohle je nejdůležitější test celé služby. Na ostrých datech je většina nálezů
     * `amount_mismatch` právě takových (např. úhrada 100 000 Kč proti faktuře na
     * 253 616 Kč) a zaúčtovat rozdíl 153 616 Kč jako kurzovou ztrátu by bylo nesmyslné.
     */
    public function testNoProposalForDomesticDocument(): void
    {
        $p = $this->service('CZK')->propose(1, 'invoice', 39, 'amount_mismatch', [
            'expected' => 253616.0, 'actual' => 100000.0, 'diff' => -153616.0, 'fx_booked' => 0,
        ]);

        self::assertNull($p, 'Rozdíl na korunovém dokladu není kurzový rozdíl.');
    }

    /** Kurzový rozdíl už zaúčtovaný je → doúčtovávat se nemá nic. */
    public function testNoProposalWhenFxAlreadyBooked(): void
    {
        $p = $this->service('EUR')->propose(1, 'purchase_invoice', 42, 'amount_mismatch', [
            'diff' => 10.0, 'fx_booked' => 10.0,
        ]);

        self::assertNull($p);
    }

    /** Nulový rozdíl není co doúčtovat. */
    public function testNoProposalForZeroDifference(): void
    {
        self::assertNull($this->service('EUR')->propose(1, 'invoice', 7, 'amount_mismatch', [
            'diff' => 0.0, 'fx_booked' => 0,
        ]));
    }

    /**
     * Nesouhlasící měna a nesouhlasící protistrana návrh nedostanou nikdy.
     * U měny je nejspíš špatně samo spárování a doúčtování by chybu zabetonovalo;
     * u protistrany se žádný zápis nekoná — jde o evidenci jmen.
     */
    public function testNoProposalForIssuesWithoutAccountingAnswer(): void
    {
        $svc = $this->service('EUR');

        self::assertNull($svc->propose(1, 'invoice', 7, 'currency_mismatch', ['tx_currency' => 'EUR', 'doc_currency' => 'USD']));
        self::assertNull($svc->propose(1, 'invoice', 7, 'counterparty_mismatch', ['counterparty_name' => 'NAVICAT']));
    }

    /**
     * Kurzový rozdíl na transakci CZK↔CZK vzniknout nemohl — návrhem je STORNO,
     * ne další zápis. Prázdné `lines` jsou proto správně: kdyby nesly řádky,
     * uživatel by k chybnému zápisu přidal druhý.
     */
    public function testCzkToCzkFxProposesReversalWithoutLines(): void
    {
        $p = $this->service('CZK')->propose(1, 'invoice', 7, 'fx_on_czk_czk', ['amount' => -3.67]);

        self::assertNotNull($p);
        self::assertSame('fx_reversal', $p['kind']);
        self::assertSame([], $p['lines']);
    }
}
