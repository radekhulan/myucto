<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\PeriodLockAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy měkkého zámku účtování k datu (B8, audit 2026-07 core-posting).
 *
 * Ověřuje BE enforcement v {@see PostingService}: zaúčtování/re-post do zamčeného
 * data se odmítne ('date_locked'); storno zamčeného zápisu projde jen s protizápisem
 * do otevřeného data. Dále auto-posun zámku po archivaci DPH ({@see TaxSubmissionArchiver})
 * a admin endpoint {@see PeriodLockAction}.
 *
 * Vše v jedné transakci, rollback v tearDown. Soft-skip bez cfg.php.
 *
 * PostingService cachuje locked_until per instance (v produkci per-request korektní),
 * takže testy měnící zámek uprostřed testu si berou ČERSTVOU instanci přes freshPosting().
 */
#[Group('integration')]
final class DateLockTest extends TestCase
{
    private const YEAR = 2099;

    /** @var \Psr\Container\ContainerInterface */
    private $container;

    private Connection $db;
    private AccountingPeriodRepository $periods;
    private AccountingSupplierSettingsRepository $settings;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db       = $this->container->get(Connection::class);
            $this->periods  = $this->container->get(AccountingPeriodRepository::class);
            $this->settings = $this->container->get(AccountingSupplierSettingsRepository::class);
            $this->posting  = $this->container->get(PostingService::class);
            $seeder         = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Čistý výchozí stav: bez zámku (nezávisle na případné committed hodnotě na devu).
        $this->settings->setLockedUntil($this->supplierId, null);
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

