<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyPaymentDate;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use Psr\Clock\ClockInterface;

final class PayrollPaymentBatchBuilder
{
    private const MAX_LIABILITIES = 500;
    private const INSTITUTION_LIABILITY_KINDS = [
        'health_insurance',
        'social_insurance',
        'advance_tax',
        'withholding_tax',
        'enforcement',
        'insolvency',
        'risky_savings',
    ];

    public function __construct(
        private readonly PayrollPaymentBatchRepository $batches,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
        private readonly IbanValidator $ibanValidator,
        private readonly CzechBankAccountValidator $czechBankAccountValidator,
        private readonly ClockInterface $clock,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /**
     * @param list<array<string,mixed>> $requests
     * @return array{
     *   batch_id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   planned_payment_date:string,
     *   currency_code:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   snapshot_hash:string,
     *   created:bool,
     *   replayed:bool
     * }
     */
    public function build(
        int $supplierId,
        string $exportFormat,
        string $payerReference,
        array $requests,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma platební dávky musí být kladné číslo.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel platební dávky není platný.',
            );
        }
        $this->productionGate->assertActive($supplierId);
        if (!in_array($exportFormat, ['abo', 'sepa', 'manual'], true)) {
            throw new \InvalidArgumentException(
                'Formát mzdové platební dávky není podporovaný.',
            );
        }
        if ($payerReference === ''
            || mb_strlen($payerReference, 'UTF-8') > 190
        ) {
            throw new \InvalidArgumentException(
                'Reference účtu plátce není platná.',
            );
        }
        $normalizedRequests = $this->normalizeRequests($requests);
        $idempotencyMaterial = CanonicalJson::encode([
            'schema_reference' => 'payroll-payment-batch-idempotency.v1',
            'supplier_id' => $supplierId,
            'export_format' => $exportFormat,
            'payer_reference' => $payerReference,
            'requests' => $normalizedRequests,
        ]);
        $idempotencyHash = hash(
            'sha256',
            $idempotencyMaterial,
            true,
        );
        $idempotencyHex = bin2hex($idempotencyHash);

