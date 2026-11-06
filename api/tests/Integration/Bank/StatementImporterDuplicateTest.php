<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementTransactionScope;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * BUG 0 (tichá ztráta dat): GPC parser neplnil `bank_ref`, takže identita pohybu se
 * skládala z VS/KS/SS/protiúčtu/názvu/popisu. Tři legitimní platby téže částky, dne
 * a VS (opakované mikroplatby, poplatky) proto dostaly identický otisk a druhá i třetí
 * se tiše zahodily — v evidenci chyběly bez chyby i bez hlášení.
 *
 * Test drží OBA směry:
 *   - legitimní pohyby se nesmí ztratit (ani když banka ID pohybu nepošle),
 *   - skutečná duplicita (týž soubor, překrývající se výpis, doimport po upgradu)
 *     se nesmí založit podruhé.
 *
 * Izolace: syntetické účty 99905623xx, rok 2099, vlastní currencies řádky; vše se
 * v tearDown maže. Importér se skládá ručně BEZ BankPostingService a bez registru
 * vlastních účtů, aby test nezasahoval do účetnictví testovacího tenanta.
 */
#[Group('integration')]
final class StatementImporterDuplicateTest extends TestCase
{
    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;

    /** @var int[] */
    private array $otherSupplierIds = [];

    /** @var int[] */
    private array $statementIds = [];
    /** @var int[] */
    private array $currencyIds = [];

    private const FILE_NAME = 'TEST-BUG0.gpc';
    /** VS mimo jakoukoli reálnou řadu — import spouští matcher, nesmí trefit ostrý doklad. */
    private const VS = '2099009991';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->importer = new StatementImporter(
                $this->db,
                new GpcParser(),
                new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null),
                $c->get(EmailNoticeReconciler::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->cleanupLeftovers();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        // Vazby na měsíční výpis padnou první — měsíc odkazuje na pohyby DENNÍCH/dílčích
        // výpisů, takže by cizí klíč mazání hlaviček jinak zablokoval.
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
        }
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        $this->cleanupLeftovers();
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        foreach ($this->otherSupplierIds as $id) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Jádro nálezu: tři platby stejné částky, dne, VS i popisu, každá s VLASTNÍM ID
     * pohybu od banky. Před opravou se založila jediná — zbylé dvě zmizely bez hlášení.
     */
    public function testIdenticalMovementsWithDistinctBankIdsAreAllImported(): void
    {
        $account = '9990562350';
        $currencyId = $this->registerCurrency($account, '2010');

        $r = $this->import($this->gpc($account, ['26001', '26002', '26003'], stmtNo: '021'), $currencyId);

        self::assertSame(3, $r['transactions'], 'Tři legitimní pohyby se nesmí slít do jednoho.');
        self::assertSame(0, $r['skipped_duplicates']);
        self::assertSame([], $r['warnings']);
        self::assertSame(
            ['26001', '26002', '26003'],
            $this->bankRefs($r['statement_id']),
            'ID pohybu z GPC musí skončit v bank_ref (do teď tam byl NULL u všech pohybů).',
        );
    }

    /**
     * Banka ID pohybu neposlala (pole čísla dokladu je prázdné) — identita se skládá
     * z náhradních polí. Ani tady se legitimní pohyby nesmí ztratit: rozliší je pořadí
     * výskytu v souboru.
     */
    public function testIdenticalMovementsWithoutBankIdsAreAllImported(): void
    {
        $account = '9990562351';
        $currencyId = $this->registerCurrency($account, '2010');

        $r = $this->import($this->gpc($account, ['', '', ''], stmtNo: '022'), $currencyId);

        self::assertSame(3, $r['transactions'], 'Bez ID pohybu musí pohyby rozlišit pořadí v souboru.');
        self::assertSame([null, null, null], $this->bankRefs($r['statement_id']));
    }

