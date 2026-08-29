<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Service\Payroll\Document\AnnualSettlementPdfRenderer;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;

/**
 * Roční zúčtování záloh a daňového zvýhodnění — § 38ch ZDP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Dvě operace, schválně oddělené
 * ─────────────────────────────────────────────────────────────────────────────
 *  - `preview()` posoudí podmínky a spočítá výsledek, ale NIC neuloží. Je to
 *    to, co uživatel vidí, než se rozhodne. Smí se volat kolikrát chce.
 *  - `settle()` udělá totéž a výsledek zmrazí do dokladu. Je idempotentní:
 *    druhé spuštění vrátí ten původní výsledek, nezaloží druhý.
 *
 * Kdyby to byla jedna metoda, nešlo by se podívat, jak by to dopadlo, aniž by
 * se tím rovnou provedl právní úkon plátce daně.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Odmítnutí je taky výsledek
 * ─────────────────────────────────────────────────────────────────────────────
 * Nesplněné podmínky nevyhazují výjimku — vracejí `AnnualSettlementResult`
 * s `performed = false` a seznamem překážek. Volající tak vždycky dostane
 * odpověď na otázku „co s tím", ne jen „nepovedlo se". Výjimka je vyhrazená pro
 * rozbitý podklad (`AnnualSettlementUnavailableException`).
 */
