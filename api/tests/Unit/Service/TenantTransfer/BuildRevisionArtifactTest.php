<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use PHPUnit\Framework\TestCase;

final class BuildRevisionArtifactTest extends TestCase
{
    public function testBothDockerVariantsEmbedImmutableRevision(): void
    {
        foreach (['Dockerfile', 'Dockerfile.alpine'] as $filename) {
            $contents = $this->read($filename);
            self::assertStringContainsString('ARG BUILD_REVISION=unavailable', $contents, $filename);
            self::assertStringContainsString('> BUILD_REVISION', $contents, $filename);
        }
    }

    public function testReleaseWorkflowPinsImagesAndNativeBundleToCommitSha(): void
    {
        $workflow = $this->read('.github/workflows/docker-publish.yml');

        self::assertStringContainsString('BUILD_REVISION=${{ github.sha }}', $workflow);
        self::assertStringContainsString('printf \'%s\\n\' "$GITHUB_SHA" > BUILD_REVISION', $workflow);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}