    /**
     * Opačný směr: překrývající se výpis nese TYTÉŽ pohyby. Ty se založit podruhé nesmí,
     * jinak by z opravy tiché ztráty dat bylo tiché zdvojení. Rozdíl proti počtu řádků
     * v souboru se hlásí jako varování, ne mlčky.
     */
    public function testOverlappingStatementDoesNotDuplicateAndWarns(): void
    {
        $account = '9990562352';
        $currencyId = $this->registerCurrency($account, '2010');

        $first = $this->import($this->gpc($account, ['26011', '26012', '26013'], stmtNo: '023'), $currencyId);
        $second = $this->import($this->gpc($account, ['26011', '26012', '26013'], stmtNo: '024'), $currencyId);

        self::assertSame(3, $first['transactions']);
        self::assertSame(0, $second['transactions'], 'Týž pohyb z překrývajícího se výpisu se nesmí založit podruhé.');
        self::assertSame(3, $second['skipped_duplicates']);
        self::assertSame(3, $second['parsed_transactions']);
        self::assertSame('transactions_skipped_as_duplicate', $second['warnings'][0]['code'] ?? null,
            'Rozdíl mezi počtem řádků v souboru a počtem založených pohybů musí být hlášený.');
        self::assertSame(3, $this->transactionCount($account));
    }

    /**
     * Upgrade existující instalace: pohyb naimportovaný PŘED opravou nemá bank_ref a nese
     * otisk z náhradní identity. Když pak dorazí překrývající se výpis, kde už ID pohybu je,
     * nesmí se ten pohyb založit znovu — a druhá, dosud ztracená platba se naopak založit MUSÍ.
     */
    public function testHistoricalRowWithoutBankReferenceIsNotDuplicatedButLostMovementIsRecovered(): void
    {
        $account = '9990562353';
        $currencyId = $this->registerCurrency($account, '2010');

        // Stav „před opravou": banka ID pohybu neposlala, v evidenci je jediný pohyb.
        $legacy = $this->import($this->gpc($account, [''], stmtNo: '025'), $currencyId);
        self::assertSame(1, $legacy['transactions']);

        // Výpis po upgradu: tentýž pohyb + druhá platba, kterou předchozí import zahodil.
        $r = $this->import($this->gpc($account, ['26021', '26022'], stmtNo: '026'), $currencyId);

        self::assertSame(1, $r['transactions'], 'Historický pohyb bez bank_ref se nesmí založit podruhé.');
        self::assertSame(1, $r['skipped_duplicates']);
        self::assertSame(2, $this->transactionCount($account), 'Druhá, dosud ztracená platba se musí doplnit.');
    }

    /**
     * Přepojení účtu na nové připojení k bance: konektor začne TENTÝŽ pohyb popisovat jinak
     * (jiná reference banky, protiúčet v IBANu místo domácího tvaru), takže otisk pohybu vyjde
     * jiný a deterministický dedup ho nepozná. Dřív se taková platba naimportovala i zaúčtovala
     * DVAKRÁT — reconciler křížil jen různé zdroje a uvnitř `bank_api` se spoléhal na otisk.
     */
    public function testSameMovementFromTwoApiConnectionsIsNotImportedTwice(): void
    {
        $account = '9990562355';
        $currencyId = $this->activeCurrency($account, '0100');

        $first = $this->importer->importConnectedParsed(
            $this->apiStatement($account, 'kbplus:SGWD013384652111', '2222222222', '0800'),
            '{"connection":8}',
            'kb_plus-8-2099-01-01-2099-01-31.json',
            null,
            $currencyId,
            $this->supplierId,
        );
        $this->statementIds[] = (int) ($first['evidence_statement_id'] ?? $first['statement_id']);
        $this->statementIds[] = (int) $first['statement_id'];
        self::assertSame(1, $first['transactions']);

        $second = $this->importer->importConnectedParsed(
            $this->apiStatement($account, '1', 'CZ5808000000002222222222', ''),
            '{"connection":11}',
            'kb_plus-11-2099-01-02-2099-01-31.json',
            null,
            $currencyId,
            $this->supplierId,
        );
        $this->statementIds[] = (int) ($second['evidence_statement_id'] ?? $second['statement_id']);
        $this->statementIds[] = (int) $second['statement_id'];

        self::assertSame(0, $second['transactions'], 'Tentýž pohyb z nového připojení se nesmí založit podruhé.');
        self::assertSame(1, $second['skipped_duplicates']);
        self::assertSame(1, $this->rawTransactionCount($account), 'V evidenci smí zůstat jediný pohyb.');
    }

