<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Tooling\JmhzOfficialSourceMonitor;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/tools/JmhzOfficialSourceMonitor.php';

final class JmhzOfficialSourceMonitorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/myucto-jmhz-monitor-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->tempDir);
    }

    public function testFirstSuccessfulObservationCreatesBaselineWithoutAlert(): void
    {
        $report = $this->monitor($this->responses('1.4.1.6'))->monitor($this->statePath());

        self::assertTrue($report['baseline_created']);
        self::assertFalse($report['changed']);
        self::assertSame(0, $report['change_count']);
        self::assertFileExists($this->statePath());
    }

    public function testVersionAndHashChangeAreReportedWithOldNewVersionAndOfficialUrl(): void
    {
        $this->monitor($this->responses('1.4.1.6'))->monitor($this->statePath());
        $report = $this->monitor($this->responses('1.4.1.7'))->monitor($this->statePath());

        self::assertTrue($report['changed']);
        self::assertSame(1, $report['change_count']);
        self::assertSame('version_changed', $report['changes'][0]['kind']);
        self::assertSame('1.4.1.6', $report['changes'][0]['old_version']);
        self::assertSame('1.4.1.7', $report['changes'][0]['new_version']);
        self::assertSame('https://developers.mpsv.cz/assets/documents/dictionary-1.4.1.7.xlsx', $report['changes'][0]['url']);
    }

    public function testPageReformatWithoutDocumentChangeDoesNotAlert(): void
    {
        $this->monitor($this->responses('1.4.1.6'))->monitor($this->statePath());
        $responses = $this->responses('1.4.1.6');
        $responses['https://developers.mpsv.cz/index'] = "<html>\n  <body><section>nový layout</section><a href=\"/assets/documents/dictionary-1.4.1.6.xlsx\"> Datový   slovník  JMHZ  1.4.1.6 </a></body></html>";
        $report = $this->monitor($responses)->monitor($this->statePath());

        self::assertFalse($report['changed']);
        self::assertSame([], $report['changes']);
    }

    public function testChangedPresentationFileSizeDoesNotTurnOneDocumentIntoAddedAndRemoved(): void
    {
        $responses = $this->responses('1.4.1.6', 'Pokyny k vyplnění (1,8 MB)');
        $this->monitor($responses)->monitor($this->statePath());
        $report = $this->monitor($this->responses('1.4.1.6', 'Pokyny k vyplnění (1,9 MB)'))->monitor($this->statePath());

        self::assertFalse($report['changed']);
        self::assertSame([], $report['changes']);
    }

    public function testUnlistedOrForeignLinksAreNotTreatedAsOfficialDocuments(): void
    {
        $responses = $this->responses('1.4.1.6');
        $responses['https://developers.mpsv.cz/index'] = '<a href="https://evil.test/new.xlsx">Katalog 9.9</a><a href="/news">Novinka</a><a href="/assets/documents/dictionary-1.4.1.6.xlsx">Datový slovník 1.4.1.6</a>';
        $report = $this->monitor($responses)->monitor($this->statePath(), false);

        self::assertSame(1, $report['sources'][0]['document_count']);
    }

    public function testLiferayDocumentUrlRecognizesExtensionBeforeEntryUuid(): void
    {
        $indexUrl = 'https://eportal.cssz.cz/jmhz';
        $documentUrl = 'https://eportal.cssz.cz/documents/20122/7518542/Pokyny%2BJMHZ.pdf/55555555-5555-5555-5555-555555555555';
        $sources = [
            'cssz' => [
                'label' => 'ČSSZ',
                'index_url' => $indexUrl,
                'document_hosts' => ['eportal.cssz.cz'],
                'document_path_prefixes' => ['/documents/'],
                'document_extensions' => ['pdf'],
            ],
        ];
        $responses = [
            $indexUrl => '<a href="/documents/20122/7518542/Pokyny+JMHZ.pdf/55555555-5555-5555-5555-555555555555?t=123">Pokyny JMHZ 1.4</a>',
            $documentUrl => 'synthetic-cssz-document',
        ];
        $monitor = new JmhzOfficialSourceMonitor($sources, static function (string $url, int $maxBytes) use ($responses): string {
            self::assertArrayHasKey($url, $responses, "Nečekaný síťový požadavek {$url}.");
            self::assertLessThanOrEqual($maxBytes, strlen($responses[$url]));
            return $responses[$url];
        });

        $report = $monitor->monitor($this->statePath());

        self::assertSame(1, $report['sources'][0]['document_count']);
    }

    public function testMpsvApiUsesApprovedDocumentationAndOnlyCurrentlyReferencedAttachments(): void
    {
        $catalogUrl = 'https://developers.mpsv.cz/api/apidata';
        $pageUrl = 'https://developers.mpsv.cz/api/apiversion/11111111-1111-1111-1111-111111111111/documentationPage/22222222-2222-2222-2222-222222222222';
        $documentUrl = 'https://developers.mpsv.cz/assets/documents/33333333-3333-3333-3333-333333333333/current-1.2.3.xlsx';
        $catalog = json_encode([
            'data' => [[
                'slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
                'versions' => [[
                    'version' => '1.4.1',
                    'status' => 'APPROVED',
                    'apiId' => '11111111-1111-1111-1111-111111111111',
                    'documentationPageItems' => [[
                        'title' => 'Dokumentace projektu JMHZ',
                        'apiVersionDocumentationId' => '22222222-2222-2222-2222-222222222222',
                    ]],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $body = json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'mediaInline',
                'attrs' => ['id' => '33333333-3333-3333-3333-333333333333'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $page = json_encode([
            'body' => $body,
            'attachments' => [
                [
                    'mediaId' => '33333333-3333-3333-3333-333333333333',
                    'fileName' => 'current-1.2.3.xlsx',
                    'downloadLink' => $documentUrl,
                ],
                [
                    'mediaId' => '44444444-4444-4444-4444-444444444444',
                    'fileName' => 'historical-0.9.xlsx',
                    'downloadLink' => 'https://developers.mpsv.cz/assets/documents/44444444-4444-4444-4444-444444444444/historical-0.9.xlsx',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $sources = [
            'mpsv' => [
                'label' => 'MPSV',
                'index_url' => $catalogUrl,
                'index_format' => 'mpsv_api',
                'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
                'documentation_title' => 'Dokumentace projektu JMHZ',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/assets/documents/'],
                'document_extensions' => ['xlsx'],
            ],
        ];
        $monitor = new JmhzOfficialSourceMonitor($sources, static function (string $url, int $maxBytes) use ($catalogUrl, $pageUrl, $documentUrl, $catalog, $page): string {
            $responses = [$catalogUrl => $catalog, $pageUrl => $page, $documentUrl => 'synthetic-current-document'];
            self::assertArrayHasKey($url, $responses, "Nečekaný síťový požadavek {$url}.");
            self::assertLessThanOrEqual($maxBytes, strlen($responses[$url]));
            return $responses[$url];
        });

        $report = $monitor->monitor($this->statePath());
        $state = json_decode((string) file_get_contents($this->statePath()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $report['sources'][0]['document_count']);
        self::assertSame($documentUrl, $state['sources']['mpsv']['documents'][0]['url']);
        self::assertSame('1.2.3', $state['sources']['mpsv']['documents'][0]['version']);
    }

    /** @param array<string,string> $responses */
    private function monitor(array $responses): JmhzOfficialSourceMonitor
    {
        return new JmhzOfficialSourceMonitor($this->sources(), static function (string $url, int $maxBytes) use ($responses): string {
            self::assertArrayHasKey($url, $responses, "Nečekaný síťový požadavek {$url}.");
            self::assertLessThanOrEqual($maxBytes, strlen($responses[$url]));
            return $responses[$url];
        });
    }

    /** @return array<string,string> */
    private function responses(string $version, ?string $title = null): array
    {
        $url = 'https://developers.mpsv.cz/assets/documents/dictionary-' . $version . '.xlsx';
        return [
            'https://developers.mpsv.cz/index' => '<html><body><a href="/assets/documents/dictionary-' . $version . '.xlsx">' . ($title ?? 'Datový slovník JMHZ ' . $version) . '</a></body></html>',
            $url => 'synthetic-document-' . $version,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function sources(): array
    {
        return [
            'mpsv' => [
                'label' => 'MPSV',
                'index_url' => 'https://developers.mpsv.cz/index',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/assets/documents/'],
                'document_extensions' => ['xlsx'],
            ],
        ];
    }

    private function statePath(): string
    {
        return $this->tempDir . '/state.json';
    }
}
