<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAssessmentSource;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger;
use PDO;

/**
 * Evidence daňové úspory § 20 odst. 7 v anonymizované kopii.
 *
 * Před pseudonymizací se celý graf (zdroje, řetězy událostí, požadavky, aktivní vazby
 * na náklady i nedokončené požadavky) ověří ledgerem. Po přepisu archivů 504 se pak
 * přepíše po firmách: základ zdroje se vezme z přepsaného finálního snapshotu, události
 * se převážou na přepsané posouzení J6 a nové otisky požadavků i rodičů, aktivní použití
 * dostanou důkaz nativního nákladu z kopie a zrušená použití svůj zmrazený důkaz
 * s pseudonymizovaným textem. Částky, období, řádky nákladů a vazby se nemění a na konci
 * musí celý graf znovu projít ledgerem.
 */
final class NonprofitTaxSavingsCloneScrubber
{
    private const PAGE = 100;

    /** @var array<string,string> */
    private array $hashes = [];

    private function __construct(
        private readonly Connection $db,
        private readonly TextScrubber $scrubber,
    ) {}

    public static function capture(Connection $db): void
    {
        self::assertClonePhase($db);
        $pdo = $db->pdo();
        $orphan = $pdo->query(
            'SELECT b.event_id FROM nonprofit_tax_saving_cost_bindings b
               LEFT JOIN nonprofit_tax_saving_events e ON e.supplier_id = b.supplier_id AND e.id = b.event_id AND e.source_id = b.source_id
              WHERE e.id IS NULL LIMIT 1',
        )->fetchColumn();
        if ($orphan !== false) {
            self::unsupported('vazba na náklad nemá událost použití');
        }
        foreach (self::suppliers($pdo) as $supplier) {
            $guard = new DimensionPeriodGuard($db);
            $guard->transaction(function () use ($guard, $db, $supplier): void {
                $guard->lockPeriods($supplier);
                $ledger = new NonprofitTaxSavingsLedger($db);
                try {
                    foreach ($ledger->sources($supplier) as $source) {
                        $ledger->events($supplier, $source['id']);
                    }
                } catch (\Throwable $e) {
                    self::unsupported('evidence firmy ' . $supplier . ' neprošla ověřením (' . $e->getMessage() . ')');
                }
                self::assertPendingCommands($db, $supplier);
            });
        }
    }

    public static function run(Connection $db, TextScrubber $scrubber): void
    {
        self::assertClonePhase($db);
        $owner = new self($db, $scrubber);
        foreach (self::suppliers($db->pdo()) as $supplier) {
            $guard = new DimensionPeriodGuard($db);
            $guard->transaction(function () use ($guard, $owner, $supplier): void {
                $guard->lockPeriods($supplier);
                $owner->rewriteSupplier($supplier);
            });
        }
    }

    private function rewriteSupplier(int $supplier): void
    {
        $pdo = $this->db->pdo();
        $ledger = new NonprofitTaxSavingsLedger($this->db);
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $assessments = $container->get(NonprofitDppoAssessmentSource::class);
        $handled = [];
        $sources = $pdo->prepare('SELECT * FROM nonprofit_tax_saving_sources WHERE supplier_id = ? ORDER BY period_id, id FOR UPDATE');
        $sources->execute([$supplier]);
        foreach ($sources->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $sourceHash = $this->rewriteSource($ledger, $supplier, $source);
            $events = $pdo->prepare('SELECT * FROM nonprofit_tax_saving_events WHERE supplier_id = ? AND source_id = ? ORDER BY id FOR UPDATE');
            $events->execute([$supplier, (int) $source['id']]);
            $rows = $events->fetchAll(PDO::FETCH_ASSOC);
            $reversed = [];
            foreach ($rows as $row) {
                if ($row['operation'] === 'reverse_use') {
                    $reversed[(int) $row['original_id']] = true;
                }
            }
            $parent = $sourceHash;
            foreach ($rows as $row) {
                $parent = $this->rewriteEvent($assessments, $supplier, (int) $source['id'], $sourceHash, $parent, $row, isset($reversed[(int) $row['id']]));
                $handled[(int) $row['command_id']] = true;
            }
        }
        $this->rewritePendingCommands($supplier, $handled);
        foreach ($ledger->sources($supplier) as $source) {
            $ledger->events($supplier, $source['id']);
        }
    }

