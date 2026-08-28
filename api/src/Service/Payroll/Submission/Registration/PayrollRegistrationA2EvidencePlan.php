<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class PayrollRegistrationA2EvidencePlan
{
    public const SCHEMA_REFERENCE = 'payroll-registration-a2-jmhz-evidence.v1';
    public const POLICY_REFERENCE = 'regzec-a2-retroactive-jmhz-acceptance.v1';

    /** @param list<array<string,mixed>> $months */
    private function __construct(
        private int $supplierId,
        private string $environment,
        private int $employmentId,
        private string $effectiveOn,
        private array $months,
        private string $decision,
        private string $fingerprint,
    ) {}

    /** @param list<array<string,mixed>> $months */
    public static function create(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        array $months,
    ): self {
        if ($supplierId <= 0 || $employmentId <= 0
            || !in_array($environment, ['test', 'production'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $effectiveOn) !== 1
        ) {
            throw new \InvalidArgumentException('Rozsah důkazního plánu REGZEC A2 není platný.');
        }
        $normalized = [];
        foreach ($months as $month) {
            if (!is_array($month) || array_is_list($month)) {
                throw new \InvalidArgumentException('Měsíc důkazního plánu REGZEC A2 není platný.');
            }
            $periodStart = $month['period_start'] ?? null;
            if (!is_string($periodStart)
                || preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1
                || isset($normalized[$periodStart])
            ) {
                throw new \InvalidArgumentException('Období důkazního plánu REGZEC A2 není jednoznačné.');
            }
            $decision = $month['decision'] ?? null;
            if (!in_array($decision, ['accepted', 'pending', 'rejected', 'missing'], true)) {
                throw new \InvalidArgumentException('Výsledek měsíce důkazního plánu REGZEC A2 není platný.');
            }
            $normalized[$periodStart] = self::month($month, $periodStart, $decision);
        }
        ksort($normalized, SORT_STRING);
        $normalized = array_values($normalized);
        $decision = array_all(
            $normalized,
            static fn (array $month): bool => $month['decision'] === 'accepted',
        ) ? 'accepted' : 'blocked';
        $payload = [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'policy_reference' => self::POLICY_REFERENCE,
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employment_id' => $employmentId,
            'effective_on' => $effectiveOn,
            'decision' => $decision,
            'months' => $normalized,
        ];

        return new self(
            $supplierId,
            $environment,
            $employmentId,
            $effectiveOn,
            $normalized,
            $decision,
            hash('sha256', CanonicalJson::encode($payload)),
        );
    }

    public function decision(): string
    {
        return $this->decision;
    }

    /** @return list<string> */
    public function blockedPeriods(): array
    {
        return array_values(array_map(
            static fn (array $month): string => $month['period_start'],
            array_filter(
                $this->months,
                static fn (array $month): bool => $month['decision'] !== 'accepted',
            ),
        ));
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'policy_reference' => self::POLICY_REFERENCE,
            'supplier_id' => $this->supplierId,
            'environment' => $this->environment,
            'employment_id' => $this->employmentId,
            'effective_on' => $this->effectiveOn,
            'decision' => $this->decision,
            'months' => $this->months,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @param array<string,mixed> $month @return array<string,mixed> */
    private static function month(array $month, string $periodStart, string $decision): array
    {
        $normalized = [
            'period_start' => $periodStart,
            'run_id' => self::positive($month['run_id'] ?? null, 'mzdový běh'),
            'revision_id' => self::positive($month['revision_id'] ?? null, 'mzdová revize'),
            'preparation_id' => self::nullablePositive($month['preparation_id'] ?? null),
            'submission_id' => self::nullablePositive($month['submission_id'] ?? null),
            'transport_attempt_id' => self::nullablePositive($month['transport_attempt_id'] ?? null),
            'receipt_id' => self::nullablePositive($month['receipt_id'] ?? null),
            'submission_status' => self::nullableText($month['submission_status'] ?? null),
            'transport_status' => self::nullableText($month['transport_status'] ?? null),
            'transport_sent_at' => self::nullableText($month['transport_sent_at'] ?? null),
            'transport_correlation_reference' => self::nullableText(
                $month['transport_correlation_reference'] ?? null,
            ),
            'receipt_status' => self::nullableText($month['receipt_status'] ?? null),
            'receipt_verification_status' => self::nullableText(
                $month['receipt_verification_status'] ?? null,
            ),
            'receipt_correlation_reference' => self::nullableText(
                $month['receipt_correlation_reference'] ?? null,
            ),
            'form_status' => self::nullableText($month['form_status'] ?? null),
            'decision' => $decision,
            'reason' => self::nullableText($month['reason'] ?? null),
        ];
        if ($decision === 'accepted'
            && ($normalized['preparation_id'] === null
                || $normalized['submission_id'] === null
                || $normalized['transport_attempt_id'] === null
                || $normalized['receipt_id'] === null)
        ) {
            throw new \InvalidArgumentException('Přijatý měsíc REGZEC A2 nemá úplný důkazní řetězec.');
        }

        return $normalized;
    }

    private static function positive(mixed $value, string $label): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException("{$label} důkazního plánu REGZEC A2 není platný.");
        }
        return $value;
    }

    private static function nullablePositive(mixed $value): ?int
    {
        return $value === null ? null : self::positive($value, 'identifikátor');
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Text důkazního plánu REGZEC A2 není platný.');
        }
        return trim($value);
    }
}
