<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentExportRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Service\Payment\SepaPaymentOrderWriter;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollPaymentExportService
{
    private const EXPORTER_VERSION = 'payroll-payment-export.v1';

    public function __construct(
        private readonly PayrollPaymentExportRepository $exports,
        private readonly SecretEncryption $encryption,
        private readonly AboPaymentOrderWriter $abo,
        private readonly SepaPaymentOrderWriter $sepa,
        private readonly PayrollPaymentExportStorage $storage,
    ) {}

    /**
     * @param null|callable(array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string,
     *   created:bool,
     *   replayed:bool
     * }):void $beforeCommit
     * @return array{
     *   export_id:int,
     *   batch_id:int,
     *   export_format:string,
     *   export_revision_no:int,
     *   source_snapshot_hash:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string,
     *   created:bool,
     *   replayed:bool
     * }
     */
    public function export(
        int $supplierId,
        int $batchId,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?callable $beforeCommit = null,
    ): array {
        if ($this->exports->hasActiveTransaction()) {
            throw new \LogicException(
                'Platební export nelze spustit uvnitř cizí transakce.',
            );
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($supplierId <= 0 || $batchId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a platební dávka musí být kladná čísla.',
            );
        }
        if ($idempotencyKey === ''
            || mb_strlen($idempotencyKey, 'UTF-8') > 190
        ) {
            throw new \InvalidArgumentException(
                'Idempotentní klíč exportu musí mít 1 až 190 znaků.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Autor platebního exportu není platný.',
            );
        }
        $idempotencyHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-payment-export-idempotency.v1',
                'supplier_id' => $supplierId,
                'batch_id' => $batchId,
                'idempotency_key' => $idempotencyKey,
            ]),
            true,
        );
        $stored = null;

        try {
            return $this->exports->transaction(function () use (
                $supplierId,
                $batchId,
                $idempotencyHash,
                $actorUserId,
                $beforeCommit,
                &$stored,
            ): array {
                if (!$this->exports->lockSupplier($supplierId)) {
                    throw new \DomainException(
                        'Firma platebního exportu nebyla nalezena.',
                    );
                }
                $existing = $this->exports
                    ->findByIdempotencyForUpdate(
                        $supplierId,
                        $idempotencyHash,
                    );
                if ($existing !== null) {
                    if ($existing['batch_id'] !== $batchId) {
                        throw new \DomainException(
                            'Idempotentní klíč patří jiné platební dávce.',
                        );
                    }
                    $bytes = $this->storage->readVerified(
                        $supplierId,
                        $existing['storage_key'],
                    );
                    if (strlen($bytes) !== $existing['size_bytes']
                        || !hash_equals(
                            $existing['file_sha256'],
                            hash('sha256', $bytes),
                        )
                    ) {
                        throw new \DomainException(
                            'Archivovaný platební export nemá platnou integritu.',
                        );
                    }

                    $replayed = [
                        ...$existing,
                        'created' => false,
                        'replayed' => true,
                    ];
                    if ($beforeCommit !== null) {
                        $beforeCommit($replayed);
                    }

                    return $replayed;
                }

                $batch = $this->exports->lockBatchWithItems(
                    $supplierId,
                    $batchId,
                );
                if ($batch === null) {
                    throw new \DomainException(
                        'Platební dávka nebyla nalezena.',
                    );
                }
                $prepared = $this->prepare($supplierId, $batch);
                $latest = $this->exports->lockLatestRevision(
                    $supplierId,
                    $batchId,
                    $batch['export_format'],
                );
                $preparedHash = hash('sha256', $prepared['bytes']);
                if ($latest !== null
                    && $latest['source_snapshot_hash']
                        === $batch['snapshot_hash']
                    && $latest['exporter_version']
                        === self::EXPORTER_VERSION
                    && hash_equals(
                        $latest['file_sha256'],
                        $preparedHash,
                    )
                    && $latest['size_bytes']
                        === strlen($prepared['bytes'])
                    && $latest['mime_type'] === $prepared['mime_type']
                    && $latest['suggested_filename']
                        === $prepared['suggested_filename']
                ) {
                    $archived = $this->storage->readVerified(
                        $supplierId,
                        $latest['storage_key'],
                    );
                    if (!hash_equals($prepared['bytes'], $archived)) {
                        throw new \DomainException(
                            'Archivovaný platební export nemá očekávaný obsah.',
                        );
                    }
                    $replayed = [
                        'export_id' => $latest['export_id'],
                        'batch_id' => $latest['batch_id'],
                        'export_format' => $latest['export_format'],
                        'export_revision_no' =>
                            $latest['export_revision_no'],
                        'source_snapshot_hash' =>
                            $latest['source_snapshot_hash'],
                        'file_sha256' => $latest['file_sha256'],
                        'size_bytes' => $latest['size_bytes'],
                        'mime_type' => $latest['mime_type'],
                        'storage_key' => $latest['storage_key'],
                        'suggested_filename' =>
                            $latest['suggested_filename'],
                        'created' => false,
                        'replayed' => true,
                    ];
                    $this->exports->insertIdempotencyAlias(
                        $supplierId,
                        $batchId,
                        $latest['export_id'],
                        $idempotencyHash,
                    );
                    if ($beforeCommit !== null) {
                        $beforeCommit($replayed);
                    }

                    return $replayed;
                }
                $revisionNo = $latest === null
                    ? 1
                    : $latest['export_revision_no'] + 1;
                $supersedesId = $latest['export_id'] ?? null;

                $stored = $this->storage->store(
                    $supplierId,
                    $prepared['bytes'],
                );
                $manifestJson = CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-payment-export-manifest.v1',
                    'batch_id' => $batchId,
                    'batch_reference' => $batch['batch_reference'],
                    'export_format' => $batch['export_format'],
                    'export_revision_no' => $revisionNo,
                    'exporter_version' => self::EXPORTER_VERSION,
                    'source_snapshot_hash' => $batch['snapshot_hash'],
                    'declared_total_minor' =>
                        $batch['declared_total_minor'],
                    'declared_item_count' =>
                        $batch['declared_item_count'],
                    'file_sha256' => $stored['file_sha256'],
                    'size_bytes' => $stored['size_bytes'],
                    'items' => array_map(
                        static fn (array $item): array => [
                            'item_reference' =>
                                $item['item_reference'],
                            'recipient_reference' =>
                                $item['recipient_reference'],
                            'amount_minor' => $item['amount_minor'],
                            'instruction_hash' =>
                                $item['instruction_hash'],
                        ],
                        $batch['items'],
                    ),
                ]);
                $exportId = $this->exports->insert(
                    $supplierId,
                    $batchId,
                    $batch['export_format'],
                    $revisionNo,
                    $supersedesId,
                    $batch['snapshot_hash'],
                    self::EXPORTER_VERSION,
                    $stored['file_sha256'],
                    $stored['size_bytes'],
                    $prepared['mime_type'],
                    $stored['storage_key'],
                    $prepared['suggested_filename'],
                    $manifestJson,
                    $idempotencyHash,
                    $actorUserId,
                );
                $this->exports->insertIdempotencyAlias(
                    $supplierId,
                    $batchId,
                    $exportId,
                    $idempotencyHash,
                );

                $result = [
                    'export_id' => $exportId,
                    'batch_id' => $batchId,
                    'export_format' => $batch['export_format'],
                    'export_revision_no' => $revisionNo,
                    'source_snapshot_hash' => $batch['snapshot_hash'],
                    'file_sha256' => $stored['file_sha256'],
                    'size_bytes' => $stored['size_bytes'],
                    'mime_type' => $prepared['mime_type'],
                    'storage_key' => $stored['storage_key'],
                    'suggested_filename' =>
                        $prepared['suggested_filename'],
                    'created' => true,
                    'replayed' => false,
                ];
                if ($beforeCommit !== null) {
                    $beforeCommit($result);
                }

                return $result;
            });
        } catch (\Throwable $exception) {
            if ($stored !== null && $stored['created']) {
                $this->cleanupCreatedStorage(
                    $supplierId,
                    $stored['storage_key'],
                );
            }
            throw $exception;
        }
    }

    /**
     * @param array{
     *   id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   direction:string,
     *   planned_payment_date:string,
     *   currency_code:string,
     *   payer_reference:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   snapshot_ciphertext:string,
     *   snapshot_hash:string,
     *   items:list<array{
     *     id:int,
     *     item_reference:string,
     *     recipient_reference:string,
     *     amount_minor:int,
     *     instruction_ciphertext:string,
     *     instruction_hash:string
     *   }>
     * } $batch
     * @return array{
     *   bytes:string,
     *   mime_type:string,
     *   suggested_filename:string
     * }
     */
    private function prepare(int $supplierId, array $batch): array
    {
        if ($batch['channel'] !== 'bank'
            || $batch['direction'] !== 'outgoing'
            || !in_array($batch['export_format'], ['abo', 'sepa'], true)
        ) {
            throw new \DomainException(
                'Export podporuje pouze odchozí bankovní dávky ABO a SEPA.',
            );
        }
        $snapshotJson = $this->encryption->decryptFor(
            $batch['snapshot_ciphertext'],
            "payroll-payment-batch:{$supplierId}:"
                . $batch['batch_reference'],
        );
        $snapshot = $this->canonicalObject(
            $snapshotJson,
            $batch['snapshot_hash'],
            'snapshot platební dávky',
        );
        foreach ([
            'schema_reference' =>
                'payroll-payment-batch-snapshot.v1',
            'batch_reference' => $batch['batch_reference'],
            'channel' => $batch['channel'],
            'export_format' => $batch['export_format'],
            'direction' => $batch['direction'],
            'planned_payment_date' =>
                $batch['planned_payment_date'],
            'currency_code' => $batch['currency_code'],
            'payer_reference' => $batch['payer_reference'],
            'declared_total_minor' =>
                $batch['declared_total_minor'],
            'declared_item_count' =>
                $batch['declared_item_count'],
        ] as $field => $expected) {
            if (($snapshot[$field] ?? null) !== $expected) {
                throw new \DomainException(
                    'Snapshot platební dávky neodpovídá neměnným údajům.',
                );
            }
        }
        $snapshotItems = $snapshot['items'] ?? null;
        if (!is_array($snapshotItems)
            || !array_is_list($snapshotItems)
            || count($snapshotItems) !== $batch['declared_item_count']
            || count($batch['items']) !== $batch['declared_item_count']
        ) {
            throw new \DomainException(
                'Počet položek platební dávky neodpovídá snapshotu.',
            );
        }
        $payer = $this->object(
            $snapshot['payer_instruction'] ?? null,
            'instrukci účtu plátce',
        );
        $instructions = [];
        $totalMinor = 0;
        foreach ($batch['items'] as $index => $storedItem) {
            $snapshotItem = $this->object(
                $snapshotItems[$index] ?? null,
                'položku snapshotu platební dávky',
            );
            foreach ([
                'item_reference' => $storedItem['item_reference'],
                'recipient_reference' =>
                    $storedItem['recipient_reference'],
                'amount_minor' => $storedItem['amount_minor'],
                'instruction_hash' =>
                    $storedItem['instruction_hash'],
            ] as $field => $expected) {
                if (($snapshotItem[$field] ?? null) !== $expected) {
                    throw new \DomainException(
                        'Položka platební dávky neodpovídá snapshotu.',
                    );
                }
            }
            $instructionJson = $this->encryption->decryptFor(
                $storedItem['instruction_ciphertext'],
                "payroll-payment-item:{$supplierId}:"
                    . $storedItem['item_reference'],
            );
            $instruction = $this->canonicalObject(
                $instructionJson,
                $storedItem['instruction_hash'],
                'instrukci příjemce',
            );
            foreach ([
                'schema_reference' =>
                    'payroll-payment-recipient-instruction.v1',
                'recipient_reference' =>
                    $storedItem['recipient_reference'],
                'amount_minor' => $storedItem['amount_minor'],
                'currency_code' => $batch['currency_code'],
                'planned_payment_date' =>
                    $batch['planned_payment_date'],
            ] as $field => $expected) {
                if (($instruction[$field] ?? null) !== $expected) {
                    throw new \DomainException(
                        'Instrukce příjemce neodpovídá položce dávky.',
                    );
                }
            }
            if (($instruction['liabilities'] ?? null)
                !== ($snapshotItem['liabilities'] ?? null)
            ) {
                throw new \DomainException(
                    'Alokace položky neodpovídají snapshotu dávky.',
                );
            }
            if ($storedItem['amount_minor'] <= 0
                || $totalMinor > PHP_INT_MAX - $storedItem['amount_minor']
            ) {
                throw new \DomainException(
                    'Součet platební dávky není bezpečný.',
                );
            }
            $totalMinor += $storedItem['amount_minor'];
            $instruction['_item_reference'] =
                $storedItem['item_reference'];
            $instructions[] = $instruction;
        }
        if ($totalMinor !== $batch['declared_total_minor']) {
            throw new \DomainException(
                'Součet položek platební dávky neodpovídá deklaraci.',
            );
        }

        $filenameBase = 'mzdy-platby-'
            . $batch['planned_payment_date'] . '-' . $batch['id'];
        if ($batch['export_format'] === 'abo') {
            if ($batch['currency_code'] !== 'CZK') {
                throw new \DomainException(
                    'ABO export vyžaduje měnu CZK.',
                );
            }
            $accountNumber = $this->requiredString(
                $payer,
                'account_number',
                'instrukci účtu plátce',
            );
            $bankCode = $this->requiredString(
                $payer,
                'bank_code',
                'instrukci účtu plátce',
            );

            return [
                'bytes' => $this->abo->build([
                    'client_name' => $this->requiredString(
                        $payer,
                        'account_holder_name',
                        'instrukci účtu plátce',
                    ),
                    'payer_account_number' => $accountNumber,
                    'payer_bank_code' => $bankCode,
                    'payment_date' => $batch['planned_payment_date'],
                    'items' => $this->aboWriterItems($instructions),
                ]),
                'mime_type' => 'text/plain; charset=us-ascii',
                'suggested_filename' => $filenameBase . '.kpc',
            ];
        }
        if ($batch['currency_code'] !== 'EUR') {
            throw new \DomainException(
                'SEPA export vyžaduje měnu EUR.',
            );
        }

        return [
            'bytes' => $this->sepa->build([
                'order_id' => $batch['batch_reference'],
                'initiator_name' => $this->requiredString(
                    $payer,
                    'account_holder_name',
                    'instrukci účtu plátce',
                ),
                'payer_name' => $this->requiredString(
                    $payer,
                    'account_holder_name',
                    'instrukci účtu plátce',
                ),
                'payer_iban' => $this->requiredString(
                    $payer,
                    'iban',
                    'instrukci účtu plátce',
                ),
                'payer_bic' => $this->nullableString($payer, 'bic'),
                'payment_date' => $batch['planned_payment_date'],
                'creation_datetime' => $this->requiredString(
                    $snapshot,
                    'creation_datetime',
                    'snapshot platební dávky',
                ),
                'currency' => $batch['currency_code'],
                'items' => $this->sepaWriterItems($instructions),
            ]),
            'mime_type' => 'application/xml',
            'suggested_filename' => $filenameBase . '.xml',
        ];
    }

    private function cleanupCreatedStorage(
        int $supplierId,
        string $storageKey,
    ): void {
        $this->exports->transaction(function () use (
            $supplierId,
            $storageKey,
        ): void {
            if (!$this->exports->lockSupplier($supplierId)) {
                return;
            }
            if ($this->exports->countStorageReferences(
                $supplierId,
                $storageKey,
            ) !== 0) {
                return;
            }
            $this->storage->deleteCreated($supplierId, $storageKey);
        });
    }

    /**
     * @param list<array<string,mixed>> $instructions
     * @return list<array{
     *   account_number:string,
     *   bank_code:string,
     *   amount_minor:int,
     *   variable_symbol:?string,
     *   constant_symbol:?string,
     *   specific_symbol:?string,
     *   message:string,
     *   allow_missing_variable_symbol:bool
     * }>
     */
    private function aboWriterItems(array $instructions): array
    {
        $items = [];
        foreach ($instructions as $instruction) {
            $institutional = $this->isInstitutionalInstruction($instruction);
            $this->assertVariableSymbol($instruction, $institutional);
            $items[] = [
                'allow_missing_variable_symbol' => !$institutional,
                'account_number' => $this->requiredString(
                    $instruction,
                    'account_number',
                    'instrukci příjemce',
                ),
                'bank_code' => $this->requiredString(
                    $instruction,
                    'bank_code',
                    'instrukci příjemce',
                ),
                'amount_minor' => $this->amountMinor($instruction),
                'variable_symbol' => $this->nullableString(
                    $instruction,
                    'variable_symbol',
                ),
                'constant_symbol' => $this->nullableString(
                    $instruction,
                    'constant_symbol',
                ),
                'specific_symbol' => $this->nullableString(
                    $instruction,
                    'specific_symbol',
                ),
                'message' => $this->nullableString(
                    $instruction,
                    'payment_message',
                ) ?? 'Vyplata mzdy',
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $instructions
     * @return list<array{
     *   payee_name:string,
     *   iban:string,
     *   bic:?string,
     *   amount_minor:int,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   message:string
     * }>
     */
    private function sepaWriterItems(array $instructions): array
    {
        $items = [];
        foreach ($instructions as $instruction) {
            $this->assertVariableSymbol(
                $instruction,
                $this->isInstitutionalInstruction($instruction),
            );
            $items[] = [
                'payee_name' => $this->requiredString(
                    $instruction,
                    'recipient_name',
                    'instrukci příjemce',
                ),
                'iban' => $this->requiredString(
                    $instruction,
                    'iban',
                    'instrukci příjemce',
                ),
                'bic' => $this->nullableString($instruction, 'bic'),
                'amount_minor' => $this->amountMinor($instruction),
                'variable_symbol' => $this->nullableString(
                    $instruction,
                    'variable_symbol',
                ),
                // SEPA nemá pole pro české symboly — nesou se ve strukturované
                // části zprávy pro příjemce (viz SepaPaymentOrderWriter).
                // Bez nich odcházela eurová platba bez jakékoliv identifikace.
                'specific_symbol' => $this->nullableString(
                    $instruction,
                    'specific_symbol',
                ),
                'constant_symbol' => $this->nullableString(
                    $instruction,
                    'constant_symbol',
                ),
                'end_to_end_id' => 'MYUCTO-' . substr(
                    hash(
                        'sha256',
                        $this->requiredString(
                            $instruction,
                            '_item_reference',
                            'instrukci příjemce',
                        ),
                    ),
                    0,
                    28,
                ),
                'message' => $this->nullableString(
                    $instruction,
                    'payment_message',
                ) ?? 'Vyplata mzdy',
            ];
        }

        return $items;
    }

    /**
     * Instrukce mířící na instituci (ČSSZ, zdravotní pojišťovna, finanční
     * úřad, exekutor, insolvenční správce, penzijní společnost) — poznáme ji
     * podle reference příjemce, kterou sestavuje
     * {@see PayrollPaymentBatchBuilder::institutionReference()}.
     *
     * @param array<string,mixed> $instruction
     */
    private function isInstitutionalInstruction(array $instruction): bool
    {
        $reference = $instruction['recipient_reference'] ?? null;

        return is_string($reference)
            && str_starts_with($reference, 'institution:');
    }

    /**
     * Poslední brána před exportem dávky: institucionální platba bez VS ven
     * neodejde. Dávky sestavené před opravou P-07 mají symbol zmrazený ve
     * zašifrované instrukci, takže je kontrola v builderu už nezachytí —
     * tady se zastaví.
     *
     * @param array<string,mixed> $instruction
     */
    private function assertVariableSymbol(
        array $instruction,
        bool $institutional,
    ): void {
        if (!$institutional) {
            return;
        }
        $symbol = $this->nullableString($instruction, 'variable_symbol');
        if ($symbol === null) {
            throw new \DomainException(
                'Platba instituci v dávce nemá variabilní symbol; doplňte jej'
                . ' u příjemce (účet instituce, u ČSSZ mzdová účtárna)'
                . ' a sestavte dávku znovu.',
            );
        }
    }

    /** @param array<string,mixed> $instruction */
    private function amountMinor(array $instruction): int
    {
        $amountMinor = $instruction['amount_minor'] ?? null;
        if (!is_int($amountMinor) || $amountMinor <= 0) {
            throw new \DomainException(
                'Instrukce příjemce nemá platnou částku.',
            );
        }

        return $amountMinor;
    }

    /** @return array<string,mixed> */
    private function canonicalObject(
        string $json,
        string $expectedHash,
        string $context,
    ): array {
        try {
            $decoded = json_decode(
                $json,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \DomainException(
                "Neplatný {$context}.",
                previous: $exception,
            );
        }
        $object = $this->object($decoded, $context);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json
            || !hash_equals($expectedHash, hash('sha256', $canonical))
        ) {
            throw new \DomainException(
                "Kanonický hash pro {$context} nesouhlasí.",
            );
        }

        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Neplatná struktura pro {$context}.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "Neplatný klíč pro {$context}.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $source */
    private function requiredString(
        array $source,
        string $field,
        string $context,
    ): string {
        $value = $source[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(
                "Chybí povinný údaj v {$context}.",
            );
        }

        return trim($value);
    }

    /** @param array<string,mixed> $source */
    private function nullableString(array $source, string $field): ?string
    {
        $value = $source[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(
                'Volitelný údaj platební instrukce není text.',
            );
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