        return $this->batches->transaction(function () use (
            $supplierId,
            $exportFormat,
            $payerReference,
            $normalizedRequests,
            $idempotencyHash,
            $idempotencyHex,
            $actorUserId,
        ): array {
            if (!$this->batches->lockSupplier($supplierId)) {
                throw new \DomainException(
                    'Firma platební dávky nebyla nalezena.',
                );
            }
            $existing = $this->batches->findByIdempotencyForUpdate(
                $supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return [
                    ...$existing,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            $requestByLiability = [];
            foreach ($normalizedRequests as $request) {
                $requestByLiability[$request['liability_id']] =
                    $request['amount_minor'];
            }
            $liabilityIds = array_keys($requestByLiability);
            $liabilities = $this->batches->lockLiabilities(
                $supplierId,
                $liabilityIds,
            );
            if (count($liabilities) !== count($liabilityIds)) {
                throw new \DomainException(
                    'Některý vybraný platební závazek neexistuje.',
                );
            }

            $existing = $this->batches->findByIdempotencyForUpdate(
                $supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return [
                    ...$existing,
                    'created' => false,
                    'replayed' => true,
                ];
            }

            $plannedDate = null;
            $currencyCode = null;
            $channel = null;
            $declaredTotal = 0;
            $groups = [];
            $onlyLevies = true;
            foreach ($liabilities as $liability) {
                $amount = $requestByLiability[$liability['id']] ?? null;
                if ($amount === null) {
                    throw new \LogicException(
                        'Uzamčený závazek nebyl požadován.',
                    );
                }
                $this->assertLiability($liability, $amount);
                $onlyLevies = $onlyLevies
                    && PayrollLevyPaymentDate::isLevyLiabilityKind(
                        $liability['liability_kind'],
                    );
                $plannedDate ??= $liability['due_on'];
                $currencyCode ??= $liability['currency_code'];
                if ($plannedDate !== $liability['due_on']
                    || $currencyCode !== $liability['currency_code']
                ) {
                    throw new \DomainException(
                        'Jedna dávka musí mít shodné datum a měnu.',
                    );
                }
                $recipientChannel = str_starts_with(
                    $liability['recipient_reference'],
                    'employee-cash:',
                ) ? 'cash' : 'bank';
                $channel ??= $recipientChannel;
                if ($channel !== $recipientChannel) {
                    throw new \DomainException(
                        'Bankovní a hotovostní výplaty nelze míchat.',
                    );
                }
                $declaredTotal = $this->addAmounts(
                    $declaredTotal,
                    $amount,
                );
                $recipient = $liability['recipient_reference'];
                $source = $this->sourceSnapshot($liability);
                /*
                 * Do jedné platby se slévají závazky se STEJNÝM zmrazeným
                 * cílem, ne jen se stejnou referencí příjemce. Sociální odvod
                 * dvou mzdových účtáren jde na týž účet OSSZ, ale pod různým
                 * variabilním symbolem — jsou to dvě platby, ne jedna. Dokud
                 * bylo klíčem jen `recipient_reference`, skončil takový výběr
                 * na neurčitém „nejednoznačný zmrazený cíl" a zaplatit se
                 * nedaly společně vůbec.
                 */
                $groupKey = $recipient . "\0"
                    . $this->frozenTargetFingerprint($source);
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'recipient_reference' => $recipient,
                        'employee_id' => $liability['employee_id'],
                        'liability_kind' => $liability['liability_kind'],
                        'amount_minor' => 0,
                        'liabilities' => [],
                        'source' => $source,
                    ];
                } elseif ($groups[$groupKey]['employee_id']
                    !== $liability['employee_id']
                    || $groups[$groupKey]['liability_kind']
                        !== $liability['liability_kind']
                    || !$this->sameFrozenTarget(
                        $groups[$groupKey]['source'],
                        $source,
                    )
                ) {
                    throw new \DomainException(
                        'Reference příjemce nemá v dávce jednoznačný zmrazený cíl.',
                    );
                }
                $groups[$groupKey]['amount_minor'] = $this->addAmounts(
                    $groups[$groupKey]['amount_minor'],
                    $amount,
                );
                $groups[$groupKey]['liabilities'][] = [
                    'id' => $liability['id'],
                    'reference' => $liability['liability_reference'],
                    'amount_minor' => $amount,
                    'source_snapshot_hash' =>
                        $liability['source_snapshot_hash'],
                ];
            }
            ksort($groups, SORT_STRING);
            unset($recipient, $source, $groupKey);

            /*
             * `due_on` závazku je ZÁKONNÝ TERMÍN. Lhůta u pojistného i u daně
             * se ale plní připsáním na účet instituce, ne podáním příkazu —
             * příkaz proto datujeme o rezervu na mezibankovní převod dřív.
             * Viz PayrollLevyPaymentDate (§ 9 odst. 2 zák. 589/1992 Sb.).
             *
             * Předsouvá se jen dávka složená VÝHRADNĚ z odvodů. Čistá mzda má
             * v `due_on` datum výplaty a to se posouvat nesmí; smíšenou dávku
             * proto necháváme na zákonném datu (a takový výběr stejně vzniká
             * jen tehdy, když se datum splatnosti obou druhů shoduje).
             */
            $statutoryDueOn = $plannedDate;
            if ($onlyLevies) {
                $plannedDate = PayrollLevyPaymentDate::forDueOn($plannedDate);
            }

            $payerInstruction = $this->payerInstruction(
                $supplierId,
                $channel,
                $exportFormat,
                $payerReference,
                $currencyCode,
            );
            $batchReference = 'payroll-batch:'
                . substr($idempotencyHex, 0, 48);
            $preparedItems = [];
            foreach ($groups as $groupKey => $group) {
                $recipient = $group['recipient_reference'];
                $itemReference = 'payroll-item:'
                    . substr(
                        hash(
                            'sha256',
                            $idempotencyHex . "\0" . $groupKey,
                        ),
                        0,
                        48,
                    );
                $instruction = $this->recipientInstruction(
                    $supplierId,
                    $exportFormat,
                    $currencyCode,
                    $plannedDate,
                    $group,
                );
                $instructionJson = CanonicalJson::encode($instruction);
                $instructionHash = hash('sha256', $instructionJson);
                $preparedItems[] = [
                    'item_reference' => $itemReference,
                    'recipient_reference' => $recipient,
                    'amount_minor' => $group['amount_minor'],
                    'instruction_ciphertext' =>
                        $this->encryption->encryptFor(
                            $instructionJson,
                            "payroll-payment-item:{$supplierId}:"
                                . $itemReference,
                        ),
                    'instruction_hash' => $instructionHash,
                    'liabilities' => $group['liabilities'],
                ];
            }
            $declaredItemCount = count($preparedItems);
            $creationDateTime = \DateTimeImmutable::createFromInterface(
                $this->clock->now(),
            )->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:sP');
            $snapshot = [
                'schema_reference' =>
                    'payroll-payment-batch-snapshot.v1',
                'batch_reference' => $batchReference,
                'channel' => $channel,
                'export_format' => $exportFormat,
                'direction' => 'outgoing',
                'planned_payment_date' => $plannedDate,
                'statutory_due_on' => $statutoryDueOn,
                'is_shifted' => $plannedDate !== $statutoryDueOn,
                'creation_datetime' => $creationDateTime,
                'currency_code' => $currencyCode,
                'payer_reference' => $payerReference,
                'payer_instruction' => $payerInstruction,
                'declared_total_minor' => $declaredTotal,
                'declared_item_count' => $declaredItemCount,
                'items' => array_map(
                    static fn (array $item): array => [
                        'item_reference' => $item['item_reference'],
                        'recipient_reference' =>
                            $item['recipient_reference'],
                        'amount_minor' => $item['amount_minor'],
                        'instruction_hash' =>
                            $item['instruction_hash'],
                        'liabilities' => $item['liabilities'],
                    ],
                    $preparedItems,
                ),
            ];
            $snapshotJson = CanonicalJson::encode($snapshot);
            $snapshotHash = hash('sha256', $snapshotJson);
            $snapshotCiphertext = $this->encryption->encryptFor(
                $snapshotJson,
                "payroll-payment-batch:{$supplierId}:{$batchReference}",
            );
            $batchId = $this->batches->insertBatch(
                $supplierId,
                $batchReference,
                $channel,
                $exportFormat,
                $plannedDate,
                $currencyCode,
                $payerReference,
                $declaredTotal,
                $declaredItemCount,
                $snapshotCiphertext,
                $snapshotHash,
                $idempotencyHash,
                $actorUserId,
            );
            foreach ($preparedItems as $item) {
                $itemId = $this->batches->insertItem(
                    $supplierId,
                    $batchId,
                    $item['item_reference'],
                    $item['recipient_reference'],
                    $item['amount_minor'],
                    $item['instruction_ciphertext'],
                    $item['instruction_hash'],
                    hash(
                        'sha256',
                        $idempotencyHex . "\0"
                            . $item['item_reference'],
                        true,
                    ),
                );
                foreach ($item['liabilities'] as $allocation) {
                    $this->batches->insertAllocation(
                        $supplierId,
                        $itemId,
                        $allocation['id'],
                        $allocation['amount_minor'],
                        hash(
                            'sha256',
                            $idempotencyHex . "\0"
                                . $item['item_reference'] . "\0"
                                . $allocation['id'],
                            true,
                        ),
                    );
                }
            }

            return [
                'batch_id' => $batchId,
                'batch_reference' => $batchReference,
                'channel' => $channel,
                'export_format' => $exportFormat,
                'planned_payment_date' => $plannedDate,
                'currency_code' => $currencyCode,
                'declared_total_minor' => $declaredTotal,
                'declared_item_count' => $declaredItemCount,
                'snapshot_hash' => $snapshotHash,
                'created' => true,
                'replayed' => false,
            ];
        });
    }

    /**
     * @param list<array<string,mixed>> $requests
     * @return non-empty-list<array{liability_id:int,amount_minor:int}>
     */
    private function normalizeRequests(array $requests): array
    {
        if ($requests === [] || count($requests) > self::MAX_LIABILITIES) {
            throw new \InvalidArgumentException(
                'Platební dávka musí obsahovat 1 až 500 závazků.',
            );
        }
        $result = [];
        $seen = [];
        foreach ($requests as $request) {
            $liabilityId = $request['liability_id'] ?? null;
            $amountMinor = $request['amount_minor'] ?? null;
            if (!is_int($liabilityId)
                || !is_int($amountMinor)
                || $liabilityId <= 0
                || $amountMinor <= 0
            ) {
                throw new \InvalidArgumentException(
                    'Závazek i částka dávky musí být kladné.',
                );
            }
            if (isset($seen[$liabilityId])) {
                throw new \InvalidArgumentException(
                    'Jeden závazek lze v dávce uvést pouze jednou.',
                );
            }
            $seen[$liabilityId] = true;
            $result[] = [
                'liability_id' => $liabilityId,
                'amount_minor' => $amountMinor,
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int =>
                $left['liability_id'] <=> $right['liability_id'],
        );

        return $result;
    }

    /**
     * @param array{
     *   id:int,
     *   employee_id:?int,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   currency_code:string,
     *   amount_minor:int,
     *   allocated_minor:int
     * } $liability
     */
    private function assertLiability(array $liability, int $amount): void
    {
        if ($liability['direction'] !== 'outgoing') {
            throw new \DomainException(
                'Příchozí opravný závazek nelze odeslat v platební dávce; '
                . 'evidujte jej samostatným příjmovým bankovním nebo pokladním dokladem.',
            );
        }
        $netWage = $liability['liability_kind'] === 'net_wage'
            && $liability['employee_id'] !== null;
        $institutional = $liability['employee_id'] === null
            && in_array(
                $liability['liability_kind'],
                self::INSTITUTION_LIABILITY_KINDS,
                true,
            );
        if (!$netWage && !$institutional) {
            throw new \DomainException(
                'Druh závazku zatím nelze vložit do platební dávky.',
            );
        }
        $this->date($liability['due_on'], 'datum splatnosti závazku');
        if (preg_match('/^[A-Z]{3}$/D', $liability['currency_code']) !== 1) {
            throw new \DomainException('Měna závazku není platná.');
        }
        if ($liability['amount_minor'] <= 0
            || $liability['allocated_minor'] < 0
            || $liability['allocated_minor'] > $liability['amount_minor']
        ) {
            throw new \UnexpectedValueException(
                'Uložená alokace platebního závazku není platná.',
            );
        }
        $open = $liability['amount_minor']
            - $liability['allocated_minor'];
        if ($amount > $open) {
            throw new \DomainException(
                'Požadovaná platba překračuje otevřenou částku závazku.',
            );
        }
        if ($netWage) {
            $employeeId = $liability['employee_id'];
            if ($liability['recipient_reference']
                === "employee-cash:{$employeeId}"
            ) {
                return;
            }
            if (preg_match(
                '/^employee-account:[1-9][0-9]*$/D',
                $liability['recipient_reference'],
            ) === 1) {
                return;
            }
        } elseif ($this->institutionReference(
            $liability['liability_kind'],
            $liability['recipient_reference'],
        ) !== null) {
            return;
        }
        throw new \DomainException(
            'Platební závazek nemá bezpečnou referenci příjemce.',
        );
    }

    /**
     * @param array{
     *   employee_id:?int,
     *   liability_kind:string,
     *   recipient_reference:string,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * } $liability
     * @return array<string,mixed>
     */
    private function sourceSnapshot(array $liability): array
    {
        try {
            $decoded = json_decode(
                $liability['source_snapshot_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \DomainException(
                'Zdrojový snapshot závazku není platný.',
                previous: $exception,
            );
        }
        $source = $this->object($decoded, 'zdrojový snapshot závazku');
        $canonical = CanonicalJson::encode($source);
        if ($canonical !== $liability['source_snapshot_json']
            || !hash_equals(
                $liability['source_snapshot_hash'],
                hash('sha256', $canonical),
            )
            || !in_array($source['schema_reference'] ?? null, [
                'payroll-payment-net-wage-source.v1',
                'payroll-payment-health-insurance-source.v1',
                'payroll-payment-social-insurance-source.v1',
                'payroll-payment-income-tax-source.v1',
                'payroll-payment-enforcement-source.v1',
                'payroll-payment-insolvency-source.v1',
                'payroll-payment-risky-savings-source.v1',
            ], true)
            || ($source['recipient_reference'] ?? null)
                !== $liability['recipient_reference']
        ) {
            throw new \DomainException(
                'Závazek neodpovídá svému zmrazenému zdroji.',
            );
        }
        if ($liability['liability_kind'] === 'net_wage'
            && (
                $source['schema_reference']
                    !== 'payroll-payment-net-wage-source.v1'
                || $liability['employee_id'] === null
                || ($source['person_id'] ?? null)
                    !== $liability['employee_id']
            )
        ) {
            throw new \DomainException(
                'Závazek čisté mzdy neodpovídá své osobě.',
            );
        }
        $expectedInstitution = match ($liability['liability_kind']) {
            'health_insurance' => [
                'payroll-payment-health-insurance-source.v1',
                'health_insurer',
            ],
            'social_insurance' => [
                'payroll-payment-social-insurance-source.v1',
                'social_security',
            ],
            'advance_tax', 'withholding_tax' => [
                'payroll-payment-income-tax-source.v1',
                'tax_office',
            ],
            'enforcement' => [
                'payroll-payment-enforcement-source.v1',
                'other_recipient',
            ],
            'insolvency' => [
                'payroll-payment-insolvency-source.v1',
                'other_recipient',
            ],
            'risky_savings' => [
                'payroll-payment-risky-savings-source.v1',
                'other_recipient',
            ],
            default => null,
        };
        if ($expectedInstitution !== null
            && (
                $liability['employee_id'] !== null
                || $source['schema_reference'] !== $expectedInstitution[0]
                || ($source['institution_type'] ?? null)
                    !== $expectedInstitution[1]
                || ($source['liability_kind']
                    ?? $liability['liability_kind'])
                    !== $liability['liability_kind']
            )
        ) {
            throw new \DomainException(
                'Institucionální závazek neodpovídá svému zmrazenému zdroji.',
            );
        }
        if ($expectedInstitution === null
            && $liability['liability_kind'] !== 'net_wage'
        ) {
            throw new \DomainException(
                'Druh závazku neodpovídá svému zmrazenému zdroji.',
            );
        }

        return $source;
    }

    /**
     * Otisk zmrazeného cíle platby — sjednocuje závazky, které se dají poslat
     * JEDNOU platbou. Sleduje tytéž položky jako {@see self::sameFrozenTarget()},
     * aby se seskupení a jeho kontrola nikdy nerozešly.
     *
     * @param array<string,mixed> $source
     */
    private function frozenTargetFingerprint(array $source): string
    {
        $target = [];
        foreach (self::FROZEN_TARGET_FIELDS as $field) {
            $target[$field] = $source[$field] ?? null;
        }

        return hash('sha256', CanonicalJson::encode($target));
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameFrozenTarget(array $left, array $right): bool
    {
        foreach (self::FROZEN_TARGET_FIELDS as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @var list<string> */
    private const FROZEN_TARGET_FIELDS = [
        'institution_type',
        'institution_code',
        'payment_target_id',
        'payment_target_hash',
        'payment_target_row_version',
        'payment_target_verification_hash',
        'payroll_office_id',
        'payroll_office_code',
        'payroll_office_row_version',
        'employer_settings_row_version',
        'variable_symbol',
        'specific_symbol',
        'constant_symbol',
    ];

    /**
     * @return array<string,mixed>|null
     */
    private function payerInstruction(
        int $supplierId,
        string $channel,
        string $exportFormat,
        string $payerReference,
        string $currencyCode,
    ): ?array {
        if ($channel === 'cash') {
            if ($exportFormat !== 'manual' || $payerReference !== 'cash') {
                throw new \DomainException(
                    'Hotovostní dávka vyžaduje ruční formát a referenci cash.',
                );
            }

            return null;
        }
        $accountHolderName = $this->batches->lockSupplierName(
            $supplierId,
        );
        if ($accountHolderName === null) {
            throw new \DomainException(
                'Firma plátce nemá úplný název pro platební dávku.',
            );
        }
        if ($exportFormat !== 'abo' && $exportFormat !== 'sepa') {
            throw new \DomainException(
                'Bankovní dávka vyžaduje formát ABO nebo SEPA.',
            );
        }
        if (preg_match(
            '/^currency:([1-9][0-9]*)$/D',
            $payerReference,
            $match,
        ) !== 1) {
            throw new \DomainException(
                'Bankovní dávka nemá bezpečnou referenci účtu plátce.',
            );
        }
        $payer = $this->batches->lockPayerCurrency(
            $supplierId,
            (int) $match[1],
        );
        if ($payer === null
            || !$payer['is_active']
            || $payer['code'] !== $currencyCode
        ) {
            throw new \DomainException(
                'Účet plátce není aktivní v měně dávky.',
            );
        }
        if ($exportFormat === 'abo') {
            if ($currencyCode !== 'CZK'
                || $payer['account_number'] === null
                || $payer['bank_code'] === null
            ) {
                throw new \DomainException(
                    'ABO dávka vyžaduje úplný korunový účet plátce.',
                );
            }
            $account = $this->czechAccount(
                $payer['account_number'] . '/' . $payer['bank_code'],
                'účet plátce',
            );

            return [
                'account_holder_name' => $accountHolderName,
                'account_number' => $account['account_number'],
                'bank_code' => $account['bank_code'],
            ];
        }
        if ($currencyCode !== 'EUR' || $payer['iban'] === null) {
            throw new \DomainException(
                'SEPA dávka vyžaduje eurový IBAN plátce.',
            );
        }
        $iban = $this->ibanValidator->normalize($payer['iban']);
        if (!$this->ibanValidator->isValid($iban)
            || ($payer['bic'] !== null
                && !$this->ibanValidator->isValidBic($payer['bic']))
        ) {
            throw new \DomainException(
                'SEPA účet plátce není platný.',
            );
        }

        return [
            'account_holder_name' => $accountHolderName,
            'iban' => $iban,
            'bic' => $payer['bic'] === null
                ? null
                : strtoupper(trim($payer['bic'])),
        ];
    }

    /**
     * @param array{
     *   recipient_reference:string,
     *   employee_id:?int,
     *   liability_kind:string,
     *   amount_minor:int,
     *   liabilities:list<array{
     *     id:int,
     *     reference:string,
     *     amount_minor:int,
     *     source_snapshot_hash:string
     *   }>,
     *   source:array<string,mixed>
     * } $group
     * @return array<string,mixed>
     */
    private function recipientInstruction(
        int $supplierId,
        string $exportFormat,
        string $currencyCode,
        string $plannedDate,
        array $group,
    ): array {
        if ($group['employee_id'] === null) {
            return $this->institutionRecipientInstruction(
                $supplierId,
                $exportFormat,
                $currencyCode,
                $plannedDate,
                $group,
            );
        }
        $employeeId = $group['employee_id'];
        $recipientName = $this->batches->lockEmployeeName(
            $supplierId,
            $employeeId,
        );
        if ($recipientName === null) {
            throw new \DomainException(
                'Příjemce mzdy nemá úplné jméno.',
            );
        }
        $base = [
            'schema_reference' =>
                'payroll-payment-recipient-instruction.v1',
            'recipient_reference' => $group['recipient_reference'],
            'amount_minor' => $group['amount_minor'],
            'currency_code' => $currencyCode,
            'planned_payment_date' => $plannedDate,
            'recipient_name' => $recipientName,
            'liabilities' => $group['liabilities'],
        ];
        if (str_starts_with(
            $group['recipient_reference'],
            'employee-cash:',
        )) {
            return $base;
        }
        if (preg_match(
            '/^employee-account:([1-9][0-9]*)$/D',
            $group['recipient_reference'],
            $match,
        ) !== 1) {
            throw new \DomainException(
                'Bankovní reference příjemce není platná.',
            );
        }
        $accountId = (int) $match[1];
        $source = $group['source'];
        $frozenAccountId = $source['payment_target_id'] ?? null;
        $frozenHash = $source['payment_target_hash'] ?? null;
        $frozenVersion = $source['payment_target_row_version'] ?? null;
        $frozenVerification = $source[
            'payment_target_verification_hash'
        ] ?? null;
        if ($frozenAccountId !== $accountId
            || !is_string($frozenHash)
            || preg_match('/^[0-9a-f]{64}$/D', $frozenHash) !== 1
            || $frozenHash === str_repeat('0', 64)
            || !is_int($frozenVersion)
            || $frozenVersion <= 0
            || !is_string($frozenVerification)
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $frozenVerification,
            ) !== 1
        ) {
            throw new \DomainException(
                'Závazek nemá úplný zmrazený cíl příjemce.',
            );
        }
        $account = $this->batches->lockPersonAccount(
            $supplierId,
            $employeeId,
            $accountId,
        );
        if ($account === null
            || !$account['is_active']
            || $account['effective_from'] > $plannedDate
            || ($account['effective_to'] !== null
                && $account['effective_to'] < $plannedDate)
            || $account['row_version'] !== $frozenVersion
            || !hash_equals($account['bank_account_hash'], $frozenHash)
        ) {
            throw new \DomainException(
                'Aktuální účet neodpovídá zmrazenému cíli závazku.',
            );
        }
        $verificationSource = $account['verification_source'];
        $verifiedOn = $account['verified_on'];
        $verifiedBy = $account['verified_by'];
        if (!is_string($verificationSource)
            || !in_array($verificationSource, [
                'employee_confirmation',
                'bank_document',
                'user_verified',
            ], true)
            || $verifiedOn === null
            || $verifiedBy === null
            || $this->date(
                $verifiedOn,
                'datum ověření účtu příjemce',
            ) > $plannedDate
        ) {
            throw new \DomainException(
                'Zmrazený platební cíl nemá úplné ověření.',
            );
        }
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-payment-target-verification.v1',
                'person_id' => $employeeId,
                'payment_target_id' => $accountId,
                'payment_target_hash' => $account['bank_account_hash'],
                'row_version' => $account['row_version'],
                'verification_source' => $verificationSource,
                'verified_on' => $verifiedOn,
                'verified_by' => $verifiedBy,
            ]),
        );
        if (!hash_equals($frozenVerification, $verificationHash)) {
            throw new \DomainException(
                'Ověření účtu neodpovídá zmrazenému cíli závazku.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $accountId,
            PayrollRevealPurpose::PAYMENT_BATCH,
        );
        $lookupHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $lookupHash)) {
            throw new \DomainException(
                'Obsah účtu neodpovídá zmrazenému cíli závazku.',
            );
        }
        if ($exportFormat === 'abo') {
            $parsed = $this->czechAccount($plaintext, 'účet příjemce');

            return [
                ...$base,
                'account_number' => $parsed['account_number'],
                'bank_code' => $parsed['bank_code'],
            ];
        }
        $iban = $this->ibanValidator->normalize($plaintext);
        if (!$this->ibanValidator->isValid($iban)) {
            throw new \DomainException(
                'Účet příjemce není platný IBAN pro SEPA.',
            );
        }

        return [...$base, 'iban' => $iban];
    }

    /**
     * @return array{
     *   institution_type:string,
     *   institution_code:string,
     *   account_id:int,
     *   payment_message:string
     * }|null
     */
    private function institutionReference(
        string $liabilityKind,
        string $recipientReference,
    ): ?array {
        $definition = match ($liabilityKind) {
            'health_insurance' => [
                'type' => 'health_insurer',
                'reference_code' => '[0-9]{3}',
                'institution_code' => null,
                'message' => null,
            ],
            'social_insurance' => [
                'type' => 'social_security',
                'reference_code' => '[A-Z0-9][A-Z0-9._-]{0,31}',
                'institution_code' => null,
                'message' => null,
            ],
            'advance_tax' => [
                'type' => 'tax_office',
                'reference_code' => 'advance',
                'institution_code' => 'advance_tax',
                'message' => 'Zaloha na dan z prijmu',
            ],
            'withholding_tax' => [
                'type' => 'tax_office',
                'reference_code' => 'withholding',
                'institution_code' => 'withholding_tax',
                'message' => 'Srazkova dan z prijmu',
            ],
            // Zpráva pro příjemce srážky je záměrně neutrální: spis, jméno
            // povinného ani interní klíč případu se do bankovní instrukce
            // nevkládají. Konkrétní platbu identifikuje VS z ověřeného účtu.
            'enforcement' => [
                'type' => 'other_recipient',
                'reference_code' => '[A-Z0-9][A-Z0-9._-]{0,31}',
                'institution_code' => null,
                'message' => 'Srazka ze mzdy',
            ],
            'insolvency' => [
                'type' => 'other_recipient',
                'reference_code' => '[A-Z0-9][A-Z0-9._-]{0,31}',
                'institution_code' => null,
                'message' => 'Srazka pri oddluzeni',
            ],
            'risky_savings' => [
                'type' => 'other_recipient',
                'reference_code' => '[A-Z0-9][A-Z0-9._-]{0,31}',
                'institution_code' => null,
                'message' => 'Povinne sporeni rizikova prace',
            ],
            default => null,
        };
        if ($definition === null) {
            return null;
        }
        $pattern = '/^institution:' . $definition['type'] . ':('
            . $definition['reference_code']
            . '):account:([1-9][0-9]*)$/D';
        if (preg_match($pattern, $recipientReference, $match) !== 1) {
            return null;
        }
        $referenceCode = $match[1];
        $institutionCode = $definition['institution_code']
            ?? $referenceCode;
        $message = $definition['message'] ?? match ($liabilityKind) {
            'health_insurance' => "Zdravotni pojisteni {$referenceCode}",
            'social_insurance' => "Socialni pojisteni {$referenceCode}",
            default => throw new \LogicException(
                'Chybí zpráva institucionální platby.',
            ),
        };

        return [
            'institution_type' => $definition['type'],
            'institution_code' => $institutionCode,
            'account_id' => (int) $match[2],
            'payment_message' => $message,
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $source
     * @return array{
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string
     * }
     */
    private function institutionSymbols(
        int $supplierId,
        string $institutionType,
        string $institutionCode,
        array $account,
        array $source,
    ): array {
        if (($source['schema_reference'] ?? null)
                === 'payroll-payment-risky-savings-source.v1'
        ) {
            return [
                'variable_symbol' => $this->nullableTextValue(
                    $source['variable_symbol'] ?? null,
                    'variabilní symbol povinného spoření',
                ),
                'specific_symbol' => $this->nullableTextValue(
                    $source['specific_symbol'] ?? null,
                    'specifický symbol povinného spoření',
                ),
                'constant_symbol' => $this->nullableTextValue(
                    $source['constant_symbol'] ?? null,
                    'konstantní symbol povinného spoření',
                ),
            ];
        }
        if ($institutionType !== 'social_security') {
            return [
                'variable_symbol' => $this->nullableTextValue(
                    $account['variable_symbol'] ?? null,
                    'variabilní symbol instituce',
                ),
                'specific_symbol' => $this->nullableTextValue(
                    $account['specific_symbol'] ?? null,
                    'specifický symbol instituce',
                ),
                'constant_symbol' => $this->nullableTextValue(
                    $account['constant_symbol'] ?? null,
                    'konstantní symbol instituce',
                ),
            ];
        }
        $officeId = $source['payroll_office_id'] ?? null;
        if (!is_int($officeId) || $officeId <= 0) {
            throw new \DomainException(
                'Sociální závazek nemá zmrazenou mzdovou účtárnu.',
            );
        }
        $context = $this->batches->lockSocialPaymentContext(
            $supplierId,
            $officeId,
        );
        if ($context === null
            || !$context['is_active']
            || $context['institution_code'] !== $institutionCode
            || $context['code'] !== ($source['payroll_office_code'] ?? null)
            || $context['office_row_version']
                !== ($source['payroll_office_row_version'] ?? null)
            || $context['settings_row_version']
                !== ($source['employer_settings_row_version'] ?? null)
            || $context['variable_symbol']
                !== ($source['variable_symbol'] ?? null)
        ) {
            throw new \DomainException(
                'Mzdová účtárna nebo její platební identifikátory '
                . 'neodpovídají zmrazenému sociálnímu závazku.',
            );
        }

        return [
            'variable_symbol' => $context['variable_symbol'],
            'specific_symbol' => $this->nullableTextValue(
                $account['specific_symbol'] ?? null,
                'specifický symbol ČSSZ',
            ),
            'constant_symbol' => $this->nullableTextValue(
                $account['constant_symbol'] ?? null,
                'konstantní symbol ČSSZ',
            ),
        ];
    }

    /**
     * Institucionální platba BEZ variabilního symbolu je fail-closed.
     *
     * Odvod, který dorazí na účet zdravotní pojišťovny nebo OSSZ bez VS, se
     * nespáruje s IČ plátce a firma zůstane vedená jako dlužník i po zaplacení;
     * u exekuční srážky ho exekutor nepřiřadí ke spisu. Dřív se prázdný symbol
     * potichu nahradil nulou až v ABO writeru — chyba tak byla vidět teprve na
     * výpisu z účtu. Odmítáme ji už při sestavení dávky a rovnou říkáme, kde se
     * symbol doplňuje: u ČSSZ je na mzdové účtárně, u ostatních institucí na
     * jejich platebním účtu, u povinného spoření na srážce samotné.
     */
    private function assertInstitutionVariableSymbol(
        string $liabilityKind,
        string $institutionType,
        mixed $institutionName,
        ?string $variableSymbol,
    ): void {
        if ($variableSymbol !== null) {
            return;
        }
        $name = is_string($institutionName) && trim($institutionName) !== ''
            ? trim($institutionName)
            : 'příjemce odvodu';
        $where = match (true) {
            $liabilityKind === 'risky_savings' =>
                'doplňte jej u srážky povinného spoření na rizikovou práci',
            $institutionType === 'social_security' =>
                'doplňte jej v nastavení mzdové účtárny (variabilní symbol'
                . ' zaměstnavatele u ČSSZ)',
            default => "doplňte jej v nastavení platebního účtu instituce"
                . " „{$name}“",
        };

        throw new \DomainException(
            'Institucionální platba nemá variabilní symbol — ' . $where
            . '. Bez symbolu příjemce platbu nespáruje.',
        );
    }

    private function nullableTextValue(
        mixed $value,
        string $context,
    ): ?string {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                ucfirst($context) . ' musí být neprázdný text.',
            );
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $source
     * @param array{
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string
     * } $symbols
     */
    private function institutionVerificationHash(
        string $institutionType,
        string $institutionCode,
        array $account,
        array $source,
        array $symbols,
    ): string {
        if ($institutionType === 'social_security') {
            $material = [
                'schema_reference' =>
                    'payroll-social-institution-target-verification.v1',
                'institution_type' => $institutionType,
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payroll_office_id' => $source['payroll_office_id'] ?? null,
                'payroll_office_code' =>
                    $source['payroll_office_code'] ?? null,
                'payroll_office_row_version' =>
                    $source['payroll_office_row_version'] ?? null,
                'employer_settings_row_version' =>
                    $source['employer_settings_row_version'] ?? null,
                'variable_symbol' => $symbols['variable_symbol'],
                'specific_symbol' => $symbols['specific_symbol'],
                'constant_symbol' => $symbols['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ];
        } else {
            $material = [
                'schema_reference' =>
                    'payroll-institution-payment-target-verification.v1',
                'institution_type' => $institutionType,
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'row_version' => $account['row_version'],
                'variable_symbol' => $symbols['variable_symbol'],
                'specific_symbol' => $symbols['specific_symbol'],
                'constant_symbol' => $symbols['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ];
        }

        return hash('sha256', CanonicalJson::encode($material));
    }

    /**
     * @param array{
     *   recipient_reference:string,
     *   employee_id:?int,
     *   liability_kind:string,
     *   amount_minor:int,
     *   liabilities:list<array{
     *     id:int,
     *     reference:string,
     *     amount_minor:int,
     *     source_snapshot_hash:string
     *   }>,
     *   source:array<string,mixed>
     * } $group
     * @return array<string,mixed>
     */
    private function institutionRecipientInstruction(
        int $supplierId,
        string $exportFormat,
        string $currencyCode,
        string $plannedDate,
        array $group,
    ): array {
        $reference = $this->institutionReference(
            $group['liability_kind'],
            $group['recipient_reference'],
        );
        if ($reference === null) {
            throw new \DomainException(
                'Institucionální reference příjemce není platná.',
            );
        }
        $institutionType = $reference['institution_type'];
        $institutionCode = $reference['institution_code'];
        $accountId = $reference['account_id'];
        $source = $group['source'];
        $frozenHash = $source['payment_target_hash'] ?? null;
        $frozenVersion = $source['payment_target_row_version'] ?? null;
        $frozenVerification =
            $source['payment_target_verification_hash'] ?? null;
        if (($source['institution_type'] ?? null) !== $institutionType
            || ($source['institution_code'] ?? null) !== $institutionCode
            || ($source['payment_target_id'] ?? null) !== $accountId
            || !is_string($frozenHash)
            || preg_match('/^[0-9a-f]{64}$/D', $frozenHash) !== 1
            || !is_int($frozenVersion)
            || $frozenVersion <= 0
            || !is_string($frozenVerification)
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $frozenVerification,
            ) !== 1
        ) {
            throw new \DomainException(
                'Institucionální závazek nemá úplný zmrazený cíl.',
            );
        }
        $account = $this->batches->lockInstitutionAccount(
            $supplierId,
            $accountId,
        );
        if ($account === null
            || $account['institution_type'] !== $institutionType
            || $account['institution_code'] !== $institutionCode
            || $account['currency_code'] !== $currencyCode
            || $account['valid_from'] > $plannedDate
            || ($account['valid_to'] !== null
                && $account['valid_to'] < $plannedDate)
            || $account['row_version'] !== $frozenVersion
            || !hash_equals($account['bank_account_hash'], $frozenHash)
            || !in_array($account['source_kind'], [
                'official_registry',
                'official_document',
                'institution_notice',
                'user_verified',
            ], true)
            || $account['verified_by'] === null
            || $this->date(
                $account['verified_on'],
                'datum ověření účtu instituce',
            ) > $plannedDate
        ) {
            throw new \DomainException(
                'Aktuální účet instituce neodpovídá zmrazenému cíli.',
            );
        }
        $symbols = $this->institutionSymbols(
            $supplierId,
            $institutionType,
            $institutionCode,
            $account,
            $source,
        );
        $this->assertInstitutionVariableSymbol(
            $group['liability_kind'],
            $institutionType,
            $account['institution_name'],
            $symbols['variable_symbol'],
        );
        $verificationHash = $this->institutionVerificationHash(
            $institutionType,
            $institutionCode,
            $account,
            $source,
            $symbols,
        );
        if (!hash_equals($frozenVerification, $verificationHash)
            || ($source['variable_symbol'] ?? null)
                !== $symbols['variable_symbol']
            || ($source['specific_symbol'] ?? null)
                !== $symbols['specific_symbol']
            || ($source['constant_symbol'] ?? null)
                !== $symbols['constant_symbol']
        ) {
            throw new \DomainException(
                'Ověření nebo symboly instituce se od materializace změnily.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $accountId,
            PayrollRevealPurpose::PAYMENT_BATCH,
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($frozenHash, $actualHash)) {
            throw new \DomainException(
                'Obsah účtu instituce neodpovídá zmrazenému cíli.',
            );
        }
        $base = [
            'schema_reference' =>
                'payroll-payment-recipient-instruction.v1',
            'recipient_reference' => $group['recipient_reference'],
            'amount_minor' => $group['amount_minor'],
            'currency_code' => $currencyCode,
            'planned_payment_date' => $plannedDate,
            'recipient_name' => $account['institution_name'],
            'variable_symbol' => $symbols['variable_symbol'],
            'specific_symbol' => $symbols['specific_symbol'],
            'constant_symbol' => $symbols['constant_symbol'],
            'payment_message' => $reference['payment_message'],
            'liabilities' => $group['liabilities'],
        ];
        if ($exportFormat === 'abo') {
            $parsed = $this->czechAccount($plaintext, 'účet instituce');

            return [
                ...$base,
                'account_number' => $parsed['account_number'],
                'bank_code' => $parsed['bank_code'],
            ];
        }
        $iban = $this->ibanValidator->normalize($plaintext);
        if (!$this->ibanValidator->isValid($iban)) {
            throw new \DomainException(
                'Účet instituce není platný IBAN pro SEPA.',
            );
        }

        return [...$base, 'iban' => $iban];
    }

    /**
     * @return array{account_number:string,bank_code:string}
     */
    private function czechAccount(string $value, string $context): array
    {
        try {
            $parsed = $this->czechBankAccountValidator->parse($value);
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException(
                "{$context} není platný český bankovní účet.",
                0,
                $exception,
            );
        }

        return [
            'account_number' => $parsed['account_number'],
            'bank_code' => $parsed['bank_code'],
        ];
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "{$context} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function date(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new \DomainException("{$context} není datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException("{$context} není platné datum.");
        }

        return $value;
    }

    private function addAmounts(int $left, int $right): int
    {
        if ($right <= 0 || $left > PHP_INT_MAX - $right) {
            throw new \OverflowException(
                'Součet částek platební dávky není platný.',
            );
        }

        return $left + $right;
    }
}
