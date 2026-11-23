<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use InvalidArgumentException;
use MyInvoice\Tooling\PayrollSourceWatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/tools/PayrollSourceWatcher.php';

final class PayrollSourceWatcherTest extends TestCase
{
    private string $tempDir;

    /** @var array<string,array{status:int,body:string,headers:array<string,string>}|RuntimeException> */
    private array $web = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/myucto-legis-watch-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->tempDir);
    }

    public function testBaselineIsSilentAndSecondIdenticalRunReportsNothing(): void
    {
        $this->serve('https://x.test/file.zip', 'AAA');
        $watcher = $this->watcher(['f' => $this->file('https://x.test/file.zip')]);

        $first = $watcher->watch($this->statePath());
        self::assertTrue($first['baseline_created']);
        self::assertSame(['f'], $first['baselined']);
        self::assertFalse($first['changed']);

        $second = $watcher->watch($this->statePath());
        self::assertFalse($second['baseline_created']);
        self::assertFalse($second['changed']);
        self::assertSame([], $second['warnings']);
    }

    public function testFileChangeReportsOldAndNewHashWithUrl(): void
    {
        $this->serve('https://x.test/file.zip', 'AAA');
        $watcher = $this->watcher(['f' => $this->file('https://x.test/file.zip')]);
        $watcher->watch($this->statePath());

        $this->serve('https://x.test/file.zip', 'AAAB');
        $report = $watcher->watch($this->statePath());

        self::assertTrue($report['changed']);
        self::assertSame('f', $report['changes'][0]['source']);
        self::assertSame('https://x.test/file.zip', $report['changes'][0]['url']);
        self::assertStringContainsString(substr(hash('sha256', 'AAA'), 0, 12), $report['changes'][0]['details'][0]);
        self::assertStringContainsString(substr(hash('sha256', 'AAAB'), 0, 12), $report['changes'][0]['details'][0]);
    }

    public function testBaselineDifferingFromPinnedHashIsReported(): void
    {
        $this->serve('https://x.test/file.zip', 'AAA');
        $watcher = $this->watcher(['f' => $this->file('https://x.test/file.zip') + ['pinned_sha256' => hash('sha256', 'OLD')]]);

        $report = $watcher->watch($this->statePath());

        self::assertTrue($report['changed']);
        self::assertSame('Obsah se liší od připnutého v repu', $report['changes'][0]['message']);
    }

    public function testBaselineMatchingPinnedHashIsSilent(): void
    {
        $this->serve('https://x.test/file.zip', 'AAA');
        $watcher = $this->watcher(['f' => $this->file('https://x.test/file.zip') + ['pinned_sha256' => hash('sha256', 'AAA')]]);

        self::assertFalse($watcher->watch($this->statePath())['changed']);
    }

    public function testFailingSourceIsWarningKeepsOldStateAndOthersStillRun(): void
    {
        $this->serve('https://x.test/a', 'A1');
        $this->serve('https://x.test/b', 'B1');
        $watcher = $this->watcher(['a' => $this->file('https://x.test/a'), 'b' => $this->file('https://x.test/b')]);
        $watcher->watch($this->statePath());

        $this->web['https://x.test/a'] = new RuntimeException('síť nedostupná');
        $this->serve('https://x.test/b', 'B2');
        $report = $watcher->watch($this->statePath());

        self::assertCount(1, $report['warnings']);
        self::assertStringContainsString('a: síť nedostupná', $report['warnings'][0]);
        self::assertSame(['b'], array_column($report['changes'], 'source'));

        $this->serve('https://x.test/a', 'A1');
        self::assertFalse($watcher->watch($this->statePath(), false, ['a'])['changed']);
    }

    public function testHttpErrorAndEmptyBodyAreWarnings(): void
    {
        $this->web['https://x.test/a'] = ['status' => 503, 'body' => 'x', 'headers' => []];
        $this->serve('https://x.test/b', '');
        $report = $this->watcher(['a' => $this->file('https://x.test/a'), 'b' => $this->file('https://x.test/b')])
            ->watch($this->statePath());

        self::assertCount(2, $report['warnings']);
        self::assertSame([], $report['baselined']);
        self::assertFileExists($this->statePath());
    }

    public function testDryRunDoesNotWriteState(): void
    {
        $this->serve('https://x.test/a', 'A');
        $this->watcher(['a' => $this->file('https://x.test/a')])->watch($this->statePath(), false);

        self::assertFileDoesNotExist($this->statePath());
    }

    public function testPageHashIgnoresMarkupOutsideExtractedPart(): void
    {
        $page = static fn (string $nav, string $content): string => "<html><body><nav>{$nav}</nav><div id=\"c\">{$content}</div><footer>x</footer></body></html>";
        $source = ['kind' => 'page', 'label' => 'p', 'url' => 'https://x.test/p', 'extract' => '~<div id="c">(.*?)</div>~s'];
        $this->serve('https://x.test/p', $page('token1', '<p>Stravné   2027</p>'));
        $watcher = $this->watcher(['p' => $source]);
        $watcher->watch($this->statePath());

        $this->serve('https://x.test/p', $page('token2', '<p>Stravné 2027</p>'));
        self::assertFalse($watcher->watch($this->statePath())['changed']);

        $this->serve('https://x.test/p', $page('token2', '<p>Stravné 2028</p>'));
        self::assertTrue($watcher->watch($this->statePath())['changed']);
    }

    public function testPageWithoutExtractedPartIsWarning(): void
    {
        $this->serve('https://x.test/p', '<html>nic</html>');
        $report = $this->watcher(['p' => ['kind' => 'page', 'label' => 'p', 'url' => 'https://x.test/p', 'extract' => '~<main>(.*?)</main>~s']])
            ->watch($this->statePath());

        self::assertCount(1, $report['warnings']);
    }

    public function testMpsvPageReportsNewAttachmentAndReorderIsIgnored(): void
    {
        $this->serve('https://x.test/api', $this->mpsv(['a.xlsx' => 100, 'b.zip' => 200]));
        $watcher = $this->watcher(['m' => ['kind' => 'mpsv-page', 'label' => 'm', 'url' => 'https://x.test/api']]);
        $watcher->watch($this->statePath());

        $this->serve('https://x.test/api', $this->mpsv(['b.zip' => 200, 'a.xlsx' => 100]));
        self::assertFalse($watcher->watch($this->statePath())['changed']);

        $this->serve('https://x.test/api', $this->mpsv(['b.zip' => 200, 'a.xlsx' => 100, 'c.xlsx' => 300], 46));
        $report = $watcher->watch($this->statePath());

        self::assertTrue($report['changed']);
        $details = implode("\n", $report['changes'][0]['details']);
        self::assertStringContainsString('verze stránky: 45 -> 46', $details);
        self::assertStringContainsString('nové: příloha c.xlsx', $details);
    }

    public function testMpsvPageReportsChangedQuestionAndAnswer(): void
    {
        $this->serve('https://x.test/qa', $this->mpsvEntries(['1' => 'odpověď A']));
        $watcher = $this->watcher(['q' => ['kind' => 'mpsv-page', 'label' => 'q', 'url' => 'https://x.test/qa']]);
        $watcher->watch($this->statePath());

        $this->serve('https://x.test/qa', $this->mpsvEntries(['1' => 'odpověď A2', '2' => 'nová']));
        $details = implode("\n", $watcher->watch($this->statePath())['changes'][0]['details']);

        self::assertStringContainsString('změněno: záznam 1', $details);
        self::assertStringContainsString('nové: záznam 2', $details);
    }

    public function testNonJsonMpsvResponseIsWarning(): void
    {
        $this->serve('https://x.test/api', '<html>chyba</html>');
        $report = $this->watcher(['m' => ['kind' => 'mpsv-page', 'label' => 'm', 'url' => 'https://x.test/api']])
            ->watch($this->statePath());

        self::assertCount(1, $report['warnings']);
    }

    public function testRssReportsOnlyNewItemsWithKeywordDiacriticsInsensitive(): void
    {
        $source = ['kind' => 'rss', 'label' => 'rss', 'url' => 'https://x.test/rss', 'keywords' => ['stravné', 'minimální mzd']];
        $this->serve('https://x.test/rss', $this->rss([['1/2026', 'Vyhláška o něčem jiném']]));
        $watcher = $this->watcher(['r' => $source]);
        $first = $watcher->watch($this->statePath());
        self::assertFalse($first['changed']);

        $this->serve('https://x.test/rss', $this->rss([
            ['3/2026', 'Vyhláška o změně sazeb základní náhrady a stravneho'],
            ['2/2026', 'Nařízení vlády o ochraně zvířat'],
            ['1/2026', 'Vyhláška o něčem jiném'],
        ]));
        $report = $watcher->watch($this->statePath());

        self::assertTrue($report['changed']);
        self::assertCount(1, $report['changes'][0]['details']);
        self::assertStringContainsString('3/2026', $report['changes'][0]['details'][0]);
        self::assertStringContainsString('https://x.test/3/2026', $report['changes'][0]['details'][0]);

        self::assertFalse($watcher->watch($this->statePath())['changed']);
    }

    public function testRssKeywordInDescriptionMatchesAndRemovedItemsAreIgnored(): void
    {
        $source = ['kind' => 'rss', 'label' => 'rss', 'url' => 'https://x.test/rss', 'keywords' => ['normativního nájemn']];
        $this->serve('https://x.test/rss', $this->rss([['1/2026', 'Sdělení']]));
        $watcher = $this->watcher(['r' => $source]);
        $watcher->watch($this->statePath());

        $this->serve('https://x.test/rss', $this->rss([['2/2026', 'Sdělení MPSV', 'o výši Normativního nájemného na rok 2027']]));
        self::assertTrue($watcher->watch($this->statePath())['changed']);
    }

    public function testInvalidSourcesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PayrollSourceWatcher(['x' => ['kind' => 'file', 'url' => 'http://x.test/a']]);
    }

    public function testCorruptStateFileThrows(): void
    {
        file_put_contents($this->statePath(), '{"nope":1}');
        $this->serve('https://x.test/a', 'A');

        $this->expectException(RuntimeException::class);
        $this->watcher(['a' => $this->file('https://x.test/a')])->watch($this->statePath());
    }

    public function testRepositorySourceConfigurationIsValid(): void
    {
        $sources = require dirname(__DIR__, 4) . '/tools/payroll-watch-sources.php';

        self::assertArrayHasKey('csu-cisob-43', $sources);
        self::assertArrayHasKey('csu-czem-1186', $sources);
        self::assertArrayHasKey('sbirka-nove-predpisy', $sources);
        self::assertArrayHasKey('pkg-xsd-jmhz-2027', $sources);
        self::assertInstanceOf(PayrollSourceWatcher::class, new PayrollSourceWatcher($sources, static fn (): array => []));
    }

    /** @param array<string,mixed> $source */
    private function watcher(array $sources): PayrollSourceWatcher
    {
        return new PayrollSourceWatcher($sources, function (string $url): array {
            $response = $this->web[$url] ?? new RuntimeException("neznámá adresa {$url}");
            if ($response instanceof RuntimeException) {
                throw $response;
            }

            return $response;
        });
    }

    /** @return array<string,mixed> */
    private function file(string $url): array
    {
        return ['kind' => 'file', 'label' => 'soubor', 'url' => $url];
    }

    private function serve(string $url, string $body): void
    {
        $this->web[$url] = ['status' => 200, 'body' => $body, 'headers' => []];
    }

    private function statePath(): string
    {
        return $this->tempDir . '/state.json';
    }

    /** @param array<string,int> $attachments */
    private function mpsv(array $attachments, int $version = 45): string
    {
        $list = [];
        foreach ($attachments as $name => $size) {
            $list[] = ['fileName' => $name, 'fileSize' => $size, 'mediaId' => md5($name), 'downloadLink' => "https://x.test/{$name}"];
        }

        return (string) json_encode([
            'config' => ['confluencePageVersion' => $version],
            'updatedDate' => '2026-11-05T13:47:12+01:00',
            'payload' => ['attachments' => $list, 'body' => ['text' => 'x']],
        ]);
    }

    /** @param array<string,string> $answers */
    private function mpsvEntries(array $answers): string
    {
        $entries = [];
        foreach ($answers as $id => $answer) {
            $entries[] = ['content' => 'documentationEntry', 'values' => ['id' => (string) $id, 'answer' => $answer]];
        }

        return (string) json_encode(['config' => [], 'updatedDate' => '2026-11-05', 'payload' => ['entries' => $entries]]);
    }

    /** @param list<array{0:string,1:string,2?:string}> $items */
    private function rss(array $items): string
    {
        $xml = '<rss version="2.0"><channel><title>t</title>';
        foreach ($items as $item) {
            $xml .= '<item><title>' . htmlspecialchars($item[0] . ' Sb. - ' . $item[1]) . '</title><link>https://x.test/' . $item[0]
                . '</link><guid>/cs/' . $item[0] . '</guid><pubDate>2026-11-09T00:00:00</pubDate><description>'
                . htmlspecialchars('<p>' . ($item[2] ?? '') . '</p>') . '</description></item>';
        }

        return $xml . '</channel></rss>';
    }
}
