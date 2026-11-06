<?php

declare(strict_types=1);

namespace MyInvoice\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

final class PropertyBenchmarkAcceptance
{
    private const CURRENT_AS_OF = '2026-01-31T23:59:59+01:00';
    private const BEFORE_ADOPTION_AS_OF = '2025-12-31T23:59:59+01:00';
    private const PAGE_LIMIT = 100;

    private const FROZEN = [
        IssuanceRepository::class,
        IssuanceUseGuard::class,
        IssuanceLocks::class,
        PropertyReadRepository::class,
        PostingService::class,
        self::class,
        PropertyBenchmarkDataset::class,
        PropertyBenchmarkAdoption::class,
        PropertyBenchmarkRead::class,
        PropertyBenchmarkQueries::class,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ContainerInterface $container,
    ) {
    }

    public static function phaseSpec(string $phase): array
    {
        return match ($phase) {
            'pilot' => [
                'subjects' => 1,
                'units_per_subject' => 12,
                'source_months' => 2,
                'source_first' => '2025-01',
                'source_granularity' => 'monthly',
                'monthly_generation' => false,
            ],
            'portfolio-50' => [
                'subjects' => 50,
                'units_per_subject' => 12,
                'source_months' => 2,
                'source_first' => '2025-01',
                'source_granularity' => 'monthly',
                'monthly_generation' => false,
            ],
            'heavy-adoption' => [
                'subjects' => 1,
                'units_per_subject' => 400,
                'source_months' => 120,
                'source_first' => '2016-01',
                'source_granularity' => 'annual_rollup',
                'monthly_generation' => true,
            ],
            default => throw new \InvalidArgumentException('Use pilot, portfolio-50 or heavy-adoption'),
        };
    }

    public function run(string $phase, string $output): array
    {
        if (!is_dir($output)) {
            throw new \InvalidArgumentException('Output must be an existing directory');
        }
        $spec = self::phaseSpec($phase);
        $environment = $this->environmentProof();
        $pdo = $this->db->pdo();
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $file = rtrim($output, '/\\') . DIRECTORY_SEPARATOR . 'property-acceptance-' . $phase . '.json';
        if (is_file($file)) {
            throw new RuntimeException('Acceptance output already exists; use a fresh owned database and output directory');
        }

        $report = [
            'schema' => 1,
            'phase' => $phase,
            'complete' => false,
            'full_acceptance_claimed' => false,
            'database' => $environment['database'],
            'environment' => $environment,
            'spec' => $spec,
            'acceptance_limits' => [
                'saldo_page_ms' => 1000,
                'monthly_generation_ms' => 30000,
                'portfolio_summary_sla_defined' => false,
            ],
            'financial_history_writer' => 'native_property_opening_owner',
            'raw_financial_inserts' => false,
            'subjects' => [],
        ];
        self::write($file, $report);

        $dataset = new PropertyBenchmarkDataset($this->db, $this->container);
        $adoption = new PropertyBenchmarkAdoption($this->db, $this->container);
        for ($index = 1; $index <= $spec['subjects']; ++$index) {
            self::verifyFrozen($environment);
            $label = $phase . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $fixtureStarted = hrtime(true);
            $fixture = $dataset->create($spec['units_per_subject'], '2016-01-01', $label);
            $catalogMs = (hrtime(true) - $fixtureStarted) / 1e6;
            $receipt = $adoption->apply($fixture, $spec['source_months'], $spec['source_first']);
            if ($receipt['source_granularity'] !== $spec['source_granularity']) {
                throw new RuntimeException('Adoption source granularity changed');
            }

            $queries = null;
            if ($index === 1) {
                $queries = new PropertyBenchmarkQueries();
                $queries->attach($pdo);
            }
            try {
                $proof = $phase === 'pilot'
                    ? $this->pilotProof($fixture, $receipt)
                    : $this->boundedProof($fixture, $receipt);
            } finally {
                if ($queries !== null) {
                    $queries->detach($pdo);
                }
            }
            $querySummary = $queries?->summary();
            $queryPlans = $queries?->plans($pdo, 12);
            $subject = [
                'ordinal' => $index,
                'label' => $label,
                'supplier_id' => $fixture['supplier'],
                'complete' => true,
                'catalog_ms' => $catalogMs,
                'adoption' => $receipt,
                'proof' => $proof,
                'representative_query_summary' => $querySummary,
                'representative_query_plans' => $queryPlans,
            ];

            if ($phase === 'heavy-adoption') {
                $generation = $dataset->issueMonth($fixture, '2026-02');
                $generation['within_30s'] = $generation['total_ms'] <= 30000;
                $postGenerationExpected = bcadd($receipt['total_cents'], $generation['amount_cents'], 0);
                $postGeneration = $this->reconciliation($fixture['supplier'], '2026-02-28', $postGenerationExpected);
                $knownAt = self::knownAt();
                $postGenerationRead = (new PropertyBenchmarkRead($this->db))->traverse(
                    $fixture['supplier'],
                    '2026-02-28T23:59:59+01:00',
                    $knownAt,
                    400,
                    self::PAGE_LIMIT,
                );
                if ($postGenerationRead['gross_receivable_cents'] !== $postGenerationExpected
                    || $postGenerationRead['claim_count_min'] !== 11
                    || $postGenerationRead['claim_count_max'] !== 11) {
                    throw new RuntimeException('Post-generation heavy saldo mismatch');
                }
                $subject['monthly_generation'] = $generation;
                $subject['post_generation_reconciliation'] = $postGeneration;
                $subject['post_generation_read'] = $postGenerationRead;
            }

            $report['subjects'][] = $subject;
            self::write($file, $report);
            unset($fixture, $receipt, $proof, $querySummary, $queryPlans, $queries, $subject);
        }

        $pagePass = true;
        $generationPass = true;
        foreach ($report['subjects'] as $subject) {
            foreach (['current', 'before_adoption'] as $view) {
                $pagePass = $pagePass && $subject['proof'][$view]['within_1s'];
            }
            if (isset($subject['post_generation_read'])) {
                $pagePass = $pagePass && $subject['post_generation_read']['within_1s'];
                $generationPass = $generationPass && $subject['monthly_generation']['within_30s'];
            }
        }
        self::verifyFrozen($environment);
        $report['complete'] = true;
        $report['all_saldo_pages_within_1s'] = $pagePass;
        $report['monthly_generation_within_30s'] = $phase === 'heavy-adoption' ? $generationPass : null;
        $report['portfolio_subjects_measured'] = $phase === 'portfolio-50' ? count($report['subjects']) : null;
        $report['whole_portfolio_summary_endpoint_measured'] = false;
        $report['phase_acceptance_passed'] = $pagePass && ($phase !== 'heavy-adoption' || $generationPass);
        $report['full_acceptance_claimed'] = false;
        self::write($file, $report);

        return $report;
    }

