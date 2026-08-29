<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Do kdy je mzdová historie zmrazená schválenou revizí.
 *
 * Pravidlo vzniklo u nároku na daňové zvýhodnění (`PayrollDependantRepository`)
 * jako privátní helper. Zákonná evidence osoby ho potřebuje na totéž — a druhá
 * kopie téhož SELECTu je přesně ta třída chyby, kterou AGENTS.md zakazuje:
 * jedna větev by se opravila a druhá ne. Proto je pravidlo tady, volatelné.
 *
 * Hranicí je KONEC měsíce posledního schváleného období, ne jeho začátek —
 * schválená mzda pokrývá celý měsíc, takže zásah kamkoliv do něj by tiše měnil
 * podklad už vyplacené mzdy.
 *
 * Běh OTEVŘENÝ K OPRAVĚ (`correction_pending`, `reopened`) se do hranice
 * nepočítá. Jeho schválená revize je pořád ve stavu `approved` — na
 * `superseded` ji přepne teprve schválení revize opravné — takže bez téhle
 * výjimky by účetní otevřela mzdu k opravě a evidence by jí zůstala zamčená,
 * tedy přesně to, co chtěla opravit, by opravit nešlo.
 *
 * Hranice je jediné datum, ne stav per měsíc: je-li otevřený starší běh, ale
 * novější zůstává schválený, drží zámek dál ten novější. Je to záměr — měnit
 * podklad srpna pod schváleným zářím by rozbilo i září.
 */
final class PayrollApprovedPeriodFreeze
{
    public function __construct(private readonly Connection $db) {}

    /** @return string|null poslední zmrazený den (Y-m-d), null = nic schváleného */
    public function frozenThrough(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT MAX(run.period_start)
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.status = 'approved'
                AND run.status NOT IN ('correction_pending', 'reopened')"
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }
}
