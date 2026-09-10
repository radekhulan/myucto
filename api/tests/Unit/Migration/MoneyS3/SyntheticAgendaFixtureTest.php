<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

/**
 * Přibalená záloha `synthetic-agenda.lz` musí odpovídat generátoru — jinak by ruční
 * zkouška průvodce (nahrát fixture v UI) převáděla jinou agendu, než jakou ověřují testy.
 * Po změně {@see SyntheticAgenda} spusť `php api/tests/Fixtures/MoneyS3/generate.php`.
 */
final class SyntheticAgendaFixtureTest extends TestCase
{
    public function testCommittedBackupMatchesGenerator(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/MoneyS3/synthetic-agenda.lz';
        self::assertFileExists($path);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $committed = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $committed[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        $generated = SyntheticAgenda::files();
        ksort($committed);
        ksort($generated);
        self::assertSame(array_keys($generated), array_keys($committed), 'Seznam souborů zálohy se liší od generátoru.');
        foreach ($generated as $name => $content) {
            self::assertSame(hash('sha256', $content), hash('sha256', $committed[$name]), "Soubor {$name} v záloze se liší od generátoru.");
        }
    }
}