    private function pilotProof(array $fixture, array $receipt): array
    {
        $knownAt = self::knownAt();
        $reader = new PropertyBenchmarkRead($this->db);
        $current = $reader->page($fixture['supplier'], self::CURRENT_AS_OF, $knownAt, 25);
        $before = $reader->page($fixture['supplier'], self::BEFORE_ADOPTION_AS_OF, $knownAt, 25);
        if ($current['page']['next_cursor'] !== null || $before['page']['next_cursor'] !== null) {
            throw new RuntimeException('Pilot unexpectedly required another saldo page');
        }

        $units = [];
        foreach ($fixture['units'] as $index => $id) {
            $units[] = ['id' => $id, 'code' => sprintf('U%03d', $index + 1)];
        }
        $currentReference = $reader->reference($fixture['supplier'], self::CURRENT_AS_OF, $knownAt, $units);
        $beforeReference = $reader->reference($fixture['supplier'], self::BEFORE_ADOPTION_AS_OF, $knownAt, $units);
        $currentBytes = json_encode($current['page']['items'], JSON_THROW_ON_ERROR);
        $currentReferenceBytes = json_encode($currentReference, JSON_THROW_ON_ERROR);
        $beforeBytes = json_encode($before['page']['items'], JSON_THROW_ON_ERROR);
        $beforeReferenceBytes = json_encode($beforeReference, JSON_THROW_ON_ERROR);
        if ($currentBytes !== $currentReferenceBytes || $beforeBytes !== $beforeReferenceBytes) {
            throw new RuntimeException('Pilot saldo and independent reference are not byte-identical');
        }

        foreach ($current['page']['items'] as $item) {
            if ($item['totals']['gross_receivable_cents'] !== '360000' || $item['claim_count'] !== 2) {
                throw new RuntimeException('Pilot current saldo mismatch');
            }
        }
        foreach ($before['page']['items'] as $item) {
            if ($item['totals']['gross_receivable_cents'] !== '0' || $item['claim_count'] !== 0) {
                throw new RuntimeException('Pilot invented pre-adoption history');
            }
        }

        $proof = [
            'known_at' => $knownAt,
            'reconciliation' => $this->reconciliation($fixture['supplier'], '2026-01-31', $receipt['total_cents']),
            'current' => [
                'within_1s' => $current['ms'] <= 1000,
                'ms' => $current['ms'],
                'units' => $current['units'],
                'delta' => $current['delta'],
                'byte_parity' => true,
                'sha256' => hash('sha256', $currentBytes),
                'read_scope_digest' => $current['page']['read_scope_digest'],
            ],
            'before_adoption' => [
                'within_1s' => $before['ms'] <= 1000,
                'ms' => $before['ms'],
                'units' => $before['units'],
                'delta' => $before['delta'],
                'byte_parity' => true,
                'sha256' => hash('sha256', $beforeBytes),
                'read_scope_digest' => $before['page']['read_scope_digest'],
            ],
        ];
        unset($current, $before, $currentReference, $beforeReference, $currentBytes, $currentReferenceBytes, $beforeBytes, $beforeReferenceBytes);

        return $proof;
    }

