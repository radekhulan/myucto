<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use PDO;

/** Zaměstnanecké karty ze Stereo NX; zpracované mzdy zůstávají v účetním deníku. */
final class StereoNxEmployees
{
    private const KIND_EMPLOYEE = 'payroll_employee';
    private const KIND_EMPLOYMENT = 'payroll_employment';
    private const NOTE = 'Převzato ze Stereo NX.';

    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxImportMap $map,
        private readonly PayrollPersonCreateService $people,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollTakeoverPersonWriter $personWriter,
        private readonly PayrollTakeoverEmploymentWriter $employmentWriter,
        private readonly PayrollComponentRepository $components,
    ) {}

    /** @return array<string,mixed> Interní plán obsahuje osobní údaje a nesmí se vracet v protokolu. */
    public function prepare(StereoNxBackup $backup): array
    {
        return self::fromTables(
            [
                'MZAMEST' => iterator_to_array($backup->rows('MZAMEST'), false),
                'MMzdy' => iterator_to_array($backup->rows('MMzdy'), false),
                'MPOJIST' => iterator_to_array($backup->rows('MPOJIST'), false),
                'MDeti' => iterator_to_array($backup->rows('MDeti'), false),
                'MOpNezdC' => iterator_to_array($backup->rows('MOpNezdC'), false),
                'MDovol' => iterator_to_array($backup->rows('MDovol'), false),
                'MPRVYD' => iterator_to_array($backup->rows('MPRVYD'), false),
            ],
            $backup->companyIdentity(),
            $backup->companyIndex(),
        );
    }

    /**
     * Čistá cesta pro syntetické testy. Kód vztahu `P` se podporuje jen společně
     * s kategorií `HPP`, kterou uživatelská příručka Stereo označuje jako hlavní
     * pracovní poměr. Jiné kombinace se nesmějí domýšlet.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     * @param array{ico:string,dic?:string,name?:string,vat_payer?:bool} $identity
     * @return array<string,mixed>
     */
    public static function fromTables(array $tables, array $identity, int $companyIndex): array
    {
        foreach (['MZAMEST', 'MMzdy', 'MPOJIST', 'MDeti', 'MOpNezdC', 'MDovol', 'MPRVYD'] as $name) {
            if (!array_key_exists($name, $tables)) {
                throw new StereoNxException('payroll_table_missing', 'Chybí zdrojová mzdová tabulka.');
            }
        }
        $ico = preg_replace('/\D/', '', (string) ($identity['ico'] ?? '')) ?? '';
        if (!preg_match('/^[0-9]{8}$/D', $ico) || $companyIndex < 0) {
            throw new StereoNxException('employee_source_identity_invalid', 'Zdrojová firma nemá platnou identitu.');
        }

        $warnings = [];
        $insurers = self::insurers($tables['MPOJIST']);
        $declarations = self::taxDeclarations($tables['MMzdy'], $warnings);
        $records = [];
        $keys = [];
        $skipped = 0;
        foreach ($tables['MZAMEST'] as $row) {
            $key = self::text($row['Prac'] ?? null);
            if ($key === '' || strlen($key) > 190 || str_contains($key, "\0")) {
                throw new StereoNxException('employee_key_invalid', 'Karta zaměstnance nemá platný zdrojový klíč.');
            }
            if (isset($keys[$key])) {
                throw new StereoNxException('employee_key_duplicate', 'Zdroj obsahuje duplicitní kartu zaměstnance.');
            }
            $keys[$key] = true;

            $firstName = self::text($row['KrestniJmeno'] ?? null);
            $lastName = self::text($row['Prijmeni'] ?? null);
            $start = self::date($row['DatumNastupu'] ?? null, false);
            $end = self::date($row['DatumUkonceni'] ?? null, true);
            if ($firstName === '' || $lastName === '' || $start === null
                || ($end !== null && $end < $start)) {
                $skipped++;
                self::warning($warnings, 'employee_required_data_invalid',
                    'Některé zaměstnanecké karty nemají úplné jméno nebo platná data a nebyly zařazeny do převodu.');
                continue;
            }
            if (($row['PracPravVztah'] ?? null) !== 'P' || ($row['Odvod'] ?? null) !== 'HPP'
                || ($row['Zamestnanec'] ?? null) !== true || ($row['StatOrg'] ?? null) !== false) {
                $skipped++;
                self::warning($warnings, 'employee_relation_unsupported',
                    'Některé pracovněprávní vztahy nemají ověřené mapování a nebyly zařazeny do převodu.');
                continue;
            }
            if (($row['Vyrazen'] ?? null) === true && $end === null) {
                $skipped++;
                self::warning($warnings, 'employee_end_date_missing',
                    'Vyřazená karta bez data ukončení nebyla zařazena do převodu.');
                continue;
            }

            $birthDate = self::date($row['Narozeni'] ?? null, true);
            if (self::text($row['Narozeni'] ?? null) !== '' && $birthDate === null) {
                $skipped++;
                self::warning($warnings, 'employee_birth_date_invalid',
                    'Karta s neplatným datem narození nebyla zařazena do převodu.');
                continue;
            }
            $birthNumber = self::text($row['RC'] ?? null);
            if ($birthNumber !== '') {
                try {
                    $birthNumber = CzechBirthNumber::normalize($birthNumber);
                } catch (\InvalidArgumentException) {
                    $birthNumber = '';
                    self::warning($warnings, 'employee_birth_number_invalid',
                        'Neplatné rodné číslo se nepřevede; zaměstnanec vznikne bez něj.');
                }
            }

            $monthly = self::amount($row['MesTarif'] ?? null);
            $hourly = self::amount($row['HodTarif'] ?? null);
            $monthlyGross = null;
            if ($monthly > 0.0) {
                if (abs($monthly - round($monthly)) < 0.00001 && $monthly <= 10_000_000) {
                    $monthlyGross = (int) round($monthly);
                } else {
                    self::warning($warnings, 'employee_monthly_tariff_unsupported',
                        'Měsíční tarif mimo podporovaný celočíselný rozsah se nepřevede.');
                }
            }
            if ($hourly > 0.0) {
                self::warning($warnings, 'employee_hourly_tariff_unmapped',
                    'Hodinový tarif nemá na kartě MyÚčta odpovídající smluvní pole a vyžaduje ruční doplnění.');
            }
            $insurerSource = self::text($row['Pojistovna'] ?? null);
            $insurerCode = $insurerSource === '' ? null : ($insurers[$insurerSource] ?? null);
            if ($insurerSource !== '' && $insurerCode === null) {
                self::warning($warnings, 'employee_health_insurer_unmapped',
                    'Zdrojový klíč zdravotní pojišťovny nemá v číselníku jednoznačný trojmístný kód a nepřevede se.');
            }
            if (self::hasAny($row, ['Ulice', 'PSC', 'Misto'])) {
                self::warning($warnings, 'employee_address_unmapped',
                    'Adresa zaměstnance nemá ve zdroji ověřené určení státu a vyžaduje ruční doplnění.');
            }
            if (self::hasAny($row, ['BaUcet', 'KodBanky', 'IBAN', 'Swift'])) {
                self::warning($warnings, 'employee_bank_account_unmapped',
                    'Výplatní účet zaměstnance se bez ověření vlastníkem nepřevede.');
            }

            $weeklyHours = self::hours($row['TydUvazHod'] ?? null);
            if ($weeklyHours === null) {
                self::warning($warnings, 'employee_weekly_hours_unmapped',
                    'Neplatný nebo chybějící týdenní úvazek se nahradí výchozí hodnotou k ruční kontrole.');
            }
            $employmentCode = preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/D', $key) === 1 ? $key : null;
            if ($employmentCode === null) {
                self::warning($warnings, 'employee_code_unmapped',
                    'Zdrojové osobní číslo nelze použít jako kód vztahu; MyÚčto přidělí vlastní kód.');
            }

            $email = self::email($row['Email'] ?? null);
            if (self::text($row['Email'] ?? null) !== '' && $email === null) {
                self::warning($warnings, 'employee_email_invalid', 'Neplatný e-mail zaměstnance se nepřevede.');
            }
            $phoneSource = self::text($row['Mobil'] ?? null) ?: self::text($row['Telefon'] ?? null);
            $phone = self::phone($phoneSource);
            if ($phoneSource !== '' && $phone === null) {
                self::warning($warnings, 'employee_phone_invalid', 'Neplatný telefon zaměstnance se nepřevede.');
            }
            $identityDetails = [];
            foreach (['Titul' => ['title_prefix', 64], 'ZaPrijmenim' => ['title_suffix', 64], 'MistoNarozeni' => ['birth_place', 128]] as $source => [$target, $max]) {
                $value = self::limited($row[$source] ?? null, $max);
                if (self::text($row[$source] ?? null) !== '' && $value === null) {
                    self::warning($warnings, 'employee_identity_detail_invalid', 'Příliš dlouhý údaj identity zaměstnance se nepřevede.');
                } elseif ($value !== null) {
                    $identityDetails[$target] = $value;
                }
            }
            $birthSurname = self::limited($row['RodnePrijmeni'] ?? null, 128);
            if (self::text($row['RodnePrijmeni'] ?? null) !== '' && $birthSurname === null) {
                self::warning($warnings, 'employee_birth_surname_invalid', 'Příliš dlouhé rodné příjmení se nepřevede.');
            }
            if ($birthSurname !== null && mb_strtolower($birthSurname) === mb_strtolower($lastName)) $birthSurname = null;

            $imported = [
                'source_key' => $key,
                'employment_code' => $employmentCode,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => trim($firstName . ' ' . $lastName),
                'birth_date' => $birthDate,
                'birth_number' => $birthNumber === '' ? null : $birthNumber,
                'start' => $start,
                'end' => $end,
                'weekly_hours' => $weeklyHours,
                'monthly_gross' => $monthlyGross,
                'hourly_wage' => $hourly > 0.0,
                'relation_type' => 'employment',
                'identity_details' => $identityDetails,
                'birth_surname' => $birthSurname,
                'email' => $email,
                'phone' => $phone,
                'health_insurer_code' => $insurerCode,
                'tax_declarations' => $declarations[$key] ?? [],
            ];
            $imported['source_hash'] = StereoNxImportMap::fingerprint($imported);
            $records[] = $imported;
        }

        if ($tables['MMzdy'] !== []) {
            self::warning($warnings, 'historical_payroll_skipped',
                'Zpracované mzdy nelze založit jako hotové běhy bez nového výpočtu. Zdroj navíc neobsahuje příspěvek zaměstnavatele na zdravotní pojištění a enum typu daně není doložen; úplné počáteční stavy proto nevzniknou. Účetní část zůstává v převzatém deníku.');
        }
        if ($tables['MDeti'] !== []) self::warning($warnings, 'employee_children_skipped',
            'Evidence dětí neobsahuje doložené datum začátku nároku ani vztah k dítěti a zůstává k ručnímu převzetí.');
        if ($tables['MOpNezdC'] !== []) self::warning($warnings, 'employee_tax_items_skipped',
            'Obvyklé nezdanitelné částky nemají doložený význam zdrojových typů ani začátek účinnosti a zůstávají k ručnímu převzetí.');
        if ($tables['MDovol'] !== []) self::warning($warnings, 'employee_leave_skipped',
            'Roční evidence dovolené neobsahuje datum, ke kterému zůstatek platí, a nepřevede se jako aktuální stav.');
        if ($tables['MPRVYD'] !== []) self::warning($warnings, 'employee_averages_skipped',
            'Čtvrtletní průměry neobsahují doložené přesné rozhodné období a nepřevedou se.');
        return [
            'source_ico' => $ico,
            'source_company_index' => $companyIndex,
            'counts' => [
                'employees_source' => count($tables['MZAMEST']),
                'employees_ready' => count($records),
                'employees_skipped' => $skipped,
                'historical_payroll_source' => count($tables['MMzdy']),
                'historical_payroll_skipped' => count($tables['MMzdy']),
                'children_source' => count($tables['MDeti']),
                'children_skipped' => count($tables['MDeti']),
                'tax_items_source' => count($tables['MOpNezdC']),
                'tax_items_skipped' => count($tables['MOpNezdC']),
                'leave_records_source' => count($tables['MDovol']),
                'leave_records_skipped' => count($tables['MDovol']),
                'average_records_source' => count($tables['MPRVYD']),
                'average_records_skipped' => count($tables['MPRVYD']),
            ],
            'warnings' => array_values($warnings),
            'records' => $records,
        ];
    }

    /**
     * @param array<string,mixed> $plan Výstup prepare(); volající vlastní transakci.
     * @return array{counts:array<string,int>,warnings:list<array{level:string,code:string,message:string}>}
     */
    public function write(array $plan, int $supplierId, ?int $userId): array
    {
        $records = $plan['records'] ?? null;
        $ico = $plan['source_ico'] ?? null;
        $companyIndex = $plan['source_company_index'] ?? null;
        if (!is_array($records) || !is_string($ico) || !is_int($companyIndex) || $supplierId <= 0) {
            throw new StereoNxException('employee_plan_invalid', 'Plán převodu zaměstnanců není platný.');
        }
        $counts = ['employees_created' => 0, 'employments_created' => 0, 'employees_existing' => 0,
            'employees_matched' => 0, 'employees_skipped' => 0, 'historical_payroll_skipped' => (int) (($plan['counts']['historical_payroll_skipped'] ?? 0))];
        $warnings = [];
        $prerequisite = $this->prerequisite($supplierId);
        if ($prerequisite !== null) {
            $counts['employees_skipped'] = count($records);
            self::warning($warnings, 'payroll_prerequisite_missing', $prerequisite
                . ' Zaměstnanecké karty se nepřevedly; účetní zápisy mezd zůstávají v deníku.');
            return ['counts' => $counts, 'warnings' => array_values($warnings)];
        }

        if ($records !== []) $this->components->ensureDefaults($supplierId);

        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new StereoNxException('employee_plan_invalid', 'Plán převodu zaměstnanců není platný.');
            }
            $key = (string) ($record['source_key'] ?? '');
            $hash = (string) ($record['source_hash'] ?? '');
            $employeeMap = $this->map->get($supplierId, $ico, $companyIndex, self::KIND_EMPLOYEE, $key);
            $employmentMap = $this->map->get($supplierId, $ico, $companyIndex, self::KIND_EMPLOYMENT, $key);
            if (($employeeMap === null) !== ($employmentMap === null)) {
                throw new StereoNxException('employee_map_incomplete', 'Dřívější převod zaměstnance má neúplnou mapu.');
            }
            if ($employeeMap !== null && $employmentMap !== null) {
                if ($employeeMap['source_hash'] !== $hash || $employmentMap['source_hash'] !== $hash) {
                    throw new StereoNxException('employee_source_changed', 'Zdrojová karta se od předchozího převodu změnila.');
                }
                if (!$this->mappedPairExists($supplierId, $employeeMap['target_id'], $employmentMap['target_id'])) {
                    throw new StereoNxException('employee_target_missing', 'Dříve převedená zaměstnanecká karta už v cílové firmě neexistuje.');
                }
                $takeover = self::toTakeoverRecord($record);
                $this->writePersonDetails($supplierId, $employeeMap['target_id'], $takeover->person, $record, $userId, $counts, $warnings);
                $this->writeEmploymentDetails($supplierId, $employmentMap['target_id'], $takeover->employment, $userId, $counts);
                $counts['employees_existing']++;
                continue;
            }

            $pair = $this->adoptable($supplierId, $record);
            if ($pair === null) {
                $created = $this->people->create($supplierId, [
                    'full_name' => $record['full_name'],
                    'first_name' => $record['first_name'],
                    'last_name' => $record['last_name'],
                    'birth_date' => $record['birth_date'],
                    'birth_number' => $record['birth_number'],
                    'health_insurer_code' => $record['health_insurer_code'],
                    'relation_type' => $record['relation_type'],
                    'planned_start_on' => $record['start'],
                    'weekly_hours' => $record['weekly_hours'],
                    'monthly_gross' => $record['monthly_gross'],
                    'employment_code' => $this->codeAvailable($supplierId, $record['employment_code'])
                        ? $record['employment_code'] : null,
                ], $userId, null, null);
                $employeeId = (int) ($created['id'] ?? 0);
                $employmentId = $this->latestEmploymentId($supplierId, $employeeId);
                $counts['employees_created']++;
                $counts['employments_created']++;
            } else {
                [$employeeId, $employmentId] = $pair;
                $counts['employees_matched']++;
            }
            $takeover = self::toTakeoverRecord($record);
            $this->writePersonDetails($supplierId, $employeeId, $takeover->person, $record, $userId, $counts, $warnings);
            $this->writeEmploymentDetails($supplierId, $employmentId, $takeover->employment, $userId, $counts);
            $this->applyLifecycle($supplierId, $employmentId, $takeover->employment, $userId, $counts);
            $this->map->put($supplierId, $ico, $companyIndex, self::KIND_EMPLOYEE, $key, $hash, $employeeId);
            $this->map->put($supplierId, $ico, $companyIndex, self::KIND_EMPLOYMENT, $key, $hash, $employmentId);
        }
        return ['counts' => $counts, 'warnings' => array_values($warnings)];
    }

    /** @param array<string,mixed> $record @param array<string,int> $counts @param array<string,array{level:string,code:string,message:string}> $warnings */
    private function writePersonDetails(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, array $record, ?int $userId, array &$counts, array &$warnings): void
    {
        $policy = self::takeoverPolicy();
        self::mergeCounts($counts, $this->personWriter->identity($supplierId, $employeeId, $person, $policy));
        self::mergeCounts($counts, $this->personWriter->personCard(
            $supplierId, $employeeId, $person, (string) $record['start'], $userId, $policy,
        ));
        self::mergeCounts($counts, $this->personWriter->statutoryEvidence(
            $supplierId, $employeeId, $person, date('Y-m-d'), $userId, $policy,
            static function (string $section) use (&$warnings): void {
                self::warning($warnings, 'employee_statutory_evidence_unmapped',
                    'Některý údaj zákonné evidence zaměstnance vyžaduje ruční ověření (' . $section . ').');
            },
        ));
    }

    /** @param array<string,mixed> $record */
    public static function toTakeoverRecord(array $record): PayrollTakeoverRecord
    {
        $person = new PayrollTakeoverPerson(
            key: (string) $record['source_key'],
            identity: (array) ($record['identity_details'] ?? []),
            birthSurname: is_string($record['birth_surname'] ?? null) ? $record['birth_surname'] : null,
            email: is_string($record['email'] ?? null) ? $record['email'] : null,
            phone: is_string($record['phone'] ?? null) ? $record['phone'] : null,
            healthCoverage: is_string($record['health_insurer_code'] ?? null)
                ? new PayrollTakeoverEvidencePeriod(
                    $record['health_insurer_code'], substr((string) $record['start'], 0, 7) . '-01', null, null,
                    'Převzato ze Stereo NX: zdravotní pojišťovna podle číselníku pojišťoven.',
                ) : null,
            taxDeclarations: array_map(
                static fn (array $run): PayrollTakeoverEvidencePeriod => new PayrollTakeoverEvidencePeriod(
                    $run['status'], $run['from'], $run['to'], $run['reference'],
                    'Převzato ze Stereo NX: stav prohlášení poplatníka ve zpracované mzdě.',
                ),
                (array) ($record['tax_declarations'] ?? []),
            ),
        );
        return new PayrollTakeoverRecord($person, new PayrollTakeoverEmployment(
            personalNumber: (string) $record['source_key'], relationKey: (string) $record['source_key'],
            start: (string) $record['start'], end: $record['end'],
            monthlyWages: $record['monthly_gross'] === null ? [] : [[
                'from' => (string) $record['start'], 'amount' => (float) $record['monthly_gross'], 'prorated' => false,
            ]],
            hourlyWage: (bool) ($record['hourly_wage'] ?? false),
            transferStart: substr((string) $record['start'], 0, 7),
        ));
    }

    private static function takeoverPolicy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'stereo_nx', label: 'Stereo NX', strict: false,
            addressesPerType: true, birthSurnameOnCurrentVersion: false,
            verifyPayoutAccounts: false, countPlannedTermination: false,
            ignoreEndBeforeStart: false, rewriteOwnOpenings: true,
        );
    }

    /** @param array<string,int> $target @param array<string,int> $incoming */
    private static function mergeCounts(array &$target, array $incoming): void
    {
        foreach ($incoming as $key => $value) $target[$key] = ($target[$key] ?? 0) + $value;
    }

    /** @param array<string,int> $counts */
    private function writeEmploymentDetails(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, array &$counts): void
    {
        $policy = self::takeoverPolicy();
        self::mergeCounts($counts, $this->employmentWriter->monthlyWage($supplierId, $employmentId, $employment, $userId, $policy));
        self::mergeCounts($counts, $this->employmentWriter->recurringWage(
            $supplierId, $employmentId, $employment, $userId, $policy, new PayrollTakeoverRunState(),
        ));
    }

    private function prerequisite(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) return 'Firma nemá zapnutý modul Mzdy.';
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employer_settings s JOIN payroll_offices o
               ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id AND o.is_active = 1
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === false ? 'Firma nemá nastavenou aktivní výchozí mzdovou účtárnu.' : null;
    }

    /** @param array<string,mixed> $record @return array{0:int,1:int}|null */
    private function adoptable(int $supplierId, array $record): ?array
    {
        if (!is_string($record['employment_code'] ?? null)) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.employee_id, e.relation_type, e.start_date, p.full_name
               FROM payroll_employments e JOIN payroll_employees p
                 ON p.supplier_id = e.supplier_id AND p.id = e.employee_id
              WHERE e.supplier_id = ? AND e.code = ?'
        );
        $stmt->execute([$supplierId, $record['employment_code']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        if ((string) $row['relation_type'] !== $record['relation_type']
            || (string) $row['start_date'] !== $record['start']
            || mb_strtolower(trim((string) $row['full_name'])) !== mb_strtolower((string) $record['full_name'])) return null;
        return [(int) $row['employee_id'], (int) $row['id']];
    }

    /** @param array<string,int> $counts */
    private function applyLifecycle(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, array &$counts): void
    {
        $today = date('Y-m-d');
        $row = $this->employmentWriter->employmentById($supplierId, $employmentId);
        if ($row === null) throw new StereoNxException('employee_target_missing', 'Pracovní vztah nebyl po založení nalezen.');
        if ($row['status'] === 'planned' && (string) $employment->start <= $today) {
            $this->employments->transition($supplierId, $employmentId, 'active', (int) $row['row_version'],
                (string) $employment->start, self::NOTE, $userId, null, null);
        }
        self::mergeCounts($counts, $this->employmentWriter->termination(
            $supplierId, $employmentId, $employment, $today, null, $userId, self::takeoverPolicy(),
        ));
    }

    private function mappedPairExists(int $supplierId, int $employeeId, int $employmentId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND id = ? AND employee_id = ?');
        $stmt->execute([$supplierId, $employmentId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    private function latestEmploymentId(int $supplierId, int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId, $employeeId]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) throw new StereoNxException('employee_target_missing', 'Nový pracovní vztah nebyl nalezen.');
        return $id;
    }

    private function codeAvailable(int $supplierId, mixed $code): bool
    {
        if (!is_string($code) || $code === '') return false;
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        return $stmt->fetchColumn() === false;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,string> */
    private static function insurers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = self::text($row['Pojistovna'] ?? null);
            $code = self::text($row['KodZP'] ?? null);
            if ($key === '' || preg_match('/^[0-9]{3}$/D', $code) !== 1) continue;
            if (isset($out[$key]) && $out[$key] !== $code) {
                throw new StereoNxException('employee_insurer_duplicate', 'Zdrojový číselník pojišťoven obsahuje nejednoznačný kód.');
            }
            $out[$key] = $code;
        }
        return $out;
    }

    /**
     * Stav `Prohlaseni` je doložen příručkou Stereo jako podpis prohlášení poplatníka.
     * Jiné daňové enumy z MMzdy se zde záměrně nepoužívají.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,array{level:string,code:string,message:string}> $warnings
     * @return array<string,list<array{status:string,from:string,to:?string,reference:string}>>
     */
    private static function taxDeclarations(array $rows, array &$warnings): array
    {
        $months = [];
        foreach ($rows as $row) {
            $key = self::text($row['Prac'] ?? null);
            $year = $row['Rok'] ?? null;
            $month = $row['Mesic'] ?? null;
            $signed = $row['Prohlaseni'] ?? null;
            if ($key === '' || !is_int($year) || $year < 1990 || $year > 2200
                || !is_int($month) || $month < 1 || $month > 12 || !is_bool($signed)) {
                self::warning($warnings, 'employee_tax_declaration_invalid',
                    'U některé zpracované mzdy není doložen měsíc nebo stav prohlášení poplatníka; tento stav se nepřevede.');
                continue;
            }
            $period = sprintf('%04d-%02d', $year, $month);
            if (isset($months[$key][$period]) && $months[$key][$period] !== $signed) {
                throw new StereoNxException('employee_tax_declaration_conflict', 'Zdroj obsahuje rozporný stav prohlášení poplatníka za stejný měsíc.');
            }
            $months[$key][$period] = $signed;
        }
        $out = [];
        foreach ($months as $key => $periods) {
            ksort($periods);
            $runs = [];
            $previous = null;
            foreach ($periods as $period => $signed) {
                $status = $signed ? 'signed' : 'not-signed';
                $last = array_key_last($runs);
                $contiguous = $previous !== null
                    && (new \DateTimeImmutable($previous . '-01'))->modify('+1 month')->format('Y-m') === $period;
                if ($last !== null && $runs[$last]['status'] === $status && $contiguous) {
                    $previous = $period;
                    continue;
                }
                if ($last !== null) {
                    $runs[$last]['to'] = (new \DateTimeImmutable($previous . '-01'))->format('Y-m-t');
                }
                $runs[] = [
                    'status' => $status, 'from' => $period . '-01', 'to' => null,
                    'reference' => 'stereo-nx:mmzdy:' . $period,
                ];
                $previous = $period;
            }
            $out[$key] = $runs;
        }
        return $out;
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private static function hasAny(array $row, array $fields): bool
    {
        foreach ($fields as $field) if (self::text($row[$field] ?? null) !== '') return true;
        return false;
    }

    /** @param array<string,array{level:string,code:string,message:string}> $warnings */
    private static function warning(array &$warnings, string $code, string $message): void
    {
        $warnings[$code] ??= ['level' => 'warning', 'code' => $code, 'message' => $message];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function limited(mixed $value, int $max): ?string
    {
        $text = self::text($value);
        return $text !== '' && mb_strlen($text) <= $max ? $text : null;
    }

    private static function email(mixed $value): ?string
    {
        $text = self::text($value);
        return $text !== '' && strlen($text) <= 191 && filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? $text : null;
    }

    private static function phone(string $value): ?string
    {
        return preg_match('/^\+?[0-9][0-9 ()\/.-]{4,39}$/', $value) === 1 ? $value : null;
    }

    private static function date(mixed $value, bool $optional): ?string
    {
        $text = self::text($value);
        if ($text === '') return $optional ? null : null;
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $text)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
    }

    private static function amount(mixed $value): float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : 0.0;
    }

    private static function hours(mixed $value): ?string
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || (float) $value <= 0.0 || (float) $value > 168.0) return null;
        return number_format((float) $value, 2, '.', '');
    }
}
