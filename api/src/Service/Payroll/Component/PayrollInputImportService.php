<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollInputImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

final class PayrollInputImportService
{
    public function __construct(
        private readonly PayrollInputTabularParser $parser,
        private readonly PayrollInputImportRepository $imports,
        private readonly PayrollInputPreviewService $preview,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        string $period,
        string $format,
        string $sourceName,
        string $content,
    ): array {
        $periodStart = $this->period($period);
        $format = $this->format($format);
        $sourceName = $this->sourceName($sourceName);
        $parsed = $this->parser->parse($format, $content);
        $valid = [];
        $errors = [];
        $duplicates = [];
        $seen = [];
        $provisionalAnnualUsage = [];

        foreach ($parsed['errors'] as $error) {
            $errors[] = [
                ...$error,
                'payload' => [],
            ];
        }
        foreach ($parsed['rows'] as $raw) {
            $rowNumber = PayrollTimeValue::int($raw['row_number'] ?? null, 'row_number');
            $safePayload = $this->safePayload($raw);
            try {
                $payload = $this->validateRow($supplierId, $periodStart, $raw);
                $dedupeKey = PayrollTimeValue::int(
                    $payload['employment_id'],
                    'employment_id',
                ) . "\0" . PayrollTimeValue::string(
                    $payload['external_id'],
                    'external_id',
                );
                if (isset($seen[$dedupeKey])) {
                    $duplicates[] = $this->duplicate(
                        $rowNumber,
                        $payload,
                        null,
                        'duplicate_in_file',
                        'Stejné external_id se pro vztah v souboru opakuje.',
                    );
                    continue;
                }
                $seen[$dedupeKey] = true;
                /*
                 * Opakovaný import téhož `external_id`:
                 *  - ručně přepsaná hodnota zůstává, import ji jen ohlásí
                 *    (a zrušenému importnímu vstupu aktualizuje hodnotu, aby
                 *    „Vrátit na hodnotu z importu" vrátilo poslední dávku);
                 *  - stejná hodnota je duplicita jako dřív;
                 *  - změněná hodnota u KONCEPTU se aktualizuje — oprava podkladu
                 *    v docházce se tak do mezd dostane bez ručního zásahu;
                 *  - schválený či uzamčený vstup import nepřepíše nikdy.
                 * Zrušený vstup bez přepisu (koncept někdo zrušil) se neřeší:
                 * řádek se naimportuje znovu jako nový, stejně jako dřív.
                 */
                $existing = $this->imports->existingInputState(
                    $supplierId,
                    PayrollTimeValue::int(
                        $payload['employment_id'],
                        'employment_id',
                    ),
                    $periodStart,
                    PayrollTimeValue::string(
                        $payload['external_id'],
                        'external_id',
                    ),
                );
                $update = null;
                if ($existing !== null && $existing['override_input_id'] !== null) {
                    $duplicates[] = [
                        ...$this->duplicate(
                            $rowNumber,
                            $payload,
                            $existing['input_id'],
                            'overridden_manually',
                            'Hodnota je v rychlém měsíčním vstupu ručně přepsaná; import ji nepřepsal.',
                        ),
                        'refresh' => true,
                    ];
                    continue;
                }
                if ($existing !== null && $existing['status'] !== 'cancelled') {
                    $same = $existing['amount_minor'] === $payload['amount_minor']
                        && $existing['quantity_milliunits'] === $payload['quantity_milliunits']
                        && $existing['component_id'] === $payload['component_id']
                        && $existing['source_period_start'] === $payload['source_period_start'];
                    if ($same) {
                        $duplicates[] = $this->duplicate(
                            $rowNumber,
                            $payload,
                            $existing['input_id'],
                            'duplicate_external_id',
                            'Externí vstup už v tomto vztahu a měsíci existuje.',
                        );
                        continue;
                    }
                    if ($existing['status'] !== 'draft') {
                        $duplicates[] = $this->duplicate(
                            $rowNumber,
                            $payload,
                            $existing['input_id'],
                            'changed_after_approval',
                            'Hodnota v importu se liší od už schváleného vstupu. Schválený vstup '
                            . 'import nepřepisuje — opravte ho v Mzdových vstupech nebo ručním '
                            . 'přepisem v rychlém měsíčním vstupu.',
                        );
                        continue;
                    }
                    $update = [
                        'input_id' => $existing['input_id'],
                        'row_version' => $existing['row_version'],
                    ];
                }
                $impact = $this->preview->preview($supplierId, $payload);
                if (($impact['support_status'] ?? null) !== 'supported') {
                    throw new \InvalidArgumentException(
                        PayrollTimeValue::string($impact['blocker'] ?? null, 'blocker')
                    );
                }
                $annualLimit = $impact['annual_limit_minor'] ?? null;
                if ($annualLimit !== null) {
                    $employeeId = PayrollTimeValue::int(
                        $payload['employee_id'],
                        'employee_id',
                    );
                    $componentId = PayrollTimeValue::int(
                        $payload['component_id'],
                        'component_id',
                    );
                    $year = substr($periodStart, 0, 4);
                    $usageKey = "{$employeeId}\0{$componentId}\0{$year}";
                    $provisional = $provisionalAnnualUsage[$usageKey] ?? 0;
                    $positiveAmount = max(
                        0,
                        PayrollTimeValue::int($payload['amount_minor'], 'amount_minor'),
                    );
                    $projected = $this->add(
                        $this->add(
                            PayrollTimeValue::int(
                                $impact['annual_used_minor'] ?? null,
                                'annual_used_minor',
                            ),
                            $provisional,
                        ),
                        $positiveAmount,
                    );
                    if ($projected > PayrollTimeValue::int(
                        $annualLimit,
                        'annual_limit_minor',
                    )) {
                        throw new \InvalidArgumentException(
                            'Importovaný vstup by překročil roční limit benefitu.'
                        );
                    }
                    $provisionalAnnualUsage[$usageKey] = $this->add(
                        $provisional,
                        $positiveAmount,
                    );
                    $impact['annual_after_minor'] = $projected;
                    $impact['annual_limit_exceeded'] = false;
                }
                $valid[] = [
                    'row_number' => $rowNumber,
                    'payload' => $payload,
                    'impact' => $impact,
                    'update' => $update,
                ];
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'error_code' => 'row_validation_failed',
                    'field_name' => null,
                    'error_message' => $e->getMessage(),
                    'payload' => $safePayload,
                ];
            }
        }

