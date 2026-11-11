<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Souhrn pracovního měsíce z dávky importu docházky.
 *
 * Dávka nese hodiny jen jako řádky evidence; pracovní měsíc vztahu o nich
 * dosud nevěděl. Tady se z řádků dávky stane neměnný souhrn k měsíci, včetně
 * původu každého čísla (soubor!list!buňka a otisk souboru). Bere se jen to,
 * co podklady opravdu nesou: měsíční součty hodin. Dny ani data absencí
 * v podkladech nejsou a nevymýšlejí se.
 *
 * Každý vztah se zapisuje ve vlastní transakci (nebo savepointu): chyba
 * jednoho — schválený měsíc, časové záznamy jako druhý zdroj — vrátí jen jeho
 * zápis včetně kalendáře, ostatní vztahy dávky projdou.
 *
 * Měsíce se tu NEschvalují. Pracovní souhrn JMHZ z nich staví
 * {@see PayrollJmhzWorkMonthSummaryBuilder} (verze v6) a čisté měsíce dávky
 * schvaluje {@see PayrollTimeImportApprovalService}.
 */
final class PayrollTimeImportSummaryWriter
{
    public function __construct(
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollEmploymentCalendarProvisioner $calendars,
        private readonly Connection $db,
    ) {}

    /**
     * @return array{
     *   written:int,
     *   replayed:int,
     *   calendars_created:int,
     *   exceptions:list<array{employment_id:int,message:string}>,
     *   warnings:list<array{employment_id:int,code:string,message:string}>
     * }
     */
    public function writeFromBatch(int $supplierId, int $importId, ?int $userId): array
    {
        $batch = $this->imports->batch($supplierId, $importId);
        if ($batch === null || $batch['source_system'] !== AttendanceMeaning::SOURCE_SYSTEM) {
            throw new \InvalidArgumentException('Dávka importu docházky nebyla nalezena.');
        }
        $periodStart = $batch['period'] . '-01';
        $fileHashes = [];
        foreach ($batch['files'] as $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && is_string($file['sha256'] ?? null)) {
                $fileHashes[$file['name']] = $file['sha256'];
            }
        }

        /** @var array<int,array{values:array<string,int>,sources:array<string,array{ref:string,file_sha256:?string}>}> $summaries */
        $summaries = [];
        foreach ($this->imports->batchRows($supplierId, $importId) as $row) {
            $meaning = (string) $row['meaning'];
            if (!in_array($meaning, AttendanceMeaning::HOURS, true) || $row['quantity_millihours'] === null) {
                continue;
            }
            $employmentId = (int) $row['employment_id'];
            $summaries[$employmentId]['values'][$meaning] = (int) $row['quantity_millihours'];
            $summaries[$employmentId]['sources'][$meaning] = [
                'ref' => (string) $row['source'],
                'file_sha256' => self::fileHash((string) $row['source'], $fileHashes),
            ];
        }
        ksort($summaries);

        $result = ['written' => 0, 'replayed' => 0, 'calendars_created' => 0, 'exceptions' => [], 'warnings' => []];
        foreach ($summaries as $employmentId => $summary) {
            $values = $summary['values'];
            ksort($values);
            $sources = $summary['sources'];
            ksort($sources);
            $content = hash('sha256', CanonicalJson::encode([
                'values' => $values,
                'worked_days' => null,
                'sources' => $sources,
            ]));

            try {
                [$calendar, $saved] = $this->transactional(fn (): array => [
                    $this->calendars->ensureForPeriod($supplierId, $employmentId, $periodStart, $userId),
                    $this->time->saveImportSummary(
                        $supplierId,
                        $employmentId,
                        $periodStart,
                        $importId,
                        $values,
                        $sources,
                        $content,
                        $userId,
                    ),
                ]);
            } catch (PayrollTimeLockedException|PayrollTimeConflictException|\InvalidArgumentException|\DomainException $e) {
                $result['exceptions'][] = ['employment_id' => $employmentId, 'message' => $e->getMessage()];
                continue;
            }

            if ($calendar['created']) {
                ++$result['calendars_created'];
            }
            if ($calendar['issue'] !== null) {
                $result['warnings'][] = [
                    'employment_id' => $employmentId,
                    'code' => $calendar['issue'],
                    'message' => PayrollEmploymentCalendarProvisioner::issueMessage($calendar['issue']),
                ];
            }
            $saved['status'] === 'replayed' ? ++$result['replayed'] : ++$result['written'];
            if (isset($values['fund_hours'])) {
                $check = $this->calendars->fundCheck($supplierId, $employmentId, $periodStart, $values['fund_hours']);
                if ($check !== null) {
                    $result['warnings'][] = [
                        'employment_id' => $employmentId,
                        'code' => $check['code'],
                        'message' => $check['message'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Otisk souboru, ze kterého hodnota pochází. Odkaz má tvar
     * `soubor!list!buňka`; při shodě víc názvů vyhraje nejdelší.
     *
     * @param array<string,string> $fileHashes
     */
    private static function fileHash(string $source, array $fileHashes): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach ($fileHashes as $name => $sha256) {
            $name = (string) $name;
            if (str_starts_with($source, $name . '!') && strlen($name) > $bestLength) {
                $best = $sha256;
                $bestLength = strlen($name);
            }
        }

        return $best;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_time_import_summary');
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_time_import_summary');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_time_import_summary');
                $pdo->exec('RELEASE SAVEPOINT payroll_time_import_summary');
            }
            throw $e;
        }
    }
}
