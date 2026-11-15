<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\UncancelInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #80: omylem stornovaná faktura.
 *
 *  - admin force-delete zaúčtované faktury nejdřív smaže její zápisy v deníku (jde-li to
 *    v deníku i ručně) a teprve pak fakturu — bez retenční brány, protože v účetnictví
 *    po dokladu nic nezůstalo;
 *  - „Zrušit storno" vrátí interně stornovanou fakturu do stavu před stornem.
 *
 * Commitnutá data na throwaway dodavateli (StockTestCase) — akce po commitu volají
 * StatsRecomputer s vlastní transakcí, obalující rollback transakce by jim překážela.
 */
#[Group('integration')]
final class InvoiceUncancelAndJournalPurgeTest extends StockTestCase
{
    public function testForceDeletePostedInvoiceDeletesJournalEntryFirst(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-P1');
        $entryId = $this->post($supplierId, $invoiceId);

        $res = $this->invoke(DeleteInvoiceAction::class, $supplierId, $invoiceId, [], ['force' => '1']);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame(1, $res['body']['journal_entries_deleted'] ?? null);
        self::assertSame(0, $this->rowCount('invoices', $invoiceId));
        self::assertSame(0, $this->rowCount('journal_entries', $entryId), 'Zápis je smazaný, ne stornovaný.');
        self::assertSame(0, $this->journalCount($supplierId), 'V deníku nezůstala ani storno dvojice.');
        $payload = $this->lastLog('invoice.force_deleted', $invoiceId);
        self::assertNull($payload['retention_override'] ?? null, 'Bez zápisu v deníku není co chránit retencí.');
        self::assertSame('invoice_force_delete', $this->lastLog('accounting.entry_deleted', $entryId)['reason'] ?? null);
    }

    public function testForceDeleteCancelledInvoiceDeletesReversalPair(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-P2');
        $this->post($supplierId, $invoiceId);
        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal']);
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        self::assertSame(2, $this->journalCount($supplierId), 'Předpoklad: storno dvojice v deníku.');

        $res = $this->invoke(DeleteInvoiceAction::class, $supplierId, $invoiceId, [], ['force' => '1']);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame(0, $this->rowCount('invoices', $invoiceId));
        self::assertSame(0, $this->journalCount($supplierId));
    }

    /**
     * Zápis v uzamčeném období (po podání přiznání k DPH): bez potvrzení se nic nesmaže
     * a UI dostane varování, které jde potvrdit. Dřív to skončilo retenční chybou, kterou
     * UI potvrdit neumělo, a účetní musela nejdřív ručně mazat v deníku.
     */
    public function testForceDeleteWithLockedJournalAsksForAcknowledgement(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-P3');
        $entryId = $this->post($supplierId, $invoiceId);
        $this->lockUntil($supplierId, '2099-12-31');

        $res = $this->invoke(DeleteInvoiceAction::class, $supplierId, $invoiceId, [], ['force' => '1']);

        self::assertSame(409, $res['status'], json_encode($res['body']));
        self::assertSame('date_locked', $res['body']['error']['code'] ?? null);
        self::assertTrue($res['body']['error']['can_acknowledge'] ?? false);
        self::assertStringContainsString('odpovědnost účetního', (string) $res['body']['error']['message']);
        self::assertSame(1, $this->rowCount('invoices', $invoiceId));
        self::assertSame(1, $this->rowCount('journal_entries', $entryId), 'Bez potvrzení zápis zůstal.');
    }

    public function testForceDeleteWithLockedJournalAndAcknowledgementDeletesBoth(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-P4');
        $entryId = $this->post($supplierId, $invoiceId);
        $this->lockUntil($supplierId, '2099-12-31');

        $res = $this->invoke(DeleteInvoiceAction::class, $supplierId, $invoiceId, [], ['force' => '1', 'ack_locked' => '1']);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame(0, $this->rowCount('invoices', $invoiceId));
        self::assertSame(0, $this->rowCount('journal_entries', $entryId));
        self::assertSame(0, $this->journalCount($supplierId), 'Žádný protizápis, žádná storno dvojice.');
        self::assertSame('2099-12-31', $this->lastLog('accounting.entry_deleted', $entryId)['locked_override'] ?? null,
            'Přehlasovaný zámek musí být v auditní stopě.');
    }

