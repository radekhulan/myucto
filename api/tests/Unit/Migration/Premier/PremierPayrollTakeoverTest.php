<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Geo\CountryNameMatcher;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Service\Migration\Premier\PremierPayrollTakeover;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Překlad vztahu z PREMIER do kanonické podoby převzatých mezd: odkazy a poznámky
 * zůstávají takové, jaké zapisoval převod před sdílenou vrstvou.
 */
final class PremierPayrollTakeoverTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testStatutoryPersonMapsCardEvidenceAndAccount(): void
    {
        [$statutory, $dpp] = $this->relations();
        $record = PremierPayrollTakeover::record($statutory, '2025-12-31');
        $person = $record->person;

        self::assertSame('1985-01-01', $person->identity['birth_date']);
        self::assertSame('Ing.', $person->identity['title_prefix']);
        self::assertSame('Vzorová', $person->birthSurname, 'Rodné příjmení jiné než příjmení se převezme.');
        self::assertSame('premier:per_main:rezident', $person->taxResidence?->reference);
        self::assertSame('Převzato z PREMIER: zdravotní pojišťovna 111.', $person->healthCoverage?->note);
        self::assertSame('czech', $person->socialJurisdiction?->status);
        self::assertCount(1, $person->payoutAccounts);
        self::assertSame(SyntheticPremierBackup::BANK_ACCOUNT, $person->payoutAccounts[0]->account);
        self::assertSame([['not-signed', '2025-01-01', null, 'premier:mzdy:2025-01']], array_map(
            static fn ($run): array => [$run->status, $run->from, $run->to, $run->reference],
            $person->taxDeclarations,
        ));
        self::assertSame(['1', '1', '2025-01-01', null], [$record->employment->personalNumber, $record->employment->relationKey,
            $record->employment->start, $record->employment->end]);

        $ended = PremierPayrollTakeover::record($dpp, '2025-12-31');
        self::assertSame('2025-06-30', $ended->employment->end);
        self::assertSame([], $ended->person->payoutAccounts);
        self::assertNull($ended->person->healthCoverage);
    }

    /**
     * Zákonnou evidenci osoby zapíše vztah, který převod zpracuje první, ale začínat má
     * nejstarším vztahem osoby. Začátek osoby přes všechny její vztahy dodává importér
     * ({@see PayrollTakeoverEvidencePeriod::earliestByPerson()}); pozdější začátek ho nepřebije.
     */
    public function testStatutoryEvidenceStartsWithTheOldestRelationOfThePerson(): void
    {
        [$statutory] = $this->relations();
        $person = PremierPayrollTakeover::record($statutory, '2025-12-31', null, null, '2023-04-01')->person;

        self::assertSame(['2023-04-01', '2023-04-01', '2023-04-01'], [
            $person->taxResidence?->from, $person->healthCoverage?->from, $person->socialJurisdiction?->from,
        ]);
        self::assertSame('2025-01-01', PremierPayrollTakeover::record($statutory, '2025-12-31', null, null, '2025-06-01')->person->healthCoverage?->from);
        self::assertSame(['p1' => '2023-04-01', 'p2' => '2025-01-01'], PayrollTakeoverEvidencePeriod::earliestByPerson([
            ['p1', '2025-01-01'], ['p2', '2025-01-01'], ['p1', '2023-04-01'],
        ]));
    }

    /**
     * Přihláška ČSSZ vyžaduje rodné příjmení vždy (`birth/@nam`) a PREMIER ho vede i u osob
     * bez změny jména; shodné s příjmením se proto bere stejně jako jiné.
     */
    public function testBirthSurnameEqualToCurrentSurnameIsTakenOver(): void
    {
        $relations = array_column($this->relations(), null, 'key');
        $same = PremierPayrollTakeover::record($relations['3'], '2026-12-31')->person;
        self::assertSame('Vzorový', $same->birthSurname);
        self::assertSame(['CZ', 'CZ'], [$same->identity['birth_country_code'], $same->identity['citizenship_country_code']]);
        self::assertNull(PremierPayrollTakeover::record($relations['2'], '2026-12-31')->person->birthSurname, 'Osoba bez RODNE_P zůstává bez rodného příjmení.');
    }

    /** Stát adresy je v PREMIER volný text; na kód ho převede číselník zemí. */
    public function testResidenceCountryFromNameViaCountryCodebook(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true]);
        $relations = PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations;
        $slovak = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '6'))[0];
        $countries = new CountryNameMatcher([
            ['iso2' => 'CZ', 'iso3' => 'CZE', 'name_cs' => 'Česko', 'name_en' => 'Czechia'],
            ['iso2' => 'SK', 'iso3' => 'SVK', 'name_cs' => 'Slovensko', 'name_en' => 'Slovakia'],
        ]);

        self::assertSame(['street_line' => 'Hlavná 5', 'city' => 'Bratislava', 'postal_code' => '81101', 'country_code' => 'SK'],
            PremierPayrollTakeover::record($slovak, '2025-12-31', $countries)->person->residence);
        self::assertNull(PremierPayrollTakeover::record($slovak, '2025-12-31')->person->residence, 'Bez číselníku se stát neurčí a adresa se nezapíše.');
        self::assertNull(PremierPayrollTakeover::address(['street_line' => 'X 1', 'city' => 'Y', 'postal_code' => '1', 'country_code' => null,
            'country_text' => 'Atlantida'], $countries));
    }

    /**
     * Evidence JMHZ vztahu z posledního formuláře: identifikátory se převezmou jen
     * z formuláře, který přijala ČSSZ; pracoviště, CZ-ISCO a doklady Zákonných termínů.
     */
    public function testJmhzEvidenceAndIdentifiers(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true]);
        $relations = PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations;
        [$employee, $dpp] = array_values(array_filter($relations, static fn (array $r): bool => in_array($r['key'], ['5', '6'], true)));

        $employment = PremierPayrollTakeover::record($employee, '2025-12-31')->employment;
        self::assertSame(['1234567895', '1234567890123', '25120'], [$employment->oic, $employment->idPpv, $employment->czIsco]);
        self::assertSame(['work_place' => 'Brno', 'municipality_code' => '582786', 'country_code' => 'CZ', 'regular_workplace' => null], $employment->workplace);
        self::assertSame('Převzato z PREMIER: oznámení o nástupu ČSSZ přijaté 20. 1. 2025.', $employment->checklistNotes['social_jmhz_registration']);
        self::assertSame('Převzato z PREMIER: podepsané prohlášení poplatníka, mzda za 2025-01.', $employment->checklistNotes['tax_declaration']);
        self::assertSame(['2025-01-01' => ['weekly' => 38.75, 'daily' => 7.75], '2025-07-01' => ['weekly' => 38.75, 'daily' => 7.75]], $employee['working_time']);

        self::assertSame(['oic' => '9876543204', 'id_ppv' => null, 'confirmed' => false], PremierPayrollTakeover::identifiers($dpp));
        $ended = PremierPayrollTakeover::record($dpp, '2025-12-31')->employment;
        self::assertNull($ended->oic, 'OIČ bez přijatého formuláře JMHZ se nepřevezme.');
        self::assertArrayHasKey('social_jmhz_deregistration', $ended->checklistNotes);
    }

    /** Nerezident dostane stát rezidence z karty `PER_NERZ` (jako PAMICA); rezidentská karta se nepoužije. */
    public function testNonResidentCountryFromNonResidentCard(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true, 'payroll_nonresident' => true]);
        $relations = array_column(PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations, null, 'key');

        $residence = PremierPayrollTakeover::record($relations['6'], '2025-12-31')->person->taxResidence;
        self::assertSame(['non-resident', 'SK', 'premier:per_nerz:n_stat'], [$residence?->status, $residence?->country, $residence?->reference]);
        $resident = PremierPayrollTakeover::record($relations['5'], '2025-12-31')->person->taxResidence;
        self::assertSame(['czech-resident', null], [$resident?->status, $resident?->country]);
    }

    /**
     * Osobní ohodnocení z karty vztahu (`MZ_SRAZ` 303) trvající na konci převáděného období je
     * opakovaná složka; karta, která začíná až po posledním převzatém měsíci, ne.
     */
    public function testRecurringIncomeFromRelationCard(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true]);
        $relations = array_column(PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations, null, 'key');

        self::assertSame(
            [['code' => 'PREMIER_303', 'name' => 'Osobní ohodnocení', 'kind' => 'bonus', 'amount' => 2000.0, 'from' => '2025-01-01', 'to' => null]],
            PremierPayrollTakeover::record($relations['5'], '2025-12-31')->employment->recurringComponents,
        );
        self::assertSame([], PremierPayrollTakeover::recurringComponents($relations['5'], '2025-12-31', '2025-01'));
        self::assertSame([], PremierPayrollTakeover::record($relations['6'], '2025-12-31')->employment->recurringComponents);
    }

    public function testPolicyKeepsPremierBehaviour(): void
    {
        $policy = PremierPayrollTakeover::policy();

        self::assertSame(['premier', 'PREMIER'], [$policy->sourceKey, $policy->label]);
        self::assertTrue($policy->strict);
        self::assertTrue($policy->addressesPerType);
        self::assertFalse($policy->verifyPayoutAccounts);
        self::assertTrue($policy->ignoreEndBeforeStart);
        self::assertTrue($policy->rewriteOwnOpenings);
    }

    /** @return list<array<string,mixed>> */
    private function relations(): array
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true]);
        return PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations;
    }
}
