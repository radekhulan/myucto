<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzEmployerAnnualEvidenceRepository;
use MyInvoice\Service\Payroll\SupportMatrix;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzEmployerAnnualEvidenceService
{
    public const SCHEMA_REFERENCE = 'payroll-jmhz-employer-annual-evidence.v1';

    public function __construct(
        private JmhzEmployerAnnualEvidenceRepository $repository,
        private JmhzSpecPackageCatalog $specification,
        private SupportMatrix $support,
    ) {}

    /** @return array<string,mixed> */
    public function view(int $supplierId, int $reportYear): array
    {
        $this->assertYear($reportYear);
        $codebooks = $this->codebooks();
        return [
            'evidence' => $this->repository->latest($supplierId, $reportYear),
            'offices' => $this->repository->activeOffices($supplierId),
            'collective_agreement_types' => $codebooks->entries('kolektivni_smlouva'),
            'ownership_forms' => $codebooks->entries('hospodarska_a_financni_kont'),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        int $reportYear,
        array $input,
        ?int $actorUserId,
    ): array {
        $this->assertYear($reportYear);
        $expected = $input['expected_revision_id'] ?? null;
        if ($expected !== null && (!is_int($expected) || $expected <= 0)) {
            throw new \InvalidArgumentException('expected_revision_id musí být kladné celé číslo nebo null.');
        }
        $types = $input['collective_agreement_types'] ?? null;
        if (!is_array($types) || !array_is_list($types) || $types === []) {
            throw new \InvalidArgumentException('Vyberte alespoň jeden typ kolektivní smlouvy.');
        }
        $normalizedTypes = [];
        $codebooks = $this->codebooks();
        foreach ($types as $type) {
            $value = is_int($type) ? (string) $type : $type;
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Typ kolektivní smlouvy není platný.');
            }
            $codebooks->requireValue('kolektivni_smlouva', $value);
            $normalizedTypes[$value] = true;
        }
        ksort($normalizedTypes, SORT_NUMERIC);
        $normalizedTypes = array_map(
            static fn (int|string $value): string => (string) $value,
            array_keys($normalizedTypes),
        );
        if (in_array('0', $normalizedTypes, true) && $normalizedTypes !== ['0']) {
            throw new \InvalidArgumentException('Volbu „bez kolektivní smlouvy“ nelze kombinovat s jiným typem.');
        }
        $ownership = is_int($input['ownership_form'] ?? null)
            ? (string) $input['ownership_form']
            : $input['ownership_form'] ?? null;
        if (!is_string($ownership)) {
            throw new \InvalidArgumentException('Forma vlastnictví a kontroly není platná.');
        }
        $codebooks->requireValue('hospodarska_a_financni_kont', $ownership);
        $total = self::hundredths($input['average_headcount'] ?? null, 'Průměrný počet zaměstnanců');
        $disabled = self::hundredths(
            $input['average_disabled_headcount'] ?? null,
            'Průměrný počet zaměstnanců se zdravotním postižením',
        );
        if ($disabled > $total) {
            throw new \InvalidArgumentException('Průměrný počet OZP nesmí překročit celkový počet zaměstnanců.');
        }
        $officeId = $input['ozp_reporting_office_id'] ?? null;
        if ($officeId !== null && (!is_int($officeId) || $officeId <= 0)) {
            throw new \InvalidArgumentException('Mzdová účtárna pro vykázání OZP není platná.');
        }
        if ($officeId !== null
            && !$this->repository->officeBelongsToSupplier($supplierId, $officeId)
        ) {
            throw new \InvalidArgumentException('Mzdová účtárna pro vykázání OZP nepatří této firmě.');
        }
        $referenceValue = $input['evidence_reference'] ?? null;
        if ($referenceValue !== null && !is_string($referenceValue)) {
            throw new \InvalidArgumentException('Poznámka ke zdroji ročních údajů není platná.');
        }
        $reference = trim($referenceValue ?? '');
        if (mb_strlen($reference) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $reference) === 1) {
            throw new \InvalidArgumentException('Poznámka ke zdroji ročních údajů není platná.');
        }
        $share = $total === 0
            ? 0
            : intdiv(($disabled * 10_000) + intdiv($total, 2), $total);
        $payload = [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'report_year' => $reportYear,
            'collective_agreement_types' => $normalizedTypes,
            'ownership_form' => $ownership,
            'average_headcount_hundredths' => $total,
            'average_disabled_headcount_hundredths' => $disabled,
            'disabled_share_hundredths' => $share,
            'ozp_reporting_office_id' => $officeId,
            'evidence_reference' => $reference === '' ? null : $reference,
        ];
        $payload['collective_agreement_types_json'] = CanonicalJson::encode($normalizedTypes);
        $payload['payload_sha256'] = hash('sha256', CanonicalJson::encode($payload));

        return $this->repository->append(
            $supplierId,
            $reportYear,
            $payload,
            $expected,
            $actorUserId,
        );
    }

    /** @return array<string,mixed>|null */
    public function snapshotForPreparation(
        int $supplierId,
        string $periodStart,
    ): ?array {
        if (preg_match('/^(\d{4})-12-01$/D', $periodStart, $match) !== 1) {
            return null;
        }
        $evidence = $this->repository->latest($supplierId, (int) $match[1], true);
        if ($evidence === null) {
            return null;
        }
        unset($evidence['supplier_id'], $evidence['created_by']);

        return $evidence;
    }

    private function assertYear(int $year): void
    {
        if (!$this->support->supportsYear($year)) {
            throw new \InvalidArgumentException(
                "Rok {$year} není podporovaný účinnými mzdovými rulesety.",
            );
        }
    }

    private static function hundredths(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$label} musí být číslo nejvýše na dvě desetinná místa.");
        }
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D', $value, $match) !== 1) {
            throw new \InvalidArgumentException("{$label} musí být číslo nejvýše na dvě desetinná místa.");
        }
        $fraction = str_pad($match[2] ?? '', 2, '0');

        return ((int) $match[1] * 100) + (int) $fraction;
    }

    private function codebooks(): JmhzCodebookCatalog
    {
        return new JmhzCodebookCatalog($this->specification->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));
    }
}
