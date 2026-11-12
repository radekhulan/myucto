<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Agreement;

use MyInvoice\Repository\Payroll\PayrollAgreementImportRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceCell;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceOpenAgreementMatcher;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonMatcher;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRegistrationCodebooks;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRegistrationData;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheet;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;

/**
 * Import dohod bez docházky (Mzdy → Importy → Dohody): jeden řádek = jedna
 * dohoda nebo pracovní poměr, bez vazby na měsíc. Typicky hromadné uzavření
 * DPP před akcí a příprava přihlášek ČSSZ.
 *
 * Osoba se v evidenci hledá stejně jako v importu docházky
 * ({@see AttendanceOpenAgreementMatcher}): rodné číslo, uložená vazba,
 * jednoznačné osobní číslo, bez rodného čísla jméno s datem narození jen
 * k potvrzení. Existující osoba dostane jen nový vztah, nová osoba kartu
 * i vztah; víc řádků jedné osoby = víc dohod. Zakládá se toutéž cestou jako
 * v importu docházky ({@see AttendanceImportService::createAgreement()}).
 *
 * Dohoda, kterou osoba v evidenci už má (stejný druh vztahu platný ke dni
 * nástupu z řádku), se nezakládá: stejný soubor podruhé nic nezaloží.
 * Server při založení náhled vždy přepočítá ze souborů.
 */
final class AgreementImportService
{
    private const MAX_PERSONS_PER_REQUEST = 200;

    public const STATUSES = ['create', 'exists', 'duplicate', 'error'];
    public const OUTCOMES = ['created', 'existing', 'skipped', 'failed'];
    public const RELATION_TYPES = ['dpp', 'dpc', 'employment', 'small_scale_employment'];

    private const RELATION_LABELS = [
        'dpp' => 'DPP',
        'dpc' => 'DPČ',
        'employment' => 'pracovní poměr',
        'small_scale_employment' => 'zaměstnání malého rozsahu',
    ];

    public function __construct(
        private readonly AttendanceWorkbookReader $reader,
        private readonly AttendancePersonMatcher $matcher,
        private readonly AttendanceOpenAgreementMatcher $existingMatcher,
        private readonly AttendanceImportService $attendance,
        private readonly AttendanceRegistrationCodebooks $codebooks,
        private readonly PayrollAgreementImportRepository $repository,
    ) {
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, array $files): array
    {
        return self::public($this->compute($supplierId, $files));
    }

