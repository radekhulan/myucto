<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Přeúčtování BANKOVNÍHO pohybu nesmí obejít bankovní invarianty.
 *
 * Obecné přeúčtování dokladu pracuje s libovolnou vyrovnanou kontací. U banky to
 * nestačí: pohyb na 221 musí sedět na částku výpisu (jinak by 221 přestal odpovídat
 * bance) a bankovní noha patří na analytiku vlastního účtu výpisu (#35) — holé
 * „221" z dialogu by skončilo na syntetice a rozbilo jednoúčtovou, a tím
 * jednoměnovou, analytiku, kterou zbytek systému udržuje. Obojí hlídá
 * {@see BankPostingService::prepareRepostLines()}, tedy TÁŽ cesta jako u ručního
 * zaúčtování; kdyby ji přeúčtování vynechalo, vznikla by druhá cesta k témuž
 * zápisu s jinými pravidly — přesně ta třída driftu, kvůli které tenhle test je.
 */
final class BankRepostLineGuardTest extends TestCase
{
    /** Bankovní pohyb: řádky jdou přes BankPostingService a do deníku míří jeho VÝSTUP. */
    public function testBankRepostNormalizesLinesThroughBankPostingService(): void
    {
        $raw = [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '648', 'side' => 'credit', 'amount' => 1000.0],
        ];
        // Co vrátí bankovní služba: doplněná analytika vlastního účtu výpisu.
        $normalized = [
            ['account_code' => '221.100', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '648', 'side' => 'credit', 'amount' => 1000.0],
        ];

        $bank = $this->createMock(BankPostingService::class);
        $bank->expects(self::once())
            ->method('prepareRepostLines')
            ->with(7, 4242, $raw)
            ->willReturn($normalized);

        $posting = $this->createMock(PostingService::class);
        $posting->expects(self::once())
            ->method('postDocument')
            // Zdroj je 'bank' (ne 'bank_transaction') a řádky jsou ty NORMALIZOVANÉ.
            ->with(7, 'bank', 4242, $normalized, self::anything())
            ->willReturn(999);
        $posting->expects(self::never())->method('reverse');

        $service = $this->service($bank, $posting);
        $result = $service->repost(7, 'bank', 4242, $raw, ['user_id' => 1]);

        self::assertSame(DocumentRepostService::STRATEGY_REPLACE, $result['strategy']);
        self::assertSame(999, $result['entry_id']);
    }

    /**
     * Faktura bankovní cestou NEPROCHÁZÍ — invariant „221 = částka výpisu" na ni
     * nesedí a odmítl by každou legitimní kontaci.
     */
    public function testInvoiceRepostDoesNotTouchBankRules(): void
    {
        $lines = [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 500.0],
        ];

        $bank = $this->createMock(BankPostingService::class);
        $bank->expects(self::never())->method('prepareRepostLines');

        $posting = $this->createMock(PostingService::class);
        $posting->expects(self::once())
            ->method('postDocument')
            ->with(7, 'purchase_invoice', 4242, $lines, self::anything())
            ->willReturn(1001);

        $service = $this->service($bank, $posting);
        $service->repost(7, 'purchase_invoice', 4242, $lines, ['user_id' => 1]);
    }

    /**
     * Bankovní pohyb umí i variantu storno + nový zápis (zamčené období). Zápis
     * i tak dostane normalizované řádky — a storno se dělá PŘES PostingService,
     * takže `unpost()` po něm pořád najde ten správný živý zápis.
     */
    public function testBankRepostInLockedPeriodStillNormalizes(): void
    {
        $raw = [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]];
        $normalized = [['account_code' => '221.100', 'side' => 'debit', 'amount' => 10.0]];

        $bank = $this->createMock(BankPostingService::class);
        $bank->expects(self::once())->method('prepareRepostLines')->willReturn($normalized);

        $posting = $this->createMock(PostingService::class);
        $posting->expects(self::once())->method('reverse')->willReturn(555);
        $posting->expects(self::once())
            ->method('postDocument')
            ->with(7, 'bank', 4242, $normalized, self::anything())
            ->willReturn(556);

        // Zámek k 2026-08-31 → do data zápisu (2026-08-20) se zapsat nedá.
        $service = $this->service($bank, $posting, lockedUntil: '2026-08-31');
        $result = $service->repost(7, 'bank', 4242, $raw, ['user_id' => 1], confirmDateShift: true);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $result['strategy']);
        self::assertSame(555, $result['reversal_entry_id']);
        self::assertTrue($result['date_shifted']);
    }

    private function service(
        BankPostingService $bank,
        PostingService $posting,
        ?string $lockedUntil = null,
    ): DocumentRepostService {
        $lockStmt = $this->createStub(PDOStatement::class);
        $lockStmt->method('execute')->willReturn(true);
        $lockStmt->method('fetchColumn')->willReturn($lockedUntil ?? false);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('prepare')->willReturn($lockStmt);

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $journal = $this->createStub(JournalEntryRepository::class);
        $journal->method('findBySource')->willReturn([
            'id' => 314, 'entry_date' => '2026-08-20', 'period_id' => 5,
            'document_no' => 'BV-1', 'description' => 'Úrok', 'reversed_by' => null,
        ]);
        $journal->method('linesForEntry')->willReturn([]);

        $periods = $this->createStub(AccountingPeriodRepository::class);
        $periods->method('findById')->willReturn(['id' => 5, 'status' => 'open']);
        $periods->method('findForDate')->willReturn(['id' => 6, 'status' => 'open']);

        $accounts = $this->createStub(ChartOfAccountsRepository::class);
        $accounts->method('idToAccountMap')->willReturn([]);

        return new DocumentRepostService(
            $db,
            $posting,
            $journal,
            $periods,
            $accounts,
            $this->createStub(ActivityLogger::class),
            $bank,
        );
    }
}
