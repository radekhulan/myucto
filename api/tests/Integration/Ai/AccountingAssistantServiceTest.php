<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use GuzzleHttp\Psr7\Response;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemKindService;
use MyInvoice\Service\Ai\AccountingAssistantException;
use MyInvoice\Service\Ai\AccountingAssistantService;
use MyInvoice\Service\Ai\AiInternalDocumentValidator;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\DocumentTextExtractor;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AI asistent a AI nad dokumenty proti skutečné DB (dokumenty, osnova, složky) s podvrženým
 * poskytovatelem LLM (Guzzle MockHandler) — žádné placené volání.
 */
#[Group('integration')]
final class AccountingAssistantServiceTest extends TestCase
{
    private PDO $pdo;
    private Connection $conn;
    private DocumentRepository $docs;
    private int $supplierId;
    private bool $doubleEntry;
    /** @var list<int> */
    private array $createdDocs = [];
    /** @var array<int, array<string,mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            if ($c === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->conn = $c->get(Connection::class);
            $this->pdo = $this->conn->pdo();
            $this->docs = $c->get(DocumentRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) {
            $this->markTestSkipped('No supplier');
        }
        $mode = $this->pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$this->supplierId]);
        $this->doubleEntry = $mode->fetchColumn() === 'double_entry';
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach ($this->createdDocs as $id) {
            $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        }
    }

    /** @param list<mixed> $responses */
    private function service(array $responses): AccountingAssistantService
    {
        $this->history = [];
        $ai = FakeAiProviderHttpClientFactory::create($this, 'anthropic', $responses, $this->history);
        $c = Bootstrap::buildApp()->getContainer();
        return new AccountingAssistantService(
            $this->conn,
            $ai,
            $this->docs,
            $c->get(DocumentFolderRepository::class),
            $c->get(DocumentStorage::class),
            new DocumentTextExtractor(),
            $c->get(ChartOfAccountsRepository::class),
            $c->get(VatStatusService::class),
            $c->get(AiInternalDocumentValidator::class),
            $c->get(OtherItemKindService::class),
        );
    }

