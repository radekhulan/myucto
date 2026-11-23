<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\BankBulkAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bank\BankBulkService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Bankovní pohyby podle filtru (Doúčtovat doklady): hromadné zaúčtování nespárovaných
 * pohybů na protiúčet a spárování několika pohybů s jedním dokladem, obojí nanečisto,
 * ostře a vrácení.
 */
#[Group('integration')]
final class BankBulkTest extends BankPostingTestCase
{
    private BankBulkService $bulk;
    private ImportJobRepository $jobs;
    private AccountingBulkItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bulk = $this->container->get(BankBulkService::class);
        $this->jobs = $this->container->get(ImportJobRepository::class);
        $this->items = $this->container->get(AccountingBulkItemRepository::class);
    }

    private function filter(array $over = []): array
    {
        return $over + [
            'action' => 'post', 'date_from' => self::YEAR . '-06-01', 'date_to' => self::YEAR . '-06-30',
            'direction' => 'out', 'text' => 'mzda', 'counter_account' => '331',
        ];
    }

    private function runJob(array $params): int
    {
        $jobId = $this->jobs->create($this->supplierId, BankBulkService::SOURCE, $params, $this->userId);
        $this->bulk->run($jobId);
        return $jobId;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function byStatus(int $jobId): array
    {
        $out = [];
        foreach ($this->items->page($this->supplierId, $jobId, null, 1, 200)['items'] as $item) $out[$item['status']][] = $item;
        return $out;
    }

    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/bank-bulk/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Správce', 'staff', true, ['accounting' => 2, 'accounting.journal.post' => 2, 'accounting.journal.bulk_repost' => 2, 'bank' => 2, 'bank.match' => 2, 'invoices' => 2, 'purchase_invoices' => 2], 'admin'))
            ->withParsedBody($body);
    }

    private function call(string $method, array $body, array $args = []): array
    {
        $action = $this->container->get(BankBulkAction::class);
        $resp = $args === [] ? $action->{$method}($this->request($body), new Response()) : $action->{$method}($this->request($body), new Response(), $args);
        $resp->getBody()->rewind();
        return [$resp->getStatusCode(), json_decode((string) $resp->getBody(), true)];
    }

    public function testUnmatchedSalaryPaymentsArePostedAndRolledBack(): void
    {
        $statement = $this->statement();
        $a = $this->transaction($statement, -21000.0, ['description' => 'Mzda 06']);
        $b = $this->transaction($statement, -19500.0, ['description' => 'Mzda 06']);
        $other = $this->transaction($statement, -800.0, ['description' => 'Nájem']);

        $count = $this->bulk->count($this->supplierId, $this->bulk->normalizeFilter($this->supplierId, $this->filter()));
        self::assertSame(2, $count['matching']);
        self::assertSame(-40500.0, $count['total_amount']);

        $dryId = $this->runJob(['kind' => 'dry_run'] + $this->bulk->normalizeFilter($this->supplierId, $this->filter()));
        self::assertCount(2, $this->byStatus($dryId)['would_change'] ?? [], json_encode($this->byStatus($dryId)));
        self::assertSame(0, $this->entryCountForTx($a), 'Nanečisto nesmí nic zaúčtovat.');

        $dry = $this->jobs->find($dryId, $this->supplierId);
        $applyId = $this->runJob(array_merge((array) $dry['params'], ['kind' => 'apply', 'dry_run_job_id' => $dryId, 'excluded_ids' => [$b]]));
        self::assertSame('completed', $this->jobs->find($applyId, $this->supplierId)['status']);
        $entry = $this->journal->findBySource($this->supplierId, 'bank', $a);
        self::assertNotNull($entry);
        $lines = $this->linesByAccountCode((int) $entry['id']);
        self::assertSame(21000.0, $lines['331']['debit']);
        self::assertSame(21000.0, $lines['221']['credit']);
        self::assertSame(0, $this->entryCountForTx($b), 'Ručně vyřazený pohyb zůstane nezaúčtovaný.');
        self::assertSame(0, $this->entryCountForTx($other));
        self::assertSame('excluded', ($this->byStatus($applyId)['excluded'][0] ?? [])['status'] ?? null);

        $rollbackId = $this->runJob(['kind' => 'rollback', 'rollback_of_job_id' => $applyId]);
        self::assertCount(1, $this->byStatus($rollbackId)['applied'] ?? [], json_encode($this->byStatus($rollbackId)));
        $active = $this->journal->findBySource($this->supplierId, 'bank', $a);
        self::assertTrue($active === null || $active['reversed_by'] !== null, 'Po vrácení nemá pohyb živý zápis.');
    }

    public function testApplySkipsTransactionPostedAfterDryRun(): void
    {
        $tx = $this->transaction($this->statement(), -1000.0, ['description' => 'Mzda 06']);
        $dryId = $this->runJob(['kind' => 'dry_run'] + $this->bulk->normalizeFilter($this->supplierId, $this->filter()));
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '395', 'credit_account_code' => '221'], $this->meta());
        $dry = $this->jobs->find($dryId, $this->supplierId);
        $applyId = $this->runJob(array_merge((array) $dry['params'], ['kind' => 'apply', 'dry_run_job_id' => $dryId, 'excluded_ids' => []]));
        self::assertSame('changed_after_dry_run', ($this->byStatus($applyId)['skipped'][0] ?? [])['reason_code'] ?? null);
        self::assertArrayHasKey('395', $this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id']));
    }

    public function testCardTerminalIncomesAreMatchedWithOneInvoiceAndUnmatched(): void
    {
        $invoiceId = $this->saleInvoice('SYNJR' . random_int(1000, 9999), $this->client('Syntetický odběratel'), 1000.0);
        $statement = $this->statement();
        $t1 = $this->transaction($statement, 300.0, ['description' => 'Terminál karta']);
        $t2 = $this->transaction($statement, 300.0, ['description' => 'Terminál karta', 'posted_at' => self::YEAR . '-06-16']);
        $t3 = $this->transaction($statement, 500.0, ['description' => 'Terminál karta', 'posted_at' => self::YEAR . '-06-17']);

        $filter = ['action' => 'match', 'date_from' => self::YEAR . '-06-01', 'date_to' => self::YEAR . '-06-30',
            'text' => 'Terminál', 'document_type' => 'invoice', 'document_id' => $invoiceId];
        [$status, $body] = $this->call('start', $filter + ['dry_run' => true]);
        self::assertSame(201, $status, json_encode($body));
        $dryId = (int) $body['job_id'];
        $dry = $this->byStatus($dryId);
        self::assertSame([$t1, $t2], array_column($dry['would_change'] ?? [], 'source_id'), json_encode($dry));
        self::assertSame('over_remaining', $dry['skipped'][0]['reason_code']);
        self::assertSame($t3, $dry['skipped'][0]['source_id']);

        [$status, $body] = $this->call('start', ['dry_run' => false, 'dry_run_job_id' => $dryId, 'excluded_ids' => []]);
        self::assertSame(201, $status, json_encode($body));
        $applyId = (int) $body['job_id'];
        self::assertCount(2, $this->byStatus($applyId)['applied'] ?? [], json_encode($this->byStatus($applyId)));
        $paid = (float) $this->db->pdo()->query('SELECT paid_total FROM invoices WHERE id = ' . $invoiceId)->fetchColumn();
        self::assertSame(600.0, $paid);
        self::assertSame('issued', $this->db->pdo()->query('SELECT status FROM invoices WHERE id = ' . $invoiceId)->fetchColumn());

        [$status, $body] = $this->call('rollback', [], ['id' => (string) $applyId]);
        self::assertSame(201, $status, json_encode($body));
        self::assertCount(2, $this->byStatus((int) $body['job_id'])['applied'] ?? [], json_encode($this->byStatus((int) $body['job_id'])));
        self::assertSame(0.0, (float) $this->db->pdo()->query('SELECT paid_total FROM invoices WHERE id = ' . $invoiceId)->fetchColumn());
        self::assertSame('unmatched', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id = ' . $t1)->fetchColumn());
    }
}
