<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\BulkActionAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bulk\BulkActionException;
use MyInvoice\Service\Accounting\Bulk\BulkActionService;
use MyInvoice\Service\Accounting\Bulk\BulkOperations;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadná změna poznámky faktur a variabilního symbolu bankovních pohybů: běh
 * nanečisto → ostrý běh → vrácení, důvody přeskočení, kontrola VS, stav dokladu
 * (koncept × vystavený) a výběr pohybů omezený na výpisy aktuální firmy.
 */
#[Group('integration')]
final class BulkNoteAndSymbolTest extends BankPostingTestCase
{
    private ImportJobRepository $jobs;
    private AccountingBulkItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = $this->container->get(ImportJobRepository::class);
        $this->items = $this->container->get(AccountingBulkItemRepository::class);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $params */
    private function runJob(array $params): int
    {
        $jobId = $this->jobs->create($this->supplierId, BulkActionService::SOURCE, $params, $this->userId);
        $this->container->get(BulkActionService::class)->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) $job['last_error']);
        return $jobId;
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $params
     */
    private function dryRun(string $operation, string $target, array $ids, array $params, string $origin = 'session'): int
    {
        $ids = $this->container->get(BulkSelection::class)->explicitIds($this->supplierId, $target, $ids);
        $normalized = $this->container->get(BulkOperations::class)->get($operation, $target)
            ->normalizeParams($this->supplierId, $target, ['ids' => $ids] + $params);
        return $this->runJob(['kind' => 'dry_run', 'operation' => $operation, 'target' => $target,
            'selection_count' => count($ids), 'ids' => $ids, 'origin' => $origin] + $normalized);
    }

    private function applyRun(int $dryJobId): int
    {
        $dry = $this->jobs->find($dryJobId, $this->supplierId);
        return $this->runJob(array_merge((array) $dry['params'], ['kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_item_ids' => []]));
    }

    private function rollbackRun(int $applyJobId): int
    {
        $apply = (array) $this->jobs->find($applyJobId, $this->supplierId)['params'];
        unset($apply['dry_run_job_id'], $apply['excluded_item_ids']);
        return $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyJobId] + $apply);
    }

    /** @return array<int,array<string,mixed>> */
    private function bySource(int $jobId): array
    {
        $out = [];
        foreach ($this->items->page($this->supplierId, $jobId, null, 1, 200)['items'] as $item) {
            $out[$item['source_id']] = $item;
        }
        return $out;
    }

    private function note(string $table, int $id, string $field = 'note_below_items'): ?string
    {
        $value = $this->db->pdo()->query("SELECT {$field} FROM {$table} WHERE id = {$id}")->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return array{0:?string, 1:string} */
    private function tx(int $id): array
    {
        $row = $this->db->pdo()->query("SELECT variable_symbol, match_status FROM bank_transactions WHERE id = {$id}")->fetch(\PDO::FETCH_NUM);
        return [$row[0] === null ? null : (string) $row[0], (string) $row[1]];
    }

    /**
     * @param array<string,int> $rights
     * @param array<string,mixed> $body
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function callStart(array $rights, array $body): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/bulk-actions/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $rights, ''))
            ->withParsedBody($body);
        $response = $this->container->get(BulkActionAction::class)->start($request, new Response());
        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    private function draftInvoice(?string $note = null): int
    {
        $id = $this->saleInvoice('SYN-DRAFT-' . random_int(100000, 999999), $this->client('Syntetický odběratel'), 100.0, 'invoice', 'draft');
        $this->db->pdo()->prepare('UPDATE invoices SET note_below_items = ? WHERE id = ?')->execute([$note, $id]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služba", 1, "ks", 100, (SELECT MIN(id) FROM vat_rates), 0, 100, 0, 100, 0)'
        )->execute([$id]);
        return $id;
    }

    // ── set_note ─────────────────────────────────────────────────────────────

    public function testSetNoteOnDraftsReplacesAppendsAndRollsBackExactly(): void
    {
        $empty = $this->draftInvoice();
        $filled = $this->draftInvoice('Původní text');
        $totalBefore = $this->db->pdo()->query("SELECT total_with_vat FROM invoices WHERE id = {$filled}")->fetchColumn();

        $dryId = $this->dryRun('set_note', 'invoice', [$empty, $filled], ['note_field' => 'note_below_items', 'note_mode' => 'append', 'note_text' => "Splatné převodem.\r\n"]);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$filled]['status'], json_encode($dry[$filled]));
        self::assertSame("Původní text\nSplatné převodem.", $dry[$filled]['after']['note_below_items']);
        self::assertSame('Původní text', $this->note('invoices', $filled), 'Nanečisto se nic neuloží.');

        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$empty]['status'], json_encode($this->bySource($applyId)[$empty]));
        self::assertSame('Splatné převodem.', $this->note('invoices', $empty));
        self::assertSame("Původní text\nSplatné převodem.", $this->note('invoices', $filled));
        self::assertSame($totalBefore, $this->db->pdo()->query("SELECT total_with_vat FROM invoices WHERE id = {$filled}")->fetchColumn(),
            'Úprava poznámky nepřepočítá doklad.');
        $audit = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'invoice.updated' AND entity_id = {$filled}")->fetchColumn();
        self::assertSame(1, $audit, 'Uložení jde cestou editoru včetně jeho auditní stopy.');

        $this->db->pdo()->prepare('UPDATE invoices SET note_below_items = ? WHERE id = ?')->execute(['Ručně změněno', $empty]);
        $rolled = $this->bySource($this->rollbackRun($applyId));
        self::assertSame(['failed', 'changed_after_apply'], [$rolled[$empty]['status'], $rolled[$empty]['reason_code']]);
        self::assertSame('applied', $rolled[$filled]['status']);
        self::assertSame('Původní text', $this->note('invoices', $filled));
        self::assertSame('Ručně změněno', $this->note('invoices', $empty));

        $clear = $this->applyRun($this->dryRun('set_note', 'invoice', [$filled], ['note_field' => 'note_below_items', 'note_mode' => 'clear']));
        self::assertSame('applied', $this->bySource($clear)[$filled]['status']);
        self::assertNull($this->note('invoices', $filled));
    }

    public function testSetNoteOnIssuedInvoiceNeedsOptInSessionAndOpenPeriod(): void
    {
        $issued = $this->saleInvoice('SYN-NOTE-' . random_int(100000, 999999), $this->client('Syntetický odběratel'), 100.0);
        $params = ['note_field' => 'note_above_items', 'note_mode' => 'replace', 'note_text' => 'Děkujeme za objednávku.'];

        $item = $this->bySource($this->dryRun('set_note', 'invoice', [$issued], $params))[$issued];
        self::assertSame(['skipped', 'not_draft'], [$item['status'], $item['reason_code']], 'Bez volby se vystavený doklad nemění.');

        $item = $this->bySource($this->dryRun('set_note', 'invoice', [$issued], ['include_issued' => true] + $params, 'background'))[$issued];
        self::assertSame(['skipped', 'session_required'], [$item['status'], $item['reason_code']]);

        $dryId = $this->dryRun('set_note', 'invoice', [$issued], ['include_issued' => true] + $params);
        self::assertSame(['would_change', 'issued'], [$this->bySource($dryId)[$issued]['status'], $this->bySource($dryId)[$issued]['strategy']],
            json_encode($this->bySource($dryId)[$issued]));
        self::assertNull($this->note('invoices', $issued, 'note_above_items'));
        $applyId = $this->applyRun($dryId);
        self::assertSame('applied', $this->bySource($applyId)[$issued]['status'], json_encode($this->bySource($applyId)[$issued]));
        self::assertSame('Děkujeme za objednávku.', $this->note('invoices', $issued, 'note_above_items'));
        self::assertSame('issued', $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$issued}")->fetchColumn());
        $forced = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'invoice.force_updated' AND entity_id = {$issued}")->fetchColumn();
        self::assertSame(1, $forced, 'Vystavený doklad se ukládá jako vynucená úprava z editoru.');
    }

    public function testApplyTakesOriginFromItsOwnRequestNotFromDryRun(): void
    {
        // Běh nanečisto spuštěný v aplikaci nesmí API tokenu, který ho provádí, propůjčit
        // úkony vyhrazené relaci (úprava vystaveného dokladu).
        $issued = $this->saleInvoice('SYN-ORIG-' . random_int(100000, 999999), $this->client('Syntetický odběratel'), 100.0);
        $dryId = $this->dryRun('set_note', 'invoice', [$issued], ['include_issued' => true,
            'note_field' => 'note_above_items', 'note_mode' => 'replace', 'note_text' => 'Text'], 'session');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/bulk-actions/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'token')
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true,
                ['accounting' => 2, 'accounting.bulk_changes' => 2, 'invoices' => 2], ''));
        $apply = new \ReflectionMethod(BulkActionAction::class, 'applyParams');
        $params = $apply->invoke($this->container->get(BulkActionAction::class), $request, $this->supplierId,
            ['dry_run' => false, 'dry_run_job_id' => $dryId]);
        self::assertSame('session', $this->jobs->find($dryId, $this->supplierId)['params']['origin']);
        self::assertSame('background', $params['origin'], 'Provedení přes API token nesmí zdědit původ z relace.');
    }

    public function testSetNoteSkipsIssuedInvoiceInClosedPeriod(): void
    {
        // Zámky období si služba drží po dobu procesu (jedna úloha = jeden proces workeru),
        // proto se období zavírá ještě před první úlohou testu.
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $issued = $this->saleInvoice('SYN-NOTE-' . random_int(100000, 999999), $this->client('Syntetický odběratel'), 100.0);
        $item = $this->bySource($this->dryRun('set_note', 'invoice', [$issued], ['include_issued' => true,
            'note_field' => 'note_above_items', 'note_mode' => 'replace', 'note_text' => 'Text']))[$issued];
        self::assertSame(['skipped', 'period_closed'], [$item['status'], $item['reason_code']], 'Přebití uzavřeného období hromadná akce nenabízí.');
        self::assertNull($this->note('invoices', $issued, 'note_above_items'));
    }

    public function testSetNoteOnPurchaseDraftAndCancelledIsSkipped(): void
    {
        $vendor = $this->client('Syntetický dodavatel');
        $draft = $this->purchaseInvoice('SYN-PN-' . random_int(100000, 999999), $vendor, 100.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$draft]);
        $cancelled = $this->purchaseInvoice('SYN-PN-' . random_int(100000, 999999), $vendor, 100.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$cancelled]);

        $applyId = $this->applyRun($this->dryRun('set_note', 'purchase_invoice', [$draft, $cancelled],
            ['note_field' => 'note_below_items', 'note_mode' => 'replace', 'note_text' => 'Interní poznámka', 'include_issued' => true]));
        $items = $this->bySource($applyId);
        self::assertSame('applied', $items[$draft]['status'], json_encode($items[$draft]));
        self::assertSame('Interní poznámka', $this->note('purchase_invoices', $draft));
        self::assertArrayNotHasKey($cancelled, $items, 'Storno doklad se ve výběru přeskočí už nanečisto.');
        $dry = $this->bySource((int) $this->jobs->find($applyId, $this->supplierId)['params']['dry_run_job_id']);
        self::assertSame(['skipped', 'not_editable'], [$dry[$cancelled]['status'], $dry[$cancelled]['reason_code']], json_encode($dry[$cancelled]));
        self::assertNull($this->note('purchase_invoices', $cancelled));
    }

    public function testSetNoteParamsAreValidated(): void
    {
        $operation = $this->container->get(BulkOperations::class)->get('set_note', 'invoice');
        foreach ([
            ['note_field' => 'note', 'note_mode' => 'replace', 'note_text' => 'x'],
            ['note_field' => 'note_below_items', 'note_mode' => 'replace', 'note_text' => "  \n "],
            ['note_field' => 'note_below_items', 'note_mode' => 'append', 'note_text' => str_repeat('a', 2001)],
        ] as $raw) {
            try {
                $operation->normalizeParams($this->supplierId, 'invoice', $raw);
                self::fail('Neplatné parametry prošly: ' . json_encode($raw));
            } catch (BulkActionException $e) {
                self::assertSame('validation_failed', $e->errorCode);
            }
        }
    }

    // ── set_variable_symbol ──────────────────────────────────────────────────

    public function testSetVariableSymbolMakesMovementMatchAutomatically(): void
    {
        $vs = (string) random_int(1000000, 9999999);
        $invoice = $this->saleInvoice($vs, $this->client('Syntetický odběratel'), 1210.0);
        $statement = $this->statement();
        $tx = $this->transaction($statement, 1210.0, ['description' => 'Faktura ' . $vs]);
        $other = $this->transaction($statement, 55.0, ['description' => 'Platba bez čísla']);

        $dryId = $this->dryRun('set_variable_symbol', 'bank_transaction', [$tx, $other], ['vs_mode' => 'pattern', 'vs_pattern' => '{zprava}']);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$tx]['status'], json_encode($dry[$tx]));
        self::assertSame($vs, $dry[$tx]['after']['variable_symbol']);
        self::assertSame(['skipped', 'invalid_variable_symbol'], [$dry[$other]['status'], $dry[$other]['reason_code']]);
        self::assertSame([null, 'unmatched'], $this->tx($tx), 'Nanečisto se nic neuloží.');

        $applied = $this->bySource($this->applyRun($dryId))[$tx];
        self::assertSame('applied', $applied['status'], json_encode($applied));
        self::assertSame($vs, $this->tx($tx)[0]);
        self::assertSame('auto_exact', $this->tx($tx)[1], 'Po opravě symbolu pohyb projde automatickým párováním.');
        self::assertSame($invoice, (int) $this->db->pdo()->query("SELECT matched_invoice_id FROM bank_transactions WHERE id = {$tx}")->fetchColumn());
        self::assertSame('auto_exact', $applied['after']['match_status']);
        $audit = $this->db->pdo()->query("SELECT id FROM activity_log WHERE action = 'bulk_action.item_applied'
            AND entity_type = 'bank_transaction' AND entity_id = {$tx}")->fetchColumn();
        self::assertNotFalse($audit, 'Změna pohybu je v auditní stopě.');
    }

    public function testSetVariableSymbolSkipsSettledMovementsAndRollsBack(): void
    {
        $statement = $this->statement();
        $free = $this->transaction($statement, 10.0, ['variable_symbol' => '111']);
        $ignored = $this->transaction($statement, 11.0, ['match_status' => 'ignored']);
        $invoice = $this->saleInvoice((string) random_int(1000000, 9999999), $this->client('Syntetický odběratel'), 12.0);
        $matched = $this->transaction($statement, 12.0, ['match_status' => 'manual', 'matched_invoice_id' => $invoice]);
        $posted = $this->transaction($statement, 13.0);
        // Syntetická účtová osnova v CI má jen syntetický 221, analytika 221.100 je jen v lokální DB.
        $this->postPredpis('bank', $posted, '221', '518', 13.0);

        $dryId = $this->dryRun('set_variable_symbol', 'bank_transaction', [$free, $ignored, $matched, $posted], ['vs_mode' => 'constant', 'vs_value' => '20990001']);
        $dry = $this->bySource($dryId);
        self::assertSame('would_change', $dry[$free]['status']);
        self::assertSame(['skipped', 'ignored'], [$dry[$ignored]['status'], $dry[$ignored]['reason_code']]);
        self::assertSame(['skipped', 'already_matched'], [$dry[$matched]['status'], $dry[$matched]['reason_code']]);
        self::assertSame(['skipped', 'posted'], [$dry[$posted]['status'], $dry[$posted]['reason_code']]);

        $applyId = $this->applyRun($dryId);
        self::assertSame(['20990001', 'unmatched'], $this->tx($free));
        self::assertSame('applied', $this->bySource($this->rollbackRun($applyId))[$free]['status']);
        self::assertSame(['111', 'unmatched'], $this->tx($free), 'Vrácení obnoví původní symbol.');
    }

    public function testVariableSymbolValidation(): void
    {
        $operation = $this->container->get(BulkOperations::class)->get('set_variable_symbol', 'bank_transaction');
        foreach ([
            ['vs_mode' => 'constant', 'vs_value' => '12A4'],
            ['vs_mode' => 'constant', 'vs_value' => '12345678901'],
            ['vs_mode' => 'constant', 'vs_value' => ''],
            ['vs_mode' => 'pattern', 'vs_pattern' => '2099'],
            ['vs_mode' => 'pattern', 'vs_pattern' => 'FA{zprava}'],
            ['vs_mode' => 'pattern', 'vs_pattern' => '{neznama}'],
            ['vs_mode' => 'other'],
        ] as $raw) {
            try {
                $operation->normalizeParams($this->supplierId, 'bank_transaction', $raw);
                self::fail('Neplatný symbol prošel: ' . json_encode($raw));
            } catch (BulkActionException $e) {
                self::assertSame('validation_failed', $e->errorCode);
            }
        }
        self::assertSame('0012345678', $operation->normalizeParams($this->supplierId, 'bank_transaction', ['vs_mode' => 'constant', 'vs_value' => ' 0012345678 '])['vs_value']);

        $tx = $this->transaction($this->statement(), 1.0, ['variable_symbol' => '123456', 'description' => 'x']);
        $item = $this->bySource($this->dryRun('set_variable_symbol', 'bank_transaction', [$tx], ['vs_mode' => 'pattern', 'vs_pattern' => '{vs}{vs}']))[$tx];
        self::assertSame(['skipped', 'invalid_variable_symbol'], [$item['status'], $item['reason_code']], 'Výsledek vzoru delší než 10 číslic se nezapíše.');
    }

    public function testBankSelectionIsLimitedToOwnStatementsAndListFilter(): void
    {
        $rights = ['accounting' => 2, 'accounting.bulk_changes' => 2, 'bank' => 2, 'bank.match' => 2];
        $marker = 'SYNBULK' . random_int(100000, 999999);
        $statement = $this->statement();
        $a = $this->transaction($statement, 21.0, ['counterparty_name' => $marker]);
        $b = $this->transaction($statement, 22.0, ['counterparty_name' => $marker]);
        $this->transaction($statement, 23.0, ['counterparty_name' => 'Jiná protistrana']);
        $foreignStatement = $this->statement('1000000005', '0100', $this->otherSupplierId());
        $foreign = $this->transaction($foreignStatement, 24.0, ['counterparty_name' => $marker]);

        $body = ['dry_run' => true, 'operation' => 'set_variable_symbol', 'target' => 'bank_transaction',
            'params' => ['vs_mode' => 'constant', 'vs_value' => '42']];
        [$status, $resp] = $this->callStart($rights, ['selection' => ['ids' => [$a, $foreign]]] + $body);
        self::assertSame([422, 'foreign_ids'], [$status, $resp['error']['code'] ?? null], json_encode($resp));

        [$status, $resp] = $this->callStart(['accounting' => 2, 'accounting.bulk_changes' => 2, 'bank' => 2], ['selection' => ['ids' => [$a]]] + $body);
        self::assertSame([409, 'operation_unavailable'], [$status, $resp['error']['code'] ?? null], 'Symbol mění jen ten, kdo smí párovat.');

        $selection = $this->container->get(\MyInvoice\Service\Bank\BankMovementListService::class)
            ->listIds($this->supplierId, ['q' => $marker, 'year' => self::YEAR], 'all', BulkSelection::LIMIT + 1);
        self::assertSame(['ids' => [$a, $b], 'total' => 2], $selection, 'Cizí pohyb se stejnou protistranou do výběru nepatří.');
        $none = $this->container->get(\MyInvoice\Service\Bank\BankMovementListService::class)
            ->listIds($this->supplierId, ['q' => $marker, 'year' => self::YEAR + 1], 'all', BulkSelection::LIMIT + 1);
        self::assertSame(0, $none['total'], 'Filtr roku je rozsah dat.');
    }
}
