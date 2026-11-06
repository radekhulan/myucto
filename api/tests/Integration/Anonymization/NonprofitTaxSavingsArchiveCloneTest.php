<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Anonymization\AnonymizationOptions;
use MyInvoice\Service\Anonymization\AnonymizationService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsService;
use MyInvoice\Tests\Integration\Tax\NonprofitDppoStoredArchiveGuardTest;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use MyInvoice\Tests\Support\OwnedTestDatabaseSessions;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Anonymizovaná kopie instalace s evidencí daňové úspory: zdroj z finálního archivu 504,
 * použití, jeho storno, zneplatnění a obnovení zdroje, aktivní použití s vazbou na náklad
 * a nedokončený požadavek.
 */
final class NonprofitTaxSavingsArchiveCloneTest extends TestCase
{
    private const MARKER = 'saving-private@example.test';

    public function testCloneRewritesSavingsGraphAndKeepsLedgerOperable(): void
    {
        $this->withOwnedSource(function (Config $config, string $source, string $target, PDO $server): void {
            $fixture = $this->savingsGraph();
            $supplier = $fixture['supplier'];
            $before = $this->graph($server, $source, $supplier);
            self::assertSame(['use', 'reverse_use', 'void', 'restore', 'use'], array_column($before['events'], 'operation'));
            self::assertCount(1, $before['bindings']);

            $target = CloneSandbox::anonymized($server, $config, $source, 'synthetic-dppo-savings-clone');

            self::assertSame($before, $this->graph($server, $source, $supplier), 'Zdrojová evidence se při kopii změnila.');
            $after = $this->graph($server, $target, $supplier);
            self::assertSame(array_column($before['events'], 'operation'), array_column($after['events'], 'operation'));
            self::assertSame(array_column($before['events'], 'amount_cents'), array_column($after['events'], 'amount_cents'));
            self::assertSame(array_column($before['events'], 'cost_line_id'), array_column($after['events'], 'cost_line_id'));
            self::assertSame($before['bindings'], $after['bindings']);

            $cloned = $this->connection($config, $target);
            try {
                $sourceId = $fixture['source_id'];
                $events = (new DimensionPeriodGuard($cloned))->transaction(function () use ($cloned, $supplier, $sourceId): array {
                    (new DimensionPeriodGuard($cloned))->lockPeriods($supplier);
                    $ledger = new NonprofitTaxSavingsLedger($cloned);
                    $owner = $ledger->source($supplier, $sourceId);
                    $snapshot = $cloned->pdo()->query('SELECT * FROM income_tax_return_snapshots WHERE id = ' . $owner['snapshot_id'])->fetch(PDO::FETCH_ASSOC);
                    NonprofitDppoStoredProof::verify(json_decode($snapshot['snapshot_json'], true), json_decode($snapshot['source_manifest'], true), $snapshot['xml_content']);
                    self::assertSame($snapshot['xml_sha256'], $owner['basis']['xml_sha256']);

                    return $ledger->events($supplier, $sourceId);
                });
                self::assertNotSame(array_column($before['events'], 'record_hash'), array_column($after['events'], 'record_hash'));
                foreach (['sources', 'events', 'commands'] as $part) {
                    self::assertStringNotContainsString(self::MARKER, json_encode($after[$part], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                $active = $events[array_key_last($events)];
                self::assertSame('use', $active['operation']);
                self::assertSame(
                    NonprofitTaxRules::hash((new DimensionPeriodGuard($cloned))->transaction(fn (): array => (new NativeCostCoverage($cloned))->financingCost($supplier, $fixture['line']))),
                    NonprofitTaxRules::hash($active['proof']['cost_native']),
                );

                $owner = new NonprofitTaxSavingsService($cloned);
                $preview = $owner->previewReverse($supplier, $sourceId, $active['id'], 'Synthetic clone correction');
                $reversal = $owner->confirmReverse($supplier, $sourceId, $active['id'], 'Synthetic clone correction', $preview['preview_hash'], $preview['head_hash'], 'clone-follow-up-reverse', $fixture['actor']);
                self::assertSame('reverse_use', $reversal['operation']);
                self::assertSame('0', (new CostCoverage($cloned))->claimed($supplier, $fixture['line']));
                self::assertSame(0, (int) $cloned->pdo()->query('SELECT COUNT(*) FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id = ' . $supplier)->fetchColumn());
            } finally {
                $cloned->close();
            }
        });
    }

    /**
     * @return array{supplier:int, actor:int, source_id:int, line:int}
     */
    private function savingsGraph(): array
    {
        $guard = new NonprofitDppoStoredArchiveGuardTest('testStoredHistoricalUseClaimsCapacityAndCanBeReversedWithExactReceipt');
        (new \ReflectionMethod($guard, 'setUp'))->invoke($guard);
        $db = $this->property($guard, 'db');
        $supplier = $this->property($guard, 'supplier');
        $actor = $this->property($guard, 'actor');
        $fixture = (new \ReflectionMethod($guard, 'savingFixture'))->invoke($guard);
        $sourceId = (int) $fixture['source']['source']['id'];
        $owner = new NonprofitTaxSavingsService($db);

        $first = (new \ReflectionMethod($guard, 'appendHistoricalUse'))->invoke($guard, $fixture);
        $preview = $owner->previewReverse($supplier, $sourceId, $first['id'], 'Synthetic reversal ' . self::MARKER);
        $owner->confirmReverse($supplier, $sourceId, $first['id'], 'Synthetic reversal ' . self::MARKER, $preview['preview_hash'], $preview['head_hash'], 'synthetic-reverse', $actor);
        $period = (int) $fixture['source']['source']['period_id'];
        $owner->preparePeriodReplacement($supplier, $period, $actor, 'synthetic-replace');
        $owner->restoreEffective($supplier, 2026, $actor, 'synthetic-restore');
        $this->appendUse($guard, $db, $fixture, 'synthetic-active-use', '1500000');

        $request = ['operation' => 'use', 'source_id' => $sourceId, 'evidence' => 'Synthetic pending ' . self::MARKER];
        $db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_commands(supplier_id,idempotency_key,request_json,request_hash,created_by) VALUES(?,?,?,?,?)')
            ->execute([$supplier, 'synthetic-pending', NonprofitTaxRules::canonicalJson($request), NonprofitTaxRules::hash(['request' => $request, 'idempotency_key' => 'synthetic-pending', 'actor' => $actor]), $actor]);
        $db->pdo()->commit();

        return ['supplier' => $supplier, 'actor' => $actor, 'source_id' => $sourceId, 'line' => (int) $fixture['line']];
    }

    /**
     * Druhé (aktivní) použití po obnovení zdroje — stejná cesta jako historické použití
     * fixture, jen s vlastním klíčem, částkou a dnešní kapacitou nákladu.
     */
    private function appendUse(object $guard, Connection $db, array $fixture, string $key, string $amount): array
    {
        $container = $this->property($guard, 'container');
        $supplier = $this->property($guard, 'supplier');
        $actor = $this->property($guard, 'actor');
        $ledger = new NonprofitTaxSavingsLedger($db);
        $source = $ledger->source($supplier, (int) $fixture['source']['source']['id']);
        $events = $ledger->events($supplier, $source['id']);
        $head = $ledger->head($source, $events);
        $input = ['cost_line_id' => $fixture['line'], 'amount_cents' => $amount, 'purpose' => 'nonbusiness', 'evidence' => 'Synthetic active cost-use ' . self::MARKER];
        $assessment = $container->get(\MyInvoice\Repository\NonprofitTaxAssessmentRepository::class)->latest($supplier, $fixture['next'], true);
        $row = array_column($assessment['result']['rows'], null, 'line_id')[$fixture['line']];
        $capacity = (new CostCoverage($db))->capacity($supplier, $fixture['line']);
        $proof = [
            'cost_native' => (new NativeCostCoverage($db))->financingCost($supplier, $fixture['line']),
            'assessment' => ['id' => $assessment['id'], 'input_hash' => $assessment['input_hash'], 'request_hash' => $assessment['request_hash'], 'row' => $row],
            'legal_profile' => $assessment['snapshot']['legal_profile'],
            'purpose' => $input['purpose'],
            'evidence' => $input['evidence'],
            'cost_capacity_hash' => NonprofitTaxRules::hash($capacity),
            'cost_capacity' => $capacity,
        ];
        $plan = ['operation' => 'use', 'source_id' => $source['id'], 'source_hash' => $source['record_hash'], 'head_hash' => $head,
            'period_id' => $fixture['next'], 'cost_line_id' => $fixture['line'], 'amount_cents' => $amount, 'proof' => $proof];
        $request = ['operation' => 'use', 'source_id' => $source['id'], 'input' => $input, 'preview_hash' => NonprofitTaxRules::hash($plan), 'expected_head_hash' => $head];
        $owner = new NonprofitTaxSavingsService($db);

        return (new \ReflectionMethod($owner, 'append'))->invoke($owner, $supplier, $request, $key, $actor, $fixture['next'], $fixture['line'], $amount, null, $proof);
    }

    /** @return array{sources:list<array<string,mixed>>, events:list<array<string,mixed>>, commands:list<array<string,mixed>>, bindings:list<array<string,mixed>>} */
    private function graph(PDO $server, string $schema, int $supplier): array
    {
        $out = [];
        foreach (['sources' => 'nonprofit_tax_saving_sources', 'events' => 'nonprofit_tax_saving_events', 'commands' => 'nonprofit_tax_saving_commands'] as $key => $table) {
            $out[$key] = $server->query('SELECT * FROM ' . DatabaseCloner::quote($schema) . '.' . $table . ' WHERE supplier_id = ' . $supplier . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        $out['bindings'] = $server->query('SELECT source_id, event_id, cost_line_id FROM ' . DatabaseCloner::quote($schema) . '.nonprofit_tax_saving_cost_bindings WHERE supplier_id = ' . $supplier . ' ORDER BY event_id')->fetchAll(PDO::FETCH_ASSOC);

        return $out;
    }

    private function connection(Config $config, string $database): Connection
    {
        $settings = $config->all();
        $settings['db']['name'] = $database;

        return Connection::withoutSharedTestConnection(fn () => new Connection(new Config($settings, $config->dataDir())));
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function withOwnedSource(callable $test): void
    {
        $base = Config::load(Bootstrap::rootDir());
        $previous = (string) getenv('MYINVOICE_DB_NAME');
        self::assertStringEndsWith('_test', $previous);
        self::assertContains($base->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $prefix = 'myucto_dppo_savings_' . bin2hex(random_bytes(5));
        $source = $prefix . '_source_test';
        $target = $prefix . '_target_test';
        $server = new PDO(
            'mysql:host=' . $base->get('db.host') . ';port=' . $base->get('db.port', 3306) . ';charset=utf8mb4',
            $base->get('db.user'),
            $base->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        try {
            $source = CloneSandbox::acquire($server, $previous, true);
            $this->select($source);
            $test(Config::load(Bootstrap::rootDir()), $source, $target, $server);
        } finally {
            $this->select($previous);
            CloneSandbox::releaseAll($server);
        }
    }

    private function select(string $database): void
    {
        putenv('MYINVOICE_DB_NAME=' . $database);
        $_ENV['MYINVOICE_DB_NAME'] = $database;
        $_SERVER['MYINVOICE_DB_NAME'] = $database;
    }
}
