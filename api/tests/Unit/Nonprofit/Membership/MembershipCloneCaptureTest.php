<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Anonymization\MembershipCloneCapture;
use MyInvoice\Service\Anonymization\MembershipCloneSpoolWriter;
use PHPUnit\Framework\TestCase;

final class MembershipCloneCaptureTest extends TestCase
{
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-membership-capture-' . bin2hex(random_bytes(8));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
    }

    protected function tearDown(): void
    {
        $this->previousDataDir === false ? putenv('MYINVOICE_DATA_DIR') : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        $this->removeDirectory($this->dataDir);
    }

    public function testSpoolStreamsAtMostFiveHundredVerifiedRecordsPerBatch(): void
    {
        $writer = new MembershipCloneSpoolWriter('synthetic_membership_clone');
        for ($id = 1; $id <= 1001; ++$id) $writer->append('operation', ['id' => $id, 'proof' => str_repeat('x', 128)]);
        $capture = $writer->finish(str_repeat('a', 64));

        try {
            self::assertSame(['member' => 0, 'revision' => 0, 'operation' => 1001], $capture->counts);
            $sizes = [];
            $lastIds = [];
            foreach ($capture->batches('operation') as $batch) {
                $sizes[] = count($batch);
                $lastIds[] = $batch[array_key_last($batch)]['id'];
            }
            self::assertSame([500, 500, 1], $sizes);
            self::assertSame([500, 1000, 1001], $lastIds);
        } finally {
            $capture->cleanup();
        }
    }

    public function testSpoolRejectsChangedContentBeforeUse(): void
    {
        $writer = new MembershipCloneSpoolWriter('synthetic_membership_clone');
        $writer->append('member', ['id' => 1]);
        $capture = $writer->finish(str_repeat('b', 64));
        file_put_contents(MembershipCloneCapture::pathForToken($capture->token), "changed\n", FILE_APPEND);

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('missing or changed');
            $capture->assertReadable();
        } finally {
            $capture->cleanup();
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = scandir($directory);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