    // (a) zaúčtování DO zamčeného data → date_locked
    public function testPostIntoLockedDateRefused(): void
    {
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-06-30');
        $posting = $this->freshPosting();

        try {
            $this->postManual($posting, self::YEAR . '-06-15');
            self::fail('Zaúčtování do zamčeného data musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
            self::assertStringContainsString(self::YEAR . '-06-30', $e->getMessage());
        }
    }

    // (b) zaúčtování do NEzamčeného data projde, i když je locked_until nastaveno dřív
    public function testPostIntoOpenDateAllowedDespiteEarlierLock(): void
    {
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-03-31');
        $posting = $this->freshPosting();

        $entryId = $this->postManual($posting, self::YEAR . '-06-15');
        self::assertGreaterThan(0, $entryId, 'Datum po zámku (06-15 > 03-31) se zaúčtuje normálně.');
    }

    // (c) re-post zápisu, jehož PŮVODNÍ datum je zamčené → date_locked (i s novým otevřeným datem)
    public function testRepostOfEntryWithLockedOriginalDateRefused(): void
    {
        $lines = $this->manualLines();
        // Arrange: zaúčtuj k 06-15 BEZ zámku (idempotentní zdroj invoice/777001).
        $this->posting->postDocument($this->supplierId, 'invoice', 777001, $lines, ['entry_date' => self::YEAR . '-06-15']);

        // Zamkni 06-30 a zkus re-post s NOVÝM otevřeným datem 08-15 — původní 06-15 je zamčené.
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-06-30');
        $posting = $this->freshPosting();

        try {
            $posting->postDocument($this->supplierId, 'invoice', 777001, $lines, ['entry_date' => self::YEAR . '-08-15']);
            self::fail('Re-post zápisu se zamčeným původním datem musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
    }

    // (d) storno zamčeného zápisu s datem storna do OTEVŘENÉHO (nezamčeného) data → projde
    public function testReverseLockedEntryIntoOpenDatePasses(): void
    {
        $entryId = $this->postManual($this->posting, self::YEAR . '-06-15');
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-06-30');
        $posting = $this->freshPosting();

        $reversalId = $posting->reverse($this->supplierId, $entryId, [
            'entry_date' => self::YEAR . '-08-15', // otevřené, > zámek
            'posted_by'  => $this->userId,
        ]);
        self::assertGreaterThan(0, $reversalId);

        $reversal = $this->journalEntry($reversalId);
        self::assertSame(self::YEAR . '-08-15', substr((string) $reversal['entry_date'], 0, 10), 'Protizápis je datovaný do otevřeného data.');
        $original = $this->journalEntry($entryId);
        self::assertSame($reversalId, (int) $original['reversed_by'], 'Original je navázán na storno.');
    }

    // (d2) storno bez zadaného data storna: původní datum zamčené → protizápis se posune na DNES
    public function testReverseLockedEntryAutoShiftsMirrorToToday(): void
    {
        // Zámek v minulosti vůči reálnému dnešku (rok 2020), aby posun na dnešek prošel.
        $pastYear = 2020;
        $p = $this->periods->create($this->supplierId, $pastYear, $pastYear . '-01-01', $pastYear . '-12-31');
        $this->periods->setStatus($p, $this->supplierId, 'open');

        // Otevřené období pro aktuální rok (cíl auto-posunu).
        $today   = date('Y-m-d');
        $curYear = (int) date('Y');
        $cur = $this->periods->create($this->supplierId, $curYear, $curYear . '-01-01', $curYear . '-12-31');
        $this->periods->setStatus($cur, $this->supplierId, 'open');

        $entryId = $this->postManual($this->posting, $pastYear . '-06-15');
        $this->settings->setLockedUntil($this->supplierId, $pastYear . '-06-30');
        $posting = $this->freshPosting();

        // Bez entry_date — reverse defaultuje na původní (zamčené) datum → posun na dnešek.
        $reversalId = $posting->reverse($this->supplierId, $entryId, ['posted_by' => $this->userId]);
        $reversal = $this->journalEntry($reversalId);
        self::assertSame($today, substr((string) $reversal['entry_date'], 0, 10), 'Protizápis zamčeného zápisu je datovaný k dnešku (otevřené datum).');
    }

    // (e) storno s datem storna TAKÉ zamčeným → date_locked
    public function testReverseIntoLockedDateRefused(): void
    {
        $entryId = $this->postManual($this->posting, self::YEAR . '-06-15');
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-06-30');
        $posting = $this->freshPosting();

        try {
            $posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-06-15']);
            self::fail('Storno datované do zamčeného data musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
    }

    // (f) advanceLockedUntil() posouvá zámek jen VPŘED (GREATEST/MAX), nikdy nezmenší.
    // (Rozhodovací logika co/zda zamknout je čistá a testovaná v Unit
    //  TaxSubmissionArchiverLockDecisionTest — tady jen DB posun VPŘED.)
    public function testAdvanceLockedUntilForwardOnly(): void
    {
        $this->settings->advanceLockedUntil($this->supplierId, '2020-05-31');
        self::assertSame('2020-05-31', $this->settings->getLockedUntil($this->supplierId), 'První posun nastaví zámek.');

        $this->settings->advanceLockedUntil($this->supplierId, '2020-06-30');
        self::assertSame('2020-06-30', $this->settings->getLockedUntil($this->supplierId), 'Pozdější datum posune vpřed.');

        // Dřívější datum zámek NESMÍ zmenšit (MAX / idempotence vůči opakované archivaci).
        $this->settings->advanceLockedUntil($this->supplierId, '2020-01-31');
        self::assertSame('2020-06-30', $this->settings->getLockedUntil($this->supplierId), 'Dřívější období zámek nezmenší (MAX).');
    }

    // (f2) HIGH#1: archivace PROBÍHAJÍCÍHO období (běžný měsíc) NESMÍ posunout zámek —
    // jinak by pouhé stažení náhledu zmrazilo účtování celého probíhajícího období.
    public function testArchiveDoesNotLockCurrentPeriod(): void
    {
        $archiver = $this->container->get(TaxSubmissionArchiver::class);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost/>';

        $curYear  = (int) date('Y');
        $curMonth = (int) date('n');
        $archiver->archive($this->supplierId, 'dphdp3', $curYear, $curMonth, null, $xml, [], $this->userId);

        self::assertNull(
            $this->settings->getLockedUntil($this->supplierId),
            'Archivace běžného (probíhajícího) měsíce nesmí zamknout účtování.',
        );
    }

    // (f3) HIGH#1: readonly cesta (allowLock=false) nesmí posunout zámek ani u minulého období.
    public function testArchiveReadonlyPathDoesNotLock(): void
    {
        $archiver = $this->container->get(TaxSubmissionArchiver::class);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost/>';

        // Minulé období (2020-05), ale allowLock=false → zámek se nesmí pohnout.
        $archiver->archive($this->supplierId, 'dphdp3', 2020, 5, null, $xml, [], $this->userId, false);

        self::assertNull(
            $this->settings->getLockedUntil($this->supplierId),
            'Readonly cesta (allowLock=false) nesmí mutovat zámek.',
        );
    }

    // (g) admin endpoint nastaví/posune zámek s reason + audit log
    public function testAdminEndpointSetsLockWithReasonAndAudit(): void
    {
        $action = $this->container->get(PeriodLockAction::class);

        // Non-admin (accountant) → 403, zámek se nezmění.
        $forbidden = $action->update(
            $this->adminRequest('accountant', ['locked_until' => self::YEAR . '-06-30', 'reason' => 'test zámku']),
            new Psr7Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode(), 'Zámek smí měnit jen admin.');

        // Chybějící reason → 422.
        $noReason = $action->update(
            $this->adminRequest('admin', ['locked_until' => self::YEAR . '-06-30']),
            new Psr7Response(),
        );
        self::assertSame(422, $noReason->getStatusCode(), 'Reason je povinný.');

        // Admin happy path.
        $ok = $action->update(
            $this->adminRequest('admin', ['locked_until' => self::YEAR . '-06-30', 'reason' => 'uzávěrka Q2 podána']),
            new Psr7Response(),
        );
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(self::YEAR . '-06-30', $this->settings->getLockedUntil($this->supplierId));

        $auditCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.lock_date_changed'"
        )->fetchColumn();
        self::assertSame(1, $auditCount, 'Změna zámku se zapíše do activity_log.');
    }

    // (h) hlavní účetní s právem accounting.period_lock posouvá zámek, ale zpět přes
    //     podané DPH ani přes uzavřené období ne; správce období ano.
    public function testChiefAccountantMovesLockButNotBackOverFiledVatOrClosedPeriod(): void
    {
        $action = $this->container->get(PeriodLockAction::class);
        $chief = new EffectiveRole(9901, 'Hlavní účetní', 'staff', true,
            ['accounting' => 2, 'accounting.journal.write' => 2, 'accounting.period_lock' => 2]);
        $plain = new EffectiveRole(9902, 'Účetní bez zámku', 'staff', true,
            ['accounting' => 2, 'accounting.journal.write' => 2]);

        self::assertSame(403, $action->update($this->roleRequest($plain, ['locked_until' => self::YEAR . '-03-31', 'reason' => 'uzávěrka 1Q']), new Psr7Response())->getStatusCode());

        $forward = $action->update($this->roleRequest($chief, ['locked_until' => self::YEAR . '-06-30', 'reason' => 'uzávěrka 2Q']), new Psr7Response());
        self::assertSame(200, $forward->getStatusCode(), (string) $forward->getBody());
        $back = $action->update($this->roleRequest($chief, ['locked_until' => self::YEAR . '-05-31', 'reason' => 'oprava května']), new Psr7Response());
        self::assertSame(200, $back->getStatusCode(), (string) $back->getBody());
        self::assertSame(self::YEAR . '-05-31', $this->settings->getLockedUntil($this->supplierId));

        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, form_variant, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, 'dphdp3', ?, 4, 'B', '<x/>', 4, ?, 'submitted', NOW())"
        )->execute([$this->supplierId, self::YEAR, hash('sha256', '<x/>')]);
        foreach ([self::YEAR . '-03-31', null] as $target) {
            $filed = $action->update($this->roleRequest($chief, ['locked_until' => $target, 'reason' => 'oprava dubna']), new Psr7Response());
            self::assertSame(409, $filed->getStatusCode());
            self::assertSame('period_lock_filed_vat', json_decode((string) $filed->getBody(), true)['error']['code'] ?? null);
        }
        self::assertSame(self::YEAR . '-05-31', $this->settings->getLockedUntil($this->supplierId));

        $prior = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$prior]);
        $this->db->pdo()->prepare("DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = ?")->execute([$this->supplierId, self::YEAR]);
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-01-31');
        $closed = $action->update($this->roleRequest($chief, ['locked_until' => (self::YEAR - 1) . '-06-30', 'reason' => 'oprava loňska']), new Psr7Response());
        self::assertSame(409, $closed->getStatusCode());
        self::assertSame('period_lock_closed_period', json_decode((string) $closed->getBody(), true)['error']['code'] ?? null);

        // Správce účetních období posune zámek zpět jako dosud.
        $admin = $action->update($this->adminRequest('admin', ['locked_until' => (self::YEAR - 1) . '-06-30', 'reason' => 'oprava loňska']), new Psr7Response());
        self::assertSame(200, $admin->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    private function roleRequest(EffectiveRole $role, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->adminRequest('accountant', $body)->withAttribute('auth.effective_role', $role);
    }

    /** @return list<array{account_code:string, side:'debit'|'credit', amount:float}> */
    private function manualLines(): array
    {
        return [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
        ];
    }

    private function postManual(PostingService $posting, string $date): int
    {
        return $posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $this->manualLines(),
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** Čerstvá PostingService (prázdná per-instance cache locked_until). */
    private function freshPosting(): PostingService
    {
        return new PostingService(
            $this->db,
            $this->container->get(ChartOfAccountsRepository::class),
            $this->periods,
            $this->container->get(PostingRuleRepository::class),
            $this->container->get(JournalEntryRepository::class),
            $this->container->get(VatLedgerService::class),
            $this->container->get(ActivityLogger::class),
            $this->container->get(\MyInvoice\Service\Accounting\Expense\ExpenseClassificationService::class),
        );
    }

    /** @return array<string,mixed> */
    private function journalEntry(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, entry_date, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $this->supplierId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(string $role, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/accounting/period-lock')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);
    }
}