    /**
     * Protiváha: dvě opravdu RŮZNÉ platby téhož dne a částky ze stejného zdroje, které spolu
     * nic nespojuje, musí zůstat obě. Silná shoda (reference / protiúčet + VS / popis) je
     * podmínka, ne domněnka.
     */
    public function testTwoUnrelatedApiMovementsOfSameDayAndAmountAreBothKept(): void
    {
        $account = '9990562356';
        $currencyId = $this->activeCurrency($account, '0100');

        $first = $this->importer->importConnectedParsed(
            $this->apiStatement($account, 'ref-A', '3333333333', '0800', vs: '2099009991', description: 'Platba A'),
            '{"batch":"a"}',
            'kb_plus-20-a.json',
            null,
            $currencyId,
            $this->supplierId,
        );
        $this->statementIds[] = (int) ($first['evidence_statement_id'] ?? $first['statement_id']);
        $this->statementIds[] = (int) $first['statement_id'];
        self::assertSame(1, $first['transactions']);

        $second = $this->importer->importConnectedParsed(
            $this->apiStatement($account, 'ref-B', '4444444444', '0300', vs: '2099009992', description: 'Platba B'),
            '{"batch":"b"}',
            'kb_plus-20-b.json',
            null,
            $currencyId,
            $this->supplierId,
        );
        $this->statementIds[] = (int) ($second['evidence_statement_id'] ?? $second['statement_id']);
        $this->statementIds[] = (int) $second['statement_id'];

        self::assertSame(1, $second['transactions'], 'Nesouvisející platba téhož dne a částky se nesmí spolknout.');
        self::assertSame(2, $this->rawTransactionCount($account));
    }