    private function rewriteSource(NonprofitTaxSavingsLedger $ledger, int $supplier, array $row): string
    {
        $old = json_decode((string) $row['basis_json'], true, 512, JSON_THROW_ON_ERROR);
        $basis = $ledger->finalBasis($supplier, (int) $row['snapshot_id']);
        if (NonprofitTaxRules::canonicalJson(self::basisProjection($old)) !== NonprofitTaxRules::canonicalJson(self::basisProjection($basis))) {
            throw new \LogicException('Základ daňové úspory se po přepisu archivu DPPO 504 liší.');
        }
        $record = [
            'supplier_id' => $supplier,
            'period_id' => (int) $row['period_id'],
            'snapshot_id' => (int) $row['snapshot_id'],
            'legal_profile_id' => (int) $row['legal_profile_id'],
            'reduction_kc' => (int) $row['reduction_kc'],
            'saving_cents' => (string) $row['saving_cents'],
            'basis' => $basis,
            'created_by' => (int) $row['created_by'],
        ];
        $hash = NonprofitTaxRules::hash($ledger->sourceRecord($record));
        $this->map((string) $row['record_hash'], $hash);
        $this->db->pdo()->prepare('UPDATE nonprofit_tax_saving_sources SET basis_json = ?, record_hash = ? WHERE supplier_id = ? AND id = ?')
            ->execute([NonprofitTaxRules::canonicalJson($basis), $hash, $supplier, (int) $row['id']]);

        return $hash;
    }

