<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 5) . '/tools/JmhzOfficialExamplePackageBuilder.php';

final class JmhzOfficialExamplePackageBuilderTest extends TestCase
{
    public function testCompletePinnedXsdInventoryIsAccepted(): void
    {
        $method = new \ReflectionMethod(\JmhzOfficialExamplePackageBuilder::class, 'verifyXsdInventory');
        $method->invoke(
            new \JmhzOfficialExamplePackageBuilder(),
            dirname(__DIR__, 5) . '/api/xsd/jmhz',
        );

        self::addToAssertionCount(1);
    }

    public function testModifiedImportedXsdIsRejectedEvenWhenInventoryFileIsUntouched(): void
    {
        $source = dirname(__DIR__, 5) . '/api/xsd/jmhz';
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-jmhz-xsd-inventory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporary));
        try {
            $this->copyDirectory($source, $temporary);
            self::assertNotFalse(file_put_contents(
                $temporary . '/jmhz-1.4.3.6/baseTypes2.xsd',
                "\n",
                FILE_APPEND,
            ));
            $method = new \ReflectionMethod(\JmhzOfficialExamplePackageBuilder::class, 'verifyXsdInventory');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('neodpovídají úplnému inventáři');
            $method->invoke(new \JmhzOfficialExamplePackageBuilder(), $temporary);
        } finally {
            $this->removeDirectory($temporary);
        }
    }

    public function testUnknownClassificationReasonIsRejected(): void
    {
        $method = new \ReflectionMethod(\JmhzOfficialExamplePackageBuilder::class, 'assertDecision');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neznámý důvod');
        $method->invoke(
            new \JmhzOfficialExamplePackageBuilder(),
            [
                'classification' => 'unresolved',
                'reason_code' => 'unknown',
                'decision_evidence' => ['pinned_xsd_validation'],
                'blocking_reasons' => ['source_version_not_established'],
            ],
            'fail',
            [],
            1,
        );
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                self::fail('Nelze načíst položku XSD bundle.');
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::assertTrue(mkdir($target));
            } elseif ($item->isFile()) {
                self::assertTrue(copy($item->getPathname(), $target));
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                self::fail('Nelze uklidit dočasný XSD bundle.');
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
