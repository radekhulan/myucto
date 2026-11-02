<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyInvoice\Action\Accounting\AiAssistantAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\OtherItemKindService;
use MyInvoice\Service\Ai\AccountingAssistantService;
use MyInvoice\Service\Ai\AiAssistantHistoryService;
use MyInvoice\Service\Ai\AiInternalDocumentValidator;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\DocumentTextExtractor;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * AI asistent navrhuje interní doklady: kontrola návrhu stejnými pravidly jako ruční
 * zaúčtování interního dokladu, založení a zaúčtování potvrzeného návrhu přes
 * InternalDocumentService a právo zápisu deníku. Poskytovatel LLM je podvržený
 * (Guzzle MockHandler), žádné placené volání.
 */
#[Group('integration')]
final class AccountingAssistantInternalDocumentTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplierId;
    private int $userId;
    /** @var array<int, array<string,mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje místní databázi.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testConfirmedProposalIsCreatedAndPostedThroughInternalDocumentService(): void
    {
        $out = $this->ask([self::proposal()]);

        self::assertStringContainsString('`internal_documents`', $this->sentSystem());
        self::assertStringContainsString('Účetnictví → Interní doklady', $this->sentSystem());
        self::assertArrayHasKey('internal_documents', $this->sentSchema()['properties']);
        self::assertCount(1, $out['internal_documents']);
        $proposal = $out['internal_documents'][0];
        self::assertSame([], $proposal['problems']);
        self::assertTrue($proposal['postable']);
        self::assertSame('reclassification', $proposal['kind']);

        // Stejné mapování jako rozhraní (AiInternalDocumentProposal.vue) a stejná služba jako ruční doklad.
        $service = $this->container->get(InternalDocumentService::class);
        $draft = $service->create($this->supplierId, self::payload($proposal), $this->userId ?: null);
        self::assertSame('draft', $draft['status']);
        self::assertSame(5000.0, $draft['amount']);
        $posted = $service->post($this->supplierId, (int) $draft['id'], ['user_id' => null, 'posted_by' => null]);
        self::assertSame('posted', $posted['status']);
        self::assertSame('ID-' . self::YEAR . '-0001', $posted['document_no']);

        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(a.account_code, ':', l.side, ':', l.amount)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id"
        );
        $stmt->execute([(int) $posted['journal_entry_id'], $this->supplierId]);
        self::assertSame(['501:debit:5000.00', '518:credit:5000.00'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Saldokontní účty (zápočet 321/311) projdou stejně jako u ručního dokladu; partner se dohledá podle IČO. */
    public function testOffsetOnSettlementAccountsFollowsManualRules(): void
    {
        $this->pdo->prepare("UPDATE clients SET ic = '00000019' WHERE id = ?")->execute([$this->client()]);
        $out = $this->ask([self::proposal([
            'kind' => 'offset', 'title' => 'Zápočet', 'partner_name' => 'Syntetický partner', 'partner_ico' => '00000019',
            'lines' => [
                ['account_code' => '321', 'side' => 'debit', 'amount' => 1500, 'dimension_value_ids' => []],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 1500, 'dimension_value_ids' => []],
            ],
        ])]);

        $proposal = $out['internal_documents'][0];
        self::assertTrue($proposal['postable'], implode(' ', $proposal['problems']));
        self::assertNotNull($proposal['partner_id']);
        $service = $this->container->get(InternalDocumentService::class);
        $posted = $service->post($this->supplierId, (int) $service->create($this->supplierId, self::payload($proposal), null)['id'], []);
        self::assertSame('posted', $posted['status']);
        self::assertSame($proposal['partner_id'], $posted['partner_id']);
    }

    /** Dimenze: model vidí aktivní hodnoty, návrh je dostane ve tvaru agendy a zaúčtování je přenese na řádek. */
    public function testDimensionsAreOfferedValidatedAndPosted(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $type = $dimensions->createType($this->supplierId, ['code' => 'zzaiproj', 'name' => 'Zakázka AI']);
        $value = $dimensions->createValue($this->supplierId, (int) $type['id'], ['code' => 'ZZ-01', 'name' => 'Stavba']);
        $valueId = (int) $value['id'];

        $proposal = $this->ask([self::proposal(['lines' => [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 5000, 'dimension_value_ids' => [$valueId]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 5000, 'dimension_value_ids' => []],
        ]])])['internal_documents'][0];
        self::assertStringContainsString($valueId . ' – Zakázka AI: ZZ-01 Stavba', $this->sentUserText());
        self::assertTrue($proposal['postable'], implode(' ', $proposal['problems']));
        self::assertSame([(int) $type['id'] => $valueId], $proposal['lines'][0]['dimensions']);
        self::assertSame(['Zakázka AI: ZZ-01 Stavba'], $proposal['lines'][0]['dimension_labels']);

        $service = $this->container->get(InternalDocumentService::class);
        $posted = $service->post($this->supplierId, (int) $service->create($this->supplierId, self::payload($proposal), null)['id'], []);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM journal_entry_line_dimensions d
            JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ? AND d.dimension_value_id = ?');
        $stmt->execute([(int) $posted['journal_entry_id'], $valueId]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $invalid = $this->ask([self::proposal(['lines' => [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 5000, 'dimension_value_ids' => [999999999]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 5000, 'dimension_value_ids' => []],
        ]])])['internal_documents'][0];
        self::assertFalse($invalid['postable']);
        self::assertStringContainsString('Neplatná hodnota dimenze', implode(' ', $invalid['problems']));
    }

    public function testClosedPeriodLockedDateAndUnbalancedEntryBlockProposal(): void
    {
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR]);
        $closed = $this->ask([self::proposal()])['internal_documents'][0];
        self::assertFalse($closed['postable']);
        self::assertStringContainsString('do uzavřeného období nelze účtovat', implode(' ', $closed['problems']));
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR]);

        $this->pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, self::YEAR . '-10-31']);
        $locked = $this->ask([self::proposal()])['internal_documents'][0];
        self::assertSame(['Datum účetního případu ' . self::YEAR . '-09-30 je uzamčené k ' . self::YEAR . '-10-31.'], $locked['problems']);
        $this->pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);

        $unbalanced = $this->ask([self::proposal(['lines' => [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 5000, 'dimension_value_ids' => []],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 4000, 'dimension_value_ids' => []],
        ]])])['internal_documents'][0];
        self::assertFalse($unbalanced['balanced']);
        self::assertFalse($unbalanced['postable']);
    }

    /** Neaktivní a závěrkový účet odmítne kontrola návrhu i zaúčtování ručního dokladu, stejnou cestou. */
    public function testInactiveAndClosingAccountsAreRejectedLikeManualPosting(): void
    {
        $this->pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '518'")
            ->execute([$this->supplierId]);
        $inactive = $this->ask([self::proposal()])['internal_documents'][0];
        self::assertFalse($inactive['postable']);
        self::assertStringContainsString('deaktivovaný', implode(' ', $inactive['problems']));
        $this->assertManualPostingRejects($inactive);

        $stmt = $this->pdo->prepare("SELECT account_code FROM chart_of_accounts
            WHERE supplier_id = ? AND account_type = 'closing' AND is_active = 1 ORDER BY account_code LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $closingCode = $stmt->fetchColumn();
        if ($closingCode === false) {
            return;
        }
        $closing = $this->ask([self::proposal(['lines' => [
            ['account_code' => (string) $closingCode, 'side' => 'debit', 'amount' => 100, 'dimension_value_ids' => []],
            ['account_code' => '501', 'side' => 'credit', 'amount' => 100, 'dimension_value_ids' => []],
        ]])])['internal_documents'][0];
        self::assertFalse($closing['postable']);
        self::assertStringContainsString('závěrkový', implode(' ', $closing['problems']));
        $this->assertManualPostingRejects($closing);
    }

    public function testWithoutPermissionOrDoubleEntryNoInternalDocumentIsProposed(): void
    {
        $out = $this->ask([self::proposal()], false);
        self::assertSame([], $out['internal_documents'], 'bez práva server návrh zahodí');
        self::assertStringContainsString('`internal_documents` vždy prázdné pole', $this->sentSystem());

        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        self::assertSame([], $this->ask([self::proposal()])['internal_documents'], 'daňová evidence agendu nemá');
    }

    /** Akce asistenta pouští návrh interního dokladu jen s právem, které vyžaduje založení interního dokladu. */
    public function testActionUsesInternalDocumentWritePermission(): void
    {
        $read = ['accounting' => 1];
        $write = ['accounting' => 2, 'accounting.journal.write' => 2];
        self::assertFalse(RequestAuthorization::canCreateInternalDocument($this->request($read)));
        self::assertTrue(RequestAuthorization::canCreateInternalDocument($this->request($write)));

        foreach ([[$read, 0], [$write, 1]] as [$permissions, $expected]) {
            $action = new AiAssistantAction($this->service([self::aiResponse([self::proposal()])]), new AiAssistantHistoryService($this->db));
            $response = $action->ask($this->request($permissions)->withParsedBody(['question' => 'Přeúčtuj 5 000 z 518 na 501.']), new Response());
            self::assertSame(200, $response->getStatusCode());
            $response->getBody()->rewind();
            $body = json_decode((string) $response->getBody(), true);
            $data = $body['data'] ?? $body;
            self::assertCount($expected, $data['internal_documents']);
        }
    }

    /** @param array<string,mixed> $proposal */
    private function assertManualPostingRejects(array $proposal): void
    {
        $service = $this->container->get(InternalDocumentService::class);
        $draft = $service->create($this->supplierId, self::payload($proposal), null);
        try {
            $service->post($this->supplierId, (int) $draft['id'], []);
            self::fail('Ruční zaúčtování musí návrh odmítnout stejně jako kontrola návrhu.');
        } catch (InternalDocumentException|\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertContains($e->getMessage(), $proposal['problems']);
        }
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private static function proposal(array $changes = []): array
    {
        return $changes + [
            'kind' => 'reclassification', 'title' => 'Přeúčtování služeb na materiál', 'entry_date' => self::YEAR . '-09-30',
            'document_date' => self::YEAR . '-09-30', 'partner_name' => '', 'partner_ico' => '', 'note' => '',
            'lines' => [
                ['account_code' => '501', 'side' => 'debit', 'amount' => 5000, 'dimension_value_ids' => []],
                ['account_code' => '518', 'side' => 'credit', 'amount' => 5000, 'dimension_value_ids' => []],
            ],
        ];
    }

    /**
     * Tělo `POST /api/accounting/internal-documents` z návrhu, jak ho skládá rozhraní.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function payload(array $p): array
    {
        return [
            'kind' => $p['kind'], 'title' => $p['title'], 'document_date' => $p['document_date'], 'entry_date' => $p['entry_date'],
            'partner_id' => $p['partner_id'], 'partner_name' => $p['partner_name'], 'note' => $p['note'],
            'lines' => array_map(static fn (array $l): array => [
                'account_code' => $l['account_code'], 'side' => $l['side'], 'amount' => $l['amount'], 'dimensions' => $l['dimensions'],
            ], $p['lines']),
            'document_links' => [],
        ];
    }

    /** @param list<array<string,mixed>> $docs */
    private static function aiResponse(array $docs): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => json_encode([
                'answer' => 'Navrhuji interní doklad.', 'other_items' => [], 'internal_documents' => $docs,
                'journal_entries' => [], 'document_suggestions' => [], 'warnings' => [],
            ])]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]));
    }

    /**
     * @param list<array<string,mixed>> $docs
     * @return array<string,mixed>
     */
    private function ask(array $docs, bool $allowed = true): array
    {
        return $this->service([self::aiResponse($docs)])->ask(
            $this->supplierId, DocumentViewerContext::admin(), 'Přeúčtuj 5 000 z 518 na 501.', [], [], [], false, null, $allowed);
    }

    /** @param list<mixed> $responses */
    private function service(array $responses): AccountingAssistantService
    {
        $this->history = [];
        $c = $this->container;
        return new AccountingAssistantService(
            $this->db,
            FakeAiProviderHttpClientFactory::create($this, 'anthropic', $responses, $this->history),
            $c->get(DocumentRepository::class),
            $c->get(DocumentFolderRepository::class),
            $c->get(DocumentStorage::class),
            new DocumentTextExtractor(),
            $c->get(ChartOfAccountsRepository::class),
            $c->get(VatStatusService::class),
            $c->get(AiInternalDocumentValidator::class),
            $c->get(OtherItemKindService::class),
        );
    }

    /** @return array<string,mixed> */
    private function sentPayload(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    private function sentUserText(): string
    {
        $content = $this->sentPayload()['messages'][0]['content'];
        return is_string($content) ? $content : (string) end($content)['text'];
    }

    private function sentSystem(): string
    {
        $system = $this->sentPayload()['system'];
        return is_string($system) ? $system : (string) json_encode($system, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private function sentSchema(): array
    {
        return $this->sentPayload()['output_config']['format']['schema'];
    }

    private function client(): int
    {
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, 'Syntetický partner', 'Ulice 1', 'Praha', '11000',
                     (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), 'partner@example.invalid',
                     (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1))"
        )->execute([$this->supplierId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/ai-assistant/ask')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }
}
