<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic testy pro privátní helpery v AiPdfExtractoru.
 *
 * Pokrývá:
 *   - detectWeakExtraction — kdy spustit auto-upgrade na Sonnet (vendor=tenant /
 *     katastrofální items mismatch >50 %)
 *   - maybeFlagTotalsMismatch — signed sum (sleva s mínusem, dobropisy se zápornou
 *     qty), žádný `total_with_vat / 1.21` fallback (multi-rate false positive)
 *   - applyRoundingFromAiTotal — reálný nález — 84092.58 vs "K úhradě" 84093 = 0.42 rounding
 *
 * Závislosti AiPdfExtractoru jsou mockované přes PHPUnit createMock — testy
 * neběží proti DB / API.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiPdfExtractorUnitTest extends TestCase
{
    private PurchaseInvoiceRepository $repo;
    private AiPdfExtractor $extractor;
    private PurchaseInvoicePdfArchiver $pdfArchiver;
    /** @var LlmGatewayInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $anthropic;
    /** @var PdfIsdocExtractor&\PHPUnit\Framework\MockObject\MockObject */
    private $pdfIsdoc;
    /** @var IsdocParser&\PHPUnit\Framework\MockObject\MockObject */
    private $isdocParser;
    /** @var IsdocToPurchaseInvoiceMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $isdocMapper;
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private $conn;

    protected function setUp(): void
    {
        // Mock všechny závislosti — privátní metody, které testujeme, používají jen
        // některé z nich. Pro pure-logic checky (detectWeakExtraction) žádné nepotřebujeme,
        // pro maybeFlagTotalsMismatch / applyRoundingFromAiTotal jen $repo.
        $this->repo = $this->createMock(PurchaseInvoiceRepository::class);
        $this->anthropic = $this->createMock(LlmGatewayInterface::class);
        // ISDOC-first rozhodnutí obstarává reálný router nad mockovanými extraktory —
        // pin testy tak řídí přímo (a jen) detekci/parse ISDOC.
        $this->pdfIsdoc = $this->createMock(PdfIsdocExtractor::class);
        $this->isdocParser = $this->createMock(IsdocParser::class);
        $this->isdocMapper = $this->createMock(IsdocToPurchaseInvoiceMapper::class);
        $router = new InvoiceExtractionRouter($this->pdfIsdoc, $this->isdocParser, new NullLogger());

        // Plánovač je `final` (nejde doubleovat) — složíme reálný nad mockovaným
        // spojením. Testované privátní metody na něj nesahají, takže se nikdy nezeptá DB.
        $conn = $this->createMock(Connection::class);
        $this->conn = $conn;
        $planner = new \MyInvoice\Service\Oss\OssItemPlanner(
            $conn,
            new \MyInvoice\Service\Oss\OssItemDeriver($conn, new \MyInvoice\Service\Oss\OssRateCodebook($conn)),
            new \MyInvoice\Service\Oss\OssRateCodebook($conn),
            new \MyInvoice\Service\Vat\VatRateResolver($conn),
        );

        $this->pdfArchiver = $this->createMock(PurchaseInvoicePdfArchiver::class);
        $this->extractor = new AiPdfExtractor(
            $conn,
            $this->anthropic,
            $this->createMock(ClientResolver::class),
            $this->repo,
            $this->createMock(PurchaseInvoiceCalculator::class),
            $router,
            $this->isdocMapper,
            $this->createMock(Config::class),
            $this->createMock(CnbExchangeRateClient::class),
            new ImageToPdfConverter(), // bez závislostí — reálná instance stačí
            $this->createMock(\MyInvoice\Repository\TaxConstantsRepository::class),
            $this->pdfArchiver,
            new ExpenseKindClassifier(), // pure, bez DB — mock by nic nepřidal
            $planner,
            null, // uložení vytěžení importu — helpery ho nepotřebují
            null, // automatika plateb kartou — helpery ji nepotřebují
            null, // automatické pravidlo nákladů — helpery ho nepotřebují
            new NullLogger(),
        );
    }

    // ── PIN: ISDOC-first sémantika (F7 §3.9, §11.1) ────────────────────────
    // Zamykají OPRAVENÉ chování: validní ISDOC ⇒ LLM 0×; přítomný ISDOC který
    // selhal parse ⇒ LLM právě 1× (AI fallback; import se nebrickuje).

    public function testStoredPdfInsideIsdocxPreservesPackageAndNeverCallsLlm(): void
    {
        $pdf = "%PDF-1.4\nsynthetic invoice\n%%EOF";
        $xml = '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>SYNTHETIC-ZIP-1</ID></Invoice>';
        $tmp = tempnam(sys_get_temp_dir(), 'extractor-isdocx-');
        self::assertNotFalse($tmp);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE));
            // PDF je první a bez komprese: jeho hlavička leží v prvním 1 KiB ZIPu,
            // kde ji PDF normalizace dříve zaměnila za začátek celého uploadu.
            self::assertTrue($zip->addFromString('invoice.pdf', $pdf));
            self::assertTrue($zip->setCompressionName('invoice.pdf', \ZipArchive::CM_STORE));
            self::assertTrue($zip->addFromString('invoice.isdoc', $xml));
            self::assertTrue($zip->close());
            $bytes = file_get_contents($tmp);
            self::assertNotFalse($bytes);
        } finally {
            unlink($tmp);
        }
        $pdfOffset = strpos($bytes, '%PDF-');
        self::assertNotFalse($pdfOffset);
        self::assertGreaterThan(0, $pdfOffset);
        self::assertLessThan(1024, $pdfOffset);

        $this->pdfIsdoc->expects($this->never())->method('extract');
        $this->anthropic->expects($this->never())->method('extractInvoice');
        $this->isdocParser->expects($this->once())->method('parse')->with($xml)
            ->willReturn(['supplier_ic' => null, 'invoices' => [['id' => 'SYNTHETIC-ZIP-1']]]);
        $this->repo->expects($this->once())->method('findIdByPdfHash')
            ->with(1, hash('sha256', $pdf))->willReturn(null);
        $this->isdocMapper->expects($this->once())->method('map')
            ->with(['id' => 'SYNTHETIC-ZIP-1'], 1, 2)
            ->willReturn(['purchase_invoice_id' => 99, 'vendor_id' => 7]);
        $this->pdfArchiver->expects($this->once())->method('archiveBytes')
            ->with(99, 1, $pdf, 'invoice.pdf');
        $this->pdfArchiver->expects($this->once())->method('archiveSourceBytes')
            ->with(99, 1, $bytes, 'invoice.isdocx', 'isdocx');

        $result = $this->extractor->extractAndCreate(1, 2, $bytes, null, 'invoice.isdocx');

        self::assertTrue($result['ok']);
        self::assertSame('isdocx', $result['source']);
        self::assertSame(99, $result['purchase_invoice_id']);
    }

    public function testPin_validEmbeddedIsdoc_neverCallsLlm(): void
    {
        $pdfBytes = "%PDF-1.4\nfake pdf s embedded ISDOC";
        $this->pdfIsdoc->method('extract')->willReturn('<Invoice>…isdoc.cz/namespace/2013…</Invoice>');
        $this->isdocParser->method('parse')->willReturn([
            'supplier_ic' => '12345678',
            'invoices'    => [['id' => 'F-2026-001']],
        ]);
        $this->isdocMapper->method('map')->willReturn([
            'purchase_invoice_id' => 99,
            'vendor_id'           => 7,
        ]);

        // Jádro pinu: LLM se NIKDY nesmí zavolat, když ISDOC parsuje validně.
        $this->anthropic->expects($this->never())->method('extractInvoice');

        $result = $this->extractor->extractAndCreate(1, 1, $pdfBytes, null, 'faktura.pdf');

        self::assertTrue($result['ok']);
        self::assertSame('isdoc_embedded', $result['source']);
        self::assertSame(99, $result['purchase_invoice_id']);
    }

    public function testPin_malformedEmbeddedIsdoc_fallsBackToLlmExactlyOnce(): void
    {
        $pdfBytes = "%PDF-1.4\nfake pdf s VADNÝM embedded ISDOC";
        // ISDOC je fyzicky přítomen…
        $this->pdfIsdoc->method('extract')->willReturn('<Invoice>…rozbité…');
        // …ale parse selže (malformed).
        $this->isdocParser->method('parse')
            ->willThrowException(new \RuntimeException('Nelze parsovat ISDOC XML.'));

        // Jádro pinu: AI fallback zafunguje PŘESNĚ 1× (import se nebrickuje).
        $this->anthropic->expects($this->once())
            ->method('extractInvoice')
            ->willReturn(['ok' => false, 'error' => 'AI mock — extrakce neběží v unit testu']);

        $result = $this->extractor->extractAndCreate(1, 1, $pdfBytes, null, 'faktura.pdf');

        // AI mock vrátil ok=false → výsledek ai_failed, ale klíčové je, že se AI
        // VŮBEC zavolalo (dřív by tichý try/catch call utratil / zbrickoval import).
        self::assertFalse($result['ok']);
        self::assertSame('ai_failed', $result['source']);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function notConfiguredResults(): iterable
    {
        yield 'klient bez klíče' => [['ok' => false, 'error' => 'AI vytěžování není nastavené', 'code' => 'provider_not_configured']];
        yield 'router bez providera' => [['ok' => false, 'error' => 'provider_not_configured']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notConfiguredResults')]
    public function testMissingKey_isReportedAsNotConfigured(array $llmResult): void
    {
        $this->pdfIsdoc->method('extract')->willReturn(null);
        $this->anthropic->method('extractInvoice')->willReturn($llmResult);

        $result = $this->extractor->extractAndCreate(1, 1, "%PDF-1.4\nfake pdf bez ISDOC", null, 'faktura.pdf');

        self::assertFalse($result['ok']);
        self::assertSame('ai_not_configured', $result['source']);
    }

    public function testPin_validEmbeddedIsdoc_mapRejectsWrongTenant_structuredErrorNoLlm(): void
    {
        // Regrese H1 (Fable review commit 2): validní ISDOC, ale mapper ho odmítne
        // (wrong-tenant / chybějící IČO → InvalidArgumentException). Musí vrátit
        // STRUKTUROVANOU chybu (ne bublinu do HTTP 500) a §3.9: AI se NEVOLÁ.
        $pdfBytes = "%PDF-1.4\nfake pdf s validním ISDOC pro jiného plátce";
        $this->pdfIsdoc->method('extract')->willReturn('<Invoice>…isdoc.cz/namespace/2013…</Invoice>');
        $this->isdocParser->method('parse')->willReturn([
            'supplier_ic' => '87654321',
            'invoices'    => [['id' => 'F-2026-002']],
        ]);
        $this->isdocMapper->method('map')
            ->willThrowException(new \InvalidArgumentException('ISDOC patří jinému plátci (IČO 87654321).'));

        // Validní ISDOC ⇒ LLM se NIKDY nevolá, ani při selhání mapování.
        $this->anthropic->expects($this->never())->method('extractInvoice');

        $result = $this->extractor->extractAndCreate(1, 1, $pdfBytes, null, 'faktura.pdf');

        self::assertFalse($result['ok']);
        self::assertSame('isdoc_map_failed', $result['source']);
        self::assertStringContainsString('jinému plátci', $result['error']);
    }

    #[DataProvider('modelUpgradePolicies')]
    public function testWeakExtractionUpgradesOnlyWhenExplicitlyAllowed(bool $allowUpgrade): void
    {
        $weak = ['ok' => true, 'model' => 'synthetic-default', 'data' => ['vendor' => ['ic' => '12345679']]];
        $this->anthropic->expects(self::exactly($allowUpgrade ? 2 : 1))->method('extractInvoice')
            ->willReturnCallback(function (int $supplier, string $pdf, ?string $model) use ($weak): array {
                static $calls = 0;
                self::assertSame($calls++ === 0 ? null : 'synthetic-stronger', $model);
                return $weak;
            });
        $this->anthropic->expects($allowUpgrade ? self::once() : self::never())->method('strongerModel')
            ->with(42, 'synthetic-default')->willReturn('synthetic-stronger');
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('12345679');
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $this->conn->method('pdo')->willReturn($pdo);
        $result = $this->extractor->extractAndCreate(42, 1, '%PDF-1.4 synthetic weak extraction', allowModelUpgrade: $allowUpgrade);
        self::assertSame('ai_invalid', $result['source']);
    }

    public static function modelUpgradePolicies(): iterable
    {
        yield 'client keeps company default' => [false];
        yield 'staff keeps automatic upgrade' => [true];
    }

    // ── detectWeakExtraction ────────────────────────────────────────────────

    public function testDetectWeak_clean_extraction_returns_null(): void
    {
        $data = [
            'vendor'   => ['ic' => '10000010'],
            'customer' => ['ic' => '12345679'],
            'total_without_vat' => 4113.29,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 4113.29],
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_vendor_equals_tenant_no_customer_triggers(): void
    {
        $data = [
            'vendor'   => ['ic' => '12345679'], // = tenant
            'customer' => ['ic' => null],
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertSame('vendor_is_tenant_no_swap_target',
            $this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_vendor_equals_tenant_with_customer_handles_swap(): void
    {
        // Když customer má jiné IČ než tenant, swap-back proběhne normální cestou
        // → není to weakness, nereaguj.
        $data = [
            'vendor'   => ['ic' => '12345679'], // = tenant
            'customer' => ['ic' => '10000010'], // jiný IČ → swap-back funguje
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_catastrophic_mismatch_triggers(): void
    {
        // NC Auto pattern: AI total 5317, items sum 31057 (6x víc)
        $data = [
            'vendor'   => ['ic' => '10000010'],
            'customer' => ['ic' => '12345679'],
            'total_without_vat' => 5317.34,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 239],
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // 27720 halucinace
                ['quantity' => 1, 'unit_price_without_vat' => 3098.34],
            ],
        ];
        $this->assertSame('catastrophic_items_mismatch',
            $this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_discount_sign_under_threshold_does_not_trigger(): void
    {
        // Reálný nález PŘED sleva fix: sleva kladná, items=84942, AI=69498 → 22 %.
        // Pod 50 % prahem → není to weakness, jen warning.
        $data = [
            'vendor'   => ['ic' => '49437381'],
            'customer' => ['ic' => '12345679'],
            'total_without_vat' => 69498.35,
            'items' => [
                ['quantity' => 12, 'unit_price_without_vat' => 5709],
                ['quantity' => 12, 'unit_price_without_vat' => 726],
                ['quantity' => 12, 'unit_price_without_vat' => 643.50], // chybí mínus
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_skips_check2_when_ai_total_without_vat_missing(): void
    {
        // Bez total_without_vat NESPOUŠTÍME items vs total kontrolu (multi-rate
        // by jinak vyžadoval `total_with_vat / 1.21`, což u 21/12/0 % mixu nesedí).
        $data = [
            'vendor'   => ['ic' => '10000010'],
            'customer' => ['ic' => '12345679'],
            'total_with_vat' => 6433.98, // jen s DPH, bez DPH chybí
            'items' => [
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // halucinace
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '12345679'));
    }

    public function testDetectWeak_no_tenant_ic_skips_vendor_check(): void
    {
        // Když nemáme tenant IC (např. supplier bez IČ — zahraniční fyzická osoba),
        // vendor=tenant check se přeskočí.
        $data = [
            'vendor'   => ['ic' => '12345678'],
            'customer' => ['ic' => null],
            'total_without_vat' => 1000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 1000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, null));
    }

    // ── maybeFlagTotalsMismatch ────────────────────────────────────────────

    public function testMismatch_clean_invoice_no_warning(): void
    {
        // Items sum sedí s AI totalem do 2 % → žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 4113.29, 'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 4113.29];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_with_negative_sign_no_warning(): void
    {
        // Reálný nález po fix: sleva má v unit_price mínus, signed sum sedí s AI.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,     'vat_rate_id' => 1], // 68508
            ['quantity' => 12, 'unit_price_without_vat' => 726,      'vat_rate_id' => 1], // 8712
            ['quantity' => 12, 'unit_price_without_vat' => -643.50,  'vat_rate_id' => 1], // -7722
        ];
        $data = ['total_without_vat' => 69498]; // signed sum: 68508+8712-7722 = 69498
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_credit_note_negative_qty_no_warning(): void
    {
        // Dobropis: extractor aplikoval `qty *= -1`. AI total je kladný (per prompt).
        // signed sum bude záporný, ale po abs() musí sednout s AI totalem.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => -1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ['quantity' => -2, 'unit_price_without_vat' => 500,  'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 2000];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_wrong_sign_warns_no_placeholder(): void
    {
        // Reálný nález PŘED sleva fix: 22 % rozdíl → warning ano, placeholder ne (<50 %).
        $this->repo->expects($this->once())
            ->method('setExtractionWarning')
            ->with(42, 1, $this->stringContains('vyšší než'));
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,   'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 726,    'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 643.50, 'vat_rate_id' => 1], // chybí mínus
        ];
        $data = ['total_without_vat' => 69498.35];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_catastrophic_triggers_placeholder_with_preserved_descriptions(): void
    {
        // NC Auto >50 % mismatch → placeholder + zachované AI popisy s qty=0/price=0.
        $this->repo->expects($this->once())->method('setExtractionWarning');
        $this->repo->expects($this->once())
            ->method('replaceItems')
            ->with(42, $this->callback(function (array $items): bool {
                // První řádek = KOREKCE s AI totalem
                if (!str_starts_with($items[0]['description'], 'KOREKCE')) return false;
                if ($items[0]['unit_price_without_vat'] !== 5317.34) return false;
                if ($items[0]['quantity'] !== 1.0) return false;
                // Další řádky = AI popisy s qty=0/price=0
                if (count($items) !== 4) return false; // 1 korekce + 3 popisy
                for ($i = 1; $i <= 3; $i++) {
                    if ($items[$i]['quantity'] !== 0.0) return false;
                    if ($items[$i]['unit_price_without_vat'] !== 0.0) return false;
                }
                return $items[1]['description'] === 'Vyvážení kol'
                    && $items[2]['description'] === 'AdBlue'
                    && $items[3]['description'] === 'Závaží';
            }));

        $items = [
            ['quantity' => 14, 'unit_price_without_vat' => 1980, 'vat_rate_id' => 1, 'description' => 'Vyvážení kol'],
            ['quantity' => 15, 'unit_price_without_vat' => 31,   'vat_rate_id' => 1, 'description' => 'AdBlue'],
            ['quantity' => 8,  'unit_price_without_vat' => 52,   'vat_rate_id' => 1, 'description' => 'Závaží'],
        ];
        $data = ['total_without_vat' => 5317.34];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_skips_when_total_without_vat_missing(): void
    {
        // Bez total_without_vat nepoužíváme `total_with_vat / 1.21` fallback,
        // u multi-rate by dělal false positive. Žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
        ];
        $data = ['total_with_vat' => 1210]; // jen s DPH; bez DPH chybí
        $this->invokeFlag(42, 1, $data, $items);
    }

    // ── applyRoundingFromAiTotal ───────────────────────────────────────────

    public function testRounding_ai_total_84092_to_84093_yields_042(): void
    {
        // Reálný nález: items sum × 1.21 = 84092.58, AI total_with_vat (z "K úhradě") = 84093.
        // Rozdíl 0.42 → uložit jako rounding.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, 0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], false);
    }

    public function testRounding_credit_note_applies_negative_sign(): void
    {
        // Dobropis: total_with_vat v DB záporný, AI totaly kladné (per prompt).
        // Sign aplikujeme v setRounding na záporno.
        $this->repo->method('find')->willReturn(['total_with_vat' => -84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], true);
    }

    public function testRounding_no_diff_skips(): void
    {
        // Když je AI total = recomputed total, nic se neukládá.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 84092.58], false);
    }

    public function testRounding_diff_over_1_kc_skips(): void
    {
        // Rozdíl > 1 Kč není zaokrouhlení, je to chyba — ignorujeme.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 1050], false);
    }

    public function testRounding_missing_ai_total_skips(): void
    {
        $this->repo->expects($this->never())->method('find');
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => null], false);
    }

    public function testRounding_prefers_pdf_rounded_over_ai_total(): void
    {
        // Regression: user report Vodafone faktura 1025255728.
        //   Items recompute: 1×1241,34 × 1,21 = 1502,02 (= total_with_vat v DB)
        //   AI total_with_vat (její DPH math): 1502,03  ← se splete o haléř
        //   AI total_with_vat_rounded (PDF "K úhradě"): 1502,00
        //   Reálný rounding má být PDF − recompute = 1502,00 − 1502,02 = -0,02
        //   PŘED FIXEM: computeRounding bral rounded − AI total = -0,03 → "K úhradě" 1501,99
        //   PO FIXU: applyRoundingFromPdfTotal preferuje rounded, počítá vůči
        //   přesnému items totalu z DB → -0,02 → "K úhradě" 1502,00 ✓
        $this->repo->method('find')->willReturn(['total_with_vat' => 1502.02]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.02);

        $this->invokeRounding(42, 1, [
            'total_with_vat'         => 1502.03,  // AI's chybný DPH součet
            'total_with_vat_rounded' => 1502.00,  // PDF "K úhradě" — preferovat
        ], false);
    }

    // ── resolvePricesIncludeVat (režim „ceny s DPH" pro účtenky) ────────────

    public function testPricesInclVat_explicit_true_flag_wins(): void
    {
        // AI explicitně řekla, že ceny jsou s DPH → režim shora, bez ohledu na typ.
        self::assertTrue($this->invokeResolvePricesInclVat(['unit_prices_include_vat' => true], 'invoice'));
    }

    public function testPricesInclVat_explicit_false_flag_wins_even_for_receipt(): void
    {
        // I u účtenky: když AI explicitně řekne false (cena bez DPH na dokladu je),
        // respektujeme to a NEpřepínáme na režim shora.
        self::assertFalse($this->invokeResolvePricesInclVat(['unit_prices_include_vat' => false], 'receipt'));
    }

    public function testPricesInclVat_receipt_defaults_to_true_when_flag_missing(): void
    {
        // Účtenka bez flagu → default true (ceny s DPH, bez DPH cena na dokladu není).
        self::assertTrue($this->invokeResolvePricesInclVat([], 'receipt'));
    }

    public function testPricesInclVat_invoice_defaults_to_false_when_flag_missing(): void
    {
        // Běžná faktura bez flagu → default false (ceny bez DPH, počítá se zdola).
        self::assertFalse($this->invokeResolvePricesInclVat([], 'invoice'));
    }

    public function testMismatch_receipt_gross_sum_matches_total_with_vat_no_warning(): void
    {
        // Režim shora: ceny řádků jsou s DPH → reference je total_with_vat, ne _without_.
        // Účtenka 344 (1×344) sedí s total_with_vat → žádné varování,
        // i když total_without_vat (284,30) by se součtem řádků NEsedělo.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [['quantity' => 1, 'unit_price_without_vat' => 344.00, 'vat_rate_id' => 1]];
        $data = ['total_with_vat' => 344.00, 'total_without_vat' => 284.30];
        $this->invokeFlag(42, 1, $data, $items, true); // pricesIncludeVat = true
    }

    // ── isVendorNonPayer (dodavatel neplátce → bez nároku na odpočet) ───────

    public function testNonPayer_registry_payer_overrides_document_nonpayer(): void
    {
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(true, ['is_vat_payer' => false]));
    }

    public function testNonPayer_registry_nonpayer_overrides_document_payer(): void
    {
        self::assertTrue(AiPdfExtractor::isVendorNonPayer(false, ['is_vat_payer' => true]));
    }

    public function testNonPayer_unknown_uses_document_signal_false_means_nonpayer(): void
    {
        // Registr nerozhodl (null) → signál z dokladu: AI vendor.is_vat_payer=false ⇒ neplátce.
        self::assertTrue(AiPdfExtractor::isVendorNonPayer(null, ['is_vat_payer' => false]));
    }

    public function testNonPayer_unknown_without_signal_defaults_payer(): void
    {
        // Nezjištěno z registru ani z dokladu → konzervativně NEpředpokládej neplátce.
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(null, []));
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(null, ['is_vat_payer' => true]));
    }

    #[DataProvider('vendorVatStatuses')]
    public function testVendorVatStatusPrefersRegistry(?bool $documentStatus, ?bool $resolvedStatus): void
    {
        self::assertSame($resolvedStatus ?? $documentStatus, AiPdfExtractor::resolveVendorVatPayer($resolvedStatus, ['is_vat_payer' => $documentStatus]));
        self::assertSame($resolvedStatus, AiPdfExtractor::resolveVendorVatPayer($resolvedStatus, []));
    }

    public static function vendorVatStatuses(): iterable
    {
        foreach ([true, false, null] as $documentStatus) {
            foreach ([true, false, null] as $resolvedStatus) {
                yield [$documentStatus, $resolvedStatus];
            }
        }
    }

    #[DataProvider('documentVatEvidence')]
    public function testDocumentVatRequiresConsistentAmounts(array $data, bool $expected): void
    {
        self::assertSame($expected, AiPdfExtractor::hasExplicitDocumentVat($data));
    }

    public static function documentVatEvidence(): iterable
    {
        $totals = ['currency' => 'CZK', 'total_without_vat' => 10000, 'total_with_vat' => 12100];
        $item = ['quantity' => 1, 'unit_price_without_vat' => 10000, 'vat_rate' => 21];
        yield 'missing totals' => [['items' => [$item]], false];
        yield 'rate without charged VAT' => [['total_without_vat' => 10000, 'total_with_vat' => 10000, 'items' => [$item]], false];
        yield 'consistent line and totals' => [$totals + ['items' => [$item]], true];
        yield 'inconsistent line and totals' => [$totals + ['items' => [array_replace($item, ['vat_rate' => 12])]], false];
        yield 'gross line without recap' => [$totals + ['unit_prices_include_vat' => true, 'items' => [$item]], false];
        yield 'consistent recap with gross prices' => [$totals + ['unit_prices_include_vat' => true, 'vat_recap' => [['rate' => 21, 'base' => 10000, 'vat' => 2100]]], true];
        yield 'inconsistent recap' => [$totals + ['vat_recap' => [['rate' => 21, 'base' => 10000, 'vat' => 1200]]], false];
        yield 'VAT amount without positive rate' => [$totals + ['vat_recap' => [['rate' => 0, 'base' => 10000, 'vat' => 2100]]], false];
        yield 'rounding without VAT' => [['total_without_vat' => 10000, 'total_with_vat' => 10000.01, 'items' => [array_replace($item, ['vat_rate' => 0])]], false];
        yield 'credit note amounts' => [['total_without_vat' => -10000, 'total_with_vat' => -12100, 'items' => [array_replace($item, ['quantity' => -1])]], true];
    }

    // ── collapseToSummaryBaseLine (haléřový drift → 1 ks dle rekapitulace) ──

    public function testCollapse_fuelReceipt_matchesVatRecap(): void
    {
        // Reálný případ (faktura 984): 34,29 l × 33,71 = 1 155,92, ale REKAPITULACE
        // dokladu má základ 1 155,94. Sluč na 1 ks × 1 155,94.
        $items = [[
            'description' => 'Prémiová nafta', 'quantity' => 34.29, 'unit' => 'l',
            'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0,
        ]];
        $out = \MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 1155.94], false);

        self::assertNotNull($out);
        self::assertCount(1, $out);
        self::assertSame(1.0, $out[0]['quantity']);
        self::assertSame(1155.94, $out[0]['unit_price_without_vat']);
        self::assertSame(7, $out[0]['vat_rate_id']);
        self::assertSame('Prémiová nafta', $out[0]['description']);

        // A přes InvoiceMath sedí na rekapitulaci: 1 155,94 / 242,75 / 1 398,69.
        $r = \MyInvoice\Service\Invoice\InvoiceMath::compute([
            ['quantity' => 1.0, 'unit_price_without_vat' => 1155.94, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(1155.94, $r['totals']['without_vat']);
        self::assertSame(242.75,  $r['totals']['vat']);
        self::assertSame(1398.69, $r['totals']['with_vat']);
    }

    public function testCollapse_exactMatch_keepsLines(): void
    {
        // Součet řádků přesně sedí se základem → neslučovat (null).
        $items = [['description' => 'X', 'quantity' => 2.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 200.0], false));
    }

    public function testCollapse_multipleRates_keepsLines(): void
    {
        $items = [
            ['description' => 'A', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'B', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 50.0,  'vat_rate_id' => 8, 'order_index' => 1],
        ];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 149.99], false));
    }

    public function testCollapse_creditNote_keepsLines(): void
    {
        $items = [['description' => 'X', 'quantity' => 34.29, 'unit' => 'l', 'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 1155.94], true));
    }

    public function testCollapse_largeDiff_keepsLines(): void
    {
        // Rozdíl > 1 Kč = jiný problém (chybné řádky) → neslučovat, řeší warning.
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 950.0], false));
    }

    public function testCollapse_missingSummaryBase_keepsLines(): void
    {
        $items = [['description' => 'X', 'quantity' => 34.29, 'unit' => 'l', 'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, [], false));
    }

    // ── authoritativeRecapBaseLine (#99 — doklad s rekapitulací = záznam, ne kalkulačka) ──

    /** Reálný případ issue #99: účtenka za naftu, „cena/litr" 35,90 je BRUTTO. */
    private function fuelReceiptData(): array
    {
        return [
            'currency'         => 'CZK',
            'total_without_vat' => 2219.59,
            'total_with_vat'    => 2685.70,
            'vat_recap'         => [['rate' => 21, 'base' => 2219.59, 'vat' => 466.11]],
            'items'             => [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate' => 21]],
        ];
    }

    public function testAuthoritativeRecap_fuelReceipt_grossUnitPrice_usesDocumentBase(): void
    {
        $data = $this->fuelReceiptData();
        // řádek tak, jak ho extractor postaví z AI (35,90 = brutto cena/litr)
        $items = [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate_id' => 7, 'order_index' => 0]];

        $out = \MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false);

        self::assertNotNull($out);
        self::assertCount(1, $out);
        self::assertSame(1.0, $out[0]['quantity']);
        self::assertSame(2219.59, $out[0]['unit_price_without_vat']); // základ z rekapitulace, NE 2 685,68
        self::assertSame('DIESEL', $out[0]['description']);

        // A přes InvoiceMath sedí přesně na doklad: 2 219,59 / 466,11 / 2 685,70 (žádné zaokrouhlení).
        $r = \MyInvoice\Service\Invoice\InvoiceMath::compute([
            ['quantity' => 1.0, 'unit_price_without_vat' => 2219.59, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(2219.59, $r['totals']['without_vat']);
        self::assertSame(466.11,  $r['totals']['vat']);
        self::assertSame(2685.70, $r['totals']['with_vat']);
    }

    public function testAuthoritativeRecap_linesAlreadyMatchBase_keepsLines(): void
    {
        // Běžná faktura: řádky bez DPH sedí na základ z rekapitulace → NEslučovat (zachovej detail).
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 200.0, 'total_with_vat' => 242.0,
            'vat_recap' => [['rate' => 21, 'base' => 200.0, 'vat' => 42.0]],
        ];
        $items = [
            ['description' => 'A', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 120.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'B', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 80.0, 'vat_rate_id' => 7, 'order_index' => 1],
        ];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_inconsistentRecap_returnsNull(): void
    {
        // Rekapitulace NESEDÍ na celkem (200+42 ≠ 999) → nedůvěřuj, ať to vyřeší mismatch varování.
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 200.0, 'total_with_vat' => 999.0,
            'vat_recap' => [['rate' => 21, 'base' => 200.0, 'vat' => 42.0]],
        ];
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::singleRateConsistentRecap($data));
    }

    public function testSingleRateConsistentRecap_ignoresEmptyTemplateRows(): void
    {
        // AI běžně vrátí šablonu rekapitulace s nulovými řádky 0 %/12 % vedle reálné 21 %
        // (případ Axigon) — prázdné řádky musí jít stranou, ať zůstane „jednosazbové".
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 1554.88, 'total_with_vat' => 1881.40,
            'vat_recap' => [
                ['rate' => 0, 'base' => 0, 'vat' => 0],
                ['rate' => 12, 'base' => 0, 'vat' => 0],
                ['rate' => 21, 'base' => 1554.88, 'vat' => 326.52],
            ],
        ];
        $recap = \MyInvoice\Service\Import\AiPdfExtractor::singleRateConsistentRecap($data);
        self::assertNotNull($recap);
        self::assertSame(21.0, $recap['rate']);
        self::assertSame(1554.88, $recap['base']);
        self::assertSame(326.52, $recap['vat']);
    }

    public function testAuthoritativeRecap_multiRate_returnsNull(): void
    {
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 150.0, 'total_with_vat' => 180.5,
            'vat_recap' => [['rate' => 21, 'base' => 100.0, 'vat' => 21.0], ['rate' => 12, 'base' => 50.0, 'vat' => 6.0]],
        ];
        $items = [['description' => 'X', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 999.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_noRecap_returnsNull(): void
    {
        $data = ['currency' => 'CZK', 'total_without_vat' => 950.0];
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_creditNote_returnsNull(): void
    {
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine(
            [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate_id' => 7, 'order_index' => 0]],
            $this->fuelReceiptData(),
            true,
        ));
    }

    // ── linesAreGrossSingleRate (víceřádkový e-shop s brutto cenami → ceny s DPH) ──

    /** Doklad se sloupcem „Cena celkem s DPH": jednotkové ceny řádků jsou brutto. */
    private function grossEshopData(): array
    {
        return [
            'currency'          => 'CZK',
            'total_without_vat' => 15766.11,
            'total_with_vat'    => 19077.0,
            'vat_recap'         => [['rate' => 21, 'base' => 15766.11, 'vat' => 3310.89]],
            'items'             => [
                ['description' => 'Zboží A', 'quantity' => 4, 'unit_price_without_vat' => 4603, 'vat_rate' => 21],
                ['description' => 'Zboží B', 'quantity' => 4, 'unit_price_without_vat' => 149, 'vat_rate' => 21],
                ['description' => 'Služba C', 'quantity' => 1, 'unit_price_without_vat' => 69, 'vat_rate' => 21],
            ],
        ];
    }

    /** Řádky tak, jak je staví createDraft z AI (brutto v unit_price_without_vat). */
    private function grossEshopItems(): array
    {
        return [
            ['description' => 'Zboží A', 'quantity' => 4.0, 'unit' => 'ks', 'unit_price_without_vat' => 4603.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'Zboží B', 'quantity' => 4.0, 'unit' => 'ks', 'unit_price_without_vat' => 149.0, 'vat_rate_id' => 7, 'order_index' => 1],
            ['description' => 'Služba C', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 69.0, 'vat_rate_id' => 7, 'order_index' => 2],
        ];
    }

    public function testLinesAreGross_multiLineGrossEshop_true(): void
    {
        // Σ řádků 19 077 = CELKEM s DPH (≠ základ 15 766,11) → brutto ceny → true.
        self::assertTrue(\MyInvoice\Service\Import\AiPdfExtractor::linesAreGrossSingleRate(
            $this->grossEshopItems(), $this->grossEshopData(), false,
        ));
    }

    public function testLinesAreGross_keptAsPricesInclVat_matchesRecapExactly(): void
    {
        // Konec-konců přes InvoiceMath (shora) + override sedí na doklad přesně,
        // a hlavně všechny 3 řádky zůstanou zachované.
        $r = \MyInvoice\Service\Invoice\InvoiceMath::compute(
            array_map(static fn ($i) => [
                'quantity' => $i['quantity'],
                'unit_price_without_vat' => $i['unit_price_without_vat'],
                'vat_rate_snapshot' => 21,
            ], $this->grossEshopItems()),
            false,
            true, // prices_include_vat
            [['rate' => 21, 'base' => 15766.11, 'vat' => 3310.89]], // override § 73
        );
        self::assertCount(3, $r['items']);
        self::assertSame(15766.11, $r['totals']['without_vat']);
        self::assertSame(3310.89,  $r['totals']['vat']);
        self::assertSame(19077.0,  $r['totals']['with_vat']);
    }

    public function testLinesAreGross_linesAlreadyNet_false(): void
    {
        // Běžná faktura: řádky bez DPH sedí na základ (ne na celkem s DPH) → false.
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 200.0, 'total_with_vat' => 242.0,
            'vat_recap' => [['rate' => 21, 'base' => 200.0, 'vat' => 42.0]],
        ];
        $items = [
            ['description' => 'A', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 120.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'B', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 80.0, 'vat_rate_id' => 7, 'order_index' => 1],
        ];
        self::assertFalse(\MyInvoice\Service\Import\AiPdfExtractor::linesAreGrossSingleRate($items, $data, false));
    }

    // ──────────────────────────────────────────────────────────────────────
    // euAcquisitionTaxDate — zákonné DUZP pořízení zboží z JČS dle § 25 odst. 1
    // (issue #116/#117: doklad nese jen datum dodání, DUZP se musí dopočítat)
    // ──────────────────────────────────────────────────────────────────────

    public function testEuAcquisitionTaxDate_lateInvoice_fifteenthOfNextMonth(): void
    {
        // Stellantis case (issue #116): dodání 23.4., doklad vystaven až 4.6.
        // → DUZP = 15.5. (15. den měsíce následujícího po pořízení).
        self::assertSame(
            '2026-05-15',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-04-23', '2026-06-04'),
        );
    }

    public function testEuAcquisitionTaxDate_invoiceIssuedBeforeFifteenth_issueDateWins(): void
    {
        // Doklad vystavený PŘED 15. dnem následujícího měsíce → DUZP = den vystavení.
        self::assertSame(
            '2026-05-02',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-04-23', '2026-05-02'),
        );
        // Vystaveno už v měsíci dodání (běžný případ — faktura s dodávkou).
        self::assertSame(
            '2026-04-25',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-04-23', '2026-04-25'),
        );
    }

    public function testEuAcquisitionTaxDate_invoiceOnFifteenth_staysFifteenth(): void
    {
        // Vystaveno přesně 15. dne → totéž datum (hranice § 25 „před tímto dnem").
        self::assertSame(
            '2026-05-15',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-04-23', '2026-05-15'),
        );
    }

    public function testEuAcquisitionTaxDate_decemberDelivery_rollsToJanuary(): void
    {
        // Přelom roku: dodání v prosinci → 15. ledna následujícího roku.
        self::assertSame(
            '2027-01-15',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-12-23', '2027-02-01'),
        );
    }

    public function testEuAcquisitionTaxDate_missingOrInvalidDelivery_null(): void
    {
        // Bez data dodání nelze § 25 dopočítat → null (tax_date zůstane, jak je).
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate(null, '2026-06-04'));
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('23.4.2026', '2026-06-04'));
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('', '2026-06-04'));
    }

    public function testEuAcquisitionTaxDate_invalidIssueDate_fallsBackToFifteenth(): void
    {
        // Nevalidní datum vystavení → konzervativně 15. den následujícího měsíce.
        self::assertSame(
            '2026-05-15',
            \MyInvoice\Service\Import\AiPdfExtractor::euAcquisitionTaxDate('2026-04-23', ''),
        );
    }

    public function testLinesAreGross_noRecap_false(): void
    {
        $items = $this->grossEshopItems();
        self::assertFalse(\MyInvoice\Service\Import\AiPdfExtractor::linesAreGrossSingleRate($items, ['currency' => 'CZK'], false));
    }

    public function testLinesAreGross_creditNote_false(): void
    {
        self::assertFalse(\MyInvoice\Service\Import\AiPdfExtractor::linesAreGrossSingleRate(
            $this->grossEshopItems(), $this->grossEshopData(), true,
        ));
    }

    public function testLinesAreGross_multiRate_false(): void
    {
        // Vícesazbový doklad → singleRateConsistentRecap vrací null → false.
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 150.0, 'total_with_vat' => 180.5,
            'vat_recap' => [['rate' => 21, 'base' => 100.0, 'vat' => 21.0], ['rate' => 12, 'base' => 50.0, 'vat' => 6.0]],
        ];
        self::assertFalse(\MyInvoice\Service\Import\AiPdfExtractor::linesAreGrossSingleRate($this->grossEshopItems(), $data, false));
    }

    // ── reconcileLineAmount (NC Auto/BMW: „Cena" ≠ jednotková cena → vezmi částku) ──

    public function testReconcile_bmwMismatchedLine_usesLineTotal(): void
    {
        // AW 8,29 × 1 980 = 16 414, ale řádková částka je 1 980 → 1 ks × 1 980.
        self::assertSame([1.0, 1980.0],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(8.29, 1980.0, 1980.0));
    }

    public function testReconcile_consistentLine_keepsQtyAndPrice(): void
    {
        // 6 × 239 = 1 434 = částka přesně → neměnit (zachovej qty/cenu pro itemizaci).
        self::assertSame([6.0, 239.0],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(6.0, 239.0, 1434.0));
    }

    public function testReconcile_noLineTotal_keepsQtyAndPrice(): void
    {
        self::assertSame([4.0, 4603.0],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(4.0, 4603.0, null));
    }

    public function testReconcile_haléřDrift_snapsToLineTotal(): void
    {
        // 0,5 × 235,05 = 117,525 → 117,53 vs částka 117,52: i haléřový drift snapneme
        // na řádkovou částku (jinak by součet řádků rozhodil základ a spustil collapse).
        self::assertSame([1.0, 117.52],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(0.5, 235.05, 117.52));
    }

    public function testReconcile_unitPricePrintedInCents_keepsQuantityWithExactPrice(): void
    {
        // Doklad tiskne 0,01 EUR/ks, řádek 2 700,00 za 250 000 ks → přesná cena 0,0108.
        self::assertSame([250000.0, 0.0108],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(250000.0, 0.01, 2700.0));
        // 0,00 EUR/ks a řádek 1 000,00 → 0,004, ne 1 ks × 1 000.
        self::assertSame([250000.0, 0.004],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(250000.0, 0.0, 1000.0));
        // Sleva: záporná cena zaokrouhlená na haléře drží znaménko.
        self::assertSame([3.0, -0.333333],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(3.0, -0.33, -1.0));
    }

    public function testReconcile_negativeLineTotal_preservesSign(): void
    {
        // Záporný řádek (sleva/storno) s nesedícím součinem (5 × −23 = −115 ≠ −69)
        // → 1 ks × záporná částka.
        self::assertSame([1.0, -69.0],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(5.0, -23.0, -69.0));
    }

    public function testReconcile_zeroLineTotal_keepsQtyAndPrice(): void
    {
        self::assertSame([2.0, 100.0],
            \MyInvoice\Service\Import\AiPdfExtractor::reconcileLineAmount(2.0, 100.0, 0.0));
    }

    // ── fixSwappedIssueDueDates (AI zaměnila „Datum vystavení" ↔ „Datum splatnosti") ──

    public function testFixDates_dueBeforeIssue_swapsBack(): void
    {
        // Reálný report #302: AI vrátila vystavení 23.06 a splatnost 09.06 → prohozeno.
        self::assertSame(
            ['issue_date' => '2026-06-09', 'due_date' => '2026-06-23'],
            \MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
                'issue_date' => '2026-06-23',
                'due_date'   => '2026-06-09',
            ]),
        );
    }

    public function testFixDates_correctOrder_returnsNull(): void
    {
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '2026-06-09',
            'due_date'   => '2026-06-23',
        ]));
    }

    public function testFixDates_equalDates_returnsNull(): void
    {
        // Splatnost = vystavení (např. platba v hotovosti) je validní pořadí.
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '2026-06-09',
            'due_date'   => '2026-06-09',
        ]));
    }

    public function testFixDates_missingDueDate_returnsNull(): void
    {
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '2026-06-23',
            'due_date'   => null,
        ]));
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '2026-06-23',
        ]));
    }

    public function testFixDates_nonIsoDate_returnsNull(): void
    {
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '23.06.2026',
            'due_date'   => '09.06.2026',
        ]));
    }

    public function testFixDates_doesNotTouchTaxDate(): void
    {
        // tax_date (DUZP) zůstává netknuté i při prohození vystavení↔splatnost.
        $out = \MyInvoice\Service\Import\AiPdfExtractor::fixSwappedIssueDueDates([
            'issue_date' => '2026-06-23',
            'tax_date'   => '2026-06-09',
            'due_date'   => '2026-06-09',
        ]);
        self::assertSame(['issue_date' => '2026-06-09', 'due_date' => '2026-06-23'], $out);
        self::assertArrayNotHasKey('tax_date', $out);
    }

    // ── inferReverseCharge — brzdy před heuristikou (audit VAT klasifikací) ──
    // Obě větve rozhodnou DŘÍV, než se sáhne na zemi dodavatele, takže testy běží
    // bez DB (Connection je mock). To je zároveň pointa: nesmí se ptát databáze,
    // když už z dokladu samotného plyne, že o reverse charge nejde.

    /**
     * C-4: sazba, kterou český číselník nezná (DE 19 %), skončí na řádku jako zástupná
     * NULA. Kdyby o RC rozhodovala ta, vypadala by faktura s vyčíslenou německou daní
     * jako plnění bez daně → samovyměření ř. 5/12 + KH A.2 a odpočet ř. 43 z cizí daně.
     */
    public function testInferReverseCharge_unmappedForeignRateBlocksSelfAssessment(): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'inferReverseCharge');
        $items = [['description' => 'Beratung', 'vat_rate_id' => 1]];

        self::assertFalse($ref->invoke($this->extractor, 42, $items, [19.0]));
    }

    /**
     * H-7: poplatek orgánu veřejné moci není zdanitelné plnění (§ 5 odst. 4 ZDPH),
     * takže se § 9 odst. 1 neuplatní ani u zahraničního soudu — heuristika musí mlčet.
     */
    public function testInferReverseCharge_publicAuthorityFeeBlocksSelfAssessment(): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'inferReverseCharge');
        $items = [['description' => 'Gerichtskosten Europäisches Mahnverfahren', 'vat_rate_id' => 1]];

        self::assertFalse($ref->invoke($this->extractor, 42, $items, []));
    }

    // ── normalizeIc (BUG 2, vendor bugreport 2026-08-06) ────────────────────
    // Guard „Faktura adresovaná jinému plátci" (extractAndCreate) porovnává
    // customer.ic vs tenant.ic jako řetězec po normalizaci. AI extrakce u IČO
    // začínajícího nulou vrátí jen 7 číslic — bez zero-padu na 8 míst by dvě
    // stejná IČO (`01234567` vs `1234567`) vypadala jako neshoda a guard by
    // legitimní doklad odmítl.

    public function testNormalizeIc_padsLeadingZero(): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'normalizeIc');
        self::assertSame('01234567', $ref->invoke($this->extractor, '1234567'));
        self::assertSame('01234567', $ref->invoke($this->extractor, '01234567'));
    }

    public function testNormalizeIc_regularEightDigitIcUnchanged(): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'normalizeIc');
        self::assertSame('12345679', $ref->invoke($this->extractor, '12345679'));
    }

    public function testNormalizeIc_emptyReturnsNull(): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'normalizeIc');
        self::assertNull($ref->invoke($this->extractor, ''));
    }

    // ── vendorCountryInfo: registrace k DPH vs. sídlo ──────────────────────
    // Mimoevropská firma fakturující přes registraci k DPH v jiném členském státě
    // (adresa mimo EU, DIČ `IE…`) je pro § 9 odst. 1 osobou registrovanou v JČS:
    // přijatá SLUŽBA patří na ř. 5 (kód 24e), ne na ř. 12. U ZBOŽÍ o tom prefix DIČ
    // nerozhoduje — pořízení z JČS (§ 16) vs. dovoz (§ 20/§ 23) se liší fyzickým
    // pohybem zboží, takže tam přednost prefixu neplatí.
    //
    // Všechna DIČ v testech jsou syntetická (repo je veřejné).

    /**
     * @return iterable<string, array{0:string, 1:bool, 2:?string, 3:bool, 4:string, 5:bool}>
     */
    public static function vendorCountryCases(): iterable
    {
        // [ iso2 sídla, is_eu sídla, dic, je to služba?, očekávané iso2, očekávané is_eu ]
        yield 'sluzba: adresa US + irske DIC → registrace v JCS (r. 5)' => ['US', false, 'IE1234567X', true, 'IE', true];
        yield 'zbozi: adresa US + irske DIC → zustava 3. zemi (dovoz)' => ['US', false, 'IE1234567X', false, 'US', false];
        yield 'sluzba: rakouske DIC s pismenem U'                      => ['US', false, 'ATU12345678', true, 'AT', true];
        yield 'sluzba: recke EL se preklada na ISO GR'                 => ['US', false, 'EL123456789', true, 'GR', true];
        yield 'sluzba: non-Union OSS prefix EU neni clensky stat'      => ['US', false, 'EU372000000', true, 'US', false];
        yield 'sluzba: GB po Brexitu neni clensky stat'                => ['GB', false, 'GB123456789', true, 'GB', false];
        yield 'sluzba: XI (Severni Irsko) je pro sluzby 3. zemi'       => ['US', false, 'XI123456789', true, 'US', false];
        yield 'sluzba: tuzemsky prefix CZ se neuplatni'                => ['US', false, 'CZ12345678', true, 'US', false];
        yield 'sluzba: samotne "IE" bez narodni casti neni DIC'        => ['US', false, 'IE', true, 'US', false];
        yield 'sluzba: volny text v poli DIC neprepne zarazeni'        => ['US', false, 'DEutschland s.r.o.', true, 'US', false];
        yield 'sluzba: obchodni jmeno s cislici take neprojde'         => ['US', false, 'DEUTSCHLAND12', true, 'US', false];
        yield 'sluzba: bez DIC rozhoduje adresa'                       => ['US', false, null, true, 'US', false];
        yield 'sluzba: prazdne DIC rozhoduje adresa'                   => ['US', false, '   ', true, 'US', false];
        yield 'sluzba: sidlo v EU zustava sidlem (zadny prepis)'       => ['DE', true, 'DE123456789', true, 'DE', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vendorCountryCases')]
    public function testVendorCountryInfo_prefersVatIdRegistrationOnlyForServices(
        string $addressIso2,
        bool $addressIsEu,
        ?string $dic,
        bool $isService,
        string $expectedIso2,
        bool $expectedIsEu,
    ): void {
        $this->givenVendor($addressIso2, $addressIsEu, $dic);

        $info = $this->invokeVendorCountryInfo(1, $isService);

        self::assertSame($expectedIso2, $info['iso2']);
        self::assertSame($expectedIsEu, $info['is_eu']);
    }

    public function testVendorCountryInfo_euMembershipDoesNotDependOnEditableCodebook(): void
    {
        // `countries.is_eu` je číselník, který si zákazník může v administraci přepsat.
        // Zákonné zařazení do přiznání na něm viset nesmí: seznam členských států drží
        // KontrolniHlaseniBuilder (tabulka z dphkh1.xsd), stejně jako pro KH A.2.
        $this->givenVendor('US', false, 'IE1234567X', codebook: [
            ['iso2' => 'US', 'is_eu' => 0],
            ['iso2' => 'IE', 'is_eu' => 0], // přepsaný/rozbitý číselník
        ]);

        $info = $this->invokeVendorCountryInfo(1, true);

        self::assertSame('IE', $info['iso2']);
        self::assertTrue($info['is_eu']);
    }

    public function testVendorCountryInfo_keepsSeatCountryForTheDocumentWarning(): void
    {
        // Rozdíl sídlo × stát registrace musí jít vyčíst i po překlopení — volající
        // na něj upozorňuje uživatele varováním na dokladu (ne jen INFO logem).
        $this->givenVendor('US', false, 'IE1234567X');

        $info = $this->invokeVendorCountryInfo(1, true);

        self::assertSame('US', $info['address_iso2']);
        self::assertSame('IE', $info['iso2']);
    }

    public function testVendorCountryInfo_unknownVendorFallsBackToSafeDefault(): void
    {
        $this->givenVendor('US', false, 'IE1234567X');

        $info = $this->invokeVendorCountryInfo(999, true);

        self::assertSame('', $info['iso2']);
        self::assertFalse($info['is_eu']);
        self::assertSame('', $info['address_iso2']);
    }

    /**
     * Dodavatel #1 nad in-memory číselníkem zemí. Výchozí číselník je ZÁMĚRNĚ úplný
     * (IE/DE/GR/AT tam jsou a jsou vedené jako EU) — jinak by se testy o prefixu DIČ
     * mohly „splnit" jen tím, že daná země v číselníku chybí.
     *
     * @param list<array{iso2:string, is_eu:int}>|null $codebook
     */
    private function givenVendor(string $addressIso2, bool $addressIsEu, ?string $dic, ?array $codebook = null): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT, is_eu INTEGER)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, country_id INTEGER, dic TEXT)');

        $rows = $codebook ?? [
            ['iso2' => 'US', 'is_eu' => 0],
            ['iso2' => 'IE', 'is_eu' => 1],
            ['iso2' => 'DE', 'is_eu' => 1],
            ['iso2' => 'GR', 'is_eu' => 1],
            ['iso2' => 'AT', 'is_eu' => 1],
            ['iso2' => 'CZ', 'is_eu' => 1],
            ['iso2' => 'GB', 'is_eu' => 0],
        ];
        $ins = $pdo->prepare('INSERT INTO countries (id, iso2, is_eu) VALUES (?, ?, ?)');
        $seatId = null;
        $id = 0;
        foreach ($rows as $r) {
            $id++;
            $isSeat = $r['iso2'] === $addressIso2;
            $ins->execute([$id, $r['iso2'], $isSeat ? ($addressIsEu ? 1 : 0) : $r['is_eu']]);
            if ($isSeat && $seatId === null) {
                $seatId = $id;
            }
        }
        self::assertNotNull($seatId, "Číselník testu neobsahuje zemi sídla {$addressIso2}.");
        $pdo->prepare('INSERT INTO clients (id, country_id, dic) VALUES (1, ?, ?)')->execute([$seatId, $dic]);

        $this->conn->method('pdo')->willReturn($pdo);
    }

    /** @return array{iso2:string, is_eu:bool, address_iso2:string} */
    private function invokeVendorCountryInfo(int $vendorId, bool $isService): array
    {
        $ref = new \ReflectionMethod($this->extractor, 'vendorCountryInfo');

        return $ref->invoke($this->extractor, $vendorId, $isService);
    }

    // ── Helper: reflection invokers ────────────────────────────────────────

    private function invokeResolvePricesInclVat(array $data, string $documentKind): bool
    {
        $ref = new \ReflectionMethod($this->extractor, 'resolvePricesIncludeVat');
        return (bool) $ref->invoke(null, $data, $documentKind);
    }

    private function invokeDetectWeak(array $data, ?string $tenantIc): ?string
    {
        $ref = new \ReflectionMethod($this->extractor, 'detectWeakExtraction');
        return $ref->invoke($this->extractor, $data, $tenantIc);
    }

    private function invokeFlag(int $invoiceId, int $supplierId, array $data, array $items, bool $pricesIncludeVat = false): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'maybeFlagTotalsMismatch');
        $ref->invoke($this->extractor, $invoiceId, $supplierId, $data, $items, $pricesIncludeVat);
    }

    private function invokeRounding(int $id, int $supplierId, array $data, bool $isCredit): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'applyRoundingFromPdfTotal');
        $ref->invoke($this->extractor, $id, $supplierId, $data, $isCredit);
    }
}
