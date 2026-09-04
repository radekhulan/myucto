<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolReport;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;

/**
 * Uzavření MĚSÍCE podle protokolu o zpracování.
 *
 * ── Proč to nejde odvodit ze stavu jednoho podání ───────────────────────────
 * Měsíční hlášení není jedno podání, ale řetězec: řádné, k němu opravy, případně
 * storno. Obsahová oprava JMHZ řádné podání ZÁMĚRNĚ nenahrazuje — přijaté
 * formuláře řádného podání zůstávají zaevidované a oprava jen doplní nebo
 * opraví jednotlivé součásti (viz {@see \MyInvoice\Service\Payroll\Submission\PayrollAgendaCorrectionPolicy}).
 * Řádné podání proto navždy zůstane ve stavu, jaký mu ČSSZ dala; za 08/2026
 * „částečně přijato".
 *
 * Kdyby se z toho odvozoval stav povinnosti, hlásila by aplikace „je nutný
 * zásah" i po tom, co účetní opravu podala a ČSSZ potvrdila, že je hlášení
 * úplné. To je nejhorší možná lež: nutí člověka řešit něco, co je hotové, a
 * tím ho učí ta upozornění ignorovat.
 *
 * ── Čím se to tedy pozná ────────────────────────────────────────────────────
 * ČSSZ stav MĚSÍCE říká sama a explicitně: `stavMH` v protokolu o zpracování.
 * Kód 1 „zpracováno a je úplné" a kód 6 „obsahuje propustné chyby" (podle
 * pravidel podání přijaté hlášení, chyby zůstávají jen v protokolu) znamenají,
 * že za období není co dodávat. Nic se tu neodhaduje ani nedopočítává —
 * přebírá se výrok protistrany.
 *
 * Povinnosti se JEN uzavírají. Zrušená povinnost ani povinnost už splněná se
 * nepřepisuje a stav podání se nemění vůbec: `partially_accepted` je pravda
 * o tom podání a pravdou zůstane.
 */
final readonly class JmhzMonthCompletionService
{
    /** Stavy povinnosti, které smí uzavřít doložený výrok ČSSZ. */
    private const CLOSABLE = ['open', 'prepared', 'submitted', 'overdue', 'manual_review'];

    public function __construct(
        private PayrollSubmissionRepository $repository,
    ) {}

    /**
     * Uzavře povinnosti celého řetězce, pokud protokol hlásí úplný měsíc.
     *
     * @return list<int> id povinností, které se tím uzavřely
     */
    public function apply(
        int $supplierId,
        string $environment,
        int $submissionId,
        JmhzProtocolReport $report,
    ): array {
        if ($report->kind === JmhzProtocolKind::PartialSubmission) {
            // Obálka GovTalk odpovídá na PŘÍJEM podání, ne na zpracování
            // měsíce. Uzavřít podle ní povinnost by znamenalo prohlásit za
            // hotové hlášení, o kterém cJMHZ ještě nerozhodla.
            return [];
        }
        if (!in_array(
            $report->status,
            [
                JmhzSubmissionStatus::ProcessedAndComplete,
                JmhzSubmissionStatus::ContainsPassableErrors,
            ],
            true,
        )) {
            return [];
        }

        $closed = [];
        foreach ($this->chain($supplierId, $environment, $submissionId) as $memberId) {
            $obligation = $this->repository->findObligationOfSubmission(
                $supplierId,
                $environment,
                $memberId,
            );
            if ($obligation === null
                || !in_array((string) $obligation['status'], self::CLOSABLE, true)
            ) {
                continue;
            }
            $this->repository->updateObligationStatus(
                $supplierId,
                $environment,
                (int) $obligation['id'],
                (int) $obligation['row_version'],
                'fulfilled',
            );
            $closed[] = (int) $obligation['id'];
        }

        return $closed;
    }

    /**
     * Celý řetězec za období: řádné podání a všechno, co se na něj váže.
     *
     * Protokol může dorazit k opravě stejně jako k řádnému podání, takže se
     * nejdřív najde kořen a teprve od něj řetězec.
     *
     * @return list<int>
     */
    private function chain(int $supplierId, string $environment, int $submissionId): array
    {
        $submission = $this->repository->findSubmission($supplierId, $submissionId);
        if ($submission === null || (string) $submission['environment'] !== $environment) {
            return [];
        }
        $rootId = $submission['corrects_submission_id'] === null
            ? $submissionId
            : (int) $submission['corrects_submission_id'];

        $ids = [];
        foreach ($this->repository->jmhzChainForRoot($supplierId, $environment, $rootId) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids === [] ? [$submissionId] : $ids;
    }
}
