<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use PDO;

/**
 * Převod starších zaúčtovaných zápisů na dvojice, s náhledem nanečisto.
 *
 *  - běh = řádek `journal_pair_conversion_runs` (mode preview | apply); služba ho
 *    založí a spustí worker `api/bin/journal-pair-conversion-worker.php`, výpočet
 *    i klasifikaci drží {@see JournalPairConversionJob} (po dávkách, nikdy celý rok
 *    v jednom PHP poli);
 *  - klasifikace: `paired`, `auto`, `composite_mn`, `ineligible` s důvodem
 *    (viz {@see JournalPairConversionJob});
 *  - `apply` fyzicky rozdělí jen `auto` zápisy v otevřeném nezamčeném období s formou
 *    jednoduchého zápisu k datu zápisu, přes JournalEntryRepository::splitIntoPairs()
 *    (jedna transakce na zápis, žádné DELETE řádků); ostatním zapíše
 *    `journal_entry_pair_status`;
 *  - náhled nic v deníku nemění; jeho id jde do auditu změny formy
 *    (`conversion_preview_id`).
 *
 * Firma smí mít nejvýš jeden čekající nebo běžící převod (apply); náhledů může být víc.
 * Chyby hlásí přes {@see JournalPairException} (kód + HTTP status).
 */
final class JournalPairConversionService
{
    public const AUDIT_STARTED = 'accounting.pair_conversion_started';
    public const AUDIT_CANCELLED = 'accounting.pair_conversion_cancelled';

    /** @var \Closure(int):(?string) spustí worker; vrací null, nebo popis chyby */
    private readonly \Closure $launcher;

    /**
     * @param (\Closure(int):(?string))|null $launcher náhrada spuštění workeru (testy, CLI);
     *        null = podproces na pozadí
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        ?\Closure $launcher = null,
    ) {
        $this->launcher = $launcher ?? static function (int $runId): ?string {
            $root = Bootstrap::rootDir();
            $diag = null;
            $spawned = BackgroundProcess::spawnPhp(
                $root . '/api/bin/journal-pair-conversion-worker.php',
                ['--run-id=' . $runId],
                RuntimePaths::log('journal-pair-conversion-worker.log'),
                $root,
                $diag,
            );
            return $spawned ? null : ($diag ?? 'neznámá chyba');
        };
    }

    /**
     * Založí běh náhledu nanečisto pro okno dat (NULL = bez omezení) a spustí ho na pozadí.
     *
     * @return int id běhu
     * @throws JournalPairException
     */
    public function preview(int $supplierId, ?string $from, ?string $to, int $actor): int
    {
        return $this->start($supplierId, 'preview', $from, $to, $actor);
    }

    /**
     * Založí běh převodu (fyzické rozdělení způsobilých zápisů) a spustí ho na pozadí.
     *
     * @return int id běhu
     * @throws JournalPairException
     */
    public function apply(int $supplierId, ?string $from, ?string $to, int $actor): int
    {
        return $this->start($supplierId, 'apply', $from, $to, $actor);
    }

