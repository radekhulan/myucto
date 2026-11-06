<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use PHPUnit\Framework\TestCase;

/** Binární přílohy (PDF, obrázky) v AiProviderHttpClient::completeJson() pro každého poskytovatele. */
final class AiProviderHttpClientAttachmentsTest extends TestCase
{
    private const PDF = "%PDF-1.4\nsyntetické PDF";

    /** @return list<array{name:string,mime:string,bytes:string}> */
    private static function attachments(): array
    {
        return [
            ['name' => 'zapis.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF],
            ['name' => 'doklad.png', 'mime' => 'image/png', 'bytes' => 'PNGBYTES'],
        ];
    }

    private static function anthropicOk(): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'tool_use', 'input' => ['answer' => 'ok']]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]));
    }

    /** @param array<int, array<string,mixed>> $history @return array<string,mixed> */
    private static function sentJson(array $history, int $i = 0): array
    {
        return json_decode((string) $history[$i]['request']->getBody(), true);
    }

    public function testAnthropicSendsDocumentAndImageBlocksBeforeText(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history);

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 8000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $content = self::sentJson($history)['messages'][0]['content'];
        self::assertSame('document', $content[0]['type']);
        self::assertSame('application/pdf', $content[0]['source']['media_type']);
        self::assertSame(base64_encode(self::PDF), $content[0]['source']['data']);
        self::assertSame('image', $content[1]['type']);
        self::assertSame('image/png', $content[1]['source']['media_type']);
        self::assertSame(['type' => 'text', 'text' => 'DOTAZ'], $content[2]);
        self::assertSame(8000, self::sentJson($history)['max_tokens'], 'strop 4000 už velký výstup neořezává');
        self::assertSame(AiProviderHttpClient::LONG_REQUEST_TIMEOUT, $history[0]['options']['timeout']);
    }

    /** Klíč bez přiřazeného workspace potřebuje hlavičku anthropic-workspace-id i u kontace bankovních pohybů. */
    public function testAnthropicSendsWorkspaceHeaderOnlyWhenConfigured(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history, anthropicWorkspaceId: 'wrkspc_01Test');
        self::assertTrue($client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'])['ok']);
        self::assertSame('wrkspc_01Test', $history[0]['request']->getHeaderLine('anthropic-workspace-id'));

        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history);
        self::assertTrue($client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'])['ok']);
        self::assertFalse($history[0]['request']->hasHeader('anthropic-workspace-id'));
        self::assertSame('test-api-key', $history[0]['request']->getHeaderLine('x-api-key'));
        self::assertSame('2023-06-01', $history[0]['request']->getHeaderLine('anthropic-version'));
    }

    /** Claude 5 vrací na `temperature` HTTP 400 (zjištěno ostrým voláním); starší modely ho dostávají dál. */
    public function testTemperatureIsSentOnlyToModelsThatAcceptIt(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk(), self::anthropicOk()], $history);

        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], 'claude-haiku-4-5', 300);
        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], AiProviderHttpClient::BANK_ESCALATION_MODEL, 300);

        self::assertSame(0, self::sentJson($history, 0)['temperature']);
        self::assertArrayNotHasKey('temperature', self::sentJson($history, 1));
        self::assertTrue(LlmProviderCapabilities::anthropicAcceptsTemperature('claude-3-5-sonnet-20241022'));
        self::assertTrue(LlmProviderCapabilities::anthropicAcceptsTemperature('claude-sonnet-4-5'));
        self::assertFalse(LlmProviderCapabilities::anthropicAcceptsTemperature('claude-opus-5-5'));
    }

    /**
     * Sonnet 5.5 / Opus 5.5 / Fable 5.1 odmítají vynucený tool_choice: i krátká klasifikace
     * u nich jde přes structured outputs, s rezervou tokenů na přemýšlení. Haiku 4.5 beze změny.
     */
    public function testModelsRejectingForcedToolChoiceUseStructuredOutputs(): void
    {
        $history = [];
        $json = new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'x'], ['type' => 'text', 'text' => '{"answer":"ok"}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]));
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [$json, self::anthropicOk()], $history);

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], 'claude-sonnet-5-5', 300);
        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], 'claude-haiku-4-5', 300);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(['answer' => 'ok'], $r['data'], 'JSON se čte z textového bloku, ne z prvního (thinking)');
        $sent = self::sentJson($history, 0);
        self::assertArrayNotHasKey('tool_choice', $sent);
        self::assertSame('json_schema', $sent['output_config']['format']['type']);
        self::assertSame(4000, $sent['max_tokens'], 'rezerva na přemýšlení');
        self::assertSame('low', $sent['output_config']['effort']);
        self::assertSame('posting_suggestion', self::sentJson($history, 1)['tool_choice']['name']);
        self::assertSame(300, self::sentJson($history, 1)['max_tokens']);
    }

    public function testRefusalIsReportedAsError(): void
    {
        $history = [];
        $refusal = new Response(200, [], json_encode(['model' => 'claude-haiku-5-5', 'stop_reason' => 'refusal', 'content' => []]));
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [$refusal], $history);

        self::assertSame(['ok' => false, 'error' => 'refusal'], $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], 'claude-haiku-5-5', 300));
    }

    public function testAnthropicGenerationRules(): void
    {
        self::assertTrue(LlmProviderCapabilities::anthropicSupportsEffort('claude-haiku-5-5'));
        self::assertFalse(LlmProviderCapabilities::anthropicSupportsEffort('claude-haiku-4-5'));
        self::assertTrue(LlmProviderCapabilities::anthropicThinksByDefault('claude-haiku-5-5'));
        self::assertTrue(LlmProviderCapabilities::anthropicThinksByDefault('claude-sonnet-5'));
        self::assertFalse(LlmProviderCapabilities::anthropicThinksByDefault('claude-opus-4-8'));
        self::assertFalse(LlmProviderCapabilities::anthropicThinksByDefault('claude-haiku-4-5-20251001'));
        foreach (['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1'] as $m) {
            self::assertTrue(LlmProviderCapabilities::anthropicRejectsForcedToolChoice($m), $m);
        }
        foreach (['claude-haiku-5-5', 'claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5', 'claude-fable-5', 'claude-opus-4-8'] as $m) {
            self::assertFalse(LlmProviderCapabilities::anthropicRejectsForcedToolChoice($m), $m);
        }
        self::assertSame(['output_config' => ['effort' => 'low']], LlmProviderCapabilities::anthropic()->effortPayload('claude-haiku-5-5', 'fast'));
        self::assertSame([], LlmProviderCapabilities::anthropic()->effortPayload('claude-haiku-4-5', 'fast'));
        self::assertContains(LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL, LlmProviderCapabilities::ANTHROPIC_MODELS);
    }

    /** Stávající volající (bez příloh) posílají požadavek beze změny. */
    public function testWithoutAttachmentsRequestIsUnchanged(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history);

        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 300);

        $sent = self::sentJson($history);
        self::assertSame('DOTAZ', $sent['messages'][0]['content']);
        self::assertSame(300, $sent['max_tokens']);
        self::assertArrayNotHasKey('timeout', $history[0]['options']);
    }

    public function testOpenAiSendsFileAndImageUrlParts(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'openai', [
            new Response(200, [], json_encode([
                'model' => 'gpt-test',
                'choices' => [['message' => ['content' => '{"answer":"ok"}']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
            ])),
        ], $history, 'gpt-test');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(['answer' => 'ok'], $r['data']);
        $content = self::sentJson($history)['messages'][1]['content'];
        self::assertSame('file', $content[0]['type']);
        self::assertSame('zapis.pdf', $content[0]['file']['filename']);
        self::assertSame('data:application/pdf;base64,' . base64_encode(self::PDF), $content[0]['file']['file_data']);
        self::assertSame('image_url', $content[1]['type']);
        self::assertSame('data:image/png;base64,' . base64_encode('PNGBYTES'), $content[1]['image_url']['url']);
        self::assertSame(['type' => 'text', 'text' => 'DOTAZ'], $content[2]);
    }

    public function testGeminiSendsInlineDataParts(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'gemini', [
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '{"answer":"ok"}']]]]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1],
            ])),
        ], $history, 'gemini-2.5-flash');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $parts = self::sentJson($history)['contents'][0]['parts'];
        self::assertSame(['mime_type' => 'application/pdf', 'data' => base64_encode(self::PDF)], $parts[0]['inline_data']);
        self::assertSame('image/png', $parts[1]['inline_data']['mime_type']);
        self::assertSame(['text' => 'DOTAZ'], $parts[2]);
    }

    public function testOllamaRejectsBinaryAttachmentsWithoutCallingProvider(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'ollama', [], $history, 'llama3');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertSame(['ok' => false, 'error' => 'attachments_unsupported'], $r);
        self::assertSame([], $history);
    }

    public function testStatusUsesStrongerModelForHaikuAndReportsMissingDpa(): void
    {
        $status = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [])->status(1);
        self::assertSame([
            'available' => true, 'reason' => null, 'provider' => 'anthropic',
            'model' => AiProviderHttpClient::BANK_ESCALATION_MODEL, 'supports_files' => true,
        ], $status);

        $history = [];
        $noDpa = FakeAiProviderHttpClientFactory::create($this, 'openai', [], $history, 'gpt-test', false)->status(1);
        self::assertFalse($noDpa['available']);
        self::assertSame('dpa_not_confirmed', $noDpa['reason']);
        self::assertSame('gpt-test', $noDpa['model']);
    }
}
