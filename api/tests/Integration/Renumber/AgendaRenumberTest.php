<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Renumber;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Renumber\CashDocumentRenumberService;
use MyInvoice\Service\Renumber\InternalDocumentRenumberService;
use MyInvoice\Service\Renumber\OtherItemRenumberService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přečíslování pokladny, ostatních pohledávek/závazků a interních dokladů za měsíc.
 *
 * - Čísla automaticky očíslovaných dokladů měsíce se rozdají podle data dokladu, množina
 *   čísel řady zůstává stejná; ruční a cizí čísla zůstávají.
 * - Pokladna: příjmový doklad, výdaj s DPH a doklad evidovaný v EET zůstávají.
 * - Kopie čísla v deníku (číslo dokladu, popis, storno) se přepíší.
 * - Zamčený měsíc a rok po roční uzávěrce daňové evidence se odmítne.
 *
 * Izolace: rok 2099, vlastní pokladna, vlastní řady a doklady se zakládají a mažou v testu.
 */
#[Group('integration')]
final class AgendaRenumberTest extends TestCase
{
    private const PERIOD = '2099-08';

    private Connection $db;
    private CashDocumentRenumberService $cash;
    private OtherItemRenumberService $otherItems;
    private InternalDocumentRenumberService $internal;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $registerId = 0;
    private int $periodId = 0;
    private ?int $createdPeriodId = null;
    private int $kindScope = 0;
    private ?int $nativeSeriesId = null;
    /** @var array<string, list<int>> */
    private array $created = ['cash_documents' => [], 'other_items' => [], 'internal_documents' => [], 'journal_entries' => [],
        'payment_events' => [], 'eet_records' => [], 'tax_evidence_closings' => []];
    private string|false|null $origLockedUntil = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->cash = $c->get(CashDocumentRenumberService::class);
            $this->otherItems = $c->get(OtherItemRenumberService::class);
            $this->internal = $c->get(InternalDocumentRenumberService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        $locked = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $locked->execute([$this->supplierId]);
        $row = $locked->fetch(PDO::FETCH_ASSOC);
        $this->origLockedUntil = $row === false ? false : ($row['locked_until'] ?? null);

        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, own_series) VALUES (?, 'Přečíslování test', '211', 'CZK', 1)")
            ->execute([$this->supplierId]);
        $this->registerId = (int) $pdo->lastInsertId();
        $this->kindScope = 990000 + random_int(1, 9999);

