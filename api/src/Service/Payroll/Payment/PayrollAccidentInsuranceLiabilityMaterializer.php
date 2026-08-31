<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Zákonné pojištění odpovědnosti zaměstnavatele za škodu při pracovním úrazu
 * a nemoci z povolání (vyhláška č. 125/1993 Sb.) — čtvrtletní, na rozdíl od
 * ostatních závazků v tomhle adresáři, které vznikají z JEDNÉ měsíční revize.
 *
 * Volá se stejně jako ostatní materializery — z revize schváleného měsíce —
 * ale REÁLNĚ pracuje jen tehdy, když je ten měsíc POSLEDNÍ MĚSÍC ČTVRTLETÍ
 * (březen/červen/září/prosinec). V ostatních měsících je to no-op. Kdyby
 * některý z předchozích dvou měsíců čtvrtletí ještě neměl schválenou revizi
 * s vypočteným výsledkem sociálního pojištění, materializace založí chybu —
 * NIKDY neodhaduje vyměřovací základ z neúplných dat. Volající (lenient
 * endpoint `POST /payroll/revisions/{id}/payments/liabilities`) chybu ukáže
 * jako `preparation_issue`, ne jako tvrdé selhání zbytku přípravy plateb —
 * proto se tenhle materializer záměrně NEZAPOJUJE do fail-closed
 * {@see \MyInvoice\Service\Payroll\Run\PayrollRunPaymentPreparationService},
 * která by jinak zablokovala přechod běhu do `payment_ready` u firem, které
 * ještě nemají spočítané všechny tři měsíce čtvrtletí.
 */
