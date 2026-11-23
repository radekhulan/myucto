<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use InvalidArgumentException;
use MyInvoice\Service\Codebook\HealthInsurers;

final readonly class HealthPersonMonthInput
{
    /** @var non-empty-list<HealthInsuranceRelationshipInput> */
    public array $relationships;

    /** @var list<HealthMinimumReductionInterval> */
    public array $minimumReductions;

    /** @var list<HealthOtherEmployerBase> */
    public array $otherEmployerBases;

    /**
     * @param array<mixed> $relationships
     * @param array<mixed> $minimumReductions
     * @param array<mixed> $otherEmployerBases
     */
    public function __construct(
        public string $personId,
        public HealthJurisdictionEvidence $jurisdiction,
        public ?string $jurisdictionEvidenceReference,
        public HealthInsurerSnapshotStatus $insurerStatus,
        public ?string $insurerCode,
        public ?string $insurerEvidenceReference,
        array $relationships,
        array $minimumReductions = [],
        array $otherEmployerBases = [],
        public HealthMinimumTopUpResponsibility $topUpResponsibility =
            HealthMinimumTopUpResponsibility::Employee,
        public ?string $topUpResponsibilityEvidenceReference = null,
        public ?string $selectedTopUpEmployerEvidenceReference = null,
        public HealthMinimumTopUpEmployerSelection $topUpEmployerSelection =
            HealthMinimumTopUpEmployerSelection::Unverified,
        public HealthMinimumTopUpResponsibilitySource $topUpResponsibilitySource =
            HealthMinimumTopUpResponsibilitySource::Declared,
        public bool $coverageEvidenceMissing = false,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $personId) !== 1) {
            throw new InvalidArgumentException('Health insurance person ID is not canonical.');
        }
        self::assertJurisdiction($jurisdiction, $jurisdictionEvidenceReference);
        self::assertInsurer($insurerStatus, $insurerCode, $insurerEvidenceReference);
        if ($coverageEvidenceMissing
            && ($jurisdiction !== HealthJurisdictionEvidence::Unverified
                || $insurerStatus !== HealthInsurerSnapshotStatus::Unverified
                || $insurerCode !== null)
        ) {
            throw new InvalidArgumentException(
                'Missing health coverage evidence cannot carry a jurisdiction or an insurer.',
            );
        }
        if (!array_is_list($relationships) || $relationships === []) {
            throw new InvalidArgumentException(
                'Health insurance person relationships must be a non-empty list.',
            );
        }
        $validatedRelationships = [];
        $relationshipIds = [];
        foreach ($relationships as $relationship) {
            if (!$relationship instanceof HealthInsuranceRelationshipInput) {
                throw new InvalidArgumentException(
                    'Health insurance relationships must use the dedicated input type.',
                );
            }
            $validatedRelationships[] = $relationship;
            $relationshipIds[] = $relationship->relationshipId;
        }
        if (count(array_unique($relationshipIds)) !== count($relationshipIds)) {
            throw new InvalidArgumentException(
                'Health insurance relationship IDs must be unique for one person.',
            );
        }
        if (!array_is_list($minimumReductions)) {
            throw new InvalidArgumentException('Health minimum reductions must be a list.');
        }
        $validatedReductions = [];
        foreach ($minimumReductions as $reduction) {
            if (!$reduction instanceof HealthMinimumReductionInterval) {
                throw new InvalidArgumentException(
                    'Health minimum reductions contain an invalid value.',
                );
            }
            $validatedReductions[] = $reduction;
        }
        if (!array_is_list($otherEmployerBases)) {
            throw new InvalidArgumentException('Other employer bases must be a list.');
        }
        $validatedOtherEmployers = [];
        $otherEmployerReferences = [];
        foreach ($otherEmployerBases as $otherEmployer) {
            if (!$otherEmployer instanceof HealthOtherEmployerBase) {
                throw new InvalidArgumentException(
                    'Other employer bases contain an invalid value.',
                );
            }
            $validatedOtherEmployers[] = $otherEmployer;
            $otherEmployerReferences[] = $otherEmployer->employerReference;
        }
        if (
            count(array_unique($otherEmployerReferences))
            !== count($otherEmployerReferences)
        ) {
            throw new InvalidArgumentException(
                'Health insurance other employer references must be unique.',
            );
        }
        if (
            $topUpResponsibility === HealthMinimumTopUpResponsibility::EmployerObstacleVerified
            && $topUpResponsibilityEvidenceReference !== null
            && !self::isEvidenceReference($topUpResponsibilityEvidenceReference)
        ) {
            throw new InvalidArgumentException(
                'Employer-paid minimum top-up evidence is not canonical.',
            );
        }
        if (
            $topUpResponsibility !== HealthMinimumTopUpResponsibility::EmployerObstacleVerified
            && $topUpResponsibilityEvidenceReference !== null
        ) {
            throw new InvalidArgumentException(
                'Top-up responsibility evidence is only allowed for a verified employer obstacle.',
            );
        }
        if (
            $selectedTopUpEmployerEvidenceReference !== null
            && !self::isEvidenceReference($selectedTopUpEmployerEvidenceReference)
        ) {
            throw new InvalidArgumentException(
                'Selected minimum top-up employer evidence is not canonical.',
            );
        }
        if (
            $selectedTopUpEmployerEvidenceReference !== null
            && $validatedOtherEmployers === []
        ) {
            throw new InvalidArgumentException(
                'Selected minimum top-up employer evidence requires another employer.',
            );
        }
        if (
            $topUpEmployerSelection === HealthMinimumTopUpEmployerSelection::OtherEmployer
            && $validatedOtherEmployers === []
        ) {
            throw new InvalidArgumentException(
                'Another selected minimum top-up employer requires another employer.',
            );
        }
        // Zákonný výchozí stav umí být JEN to, co zákon skutečně stanoví:
        // § 3 odst. 10 z. č. 592/1992 Sb. dělá výchozím plátcem zaměstnance
        // a výjimku (překážky na straně organizace) váže na doklad. Kdyby se
        // sem dal odvodit i zaměstnavatel nebo `unverified`, vznikla by cesta,
        // jak výjimku prohlásit bez dokladu „jménem zákona".
        if (
            $topUpResponsibilitySource === HealthMinimumTopUpResponsibilitySource::StatutoryDefault
            && ($topUpResponsibility !== HealthMinimumTopUpResponsibility::Employee
                || $topUpResponsibilityEvidenceReference !== null)
        ) {
            throw new InvalidArgumentException(
                'The statutory default top-up responsibility is the employee without evidence.',
            );
        }

        $this->relationships = $validatedRelationships;
        $this->minimumReductions = $validatedReductions;
        $this->otherEmployerBases = $validatedOtherEmployers;
    }

    private static function assertJurisdiction(
        HealthJurisdictionEvidence $jurisdiction,
        ?string $reference,
    ): void {
        if (
            $jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
            && $reference !== null
            && !self::isEvidenceReference($reference)
        ) {
            throw new InvalidArgumentException(
                'Foreign health insurance regime evidence is not canonical.',
            );
        }
        if (
            $jurisdiction !== HealthJurisdictionEvidence::ForeignRegimeVerified
            && $reference !== null
        ) {
            throw new InvalidArgumentException(
                'Jurisdiction evidence is only allowed for a verified foreign regime.',
            );
        }
    }

    private static function assertInsurer(
        HealthInsurerSnapshotStatus $status,
        ?string $code,
        ?string $reference,
    ): void {
        if ($status === HealthInsurerSnapshotStatus::Verified) {
            // Ověřený snapshot musí nést kód z číselníku, ne jen trojici číslic —
            // dřív tudy prošla i neexistující pojišťovna (např. 999).
            // Hlášky tohoto DTO jsou historicky anglické, výčet bereme z číselníku.
            if ($code === null || !HealthInsurers::isValid($code)) {
                throw new InvalidArgumentException(sprintf(
                    'A verified health insurer snapshot requires a code from the codebook: %s.',
                    HealthInsurers::listForMessage(),
                ));
            }
            if ($reference !== null && !self::isEvidenceReference($reference)) {
                throw new InvalidArgumentException(
                    'Health insurer snapshot evidence is not canonical.',
                );
            }

            return;
        }
        if ($status === HealthInsurerSnapshotStatus::NotApplicable && ($code !== null || $reference !== null)) {
            throw new InvalidArgumentException(
                'A non-applicable health insurer snapshot cannot contain insurer data.',
            );
        }
        if ($status === HealthInsurerSnapshotStatus::Unverified && $reference !== null) {
            throw new InvalidArgumentException(
                'An unverified health insurer snapshot cannot contain verified evidence.',
            );
        }
        if ($code !== null && preg_match('/^\d{3}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Health insurer code must contain three digits.');
        }
    }

    private static function isEvidenceReference(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) === 1;
    }
}
