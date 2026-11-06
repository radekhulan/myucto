<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Raw persistence účetního rámce smí zůstat jen za AccountingFrameworkService::forDate. */
#[Group('architecture')]
final class AccountingFrameworkSsotGuardTest extends TestCase
{
    private const TABLE = 'supplier_accounting_frameworks';

    /** @var array<string, list<string>> relativní cesta => povolené persistence symboly */
    private const ALLOWED_SYMBOLS = [
        'Repository/AccountingFrameworkRepository.php' => [
            'forDate',
            'forDateForSuppliers',
            'listForTenant',
            'listForTenantForUpdate',
            'readForDateForUpdate',
            'nextChangeForUpdate',
        ],
        'Service/Accounting/AccountingFrameworkService.php' => [
            'recordFramework',
        ],
    ];

    public function testRawTableAccessStaysInsidePersistenceSymbols(): void
    {
        $sources = SourceCorpus::sources(dirname(__DIR__, 2) . '/src');

        foreach (self::ALLOWED_SYMBOLS as $relative => $symbols) {
            self::assertArrayHasKey($relative, $sources, "Chybí povolený persistence soubor {$relative}.");
            self::assertSame(
                [],
                PhpSourceRegions::missingSymbols($sources[$relative], $symbols),
                "Symbolová výjimka guardu se rozešla s kódem v {$relative}.",
            );
        }

        $scan = self::scan($sources);
        $expectedExceptions = [];
        foreach (self::ALLOWED_SYMBOLS as $relative => $symbols) {
            foreach ($symbols as $symbol) {
                $expectedExceptions[] = "{$relative}::{$symbol}";
            }
        }
        sort($expectedExceptions);

        self::assertSame(
            $expectedExceptions,
            array_keys($scan['usedExceptions']),
            'Každá symbolová výjimka musí stále chránit skutečný přístup k tabulce.',
        );
        self::assertSame(
            [],
            $scan['findings'],
            "Tabulku účetních rámců čte nebo zapisuje kód mimo persistence SSOT.\n"
                . "K datovanému rámci přistupuj přes AccountingFrameworkService::forDate().\n  "
                . implode("\n  ", $scan['findings']),
        );
    }

    public function testGuardDetectsConsumerSqlOutsideSsot(): void
    {
        $scan = self::scan([
            'Service/Report/FrameworkConsumer.php' => <<<'PHP'
                <?php
                final class FrameworkConsumer
                {
                    public function load(): void
                    {
                        $this->db->query('SELECT accounting_framework FROM supplier_accounting_frameworks');
                    }
                }
                PHP,
        ]);

        self::assertSame(
            ['Service/Report/FrameworkConsumer.php:6 (load) FROM supplier_accounting_frameworks'],
            $scan['findings'],
        );
    }

    public function testGuardIgnoresSqlWrittenOnlyInComments(): void
    {
        $scan = self::scan([
            'Service/Report/FrameworkConsumer.php' => <<<'PHP'
                <?php
                final class FrameworkConsumer
                {
                    // SELECT accounting_framework FROM supplier_accounting_frameworks
                    /* UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' */
                    public function load(): void {}
                }
                PHP,
        ]);

        self::assertSame([], $scan['findings']);
    }

    public function testAllowedRepositoryFileDoesNotAllowOtherMethods(): void
    {
        $scan = self::scan([
            'Repository/AccountingFrameworkRepository.php' => <<<'PHP'
                <?php
                final class AccountingFrameworkRepository
                {
                    public function unrelatedRead(): void
                    {
                        $this->db->query('SELECT id FROM supplier_accounting_frameworks');
                    }
                }
                PHP,
        ]);

        self::assertSame(
            ['Repository/AccountingFrameworkRepository.php:6 (unrelatedRead) FROM supplier_accounting_frameworks'],
            $scan['findings'],
        );
    }

    /**
     * @param array<string, string> $sources relativní cesta => PHP zdroj
     * @return array{findings:list<string>, usedExceptions:array<string, true>}
     */
    private static function scan(array $sources): array
    {
        $findings = [];
        $usedExceptions = [];

        foreach ($sources as $relative => $source) {
            foreach (self::tableReferences($source) as $reference) {
                $key = "{$relative}::{$reference['symbol']}";
                if (in_array($reference['symbol'], self::ALLOWED_SYMBOLS[$relative] ?? [], true)) {
                    $usedExceptions[$key] = true;
                    continue;
                }
                $findings[] = sprintf(
                    '%s:%d (%s) %s %s',
                    $relative,
                    $reference['line'],
                    $reference['symbol'],
                    $reference['operation'],
                    self::TABLE,
                );
            }
        }

        sort($findings);
        ksort($usedExceptions);

        return ['findings' => $findings, 'usedExceptions' => $usedExceptions];
    }

    /** @return list<array{symbol:string, line:int, operation:string}> */
    private static function tableReferences(string $source): array
    {
        $references = [];
        $fragments = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                if ($token === ';') {
                    array_push($references, ...self::referencesInStatement($source, $fragments));
                    $fragments = [];
                }
                continue;
            }
            if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $fragments[] = [
                'text' => $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1],
                'line' => $token[2],
            ];
        }
        array_push($references, ...self::referencesInStatement($source, $fragments));

        return $references;
    }

    /**
     * @param list<array{text:string, line:int}> $fragments
     * @return list<array{symbol:string, line:int, operation:string}>
     */
    private static function referencesInStatement(string $source, array $fragments): array
    {
        $sql = '';
        $ranges = [];
        foreach ($fragments as $fragment) {
            $start = strlen($sql);
            $sql .= $fragment['text'];
            $ranges[] = ['start' => $start, 'end' => strlen($sql), 'line' => $fragment['line']];
        }

        preg_match_all(
            '/\b(?<operation>FROM|JOIN|INTO|UPDATE|TABLE)\s+`?' . self::TABLE . '`?\b/i',
            $sql,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $references = [];
        foreach ($matches as $match) {
            $offset = $match[0][1];
            $line = 1;
            foreach ($ranges as $range) {
                if ($offset < $range['end']) {
                    $line = $range['line'] + substr_count(
                        substr($sql, $range['start'], max(0, $offset - $range['start'])),
                        "\n",
                    );
                    break;
                }
            }
            $references[] = [
                'symbol' => PhpSourceRegions::symbolAtLine($source, $line) ?? '(mimo symbol)',
                'line' => $line,
                'operation' => strtoupper($match['operation'][0]),
            ];
        }

        return $references;
    }
}
