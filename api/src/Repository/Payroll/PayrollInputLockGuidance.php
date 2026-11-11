<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use PDO;

/**
 * Proč mzdový vstup nejde přepsat nebo vrátit do konceptu a co s tím.
 *
 * Schválený a uzamčený vstup jsou dvě různé situace s jiným dalším krokem:
 * schválený vrátí do konceptu uživatel s právem schvalovat, uzamčený drží
 * mzdový běh a uvolní ho až zrušení běhu nebo opravná revize. Jedna společná
 * věta „schválený nebo uzamčený" neříkala, kterou cestou jít.
 */
final class PayrollInputLockGuidance
{
    /** Popisky stavů běhu jako v UI (`payroll.runs.status`). */
    private const RUN_STATUS_LABELS = [
        'draft' => 'Koncept',
        'inputs_locked' => 'Vstupy uzamčeny',
        'calculated' => 'Spočítáno',
        'reviewed' => 'Zkontrolováno',
        'approved' => 'Schváleno',
        'posted' => 'Zaúčtováno',
        'payment_ready' => 'Platby připraveny',
        'paid' => 'Uhrazeno',
        'closed' => 'Uzavřeno',
        'correction_pending' => 'Čeká na opravu',
        'reopened' => 'Oprava otevřena',
        'cancelled' => 'Zrušeno',
    ];

    /** Stavy, ve kterých běh jde zrušit a začít znovu z opravených vstupů. */
    private const CANCELLABLE = ['draft', 'inputs_locked', 'calculated', 'reviewed'];

    /**
     * @param string $action co se nepovedlo, např. „přepsat rychlým formulářem"
     */
    public static function message(
        PDO $pdo,
        int $supplierId,
        string $inputStatus,
        string $periodStart,
        string $action,
    ): string {
        $period = self::period($periodStart);
        if ($inputStatus === 'approved') {
            return sprintf(
                'Mzdový vstup za %s je už schválený, proto ho nelze %s. Vraťte ho v Mzdy → '
                    . 'Mzdové složky → Měsíční vstupy do konceptu (smí uživatel s právem schvalovat '
                    . 'vstupy), opravte ho a znovu schvalte.',
                $period,
                $action,
            );
        }
        if ($inputStatus === 'cancelled') {
            return sprintf(
                'Mzdový vstup za %s je zrušený, proto ho nelze %s. Zadejte nový vstup.',
                $period,
                $action,
            );
        }

        $run = self::run($pdo, $supplierId, $periodStart);
        if ($run === null) {
            return sprintf(
                'Mzdový vstup za %s je uzamčený mzdovým během, proto ho nelze %s. Běh za toto '
                    . 'období se nepodařilo dohledat; předejte to správci aplikace.',
                $period,
                $action,
            );
        }
        $status = (string) $run['status'];
        $label = self::RUN_STATUS_LABELS[$status] ?? $status;
        $next = in_array($status, self::CANCELLABLE, true)
            ? 'V Mzdy → Mzdové běhy otevřete běh a klikněte na „Zrušit běh“ (vstupy ani docházka '
                . 'se nesmažou). Vstup pak opravte a v běhu zvolte „Začít z opravených vstupů“.'
            : 'Běh je schválený, oprava jde jen opravnou revizí: v Mzdy → Mzdové běhy otevřete '
                . 'běh, zvolte „Vyžádat opravu“ a potom „Otevřít opravu“.';

        return sprintf(
            'Mzdový vstup za %s je uzamčený v mzdovém běhu č. %d (stav: %s), proto ho nelze %s. %s',
            $period,
            (int) $run['id'],
            $label,
            $action,
            $next,
        );
    }

    /** @return array{id:int|string,status:string}|null */
    private static function run(PDO $pdo, int $supplierId, string $periodStart): ?array
    {
        $statement = $pdo->prepare(
            "SELECT id, status
               FROM payroll_runs
              WHERE supplier_id = ? AND period_start = ? AND status <> 'cancelled'
              ORDER BY id DESC
              LIMIT 1"
        );
        $statement->execute([$supplierId, substr($periodStart, 0, 10)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function period(string $periodStart): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($periodStart, 0, 10));

        return $date === false ? $periodStart : $date->format('m/Y');
    }
}