    public function testUncancelRestoresInvoiceAndDeletesReversalPair(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-U1');
        $this->post($supplierId, $invoiceId);
        $this->db->pdo()->prepare('UPDATE invoices SET sent_at = NOW() WHERE id = ?')->execute([$invoiceId]);
        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal']);
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        $cancellationId = (int) $cancel['body']['cancellation_id'];

        $res = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        $row = $this->invoiceRowById($invoiceId);
        self::assertSame('sent', $row['status'], 'Faktura je zpět v odeslaném stavu.');
        self::assertNull($row['cancelled_at']);
        self::assertNull($row['booked_at']);
        self::assertSame(0, $this->rowCount('invoices', $cancellationId), 'Stornovací doklad zmizel.');
        self::assertSame(0, $this->journalCount($supplierId), 'Storno dvojice je z deníku pryč.');
        self::assertNotNull($this->lastLog('invoice.uncancelled', $invoiceId));
    }

    /**
     * BEZ OPRAVY PADÁ: API token (MCP) stornoval zaúčtovanou fakturu i se zápisem v deníku
     * a zrušením storna zápisy mazal. Obojí je ruční účetní úkon jen z relace.
     */
    public function testTokenCannotCancelOrUncancelPostedInvoice(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-T1');
        $this->post($supplierId, $invoiceId);

        $denied = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal'], [], 'bearer');
        self::assertSame(403, $denied['status'], json_encode($denied['body']));
        self::assertSame('token_write_forbidden', $denied['body']['error']['code'] ?? null);
        self::assertSame('issued', $this->invoiceRowById($invoiceId)['status']);

        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal']);
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        $journal = $this->journalCount($supplierId);

        $uncancel = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId, [], [], 'bearer');
        self::assertSame(403, $uncancel['status'], json_encode($uncancel['body']));
        self::assertSame('token_write_forbidden', $uncancel['body']['error']['code'] ?? null);
        self::assertSame($journal, $this->journalCount($supplierId), 'Zápisy v deníku zůstaly.');
        self::assertSame('cancelled', $this->invoiceRowById($invoiceId)['status']);
    }

    /** Nezaúčtovanou fakturu token interně stornovat i vrátit smí, deník se nemění. */
    public function testTokenMayCancelAndUncancelUnpostedInvoice(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-T2');

        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal'], [], 'bearer');
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        $uncancel = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId, [], [], 'bearer');
        self::assertSame(200, $uncancel['status'], json_encode($uncancel['body']));
    }

    public function testUncancelBlockedWhenDateLocked(): void
    {
        $supplierId = $this->doubleEntrySupplier();
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-U2');
        $this->post($supplierId, $invoiceId);
        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal']);
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        $this->lockUntil($supplierId, '2099-12-31');

        $res = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId, [], ['force' => '1']);

        self::assertSame(409, $res['status'], json_encode($res['body']));
        // Zastaví to brána dokladu (date_locked), nebo brána deníku (journal_date_locked).
        self::assertStringEndsWith('date_locked', (string) ($res['body']['error']['code'] ?? ''));
        self::assertSame('cancelled', $this->invoiceRowById($invoiceId)['status']);
        self::assertSame(2, $this->journalCount($supplierId));
    }

    public function testUncancelRejectsInvoiceCancelledByCreditNote(): void
    {
        $supplierId = $this->createSupplier('tax_evidence', false, false);
        $clientId = $this->client($supplierId);
        $invoiceId = $this->issuedInvoice($supplierId, 'FV-2099-U3', $clientId);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$invoiceId]);
        $creditId = $this->invoiceDraft($supplierId, $clientId, 'credit_note', ['parent_invoice_id' => $invoiceId]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$creditId]);

        $res = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId);

        self::assertSame(409, $res['status']);
        self::assertSame('credit_note_issued', $res['body']['error']['code'] ?? null);
        self::assertSame('cancelled', $this->invoiceRowById($invoiceId)['status']);
    }

    public function testUncancelReissuesStock(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'UNCANCEL');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0);
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId), 'invoice', ['varsymbol' => 'FV-2099-U4']);
        $this->invoiceItem($invoiceId, $itemId, $whId, '2.000');
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $this->issue->issueForInvoice($supplierId, $this->invoiceRowForStock($invoiceId), $this->userId);
        $pdo->commit();
        $this->syncTotals($invoiceId);
        self::assertSame(3000, $this->level($supplierId, $whId, $itemId)['qtyT']);

        $cancel = $this->invoke(CancelInvoiceAction::class, $supplierId, $invoiceId, ['mode' => 'internal']);
        self::assertSame(200, $cancel['status'], json_encode($cancel['body']));
        self::assertSame(5000, $this->level($supplierId, $whId, $itemId)['qtyT'], 'Storno vrátilo zboží na sklad.');

        $res = $this->invoke(UncancelInvoiceAction::class, $supplierId, $invoiceId);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame('issued', $this->invoiceRowById($invoiceId)['status']);
        self::assertSame(3000, $this->level($supplierId, $whId, $itemId)['qtyT'], 'Zrušení storna zboží znovu vydalo.');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function doubleEntrySupplier(): int
    {
        $supplierId = $this->createSupplier('double_entry', false, false);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($supplierId, 2099, '2099-01-01', '2099-12-31');
        return $supplierId;
    }

    private function issuedInvoice(int $supplierId, string $varsymbol, ?int $clientId = null): int
    {
        $invoiceId = $this->invoiceDraft($supplierId, $clientId ?? $this->client($supplierId), 'invoice', ['varsymbol' => $varsymbol]);
        $this->invoiceItem($invoiceId, null, null, '1.000', 1000.0);
        $this->syncTotals($invoiceId);
        return $invoiceId;
    }

    private function syncTotals(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE invoices i
                JOIN (SELECT invoice_id, SUM(total_without_vat) b, SUM(total_vat) v, SUM(total_with_vat) w
                        FROM invoice_items GROUP BY invoice_id) t ON t.invoice_id = i.id
                SET i.total_without_vat = t.b, i.total_vat = t.v, i.total_with_vat = t.w,
                    i.status = 'issued', i.vat_classification_code = '1'
              WHERE i.id = ?"
        )->execute([$invoiceId]);
    }

    private function post(int $supplierId, int $invoiceId): int
    {
        $posting = $this->container->get(PostingService::class);
        return $posting->postDocument(
            $supplierId,
            'invoice',
            $invoiceId,
            $posting->buildFromInvoice($supplierId, $invoiceId),
            ['entry_date' => '2099-06-10', 'posted_by' => $this->userId, 'user_id' => $this->userId, 'posted' => true],
        );
    }

    private function lockUntil(int $supplierId, string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$supplierId, $date]);
    }

    /**
     * @param class-string $actionClass
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @return array{status:int, body:array<mixed>}
     */
    private function invoke(string $actionClass, int $supplierId, int $invoiceId, array $body = [], array $query = [], string $auth = 'session'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth);
        if ($auth === 'bearer') {
            $req = $req->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']);
        }
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }
        $resp = ($this->container->get($actionClass))($req, new Psr7Response(), ['id' => (string) $invoiceId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function rowCount(string $table, int $id): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    private function journalCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function invoiceRowForStock(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, invoice_type, parent_invoice_id, issue_date, tax_date, varsymbol FROM invoices WHERE id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = (array) $stmt->fetch(\PDO::FETCH_ASSOC);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @return array<string,mixed> */
    private function invoiceRowById(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, cancelled_at, booked_at FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function lastLog(string $action, int $entityId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$action, $entityId]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? null : (array) json_decode((string) $payload, true);
    }
}
