<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportPlanner;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;

/**
 * Podání ČSSZ z PAMICA v převodu mezd: měsíční hlášení JMHZ a registrace zaměstnanců
 * ({@see PohodaPayrollJmhzReports}).
 *
 * Zpracované mzdy (`MZ`) a karty (`ZAM`, `ZAMpomer`) zůstávají pro převod jediným zdrojem
 * převzatých mezd; z podání se NIC nepřepisuje. Doplňuje se jen to, co v MyÚčtu po zápisu
 * z karet chybí, a to stejnými společnými zapisovači, které doplňují jen prázdné údaje:
 *
 * | z podání                                         | kam                                             |
 * |--------------------------------------------------|-------------------------------------------------|
 * | pracoviště, APZ, funkční požitky, dočasné přidělení, fond a týdenní doba, druh činnosti (hlášení) | podmínky vztahu, jen prázdná pole ({@see PayrollTakeoverEmploymentWriter::fillTerms()}) |
 * | CZ-ISCO, druh činnosti, upřesnění vztahu, obec a místo výkonu práce (registrace) | podmínky vztahu, jen prázdná pole |
 * | OIČ a ID PPV (hlášení)                          | identifikátory vztahu, jen s potvrzením původu, jen chybějící |
 * | průměrný hodinový výdělek 10345 (hlášení)       | schválený průměr čtvrtletí, které nemá průměr   |
 * | vyživované děti (hlášení)                        | vyživované osoby, jen osobě bez vyživovaných osob |
 * | celé podání (hlavička, GUIDy, stav, všechny atributy) | historie podání předchozím programem ({@see JmhzExternalSubmissionStore}) |
 *
 * Platí vždy poslední ODESLANÉ podání za měsíc (opravné po řádném); neodeslané hlášení se
 * jen uloží do historie a protokol na něj upozorní. Dny důchodového pojištění z ELDP se
 * s dny ze zpracovaných mezd jen porovnají - převzaté mzdy je nesou samy.
 */
final class PohodaPayrollJmhzWriter
{
    private const SAVEPOINT = 'pohoda_payroll_jmhz';
    private const MESSAGE_LIMIT = 30;
    private const ENVIRONMENT = 'production';
    /** Druh věty registrace podle `RegZAMitems.RelTyp` ({@see PohodaPayrollPeople}). */
    private const REGISTRATION_TYPES = ['1' => 'start', '2' => 'end', '3' => 'existing'];
    /** Stavy vztahu, u kterých jdou podmínky opravit (stejné jako u importu hlášení z XML). */
    private const OPEN_STATUSES = ['planned', 'preregistered', 'active', 'suspended'];

