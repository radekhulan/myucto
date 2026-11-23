<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Deník firmy s jednoduchým zápisem: seznam nese posouzení zápisu bez dvojic a umí
 * podle něj filtrovat, detail nese formu zápisu k datu a názvy účtů dvojic. Složená
 * firma pole nedostane.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class JournalListPairsTest extends PairsTestCase
{
    public function testListAndDetailCarryPairFieldsOnlyForSingleFirm(): void
    {
        $manual = $this->container->get(ManualJournalEntryService::class);
        $meta = ['entry_date' => self::YEAR . '-05-10', 'user_id' => $this->userId, 'posted_by' => $this->userId, 'posted' => true];
        $paired = $manual->post($this->supplierId, $manual->normalizePairs($this->supplierId, [
            ['debit_account_code' => '518', 'credit_account_code' => '211', 'amount' => 300, 'line_text' => 'Služba'],
        ], true), $meta + ['description' => 'Dvojice']);
        $composite = $manual->post($this->supplierId, $manual->normalizePairs($this->supplierId, [
            ['debit_account_code' => '221', 'credit_account_code' => '211', 'amount' => 50],
        ], true), $meta + ['description' => 'Označený zápis']);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_pair_status (supplier_id, entry_id, status, reason, assessed_at)
             VALUES (?, ?, 'composite_mn', 'test', NOW())"
        )->execute([$this->supplierId, $composite]);

        $list = $this->call('list', [], []);
        $statuses = array_column($list['items'], 'pair_status', 'id');
        self::assertArrayHasKey($paired, $statuses);
        self::assertNull($statuses[$paired]);
        self::assertSame('composite_mn', $statuses[$composite]);

        $withLines = array_column($this->call('list', [], ['include_posting_accounts' => '1'])['items'], 'posting_lines', 'id');
        self::assertSame(
            [['side' => 'debit', 'amount' => 300, 'is_red_storno' => false, 'cost_center' => null, 'account_code' => '518', 'pair_no' => 1, 'line_text' => 'Služba'],
             ['side' => 'credit', 'amount' => 300, 'is_red_storno' => false, 'cost_center' => null, 'account_code' => '211', 'pair_no' => 1, 'line_text' => 'Služba']],
            array_map(static fn (array $l): array => array_diff_key($l, ['account_name' => 1]), $withLines[$paired]),
            'Řádky seznamu nesou číslo a text dvojice, nic dalšího.',
        );

        $filtered = $this->call('list', [], ['pair_status' => 'composite_mn']);
        self::assertSame([$composite], array_map('intval', array_column($filtered['items'], 'id')));
        self::assertSame(1, $filtered['total']);

        $detail = $this->call('get', ['id' => (string) $paired], []);
        self::assertSame('single', $detail['entry_form']);
        self::assertCount(1, $detail['pairs']);
        self::assertSame('518', $detail['pairs'][0]['debit_account_code']);
        self::assertNotNull($detail['pairs'][0]['debit_account_name']);
        self::assertNotNull($detail['pairs'][0]['credit_account_name']);
        $names = array_column($detail['lines'], 'account_name', 'account_code');
        self::assertSame($names['518'], $detail['pairs'][0]['debit_account_name']);
        self::assertSame($names['211'], $detail['pairs'][0]['credit_account_name']);

        // Firma bez jednoduchého zápisu pole nedostane.
        $this->db->pdo()->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        EntryFormService::forgetShared($this->supplierId);
        foreach ($this->call('list', [], [])['items'] as $item) {
            self::assertArrayNotHasKey('pair_status', $item);
        }
        self::assertArrayNotHasKey('entry_form', $this->call('get', ['id' => (string) $paired], []));
    }

    /**
     * @param array<string,string> $args
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function call(string $method, array $args, array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query + ['per_page' => '200']);
        $action = $this->container->get(JournalAction::class);
        $resp = $args === [] ? $action->{$method}($req, new Psr7Response()) : $action->{$method}($req, new Psr7Response(), $args);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return $decoded['data'] ?? $decoded;
    }
}