    private function boundedProof(array $fixture, array $receipt): array
    {
        $knownAt = self::knownAt();
        $reader = new PropertyBenchmarkRead($this->db);
        $current = $reader->traverse($fixture['supplier'], self::CURRENT_AS_OF, $knownAt, count($fixture['units']), self::PAGE_LIMIT);
        $before = $reader->traverse($fixture['supplier'], self::BEFORE_ADOPTION_AS_OF, $knownAt, count($fixture['units']), self::PAGE_LIMIT);
        if ($current['gross_receivable_cents'] !== $receipt['total_cents']
            || $current['claim_count_min'] !== $receipt['claims_per_unit']
            || $current['claim_count_max'] !== $receipt['claims_per_unit']) {
            throw new RuntimeException('Current adopted saldo does not match the native receipt');
        }
        if ($before['gross_receivable_cents'] !== '0' || $before['claim_count_min'] !== 0 || $before['claim_count_max'] !== 0) {
            throw new RuntimeException('Adopted history appeared before its effective date');
        }

        return [
            'known_at' => $knownAt,
            'reconciliation' => $this->reconciliation($fixture['supplier'], '2026-01-31', $receipt['total_cents']),
            'current' => $current,
            'before_adoption' => $before,
            'reference_projection' => false,
            'reference_reason' => 'Independent full-history PHP projection is restricted to the 12-unit pilot',
        ];
    }

    private function reconciliation(int $supplier, string $date, string $expected): array
    {
        $started = hrtime(true);
        $result = (new PropertyReconciliation($this->db))->at($supplier, $date);
        $ms = (hrtime(true) - $started) / 1e6;
        if (!$result['matches']
            || $result['ledger_cents'] !== ['receivable' => $expected, 'credit' => '0']
            || $result['ledger_cents'] !== $result['subledger_cents']) {
            throw new RuntimeException('Native GL/subledger reconciliation failed');
        }

        return [
            'at' => $date,
            'ms' => $ms,
            'matches' => true,
            'ledger_cents' => $result['ledger_cents'],
            'subledger_cents' => $result['subledger_cents'],
        ];
    }

    private function environmentProof(): array
    {
        $config = $this->container->get(Config::class);
        $host = $config->get('db.host');
        $database = $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database)
            || preg_match('/^myucto_nno_property_benchmark_[a-z0-9_]+_test$/D', $database) !== 1
            || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Exact owned local synthetic benchmark database required');
        }

        $root = (string) realpath(Bootstrap::rootDir());
        $prefix = strtolower($root) . DIRECTORY_SEPARATOR;
        foreach ([Bootstrap::class, Config::class, Connection::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (!is_string($file) || !str_starts_with(strtolower((string) realpath($file)), $prefix)) {
                throw new RuntimeException('Application class escaped the benchmark checkout: ' . $class);
            }
        }
        $vendorFile = (new ReflectionClass(ContainerInterface::class))->getFileName();
        if (!is_string($vendorFile)
            || !str_starts_with(strtolower((string) realpath($vendorFile)), $prefix . 'api' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Composer dependency escaped the benchmark checkout');
        }

        $frozen = [];
        foreach (self::FROZEN as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $realFile = is_string($file) ? realpath($file) : false;
            if (!is_string($realFile)
                || !str_starts_with(strtolower($realFile), $prefix)) {
                throw new RuntimeException('Benchmark dependency escaped the checkout: ' . $class);
            }
            $frozen[$class] = [
                'file' => str_replace('\\', '/', substr($realFile, strlen($root) + 1)),
                'sha256' => hash_file('sha256', $realFile),
            ];
        }

        return [
            'database' => $database,
            'host' => $host,
            'bootstrap_reflection_verified' => true,
            'copied_vendor_reflection_verified' => true,
            'php' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY,
            'mariadb' => $this->db->pdo()->query('SELECT VERSION()')->fetchColumn(),
            'frozen' => $frozen,
        ];
    }

    private static function verifyFrozen(array $environment): void
    {
        $root = realpath(Bootstrap::rootDir());
        if ($root === false || !is_array($environment['frozen'] ?? null) || $environment['frozen'] === []) {
            throw new RuntimeException('Benchmark source manifest is missing');
        }
        $prefix = strtolower($root) . DIRECTORY_SEPARATOR;
        foreach ($environment['frozen'] as $proof) {
            $file = realpath($root . DIRECTORY_SEPARATOR . $proof['file']);
            if ($file === false || !str_starts_with(strtolower($file), $prefix)
                || !hash_equals($proof['sha256'], hash_file('sha256', $file))) {
                throw new RuntimeException('Frozen benchmark dependency changed');
            }
        }
    }

    private static function knownAt(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private static function write(string $file, array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Could not persist benchmark receipt');
        }
    }
}
