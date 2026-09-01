<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

final class BankMatchingEntryPointContractTest extends TestCase
{
    public function testIdokladImportUsesTwoPassMatching(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/Import/IdokladBankTransactionImporter.php',
        );
        $import = null;
        $lines = explode("\n", $source);
        foreach (PhpSourceRegions::symbols($source) as $symbol) {
            if ($symbol['name'] === 'import') {
                $import = implode("\n", array_slice(
                    $lines,
                    $symbol['startLine'] - 1,
                    $symbol['endLine'] - $symbol['startLine'] + 1,
                ));
                break;
            }
        }

        self::assertNotNull($import);
        self::assertMatchesRegularExpression(
            '/\$this->matcher->matchBatch\s*\(\s*\[\s*\$txId\s*]\s*\)/',
            $import,
            'Jednotlivý iDoklad import musí zachovat druhý průchod párování podle částky a data.',
        );
    }
}
