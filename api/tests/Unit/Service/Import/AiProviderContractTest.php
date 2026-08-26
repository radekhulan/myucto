<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use MyInvoice\Service\Import\LlmGatewayRouter;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\ResidencyPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F7 §11.1 — golden-schema kontraktní test čtyř providerů + ResidencyPolicy
 * (fail-closed pro všechny 4 vč. strongerModel/testConnection) + per-provider
 * validateKey + inkrement čítače přesně 1× (žádné dvojí počítání).
 */
#[AllowMockObjectsWithoutExpectations]
final class AiProviderContractTest extends TestCase
{
    /**
     * Reprezentativní extrahovaná faktura — IDENTICKÁ napříč providery.
     * Vrací se v JSON-round-trip kanonické podobě (celá čísla bez desetinné části
     * se přes JSON dekódují jako int) — to je přesně ten normalizovaný tvar, který
     * všechny 4 klienty vyprodukují.
     */
    private function goldenData(): array
    {
        return (array) json_decode((string) json_encode([
            'vendor'   => ['company_name' => 'ACME s.r.o.', 'ic' => '12345678', 'dic' => 'CZ12345678', 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Vzorová firma s.r.o.', 'ic' => '12345679', 'dic' => 'CZ12345679'],
            'payment'  => ['bank_account' => '2900123456/2010', 'iban' => null, 'variable_symbol' => '2026001'],
            'vendor_invoice_number' => '2026001',
            'document_kind' => 'invoice',
            'issue_date'    => '2026-01-15',
            'tax_date'      => '2026-01-15',
            'due_date'      => '2026-01-29',
            'currency'      => 'CZK',
            'items'         => [
                ['description' => 'Konzultace', 'quantity' => 2, 'unit' => 'h', 'unit_price_without_vat' => 1000.0, 'line_total_without_vat' => 2000.0, 'vat_rate' => 21.0],
            ],
            'unit_prices_include_vat' => false,
            'total_without_vat'       => 2000.0,
            'total_with_vat'          => 2420.0,
            'vat_recap'               => [['rate' => 21.0, 'base' => 2000.0, 'vat' => 420.0]],
            'already_paid'            => false,
            'supply_nature'           => 'services',
        ]), true);
    }

    // ── Golden-schema kontrakt: všechny 4 dekódují na IDENTICKÝ tvar ──────────

    public function testGoldenSchema_allFourProvidersDecodeIdentically(): void
    {
        $golden = $this->goldenData();
        $text   = (string) json_encode($golden, JSON_UNESCAPED_UNICODE);

        // Provider-nativní raw HTTP obálky se STEJNÝM vnitřním JSON textem.
        $anthropicBody = ['content' => [['type' => 'text', 'text' => $text]], 'model' => 'claude-haiku-4-5'];
        // OpenAI/Azure sdílejí chat-completions obálku; přidáme markdown fences,
        // ať se ověří i strip-normalizace.
        $openaiBody = ['choices' => [['message' => ['content' => "```json\n" . $text . "\n```"]]], 'model' => 'gpt-4o-mini'];
        $azureBody  = ['choices' => [['message' => ['content' => $text]]], 'model' => 'gpt-4o'];
        $geminiBody = ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];

        $decAnthropic = InvoiceExtractionPrompt::decodeJsonText((string) $anthropicBody['content'][0]['text']);
        $decOpenai    = InvoiceExtractionPrompt::decodeJsonText((string) AzureOpenAiClient::extractText($openaiBody));
        $decAzure     = InvoiceExtractionPrompt::decodeJsonText((string) AzureOpenAiClient::extractText($azureBody));
        $decGemini    = InvoiceExtractionPrompt::decodeJsonText((string) GeminiClient::extractText($geminiBody));

        self::assertSame($golden, $decAnthropic, 'Anthropic decode');
        self::assertSame($golden, $decOpenai, 'OpenAI decode (fenced)');
        self::assertSame($golden, $decAzure, 'Azure decode');
        self::assertSame($golden, $decGemini, 'Gemini decode');

        // A všechny čtyři jsou navzájem identické — golden-schema kontrakt splněn.
        self::assertSame($decAnthropic, $decOpenai);
        self::assertSame($decAnthropic, $decAzure);
        self::assertSame($decAnthropic, $decGemini);
        // Kontraktní podmnožina explicitně.
        foreach (['vendor', 'customer', 'items', 'vat_recap', 'document_kind'] as $k) {
            self::assertSame($golden[$k], $decGemini[$k], "kontraktní klíč $k musí být identický");
        }
    }