    private function rewriteEvent(NonprofitDppoAssessmentSource $assessments, int $supplier, int $sourceId, string $sourceHash, string $parent, array $row, bool $reversedLater): string
    {
        $pdo = $this->db->pdo();
        $command = $pdo->prepare('SELECT * FROM nonprofit_tax_saving_commands WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $command->execute([$supplier, (int) $row['command_id']]);
        $commandRow = $command->fetch(PDO::FETCH_ASSOC);
        if (!is_array($commandRow)) {
            throw new \LogicException('Událost daňové úspory nemá požadavek.');
        }
        $request = json_decode((string) $commandRow['request_json'], true, 512, JSON_THROW_ON_ERROR);
        $proof = json_decode((string) $row['proof_json'], true, 512, JSON_THROW_ON_ERROR);
        $receipt = json_decode((string) $row['receipt_json'], true, 512, JSON_THROW_ON_ERROR);
        $operation = (string) $row['operation'];
        $period = $row['period_id'] === null ? null : (int) $row['period_id'];
        $line = $row['cost_line_id'] === null ? null : (int) $row['cost_line_id'];
        $amount = (string) $row['amount_cents'];

        $newProof = match ($operation) {
            'use' => $this->useProof($assessments, $supplier, $proof, (int) $line, $reversedLater),
            'reverse_use' => [
                'original_id' => $proof['original_id'],
                'original_hash' => $this->mapped((string) $proof['original_hash']),
                'reason' => $this->scrubber->scrub((string) $proof['reason']),
            ],
            'void', 'restore' => [
                'source_hash' => $this->mapped((string) $proof['source_hash']),
                'snapshot_id' => $proof['snapshot_id'],
            ],
            default => throw new \LogicException('Neznámá operace evidence daňové úspory.'),
        };
        if (array_diff_key($proof, $newProof) !== []) {
            throw new \LogicException('Událost daňové úspory nese neznámá pole důkazu.');
        }

        $newRequest = $request;
        $newRequest['expected_head_hash'] = $parent;
        if ($operation === 'use') {
            $newRequest['input']['evidence'] = $newProof['evidence'];
        }
        if ($operation === 'reverse_use') {
            $newRequest['reason'] = $newProof['reason'];
        }
        if (in_array($operation, ['use', 'reverse_use'], true)) {
            $plan = ['operation' => $operation, 'source_id' => $sourceId, 'source_hash' => $sourceHash, 'head_hash' => $parent,
                'period_id' => $period, 'cost_line_id' => $line, 'amount_cents' => $amount, 'proof' => $newProof];
            $newRequest['preview_hash'] = NonprofitTaxRules::hash($plan);
        }
        if (in_array($operation, ['void', 'restore'], true) && is_string($request['reason'] ?? null)) {
            $newRequest['reason'] = $this->scrubber->scrub($request['reason']);
        }
        $key = 'clone-saving-' . (int) $commandRow['id'];
        $commandHash = NonprofitTaxRules::hash(['request' => $newRequest, 'idempotency_key' => $key, 'actor' => (int) $commandRow['created_by']]);
        $this->map((string) $commandRow['request_hash'], $commandHash);
        $pdo->prepare('UPDATE nonprofit_tax_saving_commands SET idempotency_key = ?, request_json = ?, request_hash = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$key, NonprofitTaxRules::canonicalJson($newRequest), $commandHash, $supplier, (int) $commandRow['id']]);

        $newReceipt = $receipt;
        $newReceipt['command_hash'] = $commandHash;
        $newReceipt['source_hash'] = $sourceHash;
        if (array_diff(array_keys($receipt), ['command_hash', 'source_hash', 'operation', 'amount_cents', 'cost_line_id', 'period_id']) !== []) {
            throw new \LogicException('Událost daňové úspory nese neznámá pole potvrzení.');
        }
        $event = [
            'supplier_id' => $supplier,
            'source_id' => $sourceId,
            'command_id' => (int) $row['command_id'],
            'previous_id' => $row['previous_id'] === null ? null : (int) $row['previous_id'],
            'original_id' => $row['original_id'] === null ? null : (int) $row['original_id'],
            'operation' => $operation,
            'period_id' => $period,
            'cost_line_id' => $line,
            'amount_cents' => $amount,
            'parent_hash' => $parent,
            'proof' => $newProof,
            'receipt' => $newReceipt,
        ];
        $hash = NonprofitTaxRules::hash((new NonprofitTaxSavingsLedger($this->db))->eventRecord($event));
        $this->map((string) $row['record_hash'], $hash);
        $pdo->prepare('UPDATE nonprofit_tax_saving_events SET parent_hash = ?, proof_json = ?, receipt_json = ?, record_hash = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$parent, NonprofitTaxRules::canonicalJson($newProof), NonprofitTaxRules::canonicalJson($newReceipt), $hash, $supplier, (int) $row['id']]);

        return $hash;
    }

    /**
     * Důkaz použití úspory: aktivní použití se opírá o dnešní nativní náklad kopie, zrušené
     * použití zůstává se zmrazeným důkazem z doby použití (jen pseudonymizovaným).
     */
    private function useProof(NonprofitDppoAssessmentSource $assessments, int $supplier, array $proof, int $line, bool $reversedLater): array
    {
        $old = $proof['assessment'] ?? null;
        if (!is_array($old) || !is_int($old['id'] ?? null)) {
            throw new \LogicException('Použití daňové úspory neodkazuje na posouzení J6.');
        }
        $view = $assessments->historicalView($supplier, $old['id']);
        $this->map((string) $old['input_hash'], (string) $view['input_hash']);
        $this->map((string) $old['request_hash'], (string) $view['request_hash']);
        $row = array_column($view['result']['rows'], null, 'line_id')[$line] ?? null;
        $legal = $view['snapshot']['legal_profile'] ?? null;
        if (!is_array($row) || !is_array($legal) || ($legal['id'] ?? null) !== ($proof['legal_profile']['id'] ?? null)) {
            throw new \LogicException('Použití daňové úspory neodpovídá přepsanému posouzení J6.');
        }
        $native = $reversedLater
            ? $this->text($proof['cost_native'])
            : (new NativeCostCoverage($this->db))->financingCost($supplier, $line);
        $capacity = $this->text($proof['cost_capacity']);
        foreach (['cost_cents', 'direct_cents', 'claimed_cents', 'available_cents'] as $key) {
            if (($capacity[$key] ?? null) !== ($proof['cost_capacity'][$key] ?? null)) {
                throw new \LogicException('Kapacita nákladu použití daňové úspory se při pseudonymizaci změnila.');
            }
        }

        return [
            'cost_native' => $native,
            'assessment' => ['id' => $view['id'], 'input_hash' => $view['input_hash'], 'request_hash' => $view['request_hash'], 'row' => $row],
            'legal_profile' => $legal,
            'purpose' => $proof['purpose'],
            'evidence' => trim($this->scrubber->scrub((string) $proof['evidence'])),
            'cost_capacity_hash' => NonprofitTaxRules::hash($capacity),
            'cost_capacity' => $capacity,
        ];
    }

    /**
     * Požadavky bez události (nedokončené) se jen pseudonymizují a převážou na nové otisky;
     * potvrdit je v kopii nejde, protože jejich náhled patřil zdrojové instalaci.
     *
     * @param array<int,true> $handled
     */
    private function rewritePendingCommands(int $supplier, array $handled): void
    {
        $pdo = $this->db->pdo();
        $commands = $pdo->prepare('SELECT * FROM nonprofit_tax_saving_commands WHERE supplier_id = ? AND id > ? ORDER BY id LIMIT ' . self::PAGE . ' FOR UPDATE');
        $update = $pdo->prepare('UPDATE nonprofit_tax_saving_commands SET idempotency_key = ?, request_json = ?, request_hash = ? WHERE supplier_id = ? AND id = ?');
        $last = 0;
        do {
            $commands->execute([$supplier, $last]);
            $rows = $commands->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if (isset($handled[$last])) {
                    continue;
                }
                $request = $this->text(json_decode((string) $row['request_json'], true, 512, JSON_THROW_ON_ERROR));
                foreach (['expected_head_hash', 'preview_hash'] as $key) {
                    if (is_string($request[$key] ?? null)) {
                        $request[$key] = $this->hashes[$request[$key]] ?? $request[$key];
                    }
                }
                $key = 'clone-saving-' . $last;
                $hash = NonprofitTaxRules::hash(['request' => $request, 'idempotency_key' => $key, 'actor' => (int) $row['created_by']]);
                $update->execute([$key, NonprofitTaxRules::canonicalJson($request), $hash, $supplier, $last]);
            }
        } while (count($rows) === self::PAGE);
    }

    private static function assertPendingCommands(Connection $db, int $supplier): void
    {
        $pdo = $db->pdo();
        $statement = $pdo->prepare(
            'SELECT c.id, c.request_json FROM nonprofit_tax_saving_commands c
              WHERE c.supplier_id = ? AND NOT EXISTS (
                    SELECT 1 FROM nonprofit_tax_saving_events e WHERE e.supplier_id = c.supplier_id AND e.command_id = c.id)
              ORDER BY c.id',
        );
        $statement->execute([$supplier]);
        $source = $pdo->prepare('SELECT 1 FROM nonprofit_tax_saving_sources WHERE supplier_id = ? AND id = ?');
        $ledger = new NonprofitTaxSavingsLedger($db);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            try {
                $ledger->command($supplier, (int) $row['id']);
            } catch (\Throwable) {
                self::unsupported('nedokončený požadavek ' . $row['id'] . ' nemá platný otisk');
            }
            $request = json_decode((string) $row['request_json'], true, 512, JSON_THROW_ON_ERROR);
            $source->execute([$supplier, (int) ($request['source_id'] ?? 0)]);
            if ($source->fetchColumn() === false) {
                self::unsupported('nedokončený požadavek ' . $row['id'] . ' odkazuje na neexistující zdroj úspory');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function basisProjection(array $basis): array
    {
        return [
            'snapshot_id' => $basis['snapshot_id'] ?? null,
            'tax_return_id' => $basis['tax_return_id'] ?? null,
            'finalized_by' => $basis['finalized_by'] ?? null,
            'period' => $basis['period'] ?? null,
            'legal_profile_id' => $basis['legal_profile']['id'] ?? null,
            'legal_profile_kind' => $basis['legal_profile']['profile']['kind'] ?? null,
            'reduction_band' => $basis['legal_profile']['profile']['reduction_band'] ?? null,
            'saving' => $basis['saving'] ?? null,
        ];
    }

    /** @return list<int> */
    private static function suppliers(PDO $pdo): array
    {
        return array_map(intval(...), $pdo->query(
            'SELECT supplier_id FROM nonprofit_tax_saving_sources
             UNION SELECT supplier_id FROM nonprofit_tax_saving_commands
             ORDER BY supplier_id',
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function text(array $value): array
    {
        return NonprofitDppoCloneScrubber::transform($this->scrubber, $value, $this->hashes);
    }

    private function mapped(string $old): string
    {
        return $this->hashes[$old] ?? throw new \LogicException('Událost daňové úspory odkazuje na otisk mimo přepsaný řetěz.');
    }

    private function map(string $old, string $new): void
    {
        if (isset($this->hashes[$old]) && $this->hashes[$old] !== $new) {
            throw new \LogicException('Evidence daňové úspory má v kopii dvě různé náhrady téhož otisku.');
        }
        $this->hashes[$old] = $new;
    }

    private static function assertClonePhase(Connection $db): void
    {
        $pdo = $db->pdo();
        if ($pdo->inTransaction()
            || TriggerMetadata::any($pdo)) {
            throw new \LogicException('Kopie evidence daňové úspory musí proběhnout před triggery a mimo transakci.');
        }
    }

    private static function unsupported(string $reason): never
    {
        throw new \RuntimeException('Anonymizace evidence daňových úspor DPPO 504 není podporována: ' . $reason . '. Zdrojová databáze zůstala zachovaná.');
    }
}