    /**
     * Založí dohody ze souborů. `confirmations` = klíč osoby => id osoby
     * z evidence (potvrzená shoda podle jména a data narození), nebo `new`;
     * `person_keys` omezí založení na vybrané osoby (dávky po 200).
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{results:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function apply(
        int $supplierId,
        array $files,
        mixed $confirmations,
        mixed $personKeys,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $confirmations = is_array($confirmations) ? $confirmations : [];
        $only = null;
        if ($personKeys !== null) {
            if (!is_array($personKeys) || !array_is_list($personKeys)) {
                throw new \InvalidArgumentException('Seznam osob k založení nemá platný tvar.');
            }
            $only = array_fill_keys(array_map('strval', $personKeys), true);
        }
        $computed = $this->compute($supplierId, $files);
        $persons = array_values(array_filter(
            $computed['persons'],
            static fn (array $person): bool => $only === null || isset($only[$person['key']]),
        ));
        if (count($persons) > self::MAX_PERSONS_PER_REQUEST) {
            throw new \InvalidArgumentException(
                'Najednou lze založit dohody nejvýše ' . self::MAX_PERSONS_PER_REQUEST . ' osob. Rozdělte je na víc kroků.',
            );
        }
        $results = [];
        $counts = ['created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($persons as $person) {
            foreach ($this->applyPerson($supplierId, $person, $confirmations[$person['key']] ?? null, $userId, $ip, $userAgent) as $result) {
                ++$counts[$result['outcome']];
                $results[] = $result;
            }
        }

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array<string,mixed>>
     */
    private function applyPerson(
        int $supplierId,
        array $person,
        mixed $confirmation,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $results = [];
        $existing = $person['existing_person'];
        $blocking = $person['errors'] === [] ? null : implode(' ', $person['errors']);
        if ($blocking === null && is_array($existing) && $existing['requires_confirmation']) {
            if ($confirmation === 'new') {
                $existing = null;
            } elseif ($confirmation !== $existing['employee_id']) {
                $blocking = "V evidenci je osoba {$existing['employee_name']} se stejným jménem a datem narození. "
                    . 'Potvrďte, že jde o ni (dostane jen nové dohody), nebo zvolte založení nové osoby.';
            }
        }
        foreach ($person['agreements'] as $agreement) {
            $base = [
                'person_key' => $person['key'],
                'row_key' => $agreement['key'],
                'display_name' => $person['display_name'],
                'relation_type' => $agreement['relation_type'],
                'start_on' => $agreement['start_on'],
                'employee_id' => null,
                'employment_id' => null,
                'message' => null,
            ];
            if ($agreement['status'] === 'exists') {
                $results[] = ['outcome' => 'existing', 'employment_id' => $agreement['existing_employment_id'], 'message' => $agreement['note']] + $base;
                continue;
            }
            if ($agreement['status'] !== 'create') {
                $results[] = ['outcome' => 'skipped', 'message' => implode(' ', $agreement['errors']) ?: $agreement['note']] + $base;
                continue;
            }
            if ($blocking !== null) {
                $results[] = ['outcome' => 'skipped', 'message' => $blocking] + $base;
                continue;
            }
            try {
                $created = $this->attendance->createAgreement(
                    $supplierId,
                    $this->item($person, $agreement),
                    is_array($existing) ? $existing : null,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $results[] = [
                    'outcome' => 'created',
                    'employee_id' => $created['employee_id'],
                    'employment_id' => $created['employment_id'],
                    'message' => $created['message'],
                ] + $base;
                if ($existing === null) {
                    $existing = [
                        'employee_id' => $created['employee_id'],
                        'employee_name' => $person['display_name'],
                        'matched_by' => 'created',
                        'relation_type' => $agreement['relation_type'],
                        'requires_confirmation' => false,
                    ];
                }
            } catch (LicensePayrollLimitExceeded) {
                $results[] = ['outcome' => 'failed', 'message' => 'Dalšího aktivního zaměstnance lze přidat až po rozšíření mzdového doplňku.'] + $base;
            } catch (\InvalidArgumentException|\DomainException|PayrollEmploymentConflictException $e) {
                $results[] = ['outcome' => 'failed', 'message' => $e->getMessage()] + $base;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $results[] = [
                    'outcome' => 'failed',
                    'message' => 'Dohodu se nepodařilo založit kvůli kolizi údajů — například rodné číslo už má jiná osoba.',
                ] + $base;
            }
        }

        return $results;
    }

    /**
     * Řádek zakládání ve tvaru kroku Osoby importu docházky.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $agreement
     * @return array<string,mixed>
     */
    private function item(array $person, array $agreement): array
    {
        return [
            'full_name' => trim($person['first_name'] . ' ' . $person['last_name']),
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'birth_number' => $person['_birth_number'],
            'birth_date' => $person['_birth_date'],
            'health_insurer_code' => $agreement['health_insurer_code'] ?? $person['health_insurer_code'],
            'bank_account' => $agreement['bank_account'] ?? $person['bank_account'],
            'personal_number' => $person['personal_number'],
            '_number_ambiguous' => $person['_number_ambiguous'] ?? false,
            'relation_type' => $agreement['relation_type'],
            'planned_start_on' => $agreement['start_on'],
            'fixed_term_end_on' => $agreement['end_on'],
            'hourly_rate' => $agreement['hourly_rate'],
            'monthly_gross' => $agreement['monthly_gross'],
            'weekly_hours' => $agreement['weekly_hours'],
            'activate' => true,
            '_registration' => $agreement['_registration'],
        ];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{persons:list<array<string,mixed>>,files:list<array<string,mixed>>}
     */
    private function compute(int $supplierId, array $files): array
    {
        if ($files === []) {
            throw new \InvalidArgumentException('Nahrajte soubor s dohodami.');
        }
        $rows = [];
        $fileRows = [];
        foreach ($files as $index => $file) {
            try {
                $sheets = $this->reader->read($file['name'], $index, $file['extension'], $file['content']);
            } catch (\InvalidArgumentException $e) {
                $fileRows[] = ['name' => $file['name'], 'rows' => 0, 'error' => $e->getMessage()];
                continue;
            }
            $count = 0;
            foreach ($sheets as $sheet) {
                $read = $this->sheetRows($sheet);
                $count += count($read);
                array_push($rows, ...$read);
            }
            $fileRows[] = ['name' => $file['name'], 'rows' => $count, 'error' => $count === 0
                ? 'V souboru není list s hlavičkou importu dohod (Příjmení, Jméno, Druh vztahu, Nástup…). Stáhněte si šablonu.'
                : null];
        }
        $persons = $this->group($rows);
        $persons = $this->matcher->withIdentityKeys($supplierId, $persons);
        $persons = $this->existingMatcher->annotate($supplierId, date('Y-m-d'), $persons, false);
        $employeeIds = [];
        foreach ($persons as $person) {
            if (is_array($person['existing_person'] ?? null)) {
                $employeeIds[] = (int) $person['existing_person']['employee_id'];
            }
        }
        $employments = $this->repository->employmentsOf($supplierId, $employeeIds);
        foreach ($persons as $index => $person) {
            $persons[$index] = $this->plan($person, $employments);
        }

        return ['persons' => $persons, 'files' => $fileRows];
    }

    /**
     * Řádky listu s hlavičkou importu dohod jako text podle významu.
     *
     * @return list<array<string,mixed>>
     */
    private function sheetRows(AttendanceSheet $sheet): array
    {
        $texts = [];
        foreach ($sheet->rows as $rowNumber => $cells) {
            if ($rowNumber > 20) {
                break;
            }
            foreach ($cells as $columnNumber => $cell) {
                $texts[$rowNumber][$columnNumber] = $cell->textValue();
            }
        }
        $header = AgreementImportColumns::header($texts);
        if ($header === null) {
            return [];
        }
        $formats = [];
        foreach (AgreementImportColumns::columns() as $column) {
            $formats[$column['meaning']] = $column['format'];
        }
        $rows = [];
        foreach ($sheet->rows as $rowNumber => $cells) {
            if ($rowNumber <= $header['row']) {
                continue;
            }
            $values = [];
            foreach ($header['columns'] as $columnNumber => $meaning) {
                $cell = $cells[$columnNumber] ?? null;
                if ($cell === null || $cell->kind === AttendanceCell::ERROR) {
                    continue;
                }
                $text = $this->cellText($cell, $formats[$meaning] ?? 'text');
                if ($text !== '') {
                    $values[$meaning] = $text;
                }
            }
            if (!isset($values['last_name']) && !isset($values['first_name']) && !isset($values['person_name'])) {
                continue;
            }
            $rows[] = ['source' => $sheet->source($rowNumber, 1), 'sheet' => $sheet->name, 'row' => $rowNumber, 'values' => $values];
        }

        return $rows;
    }

    private function cellText(AttendanceCell $cell, string $format): string
    {
        if ($format === 'date' && $cell->kind === AttendanceCell::NUMBER) {
            $days = (int) floor((float) $cell->number);
            if ($days < 1 || $days > 2958465) {
                return '';
            }

            return (new \DateTimeImmutable('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
        }

        return trim((string) preg_replace('/\s+/u', ' ', $cell->textValue()));
    }

    /**
     * Řádky jedné osoby: podle platného rodného čísla, bez něj podle jména,
     * data narození a osobního čísla.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function group(array $rows): array
    {
        $persons = [];
        $keys = [];
        foreach ($rows as $row) {
            $values = $row['values'];
            [$firstName, $lastName] = self::names($values);
            $displayName = trim($lastName . ' ' . $firstName);
            $nameKey = AttendanceText::personKey($displayName);
            $birthDate = isset($values['birth_date']) ? AttendancePersonAggregator::isoDate($values['birth_date']) : null;
            $birth = AttendanceBirthNumber::read($values['birth_number'] ?? null, $birthDate);
            $number = $values['personal_number'] ?? null;
            $identity = is_string($birth['value']) && AttendanceBirthNumber::birthDateOf($birth['value']) !== null && $birth['error'] === null
                ? 'rc:' . preg_replace('/\D/', '', $birth['value'])
                : 'n:' . $nameKey . '|' . ($birthDate ?? '') . '|' . mb_strtolower((string) $number, 'UTF-8');
            if (!isset($keys[$identity])) {
                $keys[$identity] = 'osoba-' . (count($keys) + 1);
                $persons[$keys[$identity]] = [
                    'key' => $keys[$identity],
                    'display_name' => $displayName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'personal_number' => $number,
                    'relation_label' => null,
                    'health_insurer_code' => $values['health_insurer_code'] ?? null,
                    'bank_account' => $values['bank_account'] ?? null,
                    'match' => ['status' => 'not_found'],
                    'warnings' => $birth['warnings'],
                    'errors' => [],
                    '_name_key' => $nameKey,
                    '_birth_number' => $birth['value'],
                    '_birth_number_error' => $birth['error'],
                    '_birth_date' => $birthDate ?? AttendanceBirthNumber::birthDateOf($birth['value']),
                    'rows' => [],
                ];
                if ($birth['error'] !== null) {
                    $persons[$keys[$identity]]['errors'][] = $birth['error'];
                }
            } elseif (AttendanceText::personKey($displayName) !== $persons[$keys[$identity]]['_name_key']) {
                $persons[$keys[$identity]]['warnings'][] = "Řádek {$row['row']} má u stejného rodného čísla jiné jméno („{$displayName}“); použije se jméno z prvního řádku.";
            }
            $persons[$keys[$identity]]['rows'][] = $row;
        }

        return array_values($persons);
    }

    /**
     * @param array<string,string> $values
     * @return array{0:string,1:string} jméno, příjmení
     */
    private static function names(array $values): array
    {
        $first = trim($values['first_name'] ?? '');
        $last = trim($values['last_name'] ?? '');
        if (($first === '' || $last === '') && isset($values['person_name'])) {
            $words = preg_split('/\s+/u', trim($values['person_name'])) ?: [];
            $last = $last === '' ? (string) array_shift($words) : $last;
            $first = $first === '' ? implode(' ', $words) : $first;
        }

        return [$first, $last];
    }

    /**
     * Dohody osoby: co se založí, co už v evidenci je a proč řádek nejde.
     *
     * @param array<string,mixed> $person
     * @param array<int,list<array<string,mixed>>> $employments
     * @return array<string,mixed>
     */
    private function plan(array $person, array $employments): array
    {
        if ($person['first_name'] === '' || $person['last_name'] === '') {
            $person['errors'][] = 'Chybí jméno nebo příjmení osoby.';
        }
        if (($person['_birth_number_invalid'] ?? false) && !is_array($person['existing_person'] ?? null)) {
            $person['errors'][] = 'Rodné číslo není platné (nesedí kontrolní číslice), osoba s ním nejde založit. Opravte ho v souboru.';
        }
        if (($person['match']['status'] ?? null) === 'conflict') {
            $person['errors'][] = (string) $person['match']['conflict'];
        }
        $person['errors'] = array_values(array_unique($person['errors']));
        if ($person['_number_ambiguous'] ?? false) {
            $person['warnings'][] = AttendancePersonMatcher::ambiguousNumberWarning((string) $person['personal_number']);
        }
        $existing = is_array($person['existing_person'] ?? null) ? $person['existing_person'] : null;
        $held = $existing === null ? [] : ($employments[(int) $existing['employee_id']] ?? []);
        $agreements = [];
        $planned = [];
        usort($person['rows'], static fn (array $a, array $b): int => [$a['values']['start_date'] ?? '', $a['row']] <=> [$b['values']['start_date'] ?? '', $b['row']]);
        foreach ($person['rows'] as $row) {
            $agreement = $this->agreement($row);
            if ($agreement['errors'] === []) {
                $covering = self::covering($held, $agreement);
                if ($covering !== null) {
                    $agreement['status'] = 'exists';
                    $agreement['existing_employment_id'] = $covering['employment_id'];
                    $agreement['note'] = "Osoba už má {$this->relationLabel($covering['relation_type'])} {$covering['code']} platnou od "
                        . self::czechDate((string) $covering['start_on'])
                        . ($covering['end_on'] === null ? '' : ' do ' . self::czechDate($covering['end_on']))
                        . '; nová se nezaloží.';
                } elseif (self::covering($planned, $agreement) !== null) {
                    $agreement['status'] = 'duplicate';
                    $agreement['note'] = 'Stejnou dohodu (druh vztahu a období) uvádí už dřívější řádek souboru; tenhle se nezaloží.';
                } else {
                    $agreement['status'] = 'create';
                    $planned[] = [
                        'employment_id' => 0,
                        'code' => '',
                        'relation_type' => $agreement['relation_type'],
                        'start_on' => $agreement['start_on'],
                        'end_on' => $agreement['end_on'],
                    ];
                }
            }
            $agreements[] = $agreement;
        }
        unset($person['rows']);
        $person['agreements'] = $agreements;

        return $person;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function agreement(array $row): array
    {
        $values = $row['values'];
        $errors = [];
        $warnings = [];
        $relationType = self::relationType($values['relation_label'] ?? '');
        if ($relationType === null) {
            $errors[] = isset($values['relation_label'])
                ? "Druh vztahu „{$values['relation_label']}“ nejde přečíst (DPP, DPČ, HPP)."
                : 'Chybí druh vztahu (DPP, DPČ, HPP).';
        }
        $start = isset($values['start_date']) ? AttendancePersonAggregator::isoDate($values['start_date']) : null;
        if ($start === null) {
            $errors[] = isset($values['start_date'])
                ? "Nástup „{$values['start_date']}“ nejde přečíst. Zapište ho jako den. měsíc. rok."
                : 'Chybí den nástupu.';
        }
        $end = isset($values['end_date']) ? AttendancePersonAggregator::isoDate($values['end_date']) : null;
        if (isset($values['end_date']) && $end === null) {
            $errors[] = "Konec „{$values['end_date']}“ nejde přečíst. Zapište ho jako den. měsíc. rok.";
        } elseif ($end !== null && $start !== null && $end < $start) {
            $errors[] = 'Konec dohody je před nástupem.';
        }
        $monthly = null;
        if (isset($values['monthly_wage'])) {
            $amount = AttendancePersonAggregator::amountMinor(AttendanceCell::string($values['monthly_wage']));
            if (isset($amount['value']) && $amount['value'] >= 0 && $amount['value'] % 100 === 0) {
                $monthly = intdiv($amount['value'], 100);
            } else {
                $warnings[] = "Měsíční odměnu „{$values['monthly_wage']}“ nejde přečíst jako celé koruny; vztah se založí bez ní.";
            }
        }
        $registration = [];
        foreach (AttendanceMeaning::REGISTRATION as $field) {
            if (isset($values[$field])) {
                $registration[$field] = $values[$field];
            }
        }
        $display = [];
        if ($registration !== [] && $start !== null) {
            $plan = AttendanceRegistrationData::plan($registration, $start, $this->codebooks);
            array_push($warnings, ...$plan['warnings']);
            $display = AttendanceRegistrationData::display($plan);
        }

        return [
            'key' => $row['sheet'] . '!' . $row['row'],
            'sheet' => $row['sheet'],
            'row' => $row['row'],
            'relation_type' => $relationType,
            'relation_label' => $values['relation_label'] ?? null,
            'start_on' => $start,
            'end_on' => $end,
            'hourly_rate' => $values['hourly_rate'] ?? null,
            'monthly_gross' => $monthly,
            'weekly_hours' => $values['weekly_hours'] ?? null,
            'health_insurer_code' => $values['health_insurer_code'] ?? null,
            'bank_account' => $values['bank_account'] ?? null,
            'status' => 'error',
            'existing_employment_id' => null,
            'note' => null,
            'errors' => $errors,
            'warnings' => $warnings,
            'registration' => $display,
            '_registration' => $registration,
        ];
    }

    /**
     * Vztah téhož druhu, který ke dni nástupu z řádku platí.
     *
     * @param list<array<string,mixed>> $employments
     * @param array<string,mixed> $agreement
     * @return array<string,mixed>|null
     */
    private static function covering(array $employments, array $agreement): ?array
    {
        foreach ($employments as $employment) {
            if ($employment['relation_type'] !== $agreement['relation_type'] || $employment['start_on'] === null) {
                continue;
            }
            if ($employment['start_on'] <= $agreement['start_on']
                && ($employment['end_on'] === null || $employment['end_on'] >= $agreement['start_on'])) {
                return $employment;
            }
        }

        return null;
    }

    public static function relationType(string $label): ?string
    {
        $normalized = AttendanceText::normalize($label);
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            preg_match('/\bdpp\b|provedeni prace/u', $normalized) === 1 => 'dpp',
            preg_match('/\bdpc\b|pracovni cinnosti/u', $normalized) === 1 => 'dpc',
            preg_match('/maleho rozsahu|\bzmr\b/u', $normalized) === 1 => 'small_scale_employment',
            preg_match('/\bhpp\b|\bvpp\b|pracovni pomer/u', $normalized) === 1 => 'employment',
            default => null,
        };
    }

    private function relationLabel(string $type): string
    {
        return self::RELATION_LABELS[$type] ?? $type;
    }

    private static function czechDate(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return "{$day}. {$month}. {$year}";
    }

    /**
     * Náhled pro klienta: bez rodného čísla, účtu a údajů pro registraci.
     *
     * @param array{persons:list<array<string,mixed>>,files:list<array<string,mixed>>} $computed
     * @return array<string,mixed>
     */
    private static function public(array $computed): array
    {
        $summary = ['persons' => 0, 'new_persons' => 0, 'existing_persons' => 0, 'confirm' => 0, 'create' => 0, 'exists' => 0, 'errors' => 0];
        $persons = [];
        foreach ($computed['persons'] as $person) {
            $existing = is_array($person['existing_person'] ?? null) ? $person['existing_person'] : null;
            ++$summary['persons'];
            if ($existing === null) {
                ++$summary['new_persons'];
            } elseif ($existing['requires_confirmation']) {
                ++$summary['confirm'];
            } else {
                ++$summary['existing_persons'];
            }
            $agreements = [];
            foreach ($person['agreements'] as $agreement) {
                $status = $person['errors'] !== [] && $agreement['status'] === 'create' ? 'error' : $agreement['status'];
                match ($status) {
                    'create' => ++$summary['create'],
                    'exists' => ++$summary['exists'],
                    default => ++$summary['errors'],
                };
                $agreements[] = [
                    'key' => $agreement['key'],
                    'sheet' => $agreement['sheet'],
                    'row' => $agreement['row'],
                    'relation_type' => $agreement['relation_type'],
                    'relation_label' => $agreement['relation_label'],
                    'start_on' => $agreement['start_on'],
                    'end_on' => $agreement['end_on'],
                    'status' => $status,
                    'note' => $agreement['note'],
                    'errors' => $agreement['errors'],
                    'warnings' => $agreement['warnings'],
                    'registration' => $agreement['registration'],
                ];
            }
            $birthNumber = $person['_birth_number'];
            $persons[] = [
                'key' => $person['key'],
                'display_name' => $person['display_name'],
                'personal_number' => $person['personal_number'],
                'birth_number_masked' => is_string($birthNumber) && strlen((string) preg_replace('/\D/', '', $birthNumber)) >= 2
                    ? '••••••••' . substr((string) preg_replace('/\D/', '', $birthNumber), -2)
                    : null,
                'birth_date' => $person['_birth_date'],
                'existing_person' => $existing === null ? null : [
                    'employee_id' => (int) $existing['employee_id'],
                    'employee_name' => (string) $existing['employee_name'],
                    'matched_by' => (string) $existing['matched_by'],
                    'requires_confirmation' => (bool) $existing['requires_confirmation'],
                ],
                'warnings' => array_values(array_unique($person['warnings'])),
                'errors' => $person['errors'],
                'agreements' => $agreements,
            ];
        }

        return ['persons' => $persons, 'files' => $computed['files'], 'summary' => $summary];
    }
}