        $periodId = $pdo->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND starts_on <= '2099-08-01' AND ends_on >= '2099-08-31' ORDER BY starts_on DESC LIMIT 1")->fetchColumn();
        if ($periodId === false) {
            $pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2099, '2099-01-01', '2099-12-31', 'open')")
                ->execute([$this->supplierId]);
            $periodId = $this->createdPeriodId = (int) $pdo->lastInsertId();
        }
        $this->periodId = (int) $periodId;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->created['eet_records'] as $id) {
            $pdo->prepare('DELETE FROM eet_records WHERE id = ?')->execute([$id]);
        }
        foreach ($this->created['payment_events'] as $id) {
            $pdo->prepare('DELETE FROM payment_event_allocations WHERE payment_event_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM payment_events WHERE id = ?')->execute([$id]);
        }
        foreach (['cash_documents', 'other_items', 'internal_documents'] as $table) {
            foreach ($this->created[$table] as $id) {
                $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
            }
        }
        $pdo->prepare('UPDATE journal_entries SET reversed_by = NULL WHERE supplier_id = ? AND id IN (' . implode(',', [0, ...$this->created['journal_entries']]) . ')')
            ->execute([$this->supplierId]);
        foreach ($this->created['journal_entries'] as $id) {
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
        }
        foreach ($this->created['tax_evidence_closings'] as $id) {
            $pdo->prepare('DELETE FROM tax_evidence_closings WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM accounting_document_series WHERE supplier_id = ? AND fiscal_year = 2099 AND register_id IN (?, ?)")
            ->execute([$this->supplierId, $this->registerId, $this->kindScope]);
        if ($this->nativeSeriesId !== null) {
            $pdo->prepare('DELETE FROM number_series WHERE id = ?')->execute([$this->nativeSeriesId]);
        }
        if ($this->registerId > 0) {
            $pdo->prepare('DELETE FROM cash_registers WHERE id = ?')->execute([$this->registerId]);
        }
        if ($this->createdPeriodId !== null) {
            $pdo->prepare('DELETE FROM accounting_periods WHERE id = ?')->execute([$this->createdPeriodId]);
        }
        if ($this->origLockedUntil === false) {
            $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        } else {
            $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = ? WHERE supplier_id = ?')
                ->execute([$this->origLockedUntil, $this->supplierId]);
        }
        $this->db->close();
    }

    // ── pokladna ───────────────────────────────────────────────────────────────

    public function testCashRenumbersOutgoingByDateAndKeepsHandedOutNumbers(): void
    {
        $this->legacySeries('cash_out', $this->registerId, 'RNV');
        $this->legacySeries('cash_in', $this->registerId, 'RNP');
        // Zaúčtováno v opačném pořadí, než jsou data.
        $a = $this->cashDoc('out', '2099-08-20', 'RNV-2099-0001');
        $b = $this->cashDoc('out', '2099-08-05', 'RNV-2099-0002');
        $c = $this->cashDoc('out', '2099-08-12', 'RNV-2099-0003');
        $manual = $this->cashDoc('out', '2099-08-10', 'RUCNI-1');
        $income = $this->cashDoc('in', '2099-08-01', 'RNP-2099-0001');
        $vat = $this->cashDoc('out', '2099-08-02', 'RNV-2099-0004', 'vat');
        $eet = $this->cashDoc('out', '2099-08-03', 'RNV-2099-0005');
        $this->markEet($eet);

        $entry = $this->journal('2099-08-20', 'RNV-2099-0001', 'VPD RNV-2099-0001 — Pokladna — RNV-2099-00010 jinde', 'cash', $a);
        $storno = $this->journal('2099-08-25', 'STORNO RNV-2099-0001', 'Storno RNV-2099-0001: test', 'cash', null);
        $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ?')->execute([$storno, $entry]);

        $plan = $this->cash->preview($this->supplierId, self::PERIOD, ['register_id' => $this->registerId]);
        self::assertSame([], $plan['blocked']);
        $kept = array_column($plan['kept'], 'reason', 'number');
        ksort($kept);
        self::assertSame([
            'RNP-2099-0001' => 'cash_in',
            'RNV-2099-0004' => 'vat_document',
            'RNV-2099-0005' => 'eet',
            'RUCNI-1'       => 'manual_number',
        ], $kept);

        // Auditní stopa je jen připisovaná, záznamy z dřívějších běhů zůstávají.
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'cash_document.renumbered' AND supplier_id = ? AND payload LIKE ?");
        $audit->execute([$this->supplierId, '%"id":' . $a . ',%']);
        $auditBefore = (int) $audit->fetchColumn();

        $result = $this->cash->renumber($this->supplierId, self::PERIOD, ['register_id' => $this->registerId], $this->userId);

        self::assertSame(3, $result['renumbered']);
        self::assertSame('RNV-2099-0001', $this->number('cash_documents', 'doc_number', $b));
        self::assertSame('RNV-2099-0002', $this->number('cash_documents', 'doc_number', $c));
        self::assertSame('RNV-2099-0003', $this->number('cash_documents', 'doc_number', $a));
        self::assertSame('RUCNI-1', $this->number('cash_documents', 'doc_number', $manual));
        self::assertSame('RNP-2099-0001', $this->number('cash_documents', 'doc_number', $income));
        self::assertSame('RNV-2099-0004', $this->number('cash_documents', 'doc_number', $vat));
        self::assertSame('RNV-2099-0005', $this->number('cash_documents', 'doc_number', $eet));

        $rows = $this->entries([$entry, $storno]);
        self::assertSame('RNV-2099-0003', $rows[$entry]['document_no']);
        self::assertSame('VPD RNV-2099-0003 — Pokladna — RNV-2099-00010 jinde', $rows[$entry]['description'],
            'Přepíše se jen celé číslo, ne začátek delšího čísla.');
        self::assertSame('STORNO RNV-2099-0003', $rows[$storno]['document_no']);
        self::assertSame('Storno RNV-2099-0003: test', $rows[$storno]['description']);

        $audit->execute([$this->supplierId, '%"id":' . $a . ',%']);
        self::assertSame($auditBefore + 1, (int) $audit->fetchColumn());
    }

    public function testCashRefusesLockedMonth(): void
    {
        $this->legacySeries('cash_out', $this->registerId, 'RNV');
        $late = $this->cashDoc('out', '2099-08-28', 'RNV-2099-0001');
        $this->cashDoc('out', '2099-08-02', 'RNV-2099-0002');
        $this->lockUntil('2099-12-31');

        $plan = $this->cash->preview($this->supplierId, self::PERIOD, ['register_id' => $this->registerId]);
        self::assertSame(['date_locked', 'date_locked'], array_column($plan['blocked'], 'reason'));
        try {
            $this->cash->renumber($this->supplierId, self::PERIOD, ['register_id' => $this->registerId], $this->userId);
            self::fail('Zamčený měsíc se nesmí přečíslovat.');
        } catch (\DomainException) {
        }
        self::assertSame('RNV-2099-0001', $this->number('cash_documents', 'doc_number', $late));
    }

    public function testCashRequiresOwnRegister(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cash->preview($this->supplierId, self::PERIOD, ['register_id' => 0]);
    }

    // ── ostatní pohledávky a závazky ─────────────────────────────────────────

    public function testOtherPayablesRenumberByIssueDateAndRewriteJournal(): void
    {
        $this->legacySeries('other_payable', $this->kindScope, 'RNZ');
        $first = $this->otherItem('payable', '2099-08-25', 'RNZ-2099-0001');
        $second = $this->otherItem('payable', '2099-08-03', 'RNZ-2099-0002');
        $manual = $this->otherItem('payable', '2099-08-01', 'SMLOUVA-7');
        $receivable = $this->otherItem('receivable', '2099-08-02', 'RNZ-2099-0003');
        $entry = $this->journal('2099-08-25', 'RNZ-2099-0001', 'Nájem RNZ-2099-0001', 'other_item', $first);

        $result = $this->otherItems->renumber($this->supplierId, self::PERIOD, ['side' => 'payable'], $this->userId);

        self::assertSame(2, $result['renumbered']);
        self::assertSame(['SMLOUVA-7'], array_column($result['kept'], 'number'));
        self::assertSame('RNZ-2099-0001', $this->number('other_items', 'document_no', $second));
        self::assertSame('RNZ-2099-0002', $this->number('other_items', 'document_no', $first));
        self::assertSame('SMLOUVA-7', $this->number('other_items', 'document_no', $manual));
        self::assertSame('RNZ-2099-0003', $this->number('other_items', 'document_no', $receivable), 'Pohledávky se se závazky nemíchají.');
        $row = $this->entries([$entry])[$entry];
        self::assertSame('RNZ-2099-0002', $row['document_no']);
        self::assertSame('Nájem RNZ-2099-0002', $row['description']);
    }

    public function testOtherItemsRefuseYearAfterFinalTaxEvidenceClosing(): void
    {
        $this->legacySeries('other_receivable', $this->kindScope, 'RNO');
        $late = $this->otherItem('receivable', '2099-08-25', 'RNO-2099-0001');
        $this->otherItem('receivable', '2099-08-03', 'RNO-2099-0002');
        $this->db->pdo()->prepare("INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, 2099, 'final')")
            ->execute([$this->supplierId]);
        $this->created['tax_evidence_closings'][] = (int) $this->db->pdo()->lastInsertId();

        $plan = $this->otherItems->preview($this->supplierId, self::PERIOD, ['side' => 'receivable']);
        self::assertSame(['year_closed', 'year_closed'], array_column($plan['blocked'], 'reason'));
        try {
            $this->otherItems->renumber($this->supplierId, self::PERIOD, ['side' => 'receivable'], $this->userId);
            self::fail('Rok po roční uzávěrce se nesmí přečíslovat.');
        } catch (\DomainException) {
        }
        self::assertSame('RNO-2099-0001', $this->number('other_items', 'document_no', $late));
    }

    // ── interní doklady ──────────────────────────────────────────────────────

    public function testInternalDocumentsRenumberByEntryDateFromNativeSeries(): void
    {
        $this->nativeSeries('manual', 'RNI');
        $late = $this->internalDoc('2099-08-30', 'RNI990001', 'Rozpuštění RNI990001');
        $early = $this->internalDoc('2099-08-04', 'RNI990002', 'Rozpuštění RNI990002');
        $manual = $this->internalDoc('2099-08-15', 'ID-CIZI-5', 'Cizí číslo');

        $plan = $this->internal->preview($this->supplierId, self::PERIOD);
        self::assertSame(['ID-CIZI-5'], array_column($plan['kept'], 'number'));
        $result = $this->internal->renumber($this->supplierId, self::PERIOD, [], $this->userId);

        self::assertSame(2, $result['renumbered']);
        self::assertSame('RNI990001', $this->number('internal_documents', 'document_no', $early['id']));
        self::assertSame('RNI990002', $this->number('internal_documents', 'document_no', $late['id']));
        self::assertSame('ID-CIZI-5', $this->number('internal_documents', 'document_no', $manual['id']));
        $rows = $this->entries([$early['entry'], $late['entry']]);
        self::assertSame('RNI990001', $rows[$early['entry']]['document_no']);
        self::assertSame('Rozpuštění RNI990001', $rows[$early['entry']]['description']);
        self::assertSame('RNI990002', $rows[$late['entry']]['document_no']);
    }

    public function testInternalDocumentsRefuseLockedMonth(): void
    {
        $this->nativeSeries('manual', 'RNI');
        $late = $this->internalDoc('2099-08-30', 'RNI990001', 'A');
        $this->internalDoc('2099-08-04', 'RNI990002', 'B');
        $this->lockUntil('2099-08-31');

        try {
            $this->internal->renumber($this->supplierId, self::PERIOD, [], $this->userId);
            self::fail('Zamčený měsíc se nesmí přečíslovat.');
        } catch (\DomainException) {
        }
        self::assertSame('RNI990001', $this->number('internal_documents', 'document_no', $late['id']));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function legacySeries(string $code, int $registerId, string $prefix): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, next_number)
             VALUES (?, ?, 2099, ?, ?, 10)'
        )->execute([$this->supplierId, $code, $registerId, $prefix]);
    }

    private function nativeSeries(string $kind, string $prefix): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO number_series (supplier_id, doc_kind, scope_type, scope_id, storage, name, prefix, template, period, is_default)
             VALUES (?, ?, 'company', 0, 'native', 'Přečíslování test', ?, '{PREFIX}{YY}{CCCC}', 'year', 0)"
        )->execute([$this->supplierId, $kind, $prefix]);
        $this->nativeSeriesId = (int) $this->db->pdo()->lastInsertId();
    }

    private function cashDoc(string $type, string $date, string $number, string $vatMode = 'none'): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, doc_number, issue_date, purpose, description,
                                         total_amount, vat_mode, status, partner_name)
             VALUES (?, ?, ?, ?, ?, 'other', 'Test přečíslování', 100, ?, 'posted', 'Testovací partner')"
        )->execute([$this->supplierId, $this->registerId, $type, $number, $date, $vatMode]);
        return $this->created['cash_documents'][] = (int) $this->db->pdo()->lastInsertId();
    }

    /** Výdej evidovaný v EET (vrácení peněz): platební událost s opravou tržby. */
    private function markEet(int $cashDocumentId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payment_events (supplier_id, public_id, kind, origin, contact_context, amount, eet_decision,
                                         payment_order_status, recorded_at)
             VALUES (?, UUID(), 'refund', 'correction', 'in_person', -100, 'required', 'settled', NOW(6))"
        )->execute([$this->supplierId]);
        $eventId = $this->created['payment_events'][] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payment_event_allocations (supplier_id, payment_event_id, amount, cash_document_id) VALUES (?, ?, -100, ?)')
            ->execute([$this->supplierId, $eventId, $cashDocumentId]);
        $pdo->prepare(
            "INSERT INTO eet_records (supplier_id, payment_event_id, kind, eic, environment, public_id, sale_at, sale_at_offset,
                                      deadline_at, total_amount)
             VALUES (?, ?, 'correction', 'CZ00000019', 'playground', UUID(), '2099-08-03 10:00:00', '+02:00', '2099-08-08 10:00:00', -100)"
        )->execute([$this->supplierId, $eventId]);
        $this->created['eet_records'][] = (int) $pdo->lastInsertId();
    }

    private function otherItem(string $side, string $date, string $number): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO other_items (supplier_id, side, title, amount, amount_czk, issued_on, due_on, document_no, status, partner_name)
             VALUES (?, ?, 'Test přečíslování', 100, 100, ?, ?, ?, 'confirmed', 'Testovací partner')"
        )->execute([$this->supplierId, $side, $date, $date, $number]);
        return $this->created['other_items'][] = (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{id:int, entry:int} */
    private function internalDoc(string $date, string $number, string $title): array
    {
        $entry = $this->journal($date, $number, $title, 'manual', null);
        $this->db->pdo()->prepare(
            "INSERT INTO internal_documents (supplier_id, kind, title, document_date, entry_date, document_no, status, journal_entry_id)
             VALUES (?, 'other', ?, ?, ?, ?, 'posted', ?)"
        )->execute([$this->supplierId, $title, $date, $date, $number, $entry]);
        return ['id' => $this->created['internal_documents'][] = (int) $this->db->pdo()->lastInsertId(), 'entry' => $entry];
    }

    private function journal(string $date, string $documentNo, string $description, string $sourceType, ?int $sourceId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, description, source_type, source_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->periodId, $date, $documentNo, $description, $sourceType, $sourceId]);
        return $this->created['journal_entries'][] = (int) $this->db->pdo()->lastInsertId();
    }

    private function lockUntil(string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    private function number(string $table, string $column, int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT {$column} FROM {$table} WHERE id = {$id}")->fetchColumn();
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{document_no:string, description:string}>
     */
    private function entries(array $ids): array
    {
        $rows = $this->db->pdo()->query('SELECT id, document_no, description FROM journal_entries WHERE id IN (' . implode(',', $ids) . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['document_no' => (string) $r['document_no'], 'description' => (string) $r['description']];
        }
        return $out;
    }
}