        // Aktualizace konceptu není nový vstup; náhled to počítá stejně jako zápis.
        $updatedCount = count(array_filter(
            $valid,
            static fn (array $row): bool => $row['update'] !== null,
        ));

        return [
            'format' => $format,
            'source_name' => $sourceName,
            'period' => substr($periodStart, 0, 7),
            'content_hash' => hash('sha256', $content),
            'row_count' => count($valid) + count($errors) + count($duplicates),
            'accepted_count' => count($valid) - $updatedCount,
            'rejected_count' => count($errors),
            'duplicate_count' => count($duplicates),
            'updated_count' => $updatedCount,
            'overridden_count' => count(array_filter(
                $duplicates,
                static fn (array $row): bool => $row['error_code'] === 'overridden_manually',
            )),
            'rows' => $valid,
            'errors' => array_map($this->publicError(...), $errors),
            'duplicates' => array_map($this->publicError(...), $duplicates),
            '_valid' => $valid,
            '_errors' => $errors,
            '_duplicates' => $duplicates,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(
        int $supplierId,
        string $period,
        string $format,
        string $sourceName,
        string $content,
        ?int $userId,
    ): array {
        /*
         * Tvar vstupu se ověřuje PŘED hledáním obsahového otisku. Idempotence
         * se smí uplatnit jen na požadavek, který by jinak prošel: kdyby se
         * hash hledal dřív, replay se stejným obsahem a nesmyslným formátem
         * (nebo obdobím či názvem souboru) by místo odmítnutí vydal uložený
         * import — vstupní brána dat by tak validaci obešla opakováním.
         */
        $periodStart = $this->period($period);
        $this->format($format);
        $this->sourceName($sourceName);
        $contentHash = hash('sha256', $content, true);
        $existing = $this->imports->findByHash($supplierId, $periodStart, $contentHash);
        if ($existing !== null) {
            $existing['replayed'] = true;
            return $existing;
        }
        $preview = $this->preview(
            $supplierId,
            $period,
            $format,
            $sourceName,
            $content,
        );
        return $this->imports->store(
            $supplierId,
            $periodStart,
            PayrollTimeValue::string($preview['format'] ?? null, 'format'),
            PayrollTimeValue::string($preview['source_name'] ?? null, 'source_name'),
            $contentHash,
            $this->validRows($preview['_valid'] ?? null),
            $this->errorRows($preview['_errors'] ?? null, false),
            $this->errorRows($preview['_duplicates'] ?? null, true),
            $userId,
        );
    }

    /**
     * @param array<string,string|int> $row
     * @return array{
     *   employee_id:int,
     *   employment_id:int,
     *   component_id:int,
     *   component_code:string,
     *   period_start:string,
     *   source_period_start:?string,
     *   amount_minor:int,
     *   quantity_milliunits:?int,
     *   source_kind:string,
     *   external_id:string
     * }
     */
    private function validateRow(int $supplierId, string $periodStart, array $row): array
    {
        $employmentIdValue = $this->rowString($row, 'employment_id');
        if (!ctype_digit($employmentIdValue) || (int) $employmentIdValue <= 0) {
            throw new \InvalidArgumentException('employment_id musí být kladné celé číslo.');
        }
        $employmentCode = $this->rowString($row, 'employment_code');
        if ($employmentCode === '' || mb_strlen($employmentCode) > 64) {
            throw new \InvalidArgumentException('employment_code není platný.');
        }
        $employment = $this->imports->resolveEmployment(
            $supplierId,
            (int) $employmentIdValue,
            $employmentCode,
        );
        if ($employment === null) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nebyl v této firmě nalezen nebo kód neodpovídá.'
            );
        }

