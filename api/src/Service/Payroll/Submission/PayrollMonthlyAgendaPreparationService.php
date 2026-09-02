<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;

/**
 * PŘÍPRAVA MĚSÍČNÍ POVINNOSTI NA JEDNO KLIKNUTÍ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to není „uzávěrka podání předvytvoří"
 * ═══════════════════════════════════════════════════════════════════════════
 * Podání je dokument se zmrazeným artefaktem a spisovou značkou. Kdyby ho
 * zakládala uzávěrka, vznikaly by koncepty, které se musí rušit pokaždé, když
 * se v běhu cokoliv doopraví. Povinnost proto v přehledu žije bez dokumentu
 * ({@see PayrollMonthlyAgendaDutyService}) a dokument vzniká až tehdy, když
 * o něj někdo POŽÁDÁ — jenže bez toho, aby si musel sám najít správnou
 * obrazovku, správnou revizi a správnou účtárnu. Pohodlí předvytvoření bez
 * jeho ceny.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Vlastní logika tu není žádná
 * ═══════════════════════════════════════════════════════════════════════════
 * Volají se TYTÉŽ služby, které používají obrazovky agend
 * ({@see JmhzPreparationSnapshotService} + {@see JmhzSubmissionBridgeService},
 * {@see HealthInsuranceSubmissionService::preparePaymentOverview()}), včetně
 * jejich kontrol a idempotence. Druhá cesta k témuž dokumentu by dřív nebo
 * později vyrobila jiný dokument.
 */
final readonly class PayrollMonthlyAgendaPreparationService
{
    /**
     * Klíč přípravy JMHZ je ODVOZENÝ, ne náhodný: opakované kliknutí na tutéž
     * povinnost musí vrátit tentýž zmrazený podklad, ne druhý vedle prvního.
     */
    private const JMHZ_PREPARATION_KEY_PREFIX = 'payroll-monthly-checklist-jmhz';

    public function __construct(
        private PayrollMonthlyAgendaDutyService $duties,
        private JmhzPreparationSnapshotService $jmhzPreparations,
        private JmhzSubmissionBridgeService $jmhzBridge,
        private JmhzPvpojPreviewService $jmhzOffices,
        private HealthInsuranceSubmissionService $health,
    ) {}

    /**
     * @return array{
     *   agenda_code:string,period:string,insurer_code:?string,
     *   prepared:int,submission_ids:list<int>,path:string
     * }
     */
    public function prepare(
        int $supplierId,
        string $environment,
        string $period,
        string $agendaCode,
        ?string $insurerCode,
        ?int $userId,
    ): array {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přípravy měsíční povinnosti musí být production nebo test.',
            );
        }
        $duty = $this->duty($supplierId, $period, $agendaCode, $insurerCode);

        return match ($agendaCode) {
            JmhzSubmissionBridgeService::AGENDA_CODE => $this->prepareJmhz(
                $supplierId,
                $environment,
                $period,
                (int) $duty['revision_id'],
                $userId,
            ),
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW =>
                $this->prepareHealthPaymentOverview(
                    $supplierId,
                    $environment,
                    $period,
                    (int) $duty['revision_id'],
                    (string) $duty['insurer_code'],
                    $userId,
                ),
            default => throw new \InvalidArgumentException(
                'Agendu ' . $agendaCode . ' měsíční přehled připravit neumí.',
            ),
        };
    }

    /**
     * Hlášení se podává za KAŽDOU registraci u OSSZ, kterou běh má — přehled
     * o něm mluví jako o jedné povinnosti, ale splnit ji znamená zmrazit
     * podání za všechny účtárny. Připravit jen jednu a tvářit se, že je
     * hotovo, by bylo horší než nepřipravit nic.
     *
     * Účtárna bez použitelného variabilního symbolu se přeskočit NESMÍ tiše:
     * kdyby na ni podání chybělo, přehled by ji už znovu nenabídl (povinnost
     * by z pramene agendových povinností vypadla kvůli sesterskému řádku).
     *
     * @return array{
     *   agenda_code:string,period:string,insurer_code:?string,
     *   prepared:int,submission_ids:list<int>,path:string
     * }
     */
    private function prepareJmhz(
        int $supplierId,
        string $environment,
        string $period,
        int $revisionId,
        ?int $userId,
    ): array {
        $preparation = $this->jmhzPreparations->freeze(
            $supplierId,
            $revisionId,
            $environment,
            self::JMHZ_PREPARATION_KEY_PREFIX . ':' . $environment . ':' . $revisionId,
            $userId,
        );
        $offices = $this->jmhzOffices->offices($supplierId, $revisionId);
        $blocked = array_values(array_filter(
            $offices,
            static fn (array $office): bool => ($office['submittable'] ?? false) !== true,
        ));
        if ($blocked !== []) {
            throw new \DomainException(
                'Mzdová účtárna '
                . implode(', ', array_map(
                    static fn (array $office): string => (string) $office['name'],
                    $blocked,
                ))
                . ' nemá desetimístný variabilní symbol u ČSSZ, takže za ni '
                . 'hlášení zmrazit nejde. Doplňte ho v Mzdy → Nastavení '
                . 'zaměstnavatele → Mzdové účtárny.',
            );
        }

        $submissionIds = [];
        // Běh bez účtárny (jednoúčtárenská firma) drží původní tvar reference
        // `payroll_run:{runId}` — proto se jedno podání zmrazí i s `null`.
        foreach ($offices === [] ? [null] : $offices as $office) {
            $frozen = $this->jmhzBridge->bridge(
                $supplierId,
                (int) $preparation['id'],
                null,
                $environment,
                $userId,
                $office === null ? null : (int) $office['office_id'],
            );
            $submissionIds[] = (int) $frozen['submission_id'];
        }

        return [
            'agenda_code' => JmhzSubmissionBridgeService::AGENDA_CODE,
            'period' => $period,
            'insurer_code' => null,
            'prepared' => count($submissionIds),
            'submission_ids' => $submissionIds,
            'path' => '/payroll/submissions/jmhz',
        ];
    }

    /**
     * @return array{
     *   agenda_code:string,period:string,insurer_code:?string,
     *   prepared:int,submission_ids:list<int>,path:string
     * }
     */
    private function prepareHealthPaymentOverview(
        int $supplierId,
        string $environment,
        string $period,
        int $revisionId,
        string $insurerCode,
        ?int $userId,
    ): array {
        $prepared = $this->health->preparePaymentOverview(
            $supplierId,
            $environment,
            $revisionId,
            $insurerCode,
            $userId,
        );

        return [
            'agenda_code' => HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'period' => $period,
            'insurer_code' => $insurerCode,
            'prepared' => 1,
            'submission_ids' => [(int) $prepared['submission_id']],
            'path' => '/payroll/submissions/health',
        ];
    }

    /**
     * Povinnost se hledá v TÉMŽE katalogu, ze kterého ji přehled vypsal —
     * klient tedy nemůže poslat revizi ani běh, na které se přehled nikdy
     * neodkázal.
     *
     * @return array<string,mixed>
     */
    private function duty(
        int $supplierId,
        string $period,
        string $agendaCode,
        ?string $insurerCode,
    ): array {
        foreach ($this->duties->all($supplierId, $period) as $duty) {
            if ((string) $duty['agenda_code'] === $agendaCode
                && $duty['insurer_code'] === $insurerCode
            ) {
                return $duty;
            }
        }

        throw new \DomainException(
            'Za období ' . $period . ' žádná taková nepřipravená povinnost '
            . 'není — mzdový běh nemusí být schválený, nebo je podání už '
            . 'založené. Načtěte přehled znovu.',
        );
    }
}
