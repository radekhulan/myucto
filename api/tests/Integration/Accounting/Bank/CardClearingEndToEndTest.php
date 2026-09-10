<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\ScanAttach\ScanAttachJobService;
use MyInvoice\Service\Document\ScanAttach\ScanBatchService;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionService;
use MyInvoice\Service\Document\ScanAttach\ScanMatcher;
use MyInvoice\Service\Document\ScanAttach\ScanSourceFile;
use MyInvoice\Service\Document\ScanAttach\ScanSourceInterface;
use MyInvoice\Service\Document\ScanAttach\ScanTargetRegistry;
use MyInvoice\Service\Document\ScanAttach\UploadedScanSource;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Automatizace plateb kartou přes skutečné vstupní body: import GPC výpisu (importér
 * sám páruje a účtuje), AI import účtenky (brána bez sítě), dávka skenů, ruční
 * spárování a zrušení párování akcí výpisu a zapnutá/vypnutá automatika firmy.
 *
 * Importér drží vlastní transakci a zámek, takže test nejede ve sdílené transakci:
 * všechno, co založí (rok 2024, výpisy E2ECARD-*, doklady, karty, analytiky, skeny),
 * uklidí v setUp i tearDown. Syntetická data, účet 2100000007/0800.
 */
#[Group('integration')]
final class CardClearingEndToEndTest extends TestCase
{
    private const YEAR = 2024;
    private const ACCOUNT = '2100000007';
    private const BANK = '0800';
    private const MARK = 'E2ECARD';
    private const LAST4 = ['5501', '5502', '5503', '5504', '5505', '5506', '5507', '5508'];

