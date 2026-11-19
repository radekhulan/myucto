<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMdbExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bankovní pohyby se stejným číslem dokladu a dnem (POHODA dá jedno číslo celému výpisu):
 * zápis deníku patří jen pohybům, které v deníku POHODY řádek mají. Pohyb s předkontací
 * „Nevím" zůstává nespárovaný a k zaúčtování. Izolovaná firma, rollback v tearDown.
 */
#[Group('integration')]
final class PohodaSharedBankNumberTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_shared_bank_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
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
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * Oficiální XML: řádek deníku nenese id dokladu, pohyb se pozná podle částky. Před opravou
     * dostal zápis čísla i nezaúčtovaný výdaj 150 Kč: převod ho označil jako zaúčtovaný
     * v POHODĚ (ignorovaný) a banka proti deníku nesouhlasila právě o jeho částku.
     */
    public function testXmlStatementUnderOneNumberLeavesUnbookedMovementForPosting(): void
    {
        $base = $this->supplier();
        $baseDir = $this->tmp . '/base';
        mkdir($baseDir);
        $baseProtocol = $this->importer->run($base, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($baseDir)), false);

        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp . '/shared');
        SyntheticPohodaExport::withSharedBankNumber($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        $movements = $this->movements($supplierId);
        self::assertCount(3, $movements, $this->explain($protocol));
        $open = $movements[number_format(SyntheticPohodaExport::SHARED_BANK_UNBOOKED, 2, '.', '')] ?? [];
        self::assertSame(['status' => 'unmatched', 'reason' => null, 'links' => 0], $open, json_encode($movements));
        foreach (['500.00', '-20.00'] as $booked) {
            self::assertSame(1, $movements[$booked]['links'] ?? null, json_encode($movements));
        }
        self::assertSame($this->linkCount($baseProtocol, 'unbooked_bank') + 1, $this->linkCount($protocol, 'unbooked_bank'), $this->explain($protocol));
        $this->assertBankMatchesJournal($protocol);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    /**
     * Datový soubor (MDB): řádek deníku nese id bankovního dokladu. Nezaúčtovaný výdaj zní
     * na stejnou částku jako zaúčtovaný poplatek a je ve výpisu před ním, rozhoduje id.
     */
    public function testMdbStatementUnderOneNumberUsesPohodaDocumentId(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaMdbExport::writeSharedBankNumber($this->tmp)), false);

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.description, t.match_status,
                    (SELECT COUNT(*) FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id) AS links
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = ? ORDER BY t.id"
        );
        $stmt->execute([$supplierId, SyntheticPohodaExport::SHARED_BANK]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[(string) $r['description']] = [(string) $r['match_status'], (int) $r['links']];
        }
        self::assertCount(3, $rows, $this->explain($protocol));
        self::assertSame(['unmatched', 0], $rows[SyntheticPohodaMdbExport::UNBOOKED_TEXT] ?? null, json_encode($rows));
        self::assertSame(1, $rows['Poplatek'][1] ?? null, json_encode($rows));
        self::assertSame(1, $this->linkCount($protocol, 'unbooked_bank'), $this->explain($protocol));
        $this->assertBankMatchesJournal($protocol);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    /**
     * Stejné číslo bankovního dokladu o rok později: zaúčtovaný je jen starší pohyb. Novější
     * zůstává nespárovaný a k zaúčtování. Před opravou ho vazby počítaly mezi nezaúčtované,
     * ale vyřízení pohybů rozhodovalo jen podle čísla a označilo ho jako zaúčtovaný v POHODĚ.
     */
    public function testSameNumberNextYearWithoutJournalRowStaysUnbooked(): void
    {
        $base = $this->supplier();
        $baseDir = $this->tmp . '/base';
        mkdir($baseDir);
        $baseProtocol = $this->importer->run($base, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($baseDir)), false);

        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp . '/years');
        SyntheticPohodaExport::withBankNumberNextYear($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        $movements = $this->movements($supplierId, SyntheticPohodaExport::NEXT_YEAR_BANK);
        self::assertSame(['status' => 'unmatched', 'reason' => null, 'links' => 0], $movements['-250.00'] ?? null, json_encode($movements));
        self::assertSame(1, $movements['400.00']['links'] ?? null, json_encode($movements));
        self::assertSame($this->linkCount($baseProtocol, 'unbooked_bank') + 1, $this->linkCount($protocol, 'unbooked_bank'), $this->explain($protocol));
        $this->assertBankMatchesJournal($protocol);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    /**
     * Opakovaný převod uvolní pohyb, který dřívější převod navázal na zápis čísla a označil
     * jako zaúčtovaný v POHODĚ, a zápis dostane zaúčtovaný pohyb.
     */
    public function testRerunReleasesMovementLinkedByEarlierImport(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withSharedBankNumber($dir);
        $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        $pdo = $this->db->pdo();
        $open = (int) $pdo->query("SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
            WHERE s.supplier_id = {$supplierId} AND t.bank_ref = '" . SyntheticPohodaExport::SHARED_BANK . "' AND t.amount = " . SyntheticPohodaExport::SHARED_BANK_UNBOOKED)->fetchColumn();
        $entry = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = '" . SyntheticPohodaExport::SHARED_BANK . "'")->fetchColumn();
        self::assertGreaterThan(0, $open);
        self::assertGreaterThan(0, $entry);
        // Stav po převodu bez opravy: pohyb vlastní zápis čísla a je vyřízený jako zaúčtovaný v POHODĚ.
        $pdo->exec("UPDATE journal_entries SET source_id = {$open} WHERE id = {$entry}");
        $pdo->exec("INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note) VALUES ({$supplierId}, {$entry}, 'bank', {$open}, 'Převzato z Pohody')");
        $pdo->exec("UPDATE bank_transactions SET match_status = 'ignored', match_reason = 'pohoda_booked', ignore_note = 'Zaúčtováno v Pohodě bez dokladu (převod z POHODY).' WHERE id = {$open}");

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['status' => 'unmatched', 'reason' => null, 'links' => 0], $this->movements($supplierId)[number_format(SyntheticPohodaExport::SHARED_BANK_UNBOOKED, 2, '.', '')] ?? null);
        $owner = (int) $pdo->query("SELECT source_id FROM journal_entries WHERE id = {$entry}")->fetchColumn();
        self::assertNotSame($open, $owner);
        self::assertGreaterThan(0, $owner);
        $this->assertBankMatchesJournal($protocol);
    }

    /** @return array<string,array{status:string,reason:?string,links:int}> částka => pohyb s číslem `$number` */
    private function movements(int $supplierId, string $number = SyntheticPohodaExport::SHARED_BANK): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, t.match_status, t.match_reason,
                    (SELECT COUNT(*) FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id) AS links
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = ?"
        );
        $stmt->execute([$supplierId, $number]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['amount']] = ['status' => (string) $r['match_status'], 'reason' => $r['match_reason'], 'links' => (int) $r['links']];
        }
        return $out;
    }

    private function assertBankMatchesJournal(ImportProtocol $protocol): void
    {
        $bank = $this->bankCheck($protocol);
        self::assertTrue($bank['ok'], json_encode($bank));
        self::assertEqualsWithDelta($bank['documents'], $bank['journal'], 0.001, json_encode($bank));
        self::assertArrayNotHasKey('source_differences', $bank);
    }

    /** @return array<string,mixed> kontrola banky proti deníku z rekonciliace */
    private function bankCheck(ImportProtocol $protocol): array
    {
        foreach ($protocol->get('reconciliation')[0]['documents'] ?? [] as $d) {
            if ($d['key'] === 'bank') {
                return $d;
            }
        }
        self::fail('Rekonciliace nemá kontrolu banky: ' . $this->explain($protocol));
    }

    private function linkCount(ImportProtocol $protocol, string $name): int
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === 'link') {
                return (int) ($step['counts'][$name] ?? 0);
            }
        }
        return 0;
    }

    /** @return list<string> */
    private function messages(ImportProtocol $protocol, string $stepKey, string $code): array
    {
        $out = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['key'] === $stepKey ? $step['messages'] : [] as $m) {
                if ($m['code'] === $code) {
                    $out[] = (string) $m['text'];
                }
            }
        }
        return $out;
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
