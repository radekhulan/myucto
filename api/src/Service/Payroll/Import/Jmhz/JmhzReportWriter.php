<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Zápis jednoho naplánovaného formuláře ({@see JmhzReportPlanner}) do evidence.
 *
 * Každá skupina jde toutéž cestou jako karta, na které se údaj edituje:
 * identifikátory ČSSZ, sjednané podmínky, zákonná evidence osoby, vyživované
 * děti. Vlastní SQL do cizích tabulek tu není.
 *
 * Formulář je jedna transakce, každá skupina má vlastní savepoint (jako
 * {@see RegistrationImportWriter}): když jedna neprojde kontrolou karty,
 * zbytek se zapíše a důvod se vrátí ve zprávě. Každá skupina si stav přečte
 * znovu těsně před zápisem — zákonná evidence se ukládá jako cílový stav
 * VŠECH sekcí, takže zápis ze zastaralého čtení by vrátil předchozí krok.
 */
final class JmhzReportWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollDependantRepository $dependants,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly RegistrationImportLookup $lookup,
        private readonly JmhzReportLookup $jmhzLookup,
        private readonly JmhzChildClaims $childClaims,
    ) {}

    /**
     * @param array<string,mixed> $plan
     * @return array{status:string,message:?string,employee_id:?int,employment_id:?int,operations:list<string>}
     */
    public function apply(
        int $supplierId,
        string $environment,
        array $plan,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        /** @var JmhzBatchItem $item */
        $item = $plan['_item'];
        $steps = $plan['_steps'];
        $employeeId = (int) $plan['_employee_id'];
        $employmentId = (int) $plan['_employment_id'];
        $monthStart = $item->file->periodStart();
        $reference = JmhzReportPlanner::reference($item->fileSha256, $item->form->formGuid);
        $operations = [];
        $notes = [];

        $form = function () use (
            $supplierId,
            $environment,
            $item,
            $steps,
            $employeeId,
            $employmentId,
            $monthStart,
            $reference,
            $userId,
            $ip,
            $userAgent,
            &$operations,
            &$notes,
        ): void {
            $operations = [];
            $notes = [];
            $identifiers = $steps['identifiers'];
            if ($identifiers['person'] !== null || $identifiers['employment'] !== null) {
                $this->optional('Identifikátory ČSSZ', $notes, $operations, 'identifiers', fn () => $this->writeIdentifiers(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $identifiers['person'],
                    $identifiers['employment'],
                    $reference,
                    $userId,
                ));
            }
            if (is_array($steps['terms'])) {
                $this->optional('Podmínky vztahu', $notes, $operations, 'terms', fn () => $this->writeTerms(
                    $supplierId,
                    $employmentId,
                    $item,
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['declaration'])) {
                $this->optional('Prohlášení poplatníka', $notes, $operations, 'tax_declaration', fn () => $this->saveStatutory(
                    $supplierId,
                    $employeeId,
                    $monthStart,
                    fn (array $sections, ?string $frozen): array => JmhzStatutoryChanges::declaration(
                        $sections,
                        $frozen,
                        $monthStart,
                        (bool) $item->form->declarationSigned,
                        $reference,
                    )['sections'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if ($steps['credits'] === true) {
                $this->optional('Slevy na dani', $notes, $operations, 'tax_credit_claims', fn () => $this->saveStatutory(
                    $supplierId,
                    $employeeId,
                    $monthStart,
                    fn (array $sections, ?string $frozen): array => JmhzStatutoryChanges::credits(
                        $sections,
                        $frozen,
                        $monthStart,
                        $item->form->credits,
                        $reference,
                        $item->form->birthDate,
                    )['sections'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['social_discount'])) {
                $claimed = (bool) $steps['social_discount']['claimed'];
                $this->optional('Sleva pracujícího důchodce', $notes, $operations, 'social_discount', fn () => $this->saveStatutory(
                    $supplierId,
                    $employeeId,
                    $monthStart,
                    fn (array $sections, ?string $frozen): array => JmhzStatutoryChanges::socialDiscount(
                        $sections,
                        $frozen,
                        $monthStart,
                        $claimed,
                        $reference,
                    )['sections'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if ($steps['children'] === true) {
                $this->optional('Vyživované děti', $notes, $operations, 'dependants', fn () => $this->writeChildren(
                    $supplierId,
                    $employeeId,
                    $item,
                    $reference,
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
        };
        // Odvolanou transakci (1020/1213) zopakuje celou: rollback vrátil všechny skupiny
        // formuláře a každá si stav čte znovu těsně před zápisem.
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->atomic('jmhz_import_form', $form));

        return [
            'status' => 'applied',
            'message' => $notes === [] ? null : 'Zapsáno, ale část údajů se zapsat nepodařila: ' . implode(' ', $notes),
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'operations' => $operations,
        ];
    }

    /**
     * Identifikátory platí od nástupu (stejně jako u importu registrací):
     * OIČ i ID PPV označují vztah od jeho začátku.
     */
    private function writeIdentifiers(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?string $personIdentifier,
        ?string $employmentIdentifier,
        string $reference,
        ?int $userId,
    ): void {
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $validFrom = (string) ($row['start_date'] ?? '');
        if ($validFrom === '') {
            throw new \DomainException('Pracovní vztah nemá den nástupu, identifikátory nejde časově zařadit.');
        }
        if ($row['end_date'] !== null && $validFrom > $row['end_date']) {
            $validFrom = (string) $row['end_date'];
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            $environment,
            $personIdentifier,
            $employmentIdentifier,
            $validFrom,
            $reference,
            true,
            $userId,
            false,
            'jmhz_import',
        );
    }

    private function writeTerms(
        int $supplierId,
        int $employmentId,
        JmhzBatchItem $item,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $monthStart = $item->file->periodStart();
        $warnings = [];
        $desired = JmhzReportPlanner::desiredTerms($item->form, $warnings);
        $versions = $this->jmhzLookup->termVersions($supplierId, $employmentId);
        $covering = JmhzEvidenceTimeline::covering($versions, $monthStart);
        if ($covering === null || (int) $covering['id'] !== (int) ($versions[0]['id'] ?? 0)) {
            throw new \DomainException('Mezitím se změnila verze podmínek vztahu; obnovte náhled.');
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek.');
        $body = RegistrationImportWriter::termsBody(
            $current,
            'Údaje z importovaného měsíčního hlášení JMHZ za ' . $item->period() . '.',
        );
        foreach ($desired as $field => $value) {
            $body[$field] = $field === 'workload_basis_points' ? (int) $value : $value;
        }
        if (($body['jmhz_workplace_municipality_code'] ?? null) === null) {
            $body['jmhz_workplace_country_code'] = null;
        }
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $correct = (string) $covering['effective_from'] === $monthStart;
        $body['effective_from'] = $correct ? (string) $current['effective_from'] : $monthStart;
        $terms = $this->employmentValidator->terms(
            $body,
            $this->employments->currentCzIscoCode($supplierId, $employmentId),
            $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
            $this->employments->currentRelationType($supplierId, $employmentId),
        );
        if ($correct) {
            $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, $ip, $userAgent);
        } else {
            $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, $ip, $userAgent);
        }
    }

    /**
     * @param callable(array<string,list<array<string,mixed>>>,?string):array<string,list<array<string,mixed>>> $transform
     */
    private function saveStatutory(
        int $supplierId,
        int $employeeId,
        string $monthStart,
        callable $transform,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $view = $this->statutory->editorView($supplierId, $employeeId, $monthStart)
            ?? throw new \DomainException('Zákonná evidence zaměstnance nebyla nalezena.');
        $frozen = is_string($view['frozen_through'] ?? null) ? $view['frozen_through'] : null;
        $sections = $transform($view['sections'], $frozen);
        if ($sections === $view['sections']) {
            return;
        }
        $this->statutory->save($supplierId, $employeeId, ['sections' => $sections], $monthStart, $userId, $ip, $userAgent);
    }

    private function writeChildren(
        int $supplierId,
        int $employeeId,
        JmhzBatchItem $item,
        string $reference,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $monthStart = $item->file->periodStart();
        $overview = $this->dependants->overview($supplierId, $employeeId, $monthStart)
            ?? throw new \DomainException('Zaměstnanec nebyl nalezen.');
        $plan = $this->childClaims->plan($supplierId, $employeeId, $overview, $item->form->childCredit, $monthStart, $reference);
        $previousDay = JmhzEvidenceTimeline::previousDay($monthStart);

        foreach ($plan['ends'] as $end) {
            $claim = $end['claim'];
            $this->dependants->saveClaim(
                $supplierId,
                $employeeId,
                $end['dependant_id'],
                (int) $claim['id'],
                $this->childClaims->closed($claim, $previousDay),
                (int) $claim['row_version'],
                $monthStart,
                $userId,
                $ip,
                $userAgent,
            );
        }
        foreach ($plan['actions'] as $action) {
            $dependantId = $action['dependant_id'];
            if ($dependantId === null && is_array($action['dependant'])) {
                $view = $this->dependants->createDependant(
                    $supplierId,
                    $employeeId,
                    $action['dependant'],
                    $monthStart,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $dependantId = $this->createdDependantId($view, $action['dependant']);
            }
            if ($dependantId === null || !is_array($action['claim'])) {
                continue;
            }
            $covering = $action['covering'];
            if ($action['mode'] === 'update' && is_array($covering)) {
                $this->dependants->saveClaim(
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    (int) $covering['id'],
                    $action['claim'],
                    (int) $covering['row_version'],
                    $monthStart,
                    $userId,
                    $ip,
                    $userAgent,
                );
                continue;
            }
            if ($action['mode'] === 'split' && is_array($covering)) {
                $this->dependants->saveClaim(
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    (int) $covering['id'],
                    $this->childClaims->closed($covering, $previousDay),
                    (int) $covering['row_version'],
                    $monthStart,
                    $userId,
                    $ip,
                    $userAgent,
                );
            }
            $this->dependants->createClaim(
                $supplierId,
                $employeeId,
                $dependantId,
                $action['claim'],
                $monthStart,
                $userId,
                $ip,
                $userAgent,
            );
        }
    }

    /**
     * @param array<string,mixed> $view
     * @param array<string,mixed> $input
     */
    private function createdDependantId(array $view, array $input): ?int
    {
        $found = null;
        foreach ($view['dependants'] ?? [] as $dependant) {
            if ((string) $dependant['birth_date'] === $input['birth_date']
                && (string) $dependant['full_name'] === $input['full_name']
                && ($found === null || (int) $dependant['id'] > $found)
            ) {
                $found = (int) $dependant['id'];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $notes
     * @param list<string> $operations
     * @param callable():void $work
     */
    private function optional(string $label, array &$notes, array &$operations, string $operation, callable $work): void
    {
        try {
            $this->atomic('jmhz_import_step', $work);
            $operations[] = $operation;
        } catch (\Exception $e) {
            // Odvolaná transakce nevrátila jen tuhle skupinu, ale celý formulář: další
            // skupiny by se zapsaly v autocommitu bez identifikátorů a prohlášení.
            if (TransactionFailure::aborted($e) || !$this->db->pdo()->inTransaction()) {
                throw $e;
            }
            $notes[] = "{$label}: {$e->getMessage()}";
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function atomic(string $name, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $name);
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e) || !$pdo->inTransaction()) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
                throw $e;
            }
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            } catch (\PDOException) {
                // Savepoint zmizel s transakcí, kterou MariaDB odvolala pod přebalenou chybou.
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