    /** @param array<string,mixed> $input */
    private static function toolResponse(array $input): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => json_encode($input)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ]));
    }

    private function insertDoc(string $title, ?string $text, string $scope = 'company', ?int $owner = null): int
    {
        $sha = hash('sha256', $title . microtime());
        $id = $this->docs->insert([
            'supplier_id' => $this->supplierId, 'folder_id' => null, 'title' => $title, 'description' => null,
            'original_name' => $title . '.pdf', 'filename' => substr($sha, 0, 8) . '.pdf', 'sha256' => $sha,
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'doc_type' => 'pdf', 'uploaded_by' => null,
            'scope' => $scope, 'owner_user_id' => $owner,
        ]);
        $this->createdDocs[] = $id;
        if ($text !== null) {
            $this->docs->setText($id, $text, 'extracted');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function sentPayload(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    private function sentUserText(): string
    {
        $content = $this->sentPayload()['messages'][0]['content'];
        if (is_string($content)) {
            return $content;
        }
        return (string) end($content)['text'];
    }

    public function testAskSendsFilesAndDocumentsAndNormalizesSuggestions(): void
    {
        $this->pdo->beginTransaction();
        (new ChartOfAccountsSeeder($this->conn))->seedForSupplier($this->supplierId);
        (new DocumentFolderRepository($this->conn))->create($this->supplierId, null, 'ZZAIASSISTFOLDER', null);
        $docId = $this->insertDoc('Pokladní doklad PD-SYNT', 'Příjmový pokladní doklad ZZAIASSISTMARKER vklad 50 000 Kč');
        $service = $this->service([self::toolResponse([
            'answer' => '## Postup' . "\n" . 'Upsání 353/411.',
            'journal_entries' => [[
                'entry_date' => '2026-03-01', 'description' => 'Upsání ZK', 'document_no' => '',
                'lines' => [
                    ['account_code' => '353', 'side' => 'debit', 'amount' => 200000, 'note' => ''],
                    ['account_code' => '411', 'side' => 'credit', 'amount' => 200000, 'note' => ''],
                ],
            ]],
            'document_suggestions' => [
                ['attachment_kind' => 'file', 'attachment_index' => 0, 'document_id' => 0, 'folder_id' => 0, 'new_folder_name' => 'Notář', 'title' => 'Notářský zápis'],
                ['attachment_kind' => 'file', 'attachment_index' => 7, 'document_id' => 0, 'folder_id' => 0, 'new_folder_name' => 'Neexistuje', 'title' => ''],
                ['attachment_kind' => 'document', 'attachment_index' => 0, 'document_id' => 999999999, 'folder_id' => 0, 'new_folder_name' => 'Cizí', 'title' => ''],
            ],
            'warnings' => ['Ověřte datum zápisu do OR.'],
        ])]);

        $out = $service->ask(
            $this->supplierId,
            DocumentViewerContext::admin(),
            'Potřebuji vytvořit pohledávky za upsaný základní kapitál.',
            [['role' => 'user', 'content' => 'Dobrý den'], ['role' => 'assistant', 'content' => 'Dobrý den, jak pomohu?']],
            [['name' => 'notarsky-zapis.pdf', 'bytes' => "%PDF-1.4\nsyntetický zápis"]],
            [(string) $docId],
            true,
        );

        $payload = $this->sentPayload();
        self::assertSame('claude-sonnet-5', $payload['model'], 'haiku tenant → silnější model');
        $content = $payload['messages'][0]['content'];
        self::assertSame('document', $content[0]['type']);
        self::assertSame(base64_encode("%PDF-1.4\nsyntetický zápis"), $content[0]['source']['data']);
        $user = $this->sentUserText();
        self::assertStringContainsString('ZZAIASSISTMARKER', $user, 'text dokumentu jde do promptu');
        self::assertStringContainsString('Dokument id=' . $docId, $user);
        self::assertStringContainsString('## Předchozí konverzace', $user);
        self::assertStringContainsString('## Složky v Dokumentech', $user);
        self::assertStringContainsString('ZZAIASSISTFOLDER', $user);
        self::assertStringContainsString('## Účtový rozvrh firmy', $user);
        self::assertStringContainsString('ignoruj', $payload['system'], 'ochrana proti pokynům v přílohách');

        self::assertSame('## Postup' . "\n" . 'Upsání 353/411.', $out['answer']);
        self::assertSame([[
            'attachment' => ['kind' => 'file', 'index' => 0],
            'folder_id' => null, 'folder_path' => null, 'new_folder_name' => 'Notář', 'title' => 'Notářský zápis',
        ]], $out['document_suggestions'], 'návrhy na neexistující přílohu vypadnou');
        self::assertSame(['input_tokens' => 120, 'output_tokens' => 40], $out['usage']);
        self::assertContains('Ověřte datum zápisu do OR.', $out['warnings']);
        if ($this->doubleEntry) {
            self::assertCount(1, $out['journal_entries']);
            self::assertTrue($out['journal_entries'][0]['balanced']);
        } else {
            self::assertSame([], $out['journal_entries']);
        }
    }

    /**
     * Sken v Dokumentech má v content_text jen pár řádků z vytěžení faktury. AI musí
     * dostat originální PDF, jinak notářský zápis „přečte" jen z těch pár řádků.
     */
    public function testScannedPdfDocumentIsSentAsOriginalWithTextHint(): void
    {
        $docId = $this->insertDoc('Sken notářského zápisu', 'Dodavatel: ZZSCANHINT');
        $row = $this->pdo->prepare('SELECT sha256, filename FROM documents WHERE id = ?');
        $row->execute([$docId]);
        $doc = $row->fetch(\PDO::FETCH_ASSOC);
        $storage = Bootstrap::buildApp()->getContainer()->get(DocumentStorage::class);
        $path = $storage->pathFor($this->supplierId, (string) $doc['sha256'], (string) $doc['filename']);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "%PDF-1.4\nsyntetický sken");
        try {
            $service = $this->service([self::toolResponse([
                'answer' => 'Sken přečten.', 'journal_entries' => [], 'document_suggestions' => [], 'warnings' => [],
            ])]);
            $service->ask($this->supplierId, DocumentViewerContext::admin(), 'Co je v dokumentu?', [], [], [$docId], false);

            $content = $this->sentPayload()['messages'][0]['content'];
            self::assertIsArray($content, 'sken jde jako binární příloha');
            self::assertSame('document', $content[0]['type']);
            self::assertSame(base64_encode("%PDF-1.4\nsyntetický sken"), $content[0]['source']['data']);
            self::assertStringContainsString('ZZSCANHINT', $this->sentUserText(), 'vytěžený text zůstává jako nápověda');
        } finally {
            @unlink($path);
        }
    }

    /** Splátkový kalendář patří do Ostatních pohledávek a závazků: návrh se vrátí jako other_items. */
    public function testAskReturnsOtherItemProposalWithInstallments(): void
    {
        $service = $this->service([self::toolResponse([
            'answer' => 'Založte závazek z úvěru.', 'journal_entries' => [], 'document_suggestions' => [], 'warnings' => [],
            'other_items' => [[
                'side' => 'payable', 'kind' => 'loan', 'title' => 'Úvěr', 'partner_name' => 'Banka Test', 'partner_ico' => '',
                'amount' => 2000, 'issued_on' => '2026-01-01', 'due_on' => '2026-03-01', 'variable_symbol' => '',
                'account_code' => '', 'counter_account_code' => '', 'note' => '',
                'installments' => [['due_on' => '2026-02-01', 'amount' => 1000], ['due_on' => '2026-03-01', 'amount' => 1000]],
            ]],
        ])]);

        $out = $service->ask($this->supplierId, DocumentViewerContext::admin(), 'Mám splátkový kalendář úvěru.', [], [], [], false);

        self::assertStringContainsString('`other_items`', $this->sentPayload()['system']);
        $payload = $this->sentPayload();
        self::assertArrayNotHasKey('tool_choice', $payload, 'dlouhá odpověď jde přes structured outputs, ne vynucený tool_use');
        self::assertSame('json_schema', $payload['output_config']['format']['type']);
        self::assertArrayHasKey('other_items', $payload['output_config']['format']['schema']['properties']);
        self::assertCount(1, $out['other_items']);
        self::assertSame('loan', $out['other_items'][0]['kind']);
        self::assertCount(2, $out['other_items'][0]['installments']);
        self::assertTrue($out['other_items'][0]['installments_valid']);
    }

    /** Rozvrh označí účty s analytikami a návrh na takovou syntetiku dostane upozornění. */
    public function testChartMarksAnalyticsAndWarnsOnSyntheticAccount(): void
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
                VALUES (?, ?, ?, 'asset', 'debit', ?, ?, 1)");
            $insert->execute([$this->supplierId, 'ZZ9', 'Syntetický testovací účet', 1, null]);
            $parent = (int) $this->pdo->lastInsertId();
            $insert->execute([$this->supplierId, 'ZZ9.001', 'Analytika testovacího účtu', 0, $parent]);

            $service = $this->service([self::toolResponse([
                'answer' => 'Návrh.', 'other_items' => [], 'document_suggestions' => [], 'warnings' => [],
                'journal_entries' => [[
                    'entry_date' => '2026-03-01', 'description' => 'Test', 'document_no' => '',
                    'lines' => [
                        ['account_code' => 'ZZ9', 'side' => 'debit', 'amount' => 100, 'note' => ''],
                        ['account_code' => 'ZZ9.001', 'side' => 'credit', 'amount' => 100, 'note' => ''],
                    ],
                ]],
            ])]);
            $out = $service->ask($this->supplierId, DocumentViewerContext::admin(), 'Jak zaúčtovat?', [], [], [], true);

            $user = $this->sentUserText();
            self::assertStringContainsString('ZZ9 – Syntetický testovací účet [má analytiky]', $user);
            self::assertStringContainsString("ZZ9.001 – Analytika testovacího účtu\n", $user);
            if ($this->doubleEntry) {
                self::assertNotEmpty(array_filter($out['warnings'], static fn (string $w): bool => str_contains($w, 'syntetický účet ZZ9,')));
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    /** Dokument jiného uživatele (scope=user) nesmí jít přes document_ids do promptu. */
    public function testAskRespectsDocumentVisibilityAndTenant(): void
    {
        $owner = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($owner <= 0) {
            $this->markTestSkipped('No user');
        }
        $privateId = $this->insertDoc('Osobní doklad', 'ZZPRIVATEMARKER', 'user', $owner);
        $stranger = DocumentViewerContext::forUser($owner + 987654);

        foreach ([[$stranger, $this->supplierId], [DocumentViewerContext::admin(), $this->supplierId + 987654]] as [$viewer, $sid]) {
            try {
                $this->service([self::toolResponse(['answer' => 'x', 'journal_entries' => [], 'document_suggestions' => [], 'warnings' => []])])
                    ->ask($sid, $viewer, 'Co je v dokladu?', [], [], [$privateId], false);
                self::fail('Cizí dokument musí být odmítnut.');
            } catch (AccountingAssistantException $e) {
                // Pro cizího tenanta může dřív odpovědět i kontrola dostupnosti — podstatné je, že nic neodešlo.
                self::assertContains($e->httpStatus, [422, 503]);
            }
            self::assertSame([], $this->history, 'žádný požadavek na poskytovatele');
        }

        $this->service([self::toolResponse(['answer' => 'ok', 'journal_entries' => [], 'document_suggestions' => [], 'warnings' => []])])
            ->ask($this->supplierId, DocumentViewerContext::forUser($owner), 'Co je v dokladu?', [], [], [$privateId], false);
        self::assertStringContainsString('ZZPRIVATEMARKER', $this->sentUserText(), 'vlastník svůj dokument přiložit může');
    }

    public function testAskRejectsInvalidInputWithoutCallingProvider(): void
    {
        $cases = [
            ['', [], [], []],
            [str_repeat('a', 8001), [], [], []],
            ['ok', array_fill(0, 11, ['role' => 'user', 'content' => 'x']), [], []],
            ['ok', [['role' => 'system', 'content' => 'x']], [], []],
            ['ok', [], [['name' => 'virus.exe', 'bytes' => 'MZ']], []],
            ['ok', [], [['name' => 'fake.pdf', 'bytes' => 'neni pdf']], []],
            ['ok', [], array_fill(0, 6, ['name' => 'a.txt', 'bytes' => 'x']), []],
            ['ok', [], [], ['abc']],
        ];
        foreach ($cases as $i => [$question, $history, $files, $ids]) {
            try {
                $this->service([])->ask($this->supplierId, DocumentViewerContext::admin(), $question, $history, $files, $ids, false);
                self::fail('Případ ' . $i . ' měl být odmítnut.');
            } catch (AccountingAssistantException $e) {
                self::assertSame('ai_assistant.invalid_input', $e->errorCode, 'případ ' . $i);
                self::assertSame(422, $e->httpStatus);
            }
            self::assertSame([], $this->history);
        }
    }

    public function testSummaryIsPersistedAndFulltextSearchable(): void
    {
        $id = $this->insertDoc('Smlouva bez klíčového slova', 'Obsah smlouvy o dílo.');
        $service = $this->service([
            self::toolResponse(['summary' => 'Smlouva o dílo ZZSUMMARYMARKER mezi dvěma stranami.']),
            self::toolResponse(['summary' => "## Shrnutí\n- dlouhé ZZLONGSUMMARY"]),
        ]);

        $short = $service->summarizeDocument($this->supplierId, DocumentViewerContext::admin(), $id, 'short');
        self::assertSame('Smlouva o dílo ZZSUMMARYMARKER mezi dvěma stranami.', $short['ai_summary_short']);
        self::assertNull($short['ai_summary_long']);
        self::assertNotNull($short['ai_summary_at']);

        $long = $service->summarizeDocument($this->supplierId, DocumentViewerContext::admin(), $id, 'long');
        self::assertSame($short['ai_summary_short'], $long['ai_summary_short'], 'dlouhé shrnutí krátké nepřepíše');
        self::assertSame("## Shrnutí\n- dlouhé ZZLONGSUMMARY", $long['ai_summary_long']);

        $found = $this->docs->search($this->supplierId, 'ZZSUMMARYMARKER', DocumentViewerContext::admin());
        self::assertContains($id, array_column($found, 'id'), 'fulltext trefí text shrnutí');
        $row = array_values(array_filter($found, static fn (array $d): bool => $d['id'] === $id))[0];
        self::assertSame($short['ai_summary_short'], $row['ai_summary_short']);
        self::assertContains($id, array_column($this->docs->search($this->supplierId, 'ZZLONGSUMMARY', DocumentViewerContext::admin()), 'id'));

        $detail = $this->docs->find($id, $this->supplierId, DocumentViewerContext::admin());
        self::assertSame("## Shrnutí\n- dlouhé ZZLONGSUMMARY", $detail['ai_summary_long']);
    }

    public function testAnalysisIsPersistedOnDocument(): void
    {
        $id = $this->insertDoc('Faktura FV-SYNT', 'Faktura 12100 Kč vč. DPH 21 %');
        $out = $this->service([self::toolResponse([
            'answer' => 'Přijatá faktura za služby.',
            'fields' => [['label' => 'Celkem', 'value' => '12 100 Kč'], ['label' => '', 'value' => 'zahodit']],
            'journal_entries' => [[
                'entry_date' => 'neplatné', 'description' => 'Služby', 'document_no' => 'FV-SYNT',
                'lines' => [
                    ['account_code' => '518', 'side' => 'debit', 'amount' => 10000, 'note' => ''],
                    ['account_code' => '343', 'side' => 'debit', 'amount' => 2100, 'note' => ''],
                    ['account_code' => '321', 'side' => 'credit', 'amount' => 12100, 'note' => ''],
                ],
            ]],
            'warnings' => [],
        ])])->analyzeDocument($this->supplierId, DocumentViewerContext::admin(), $id, 'long', true);

        self::assertSame('long', $out['detail']);
        self::assertTrue($out['accounting']);
        self::assertSame([['label' => 'Celkem', 'value' => '12 100 Kč']], $out['fields']);
        self::assertSame('anthropic', $out['provider']);
        if ($this->doubleEntry) {
            self::assertCount(1, $out['journal_entries']);
            self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $out['journal_entries'][0]['entry_date']);
            self::assertTrue($out['journal_entries'][0]['balanced']);
        } else {
            self::assertSame([], $out['journal_entries']);
        }

        $detail = $this->docs->find($id, $this->supplierId, DocumentViewerContext::admin());
        self::assertEquals($out, $detail['ai_analysis'], 'JSON round-trip: 10000.0 se vrátí jako 10000');

        $this->expectException(AccountingAssistantException::class);
        $this->service([])->analyzeDocument($this->supplierId + 987654, DocumentViewerContext::admin(), $id, 'short', false);
    }
}
