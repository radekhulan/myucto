<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunKind;
use PDO;

/**
 * Na kterém mzdovém měsíci firma právě pracuje.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Mzdové obrazovky se z menu otevíraly na předchozím kalendářním měsíci.
 * Firma, která v listopadu teprve zpracovává únor, tak pokaždé přistála na
 * říjnu, kde nemá co dělat, a měsíc přepínala ručně na každé stránce zvlášť.
 * Rozpracovaný měsíc přitom aplikace zná: je to první měsíc od začátku vedení
 * mezd, který ještě nemá schválenou mzdu.
 *
 * Měsíc je ZPRACOVANÝ, když má aspoň jeden nezrušený běh a každý jeho nezrušený
 * běh je schválený nebo dál ({@see PayrollClosedRunGuard::FINISHED_STATUSES}),
 * případně převzatý z předchozího programu. Běh otevřený k opravě měsíc znovu
 * otevírá. Běhy jsou po mzdových účtárnách, proto „každý", ne „některý".
 */
final class PayrollWorkingPeriodResolver
{
    /** Strop průchodu měsíci od začátku vedení mezd; delší řada je chyba dat. */
    private const MAX_MONTHS = 240;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param string $today dnešek `Y-m-d`
     * @return array{first_open:?string,last_processed:?string} měsíce `RRRR-MM`;
     *     `first_open` je první nezpracovaný měsíc od začátku vedení mezd (nejdál
     *     měsíc následující po dnešním), `last_processed` poslední zpracovaný.
     *     Bez začátku vedení mezd je obojí `null` a platí výchozí chování obrazovek.
     */
    public function resolve(int $supplierId, string $today): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $start = $stmt->fetchColumn();
        $todayDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if (!is_string($start) || $start === '' || $todayDate === false) {
            return ['first_open' => null, 'last_processed' => null];
        }
        $cursor = new \DateTimeImmutable(substr($start, 0, 7) . '-01');

        $finished = PayrollClosedRunGuard::FINISHED_STATUSES;
        $runs = $this->db->pdo()->prepare(
            'SELECT period_start,
                    SUM(run_kind <> ? AND status NOT IN (' . implode(',', array_fill(0, count($finished), '?')) . ')) AS open_runs
               FROM payroll_runs
              WHERE supplier_id = ?
                AND period_start >= ?
                AND status <> "cancelled"
              GROUP BY period_start'
        );
        $runs->execute([PayrollRunKind::TAKEOVER->value, ...$finished, $supplierId, $cursor->format('Y-m-d')]);
        $processed = [];
        foreach ($runs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['open_runs'] === 0) {
                $processed[substr((string) $row['period_start'], 0, 7)] = true;
            }
        }

        // Dál než na měsíc po dnešním se nechodí: i firma, která vyplácí
        // předem, pracuje nanejvýš na příštím měsíci.
        $limit = $todayDate->modify('first day of next month');
        if ($cursor > $limit) {
            // Mzdy se v aplikaci povedou až od pozdějšího měsíce.
            return ['first_open' => $cursor->format('Y-m'), 'last_processed' => null];
        }
        $firstOpen = null;
        for ($step = 0; $step < self::MAX_MONTHS && $cursor <= $limit; $step++) {
            if (!isset($processed[$cursor->format('Y-m')])) {
                $firstOpen = $cursor->format('Y-m');
                break;
            }
            $cursor = $cursor->modify('+1 month');
        }
        $lastProcessed = $processed === [] ? null : max(array_keys($processed));

        return [
            'first_open' => $firstOpen ?? $limit->format('Y-m'),
            'last_processed' => $lastProcessed,
        ];
    }
}
