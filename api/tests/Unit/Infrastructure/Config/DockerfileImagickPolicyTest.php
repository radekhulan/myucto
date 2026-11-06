<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oba runtime image instalují restriktivní ImageMagick policy. Policy zakazuje
 * skriptovací a síťové codery, ale nesmí rozbít náhledy PDF ani SVG logo.
 */
final class DockerfileImagickPolicyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function dockerfileProvider(): array
    {
        return [
            'Dockerfile (Debian/Apache)' => ['/Dockerfile'],
            'Dockerfile.alpine (nginx)'  => ['/Dockerfile.alpine'],
        ];
    }

    #[DataProvider('dockerfileProvider')]
    public function testRuntimeImageInstallsPolicy(string $relativePath): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 5) . $relativePath);
        $codeOnly = implode("\n", array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        ));
        self::assertMatchesRegularExpression('#^COPY docker/imagemagick-policy\.xml #m', $codeOnly);
        self::assertStringContainsString('"$d/policy.xml"', $codeOnly);
        self::assertStringContainsString('mvg:/tmp/policy-probe.mvg', $codeOnly);
    }

    public function testPolicyDeniesDangerousCodersAndKeepsPdf(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/docker/imagemagick-policy.xml');
        self::assertNotFalse($xml);
        $denied = [];
        foreach ($xml->policy as $p) {
            if ((string) $p['domain'] === 'coder' && (string) $p['rights'] === 'none') {
                $denied[] = (string) $p['pattern'];
            }
        }
        foreach (['MVG', 'MSL', 'URL', 'HTTP', 'HTTPS', 'TEXT', 'EPHEMERAL', 'PS', 'EPS'] as $coder) {
            self::assertContains($coder, $denied);
        }
        foreach (['PDF', 'SVG', 'PNG', 'JPEG', 'HEIC', '*'] as $coder) {
            self::assertNotContains($coder, $denied);
        }
        self::assertStringNotContainsString('name="time"', (string) $xml->asXML());
    }
}
