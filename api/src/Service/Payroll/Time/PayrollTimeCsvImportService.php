<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;

final class PayrollTimeCsvImportService
{
    private const REQUIRED = [
        'employment_code',
        'starts_at',
        'ends_at',
        'timezone',
        'category',
        'external_id',
    ];
    private const CATEGORIES = [
        'regular',
        'overtime',
        'night',
        'weekend',
        'holiday',
        'difficult_environment',
    ];
    private const CANONICAL_COLUMNS = [
        'employment_id',
        'employment_code',
        'starts_at',
        'ends_at',
        'timezone',
        'category',
        'break_minutes',
        'external_id',
    ];

    public function __construct(
        private readonly PayrollTimeRepository $repository,
        private readonly PayrollTimeService $time,
        private readonly PayrollInputTabularParser $parser,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        string $period,
        string $format,
        string $originalName,
        string $content,
    ): array {
        $format = $this->format($format);
        $originalName = $this->originalName($originalName);
        $content = $this->decodedContent($format, $content);
        return $this->previewDecoded(
            $supplierId,
            $period,
            $format,
            $originalName,
            $content,
        );
    }

    /** @return array<string,mixed> */
    private function previewDecoded(
        int $supplierId,
        string $period,
        string $format,
        string $originalName,
        string $content,
    ): array {
        [$periodStart] = $this->time->periodBounds($period);
        $parsed = $this->parser->parse($format, $content, self::REQUIRED);
        $errors = array_map($this->parserError(...), $parsed['errors']);
        $accepted = [];
        $duplicates = 0;
        $seenSourceHashes = [];
        $resolvedEmployments = [];
        foreach ($parsed['rows'] as $row) {
            $row['row_hash'] = $this->rowHash($row);
            $validated = $this->validateRow(
                $supplierId,
                $period,
                $row,
                $resolvedEmployments,
            );
            if (isset($validated['error'])) {
                $errors[] = $validated['error'];
                continue;
            }
            $entry = $validated['entry'];
            $sourceHash = PayrollTimeValue::string($entry['source_hash'] ?? null, 'source_hash');
            $sourceHashKey = bin2hex($sourceHash);
            if (isset($seenSourceHashes[$sourceHashKey])) {
                ++$duplicates;
                continue;
            }
            $seenSourceHashes[$sourceHashKey] = true;
            if ($this->repository->hasEntrySourceHash(
                $supplierId,
                PayrollTimeValue::int($entry['employment_id'] ?? null, 'employment_id'),
                $sourceHash,
            )) {
                ++$duplicates;
                continue;
            }
            $accepted[] = $entry;
        }

        return [
            'format' => $format,
            'supported' => true,
            'status' => 'preview',
            'period' => $period,
            'period_start' => $periodStart,
            'original_name' => $originalName,
            'content_hash' => hash('sha256', $content),
            'total_rows' => count($parsed['rows']) + count($parsed['errors']),
            'accepted_rows' => count($accepted),
            'rejected_rows' => count($errors),
            'duplicate_rows' => $duplicates,
            'errors' => array_map(
                static fn (array $error): array => array_diff_key($error, ['row_hash' => true]),
                $errors,
            ),
            'rows' => array_map(
                static fn (array $row): array => array_diff_key(
                    $row,
                    ['source_hash' => true, 'row_hash' => true],
                ),
                $accepted,
            ),
            '_accepted' => $accepted,
            '_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function import(
        int $supplierId,
        string $period,
        string $format,
        string $originalName,
        string $content,
        ?int $userId,
    ): array {
        $format = $this->format($format);
        $originalName = $this->originalName($originalName);
        $content = $this->decodedContent($format, $content);
        [$periodStart] = $this->time->periodBounds($period);
        $contentHash = hash('sha256', $content, true);
        $existing = $this->repository->importByHash($supplierId, $periodStart, $contentHash);
        if ($existing !== null) {
            $existing['replayed'] = true;
            return $existing;
        }

        $preview = $this->previewDecoded(
            $supplierId,
            $period,
            $format,
            $originalName,
            $content,
        );

        $acceptedRows = PayrollTimeValue::rows($preview['_accepted'] ?? null, '_accepted');
        $errors = $this->errors($preview['_errors'] ?? null);
        $accepted = 0;
        $duplicates = PayrollTimeValue::int(
            $preview['duplicate_rows'] ?? null,
            'duplicate_rows',
        );
        foreach ($acceptedRows as $row) {
            $employmentId = PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            );
            $sourceHash = PayrollTimeValue::string(
                $row['source_hash'] ?? null,
                'source_hash',
            );
            if ($this->repository->hasEntrySourceHash(
                $supplierId,
                $employmentId,
                $sourceHash,
            )) {
                ++$duplicates;
                continue;
            }
            $state = $this->repository->monthState(
                $supplierId,
                $employmentId,
                $periodStart,
            );
            $row['month_row_version'] = $state === null
                ? 0
                : PayrollTimeValue::int($state['row_version'] ?? null, 'row_version');
            $row['row_version'] = 0;
            try {
                $this->time->saveEntry(
                    $supplierId,
                    $row,
                    $userId,
                    'import',
                    PayrollTimeValue::string($row['external_id'] ?? null, 'external_id'),
                    $sourceHash,
                );
                ++$accepted;
            } catch (PayrollTimeLockedException) {
                $errors[] = $this->error(
                    PayrollTimeValue::int($row['row_number'] ?? null, 'row_number'),
                    'month_locked',
                    null,
                    'Měsíc pracovního vztahu je schválený; před importem jej znovu otevřete.',
                    PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash'),
                );
            } catch (\InvalidArgumentException $e) {
                $errors[] = $this->error(
                    PayrollTimeValue::int($row['row_number'] ?? null, 'row_number'),
                    'row_rejected',
                    null,
                    $e->getMessage(),
                    PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash'),
                );
            }
        }

        $rejected = count($errors);
        $status = $accepted > 0 && $rejected > 0
            ? 'partial'
            : ($accepted > 0 ? 'imported' : 'failed');
        $recorded = $this->repository->recordImport(
            $supplierId,
            $periodStart,
            $format,
            $originalName,
            $contentHash,
            $status,
            PayrollTimeValue::int($preview['total_rows'] ?? null, 'total_rows'),
            $accepted,
            $rejected,
            $duplicates,
            $errors,
            $userId,
        );
        $recorded['replayed'] = false;
        return $recorded;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int|null> $resolvedEmployments
     * @return array{entry:array<string,mixed>}|array{
     *   error:array{row_number:int,error_code:string,field_name:?string,error_message:string,row_hash:string}
     * }
     */
    private function validateRow(
        int $supplierId,
        string $period,
        array $row,
        array &$resolvedEmployments,
    ): array {
        $rowNumber = PayrollTimeValue::int($row['row_number'] ?? null, 'row_number');
        $rowHash = PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash');
        $employmentCode = $this->nullable(
            PayrollTimeValue::string($row['employment_code'] ?? '', 'employment_code'),
        );
        $employmentIdRaw = $this->nullable(
            PayrollTimeValue::string($row['employment_id'] ?? '', 'employment_id'),
        );
        $employmentId = null;
        if ($employmentIdRaw !== null) {
            if (!ctype_digit($employmentIdRaw) || (int) $employmentIdRaw <= 0) {
                return ['error' => $this->error(
                    $rowNumber,
                    'invalid_employment_id',
                    'employment_id',
                    'employment_id musí být kladné celé číslo.',
                    $rowHash,
                )];
            }
            $employmentId = (int) $employmentIdRaw;
        }
        $resolutionKey = ($employmentId === null ? '' : (string) $employmentId)
            . "\0" . ($employmentCode ?? '');
        if (!array_key_exists($resolutionKey, $resolvedEmployments)) {
            $resolvedEmployments[$resolutionKey] = $this->repository->resolveEmployment(
                $supplierId,
                $employmentId,
                $employmentCode,
            );
        }
        $resolved = $resolvedEmployments[$resolutionKey];
        if ($resolved === null) {
            return ['error' => $this->error(
                $rowNumber,
                'employment_not_unique',
                'employment_code',
                'Pracovní vztah nebyl v této firmě jednoznačně nalezen; použijte employment_id a odpovídající employment_code.',
                $rowHash,
            )];
        }
        $externalId = trim(
            PayrollTimeValue::string($row['external_id'] ?? '', 'external_id'),
        );
        if ($externalId === '' || mb_strlen($externalId) > 191) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_external_id',
                'external_id',
                'external_id je povinné a smí mít nejvýše 191 znaků.',
                $rowHash,
            )];
        }
        $break = trim(
            PayrollTimeValue::string($row['break_minutes'] ?? '0', 'break_minutes'),
        );
        if ($break === '') {
            $break = '0';
        }
        if (!ctype_digit($break)) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_break',
                'break_minutes',
                'break_minutes musí být nezáporné celé číslo.',
                $rowHash,
            )];
        }
        $breakMinutes = (int) $break;
        $category = PayrollTimeValue::string($row['category'] ?? '', 'category');
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_category',
                'category',
                'category není podporovaná kategorie pracovního času.',
                $rowHash,
            )];
        }
        $startsAt = PayrollTimeValue::string($row['starts_at'] ?? '', 'starts_at');
        $timezone = PayrollTimeValue::string($row['timezone'] ?? '', 'timezone');
        try {
            $interval = PayrollTimeInterval::fromIso(
                $startsAt,
                PayrollTimeValue::string($row['ends_at'] ?? '', 'ends_at'),
                $timezone,
            );
            $start = (new \DateTimeImmutable(
                $interval->startsAtUtc,
                new \DateTimeZone('UTC'),
            ))->setTimezone(new \DateTimeZone($timezone));
        } catch (\InvalidArgumentException $e) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_interval',
                'starts_at',
                $e->getMessage(),
                $rowHash,
            )];
        }
        if ($start->format('Y-m') !== $period) {
            return ['error' => $this->error(
                $rowNumber,
                'period_mismatch',
                'starts_at',
                'Začátek záznamu neleží v importovaném období.',
                $rowHash,
            )];
        }
        if ($breakMinutes >= $interval->durationMinutes) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_break',
                'break_minutes',
                'Přestávka musí být kratší než časový interval.',
                $rowHash,
            )];
        }

        $sourceHash = hash('sha256', "payroll-time-import\0{$resolved}\0{$externalId}", true);
        return ['entry' => [
            'row_number' => $rowNumber,
            'row_hash' => $rowHash,
            'employment_id' => $resolved,
            'employment_code' => $employmentCode,
            'starts_at' => $startsAt,
            'ends_at' => PayrollTimeValue::string($row['ends_at'] ?? '', 'ends_at'),
            'timezone' => $timezone,
            'category' => $category,
            'break_minutes' => $breakMinutes,
            'external_id' => $externalId,
            'source_hash' => $sourceHash,
        ]];
    }

    /**
     * @return array{
     *   row_number:int,
     *   error_code:string,
     *   field_name:?string,
     *   error_message:string,
     *   row_hash:string
     * }
     */
    private function error(
        int $row,
        string $code,
        ?string $field,
        string $message,
        string $rowHash,
    ): array {
        return [
            'row_number' => $row,
            'error_code' => $code,
            'field_name' => $field,
            'error_message' => $message,
            'row_hash' => $rowHash,
        ];
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array{row_number:int,error_code:string,field_name:?string,error_message:string} $error
     * @return array{row_number:int,error_code:string,field_name:?string,error_message:string,row_hash:string}
     */
    private function parserError(array $error): array
    {
        $rowNumber = $error['row_number'];
        $errorCode = $error['error_code'];
        return [
            'row_number' => $rowNumber,
            'error_code' => $errorCode,
            'field_name' => $error['field_name'],
            'error_message' => $error['error_message'],
            'row_hash' => hash(
                'sha256',
                "payroll-time-import-error\0{$rowNumber}\0{$errorCode}",
                true,
            ),
        ];
    }

    /** @param array<string,string|int> $row */
    private function rowHash(array $row): string
    {
        $canonical = [];
        foreach (self::CANONICAL_COLUMNS as $column) {
            $value = $row[$column] ?? '';
            $canonical[$column] = trim((string) $value);
        }
        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            true,
        );
    }

    private function format(string $format): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formát musí být csv nebo xlsx.');
        }
        return $format;
    }

    private function originalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '' || mb_strlen($name) > 190
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new \InvalidArgumentException('Název importního souboru není platný.');
        }
        return $name;
    }

    private function decodedContent(string $format, string $content): string
    {
        if ($format === 'csv') {
            return $content;
        }
        if (strlen($content) > 6_700_000) {
            throw new \InvalidArgumentException('XLSX překračuje bezpečný limit 5 MB.');
        }
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Obsah XLSX není platné Base64.');
        }
        return $decoded;
    }

    /**
     * @return list<array{
     *   row_number:int,
     *   error_code:string,
     *   field_name:?string,
     *   error_message:string,
     *   row_hash:string
     * }>
     */
    private function errors(mixed $value): array
    {
        $rows = PayrollTimeValue::rows($value, '_errors');
        $result = [];
        foreach ($rows as $row) {
            $field = $row['field_name'] ?? null;
            if ($field !== null && !is_string($field)) {
                throw new \UnexpectedValueException('field_name musí být text nebo null.');
            }
            $result[] = [
                'row_number' => PayrollTimeValue::int(
                    $row['row_number'] ?? null,
                    'row_number',
                ),
                'error_code' => PayrollTimeValue::string(
                    $row['error_code'] ?? null,
                    'error_code',
                ),
                'field_name' => $field,
                'error_message' => PayrollTimeValue::string(
                    $row['error_message'] ?? null,
                    'error_message',
                ),
                'row_hash' => PayrollTimeValue::string(
                    $row['row_hash'] ?? null,
                    'row_hash',
                ),
            ];
        }
        return $result;
    }
}