    private \DI\Container $c;
    private Connection $db;
    private PDO $pdo;
    private int $sid = 0;
    private int $userId = 0;
    private string $ownIco = '';
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $periodId = 0;
    private bool $createdPeriod = false;
    private ?string $policyBefore = null;
    private int $stmtSeq = 0;
    private int $docSeq = 0;
    /** @var list<int> */
    private array $jobIds = [];
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if (!$container instanceof \DI\Container) {
                self::markTestSkipped('Kontejner neumí make().');
            }
            $this->c = $container;
            $this->db = $this->c->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->sid = (int) ($this->pdo->query(
            "SELECT id FROM supplier WHERE accounting_mode = 'double_entry' AND is_vat_payer = 1 AND ic IS NOT NULL AND ic <> '' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $country = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->sid === 0 || $this->userId === 0 || $country === 0) {
            self::markTestSkipped('Chybí podvojná firma s IČO, uživatel nebo země.');
        }
        $this->ownIco = (string) $this->pdo->query("SELECT ic FROM supplier WHERE id = {$this->sid}")->fetchColumn();
        $this->cleanup();

        $this->c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->sid);
        $periods = $this->c->get(AccountingPeriodRepository::class);
        $existing = $periods->findByYear($this->sid, self::YEAR);
        if ($existing === null) {
            $this->periodId = $periods->create($this->sid, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
            $this->createdPeriod = true;
        } else {
            $this->periodId = (int) $existing['id'];
        }

        $this->pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", ?, "Kč", "koruna", "koruna", 2, 1, 0, ?, ?)'
        )->execute([$this->sid, self::MARK . ' účet', self::ACCOUNT, self::BANK]);
        $this->currencyId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic, main_email, is_customer, is_vendor, is_vat_payer)
             VALUES (?, ?, 'Testovací 8', 'Brno', '60200', ?, ?, '11220088', 'karty@example.test', 0, 1, 1)"
        )->execute([$this->sid, self::MARK . ' dodavatel s.r.o.', $country, $this->currencyId]);
        $this->vendorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, ?, '378')"
        )->execute([$this->sid, self::YEAR . '-01-01']);
        $before = $this->pdo->prepare("SELECT level FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'");
        $before->execute([$this->sid]);
        $level = $before->fetchColumn();
        $this->policyBefore = $level === false ? null : (string) $level;
        $this->setPolicy('auto');

        $this->tmp = sys_get_temp_dir() . '/card-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->cleanup();
        if ($this->policyBefore === null) {
            $this->pdo->prepare("DELETE FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'")->execute([$this->sid]);
        } else {
            $this->setPolicy($this->policyBefore);
        }
        if ($this->createdPeriod && $this->periodId > 0) {
            $this->pdo->exec("DELETE FROM accounting_periods WHERE id = {$this->periodId}");
        }
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        $this->db->close();
    }

    /** Import výpisu: platba kartou se sama zaúčtuje 378.x/221, existující doklad se spáruje i vypořádá. */
    public function testImportPostsCardPaymentsAndSettlesExistingDocument(): void
    {
        $pi = $this->postedPurchase(1210.00, '2024-06-10', '5501');
        $vatBefore = $this->vatFingerprint();

        $this->import([['D1', -1210.00, '2024-06-12', '5501'], ['D2', -300.00, '2024-06-12', '5502']]);
        $tx1 = $this->txId('D1');
        $tx2 = $this->txId('D2');

        $code1 = $this->cardCode('5501');
        $code2 = $this->cardCode('5502');
        self::assertNotSame($code1, $code2, 'Každá karta má vlastní analytiku.');
        self::assertSame([$pi], $this->matchedPurchases($tx1), 'Doklad s koncovkou se spároval při importu.');
        $this->assertBankEntry($tx1, $code1, 1210.00);
        $this->assertSettlement($tx1, $code1, 1210.00);
        $this->assertBankEntry($tx2, $code2, 300.00);
        self::assertNull($this->liveEntry('card_settlement', $tx2));

        self::assertEqualsWithDelta(0.00, $this->balance($code1), 0.001);
        self::assertEqualsWithDelta(300.00, $this->balance($code2), 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance('321'), 0.001);
        self::assertEqualsWithDelta(-1510.00, $this->balance('221'), 0.001);
        self::assertSame($vatBefore, $this->vatFingerprint(), 'Platby kartou nesmí změnit DPH ani KH.');

        // Opakovaný import téhož výpisu nic nezdvojí.
        $fingerprint = $this->fingerprint();
        $this->import([['D1', -1210.00, '2024-06-12', '5501'], ['D2', -300.00, '2024-06-12', '5502']], $this->stmtSeq);
        self::assertSame($fingerprint, $this->fingerprint());
        self::assertSame(2, $this->txCount());
    }

    /** Doklad dorazí po platbě AI importem účtenky „uhrazeno kartou" — spáruje a vypořádá se sám. */
    public function testDocumentArrivingLaterByAiImportSettlesItself(): void
    {
        $this->import([['D3', -484.00, '2024-06-20', '5503']]);
        $tx = $this->txId('D3');
        $code = $this->cardCode('5503');
        $this->assertBankEntry($tx, $code, 484.00);
        self::assertEqualsWithDelta(484.00, $this->balance($code), 0.001);

        $number = self::MARK . '-AI-' . strtoupper(bin2hex(random_bytes(3)));
        $llm = new FakeLlmGateway(fn (string $bytes): array => [
            'vendor' => ['company_name' => self::MARK . ' dodavatel s.r.o.', 'ic' => '11220088', 'dic' => null, 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => $this->ownIco, 'dic' => null],
            'vendor_invoice_number' => $number,
            'document_kind' => 'invoice',
            'issue_date' => '2024-06-19',
            'tax_date' => '2024-06-19',
            'due_date' => '2024-06-19',
            'currency' => 'CZK',
            'total_without_vat' => 400.0,
            'total_with_vat' => 484.0,
            'already_paid' => true,
            'payment' => ['method' => 'card', 'method_confidence' => 0.95],
            'card_last4' => '**** **** **** 5503',
            'company_role' => null,
            'items' => [['description' => 'Syntetické zboží', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 400.0, 'vat_rate' => 21]],
        ]);
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);
        $extractor = $this->c->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver]);

        $pdf = "%PDF-1.4\n% SYNTHETIC-CARD-AI-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $res = $extractor->extractAndCreate($this->sid, $this->userId, $pdf, null, 'uctenka.pdf');
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $pi = (int) $res['purchase_invoice_id'];

        self::assertSame([$pi], $this->matchedPurchases($tx), 'AI import účtenky s koncovkou ji spároval s platbou karty.');
        if ($this->liveEntry('purchase_invoice', $pi) === null) {
            // Firma bez automatického účtování přijatých dokladů: zaúčtování dokladu
            // je ta cesta, po které vypořádání vznikne samo.
            $this->c->get(DocumentAutoPoster::class)->post($this->sid, 'purchase_invoice', $pi, ['user_id' => $this->userId]);
        }

        $this->assertSettlement($tx, $code, 484.00);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
    }

    /** Doklad dorazí po platbě a koncovku mu dodá připojený sken účtenky. */
    public function testDocumentArrivingLaterByScanAttachSettlesItself(): void
    {
        $this->import([['D4', -777.00, '2024-06-05', '5504']]);
        $tx = $this->txId('D4');
        $code = $this->cardCode('5504');
        $pi = $this->postedPurchase(777.00, '2024-06-01', null);
        self::assertSame([], $this->matchedPurchases($tx));

        $llm = new FakeLlmGateway(fn (string $bytes): ?array => str_contains($bytes, 'SYNTHETIC-CARD-SCAN') ? [
            'vendor' => ['company_name' => self::MARK . ' dodavatel s.r.o.', 'ic' => '11220088', 'dic' => null],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => $this->ownIco, 'dic' => null],
            'vendor_invoice_number' => null, 'varsymbol' => null, 'document_kind' => 'invoice',
            'issue_date' => '2024-06-01', 'tax_date' => '2024-06-01', 'total_with_vat' => 777.0, 'currency' => 'CZK',
            'barcode' => null, 'license_plate' => null, 'card_last4' => '5504', 'company_role' => 'buyer',
        ] : null);
        $jobs = $this->c->get(ImportJobRepository::class);
        $jobId = $jobs->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'], 'date_from' => '2024-05-01', 'date_to' => '2024-07-31',
        ], $this->userId);
        $this->jobIds[] = $jobId;

        $this->scanJobService($llm)->run($jobId, $this->source([
            'uctenka-karta.pdf' => "%PDF-1.4\n% SYNTHETIC-CARD-SCAN-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        ]));

        self::assertSame('5504', (string) $this->pdo->query("SELECT card_last4 FROM purchase_invoices WHERE id = {$pi}")->fetchColumn(),
            'Koncovka ze skenu přešla na doklad.');
        self::assertSame([$pi], $this->matchedPurchases($tx), 'Připojený sken dotáhl spárování s platbou karty.');
        $this->assertSettlement($tx, $code, 777.00);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
    }

    /** Ruční spárování i zrušení párování akcí výpisu; zrušení vrátí zůstatek 378.x. */
    public function testManualMatchAndUnmatchThroughStatementActions(): void
    {
        $this->import([['D5', -650.00, '2024-06-08', '5505']]);
        $tx = $this->txId('D5');
        $code = $this->cardCode('5505');
        $pi = $this->postedPurchase(650.00, '2024-06-07', null);
        $vatBefore = $this->vatFingerprint();
        $action = $this->c->get(BankStatementAction::class);

        $matched = $this->callAction($action, 'manualMatch', ['purchase_invoice_id' => $pi], ['id' => (string) $tx]);
        self::assertSame(200, $matched['status'], json_encode($matched['body']));
        $this->assertSettlement($tx, $code, 650.00);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);

        $unmatched = $this->callAction($action, 'unmatch', [], ['id' => (string) $tx]);
        self::assertSame(200, $unmatched['status'], json_encode($unmatched['body']));
        self::assertNull($this->liveEntry('card_settlement', $tx), 'Zrušení párování vypořádání stornuje.');
        $this->assertBankEntry($tx, $code, 650.00);
        self::assertEqualsWithDelta(650.00, $this->balance($code), 0.001, 'Zůstatek 378.x se vrátí.');
        self::assertEqualsWithDelta(-650.00, $this->balance('321'), 0.001, 'Doklad je znovu neuhrazený.');
        self::assertEqualsWithDelta(-650.00, $this->balance('221'), 0.001, 'Bankovní pohyb zůstává zaúčtovaný.');
        self::assertSame($vatBefore, $this->vatFingerprint());
    }

    /** Koncept s koncovkou karty přijatý akcí přechodu stavu se sám spáruje a vypořádá. */
    public function testDraftTransitionedToReceivedSettlesCardPayment(): void
    {
        $this->import([['D8', -363.00, '2024-06-22', '5508']]);
        $tx = $this->txId('D8');
        $code = $this->cardCode('5508');
        $this->assertBankEntry($tx, $code, 363.00);
        $pi = $this->postedPurchase(363.00, '2024-06-21', '5508', 'draft');
        self::assertSame([], $this->matchedPurchases($tx), 'Koncept se nepáruje.');
        self::assertEqualsWithDelta(363.00, $this->balance($code), 0.001);

        $action = $this->c->get(\MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction::class);
        $r = $this->callAction($action, '__invoke', ['target' => 'received'], ['id' => (string) $pi]);

        self::assertSame(200, $r['status'], json_encode($r['body']));
        self::assertSame([$pi], $this->matchedPurchases($tx), 'Přijetí konceptu dotáhlo spárování s platbou karty.');
        $this->assertSettlement($tx, $code, 363.00);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001, 'Zůstatek 378.x klesl na nulu.');
    }

    /** Vypnutá automatika firmy neúčtuje nic; po zapnutí se zaúčtuje platba i vypořádání. */
    public function testAutomationOffPostsNothingAndOnPostsEverything(): void
    {
        $this->setPolicy('off');
        $pi = $this->postedPurchase(210.00, '2024-06-14', '5506');
        $this->import([['D6', -210.00, '2024-06-15', '5506'], ['D7', -90.00, '2024-06-15', '5507']]);
        $tx6 = $this->txId('D6');
        $tx7 = $this->txId('D7');

        self::assertSame([$pi], $this->matchedPurchases($tx6), 'Párování běží i bez automatického účtování.');
        self::assertNull($this->liveEntry('bank', $tx6));
        self::assertNull($this->liveEntry('bank', $tx7));
        self::assertNull($this->liveEntry('card_settlement', $tx6));
        self::assertSame(0, $this->countLines('378'));

        $this->setPolicy('auto');
        $posting = $this->c->get(BankPostingService::class);
        $posting->handleTransaction($tx6, $this->userId);
        $posting->handleTransaction($tx7, $this->userId);

        $this->assertBankEntry($tx6, $this->cardCode('5506'), 210.00);
        $this->assertSettlement($tx6, $this->cardCode('5506'), 210.00);
        $this->assertBankEntry($tx7, $this->cardCode('5507'), 90.00);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array{0:string,1:float,2:string,3:string}> $rows [číslo dokladu, částka, datum, koncovka] */
    private function import(array $rows, ?int $stmtNo = null): void
    {
        $stmtNo ??= ++$this->stmtSeq;
        $acc16 = str_pad(self::ACCOUNT, 16, '0', STR_PAD_LEFT);
        $lines = ['074' . $acc16 . str_pad(self::MARK . ' UCET', 20) . '010124'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+' . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+' . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad((string) $stmtNo, 3, '0', STR_PAD_LEFT) . '300624'];
        foreach ($rows as [$doc, $amount, $date, $last4]) {
            $d = (new \DateTimeImmutable($date))->format('dmy');
            $lines[] = '075' . $acc16 . str_pad('', 16, '0') . str_pad(self::MARK . $doc, 13, '0', STR_PAD_LEFT)
                . str_pad((string) (int) round(abs($amount) * 100), 12, '0', STR_PAD_LEFT)
                . ($amount < 0 ? '1' : '2')
                . str_pad('', 10, '0') . '00' . '0000' . '0000' . str_pad('', 10, '0')
                . $d . str_pad('OBCHOD ' . $last4, 20) . '00203' . $d;
            $lines[] = '078OBCHOD TEST ' . $last4 . ' PK: 000000******' . $last4;
        }
        $this->c->get(StatementImporter::class)->import(
            implode("\r\n", $lines) . "\r\n",
            self::MARK . '-' . $stmtNo . '.gpc',
            $this->userId,
            $this->currencyId,
        );
    }

    private function txId(string $doc): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE ? AND bt.bank_ref LIKE ? ORDER BY bt.id LIMIT 1"
        );
        $stmt->execute([self::MARK . '-%', '%' . self::MARK . $doc]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Pohyb ' . $doc . ' se neimportoval.');
        return $id;
    }

    private function txCount(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.file_name LIKE ?'
        );
        $stmt->execute([self::MARK . '-%']);
        return (int) $stmt->fetchColumn();
    }

    private function cardCode(string $last4): string
    {
        $stmt = $this->pdo->prepare('SELECT analytic_suffix FROM payment_cards WHERE supplier_id = ? AND last4 = ?');
        $stmt->execute([$this->sid, $last4]);
        $suffix = $stmt->fetchColumn();
        self::assertNotEmpty($suffix, 'Karta ' . $last4 . ' nemá analytiku.');
        return '378.' . $suffix;
    }

    private function postedPurchase(float $total, string $date, ?string $last4, string $status = 'received'): int
    {
        $number = self::MARK . '-PF-' . (++$this->docSeq) . '-' . bin2hex(random_bytes(2));
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by, payment_method, card_last4)
             VALUES (?, ?, ?, '{}', 'invoice', 'full', ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, ?)"
        )->execute([
            $this->sid, $this->vendorId, $number, $date, $date, $date, $date, $this->currencyId,
            $total, $total, $status, $this->userId, $last4 !== null ? 'card' : 'bank_transfer', $last4,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $map = $this->c->get(\MyInvoice\Repository\ChartOfAccountsRepository::class)->codeToIdMap($this->sid);
        $this->c->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->sid, 'period_id' => $this->periodId, 'entry_date' => $date,
            'document_no' => $number, 'description' => 'Předpis', 'source_type' => 'purchase_invoice',
            'source_id' => $id, 'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => $total],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => $total],
        ]);
        return $id;
    }

    /** @return list<int> */
    private function matchedPurchases(int $txId): array
    {
        return array_map('intval', $this->pdo->query(
            "SELECT purchase_invoice_id FROM payment_matches WHERE bank_transaction_id = {$txId} ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,array{debit:float,credit:float}>|null */
    private function liveEntry(string $sourceType, int $sourceId): ?array
    {
        $e = $this->c->get(JournalEntryRepository::class)->findBySource($this->sid, $sourceType, $sourceId);
        if ($e === null || $e['reversed_by'] !== null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.account_code, l.side, l.amount FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = ?'
        );
        $stmt->execute([(int) $e['id']]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $code = str_starts_with((string) $l['account_code'], '221') ? '221' : (string) $l['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    private function assertBankEntry(int $txId, string $code, float $amount): void
    {
        $lines = $this->liveEntry('bank', $txId);
        self::assertNotNull($lines, 'Platba kartou není zaúčtovaná.');
        self::assertEqualsWithDelta($amount, $lines[$code]['debit'] ?? 0.0, 0.001, 'MD ' . $code);
        self::assertEqualsWithDelta($amount, $lines['221']['credit'] ?? 0.0, 0.001, 'D 221');
    }

    private function assertSettlement(int $txId, string $code, float $amount): void
    {
        $lines = $this->liveEntry('card_settlement', $txId);
        self::assertNotNull($lines, 'Chybí vypořádání platby kartou s dokladem.');
        self::assertEqualsWithDelta($amount, $lines['321']['debit'] ?? 0.0, 0.001, 'MD 321');
        self::assertEqualsWithDelta($amount, $lines[$code]['credit'] ?? 0.0, 0.001, 'D ' . $code);
    }

    /** Zůstatek účtu (a jeho analytik) ze zápisů roku 2024. */
    private function balance(string $code): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2024-01-01' AND '2024-12-31'
                AND (a.account_code = ? OR a.account_code LIKE CONCAT(?, '.%'))"
        );
        $stmt->execute([$this->sid, $code, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function countLines(string $prefix): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2024-01-01' AND '2024-12-31' AND a.account_code LIKE CONCAT(?, '%')"
        );
        $stmt->execute([$this->sid, $prefix]);
        return (int) $stmt->fetchColumn();
    }

    private function fingerprint(): string
    {
        $rows = $this->pdo->query(
            "SELECT e.id, e.source_type, e.source_id, e.reversed_by, a.account_code, l.side, l.amount
               FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = {$this->sid} AND e.entry_date BETWEEN '2024-01-01' AND '2024-12-31'
              ORDER BY e.id, l.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode($rows));
    }

    /** DPH přiznání a KH za červen 2024 — souhrny (XML nese datum sestavení). */
    private function vatFingerprint(): string
    {
        $dph = $this->c->get(DphPriznaniBuilder::class)->build($this->sid, self::YEAR, 6, 'monthly');
        $kh = $this->c->get(KontrolniHlaseniBuilder::class)->build($this->sid, self::YEAR, 6, 'monthly');
        return hash('sha256', json_encode([$dph['summary'], $kh['summary']]));
    }

    private function setPolicy(string $level): void
    {
        $this->pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.payment.matched', ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level)"
        )->execute([$this->sid, $level, $this->userId]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callAction(object $action, string $method, array $body, array $args): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-statements')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $resp = $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function scanJobService(FakeLlmGateway $llm): ScanAttachJobService
    {
        return new ScanAttachJobService(
            $this->c->get(ImportJobRepository::class),
            $this->c->get(ScanBatchRepository::class),
            $this->c->get(DocumentIngestService::class),
            $this->c->get(DocumentStorage::class),
            new ScanExtractionService(
                $llm,
                $this->c->get(DocumentExtractionRepository::class),
                $this->c->get(DocumentRepository::class),
                $this->c->get(ImageToPdfConverter::class),
                $this->db,
            ),
            $this->c->get(DocumentExtractionRepository::class),
            new ScanMatcher(),
            $this->c->get(ScanTargetRegistry::class),
            $this->c->get(ScanBatchService::class),
            $this->c->get(PaymentCardRepository::class),
        );
    }

    /** @param array<string,string> $files */
    private function source(array $files): ScanSourceInterface
    {
        return new class ($this->tmp, $files) implements ScanSourceInterface {
            /** @param array<string,string> $files */
            public function __construct(private readonly string $dir, private readonly array $files) {}

            public function count(): ?int
            {
                return count($this->files);
            }

            public function files(): iterable
            {
                foreach ($this->files as $name => $bytes) {
                    $path = $this->dir . '/src-' . bin2hex(random_bytes(6));
                    file_put_contents($path, $bytes);
                    yield new ScanSourceFile((string) $name, $path, strlen($bytes));
                }
            }

            public function hasFiles(): bool
            {
                return $this->files !== [];
            }

            public function cleanup(): void {}
        };
    }

    /** Smaže vše, co test mohl založit — i po předchozím spadlém běhu. */
    private function cleanup(): void
    {
        $pdo = $this->pdo;
        $sid = $this->sid;
        $from = self::YEAR . '-01-01';
        $to = self::YEAR . '-12-31';

        foreach ($this->jobIds as $jobId) {
            $docIds = array_map('intval', $pdo->query("SELECT document_id FROM scan_batch_items WHERE job_id = {$jobId} AND document_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
            $pdo->exec("DELETE FROM scan_matches WHERE job_id = {$jobId}");
            $pdo->exec("DELETE FROM scan_batch_items WHERE job_id = {$jobId}");
            $pdo->exec("DELETE FROM import_jobs WHERE id = {$jobId}");
            (new UploadedScanSource(ScanAttachJobService::stagingDir($sid, $jobId)))->cleanup();
            $pdo->exec("DELETE FROM document_folders WHERE supplier_id = {$sid} AND name = 'Dávka {$jobId}'");
            if ($docIds !== []) {
                $in = implode(',', $docIds);
                $shas = $pdo->query("SELECT sha256 FROM documents WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
                $pdo->exec("DELETE FROM document_links WHERE document_id IN ($in)");
                $pdo->exec("DELETE FROM document_files WHERE document_id IN ($in)");
                $pdo->exec("DELETE FROM documents WHERE id IN ($in)");
                foreach ($shas as $sha) {
                    $pdo->prepare('DELETE FROM document_extractions WHERE supplier_id = ? AND sha256 = ?')->execute([$sid, $sha]);
                }
            }
        }
        $this->jobIds = [];

        $pdo->prepare("UPDATE journal_entries SET reversed_by = NULL WHERE supplier_id = ? AND entry_date BETWEEN ? AND ?")->execute([$sid, $from, $to]);
        $entryIds = $pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND entry_date BETWEEN ? AND ?');
        $entryIds->execute([$sid, $from, $to]);
        $ids = array_map('intval', $entryIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $this->deleteReferencing('journal_entries', $ids);
            $in = implode(',', $ids);
            $pdo->exec("DELETE FROM journal_entry_lines WHERE entry_id IN ($in)");
            $pdo->exec("DELETE FROM journal_entries WHERE id IN ($in)");
        }

        $stmt = $pdo->prepare('SELECT id FROM bank_statements WHERE file_name LIKE ?');
        $stmt->execute([self::MARK . '-%']);
        $statementIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($statementIds !== []) {
            $in = implode(',', $statementIds);
            $txIds = array_map('intval', $pdo->query("SELECT id FROM bank_transactions WHERE statement_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            if ($txIds !== []) {
                $this->deleteReferencing('bank_transactions', $txIds);
                $pdo->exec('DELETE FROM bank_transactions WHERE id IN (' . implode(',', $txIds) . ')');
            }
            $this->deleteReferencing('bank_statements', $statementIds);
            $pdo->exec("DELETE FROM bank_statements WHERE id IN ($in)");
        }

        $pis = $pdo->prepare(
            "SELECT pi.id FROM purchase_invoices pi JOIN clients c ON c.id = pi.vendor_id
              WHERE pi.supplier_id = ? AND c.company_name = ?"
        );
        $pis->execute([$sid, self::MARK . ' dodavatel s.r.o.']);
        $piIds = array_map('intval', $pis->fetchAll(PDO::FETCH_COLUMN));
        if ($piIds !== []) {
            $this->deleteReferencing('purchase_invoices', $piIds);
            $pdo->exec('DELETE FROM purchase_invoices WHERE id IN (' . implode(',', $piIds) . ')');
        }

        $ph = implode(',', array_fill(0, count(self::LAST4), '?'));
        $cards = $pdo->prepare("SELECT id, analytic_suffix FROM payment_cards WHERE supplier_id = ? AND last4 IN ($ph)");
        $cards->execute([$sid, ...self::LAST4]);
        foreach ($cards->fetchAll(PDO::FETCH_ASSOC) as $card) {
            if ($card['analytic_suffix'] !== null) {
                $pdo->prepare(
                    'DELETE c FROM chart_of_accounts c
                      WHERE c.supplier_id = ? AND c.account_code = ?
                        AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l WHERE l.account_id = c.id)'
                )->execute([$sid, '378.' . $card['analytic_suffix']]);
            }
            $pdo->exec('DELETE FROM payment_cards WHERE id = ' . (int) $card['id']);
        }

        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')->execute([$sid, self::MARK . ' dodavatel s.r.o.']);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND account_number = ?')->execute([$sid, self::ACCOUNT]);
        $pdo->prepare('DELETE FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ?')->execute([$sid, self::ACCOUNT]);
        $pdo->prepare('DELETE FROM card_clearing_settings WHERE supplier_id = ?')->execute([$sid]);
    }

    /**
     * Smaže řádky tabulek, které na zadané id odkazují cizím klíčem (jednu úroveň) —
     * výpisy, pohyby a doklady na sebe navazují přes desítky evidenčních tabulek.
     *
     * @param list<int> $ids
     */
    private function deleteReferencing(string $table, array $ids): void
    {
        $refs = $this->pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = "id"'
        );
        $refs->execute([$table]);
        $in = implode(',', $ids);
        foreach ($refs->fetchAll(PDO::FETCH_ASSOC) as $ref) {
            if ($ref['TABLE_NAME'] === $table) {
                $this->pdo->exec("UPDATE `{$ref['TABLE_NAME']}` SET `{$ref['COLUMN_NAME']}` = NULL WHERE `{$ref['COLUMN_NAME']}` IN ($in)");
                continue;
            }
            try {
                $this->pdo->exec("DELETE FROM `{$ref['TABLE_NAME']}` WHERE `{$ref['COLUMN_NAME']}` IN ($in)");
            } catch (\PDOException) {
                $this->pdo->exec("UPDATE `{$ref['TABLE_NAME']}` SET `{$ref['COLUMN_NAME']}` = NULL WHERE `{$ref['COLUMN_NAME']}` IN ($in)");
            }
        }
    }
}