    /**
     * Automatická synchronizace stahuje okno s překryvem, takže už evidované pohyby dostane
     * znovu. Nové stažení je skrytý podklad měsíčního výpisu a k už evidovanému pohybu se
     * stejným otiskem vazbu nezakládá: dřív se každý pohyb navázal ke každému stažení
     * a tabulka vazeb rostla o statisíce řádků denně. Měsíční výpis musí mít oba pohyby.
     */
    public function testRepeatedDownloadOfConnectedFeedDoesNotRelinkKnownMovements(): void
    {
        $account = '9990562357';
        $currencyId = $this->activeCurrency($account, '0100');
        $known = $this->apiStatement($account, 'ref-W1', '5555555555', '0800', description: 'Platba W1');
        $new = $this->apiStatement($account, 'ref-W2', '6666666666', '0300', vs: '2099009993', description: 'Platba W2');

        $first = $this->importer->importConnectedParsed($known, '{"window":1}', 'kb_plus-30-1.json', null, $currencyId, $this->supplierId);
        $this->statementIds[] = (int) $first['evidence_statement_id'];
        $this->statementIds[] = (int) $first['statement_id'];
        $second = $this->importer->importConnectedParsed(
            ['header' => $known['header'], 'transactions' => [...$known['transactions'], ...$new['transactions']]],
            '{"window":2}', 'kb_plus-30-2.json', null, $currencyId, $this->supplierId,
        );
        $evidence = (int) $second['evidence_statement_id'];
        $this->statementIds[] = $evidence;
        $this->statementIds[] = (int) $second['statement_id'];

        self::assertSame(1, $second['transactions']);
        self::assertSame(1, $second['skipped_duplicates']);
        $links = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transaction_imports WHERE statement_id = ?');
        $links->execute([$evidence]);
        self::assertSame(0, (int) $links->fetchColumn(), 'Opakované stažení nesmí znovu navázat už evidovaný pohyb.');
        self::assertSame((int) $first['statement_id'], (int) $second['statement_id'], 'Obě stažení patří do téhož měsíce.');
        $month = $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE '
            . StatementTransactionScope::sql((int) $second['statement_id']))->fetchColumn();
        self::assertSame(2, (int) $month, 'Měsíční výpis obsahuje oba pohyby.');
    }

    /**
     * Naparsovaný výpis z bankovního API s jediným pohybem.
     *
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    private function apiStatement(
        string $account,
        string $bankRef,
        string $counterpartyAccount,
        string $counterpartyBank,
        string $vs = self::VS,
        string $description = 'MyFirma s.r.o — vlastni ucet',
    ): array {
        return [
            'header' => [
                'account_number' => $account,
                'statement_date' => '2099-01-31',
                'statement_number' => null,
                'prev_balance' => null,
                'curr_balance' => null,
                'credit_total' => null,
                'debit_total' => null,
            ],
            'transactions' => [[
                'posted_at' => '2099-01-15',
                'amount' => 10000.00,
                'currency' => 'CZK',
                'variable_symbol' => $vs,
                'constant_symbol' => null,
                'specific_symbol' => null,
                'counterparty_account' => $counterpartyAccount,
                'counterparty_bank' => $counterpartyBank !== '' ? $counterpartyBank : null,
                'counterparty_name' => 'MyFirma s.r.o',
                'description' => $description,
                'bank_ref' => $bankRef,
            ]],
        ];
    }

    private function activeCurrency(string $account, string $bankCode): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, "CZK", ?, "CZK", "CZK", "CZK", 2, 1, 0, ?, ?)'
        )->execute([$this->supplierId, 'TEST BUG0 API /' . $bankCode . ' ' . $account, $account, $bankCode]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function rawTransactionCount(string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.account_number = ?'
        );
        $stmt->execute([$account]);
        return (int) $stmt->fetchColumn();
    }

    /** Nahrání téhož souboru podruhé zůstává duplicitou na úrovni výpisu (SHA-256 file_hash). */
    public function testSameFileImportedTwiceStaysStatementLevelDuplicate(): void
    {
        $account = '9990562354';
        $currencyId = $this->registerCurrency($account, '2010');
        $content = $this->gpc($account, ['26031', '26032'], stmtNo: '027');

        $first = $this->import($content, $currencyId);
        $second = $this->importer->import($content, self::FILE_NAME, null, $currencyId);

        self::assertSame(2, $first['transactions']);
        self::assertTrue($second['duplicate']);
        self::assertSame(0, $second['transactions']);
        self::assertSame($first['statement_id'], $second['statement_id']);
    }

    public function testUniquelyOwnedLegacyStatementIsClaimedBeforeDeduplication(): void
    {
        $account = '9990562354';
        $currencyId = $this->registerCurrency($account, '2010');
        $content = $this->gpc($account, ['26033'], stmtNo: '029');
        $first = $this->import($content, $currencyId);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = NULL WHERE id = ?')->execute([$first['statement_id']]);
        $pdo->prepare('UPDATE bank_transactions SET import_fingerprint = portable_fingerprint, portable_fingerprint = NULL WHERE statement_id = ?')
            ->execute([$first['statement_id']]);
        self::assertTrue((new BankStatementOwnershipResolver($this->db))->statementOwned($first['statement_id'], $this->supplierId));

        $again = $this->importer->import($content, self::FILE_NAME, null, $currencyId);
        self::assertTrue($again['duplicate']);
        self::assertSame($first['statement_id'], $again['statement_id']);
        self::assertSame($this->supplierId, (int) $pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $first['statement_id'])->fetchColumn());

        $overlap = $this->import($this->gpc($account, ['26033'], stmtNo: '030'), $currencyId);
        self::assertSame(0, $overlap['transactions']);
        self::assertSame(1, $overlap['skipped_duplicates']);
        self::assertSame(1, $this->transactionCount($account));
    }

    public function testOverlappingImportClaimsUniquelyOwnedLegacyStatement(): void
    {
        $account = '9990562354';
        $currencyId = $this->registerCurrency($account, '2010');
        $first = $this->import($this->gpc($account, ['26035'], stmtNo: '032'), $currencyId);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = NULL WHERE id = ?')->execute([$first['statement_id']]);
        $pdo->prepare('UPDATE bank_transactions SET import_fingerprint = portable_fingerprint, portable_fingerprint = NULL WHERE statement_id = ?')
            ->execute([$first['statement_id']]);

        $overlap = $this->import($this->gpc($account, ['26035'], stmtNo: '033'), $currencyId);

        self::assertSame(0, $overlap['transactions']);
        self::assertSame(1, $overlap['skipped_duplicates']);
        self::assertSame(1, $this->transactionCount($account));
        self::assertSame($this->supplierId, (int) $pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $first['statement_id'])->fetchColumn());
    }

    public function testAmbiguousLegacyStatementIsNeverClaimed(): void
    {
        $account = '9990562354';
        $currencyId = $this->registerCurrency($account, '2010');
        $content = $this->gpc($account, ['26034'], stmtNo: '031');
        $first = $this->import($content, $currencyId);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = NULL WHERE id = ?')->execute([$first['statement_id']]);
        $pdo->prepare('UPDATE bank_transactions SET import_fingerprint = portable_fingerprint, portable_fingerprint = NULL WHERE statement_id = ?')
            ->execute([$first['statement_id']]);
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, street, city, zip, country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['Synthetic ambiguous owner', 'ambiguous-owner@invalid.example', $this->supplierId]);
        $otherSupplier = (int) $pdo->lastInsertId();
        $this->otherSupplierIds[] = $otherSupplier;
        $this->registerCurrency($account, '2010', $otherSupplier);
        self::assertFalse((new BankStatementOwnershipResolver($this->db))->statementOwned($first['statement_id'], $this->supplierId));

        $second = $this->import($content, $currencyId);

        self::assertNotSame($first['statement_id'], $second['statement_id']);
        self::assertNull($pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $first['statement_id'])->fetchColumn());
        self::assertSame($this->supplierId, (int) $pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . $second['statement_id'])->fetchColumn());
    }

    public function testSameFileIsImportedSeparatelyForTwoSuppliers(): void
    {
        $account = '9990562355';
        $firstCurrency = $this->registerCurrency($account, '2010');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, street, city, zip, country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['Synthetic duplicate owner', 'statement-owner@invalid.example', $this->supplierId]);
        $otherSupplier = (int) $pdo->lastInsertId();
        $this->otherSupplierIds[] = $otherSupplier;
        $secondCurrency = $this->registerCurrency($account, '2010', $otherSupplier);
        $content = $this->gpc($account, ['26041'], stmtNo: '028');

        $first = $this->import($content, $firstCurrency);
        $second = $this->import($content, $secondCurrency);
        $secondAgain = $this->importer->import($content, self::FILE_NAME, null, $secondCurrency);

        self::assertNotSame($first['statement_id'], $second['statement_id']);
        self::assertTrue($secondAgain['duplicate']);
        self::assertSame($second['statement_id'], $secondAgain['statement_id']);
        $owners = $pdo->prepare('SELECT supplier_id FROM bank_statements WHERE id = ?');
        $owners->execute([$first['statement_id']]);
        self::assertSame($this->supplierId, (int) $owners->fetchColumn());
        $owners->execute([$second['statement_id']]);
        self::assertSame($otherSupplier, (int) $owners->fetchColumn());
    }

    public function testUnownedHashRemainsUniqueWithoutBlockingOwnedStatement(): void
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', 'synthetic-unowned-' . bin2hex(random_bytes(8)));
        $insert = $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, statement_date)
             VALUES (?, "gpc", ?, ?, "9990562356", "2099-01-31")'
        );
        $insert->execute([null, self::FILE_NAME, $hash]);
        $this->statementIds[] = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, self::FILE_NAME, $hash]);
        $this->statementIds[] = (int) $pdo->lastInsertId();

        try {
            $insert->execute([null, self::FILE_NAME, $hash]);
            self::fail('Druhý neidentifikovaný výpis se stejným hashem se nesmí založit.');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function testImportLockExcludesSecondConnectionUntilMatchingFinishes(): void
    {
        $second = Connection::withoutSharedTestConnection(static fn (): PDO => Bootstrap::buildContainer()->get(Connection::class)->pdo());
        $name = \MyInvoice\Infrastructure\Database\NamedLockName::for($this->db, 'bank-import', $this->supplierId);
        $matcher = $this->createMock(StatementMatcher::class);
        $matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids) use ($second, $name): array {
            self::assertCount(1, $ids);
            self::assertFalse($this->db->pdo()->inTransaction());
            $attempt = $second->prepare('SELECT GET_LOCK(?, 0)');
            $attempt->execute([$name]);
            self::assertSame(0, (int) $attempt->fetchColumn(), 'Import serialization must outlive the storage transaction.');
            return [];
        });
        $reconciler = $this->createStub(EmailNoticeReconciler::class);
        $reconciler->method('takeOverFromEmailNotice')->willReturn(null);
        $this->importer = new StatementImporter($this->db, new GpcParser(), $matcher, $reconciler);
        try {
            $currencyId = $this->registerCurrency('1000000005', '2010');
            $this->import($this->gpc('1000000005', ['1001'], '097'), $currencyId);
            $attempt = $second->prepare('SELECT GET_LOCK(?, 0)');
            $attempt->execute([$name]);
            self::assertSame(1, (int) $attempt->fetchColumn(), 'Completed import must release its lock.');
        } finally {
            $second->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    /** @return array{statement_id:int, transactions:int, matched:int, duplicate:bool, parsed_transactions:int, skipped_duplicates:int, warnings:list<array<string,mixed>>} */
    /**
     * Výkon: kontrola duplicit se na evidované pohyby ptá po dávkách, ne dotazem na každý
     * řádek a každý otisk-kandidát. Překrývající se výpis (vše duplicitní) stál dřív
     * dotaz na každý řádek.
     */
    public function testDuplicateCheckDoesNotQueryPerLine(): void
    {
        $queries = [];
        foreach (['9990562359' => 40, '9990562358' => 10] as $account => $lines) {
            $currencyId = $this->registerCurrency((string) $account, '2010');
            $docs = array_map(static fn (int $i): string => (string) (27000 + $i), range(1, $lines));
            $first = $this->import($this->gpc((string) $account, $docs, stmtNo: '051'), $currencyId);
            self::assertSame($lines, $first['transactions']);

            $before = $this->selects();
            $again = $this->import($this->gpc((string) $account, $docs, stmtNo: '052'), $currencyId);
            $queries[$lines] = $this->selects() - $before;
            self::assertSame(0, $again['transactions']);
            self::assertSame($lines, $again['skipped_duplicates']);
        }

        // Překrývající se výpis (vše duplicitní) stojí na řádek jen propojení s evidovaným
        // pohybem a jeho promítnutí do měsíčního výpisu (4 dotazy). Dotaz na každý ze čtyř
        // otisků-kandidátů by přidal další čtyři na řádek.
        $perLine = ($queries[40] - $queries[10]) / 30;
        self::assertLessThanOrEqual(5, $perLine, "opakovaný import 40 řádků {$queries[40]}, 10 řádků {$queries[10]}");
    }

    private function selects(): int
    {
        $row = $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")?->fetch(PDO::FETCH_NUM);

        return (int) ($row[1] ?? 0);
    }

    private function import(string $content, ?int $currencyId): array
    {
        $r = $this->importer->import($content, self::FILE_NAME, null, $currencyId);
        self::assertFalse($r['duplicate'], 'Testovací GPC nesmí být dedupnuté na úrovni výpisu.');
        $this->statementIds[] = $r['statement_id'];
        return $r;
    }

    /** @return list<?string> */
    private function bankRefs(int $statementId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT bank_ref FROM bank_transactions WHERE statement_id = ? ORDER BY id');
        $stmt->execute([$statementId]);
        return array_map(
            static fn (mixed $v): ?string => $v === null ? null : (string) $v,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function transactionCount(string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.account_number = ?'
        );
        $stmt->execute([str_pad($account, 16, '0', STR_PAD_LEFT)]);
        return (int) $stmt->fetchColumn();
    }

    private function registerCurrency(string $account, string $bankCode, ?int $supplierId = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, "CZK", ?, "CZK", "CZK", "CZK", 2, 0, 0, ?, ?)'
        )->execute([$supplierId ?? $this->supplierId, 'TEST BUG0 /' . $bankCode . ' ' . $account, $account, $bankCode]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function cleanupLeftovers(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE bti FROM bank_transaction_imports bti JOIN bank_statements bs ON bs.id = bti.original_statement_id WHERE bs.file_name = ?')->execute([self::FILE_NAME]);
        $pdo->prepare(
            'DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name = ?'
        )->execute([self::FILE_NAME]);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name = ?')->execute([self::FILE_NAME]);
        // Výpisy z API nemají společné jméno souboru — poznají se podle syntetického účtu
        // téhle testovací třídy. Bez toho by po přerušeném běhu zůstal pohyb v evidenci
        // a další běh by ho dedupnul, takže by test hlásil chybu tam, kde žádná není.
        $ids = array_map('intval', $pdo->query(
            "SELECT id FROM bank_statements WHERE account_number LIKE '999056235%'"
        )->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
        }
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM currencies WHERE label LIKE 'TEST BUG0 /%' OR label LIKE 'TEST BUG0 API /%'")->execute();
    }

    /**
     * Minimální validní GPC: 074 hlavička + N × 075 pohyb, které se liší JEN číslem
     * dokladu (ID pohybu). Prázdné `$docNumbers[i]` = banka ID pohybu neposlala.
     * Layout přesně dle {@see GpcParser} (fixed-width). Data jsou smyšlená.
     *
     * @param list<string> $docNumbers
     */
    private function gpc(string $account, array $docNumbers, string $stmtNo): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET BUG0', 20)
            . '010199'                                          // old balance date 1.1.2099
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('37335', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('37335', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad($stmtNo, 3, '0', STR_PAD_LEFT)            // salt pro odlišení file_hash
            . '310199';                                         // statement date 31.1.2099

        $lines = [$header];
        foreach ($docNumbers as $doc) {
            $lines[] = '075' . $acc16
                . str_pad('', 16, '0')                          // protiúčet (mikroplatba bez protistrany)
                . str_pad($doc, 13, '0', STR_PAD_LEFT)          // číslo dokladu = ID pohybu
                . str_pad('12445', 12, '0', STR_PAD_LEFT)       // 124,45 — u všech pohybů shodná
                . '2'                                           // credit
                . str_pad(self::VS, 10, '0', STR_PAD_LEFT)
                . '00'
                . '0000'
                . '0000'
                . str_pad('', 10, '0')
                . '150199'
                . str_pad('OPAKOVANA PLATBA', 20)               // stejný popis u všech
                . '00203'
                . '150199';
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