final class AnnualTaxSettlementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualSettlementRepository $settlements,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly AnnualSettlementEligibility $eligibility,
        private readonly AnnualSettlementClaimMonths $claimMonths,
        private readonly AnnualSettlementEvidenceMonths $evidenceMonths,
        private readonly AnnualTaxSettlementCalculator $calculator,
        private readonly AnnualSettlementSnapshotBuilder $snapshots,
        private readonly AnnualSettlementPdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
    ) {}

    /**
     * @return array{
     *   result:AnnualSettlementResult,
     *   request:array<string,mixed>,
     *   credit_rows:list<array{label:string,amount_minor_units:int}>,
     *   child_rows:list<array{label:string,months:int,amount_minor_units:int}>,
     *   certificates:list<array<string,mixed>>,
     *   already_settled:?array<string,mixed>
     * }
     */
    public function preview(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?DateTimeImmutable $today = null,
    ): array {
        return $this->assess($supplierId, $employeeId, $taxYear, $today ?? new DateTimeImmutable());
    }

    /**
     * Zapíše potvrzení od předchozích plátců daně (§ 38ch odst. 3).
     *
     * Celý seznam za rok najednou — potvrzení dávají smysl jen jako úplná sada
     * („doklady … od VŠECH předchozích plátců daně"), a ukládat je po jednom by
     * dovolilo stav, kdy je půlka roku doložená a druhá se ztratila.
     *
     * Neúplné potvrzení se uložit SMÍ. Rozpracovanou evidenci nemá smysl
     * blokovat; úplnost je podmínka provedení zúčtování, ne podmínka existence
     * záznamu, a posuzuje se až ve výpočtu.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function saveCertificates(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $rows,
        ?int $actorUserId,
    ): array {
        // Validace přes doménový typ, ne přes vlastní pravidla — jinak by šlo
        // uložit něco, co pak výpočet odmítne přijmout.
        $seen = [];
        $prepared = [];
        foreach ($rows as $row) {
            $certificate = self::certificateFromInput($row);
            $key = mb_strtolower($certificate->certificateReference);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(
                    'Potvrzení se stejným označením je v seznamu dvakrát.',
                );
            }
            $seen[$key] = true;
            $prepared[] = [
                'certificate_reference' => $certificate->certificateReference,
                'payer_name' => $certificate->payerName,
                'payer_tax_identification' => $certificate->payerTaxIdentification,
                'received_on' => $certificate->receivedOn,
                'employment_from' => $certificate->employmentFrom,
                'employment_to' => $certificate->employmentTo,
                'gross_income_minor' => $certificate->grossIncomeMinorUnits,
                'advance_base_minor' => $certificate->advanceBaseMinorUnits,
                'advance_tax_minor' => $certificate->advanceTaxMinorUnits,
                'credit_35ba_minor' => $certificate->nonRefundableCreditMinorUnits,
                'credit_35c_minor' => $certificate->childCreditMinorUnits,
                'tax_bonus_minor' => $certificate->taxBonusMinorUnits,
                'evidence_status' => $certificate->evidenceStatus->value,
                'evidence_reference' => $certificate->evidenceReference,
                'note' => self::text($row['note'] ?? null),
            ];
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $this->settlements->replaceCertificates(
                $supplierId,
                $employeeId,
                $taxYear,
                $prepared,
                $actorUserId,
            );
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($owns) {
                $this->rollbackIfOpen($pdo);
            }
            throw $exception;
        }

        return self::certificateRows(
            $this->externalCertificates($supplierId, $employeeId, $taxYear),
        );
    }

    /** @param array<string,mixed> $row */
    private static function certificateFromInput(array $row): ExternalEmployerTaxCertificate
    {
        return new ExternalEmployerTaxCertificate(
            trim((string) ($row['certificate_reference'] ?? '')),
            self::inputAmount($row, 'advance_base_minor_units'),
            self::inputAmount($row, 'advance_tax_minor_units'),
            TaxEvidenceStatus::tryFrom((string) ($row['evidence_status'] ?? ''))
                ?? TaxEvidenceStatus::Unverified,
            self::text($row['evidence_reference'] ?? null),
            self::inputAmount($row, 'gross_income_minor_units'),
            self::inputAmount($row, 'non_refundable_credit_minor_units'),
            self::inputAmount($row, 'child_credit_minor_units'),
            self::inputAmount($row, 'tax_bonus_minor_units'),
            self::text($row['payer_name'] ?? null),
            self::text($row['payer_tax_identification'] ?? null),
            self::isoDate($row['received_on'] ?? null),
            self::isoDate($row['employment_from'] ?? null),
            self::isoDate($row['employment_to'] ?? null),
        );
    }

    /**
     * Prázdný řetězec i chybějící klíč znamenají „nevyplněno", tedy `null`.
     * Nula se pošle jako `0`, ne jako `""` — jinak by se nedalo odlišit
     * „na potvrzení je nula" od „na potvrzení to není".
     *
     * @param array<string,mixed> $row
     */
    private static function inputAmount(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('~^-?\d+$~', $value) === 1)) {
            throw new \InvalidArgumentException(
                "Údaj {$key} na potvrzení není celé číslo.",
            );
        }

        return (int) $value;
    }

    /**
     * Provede roční zúčtování a zmrazí ho do dokladu.
     *
     * @return array{
     *   result:AnnualSettlementResult,
     *   outcome:?array<string,mixed>,
     *   document:?array<string,mixed>,
     *   created:bool
     * }
     */
    public function settle(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
        ?DateTimeImmutable $today = null,
    ): array {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'Roční zúčtování musí vlastnit databázovou transakci.',
            );
        }
        $today ??= new DateTimeImmutable();

        // Posouzení běží mimo transakci jen jako rychlá odpověď „nemá cenu
        // začínat". Uvnitř transakce se opakuje, aby mezi posouzením a zápisem
        // nemohl nikdo změnit evidenci.
        $preflight = $this->assess($supplierId, $employeeId, $taxYear, $today);
        if (!$preflight['result']->performed) {
            return [
                'result' => $preflight['result'],
                'outcome' => $preflight['already_settled'],
                'document' => null,
                'created' => false,
            ];
        }

        $scope = $this->documents->beginStorageScope();
        $pdo->beginTransaction();
        try {
            $assessment = $this->assess($supplierId, $employeeId, $taxYear, $today, true);
            $result = $assessment['result'];
            if (!$result->performed) {
                $pdo->rollBack();
                $this->documents->cleanupStorageScope($supplierId, $scope);

                return [
                    'result' => $result,
                    'outcome' => $assessment['already_settled'],
                    'document' => null,
                    'created' => false,
                ];
            }

            $prepared = $this->snapshots->build(
                $supplierId,
                $employeeId,
                $taxYear,
                $result,
                $today->format('Y-m-d'),
                $assessment['credit_rows'],
                $assessment['child_rows'],
                $actorUserId,
            );
            $revisionId = (int) $prepared['revision']['id'];

            $stored = $this->settlements->insertOutcome(
                $supplierId,
                $employeeId,
                $taxYear,
                [
                    'annual_revision_id' => $revisionId,
                    'outcome' => $result->outcome?->value,
                    'tax_difference_minor' => $result->taxDifferenceMinorUnits,
                    'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
                    'settlement_difference_minor' => $result->settlementDifferenceMinorUnits,
                    'payable_minor' => $result->payableMinorUnits,
                    'payout_threshold_minor' =>
                        AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                    'settled_on' => $today->format('Y-m-d'),
                ],
                $actorUserId,
            );

            $artifact = $this->renderer->render($prepared['document']);
            $document = $this->documents->archiveAnnualPdf(
                $supplierId,
                $revisionId,
                $employeeId,
                $artifact,
                'annual-settlement:' . hash('sha256', implode("\0", [
                    (string) $supplierId,
                    (string) $employeeId,
                    (string) $taxYear,
                    $artifact->sourceSnapshotHash,
                    $artifact->rendererVersion,
                ])),
                $actorUserId,
                $scope,
            );

            $pdo->commit();
            $this->documents->commitStorageScope($scope);

            return [
                'result' => $result,
                'outcome' => $stored['outcome'],
                'document' => $document,
                'created' => $stored['created'],
            ];
        } catch (\Throwable $exception) {
            $this->rollbackIfOpen($pdo);
            try {
                $this->documents->cleanupStorageScope($supplierId, $scope);
            } catch (\Throwable $cleanup) {
                throw new \RuntimeException(
                    'Roční zúčtování selhalo a osiřelý soubor se nepodařilo uklidit.',
                    previous: $cleanup,
                );
            }
            throw $exception;
        }
    }

    /**
     * Posouzení podmínek plus výpočet. Sdílené jádro obou operací — kdyby
     * existovalo dvakrát, mohl by náhled ukázat jiné číslo, než jaké se pak
     * zapíše do dokladu.
     *
     * @return array{
     *   result:AnnualSettlementResult,
     *   request:array<string,mixed>,
     *   credit_rows:list<array{label:string,amount_minor_units:int}>,
     *   child_rows:list<array{label:string,months:int,amount_minor_units:int}>,
     *   certificates:list<array<string,mixed>>,
     *   already_settled:?array<string,mixed>
     * }
     */
    private function assess(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        DateTimeImmutable $today,
        bool $lockOutcome = false,
    ): array {
        [$request, $requestRow] = $this->loadRequest($supplierId, $employeeId, $taxYear);
        $requestPayload = [
            ...$request->toArray(),
            'other_household_caregiver_status' =>
                (string) ($requestRow['other_household_caregiver_status'] ?? 'unknown'),
            'other_household_caregivers' => is_array(
                $requestRow['other_household_caregivers'] ?? null,
            ) ? array_values($requestRow['other_household_caregivers']) : [],
        ];
        $blockers = [];

        $settled = $this->settlements->findOutcome(
            $supplierId,
            $employeeId,
            $taxYear,
            $lockOutcome,
        );
        if ($settled !== null) {
            $blockers[] = AnnualSettlementBlocker::AlreadySettled;
        }

        // Roční sazby jdou z rulesetu účinného pro celý rok. Nepokrývá-li ho
        // souvisle, roční částky odvodit nelze — a odhadovat je nebudeme.
        $rates = null;
        if (!PayrollRulesetYearCoverage::coversYear(
            $this->rulesets,
            PayrollRulesetDomain::IncomeTax,
            $taxYear,
        )) {
            $blockers[] = AnnualSettlementBlocker::RulesetYearNotCovered;
        } else {
            $rates = AnnualTaxRates::forRuleset($this->rulesets->forCalculation(
                PayrollRulesetDomain::IncomeTax,
                sprintf('%04d-12-01', $taxYear),
            ));
        }

        // Prohlášení a rezidentství se posuzují ZA OBDOBÍ, ve kterém u plátce
        // trval pracovní vztah — ne k jednomu dni. § 38k odst. 4 mluví o
        // prohlášení „na příslušné zdaňovací období", takže rozhodný je stav za
        // ten rok, ne dnešek; a § 38ch odst. 4 o úhrnu mezd za měsíce, které do
        // zúčtování vstupují. Čtení k 31. 12. dělalo z každého, kdo v průběhu
        // roku odešel, nedoloženého nerezidenta.
        $statutory = $this->settlements->statutoryEvidenceForYear(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        $evidence = $this->evidenceMonths->evaluate(
            $statutory['declarations'],
            $statutory['residences'],
            $taxYear,
            $this->settlements->employmentMonths($supplierId, $employeeId, $taxYear),
        );
        $declaration = $evidence['declaration'];
        $residence = $evidence['residence'];

        $credits = $this->claimMonths->credits(
            $this->settlements->creditClaimsForYear($supplierId, $employeeId, $taxYear),
            $taxYear,
            $declaration === TaxDeclarationStatus::Signed,
        );
        $children = $this->claimMonths->children(
            $this->settlements->childClaimsForYear($supplierId, $employeeId, $taxYear),
            $taxYear,
        );
        $blockers = [...$blockers, ...$credits['blockers'], ...$children['blockers']];
        if (!$this->settlements->childJmhzEvidenceIsComplete(
            $supplierId,
            $employeeId,
            array_map(
                static fn (AnnualSettlementChildMonths $child): string => $child->childReference,
                $children['children'],
            ),
            $requestRow,
        )) {
            $blockers[] = AnnualSettlementBlocker::ChildJmhzEvidenceIncomplete;
        }

        $blockers = $this->eligibility->evaluate(
            $request,
            $declaration,
            $residence,
            $today,
            $blockers,
        );

        try {
            $state = $this->accumulators->stateForYear(
                $supplierId,
                $employeeId,
                $taxYear,
                'income_tax',
            );
            $totals = is_array($state['totals'] ?? null) ? $state['totals'] : [];
        } catch (PayrollStatutoryAccumulatorUnavailableException) {
            $blockers[] = AnnualSettlementBlocker::AccumulatorMissing;
            $totals = null;
        }

        $certificates = $this->externalCertificates($supplierId, $employeeId, $taxYear);

        if ($rates === null || $totals === null) {
            return [
                'result' => AnnualSettlementResult::refused(
                    $taxYear,
                    $this->unique($blockers),
                    ['rates' => $rates?->toArray()],
                ),
                'request' => $requestPayload,
                'credit_rows' => [],
                'child_rows' => [],
                'certificates' => self::certificateRows($certificates),
                'already_settled' => $settled,
            ];
        }

        $input = new AnnualSettlementInput(
            $taxYear,
            (int) ($totals['completed_months'] ?? 0),
            (int) ($totals['advance_base_minor_units'] ?? 0),
            (int) ($totals['advance_tax_minor_units'] ?? 0),
            (int) ($totals['applied_non_refundable_credits_minor_units'] ?? 0),
            (int) ($totals['applied_child_credit_minor_units'] ?? 0),
            (int) ($totals['tax_bonus_minor_units'] ?? 0),
            (int) ($totals['bonus_qualifying_income_minor_units'] ?? 0),
            (int) ($totals['withholding_base_minor_units'] ?? 0),
            (int) ($totals['withholding_tax_minor_units'] ?? 0),
            $credits['credits'],
            $children['children'],
            $certificates,
        );

        $result = $this->calculator->calculate($input, $rates, $this->unique($blockers));

        return [
            'result' => $result,
            'request' => $requestPayload,
            'credit_rows' => $this->creditRows($result),
            'child_rows' => $this->childRows($result),
            'certificates' => self::certificateRows($certificates),
            'already_settled' => $settled,
        ];
    }

    /**
     * Vrácení transakce, když ještě běží.
     *
     * Vlastní metoda kvůli statické analýze: v `catch` bloku je pro ni stav
     * transakce pořád ten, který platil na začátku metody, takže by přímou
     * podmínku označila za vždy nepravdivou. Stejný vzor má
     * `AnnualPayrollSheetService`.
     */
    private function rollbackIfOpen(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * Potvrzení od předchozích plátců (§ 38ch odst. 3).
     *
     * Načítá se, co je v evidenci — VČETNĚ neúplných a nedoložených. Ty se do
     * úhrnu nezapočítají, ale musí se dostat až do výpočtu, aby z nich vznikla
     * překážka. Kdyby se odfiltrovaly tady, zúčtování by tiše proběhlo jen
     * z vlastních kumulací a poplatníkovi by vyšel přeplatek z neúplného úhrnu.
     *
     * @return list<ExternalEmployerTaxCertificate>
     */
    private function externalCertificates(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $rows = $this->settlements->certificatesForYear($supplierId, $employeeId, $taxYear);

        return array_map(
            static fn (array $row): ExternalEmployerTaxCertificate
                => new ExternalEmployerTaxCertificate(
                    (string) $row['certificate_reference'],
                    self::amount($row['advance_base_minor'] ?? null),
                    self::amount($row['advance_tax_minor'] ?? null),
                    TaxEvidenceStatus::tryFrom((string) ($row['evidence_status'] ?? ''))
                        ?? TaxEvidenceStatus::Unverified,
                    self::text($row['evidence_reference'] ?? null),
                    self::amount($row['gross_income_minor'] ?? null),
                    self::amount($row['credit_35ba_minor'] ?? null),
                    self::amount($row['credit_35c_minor'] ?? null),
                    self::amount($row['tax_bonus_minor'] ?? null),
                    self::text($row['payer_name'] ?? null),
                    self::text($row['payer_tax_identification'] ?? null),
                    self::isoDate($row['received_on'] ?? null),
                    self::isoDate($row['employment_from'] ?? null),
                    self::isoDate($row['employment_to'] ?? null),
                ),
            $rows,
        );
    }

    /**
     * @param list<ExternalEmployerTaxCertificate> $certificates
     * @return list<array<string,mixed>>
     */
    private static function certificateRows(array $certificates): array
    {
        return array_map(
            static fn (ExternalEmployerTaxCertificate $certificate): array
                => $certificate->jsonSerialize(),
            $certificates,
        );
    }

    /** `null` zůstává `null` — chybějící údaj se nesmí stát nulou. */
    private static function amount(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function isoDate(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @return list<array{label:string,amount_minor_units:int}> */
    private function creditRows(AnnualSettlementResult $result): array
    {
        $credits = $result->trace['credits'] ?? [];
        if (!is_array($credits)) {
            return [];
        }
        $rows = [];
        foreach ($credits as $kindValue => $credit) {
            if (!is_array($credit)) {
                continue;
            }
            $kind = TaxCreditKind::tryFrom((string) $kindValue);
            if ($kind === null) {
                continue;
            }
            $rows[] = [
                'label' => self::creditLabel($kind, (int) ($credit['months'] ?? 0)),
                'amount_minor_units' => (int) ($credit['amount_minor_units'] ?? 0),
            ];
        }

        return $rows;
    }

    /** @return list<array{label:string,months:int,amount_minor_units:int}> */
    private function childRows(AnnualSettlementResult $result): array
    {
        $children = $result->trace['children'] ?? [];
        if (!is_array($children)) {
            return [];
        }
        $rows = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $order = (int) ($child['order'] ?? 0);
            $ztpPMonths = (int) ($child['ztp_p_months'] ?? 0);
            $rows[] = [
                'label' => sprintf(
                    '%d. dítě%s',
                    $order,
                    $ztpPMonths > 0
                        ? sprintf(' (průkaz ZTP/P %d měs.)', $ztpPMonths)
                        : '',
                ),
                'child_reference' => (string) ($child['child_reference'] ?? ''),
                'order' => $order,
                'months' => (int) ($child['months'] ?? 0),
                'claimed_months' => is_array($child['claimed_months'] ?? null)
                    ? array_values($child['claimed_months'])
                    : [],
                'ztp_p_months' => $ztpPMonths,
                'ztp_p_claimed_months' => is_array(
                    $child['ztp_p_claimed_months'] ?? null,
                ) ? array_values($child['ztp_p_claimed_months']) : [],
                'amount_minor_units' => (int) ($child['amount_minor_units'] ?? 0),
            ];
        }

        return $rows;
    }

    private static function creditLabel(TaxCreditKind $kind, int $months): string
    {
        $name = match ($kind) {
            // § 35ba odst. 3 dvanáctinovou úpravu na slevu na poplatníka
            // nevztahuje, proto se u ní počet měsíců neuvádí — vedlo by to
            // ke špatnému závěru, že se krátí.
            TaxCreditKind::Taxpayer => 'Základní sleva na poplatníka (§ 35ba odst. 1 písm. a)',
            TaxCreditKind::DisabilityBasic => 'Základní sleva na invaliditu (§ 35ba odst. 1 písm. c)',
            TaxCreditKind::DisabilityExtended => 'Rozšířená sleva na invaliditu (§ 35ba odst. 1 písm. d)',
            TaxCreditKind::ZtpP => 'Sleva na držitele průkazu ZTP/P (§ 35ba odst. 1 písm. e)',
        };
        if ($kind === TaxCreditKind::Taxpayer) {
            return $name;
        }

        return sprintf('%s — %d měs.', $name, $months);
    }

    /** @return array{0:AnnualSettlementRequest,1:array<string,mixed>} */
    private function loadRequest(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $row = $this->settlements->findRequest($supplierId, $employeeId, $taxYear);
        if ($row === null) {
            return [AnnualSettlementRequest::unknown($taxYear), []];
        }

        return [new AnnualSettlementRequest(
            $taxYear,
            AnnualSettlementRequestStatus::from((string) $row['request_status']),
            self::date($row['requested_on'] ?? null),
            self::text($row['request_evidence_reference'] ?? null),
            AnnualSettlementPriorEmployers::from((string) $row['prior_employers']),
            self::date($row['prior_documents_received_on'] ?? null),
            AnnualSettlementFilingObligation::from((string) $row['filing_obligation']),
            self::text($row['filing_obligation_reason'] ?? null),
            AnnualSettlementAnnualClaims::from((string) $row['annual_claims']),
            self::text($row['annual_claims_note'] ?? null),
            self::text($row['note'] ?? null),
            (int) $row['row_version'],
        ), $row];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @return list<AnnualSettlementBlocker>
     */
    private function unique(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        ksort($unique);

        return array_values($unique);
    }
}