        $componentCode = strtoupper($this->rowString($row, 'component_code'));
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $componentCode) !== 1) {
            throw new \InvalidArgumentException('component_code není platný.');
        }
        if ($this->imports->reservedForHourlyCalculation($supplierId, $employment['employment_id'], $periodStart, $componentCode)) {
            throw new \InvalidArgumentException('U hodinového vztahu tato složka vzniká pouze výpočtem hodinové mzdy a nelze ji importovat.');
        }
        $component = $this->imports->resolveComponent(
            $supplierId,
            $componentCode,
            $periodStart,
        );
        if ($component === null
            || PayrollTimeValue::string(
                $component['frequency_kind'] ?? null,
                'frequency_kind',
            ) !== 'one_off') {
            throw new \InvalidArgumentException(
                'Jednorázová mzdová složka není v období jednoznačně účinná.'
            );
        }

        $amountValue = $this->rowString($row, 'amount_minor');
        if (preg_match('/^-?\d+$/D', $amountValue) !== 1) {
            throw new \InvalidArgumentException('amount_minor musí být celé haléře.');
        }
        $amount = filter_var($amountValue, FILTER_VALIDATE_INT);
        if ($amount === false) {
            throw new \InvalidArgumentException('amount_minor je mimo podporovaný rozsah.');
        }
        $externalId = $this->rowString($row, 'external_id');
        if ($externalId === '' || mb_strlen($externalId) > 190
            || preg_match('/[\x00-\x1F\x7F]/u', $externalId) === 1
            || preg_match('/^[=+\-@]/', $externalId) === 1) {
            throw new \InvalidArgumentException('external_id není platné.');
        }
        $sourcePeriod = $this->optionalMonth($this->rowString($row, 'source_period', ''));
        $quantityValue = $this->rowString($row, 'quantity_milliunits', '');
        $quantity = null;
        if ($quantityValue !== '') {
            if (preg_match('/^-?\d+$/D', $quantityValue) !== 1) {
                throw new \InvalidArgumentException(
                    'quantity_milliunits musí být celé číslo.'
                );
            }
            $parsedQuantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
            if ($parsedQuantity === false) {
                throw new \InvalidArgumentException(
                    'quantity_milliunits je mimo podporovaný rozsah.'
                );
            }
            $quantity = (int) $parsedQuantity;
        }

        return [
            'employee_id' => $employment['employee_id'],
            'employment_id' => $employment['employment_id'],
            'component_id' => PayrollTimeValue::int($component['id'] ?? null, 'component_id'),
            'component_code' => $componentCode,
            'period_start' => $periodStart,
            'source_period_start' => $sourcePeriod,
            'amount_minor' => (int) $amount,
            'quantity_milliunits' => $quantity,
            'source_kind' => 'import',
            'external_id' => $externalId,
        ];
    }

    private function format(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formát musí být csv nebo xlsx.');
        }

        return $normalized;
    }

    private function period(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('Období musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    private function optionalMonth(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('source_period musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    private function sourceName(string $value): string
    {
        $normalized = basename(str_replace('\\', '/', trim($value)));
        if ($normalized === '' || mb_strlen($normalized) > 190
            || preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new \InvalidArgumentException('Název importního souboru není platný.');
        }
        return $normalized;
    }

    /**
     * @param array<string,string|int> $row
     */
    private function rowString(array $row, string $key, ?string $default = null): string
    {
        $value = $row[$key] ?? $default;
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$key} není text.");
        }
        return trim($value);
    }

    /**
     * @param array<string,string|int> $row
     * @return array<string,mixed>
     */
    private function safePayload(array $row): array
    {
        $result = [];
        foreach ([
            'employment_id',
            'employment_code',
            'component_code',
            'amount_minor',
            'external_id',
            'source_period',
            'quantity_milliunits',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $result[$key] = $row[$key];
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{row_number:int,error_code:string,field_name:?string,error_message:string,payload:array<string,mixed>,input_id:?int}
     */
    private function duplicate(
        int $rowNumber,
        array $payload,
        ?int $inputId,
        string $code,
        string $message,
    ): array {
        return [
            'row_number' => $rowNumber,
            'error_code' => $code,
            'field_name' => 'external_id',
            'error_message' => $message,
            'payload' => $payload,
            'input_id' => $inputId,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{row_number:int,error_code:string,field_name:?string,error_message:string}
     */
    private function publicError(array $row): array
    {
        $field = $row['field_name'] ?? null;
        return [
            'row_number' => PayrollTimeValue::int(
                $row['row_number'] ?? null,
                'row_number',
            ),
            'error_code' => PayrollTimeValue::string(
                $row['error_code'] ?? null,
                'error_code',
            ),
            'field_name' => $field === null
                ? null
                : PayrollTimeValue::string($field, 'field_name'),
            'error_message' => PayrollTimeValue::string(
                $row['error_message'] ?? null,
                'error_message',
            ),
        ];
    }

    /**
     * @return list<array{row_number:int,payload:array<string,mixed>,impact:array<string,mixed>,
     *   update:?array{input_id:int,row_version:int}}>
     */
    private function validRows(mixed $value): array
    {
        $rows = PayrollTimeValue::rows($value, '_valid');
        $result = [];
        foreach ($rows as $row) {
            $update = $row['update'] ?? null;
            $update = $update === null ? null : PayrollTimeValue::row($update, 'update');
            $result[] = [
                'row_number' => PayrollTimeValue::int(
                    $row['row_number'] ?? null,
                    'row_number',
                ),
                'payload' => PayrollTimeValue::row($row['payload'] ?? null, 'payload'),
                'impact' => PayrollTimeValue::row($row['impact'] ?? null, 'impact'),
                'update' => $update === null ? null : [
                    'input_id' => PayrollTimeValue::int($update['input_id'] ?? null, 'update.input_id'),
                    'row_version' => PayrollTimeValue::int(
                        $update['row_version'] ?? null,
                        'update.row_version',
                    ),
                ],
            ];
        }
        return $result;
    }

    /**
     * @return ($duplicates is true
     *   ? list<array{row_number:int,error_code:string,field_name:?string,error_message:string,payload:array<string,mixed>,input_id:?int,refresh:bool}>
     *   : list<array{row_number:int,error_code:string,field_name:?string,error_message:string,payload:array<string,mixed>}>)
     */
    private function errorRows(mixed $value, bool $duplicates): array
    {
        $rows = PayrollTimeValue::rows($value, $duplicates ? '_duplicates' : '_errors');
        $result = [];
        foreach ($rows as $row) {
            $field = $row['field_name'] ?? null;
            $normalized = [
                'row_number' => PayrollTimeValue::int(
                    $row['row_number'] ?? null,
                    'row_number',
                ),
                'error_code' => PayrollTimeValue::string(
                    $row['error_code'] ?? null,
                    'error_code',
                ),
                'field_name' => $field === null
                    ? null
                    : PayrollTimeValue::string($field, 'field_name'),
                'error_message' => PayrollTimeValue::string(
                    $row['error_message'] ?? null,
                    'error_message',
                ),
                'payload' => PayrollTimeValue::row($row['payload'] ?? null, 'payload'),
            ];
            if ($duplicates) {
                $inputId = $row['input_id'] ?? null;
                $normalized['input_id'] = $inputId === null
                    ? null
                    : PayrollTimeValue::int($inputId, 'input_id');
                $normalized['refresh'] = ($row['refresh'] ?? false) === true;
            }
            $result[] = $normalized;
        }
        return $result;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Součet ročního limitu je mimo podporovaný rozsah.');
        }
        return $left + $right;
    }
}