    /**
     * Stav jednoho běhu pro polling; null = běh firmě nepatří nebo neexistuje.
     *
     * @return array{id:int, mode:string, status:string, date_from:?string, date_to:?string,
     *               counts:?array<string,mixed>, sample:?array<int,mixed>, error:?string,
     *               created_by:?int, created_at:string, finished_at:?string}|null
     */
    public function run(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, mode, status, date_from, date_to, counts, sample, error, created_by, created_at, finished_at
               FROM journal_pair_conversion_runs WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * Poslední běhy firmy (nejnovější první).
     *
     * @return list<array<string,mixed>> tvar jako {@see run()}
     */
    public function runs(int $supplierId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, mode, status, date_from, date_to, counts, sample, error, created_by, created_at, finished_at
               FROM journal_pair_conversion_runs WHERE supplier_id = ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$supplierId]);
        return array_map(self::shape(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zruší čekající nebo běžící běh. Už rozdělené zápisy zůstávají (každý zápis má
     * vlastní transakci); worker skončí po dokončení rozpracované dávky.
     *
     * @return bool false = běh už skončil
     * @throws JournalPairException
     */
    public function cancel(int $supplierId, int $runId, int $actor): bool
    {
        if ($this->run($supplierId, $runId) === null) {
            throw new JournalPairException('not_found', 'Běh převodu nenalezen.', 404);
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE journal_pair_conversion_runs SET status = 'cancelled', finished_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status IN ('queued', 'running')"
        );
        $stmt->execute([$supplierId, $runId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->logger->log(self::AUDIT_CANCELLED, $actor > 0 ? $actor : null, 'supplier', $supplierId,
            ['run_id' => $runId], supplierId: $supplierId);
        return true;
    }

    private function start(int $supplierId, string $mode, ?string $from, ?string $to, int $actor): int
    {
        foreach (['date_from' => $from, 'date_to' => $to] as $key => $value) {
            if ($value !== null && !IsoDate::isValid($value)) {
                throw new JournalPairException('validation_failed', $key . ' musí být datum (YYYY-MM-DD).', 422);
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new JournalPairException('validation_failed', 'date_from nesmí být po date_to.', 422);
        }
        $pdo = $this->db->pdo();
        $modeStmt = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $modeStmt->execute([$supplierId]);
        if ($modeStmt->fetchColumn() !== 'double_entry') {
            throw new JournalPairException('accounting_not_double_entry', 'Převod na dvojice je jen pro podvojné účetnictví.', 409);
        }
        if ($mode === 'apply') {
            $active = $pdo->prepare(
                "SELECT id FROM journal_pair_conversion_runs
                  WHERE supplier_id = ? AND mode = 'apply' AND status IN ('queued', 'running') LIMIT 1"
            );
            $active->execute([$supplierId]);
            $running = $active->fetchColumn();
            if ($running !== false) {
                throw new JournalPairException('conversion_in_progress', 'Převod zápisů na dvojice už běží.', 409,
                    ['run_id' => (int) $running]);
            }
        }

        $pdo->prepare(
            'INSERT INTO journal_pair_conversion_runs (supplier_id, mode, date_from, date_to, created_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $mode, $from, $to, $actor > 0 ? $actor : null]);
        $runId = (int) $pdo->lastInsertId();
        if ($mode === 'apply') {
            $this->logger->log(self::AUDIT_STARTED, $actor > 0 ? $actor : null, 'supplier', $supplierId,
                ['run_id' => $runId, 'date_from' => $from, 'date_to' => $to], supplierId: $supplierId);
        }

        $failure = ($this->launcher)($runId);
        if ($failure !== null) {
            $pdo->prepare(
                "UPDATE journal_pair_conversion_runs SET status = 'failed', error = ?, finished_at = NOW()
                  WHERE id = ? AND status = 'queued'"
            )->execute([mb_substr('Worker se nepodařilo spustit: ' . $failure, 0, 255), $runId]);
            throw new JournalPairException('worker_start_failed', 'Převod se nepodařilo spustit. Zkuste to znovu.', 500,
                ['run_id' => $runId]);
        }
        return $runId;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shape(array $row): array
    {
        $decode = static function (mixed $json): ?array {
            if (!is_string($json) || $json === '') {
                return null;
            }
            $value = json_decode($json, true);
            return is_array($value) ? $value : null;
        };
        return [
            'id'          => (int) $row['id'],
            'mode'        => (string) $row['mode'],
            'status'      => (string) $row['status'],
            'date_from'   => $row['date_from'] !== null ? (string) $row['date_from'] : null,
            'date_to'     => $row['date_to'] !== null ? (string) $row['date_to'] : null,
            'counts'      => $decode($row['counts']),
            'sample'      => $decode($row['sample']),
            'error'       => $row['error'] !== null ? (string) $row['error'] : null,
            'created_by'  => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at'  => (string) $row['created_at'],
            'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        ];
    }
}