    // ── ResidencyPolicy: EU-required → us = konflikt pro všechny 4 providery ──

    public function testResidencyPolicy_blocksEuRequiredToUs_allProviders(): void
    {
        $policy = new ResidencyPolicy();
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini'] as $provider) {
            // us + eu-required → výjimka.
            try {
                $policy->assertAllowed($provider, 'us', true);
                self::fail("očekáván residency conflict pro $provider/us");
            } catch (\MyInvoice\Service\Import\ResidencyViolationException) {
                self::assertTrue(true);
            }
            // eu + eu-required → prochází.
            $policy->assertAllowed($provider, 'eu', true);
            // us + NEvyžadováno → prochází.
            $policy->assertAllowed($provider, 'us', false);
        }
    }

    /** Reálný region pochází z capabilities() — single source of truth (§13.2). */
    public function testCapabilities_dataRegionSourceOfTruth(): void
    {
        self::assertSame('us', LlmProviderCapabilities::anthropic('us')->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::gemini(null)->dataRegion);
        self::assertSame('eu', LlmProviderCapabilities::openai('https://eu.api.openai.com', null)->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::openai(null, null)->dataRegion);
        // Azure (security audit MEDIUM): rezidence jen pro OVĚŘENÝ Azure host (allowlist).
        // Standardní Azure OpenAI endpoint region v hostname nenese → rezidence z deklarace.
        self::assertSame('eu', LlmProviderCapabilities::azureOpenai('https://myres.openai.azure.com', 'dep', 'eu')->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::azureOpenai('https://myres.openai.azure.com', 'dep', 'us')->dataRegion);
        // Hostname s EU region-tokenem (na Azure hostu) → 'eu' i při deklaraci 'us'.
        self::assertSame('eu', LlmProviderCapabilities::azureOpenai('https://westeurope.cognitiveservices.azure.com', 'dep', 'us')->dataRegion);
        // NeAzure / crafted host → fail-closed 'us' i s deklarací 'eu' (spoof blokován).
        self::assertSame('us', LlmProviderCapabilities::azureOpenai('https://custom.example.com', 'dep', 'eu')->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::azureOpenai('https://x-swedencentral.attacker.tld', 'dep', 'eu')->dataRegion);
        // M2: OpenAI base_url exact-host — spoofed/foreign host NIKDY 'eu' (žádný substring match).
        self::assertSame('us', LlmProviderCapabilities::openai('https://eu.api.openai.com.attacker.tld', null)->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::openai('https://api.openai.com', null)->dataRegion);
        self::assertSame('us', LlmProviderCapabilities::openai('https://evil.tld/eu.api.openai.com', null)->dataRegion);
    }

    /**
     * Router-level fail-closed: EU-required tenant s us-resolving providerem NIKDY
     * neprojde na extrakci / testConnection / strongerModel (§3.5).
     */
    public function testRouter_euRequiredBlocksAllDelegatedMethods_allProviders(): void
    {
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini'] as $provider) {
            $router = $this->makeRouter($provider, true, 'eu');
            // extrakce — všechny 4 metody vrací residency_conflict (žádný HTTP call).
            self::assertSame(['ok' => false, 'error' => 'residency_conflict'], $router->extractInvoice(1, '%PDF-1.4 x'), "$provider extractInvoice");
            self::assertSame(['ok' => false, 'error' => 'residency_conflict'], $router->extractFuelTransactions(1, '%PDF-1.4 x'), "$provider extractFuel");
            self::assertSame(['ok' => false, 'error' => 'residency_conflict'], $router->extractPdfTotal(1, '%PDF-1.4 x'), "$provider extractTotal");
            self::assertSame(['ok' => false, 'error' => 'residency_conflict'], $router->extractPaymentAccount(1, '%PDF-1.4 x'), "$provider extractPayment");
            // testConnection také konflikt.
            self::assertSame(['ok' => false, 'error' => 'residency_conflict'], $router->testConnection(1), "$provider testConnection");
            // strongerModel fail-closed → null (žádný upgrade na us endpoint).
            self::assertNull($router->strongerModel(1, 'claude-haiku-4-5'), "$provider strongerModel");
        }
    }

    // ── Per-provider validateKey ─────────────────────────────────────────────

    public function testValidateKey_perProvider(): void
    {
        self::assertNull(LlmProviderCapabilities::anthropic()->validateKey('sk-ant-' . str_repeat('a', 40)));
        self::assertNotNull(LlmProviderCapabilities::anthropic()->validateKey('nope'));

        self::assertNull(LlmProviderCapabilities::openai(null, null)->validateKey('sk-' . str_repeat('a', 40)));
        self::assertNotNull(LlmProviderCapabilities::openai(null, null)->validateKey('AIza' . str_repeat('a', 40)));

        self::assertNull(LlmProviderCapabilities::gemini(null)->validateKey('AIza' . str_repeat('a', 35)));
        self::assertNull(LlmProviderCapabilities::gemini(null)->validateKey('AQ.' . str_repeat('a', 40)));
        self::assertNotNull(LlmProviderCapabilities::gemini(null)->validateKey('sk-short'));
        self::assertNotNull(LlmProviderCapabilities::gemini(null)->validateKey('AQ.' . str_repeat('a', 20) . ' '));

        self::assertNull(LlmProviderCapabilities::azureOpenai('https://x.openai.azure.com', 'dep', 'eu')->validateKey('some-azure-key'));
        self::assertNotNull(LlmProviderCapabilities::azureOpenai('https://x', 'dep', 'eu')->validateKey(''));
    }

    public function testGeminiInvoiceRequest_requiresCompleteSharedSchema(): void
    {
        $golden = $this->goldenData();
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'candidates' => [['content' => ['parts' => [[
                    'text' => (string) json_encode($golden, JSON_UNESCAPED_UNICODE),
                ]]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            if (str_contains($sql, 'gemini_api_key_enc')) {
                $stmt->method('fetch')->willReturn([
                    'gemini_api_key_enc' => 'ENC',
                    'gemini_default_model' => 'gemini-3-flash',
                ]);
            } elseif (str_contains($sql, 'company_name')) {
                $stmt->method('fetch')->willReturn(false);
            } else {
                $stmt->method('fetch')->willReturn([]);
            }
            return $stmt;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('AQ.' . str_repeat('a', 40));

        $result = (new GeminiClient($conn, $crypto, new NullLogger(), $http))
            ->extractInvoice(1, "%PDF-1.4\nfake");

        self::assertTrue($result['ok']);
        self::assertCount(1, $history);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertIsArray($payload);
        $schema = $payload['generationConfig']['responseSchema'];
        self::assertSame(array_keys($schema['properties']), $schema['required']);
        self::assertSame(
            array_keys($schema['properties']['items']['items']['properties']),
            $schema['properties']['items']['items']['required'],
        );
        self::assertArrayHasKey('corrected_invoice_number', $schema['properties']);
        self::assertArrayHasKey('expense_kind', $schema['properties']['items']['items']['properties']);
        self::assertSame(16384, $payload['generationConfig']['maxOutputTokens']);
        self::assertSame('low', $payload['generationConfig']['thinkingConfig']['thinkingLevel']);
        self::assertStringContainsString(
            '/models/' . LlmProviderCapabilities::GEMINI_DEFAULT_MODEL . ':generateContent',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testGeminiCapabilities_useCurrentStableDefault(): void
    {
        $caps = LlmProviderCapabilities::gemini(null);

        self::assertSame(LlmProviderCapabilities::GEMINI_DEFAULT_MODEL, $caps->defaultModel);
        self::assertContains(LlmProviderCapabilities::GEMINI_DEFAULT_MODEL, $caps->models);
        // Modely, které provider už novým účtům nevydá (404), do whitelistu nepatří.
        self::assertNotContains('gemini-2.0-flash', $caps->models);
        self::assertNotContains('gemini-2.5-flash', $caps->models);
        // Neznámý / vyřazený uložený model spadne zpět na aktuální default.
        self::assertSame(
            LlmProviderCapabilities::GEMINI_DEFAULT_MODEL,
            LlmProviderCapabilities::gemini('gemini-3-flash')->defaultModel,
        );
        self::assertSame(
            LlmProviderCapabilities::GEMINI_DEFAULT_MODEL,
            LlmProviderCapabilities::gemini('gemini-2.5-flash')->defaultModel,
        );
    }

    /**
     * Žebříček eskalace anthropic je haiku → sonnet → opus → fable. Fable je VRCHOL,
     * ne levný tier — dřívější token heuristika ho posílala zpátky na sonnet.
     * Test jde celý řetěz krok po kroku a hlídá, že končí a nezacyklí se.
     */
    public function testStrongerModel_anthropicLadderEndsAtFable(): void
    {
        $caps = LlmProviderCapabilities::anthropic();

        self::assertSame('claude-sonnet-5', $caps->strongerModel('claude-haiku-4-5'));
        self::assertSame('claude-opus-5', $caps->strongerModel('claude-sonnet-5'));
        self::assertSame('claude-opus-5', $caps->strongerModel('claude-sonnet-4-6'));
        self::assertSame('claude-fable-5', $caps->strongerModel('claude-opus-5'));
        self::assertSame('claude-fable-5', $caps->strongerModel('claude-opus-4-7'));
        // Vrchol žebříčku — dál už není kam.
        self::assertNull($caps->strongerModel('claude-fable-5'));
        self::assertNull($caps->strongerModel(null));

        // Celý řetěz z defaultu doběhne na vrchol a nezacyklí se.
        $seen  = [];
        $model = LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;
        while ($model !== null) {
            self::assertNotContains($model, $seen, 'eskalace se zacyklila');
            self::assertContains($model, $caps->models);
            $seen[] = $model;
            $model  = $caps->strongerModel($model);
        }
        self::assertSame(
            ['claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5', 'claude-fable-5'],
            $seen,
        );
    }

    /**
     * Escalace „slabý model → silnější" musí přežít každý bump whitelistu — když
     * extrakce neprojde, router na tomhle stojí. Cíl musí být jiný model, který je
     * zároveň ve whitelistu, a model na vrcholu žebříčku už neeskaluje.
     */
    public function testStrongerModel_escalationChainSurvivesWhitelistBumps(): void
    {
        $openai = LlmProviderCapabilities::openai(null, null);
        // nano → mini → plný model.
        self::assertSame('gpt-5.4-mini', $openai->strongerModel('gpt-5.4-nano'));
        foreach (['gpt-5.4-mini', 'gpt-4o-mini'] as $weak) {
            $up = $openai->strongerModel($weak);
            self::assertNotNull($up, "openai $weak neeskaluje");
            self::assertContains($up, $openai->models);
            self::assertStringNotContainsString('mini', $up);
            self::assertStringNotContainsString('nano', $up);
        }
        self::assertNull($openai->strongerModel('gpt-5.6-sol'));
        // Default OpenAI modelu musí být levný tier, aby escalace vůbec měla kam jít.
        self::assertNotNull($openai->strongerModel(LlmProviderCapabilities::OPENAI_DEFAULT_MODEL));

        $gemini = LlmProviderCapabilities::gemini(null);
        // flash-lite nesmí spadnout do tieru flash — jinak by eskaloval sám na sebe.
        self::assertSame('gemini-3.7-flash', $gemini->strongerModel('gemini-3.5-flash-lite'));
        foreach (['gemini-3.7-flash', 'gemini-3.5-flash'] as $weak) {
            $up = $gemini->strongerModel($weak);
            self::assertNotNull($up, "gemini $weak neeskaluje");
            self::assertContains($up, $gemini->models);
            self::assertStringContainsString('pro', $up);
        }
        self::assertNull($gemini->strongerModel('gemini-2.5-pro'));
        self::assertNotNull($gemini->strongerModel(LlmProviderCapabilities::GEMINI_DEFAULT_MODEL));

        // Model mimo žebříček providera neeskaluje (fail-closed, žádný náhodný upgrade).
        self::assertNull($gemini->strongerModel('gemini-nesmysl-x'));
    }

    /**
     * Volba rychle/přesně se překládá na provider-nativní knob a hlavně se NEPOSÍLÁ
     * modelům, které ho neumí — tam je to tvrdá 400, ne degradace. Matice ověřena
     * živě proti API (private/scripts/probe_llm_effort.php).
     */
    public function testEffortPayload_onlyForModelsThatSupportIt(): void
    {
        $anthropic = LlmProviderCapabilities::anthropic();
        // haiku effort parametr nezná.
        self::assertSame([], $anthropic->effortPayload('claude-haiku-4-5', LlmProviderCapabilities::EFFORT_ACCURATE));
        self::assertSame(
            ['output_config' => ['effort' => 'high']],
            $anthropic->effortPayload('claude-sonnet-5', LlmProviderCapabilities::EFFORT_ACCURATE),
        );
        self::assertSame(
            ['output_config' => ['effort' => 'low']],
            $anthropic->effortPayload('claude-opus-4-7', LlmProviderCapabilities::EFFORT_FAST),
        );

        $openai = LlmProviderCapabilities::openai(null, null);
        self::assertSame(
            ['reasoning_effort' => 'high'],
            $openai->effortPayload('gpt-5.6-sol', LlmProviderCapabilities::EFFORT_ACCURATE),
        );
        self::assertSame(
            ['reasoning_effort' => 'low'],
            $openai->effortPayload('gpt-5.4-mini', LlmProviderCapabilities::EFFORT_FAST),
        );
        self::assertSame(['reasoning_effort' => 'low'], $openai->effortPayload('o3-mini', LlmProviderCapabilities::EFFORT_FAST));
        // gpt-4.x hlásí „Unrecognized request argument" — nesmí ho dostat.
        foreach (['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'] as $legacy) {
            self::assertSame([], $openai->effortPayload($legacy, LlmProviderCapabilities::EFFORT_ACCURATE), $legacy);
        }

        $gemini = LlmProviderCapabilities::gemini(null);
        self::assertSame(
            ['thinkingConfig' => ['thinkingLevel' => 'high']],
            $gemini->effortPayload('gemini-3.7-flash', LlmProviderCapabilities::EFFORT_ACCURATE),
        );
        // gemini-2.5 zná jen starší thinkingBudget.
        self::assertSame(
            ['thinkingConfig' => ['thinkingBudget' => 8192]],
            $gemini->effortPayload('gemini-2.5-pro', LlmProviderCapabilities::EFFORT_ACCURATE),
        );
        // Azure deployment může nést cokoliv → fail-safe mlčíme.
        self::assertSame([], LlmProviderCapabilities::azureOpenai('https://x.openai.azure.com', 'gpt-5')
            ->effortPayload('gpt-5', LlmProviderCapabilities::EFFORT_ACCURATE));
    }

    /**
     * `default` znamená „chovej se jako před zavedením volby" — žádnému providerovi
     * se nesmí poslat nic navíc. Totéž pro nesmyslnou hodnotu (fail-safe).
     */
    public function testEffortPayload_defaultSendsNothingAnywhere(): void
    {
        $all = [
            LlmProviderCapabilities::anthropic(),
            LlmProviderCapabilities::openai(null, null),
            LlmProviderCapabilities::gemini(null),
            LlmProviderCapabilities::azureOpenai('https://x.openai.azure.com', 'dep'),
        ];
        foreach ($all as $caps) {
            foreach (['claude-sonnet-5', 'gpt-5.6-sol', 'gemini-3.7-flash', 'dep'] as $model) {
                self::assertSame([], $caps->effortPayload($model, LlmProviderCapabilities::EFFORT_DEFAULT), "$caps->id/$model default");
                self::assertSame([], $caps->effortPayload($model, 'nesmysl'), "$caps->id/$model nesmysl");
            }
        }
    }

    /**
     * Poznámky účtárny jdou do promptu jako DATA v oddělené sekci, ne jako pravidla,
     * a jsou useknuté na strop — jinak by šlo prompt utopit nebo přebít schéma.
     */
    public function testTenantNotes_isolatedSectionAndCapped(): void
    {
        self::assertSame('', InvoiceExtractionPrompt::tenantNotes(null));
        self::assertSame('', InvoiceExtractionPrompt::tenantNotes('   '));
        self::assertSame('', InvoiceExtractionPrompt::tenantNotes(123));

        $block = InvoiceExtractionPrompt::tenantNotes('ACME píše VS do pole Reference.');
        self::assertStringContainsString('ACME píše VS do pole Reference.', $block);
        // Sekce musí sama říct, že nepřebíjí pravidla výše.
        self::assertStringContainsString('NE pravidla', $block);
        self::assertStringContainsString('platí pravidla výše', $block);

        // Vyplň znakem, který hlavička sekce sama neobsahuje, jinak by se počítala i ona.
        $long = str_repeat('ü', InvoiceExtractionPrompt::TENANT_NOTES_MAX + 500);
        $capped = InvoiceExtractionPrompt::tenantNotes($long);
        self::assertSame(
            InvoiceExtractionPrompt::TENANT_NOTES_MAX,
            mb_substr_count($capped, 'ü'),
            'poznámky se musí useknout na strop',
        );
    }

    /**
     * Allowlist v credentials akci a whitelist v capabilities nesmí dřív nezaležely
     * na sobě divergovat — dnes oba čtou týž zdroj pravdy.
     */
    public function testAnthropicAllowlist_isSingleSourceOfTruth(): void
    {
        self::assertSame(
            LlmProviderCapabilities::ANTHROPIC_MODELS,
            LlmProviderCapabilities::anthropic()->models,
        );
        self::assertContains(
            LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL,
            LlmProviderCapabilities::ANTHROPIC_MODELS,
        );
        self::assertContains(
            LlmProviderCapabilities::OPENAI_DEFAULT_MODEL,
            LlmProviderCapabilities::OPENAI_MODELS,
        );
        self::assertContains(
            LlmProviderCapabilities::GEMINI_DEFAULT_MODEL,
            LlmProviderCapabilities::GEMINI_MODELS,
        );
    }

    // ── Inkrement čítače přesně 1× (per-client counter model, žádné dvojí) ────

    public function testExtractionCounter_incrementsExactlyOnce(): void
    {
        $golden = $this->goldenData();
        $text   = (string) json_encode($golden, JSON_UNESCAPED_UNICODE);

        $history = [];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => $text]]],
                'model'   => 'gpt-4o-mini',
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $updateCount = 0;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$updateCount) {
            $s = $this->createMock(PDOStatement::class);
            if (str_contains($sql, 'extractions_count')) {
                $s->method('execute')->willReturnCallback(function () use (&$updateCount) { $updateCount++; return true; });
            } else {
                $s->method('execute')->willReturn(true);
            }
            if (str_contains($sql, 'openai_api_key_enc')) {
                $s->method('fetch')->willReturn(['openai_api_key_enc' => 'ENC', 'openai_default_model' => 'gpt-4o-mini', 'openai_base_url' => null]);
            } elseif (str_contains($sql, 'company_name')) {
                $s->method('fetch')->willReturn(false); // tenantContext → prázdný blok
            } else {
                $s->method('fetch')->willReturn([]);
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('sk-' . str_repeat('a', 40));

        $client = new OpenAiClient($conn, $crypto, new NullLogger(), $http);
        $result = $client->extractInvoice(1, "%PDF-1.4\nfake");

        self::assertTrue($result['ok'], 'extrakce má uspět');
        self::assertSame($golden, $result['data'], 'data normalizovaná dle golden schema');
        self::assertSame(1, $updateCount, 'čítač se smí inkrementovat právě jednou (žádné dvojí počítání)');
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(16384, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $payload);
        self::assertTrue($payload['response_format']['json_schema']['strict']);
    }

    public function testAzureInvoiceRequest_usesCurrentCompletionTokenParameter(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => (string) json_encode($this->goldenData())]]],
                'model'   => 'gpt-5',
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            if (str_contains($sql, 'azure_openai_api_key_enc')) {
                $stmt->method('fetch')->willReturn([
                    'azure_openai_api_key_enc' => 'ENC',
                    'azure_openai_endpoint' => 'https://example.openai.azure.com',
                    'azure_openai_deployment' => 'gpt-5',
                    'azure_openai_api_version' => '2024-10-21',
                ]);
            } elseif (str_contains($sql, 'company_name')) {
                $stmt->method('fetch')->willReturn(false);
            } else {
                $stmt->method('fetch')->willReturn([]);
            }
            return $stmt;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('synthetic-azure-key');

        $result = (new AzureOpenAiClient($conn, $crypto, new NullLogger(), $http))
            ->extractInvoice(1, "%PDF-1.4\nfake");

        self::assertTrue($result['ok']);
        self::assertCount(1, $history);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(16384, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $payload);
        self::assertTrue($payload['response_format']['json_schema']['strict']);
    }

    // ── Harness ──────────────────────────────────────────────────────────────

    private function makeRouter(string $provider, bool $euRequired, string $declaredRegion): LlmGatewayRouter
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function () {
            $s = $this->createMock(PDOStatement::class);
            $s->method('fetchColumn')->willReturn(1); // information_schema: sloupce existují
            return $s;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($provider, $declaredRegion, $euRequired) {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'ai_eu_residency_required')) {
                $s->method('fetch')->willReturn([
                    'ai_provider'              => $provider,
                    'ai_data_region'           => $declaredRegion,
                    'ai_eu_residency_required' => $euRequired ? 1 : 0,
                ]);
            } else {
                // capabilities SELECTy — vrať us-resolving konfiguraci (žádný EU endpoint/base_url).
                $s->method('fetch')->willReturn(['ai_data_region' => 'us']);
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $this->createMock(SecretEncryption::class);
        $logger = new NullLogger();

        $registry = new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger),
            new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger),
            new GeminiClient($conn, $crypto, $logger),
        );

        return new LlmGatewayRouter($conn, $registry, new ResidencyPolicy(), $logger);
    }
}
