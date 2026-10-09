<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverFormat;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverOpeningMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPayoutAccount;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;

/**
 * Překlad záznamu osoby a vztahu z PAMICA ({@see PohodaPayrollPeople::read()}) do
 * kanonické podoby převzatých mezd ({@see PayrollTakeoverRecord}). Nic nečte ani
 * nezapisuje; co je na PAMICA specifické (odkazy na tabulky, texty dokladů pro
 * Zákonné termíny), zůstává tady.
 */
final class PohodaPayrollTakeover
{
    public const LABEL = 'PAMICA';
    private const NOTE = 'Převzato z PAMICA: ';

    /**
     * Pravidla zápisu převodu z PAMICA (viz {@see PayrollTakeoverPolicy}): chybějící
     * karta je chyba údaje, adresy po druzích, ověření výplatních účtů dnem poslední
     * výplaty, počáteční stavy zapsané dřívějším převodem z PAMICA se srovnají se
     * zdrojem, zadané jinak (ručně, z hlášení) zůstávají.
     */
    public static function policy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'pamica',
            label: self::LABEL,
            strict: true,
            addressesPerType: true,
            birthSurnameOnCurrentVersion: true,
            verifyPayoutAccounts: true,
            countPlannedTermination: true,
            ignoreEndBeforeStart: false,
            rewriteOwnOpenings: true,
        );
    }

    /** @param array<string,mixed> $record */
    public static function record(array $record): PayrollTakeoverRecord
    {
        return new PayrollTakeoverRecord(self::person($record), self::employment($record));
    }

    /** @param array<string,mixed> $record */
    private static function person(array $record): PayrollTakeoverPerson
    {
        $from = self::monthStart((string) $record['first_period'], $record['start']);
        $residence = match ($record['tax_residence']) {
            'czech-resident' => new PayrollTakeoverEvidencePeriod('czech-resident', $from, null, 'pamica:zam:rezident',
                self::NOTE . 'zaměstnanec není v PAMICA veden jako daňový nerezident.'),
            'non-resident' => new PayrollTakeoverEvidencePeriod('non-resident', $from, null, 'pamica:zam:nerezident',
                self::NOTE . 'zaměstnanec je v PAMICA veden jako daňový nerezident.', $record['tax_residence_country'] ?? null),
            default => null,
        };
        $declarations = [];
        foreach ($record['declarations'] as $run) {
            $declarations[] = new PayrollTakeoverEvidencePeriod(
                $run['status'],
                $run['from'],
                $run['to'],
                'pamica:mz-prohlas:' . $run['period'],
                self::NOTE . ($run['status'] === 'signed' ? 'podepsané' : 'nepodepsané') . ' prohlášení poplatníka od mzdy za ' . PayrollTakeoverFormat::czechPeriod($run['period']) . '.',
            );
        }
        $discounts = [];
        foreach ($record['pensioner_discounts'] as $run) {
            $claimed = $run['status'] === 'verified';
            $discounts[] = new PayrollTakeoverEvidencePeriod(
                $run['status'],
                $run['from'],
                $run['to'],
                $claimed ? 'pamica:jmhz-10490:' . $run['period'] : null,
                self::NOTE . ($claimed ? 'sleva pracujícího důchodce uplatněná v podaném hlášení' : 'sleva pracujícího důchodce se neuplatňuje')
                    . ' od mzdy za ' . PayrollTakeoverFormat::czechPeriod($run['period']) . '.',
            );
        }
        $children = [];
        foreach ($record['children'] as $child) {
            $children[] = $child + ['reference' => 'pamica:zampdet:' . $child['code']];
        }
        $openings = [];
        foreach ((array) $record['months'] as $month => $sums) {
            $openings[$month] = new PayrollTakeoverOpeningMonth(
                month: $month,
                socialBase: (int) $sums['social'],
                advanceBase: (int) $sums['advance_base'],
                advanceTax: (int) $sums['advance_tax'],
                withholdingBase: (int) $sums['withholding_base'],
                withholdingTax: (int) $sums['withholding_tax'],
                nonRefundableCredits: (int) $sums['non_refundable'],
                childCredit: (int) $sums['child'],
                taxBonus: (int) $sums['bonus'],
            );
        }
        return new PayrollTakeoverPerson(
            key: (string) $record['person_key'],
            identity: (array) $record['identity'],
            birthSurname: is_string($record['birth_surname']) ? $record['birth_surname'] : null,
            residence: is_array($record['residence']) ? $record['residence'] : null,
            mailing: is_array($record['mailing']) ? $record['mailing'] : null,
            email: is_string($record['email']) ? $record['email'] : null,
            phone: is_string($record['phone']) ? $record['phone'] : null,
            payoutAccounts: array_map(
                static fn (array $a): PayrollTakeoverPayoutAccount => new PayrollTakeoverPayoutAccount($a['account'], $a['bank_code'], $a['active']),
                $record['accounts'],
            ),
            payoutAccountsPaidOn: is_string($record['accounts_paid_on']) ? $record['accounts_paid_on'] : null,
            taxResidence: $residence,
            healthCoverage: is_string($record['insurer_code'])
                ? new PayrollTakeoverEvidencePeriod($record['insurer_code'], $from, null, null,
                    self::NOTE . 'zdravotní pojišťovna ' . $record['insurer_code'] . ' z karty zaměstnance.')
                : null,
            socialJurisdiction: $record['foreign_legislation'] === true
                ? new PayrollTakeoverEvidencePeriod('foreign', $from)
                : new PayrollTakeoverEvidencePeriod('czech', $from, null, null, self::NOTE . 'zaměstnanec nepodléhá v PAMICA cizím právním předpisům.'),
            taxDeclarations: $declarations,
            socialDiscountClaims: $discounts,
            children: $children,
            childrenWithoutCredit: (int) $record['children_without_credit'],
            firstSignedPeriod: is_string($record['first_signed_period']) ? $record['first_signed_period'] : null,
            openingMonths: $openings,
        );
    }

    /** @param array<string,mixed> $record */
    private static function employment(array $record): PayrollTakeoverEmployment
    {
        return new PayrollTakeoverEmployment(
            personalNumber: (string) $record['personal_number'],
            relationKey: (string) $record['relation_key'],
            start: is_string($record['start']) ? $record['start'] : null,
            end: is_string($record['end']) ? $record['end'] : null,
            monthlyWages: $record['monthly_wages'],
            hourlyWage: $record['hourly_wage'] === true,
            regularBenefits: array_map('strval', array_values((array) $record['regular_benefits'])),
            averages: $record['averages'],
            absences: $record['absences'],
            absencesWithoutDates: (int) ($record['absences_without_dates'] ?? 0),
            leave: is_array($record['leave'] ?? null) ? $record['leave'] : null,
            leaveShared: ($record['leave_shared'] ?? false) === true,
            transferStart: (string) $record['transfer_start'],
            workplace: is_array($record['workplace']) ? $record['workplace'] : null,
            czIsco: is_string($record['cz_isco']) ? $record['cz_isco'] : null,
            oic: is_string($record['oic']) ? $record['oic'] : null,
            idPpv: is_string($record['id_ppv']) ? $record['id_ppv'] : null,
            checklistNotes: self::evidenceNotes($record),
        );
    }

    /**
     * Poznámka k položce Zákonných termínů podle dokladu z PAMICA; položka bez dokladu chybí.
     *
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    private static function evidenceNotes(array $record): array
    {
        $evidence = (array) $record['evidence'];
        $notes = [];
        if (is_string($record['start'])) {
            $notes['employment_contract'] = self::NOTE . 'vztah vedený v předchozím mzdovém systému, nástup ' . PayrollTakeoverFormat::czechDate($record['start']) . '.';
        }
        if (is_string($record['first_signed_period'])) {
            $notes['tax_declaration'] = self::NOTE . 'podepsané prohlášení poplatníka, mzda za ' . PayrollTakeoverFormat::czechPeriod($record['first_signed_period']) . '.';
        }
        // Vztah vzniklý před prvním převáděným měsícem: přihlášky podával předchozí systém,
        // PAMICA ale oznámení drží jen za poslední roky.
        $beforeTransfer = is_string($record['start']) && $record['start'] < $record['transfer_start'] . '-01';
        // Stav oznámení 2 mají v PAMICA téměř všechna oznámení; jiný stav se bere jako nedokončené.
        $healthStart = $evidence['health_start'] ?? null;
        if (is_array($healthStart) && $healthStart['state'] === '2') {
            $notes['health_insurance_registration'] = self::NOTE . 'oznámení zdravotní pojišťovně o nástupu k ' . PayrollTakeoverFormat::czechDate((string) $healthStart['date'])
                . self::suffix('zpracované', $healthStart['submitted']) . '.';
        } elseif ($beforeTransfer && is_string($record['insurer_code'])) {
            $notes['health_insurance_registration'] = self::NOTE . 'vztah vznikl před převodem (nástup ' . PayrollTakeoverFormat::czechDate((string) $record['start'])
                . '), pojištěn u ZP ' . $record['insurer_code'] . ', oznámení proběhlo v předchozím systému.';
        }
        $socialStart = $evidence['social_start'] ?? null;
        if (is_array($socialStart)) {
            $notes['social_jmhz_registration'] = self::NOTE . ($socialStart['source'] === 'RegZAM' ? 'registrace zaměstnance u ČSSZ (JMHZ)' : 'přihláška u ČSSZ')
                . self::suffix('odeslaná', $socialStart['submitted']) . self::suffix('přijatá', $socialStart['accepted']) . '.';
        } elseif ($beforeTransfer && $record['social_participation'] === true) {
            $notes['social_jmhz_registration'] = self::NOTE . 'vztah vznikl před převodem (nástup ' . PayrollTakeoverFormat::czechDate((string) $record['start'])
                . '), přihláška proběhla v předchozím systému.';
        }
        if (is_string($record['end']) && $record['ended_by_code'] === true) {
            $notes['termination_document'] = self::NOTE . 'skončení vztahu k ' . PayrollTakeoverFormat::czechDate($record['end']) . ' vedené v předchozím mzdovém systému.';
        }
        $healthEnd = $evidence['health_end'] ?? null;
        if (is_array($healthEnd) && $healthEnd['state'] === '2') {
            $notes['health_insurance_deregistration'] = self::NOTE . 'oznámení zdravotní pojišťovně o skončení k ' . PayrollTakeoverFormat::czechDate((string) $healthEnd['date'])
                . self::suffix('zpracované', $healthEnd['submitted']) . '.';
        }
        $socialEnd = $evidence['social_end'] ?? null;
        if (is_array($socialEnd)) {
            $notes['social_jmhz_deregistration'] = self::NOTE . ($socialEnd['source'] === 'RegZAM' ? 'odhláška zaměstnance u ČSSZ (JMHZ)' : 'odhláška u ČSSZ')
                . self::suffix('odeslaná', $socialEnd['submitted']) . self::suffix('přijatá', $socialEnd['accepted']) . '.';
        }
        $eldp = $evidence['eldp'] ?? null;
        if (is_array($eldp)) {
            $notes['eldp_submission'] = self::NOTE . 'evidenční list důchodového pojištění za rok ' . $eldp['state'] . self::suffix('odeslaný', $eldp['submitted']) . '.';
        }
        return $notes;
    }

    /** První den měsíce první mzdy, nebo nástupu, je-li dřív. */
    private static function monthStart(string $period, mixed $start): string
    {
        $first = $period . '-01';
        if (is_string($start) && substr($start, 0, 7) . '-01' < $first) {
            $first = substr($start, 0, 7) . '-01';
        }
        return $first;
    }

    private static function suffix(string $label, mixed $date): string
    {
        return is_string($date) && $date !== '' ? ", {$label} " . PayrollTakeoverFormat::czechDate($date) : '';
    }
}
