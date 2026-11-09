<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * Řádky deníku zapisuje jen zapisovač v JournalEntryRepository, který ověřuje invariant
 * dvojic jednoduchého zápisu (JournalPairInvariant). Nový `INSERT INTO journal_entry_lines`
 * nebo `UPDATE journal_entry_lines`, který mění účet, stranu, částku, červené storno nebo
 * číslo dvojice, by invariant obešel.
 *
 * Výjimky se udělují METODĚ, ne souboru. Úpravy, které mění jen měnu, zakázku, středisko
 * nebo dimenze (převody MoneyS3, přerazítkování zakázky), invariant neovlivní a guard je
 * pouští.
 */
final class JournalLineWriterGuardTest extends TestCase
{
    /** Povolené zapisovače řádků (soubor::metoda) a proč. */
    private const ALLOWED_INSERT = [
        'Repository/JournalEntryRepository.php::writeLines' => 'zapisovač řádků za invariantem (insertLines, splitIntoPairs)',
        'Service/Migration/Abra/AbraAccountingImporter.php::writeJournal' => 'převod ABRA, invariant přes abraPairNo()',
        'Service/Migration/Abra/AbraAccountingImporter.php::writeOpeningBalances' => 'převod ABRA, invariant přes abraPairNo()',
        'Service/Migration/Abra/AbraAccountingImporter.php::syncOpeningBalances' => 'převod ABRA, invariant přes abraPairNo()',
    ];

    /** Povolené úpravy účtu / strany / částky / storna / čísla dvojice na existujících řádcích. */
    private const ALLOWED_LEDGER_UPDATE = [
        'Repository/JournalEntryRepository.php::splitIntoPairs' => 'převod na dvojice, invariant nad výsledným zápisem',
        'Repository/JournalEntryRepository.php::replaceSomeLines' => 'přečíslování dvojic při výměně řádků, invariant nad výsledkem',
    ];

    private const GUARDED_COLUMNS = '/\b(?:[a-z_]+\.)?(account_id|side|amount|is_red_storno|pair_no)\s*=/i';

    public function testOnlyAllowedMethodsWriteJournalLines(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $inserts = [];
        $updates = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (stripos($code, 'journal_entry_lines') === false) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (self::statements($code) as [$kind, $sql, $line]) {
                $key = $relative . '::' . (PhpSourceRegions::symbolAtLine($code, $line) ?? '(soubor)');
                if ($kind === 'insert') {
                    $inserts[$key][] = $line;
                } elseif (preg_match(self::GUARDED_COLUMNS, self::setClause($sql)) === 1) {
                    $updates[$key][] = $line;
                }
            }
        }

        self::assertSame([], array_keys(array_diff_key($inserts, self::ALLOWED_INSERT)),
            'INSERT INTO journal_entry_lines mimo zapisovač za invariantem dvojic.');
        self::assertSame([], array_keys(array_diff_key($updates, self::ALLOWED_LEDGER_UPDATE)),
            'UPDATE účtu, strany, částky, storna nebo čísla dvojice řádku deníku mimo povolené metody.');
        self::assertSame([], array_keys(array_diff_key(self::ALLOWED_INSERT, $inserts)), 'Zastaralá výjimka pro INSERT.');
        self::assertSame([], array_keys(array_diff_key(self::ALLOWED_LEDGER_UPDATE, $updates)), 'Zastaralá výjimka pro UPDATE.');
    }

    public function testRepositoryInsertPathGoesThroughPairInvariant(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Repository/JournalEntryRepository.php');
        $insertLines = self::methodBody($code, 'insertLines');
        self::assertStringContainsString('$this->assertPairs(', $insertLines);
        self::assertStringContainsString('JournalPairInvariant::assert(', self::methodBody($code, 'assertPairs'));
        self::assertStringContainsString('$this->assertPairs(', self::methodBody($code, 'splitIntoPairs'));
    }

    public function testAbraImporterWritersCheckPairInvariant(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Migration/Abra/AbraAccountingImporter.php');
        foreach (['writeJournal', 'writeOpeningBalances', 'syncOpeningBalances'] as $method) {
            self::assertStringContainsString('$this->abraPairNo(', self::methodBody($code, $method), $method);
        }
        self::assertStringContainsString('JournalPairInvariant::assert(', self::methodBody($code, 'abraPairNo'));
    }

    public function testGuardDetectsLedgerUpdateButNotDimensionUpdate(): void
    {
        self::assertSame(1, preg_match(self::GUARDED_COLUMNS, self::setClause('UPDATE journal_entry_lines SET amount = ? WHERE id = ?')));
        self::assertSame(1, preg_match(self::GUARDED_COLUMNS, self::setClause('UPDATE journal_entry_lines jel SET jel.pair_no = 1 WHERE 1')));
        self::assertSame(0, preg_match(self::GUARDED_COLUMNS, self::setClause('UPDATE journal_entry_lines SET currency_code = ?, amount_foreign = ? WHERE side = ?')));
    }

    /**
     * SQL příkazy nad journal_entry_lines v řetězcových literálech souboru.
     *
     * @return list<array{0:'insert'|'update', 1:string, 2:int}>
     */
    private static function statements(string $code): array
    {
        $out = [];
        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $text = $token[1];
            if (preg_match('/\bINSERT\s+(?:IGNORE\s+)?INTO\s+journal_entry_lines\b/i', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $out[] = ['insert', $text, $token[2] + substr_count(substr($text, 0, $m[0][1]), "\n")];
            }
            if (preg_match('/\bUPDATE\s+journal_entry_lines\b/i', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $out[] = ['update', $text, $token[2] + substr_count(substr($text, 0, $m[0][1]), "\n")];
            }
        }
        return $out;
    }

    private static function setClause(string $sql): string
    {
        if (preg_match('/\bSET\b(.*?)(?:\bWHERE\b|$)/is', $sql, $m) !== 1) {
            return '';
        }
        return $m[1];
    }

    private static function methodBody(string $code, string $method): string
    {
        foreach (PhpSourceRegions::symbols($code) as $symbol) {
            if ($symbol['name'] === $method) {
                $lines = explode("\n", $code);
                return implode("\n", array_slice($lines, $symbol['startLine'] - 1, $symbol['endLine'] - $symbol['startLine'] + 1));
            }
        }
        self::fail('Metoda ' . $method . ' nenalezena.');
    }
}
