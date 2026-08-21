<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionUnavailable;
use PHPUnit\Framework\TestCase;

final class BuildRevisionProviderTest extends TestCase
{
    private ?string $revisionFile = null;

    protected function tearDown(): void
    {
        if ($this->revisionFile !== null && is_file($this->revisionFile)) {
            unlink($this->revisionFile);
        }
        $this->revisionFile = null;
    }

    public function testReleaseFileIsAuthoritative(): void
    {
        $file = $this->file('1ff33684a86197be40dbdbae5d18098b6fd0ef21');

        self::assertSame(
            '1ff33684a86197be40dbdbae5d18098b6fd0ef21',
            (new BuildRevisionProvider(new Config([]), $file))->current(),
        );
    }

    public function testExplicitDevelopmentRevisionWorksWithoutReleaseFile(): void
    {
        $missingFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-build-revision-' . bin2hex(random_bytes(8));

        self::assertSame(
            'dev:0123456789abcdef',
            (new BuildRevisionProvider(
                new Config(['app' => ['build_revision' => 'dev:0123456789abcdef']]),
                $missingFile,
            ))->current(),
        );
    }

    public function testExplicitDevelopmentRevisionReplacesDockerPlaceholder(): void
    {
        self::assertSame(
            'dev:0123456789abcdef',
            (new BuildRevisionProvider(
                new Config(['app' => ['build_revision' => 'dev:0123456789abcdef']]),
                $this->file('unavailable'),
            ))->current(),
        );
    }

    public function testConfiguredRevisionCannotOverrideDifferentReleaseArtifact(): void
    {
        $provider = new BuildRevisionProvider(
            new Config(['app' => ['build_revision' => 'git:aaaaaaaaaaaaaaaa']]),
            $this->file('git:bbbbbbbbbbbbbbbb'),
        );

        try {
            $provider->current();
            self::fail('Runtime konfigurace nesmí změnit identitu release artefaktu.');
        } catch (BuildRevisionUnavailable $exception) {
            self::assertSame('mismatch', $exception->reason);
        }
    }

    public function testMissingRevisionFailsClosed(): void
    {
        $provider = new BuildRevisionProvider(
            new Config([]),
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-build-revision-' . bin2hex(random_bytes(8)),
        );

        try {
            $provider->current();
            self::fail('Neidentifikovaný build nesmí být transfer-compatible.');
        } catch (BuildRevisionUnavailable $exception) {
            self::assertSame('missing', $exception->reason);
        }
    }

    public function testPlaceholderRevisionIsRejected(): void
    {
        $provider = new BuildRevisionProvider(
            new Config(['app' => ['build_revision' => 'unavailable']]),
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-build-revision-' . bin2hex(random_bytes(8)),
        );

        try {
            $provider->current();
            self::fail('Placeholder nesmí být považovaný za immutable revision.');
        } catch (BuildRevisionUnavailable $exception) {
            self::assertSame('invalid', $exception->reason);
        }
    }

    private function file(string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-build-revision-' . bin2hex(random_bytes(8));
        file_put_contents($path, $contents . "\n");
        $this->revisionFile = $path;
        return $path;
    }
}
