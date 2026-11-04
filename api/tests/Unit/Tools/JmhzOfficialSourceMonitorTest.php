<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Tooling\JmhzOfficialSourceMonitor;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/tools/JmhzOfficialSourceMonitor.php';

final class JmhzOfficialSourceMonitorTest extends TestCase
{
    private const PAGES_API_ID = '11111111-1111-1111-1111-111111111111';
    private const NEWS_PAGE_ID = '55555555-5555-5555-5555-555555555555';
    private const CONTROLS_PAGE_ID = '66666666-6666-6666-6666-666666666666';

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

    /**
     * MPSV v katalogu přejmenovalo pole se stránkami dokumentace
     * (`documentationPageItems` → `documentationPages`). Obsah zůstal, hlídač ale
     * od 9. 9. 2026 hlásil, že dokumentace v katalogu není, a deset dní nikdo nevěděl,
     * že se na MPSV něco mění. Čteme obě jména.
     */
    public function testMpsvApiAcceptsRenamedCatalogShape(): void
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
                    'documentationPages' => [[
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
        // Druhé přejmenování téhož katalogu: tělo i přílohy se přestěhovaly pod `payload`.
        $page = json_encode([
            'payload' => [
                'body' => $body,
                'attachments' => [[
                    'mediaId' => '33333333-3333-3333-3333-333333333333',
                    'fileName' => 'current-1.2.3.xlsx',
                    'downloadLink' => $documentUrl,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        $monitor = new JmhzOfficialSourceMonitor(
            ['mpsv' => [
                'label' => 'MPSV',
                'index_url' => $catalogUrl,
                'index_format' => 'mpsv_api',
                'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
                'documentation_title' => 'Dokumentace projektu JMHZ',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/assets/documents/'],
                'document_extensions' => ['xlsx'],
            ]],
            fn (string $url): string => match ($url) {
                $catalogUrl => $catalog,
                $pageUrl => $page,
                $documentUrl => 'obsah dokumentu',
                default => throw new \RuntimeException('Neočekávaná adresa ' . $url),
            },
        );

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

    /**
     * Aktuality ČSSZ jsou seznam ČLÁNKŮ, ne dokumentů.
     *
     * Právě tam chodí vady katalogu kontrol, výpadky a nové povinnosti —
     * 28. 8. 2026 takhle přišla vada ve vyhodnocování kontrol 164, 270, 290,
     * 291 a 333. Články nemají příponu, takže je filtr dokumentů odmítal
     * a celý běh skončil chybou „neobsahuje žádný rozpoznatelný dokument".
     */
    public function testArticleIndexDetectsANewAnnouncement(): void
    {
        $index = static fn (string ...$titles): string => '<html><body>'
            . implode('', array_map(
                static fn (string $t, int $i): string =>
                    '<a href="/web/cz/-/clanek-' . $i . '">' . $t . '</a>',
                $titles,
                array_keys($titles),
            ))
            . '<a href="https://example.test/cizi">Cizí odkaz</a></body></html>';

        $monitor = new JmhzOfficialSourceMonitor(
            $this->articleSources(),
            static fn (string $url, int $maxBytes): string => $index('Aktualizace JMHZ'),
        );
        $first = $monitor->monitor($this->statePath());
        self::assertTrue($first['baseline_created']);
        self::assertSame(1, $first['sources'][0]['document_count']);

        // ⚠️ Kdyby se stahoval každý článek zvlášť, tenhle test by síťově
        // selhal — a v provozu by se hlásila změna při každé úpravě patičky.
        $monitor = new JmhzOfficialSourceMonitor(
            $this->articleSources(),
            static fn (string $url, int $maxBytes): string =>
                $index('Aktualizace JMHZ', 'Upozornění pro zaměstnavatele — přepočet stavu JMH'),
        );
        $second = $monitor->monitor($this->statePath());

        self::assertTrue($second['changed']);
        self::assertSame(1, $second['change_count']);
        self::assertStringContainsString(
            'přepočet stavu JMH',
            json_encode($second['changes'], JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /**
     * Verze písemností EPO nehlídalo do 29. 8. 2026 nic.
     *
     * ⚠️ Nová verze mění `verzePis` v obálce. Podání se starou verzí projde NAŠÍ
     * validací proti starému XSD a odmítne ho až podatelna — chyba se tedy
     * projeví až u ostrého podání na straně úřadu.
     *
     * Řádky nejsou odkazy: tabulka naviguje přes `onclick`, a `resolveUrl()`
     * navíc zahazuje query string, takže zkratku je nutné číst ze surové adresy.
     */
    public function testEpoStructureVersionBumpIsReported(): void
    {
        $page = static fn (string $version): string => '<html><body><table><tbody>'
            . '<tr onclick="location.href=\'https://adisspr.mfcr.cz:443/dpr/adis/'
            . 'idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPZVD6\'">'
            . '<td>DPZVD6</td><td>Vyúčtování daně ze závislé činnosti</td>'
            . '<td>' . $version . '</td><td>10.1.2024</td></tr>'
            . '</tbody></table></body></html>';

        $monitor = new JmhzOfficialSourceMonitor(
            $this->epoSources(),
            static fn (string $url, int $maxBytes): string => $page('09.03.01'),
        );
        $first = $monitor->monitor($this->statePath());
        self::assertTrue($first['baseline_created']);
        self::assertSame(1, $first['sources'][0]['document_count']);

        $monitor = new JmhzOfficialSourceMonitor(
            $this->epoSources(),
            static fn (string $url, int $maxBytes): string => $page('09.04.01'),
        );
        $second = $monitor->monitor($this->statePath());

        self::assertTrue($second['changed']);
        $change = $second['changes'][0];
        self::assertSame('DPZVD6', $change['document_key']);
        self::assertSame('09.03.01', $change['old_version']);
        self::assertSame('09.04.01', $change['new_version']);
    }

    public function testMpsvPagesBaselineAndRepeatedObservationDoNotAlertNorDownloadAttachments(): void
    {
        $first = $this->monitorWith($this->pagesSources(), $this->pagesResponses(attachments: ['JMHZ pruvodce.pdf']))->monitor($this->statePath());
        $state = json_decode((string) file_get_contents($this->statePath()), true, 512, JSON_THROW_ON_ERROR);
        $keys = array_column($state['sources']['mpsv-pages']['documents'], 'key');

        self::assertTrue($first['baseline_created']);
        self::assertFalse($first['changed']);
        self::assertContains('stranka:aktuality', $keys);
        self::assertContains('stranka:katalog-kontrol-mh', $keys);
        self::assertContains('priloha:aktuality:jmhz-pruvodce-pdf', $keys);
        self::assertContains('zaznam:katalog-kontrol-mh:100', $keys);
        self::assertContains('aktualita:aktuality:2026-09-17:finanční-správa-upravila-syntetickou-nov', $keys);
        self::assertContains('aktualita:aktuality:2026-09-11:plánovaná-technická-údržba-dis-od-20-00', $keys);
        self::assertNotContains('priloha:aktuality:historicka-priloha-xlsx', $keys);
        self::assertSame(2, count(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'aktualita:'))));

        // Jiný zápis téhož okamžiku (MPSV vypouští koncové nuly zlomku sekundy)
        // není změnou stránky.
        $second = $this->monitorWith(
            $this->pagesSources(),
            $this->pagesResponses(updatedDate: '2026-09-25T13:23:12.2955+02:00', attachments: ['JMHZ pruvodce.pdf']),
        )->monitor($this->statePath());

        self::assertFalse($second['baseline_created']);
        self::assertSame([], $second['changes']);
    }

    public function testMpsvPagesNewDatedAnnouncementIsReportedAsAdded(): void
    {
        $this->monitorWith($this->pagesSources(), $this->pagesResponses())->monitor($this->statePath());
        $news = [
            ['13. 8. 2026', 'Oprava kontroly 99999 v systému DIS.', '13. 8. byla nasazena oprava kombinace atributů.'],
            ...$this->defaultNews(),
        ];
        $report = $this->monitorWith($this->pagesSources(), $this->pagesResponses(news: $news))->monitor($this->statePath());

        $newsChanges = $this->changesWithPrefix($report, 'aktualita:');
        self::assertCount(1, $newsChanges);
        self::assertSame('added', $newsChanges[0]['kind']);
        self::assertStringStartsWith('aktualita:aktuality:2026-08-13:oprava-kontroly-99999', $newsChanges[0]['document_key']);
        self::assertSame('13.8.2026 Oprava kontroly 99999 v systému DIS. 13. 8. byla nasazena oprava kombinace atributů.', $newsChanges[0]['title']);
        self::assertSame('https://developers.mpsv.cz/api-list/jednotne-mesicni-hlaseni-zamestnavatelu/documentation/' . self::NEWS_PAGE_ID, $newsChanges[0]['url']);
        self::assertSame('mpsv-pages', $newsChanges[0]['source_id']);
        self::assertSame(['kind', 'source_id', 'document_key', 'title', 'url', 'old_version', 'new_version', 'old_sha256', 'new_sha256'], array_keys($newsChanges[0]));
    }

    public function testMpsvPagesCorrectedAnnouncementTextIsContentChangeNotNewAnnouncement(): void
    {
        $this->monitorWith($this->pagesSources(), $this->pagesResponses())->monitor($this->statePath());
        $news = $this->defaultNews();
        $news[0][] = 'Doplněno upozornění ze strany ČSSZ.';
        $report = $this->monitorWith($this->pagesSources(), $this->pagesResponses(news: $news))->monitor($this->statePath());

        $newsChanges = $this->changesWithPrefix($report, 'aktualita:');
        self::assertCount(1, $newsChanges);
        self::assertSame('content_changed', $newsChanges[0]['kind']);
        self::assertSame('aktualita:aktuality:2026-09-17:finanční-správa-upravila-syntetickou-nov', $newsChanges[0]['document_key']);
        self::assertNotSame($newsChanges[0]['old_sha256'], $newsChanges[0]['new_sha256']);
    }

    public function testMpsvPagesUpdatedDateChangeIsReportedAsPageVersionChange(): void
    {
        $this->monitorWith($this->pagesSources(), $this->pagesResponses())->monitor($this->statePath());
        $report = $this->monitorWith(
            $this->pagesSources(),
            $this->pagesResponses(updatedDate: '2026-09-26T08:15:00.5+02:00'),
        )->monitor($this->statePath());

        self::assertSame(1, $report['change_count']);
        $change = $report['changes'][0];
        self::assertSame('version_changed', $change['kind']);
        self::assertSame('stranka:aktuality', $change['document_key']);
        self::assertSame('Aktuality', $change['title']);
        self::assertSame('2026-09-25T13:23:12+02:00', $change['old_version']);
        self::assertSame('2026-09-26T08:15:00+02:00', $change['new_version']);
    }

    public function testMpsvPagesNewAttachmentIsReportedAsAddedWithoutDownloadingIt(): void
    {
        $this->monitorWith($this->pagesSources(), $this->pagesResponses(attachments: ['JMHZ pruvodce.pdf']))->monitor($this->statePath());
        $report = $this->monitorWith(
            $this->pagesSources(),
            $this->pagesResponses(attachments: ['JMHZ pruvodce.pdf', 'Aktualizace DIS 1.4.3.pdf']),
        )->monitor($this->statePath());

        self::assertSame(1, $report['change_count']);
        $change = $report['changes'][0];
        self::assertSame('added', $change['kind']);
        self::assertSame('priloha:aktuality:aktualizace-dis-pdf', $change['document_key']);
        self::assertSame('Aktualizace DIS 1.4.3.pdf', $change['title']);
        self::assertSame('1.4.3', $change['new_version']);
        self::assertSame($this->attachmentUrl('Aktualizace DIS 1.4.3.pdf'), $change['url']);
    }

    public function testMpsvPagesCatalogEntryChangeAndNewEntryAreReported(): void
    {
        $this->monitorWith($this->pagesSources(), $this->pagesResponses())->monitor($this->statePath());
        $controls = [
            ['id' => '100', 'name' => 'Syntetická kontrola rodného čísla', 'error_message' => 'Chybné rodné číslo (upraveno).'],
            ['id' => '101', 'name' => 'Nová syntetická kontrola', 'error_message' => 'Chybí údaj.'],
        ];
        $report = $this->monitorWith($this->pagesSources(), $this->pagesResponses(controls: $controls))->monitor($this->statePath());

        self::assertSame(
            [
                ['content_changed', 'zaznam:katalog-kontrol-mh:100'],
                ['added', 'zaznam:katalog-kontrol-mh:101'],
            ],
            array_map(static fn (array $change): array => [$change['kind'], $change['document_key']], $report['changes']),
        );
        self::assertSame('Katalog kontrol MH: 101 Nová syntetická kontrola', $report['changes'][1]['title']);
    }

    /**
     * Zdroj přidaný do konfigurace po prvním běhu nesmí první den ohlásit
     * každou svou položku jako přidanou. Stávající zdroje se přitom hlídají dál.
     */
    public function testSourceMissingInPreviousStateIsBaselinedWithoutAlerts(): void
    {
        $this->monitor($this->responses('1.4.1.6'))->monitor($this->statePath());
        $report = $this->monitorWith(
            [...$this->sources(), ...$this->pagesSources()],
            [...$this->responses('1.4.1.7'), ...$this->pagesResponses()],
        )->monitor($this->statePath());

        self::assertFalse($report['baseline_created']);
        self::assertSame(['mpsv-pages'], $report['baseline_sources']);
        self::assertSame(1, $report['change_count']);
        self::assertSame('mpsv', $report['changes'][0]['source_id']);
        self::assertSame('version_changed', $report['changes'][0]['kind']);

        $third = $this->monitorWith(
            [...$this->sources(), ...$this->pagesSources()],
            [...$this->responses('1.4.1.7'), ...$this->pagesResponses(updatedDate: '2026-09-26T08:15:00+02:00')],
        )->monitor($this->statePath());

        self::assertSame([], $third['baseline_sources']);
        self::assertSame(1, $third['change_count']);
        self::assertSame('stranka:aktuality', $third['changes'][0]['document_key']);
    }

    /**
     * Hlavní dokumentace MPSV se dál sleduje stávajícím zdrojem se stejnými
     * klíči dokumentů; změna jeho identity by v ostrém stavu vyrobila falešné
     * „odebráno/přidáno" u všech dokumentů.
     */
    public function testProductionConfigurationKeepsExistingMpsvSourceAndWatchesNews(): void
    {
        $sources = require dirname(__DIR__, 4) . '/tools/jmhz-official-source-monitor-sources.php';

        self::assertSame('mpsv_api', $sources['mpsv-jmhz-documentation']['index_format']);
        self::assertSame('Dokumentace projektu JMHZ', $sources['mpsv-jmhz-documentation']['documentation_title']);
        self::assertSame('mpsv_api_pages', $sources['mpsv-jmhz-pages']['index_format']);
        self::assertSame('*', $sources['mpsv-jmhz-pages']['documentation_titles']);
        self::assertContains('Aktuality', $sources['mpsv-jmhz-pages']['news_titles']);
        new JmhzOfficialSourceMonitor($sources, static fn (): string => throw new \LogicException('Bez sítě.'));
    }

    /**
     * Definice e-Podání ČSSZ (NEMPRI, HZUPN, ELDP, REGZEC) se sledují jako
     * odkazy: dokumenty se nestahují a nová verze vystavená jako nový soubor
     * se ohlásí jako přibylý dokument. Duplicitní název (aktuální a archivní
     * verze) nesmí hlídač shodit.
     */
    public function testEpodaniDefinitionPageReportsNewDataSentenceDefinitionWithoutDownloading(): void
    {
        $index = 'https://www.cssz.gov.cz/web/cz/definice-e-podani-nempri';
        $page = static fn (string $extra): string => '<html><body>'
            . '<a href="/documents/20143/2739697/NEMPRI25.xsd/ccb22dda-af2d-8752-1ba2-7b6742052fc5">NEMPRI25.xsd</a>'
            . '<a href="/documents/20143/2739697/DV_NEMPRI25_v1_20240324.pdf/28bc0032-1f2b-f2c7-35df-2177e9e380e3">Definice datové věty</a>'
            . '<a href="https://eportal.cssz.cz/documents/20122/35802/NEMPRI_2020.pdf/ad9969a7-8848-2a93-d841-7e480dc30608?t=1">Definice datové věty</a>'
            . '<a href="https://example.test/documents/1/2/cizi.pdf/ad9969a7-8848-2a93-d841-7e480dc30608">Cizí</a>'
            . $extra
            . '</body></html>';
        $sources = [
            'cssz-definice-nempri' => [
                'label' => 'ČSSZ — Definice e-Podání NEMPRI',
                'index_url' => $index,
                'index_format' => 'document_links',
                'document_hosts' => ['www.cssz.gov.cz', 'eportal.cssz.cz'],
                'document_path_prefixes' => ['/documents/'],
                'document_extensions' => ['pdf', 'xsd', 'zip'],
            ],
        ];

        $baseline = $this->monitorWith($sources, [$index => $page('')])->monitor($this->statePath());
        self::assertTrue($baseline['baseline_created']);
        self::assertSame(3, $baseline['sources'][0]['document_count']);

        $report = $this->monitorWith($sources, [
            $index => $page('<a href="/documents/20143/3273152/DV_NEMPRI25_v1_20260309.pdf/c2f30a2b-aa02-30b1-3fe0-a9ee439deece">Stáhnout</a>'),
        ])->monitor($this->statePath());

        self::assertSame(1, $report['change_count']);
        self::assertSame('added', $report['changes'][0]['kind']);
        self::assertSame(
            'https://www.cssz.gov.cz/documents/20143/3273152/DV_NEMPRI25_v1_20260309.pdf/c2f30a2b-aa02-30b1-3fe0-a9ee439deece',
            $report['changes'][0]['url'],
        );
        self::assertSame('DV_NEMPRI25_v1_20260309.pdf', $report['changes'][0]['title']);
    }

    /** NEMPRI, HZUPN, ELDP a REGZEC jsou v ostré konfiguraci hlídače. */
    public function testProductionConfigurationWatchesCsszEpodaniDefinitions(): void
    {
        $sources = require dirname(__DIR__, 4) . '/tools/jmhz-official-source-monitor-sources.php';

        foreach ([
            'cssz-definice-nempri' => 'definice-e-podani-nempri',
            'cssz-definice-hzupn' => 'definice-e-podani-hzupn',
            'cssz-definice-eldp' => 'definice-e-podani-eldp',
            'cssz-definice-regzec' => 'pro-vyvojare-jmhz',
        ] as $id => $slug) {
            self::assertArrayHasKey($id, $sources);
            self::assertSame('document_links', $sources[$id]['index_format']);
            self::assertStringEndsWith('/' . $slug, $sources[$id]['index_url']);
        }
        new JmhzOfficialSourceMonitor($sources, static fn (): string => throw new \LogicException('Bez sítě.'));
    }

    /**
     * Index `https://developers.mpsv.cz/jmhz/2027/` odkazuje relativně
     * (`mh/…`, `pozadavky/…`). Základní cesta končí lomítkem, takže je to
     * adresář; dřív se z ní ukrojil poslední segment (`/jmhz/…`) a žádný
     * odkaz neprošel prefixem, hlídač hlásil "neobsahuje žádný dokument".
     */
    public function testRelativeLinksResolveAgainstDirectoryIndex(): void
    {
        $index = 'https://developers.mpsv.cz/jmhz/2027/';
        $sources = [
            'site' => [
                'label' => 'Přehled 2027',
                'index_url' => $index,
                'index_format' => 'document_links',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/jmhz/2027/'],
                'document_extensions' => ['html'],
            ],
        ];
        $html = '<html><body>'
            . '<a href="mh/mzda.html">Mzda</a>'
            . '<a href="./kapitoly.html">Kapitoly</a>'
            . '<a href="pozadavky/../souhrn.html#in-IN03">Souhrn</a>'
            . '<a href="mh/mzda.html#in-IN16">Mzda znovu</a>'
            . '<a href="#s1">Oddíl</a>'
            . '<a href="../2026/stary.html">Rok 2026</a>'
            . '<a href="%2e%2e/x.html">Zakódovaný únik</a>'
            . '<a href="/jmhz/2027/regzec/edv.html">Absolutní</a>'
            . '</body></html>';

        $report = $this->monitorWith($sources, [$index => $html])->monitor($this->statePath());
        $state = json_decode((string) file_get_contents($this->statePath()), true);
        $urls = array_column($state['sources']['site']['documents'], 'url');
        sort($urls);

        self::assertSame(
            [
                'https://developers.mpsv.cz/jmhz/2027/kapitoly.html',
                'https://developers.mpsv.cz/jmhz/2027/mh/mzda.html',
                'https://developers.mpsv.cz/jmhz/2027/regzec/edv.html',
                'https://developers.mpsv.cz/jmhz/2027/souhrn.html',
            ],
            $urls,
        );
        self::assertSame(4, $report['sources'][0]['document_count']);
    }

    /**
     * Navigace stránky 2027 odkazuje na `index.html`, který portál
     * přesměrovává na `…/`. Je to týž index, ne další stránka ke stažení.
     */
    public function testSitePagesSkipIndexHtmlOfTheSameDirectory(): void
    {
        $index = 'https://developers.mpsv.cz/jmhz/2027/';
        $sources = [
            'site' => [
                'label' => 'Přehled 2027',
                'index_url' => $index,
                'index_format' => 'site_pages',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/jmhz/2027/'],
                'document_extensions' => ['html'],
            ],
        ];
        $responses = [
            $index => '<a href="index.html">Úvod</a><a href="mh/mzda.html">Mzda</a>',
            $index . 'mh/mzda.html' => 'mzda',
        ];

        $report = $this->monitorWith($sources, $responses)->monitor($this->statePath());

        self::assertSame(2, $report['sources'][0]['document_count']);
    }

    public function testRelativeLinksResolveAgainstFileIndex(): void
    {
        $index = 'https://developers.mpsv.cz/jmhz/2027/index.html';
        $sources = [
            'site' => [
                'label' => 'Přehled 2027',
                'index_url' => $index,
                'index_format' => 'document_links',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/jmhz/2027/'],
                'document_extensions' => ['html'],
            ],
        ];

        $this->monitorWith($sources, [$index => '<a href="mh/mzda.html">Mzda</a><a href="../2027/regzec/a.html">A</a>'])
            ->monitor($this->statePath());
        $state = json_decode((string) file_get_contents($this->statePath()), true);
        $urls = array_column($state['sources']['site']['documents'], 'url');
        sort($urls);

        self::assertSame(
            [
                'https://developers.mpsv.cz/jmhz/2027/mh/mzda.html',
                'https://developers.mpsv.cz/jmhz/2027/regzec/a.html',
            ],
            $urls,
        );
    }

    /**
     * Statický přehled 2027: klíčem je cesta stránky, otisk se bere z jejího
     * obsahu. Tichá úprava karty změnového požadavku (stejný odkaz, stejný
     * název) se musí ohlásit a nová karta také.
     */
    public function testSitePagesReportEditedAndAddedPagesButNotRepeatedLinks(): void
    {
        $index = 'https://developers.mpsv.cz/jmhz/2027/';
        $sources = [
            'site' => [
                'label' => 'Přehled 2027',
                'index_url' => $index,
                'index_format' => 'site_pages',
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/jmhz/2027/'],
                'document_extensions' => ['html'],
            ],
        ];
        $pages = static fn (string $extraLink = ''): string => '<html><body>'
            . '<a href="mh/mzda.html">Mzda</a><a href="regzec/mzda.html">Mzda</a>'
            . '<a href="mh/mzda.html#in-IN03">Mzda</a>'
            . '<a href="pozadavky/zp_126_platnost_pobytu.html">ZP 126</a>' . $extraLink
            . '</body></html>';
        $base = 'https://developers.mpsv.cz/jmhz/2027/';
        $responses = [
            $index => $pages(),
            $base . 'mh/mzda.html' => 'mzda v1',
            $base . 'regzec/mzda.html' => 'regzec mzda',
            $base . 'pozadavky/zp_126_platnost_pobytu.html' => 'ZP 126 v1',
        ];

        $baseline = $this->monitorWith($sources, $responses)->monitor($this->statePath());
        self::assertTrue($baseline['baseline_created']);
        self::assertSame(4, $baseline['sources'][0]['document_count']);

        $same = $this->monitorWith($sources, $responses)->monitor($this->statePath());
        self::assertFalse($same['changed']);

        $responses['https://developers.mpsv.cz/jmhz/2027/pozadavky/zp_126_platnost_pobytu.html'] = 'ZP 126 v2';
        $responses[$index] = $pages('<a href="pozadavky/zp_999_nova.html">Nová</a>');
        $responses[$base . 'pozadavky/zp_999_nova.html'] = 'nová karta';
        $report = $this->monitorWith($sources, $responses)->monitor($this->statePath());

        self::assertSame(
            [
                'content_changed:index',
                'content_changed:pozadavky/zp_126_platnost_pobytu.html',
                'added:pozadavky/zp_999_nova.html',
            ],
            array_map(
                static fn (array $change): string => $change['kind'] . ':' . $change['document_key'],
                $report['changes'],
            ),
        );
    }

    public function testProductionConfigurationWatchesMpsvDocumentationAndSite2027(): void
    {
        $sources = require dirname(__DIR__, 4) . '/tools/jmhz-official-source-monitor-sources.php';

        self::assertSame('mpsv_api', $sources['mpsv-jmhz-2027-documentation']['index_format']);
        self::assertSame(
            'Dokumentace projektu JMHZ - NV2027',
            $sources['mpsv-jmhz-2027-documentation']['documentation_title'],
        );
        self::assertSame('site_pages', $sources['mpsv-jmhz-2027-site']['index_format']);
        self::assertSame('https://developers.mpsv.cz/jmhz/2027/', $sources['mpsv-jmhz-2027-site']['index_url']);
        self::assertSame(['/jmhz/2027/'], $sources['mpsv-jmhz-2027-site']['document_path_prefixes']);
        // Stávající zdroj 2026 se nesmí změnit, jinak by se klíče jeho dokumentů ve stavu přeznačily.
        self::assertSame('Dokumentace projektu JMHZ', $sources['mpsv-jmhz-documentation']['documentation_title']);
        new JmhzOfficialSourceMonitor($sources, static fn (): string => throw new \LogicException('Bez sítě.'));
    }

    public function testMpsvPagesRejectNewsOnUnwatchedPage(): void
    {
        $sources = $this->pagesSources();
        $sources['mpsv-pages']['documentation_titles'] = ['Katalog kontrol MH'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hlídá aktuality na stránce, kterou nesleduje');
        new JmhzOfficialSourceMonitor($sources, static fn (): string => '');
    }

    /** @return array<string,array<string,mixed>> */
    private function pagesSources(): array
    {
        return [
            'mpsv-pages' => [
                'label' => 'MPSV stránky',
                'index_url' => 'https://developers.mpsv.cz/api/apidata',
                'index_format' => 'mpsv_api_pages',
                'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
                'documentation_titles' => '*',
                'news_titles' => ['Aktuality'],
                'document_hosts' => ['developers.mpsv.cz'],
                'document_path_prefixes' => ['/assets/documents/'],
                'document_extensions' => ['pdf', 'xlsx'],
            ],
        ];
    }

    /** @return list<list<string>> */
    private function defaultNews(): array
    {
        return [
            ['17.9.2026', 'Finanční správa upravila syntetickou novinku k tématu svědečného.'],
            ['11. 9. 2026', "Plánovaná technická |údržba| DIS\nod 20:00 do 22:00."],
        ];
    }

    /**
     * Stránka aktualit v podobě Atlassian ADF jako na portálu: datum ve vlastním
     * tučném odstavci, text v dalších odstavcích. `|` dělí text do více uzlů
     * (formátování), `\n` je hardBreak.
     *
     * @param list<list<string>>|null $news
     * @param list<string> $attachments
     * @param list<array<string,string>>|null $controls
     * @return array<string,string>
     */
    private function pagesResponses(?array $news = null, string $updatedDate = '2026-09-25T13:23:12.295503+02:00', array $attachments = [], ?array $controls = null): array
    {
        $paragraph = static function (string $text, bool $strong = false): array {
            $content = [];
            foreach (explode("\n", $text) as $i => $line) {
                if ($i > 0) {
                    $content[] = ['type' => 'hardBreak'];
                }
                foreach (explode('|', $line) as $part) {
                    $node = ['type' => 'text', 'text' => $part];
                    if ($strong) {
                        $node['marks'] = [['type' => 'strong']];
                    }
                    $content[] = $node;
                }
            }
            return ['type' => 'paragraph', 'attrs' => ['localId' => 'x'], 'content' => $content];
        };
        $blocks = [$paragraph('Aktuality k JMHZ')];
        foreach ($news ?? $this->defaultNews() as $item) {
            foreach ($item as $i => $text) {
                $blocks[] = $paragraph($text, $i === 0);
            }
            $blocks[] = ['type' => 'paragraph', 'attrs' => ['localId' => 'y']];
        }
        $attachmentList = [[
            'mediaId' => '99999999-9999-9999-9999-999999999999',
            'fileName' => 'historicka-priloha.xlsx',
            'downloadLink' => $this->attachmentUrl('historicka-priloha.xlsx'),
        ]];
        foreach ($attachments as $i => $fileName) {
            $mediaId = sprintf('7777777%d-7777-7777-7777-777777777777', $i);
            $blocks[] = ['type' => 'paragraph', 'content' => [['type' => 'mediaInline', 'attrs' => ['id' => $mediaId, 'type' => 'file']]]];
            $attachmentList[] = ['mediaId' => $mediaId, 'fileName' => $fileName, 'downloadLink' => $this->attachmentUrl($fileName)];
        }
        $newsPage = json_encode([
            'type' => 'CONFLUENCE',
            'updatedDate' => $updatedDate,
            'title' => 'Aktuality',
            'payload' => [
                'body' => json_encode(['type' => 'doc', 'version' => 1, 'content' => $blocks], JSON_THROW_ON_ERROR),
                'attachments' => $attachmentList,
            ],
        ], JSON_THROW_ON_ERROR);
        $controlsPage = json_encode([
            'type' => 'CARD',
            'updatedDate' => '2026-09-09T13:07:38.628061+02:00',
            'title' => 'Katalog kontrol MH',
            'entries' => array_map(static fn (array $values): array => [
                'content' => 'documentationEntry',
                'values' => $values,
                'updatedDate' => '2026-09-09T13:07:38.628061+02:00',
            ], $controls ?? [['id' => '100', 'name' => 'Syntetická kontrola rodného čísla', 'error_message' => 'Chybné rodné číslo.']]),
        ], JSON_THROW_ON_ERROR);
        $catalog = json_encode([
            'data' => [[
                'slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
                'versions' => [
                    [
                        'version' => '1.3.0',
                        'status' => 'APPROVED',
                        'apiId' => '22222222-2222-2222-2222-222222222222',
                        'documentationPages' => [['title' => 'Aktuality', 'visibility' => 'PUBLIC', 'apiVersionDocumentationId' => '88888888-8888-8888-8888-888888888888']],
                    ],
                    [
                        'version' => '1.4.1',
                        'status' => 'APPROVED',
                        'apiId' => self::PAGES_API_ID,
                        'documentationPages' => [
                            ['title' => 'Aktuality', 'visibility' => 'PUBLIC', 'apiVersionDocumentationId' => self::NEWS_PAGE_ID],
                            ['title' => 'Katalog kontrol MH', 'visibility' => 'PUBLIC', 'apiVersionDocumentationId' => self::CONTROLS_PAGE_ID],
                            ['title' => 'Interní poznámky', 'visibility' => 'PRIVATE', 'apiVersionDocumentationId' => '44444444-4444-4444-4444-444444444444'],
                        ],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $pageUrl = static fn (string $pageId): string => 'https://developers.mpsv.cz/api/apiversion/' . self::PAGES_API_ID . '/documentationPage/' . $pageId;

        return [
            'https://developers.mpsv.cz/api/apidata' => $catalog,
            $pageUrl(self::NEWS_PAGE_ID) => $newsPage,
            $pageUrl(self::CONTROLS_PAGE_ID) => $controlsPage,
        ];
    }

    private function attachmentUrl(string $fileName): string
    {
        return 'https://developers.mpsv.cz/assets/documents/77777777-0000-0000-0000-000000000000/' . rawurlencode($fileName);
    }

    /**
     * @param array<string,mixed> $report
     * @return list<array<string,mixed>>
     */
    private function changesWithPrefix(array $report, string $prefix): array
    {
        return array_values(array_filter(
            $report['changes'],
            static fn (array $change): bool => str_starts_with($change['document_key'], $prefix),
        ));
    }

    /**
     * @param array<string,array<string,mixed>> $sources
     * @param array<string,string> $responses
     */
    private function monitorWith(array $sources, array $responses): JmhzOfficialSourceMonitor
    {
        return new JmhzOfficialSourceMonitor($sources, static function (string $url, int $maxBytes) use ($responses): string {
            self::assertArrayHasKey($url, $responses, "Nečekaný síťový požadavek {$url}.");
            self::assertLessThanOrEqual($maxBytes, strlen($responses[$url]));
            return $responses[$url];
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function epoSources(): array
    {
        return [
            'mfcr-epo-structures' => [
                'label' => 'Finanční správa — popisy struktur EPO',
                'index_url' => 'https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces',
                'index_format' => 'epo_structures',
                'document_hosts' => ['adisspr.mfcr.cz'],
                'document_path_prefixes' => ['/dpr/adis/idpr_pub/epo2_info/'],
                'document_extensions' => [],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function articleSources(): array
    {
        return [
            'cssz-aktuality' => [
                'label' => 'ČSSZ — Aktuality JMHZ',
                'index_url' => 'https://www.cssz.gov.cz/aktuality-jmhz',
                'index_format' => 'article_list',
                'document_hosts' => ['www.cssz.gov.cz'],
                'document_path_prefixes' => ['/web/cz/-/'],
                'document_extensions' => [],
            ],
        ];
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
