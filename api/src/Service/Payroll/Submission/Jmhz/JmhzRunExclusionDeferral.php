<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzDeferralRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Osoba vyřazená z mzdového běhu se z řádného hlášení JMHZ odloží sama.
 *
 * Schválená revize bez ní nemá výsledek, takže řádné hlášení její formulář
 * nenese. Povinnost hlásit vztah za měsíc ale trvá: příprava založí odložení
 * s důvodem vyřazení, řádné hlášení za ostatní se zmrazí a formulář doplní
 * opravné hlášení, jakmile opravná revize běhu osobu zase obsahuje
 * („Doplnit všechny odložené").
 */
final readonly class JmhzRunExclusionDeferral
{
    public const CODE = 'jmhz_payroll_run_excluded';

    public function __construct(private JmhzDeferralRepository $repository) {}

    /**
     * @param array{id:int,environment:string,source_revision_id:int,snapshot_fingerprint:string} $preparation
     * @return list<int> nově založená odložení
     */
    public function deferExcluded(int $supplierId, array $preparation, ?int $userId): array
    {
        $rows = $this->repository->settledRunExclusions($supplierId, $preparation['source_revision_id']);
        if ($rows === []) {
            return [];
        }

        return $this->repository->transaction(function () use ($supplierId, $preparation, $userId, $rows): array {
            $active = [];
            foreach ($this->repository->activeForRevision($supplierId, $preparation['source_revision_id'], true) as $row) {
                $active[$row['employment_id']] = true;
            }
            $created = [];
            foreach ($rows as $row) {
                $author = $userId ?? $row['excluded_by'];
                if (isset($active[$row['employment_id']]) || $author === null) {
                    continue;
                }
                $created[] = $this->repository->insert([
                    'supplier_id' => $supplierId,
                    'run_id' => $row['run_id'],
                    'source_revision_id' => $preparation['source_revision_id'],
                    'period_start' => $row['period_start'],
                    'office_id' => $row['office_id'],
                    'employee_id' => $row['employee_id'],
                    'employment_id' => $row['employment_id'],
                    'reason' => mb_substr('Vyřazena z mzdového běhu: ' . $row['reason'], 0, 500),
                    'blocker_codes_json' => CanonicalJson::encode([self::CODE]),
                    'decided_environment' => $preparation['environment'],
                    'decided_preparation_id' => $preparation['id'],
                    'decided_snapshot_fingerprint' => $preparation['snapshot_fingerprint'],
                    'created_by' => $author,
                ]);
                $active[$row['employment_id']] = true;
            }

            return $created;
        });
    }
}