final class PayrollAccidentInsuranceLiabilityMaterializer
{
    private const LIABILITY_KIND = 'statutory_insurance';

    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollAccidentInsuranceRateRepository $rates,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly Connection $db,
        private readonly PayrollLevyDeadlinePolicy $deadlines,
        private readonly PayrollAccidentInsuranceCalculator $calculator,
    ) {}

    /** @return array{liability_ids:list<int>,created_count:int} */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize zákonného pojištění odpovědnosti musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace zákonného pojištění odpovědnosti není platný.',
            );
        }

        return $this->liabilities->transaction(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): array {
            $revision = $this->liabilities->lockRevision($supplierId, $revisionId);
            if ($revision === null) {
                throw new \DomainException('Mzdová revize neexistuje.');
            }
            if (($revision['revision_status'] ?? null) !== 'approved'
                || ($revision['revision_no'] ?? null)
                    !== ($revision['current_revision_no'] ?? null)
            ) {
                throw new \DomainException(
                    'Závazek lze vytvořit jen z aktuální schválené revize.',
                );
            }
            $periodStart = $revision['period_start'];
            $month = (int) substr($periodStart, 5, 2);
            if (!in_array($month, [3, 6, 9, 12], true)) {
                return ['liability_ids' => [], 'created_count' => 0];
            }
            $year = (int) substr($periodStart, 0, 4);
            $quarterMonths = [$month - 2, $month - 1, $month];
            $assessmentBaseMinor = 0;
            foreach ($quarterMonths as $quarterMonth) {
                $assessmentBaseMinor += $this->monthAssessmentBase(
                    $supplierId,
                    sprintf('%04d-%02d-01', $year, $quarterMonth),
                );
            }
            $quarterStart = sprintf('%04d-%02d-01', $year, $quarterMonths[0]);

            $rate = $this->rates->effectiveOn($supplierId, $quarterStart);
            if ($rate === null) {
                throw new \DomainException(
                    'Sazba zákonného pojištění odpovědnosti není nastavena. '
                    . 'Doplňte ji v Nastavení mezd podle výměru pojišťovny.',
                );
            }
            $premiumMinor = $this->calculator->premiumMinor(
                $assessmentBaseMinor,
                $rate['rate_per_mille'],
            );
            $dueOn = $this->deadlines->dueOn(
                PayrollLevyDeadlinePolicy::ACCIDENT_INSURANCE,
                $periodStart,
            );

            $reference = sprintf(
                'accident-insurance:quarter:%04d-%02d',
                $year,
                $quarterMonths[0],
            );
            $target = $this->target(
                $supplierId,
                $rate['institution_code'],
                $dueOn,
            );
            $source = [
                'schema_reference' => 'payroll-payment-accident-insurance-source.v1',
                'run_id' => $revision['run_id'],
                'revision_id' => $revisionId,
                'revision_no' => $revision['revision_no'],
                'quarter_start' => $quarterStart,
                'quarter_end' => $periodStart,
                'assessment_base_minor_units' => $assessmentBaseMinor,
                'rate_per_mille' => $rate['rate_per_mille'],
                'rate_effective_from' => $rate['effective_from'],
                'logical_reference' => $reference,
                'recipient_reference' => $target['recipient_reference'],
                ...$target['target_snapshot'],
                'target_amount_minor' => $premiumMinor,
            ];

            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    self::LIABILITY_KIND,
                ),
                $reference,
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== null) {
                throw new \DomainException(
                    'Další revize zákonného pojištění odpovědnosti musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }
            if ($prior !== null
                && ($prior['recipient_reference'] !== $target['recipient_reference']
                    || $prior['target_snapshot'] !== $target['target_snapshot'])
            ) {
                throw new \DomainException(
                    'Ověřený cíl zákonného pojištění odpovědnosti se proti '
                    . 'předchozímu závazku změnil.',
                );
            }
            $priorSigned = $prior['signed_minor'] ?? 0;
            $delta = $premiumMinor - $priorSigned;
            if ($delta === 0) {
                return ['liability_ids' => [], 'created_count' => 0];
            }
            $direction = $delta > 0 ? 'outgoing' : 'incoming';
            $amount = abs($delta);
            $source['prior_signed_minor'] = $priorSigned;
            $source['delta_signed_minor'] = $delta;
            $sourceJson = CanonicalJson::encode($source);
            $sourceHash = hash('sha256', $sourceJson);
            $idempotencyHash = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-payment-accident-insurance-idempotency.v1',
                    'supplier_id' => $supplierId,
                    'revision_id' => $revisionId,
                    'logical_reference' => $reference,
                    'source_snapshot_hash' => $sourceHash,
                ]),
                true,
            );

            $existing = $this->liabilities->findAnyForUpdate(
                $supplierId,
                $revisionId,
                $reference,
            );
            if ($existing !== null) {
                if (($existing['direction'] ?? null) !== $direction
                    || ($existing['amount_minor'] ?? null) !== $amount
                    || !is_string($existing['source_snapshot_hash'] ?? null)
                    || !hash_equals($existing['source_snapshot_hash'], $sourceHash)
                    || !is_string($existing['idempotency_key_hash'] ?? null)
                    || !hash_equals($existing['idempotency_key_hash'], $idempotencyHash)
                ) {
                    throw new \DomainException(
                        'Idempotentní replay zákonného pojištění odpovědnosti nesouhlasí.',
                    );
                }

                return [
                    'liability_ids' => [$existing['id']],
                    'created_count' => 0,
                ];
            }

            $id = $this->liabilities->insertInstitutional(
                $supplierId,
                $revisionId,
                $reference,
                self::LIABILITY_KIND,
                $direction,
                $target['recipient_reference'],
                $dueOn,
                $amount,
                $prior['latest_id'] ?? null,
                $sourceJson,
                $sourceHash,
                $idempotencyHash,
                $actorUserId,
            );

            return ['liability_ids' => [$id], 'created_count' => 1];
        });
    }

    /**
     * Vyměřovací základ sociálního pojištění celé firmy za JEDEN měsíc
     * čtvrtletí, čtený ze schváleného a otiskem ověřeného výsledku — nepočítá
     * se znovu vlastní cestou (§ 12 odst. 2 vyhlášky odkazuje na vyměřovací
     * základ sociálního pojištění, ne na vlastní definici).
     *
     * OTEVŘENÝ NÁLEZ, rešerše 31. 8. 2026: bere se `capped_…`, tedy základ PO
     * ročním maximu podle § 15a zákona č. 589/1992 Sb. Kooperativa i Generali
     * Česká pojišťovna ale shodně uvádějí, že se maximální vyměřovací základ
     * na zákonné pojištění odpovědnosti NEVZTAHUJE — § 12 odst. 2 odkazuje jen
     * na § 5 odst. 1 písm. a) toho zákona, ne na § 15a. U firem se zaměstnanci
     * nad ročním stropem tak vychází pojistné nižší, než má být. Správně by se
     * měl brát `participating_assessment_base_minor_units`; oprava se ale
     * promítne do už zmaterializovaných závazků, takže je to samostatné
     * rozhodnutí, ne vedlejší efekt doplnění sazebníku.
     */
    private function monthAssessmentBase(int $supplierId, string $monthStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND run.period_start = ?
                AND revision.status = "approved"
                AND revision.revision_no = run.current_revision_no'
        );
        $statement->execute([$supplierId, $monthStart]);
        $revisionId = $statement->fetchColumn();
        if ($revisionId === false) {
            throw new \DomainException(sprintf(
                'Čtvrtletí není kompletní: měsíc %s nemá schválenou mzdovou revizi.',
                $monthStart,
            ));
        }

        $result = $this->statutoryResults->find(
            $supplierId,
            (int) $revisionId,
            'social_insurance',
        );
        if ($result === null || ($result['result_status'] ?? null) !== 'calculated') {
            throw new \DomainException(sprintf(
                'Měsíc %s nemá vypočtený výsledek sociálního pojištění.',
                $monthStart,
            ));
        }
        $root = $result['result_snapshot'] ?? null;
        if (!is_array($root) || array_is_list($root)
            || ($root['status'] ?? null) !== 'calculated'
        ) {
            throw new \DomainException('Výsledek sociálního pojištění není úplný.');
        }
        $rootHash = $result['result_snapshot_hash'] ?? null;
        if (!is_string($rootHash)
            || !hash_equals($rootHash, hash('sha256', CanonicalJson::encode($root)))
        ) {
            throw new \DomainException('Otisk výsledku sociálního pojištění nesouhlasí.');
        }
        $base = $root['capped_assessment_base_minor_units'] ?? null;
        if (!is_int($base) || $base < 0) {
            throw new \DomainException(
                'Vyměřovací základ sociálního pojištění není platné číslo.',
            );
        }

        return $base;
    }

    /**
     * @return array{recipient_reference:string,target_snapshot:array<string,mixed>}
     */
    private function target(
        int $supplierId,
        string $institutionCode,
        string $dueOn,
    ): array {
        $accounts = $this->institutions->lockEffectivePaymentTargets(
            $supplierId,
            'statutory_insurance',
            $institutionCode,
            'CZK',
            $dueOn,
        );
        if (count($accounts) !== 1) {
            throw new \DomainException(
                count($accounts) === 0
                    ? 'Pojistitel zákonného pojištění odpovědnosti nemá '
                        . 'účinný ověřený účet. Doplňte ho v Nastavení mezd '
                        . '(Instituce).'
                    : 'Pojistitel zákonného pojištění odpovědnosti má '
                        . 'nejednoznačný účet.',
            );
        }
        $account = $accounts[0];
        $this->assertVerifiedAccount($supplierId, $dueOn, $account);
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-accident-insurance-target-verification.v1',
                'institution_type' => 'statutory_insurance',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'variable_symbol' => $account['variable_symbol'],
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ]),
        );

        return [
            'recipient_reference' =>
                "institution:statutory_insurance:{$institutionCode}:account:"
                . $account['id'],
            'target_snapshot' => [
                'institution_type' => 'statutory_insurance',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payment_target_verification_hash' => $verificationHash,
                'variable_symbol' => $account['variable_symbol'],
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
            ],
        ];
    }

    /** @param array<string,mixed> $account */
    private function assertVerifiedAccount(
        int $supplierId,
        string $dueOn,
        array $account,
    ): void {
        if (!in_array($account['source_kind'], [
            'official_registry',
            'official_document',
            'institution_notice',
            'user_verified',
        ], true)
            || $account['verified_by'] === null
            || $account['verified_by'] <= 0
            || $account['verified_on'] > $dueOn
            || preg_match('/^[0-9a-f]{64}$/D', $account['bank_account_hash']) !== 1
        ) {
            throw new \DomainException(
                'Účet pojistitele zákonného pojištění odpovědnosti není ověřený.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $account['id'],
            PayrollRevealPurpose::PAYMENT_LIABILITY_ACCOUNT,
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $actualHash)) {
            throw new \DomainException(
                'Obsah účtu pojistitele neodpovídá uloženému otisku.',
            );
        }
    }

    /**
     * @param list<array{
     *   id:int,revision_no:int,liability_reference:string,direction:string,
     *   recipient_reference:string,amount_minor:int,
     *   source_snapshot_json:string,source_snapshot_hash:string
     * }> $rows
     * @return array{
     *   recipient_reference:string,signed_minor:int,latest_id:int,
     *   target_snapshot:array<string,mixed>
     * }|null
     */
    private function priorState(array $rows, string $reference): ?array
    {
        $state = null;
        foreach ($rows as $row) {
            if ($row['liability_reference'] !== $reference) {
                continue;
            }
            $source = json_decode($row['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($source)
                || ($source['schema_reference'] ?? null)
                    !== 'payroll-payment-accident-insurance-source.v1'
                || CanonicalJson::encode($source) !== $row['source_snapshot_json']
                || !hash_equals($row['source_snapshot_hash'], hash('sha256', $row['source_snapshot_json']))
            ) {
                throw new \DomainException(
                    'Dřívější závazek zákonného pojištění odpovědnosti nemá platný zdroj.',
                );
            }
            $target = [];
            foreach ([
                'institution_type', 'institution_code', 'payment_target_id',
                'payment_target_hash', 'payment_target_row_version',
                'payment_target_verification_hash', 'variable_symbol',
                'specific_symbol', 'constant_symbol',
            ] as $field) {
                $target[$field] = $source[$field] ?? null;
            }
            if ($state === null) {
                $state = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $target,
                ];
            } elseif ($state['recipient_reference'] !== $row['recipient_reference']
                || $state['target_snapshot'] !== $target
            ) {
                throw new \DomainException(
                    'Řetězec zákonného pojištění odpovědnosti změnil zmrazený cíl.',
                );
            }
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $state['signed_minor'] += $signed;
            $state['latest_id'] = $row['id'];
        }
        if ($state !== null && $state['signed_minor'] < 0) {
            throw new \DomainException(
                'Dřívější závazky zákonného pojištění odpovědnosti mají záporný zůstatek.',
            );
        }

        return $state;
    }
}
