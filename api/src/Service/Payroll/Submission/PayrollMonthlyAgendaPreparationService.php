<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationGate;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

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
        private JmhzPreparationGate $jmhzGate,
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
        bool $lateDiscountConfirmed = false,
        bool $registrationSettlingConfirmed = false,
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
                $lateDiscountConfirmed,
                $registrationSettlingConfirmed,
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
     * Účtárna bez použitelného variabilního symbolu nezastaví ostatní, ale
     * přeskočit se NESMÍ tiše: přehled by ji už znovu nenabídl (povinnost
     * vypadne kvůli sesterskému řádku), proto ji výsledek vypíše ve varování
     * a podá se z obrazovky JMHZ po doplnění symbolu.
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
        bool $lateDiscountConfirmed,
        bool $registrationSettlingConfirmed,
    ): array {
        // Období, které ještě neskončilo, nebo ročník, který aplikace sestavit
        // neumí: příprava skončí jednou větou a nic se nezaloží.
        $this->jmhzGate->assertOpen($period . '-01');
        $key = self::JMHZ_PREPARATION_KEY_PREFIX . ':' . $environment . ':' . $revisionId;
        $preparation = $this->jmhzPreparations->freeze($supplierId, $revisionId, $environment, $key, $userId);
        try {
            return $this->freezeJmhzOffices(
                $supplierId,
                $environment,
                $period,
                $revisionId,
                (int) $preparation['id'],
                $userId,
                $lateDiscountConfirmed,
                $registrationSettlingConfirmed,
            );
        } catch (JmhzXmlException $blocked) {
            /*
             * Odvozený klíč vrací při opakování TUTÉŽ přípravu, i když se mezitím
             * změnily údaje, které příprava čte až při svém vzniku (zařazení
             * složek, identifikátory ČSSZ, roční údaje zaměstnavatele, žádost
             * o roční zúčtování, jméno dítěte). Účetní by údaj podle hlášky
             * opravila a další pokus skončil na tomtéž nálezu. Zastavenou
             * opakovanou přípravu proto postavíme znovu z aktuálních údajů;
             * beze změny údajů vrátí služba tutéž přípravu a nic nepřibude.
             */
            if (($preparation['created'] ?? false) === true) {
                throw $blocked;
            }
            $fresh = $this->jmhzPreparations->freeze(
                $supplierId,
                $revisionId,
                $environment,
                $key . ':' . bin2hex(random_bytes(8)),
                $userId,
            );
            if ((int) $fresh['id'] === (int) $preparation['id']) {
                throw $blocked;
            }

            return $this->freezeJmhzOffices(
                $supplierId,
                $environment,
                $period,
                $revisionId,
                (int) $fresh['id'],
                $userId,
                $lateDiscountConfirmed,
                $registrationSettlingConfirmed,
            );
        }
    }

    /**
     * @return array{
     *   agenda_code:string,period:string,insurer_code:?string,
     *   prepared:int,submission_ids:list<int>,path:string
     * }
     */
    private function freezeJmhzOffices(
        int $supplierId,
        string $environment,
        string $period,
        int $revisionId,
        int $preparationId,
        ?int $userId,
        bool $lateDiscountConfirmed,
        bool $registrationSettlingConfirmed,
    ): array {
        ['offices' => $offices, 'skipped' => $blocked, 'warning' => $missingSymbol]
            = self::partitionOffices($this->jmhzOffices->offices($supplierId, $revisionId));

        $submissionIds = [];
        // Běh bez účtárny (jednoúčtárenská firma) drží původní tvar reference
        // `payroll_run:{runId}` — proto se jedno podání zmrazí i s `null`.
        foreach ($offices === [] ? [null] : $offices as $office) {
            $frozen = $this->jmhzBridge->bridge(
                $supplierId,
                $preparationId,
                null,
                $environment,
                $userId,
                $office === null ? null : (int) $office['office_id'],
                $lateDiscountConfirmed,
                $registrationSettlingConfirmed,
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
            'skipped_offices' => array_map(
                static fn (array $office): array => [
                    'office_id' => (int) $office['office_id'],
                    'name' => (string) $office['name'],
                ],
                $blocked,
            ),
            'warning' => $missingSymbol,
        ];
    }

    /**
     * Účtárny, za které se hlášení zmrazí, a ty, které nemají variabilní
     * symbol ČSSZ. Nemá-li ho žádná, příprava skončí výjimkou.
     *
     * @param list<array<string,mixed>> $offices
     * @return array{offices:list<array<string,mixed>>,skipped:list<array<string,mixed>>,warning:?string}
     */
    public static function partitionOffices(array $offices): array
    {
        $blocked = array_values(array_filter(
            $offices,
            static fn (array $office): bool => ($office['submittable'] ?? false) !== true,
        ));
        if ($blocked === []) {
            return ['offices' => $offices, 'skipped' => [], 'warning' => null];
        }
        $partial = count($blocked) < count($offices);
        $message = 'Mzdová účtárna '
            . implode(', ', array_map(
                static fn (array $office): string => (string) $office['name'],
                $blocked,
            ))
            . ' nemá desetimístný variabilní symbol u ČSSZ, takže za ni '
            . 'hlášení zmrazit nejde. Doplňte ho v Mzdy → Nastavení '
            . 'zaměstnavatele → Mzdové účtárny.'
            . ($partial
                ? ' Hlášení za ostatní účtárny se připravilo; za tuto ho po doplnění '
                    . 'symbolu připravte v Mzdy → Podání → JMHZ.'
                : '');
        if (!$partial) {
            throw new \DomainException($message);
        }

        return [
            'offices' => array_values(array_filter(
                $offices,
                static fn (array $office): bool => ($office['submittable'] ?? false) === true,
            )),
            'skipped' => $blocked,
            'warning' => $message,
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