    private int $messages = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly JmhzExternalSubmissionStore $history,
        private readonly PayrollTakeoverEmploymentWriter $employments,
        private readonly PayrollTakeoverPersonWriter $people,
        private readonly PayrollAnnualSettlementRepository $annualSettlements,
    ) {}

    /**
     * Žádost o roční zúčtování daní za předchozí rok tak, jak ji PAMICA vykázala v podaném
     * hlášení (10319, lednové a únorové hlášení roku převodu). Bez ní se hlášení za leden
     * a únor nesestaví (`jmhz_annual_request_source_missing`) a zúčtování za převzatý rok
     * nemá z čeho vyjít. Zapisuje se jen tam, kde žádost v evidenci chybí; ostatní odpovědi
     * žádosti (předchozí zaměstnavatelé, doklady) zůstanou nevyplněné k doplnění účetní.
     * Den žádosti hlášení nenese, za doklad se bere den podání hlášení, které ji vykázalo.
     *
     * @param array<string,mixed> $context {@see self::read()}
     * @param array<string,array{employee_id:int,employment_id:int}> $matched
     */
    private function annualRequests(int $supplierId, ?int $userId, array $context, array $matched, int $year, ImportProtocol $protocol, string $step): void
    {
        /** @var array<int,array{requested:bool,on:string,period:string}> $byEmployee */
        $byEmployee = [];
        foreach ($context['reports'] as $report) {
            if ($report['sent'] !== true || $report['type'] === 'S' || $report['year'] !== $year) {
                continue;
            }
            foreach ($report['forms'] as $form) {
                $pair = $matched[$form['relation_key']] ?? null;
                $value = strtoupper(trim((string) self::attribute($form['attributes'], 10319)));
                if ($pair === null || !in_array($value, ['A', 'N', 'TRUE', 'FALSE', '1', '0'], true)) {
                    continue;
                }
                // Platí první podání, které žádost vykázalo: hlášení jsou seřazená podle
                // období a odeslání, a nejdřívější den podání je doklad, že žádost už byla.
                $byEmployee[(int) $pair['employee_id']] ??= [
                    'requested' => in_array($value, ['A', 'TRUE', '1'], true),
                    'on' => substr((string) ($report['submitted_at'] ?? $report['filled_at']), 0, 10),
                    'period' => (string) $report['period'],
                ];
            }
        }
        foreach ($byEmployee as $employeeId => $request) {
            if ($this->annualSettlements->findRequest($supplierId, $employeeId, $year - 1) !== null) {
                $protocol->count($step, 'annual_requests_existing');
                continue;
            }
            $this->annualSettlements->saveRequest($supplierId, $employeeId, $year - 1, [
                'request_status' => $request['requested'] ? 'requested' : 'not_requested',
                'requested_on' => $request['requested'] ? $request['on'] : null,
                'request_evidence_reference' => $request['requested'] ? 'pamica:jmhz-10319:' . $request['period'] : null,
                'prior_employers' => 'unknown', 'prior_documents_received_on' => null,
                'filing_obligation' => 'unknown', 'filing_obligation_reason' => null,
                'annual_claims' => 'unknown', 'annual_claims_note' => null,
                'other_household_caregiver_status' => 'unknown', 'other_household_caregivers' => [],
                'note' => 'Převzato z PAMICA: ' . ($request['requested'] ? 'žádost o roční zúčtování' : 'bez žádosti o roční zúčtování')
                    . ' za rok ' . ($year - 1) . ' podle podaného hlášení za ' . $request['period'] . '.',
            ], null, $userId);
            $protocol->count($step, $request['requested'] ? 'annual_requests_requested' : 'annual_requests_not_requested');
        }
    }

    public static function policy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'pamica',
            label: 'PAMICA (podaná hlášení)',
            strict: true,
            countPlannedTermination: false,
        );
    }

    /**
     * Podání z exportu: měsíční hlášení roku, registrace a z nich platné formuláře
     * a profily vztahů.
     *
     * @return array{reports:list<array<string,mixed>>,registrations:list<array<string,mixed>>,
     *   effective:array<string,array<string,array<string,mixed>>>,profiles:array<string,array<int,string>>}
     */
    public static function read(string $file, int $year): array
    {
        $reports = PohodaPayrollJmhzReports::read($file, $year);
        $registrations = PohodaPayrollJmhzReports::registrations($file);

        return [
            'reports' => $reports,
            'registrations' => $registrations,
            'effective' => PohodaPayrollJmhzReports::effective($reports),
            'profiles' => PohodaPayrollJmhzReports::registrationProfiles($registrations),
        ];
    }

    /**
     * Podmínky vztahů z hlášení za měsíc, který převod právě zapsal - dokud je jeho verze
     * podmínek ta poslední (stejný důvod jako u pracoviště z karet,
     * {@see PohodaPayrollPeopleWriter::writeWorkplaces()}).
     *
     * @param list<array<string,mixed>> $records {@see PohodaPayrollPeople::read()}
     * @param array<string,mixed> $context {@see self::read()}
     */
    public function monthTerms(int $supplierId, ?int $userId, array $records, array $context, string $period, ImportProtocol $protocol, string $step): void
    {
        foreach ($records as $record) {
            $relationKey = (string) $record['relation_key'];
            $entry = $context['effective'][$relationKey][$period] ?? null;
            $profile = $context['profiles'][$relationKey] ?? [];
            if ($entry === null && $profile === []) {
                continue;
            }
            $employment = $this->employmentByCode($supplierId, (string) $record['personal_number']);
            if ($employment === null) {
                continue;
            }
            $this->terms($supplierId, (int) $employment['id'], (string) $record['personal_number'], $entry['form'] ?? null, $profile, $userId, $protocol, $step);
        }
    }

    /**
     * Historie podání a doplnění z podání po zápisu osob a vztahů z karet.
     *
     * @param list<array<string,mixed>> $records {@see PohodaPayrollPeople::read()}
     * @param array<string,mixed> $context {@see self::read()}
     * @param array<string,array{employee_id:int,employment_id:int}> $matched vztah v PAMICA => vztah v MyÚčtu
     */
    public function write(
        int $supplierId,
        ?int $userId,
        string $file,
        int $year,
        array $records,
        array $context,
        array $matched,
        bool $confirmIdentifiers,
        ImportProtocol $protocol,
        string $step,
    ): void {
        $this->messages = 0;
        $this->storeHistory($supplierId, $userId, $context, $matched, $protocol, $step);
        $this->warnUnsent($context['reports'], $protocol, $step);
        $this->annualRequests($supplierId, $userId, $context, $matched, $year, $protocol, $step);

        $policy = self::policy();
        $state = new PayrollTakeoverRunState();
        $byPerson = [];
        $unconfirmed = 0;
        foreach ($records as $record) {
            $relationKey = (string) $record['relation_key'];
            $number = (string) $record['personal_number'];
            $pair = $matched[$relationKey] ?? null;
            $months = $context['effective'][$relationKey] ?? [];
            if ($pair === null || ($months === [] && ($context['profiles'][$relationKey] ?? []) === [])) {
                continue;
            }
            $employmentId = (int) $pair['employment_id'];
            $latest = $months === [] ? null : end($months)['form'];
            // Poslední verze podmínek (převod ji mohl po měsících založit novější).
            $this->terms($supplierId, $employmentId, $number, $latest, $context['profiles'][$relationKey] ?? [], $userId, $protocol, $step);
            if ($months === []) {
                continue;
            }
            $relation = new PayrollTakeoverEmployment(
                personalNumber: $number,
                relationKey: $relationKey,
                averages: self::averages($months),
                oic: self::latest($months, 'personIdentifier'),
                idPpv: self::latest($months, 'employmentIdentifier'),
            );
            $this->part($protocol, $step, $number, 'Průměrný výdělek z hlášení',
                fn (): array => self::prefixed('jmhz_', $this->employments->averageEarnings($supplierId, $employmentId, $relation, $userId, $policy)));
            if ($relation->oic !== null || $relation->idPpv !== null) {
                if ($confirmIdentifiers) {
                    $this->part($protocol, $step, $number, 'OIČ a ID PPV z hlášení',
                        fn (): array => self::prefixed('jmhz_', $this->employments->identifiers(
                            $supplierId, (int) $pair['employee_id'], $employmentId, $relation, $userId, $policy, $state,
                        )));
                } else {
                    $unconfirmed++;
                }
            }
            $byPerson[(int) $pair['employee_id']]['record'] ??= $record;
            foreach ($months as $period => $entry) {
                $byPerson[(int) $pair['employee_id']]['months'][$period][] = $entry['form'];
            }
        }
        foreach ($byPerson as $employeeId => $person) {
            $children = self::children($person['months']);
            $record = $person['record'];
            if ($children['children'] !== []) {
                $takeover = new PayrollTakeoverPerson(
                    key: (string) $record['person_key'],
                    children: $children['children'],
                    firstSignedPeriod: is_string($record['first_signed_period'] ?? null) ? $record['first_signed_period'] : $children['first_signed'],
                );
                $this->part($protocol, $step, (string) $record['personal_number'], 'Děti z hlášení',
                    fn (): array => self::prefixed('jmhz_', $this->people->children($supplierId, $employeeId, $takeover, $userId, $policy)));
            }
            if (self::otherCaregiver($person['months']) === false) {
                $this->part($protocol, $step, (string) $record['personal_number'], 'Jiná osoba vyživující děti (10453)',
                    fn (): array => self::prefixed('jmhz_', $this->people->otherCaregiverNone($supplierId, $employeeId, $userId)));
            }
        }
        if ($unconfirmed > 0) {
            $protocol->count($step, 'jmhz_identifiers_unconfirmed', $unconfirmed);
            $protocol->warn($step, 'jmhz_identifiers_unconfirmed', "OIČ nebo ID PPV z odeslaných hlášení má {$unconfirmed} vztahů. "
                . 'Bez potvrzení, že čísla pocházejí z protokolů ČSSZ, je převod nepřevzal. Potvrďte to v průvodci a převod zopakujte.');
        }
        if ($state->invalidOic !== []) {
            $protocol->warn($step, 'jmhz_oic_invalid', sprintf(
                'OIČ z hlášení nesedí na kontrolní číslici u %d osob (osobní čísla %s). Porovnejte je s protokolem ČSSZ.',
                count($state->invalidOic),
                implode(', ', array_slice($state->invalidOic, 0, 30)),
            ));
        }
        $this->compareInsuranceDays($file, $year, $records, $context['effective'], $matched, $protocol, $step);
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,array{employee_id:int,employment_id:int}> $matched
     */
    private function storeHistory(int $supplierId, ?int $userId, array $context, array $matched, ImportProtocol $protocol, string $step): void
    {
        foreach ($context['reports'] as $report) {
            $forms = [];
            foreach ($report['forms'] as $index => $form) {
                $pair = $matched[$form['relation_key']] ?? null;
                $forms[] = [
                    'position' => $index + 1,
                    'form_guid' => $form['form']?->formGuid ?? self::guidAttribute($form['attributes'], 10012),
                    'form_type' => $form['form']?->formType ?? self::attribute($form['attributes'], 10016),
                    'source_relation_ref' => $form['relation_key'] !== '' ? $form['relation_key'] : null,
                    'employee_id' => $pair === null ? null : (int) $pair['employee_id'],
                    'employment_id' => $pair === null ? null : (int) $pair['employment_id'],
                    'payload' => ['item' => $form['item'], 'attributes' => $form['attributes'], 'error' => $form['error']],
                ];
                if ($form['error'] !== null) {
                    $protocol->count($step, 'jmhz_forms_unreadable');
                    $this->warn($protocol, $step, 'jmhz_form_unreadable', sprintf(
                        'Hlášení za %s, formulář %d: %s Obsah je uložený v historii podání, údaje z něj se nepřevzaly.',
                        self::czechPeriod($report['period']), $index + 1, $form['error'],
                    ));
                }
            }
            $result = $this->history->store($supplierId, self::ENVIRONMENT, JmhzExternalSubmissionStore::SOURCE_PAMICA, [
                'source_key' => $report['source_key'],
                'document_kind' => 'monthly',
                'period' => $report['period'],
                'submission_type' => $report['type'],
                'submission_guid' => $report['guid'],
                'corrected_source_key' => $report['corrected_source_key'],
                'status' => $report['sent'] ? JmhzExternalSubmissionStore::STATUS_SENT : JmhzExternalSubmissionStore::STATUS_NOT_SENT,
                'filled_at' => $report['filled_at'],
                'submitted_at' => $report['sent'] ? $report['submitted_at'] : null,
                'accepted_at' => $report['accepted_at'],
                'program' => PohodaPayrollJmhzReports::PROGRAM,
                'file_name' => null,
                'payload' => ['program' => PohodaPayrollJmhzReports::PROGRAM, 'header' => $report['header'],
                    'summary' => $report['summary'], 'delivery' => $report['delivery']],
            ], $forms, $userId);
            $protocol->count($step, 'jmhz_submissions');
            $protocol->count($step, 'jmhz_submissions_' . $result['status']);
            $protocol->count($step, 'jmhz_forms', count($forms));
        }
        foreach ($context['registrations'] as $registration) {
            $forms = [];
            foreach ($registration['items'] as $index => $item) {
                $pair = $matched[$item['relation_key']] ?? null;
                $forms[] = [
                    'position' => $index + 1,
                    'form_guid' => null,
                    'form_type' => self::REGISTRATION_TYPES[$item['type']] ?? ($item['type'] !== '' ? substr($item['type'], 0, 16) : null),
                    'source_relation_ref' => $item['relation_key'] !== '' ? $item['relation_key'] : null,
                    'employee_id' => $pair === null ? null : (int) $pair['employee_id'],
                    'employment_id' => $pair === null ? null : (int) $pair['employment_id'],
                    'payload' => ['item' => $item['item'], 'attributes' => $item['attributes']],
                ];
            }
            $result = $this->history->store($supplierId, self::ENVIRONMENT, JmhzExternalSubmissionStore::SOURCE_PAMICA, [
                'source_key' => $registration['source_key'],
                'document_kind' => 'registration',
                'period' => null,
                'submission_type' => null,
                'submission_guid' => null,
                'corrected_source_key' => null,
                'status' => $registration['sent'] ? JmhzExternalSubmissionStore::STATUS_SENT : JmhzExternalSubmissionStore::STATUS_NOT_SENT,
                'filled_at' => $registration['filled_at'],
                'submitted_at' => $registration['sent'] ? $registration['submitted_at'] : null,
                'accepted_at' => $registration['accepted_at'],
                'program' => PohodaPayrollJmhzReports::PROGRAM,
                'file_name' => null,
                'payload' => ['program' => PohodaPayrollJmhzReports::PROGRAM, 'kind' => $registration['kind'], 'header' => $registration['header']],
            ], $forms, $userId);
            $protocol->count($step, 'jmhz_registrations');
            $protocol->count($step, 'jmhz_registrations_' . $result['status']);
        }
    }

    /** @param list<array<string,mixed>> $reports */
    private function warnUnsent(array $reports, ImportProtocol $protocol, string $step): void
    {
        $sent = [];
        foreach ($reports as $report) {
            if ($report['sent']) {
                $sent[$report['period']] = true;
            }
        }
        $warned = [];
        foreach ($reports as $report) {
            if ($report['sent'] || isset($sent[$report['period']]) || isset($warned[$report['period']])) {
                continue;
            }
            $warned[$report['period']] = true;
            $protocol->count($step, 'jmhz_months_not_sent');
            $protocol->warn($step, 'jmhz_month_not_sent', sprintf(
                'Hlášení za %s nebylo odesláno: PAMICA ho má připravené (%d formulářů), ale ČSSZ ho nedostala. '
                . 'Je uložené v historii podání jako neodeslané; hlášení za tento měsíc podejte.',
                self::czechPeriod($report['period']),
                count($report['forms']),
            ), ['period' => $report['period']]);
        }
    }

    /**
     * @param array<int,string> $profile atributy registrace vztahu
     */
    private function terms(int $supplierId, int $employmentId, string $number, ?JmhzReportForm $form, array $profile, ?int $userId, ImportProtocol $protocol, string $step): void
    {
        $desired = [];
        if ($form !== null) {
            $ignored = [];
            $desired = JmhzReportPlanner::desiredTerms($form, $ignored);
            if (is_string($form->activityCode) && preg_match('/^[0-9A-Z]{1,2}$/D', $form->activityCode) === 1) {
                $desired['activity_code'] = $form->activityCode;
            }
        }
        foreach (self::registrationTerms($profile) as $field => $value) {
            $desired[$field] ??= $value;
        }
        if ($desired === []) {
            return;
        }
        // Podmínky ukončeného vztahu nejde opravit ani na kartě; z hlášení se pak
        // nezapisují a jen se spočítají (údaj je v historii podání).
        $status = $this->employments->employmentById($supplierId, $employmentId)['status'] ?? null;
        if (!in_array($status, self::OPEN_STATUSES, true)) {
            $protocol->count($step, 'jmhz_terms_closed_employment');
            return;
        }
        $this->part($protocol, $step, $number, 'Podmínky vztahu z podání',
            fn (): array => $this->employments->fillTerms($supplierId, $employmentId, $desired, $userId, self::policy()));
    }

    /**
     * Pole podmínek vztahu z poslední registrace (REGZEC): CZ-ISCO (10234), druh činnosti
     * (10239), upřesnění vztahu (10502), obec a kód obce místa výkonu práce (10528, 10529)
     * a sjednané místo výkonu práce (10527).
     *
     * @param array<int,string> $profile
     * @return array<string,string>
     */
    public static function registrationTerms(array $profile): array
    {
        $out = [];
        $value = static fn (int $id): string => trim($profile[$id] ?? '');
        if (preg_match('/^\d{4,5}$/D', $value(10234)) === 1) {
            $out['cz_isco_code'] = $value(10234);
        }
        if (preg_match('/^[0-9A-Z]{1,2}$/D', $value(10239)) === 1) {
            $out['activity_code'] = $value(10239);
        }
        if (preg_match('/^[0-9A-Z]$/D', $value(10502)) === 1) {
            $out['jmhz_relationship_detail_code'] = $value(10502);
        }
        if (preg_match('/^\d{6}$/D', $value(10529)) === 1 && $value(10528) !== '') {
            $out['work_place'] = mb_substr($value(10528), 0, 255);
            $out['jmhz_workplace_municipality_code'] = $value(10529);
            $out['jmhz_workplace_country_code'] = 'CZ';
        }
        if ($value(10527) !== '') {
            $out['regular_workplace'] = mb_substr($value(10527), 0, 255);
        }

        return $out;
    }

    /**
     * Průměr každého čtvrtletí z nejpozdějšího odeslaného měsíce v něm (10345 platí
     * pro čtvrtletí měsíce hlášení, jako u importu hlášení z XML).
     *
     * @param array<string,array{form:JmhzReportForm}> $months
     * @return list<array{year:int,quarter:int,hourly:float,from:string,to:string,gross:float,worked:float,days:float}>
     */
    public static function averages(array $months): array
    {
        $byQuarter = [];
        foreach ($months as $period => $entry) {
            $milli = $entry['form']->averageHourlyMilli;
            if ($milli === null || $milli <= 0) {
                continue;
            }
            $year = (int) substr($period, 0, 4);
            $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
            $byQuarter[$year * 10 + $quarter] = [
                'year' => $year, 'quarter' => $quarter, 'hourly' => $milli / 1000.0,
                'from' => '', 'to' => '', 'gross' => 0.0, 'worked' => 0.0, 'days' => 0.0,
            ];
        }
        ksort($byQuarter);

        return array_values($byQuarter);
    }

    /**
     * Odpověď na „tytéž děti vyživuje i jiná osoba" (10453) z posledního formuláře osoby
     * se zvýhodněním na děti; `null` = žádný takový formulář odpověď nenese.
     *
     * @param array<string,list<JmhzReportForm>> $months období => formuláře osoby
     */
    public static function otherCaregiver(array $months): ?bool
    {
        ksort($months, SORT_STRING);
        $answer = null;
        foreach ($months as $forms) {
            foreach ($forms as $form) {
                if (!$form->hasSummary || $form->declarationSigned !== true || ($form->childCredit['children'] ?? []) === []) {
                    continue;
                }
                $flag = $form->childCredit['other_caregiver'] ?? null;
                if (is_bool($flag)) {
                    $answer = $flag;
                }
            }
        }

        return $answer;
    }

    /**
     * Vyživované děti z formulářů se souhrnnými daty osoby (podepsané prohlášení). Nárok
     * běží od prvního měsíce, ve kterém dítě hlášení uvádí; dítě, které v posledním měsíci
     * osoby chybí, má nárok do konce měsíce, kdy ho hlášení uvedlo naposledy. Dítě
     * s pořadím „N" nárok nemá a nepřevádí se.
     *
     * @param array<string,list<JmhzReportForm>> $months období => formuláře osoby
     * @return array{children:list<array{order:int,code:string,reference:string,given_name:?string,family_name:?string,birth_number:?string,from:?string,to:?string}>,first_signed:?string}
     */
    public static function children(array $months): array
    {
        ksort($months, SORT_STRING);
        $seen = [];
        $firstSigned = null;
        $last = null;
        foreach ($months as $period => $forms) {
            foreach ($forms as $form) {
                if (!$form->hasSummary || $form->declarationSigned !== true) {
                    continue;
                }
                $firstSigned ??= $period;
                $last = $period;
                foreach ($form->childCredit['children'] ?? [] as $child) {
                    $key = $child['birth_number'] ?? mb_strtolower($child['family_name'] . '|' . $child['given_name'] . '|' . ($child['birth_date'] ?? ''));
                    $seen[$key] ??= ['child' => $child, 'from' => $period, 'to' => $period];
                    $seen[$key]['child'] = $child;
                    $seen[$key]['to'] = $period;
                }
            }
        }
        $out = [];
        foreach ($seen as $entry) {
            $child = $entry['child'];
            if (!in_array($child['order'], ['1', '2', '3'], true)) {
                continue;
            }
            $out[] = [
                'order' => (int) $child['order'],
                'code' => 'jmhz',
                'reference' => 'pamica:mh:' . str_replace('-', '', $entry['from']),
                'given_name' => $child['given_name'],
                'family_name' => $child['family_name'],
                'birth_number' => $child['birth_number'],
                'from' => $entry['from'] . '-01',
                'to' => $entry['to'] === $last ? null : (new \DateTimeImmutable($entry['to'] . '-01'))->format('Y-m-t'),
            ];
        }

        return ['children' => $out, 'first_signed' => $firstSigned];
    }

    /**
     * Dny důchodového pojištění a vyloučené doby z ELDP hlášení proti dnům, které převzaté
     * mzdy odvodily ze zpracované mzdy. Nic se nemění - rozdíl se jen ohlásí, protože
     * evidenční list za rok přechodu vzniká z převzatých mezd.
     *
     * @param list<array<string,mixed>> $records
     * @param array<string,array<string,array<string,mixed>>> $effective
     * @param array<string,array{employee_id:int,employment_id:int}> $matched
     */
    private function compareInsuranceDays(string $file, int $year, array $records, array $effective, array $matched, ImportProtocol $protocol, string $step): void
    {
        $numbers = [];
        foreach ($records as $record) {
            $numbers[(string) $record['relation_key']] = (string) $record['personal_number'];
        }
        $differ = [];
        $compared = 0;
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            if ((int) PohodaXml::text($mz, 'Rok') !== $year) {
                continue;
            }
            $relationKey = PohodaXml::text($mz, 'RefPomer');
            $period = sprintf('%04d-%02d', $year, (int) PohodaXml::text($mz, 'RelMes'));
            $form = $effective[$relationKey][$period]['form'] ?? null;
            if (!$form instanceof JmhzReportForm || $form->eldp === null || !isset($matched[$relationKey])) {
                continue;
            }
            try {
                $facts = PayrollMigrationTakeoverFacts::fromPohodaMz($mz);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $compared++;
            if ($facts->insuranceDays !== min(31, $form->eldp['insurance_days'])
                || $facts->excludedDays !== min(31, $form->eldp['excluded_days'])
            ) {
                $differ[$numbers[$relationKey] ?? $relationKey][] = $period;
            }
        }
        $protocol->count($step, 'jmhz_eldp_days_compared', $compared);
        if ($differ === []) {
            return;
        }
        $protocol->count($step, 'jmhz_eldp_days_differ', array_sum(array_map('count', $differ)));
        $list = [];
        foreach (array_slice($differ, 0, self::MESSAGE_LIMIT, true) as $number => $periods) {
            $list[] = $number . ' (' . implode(', ', array_map(self::czechPeriod(...), $periods)) . ')';
        }
        $protocol->warn($step, 'jmhz_eldp_days_differ', sprintf(
            'Dny důchodového pojištění nebo vyloučené doby ve zpracované mzdě nesedí na ELDP v odeslaném hlášení u %d měsíců: %s. '
            . 'Převzaté mzdy nesou dny ze zpracované mzdy; evidenční list za rok přechodu před podáním porovnejte s hlášením.',
            array_sum(array_map('count', $differ)),
            implode('; ', $list),
        ));
    }

    /**
     * @param array<string,array{form:JmhzReportForm}> $months
     */
    private static function latest(array $months, string $property): ?string
    {
        foreach (array_reverse($months) as $entry) {
            $value = $entry['form']->{$property};
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param list<array{id:int,value:string}> $attributes */
    private static function attribute(array $attributes, int $id): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute['id'] === $id && $attribute['value'] !== '') {
                return mb_substr($attribute['value'], 0, 16);
            }
        }

        return null;
    }

    /** @param list<array{id:int,value:string}> $attributes */
    private static function guidAttribute(array $attributes, int $id): ?string
    {
        $value = strtoupper((string) self::attribute($attributes, $id));

        return preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/D', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    private static function prefixed(string $prefix, array $counts): array
    {
        $out = [];
        foreach ($counts as $key => $value) {
            $out[$prefix . $key] = $value;
        }

        return $out;
    }

    private static function czechPeriod(string $period): string
    {
        return substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /** @return array<string,mixed>|null */
    private function employmentByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id FROM payroll_employments WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param callable():array<string,int> $work */
    private function part(ImportProtocol $protocol, string $step, string $number, string $label, callable $work): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $counts = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (\Exception $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $protocol->count($step, 'jmhz_failed');
            $this->warn($protocol, $step, 'jmhz_data_failed', "Osobní číslo {$number}: {$label}: {$e->getMessage()}");
            return;
        }
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $protocol->count($step, $key, $value);
            }
        }
    }

    private function warn(ImportProtocol $protocol, string $step, string $code, string $message): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $protocol->warn($step, $code, $message);
        } elseif ($this->messages === self::MESSAGE_LIMIT + 1) {
            $protocol->warn($step, 'jmhz_messages_truncated', 'Další upozornění k podáním z PAMICA protokol nevypisuje, jejich počet je v počtech kroku.');
        }
    }
}
