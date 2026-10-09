<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverFormat;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInstitutionWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;

/**
 * Doplnění osob a pracovních vztahů po převodu mezd z PAMICA ({@see PohodaPayrollPeople}).
 *
 * Tahle třída je jen ORCHESTRÁTOR: záznam z PAMICA přeloží do kanonické podoby
 * ({@see PohodaPayrollTakeover}), údaje zapíše společnými zápisy převzatých mezd
 * ({@see PayrollTakeoverPersonWriter}, {@see PayrollTakeoverEmploymentWriter},
 * {@see PayrollTakeoverAbsenceWriter}, {@see PayrollTakeoverInstitutionWriter})
 * v pevném pořadí a složí protokol. Každý údaj jde toutéž cestou jako ruční zápis
 * na kartě a doplňuje se jen to, co v MyÚčtu chybí: vyplněný údaj převod nepřepíše,
 * a opakovaný převod proto nic nezdvojí.
 *
 * Každý údaj má vlastní savepoint: když neprojde kontrolou karty, zbytek se zapíše a důvod
 * jde do protokolu s osobním číslem.
 *
 * Položky Zákonných termínů se odškrtnou jen tam, kde PAMICA nese doklad (odeslané
 * podání, oznámení pojišťovně k datu události, podepsané prohlášení). Poznámka položky
 * uvede původ a datum z PAMICA. Pracovní smlouva a doklad o skončení se odškrtnou u vztahu,
 * který PAMICA vedla: smlouva i skončení prošly předchozím mzdovým systémem.
 */
final class PohodaPayrollPeopleWriter
{
    private const MESSAGE_LIMIT = 40;
    private const SAVEPOINT = 'pohoda_payroll_people';
    private const NOTE = 'Převzato z PAMICA: ';
    /** Změnové položky checklistu, které převod umí přiřadit ke změně zpracované v PAMICA. */
    private const CHANGE_ITEMS = ['contract_amendment', 'health_insurance_change', 'social_jmhz_change'];
    /** Důvod verze podmínek, kterou zapisuje import docházky z měsíční mzdy sešitu. */
    private const WAGE_CHANGE_NOTE = 'Měsíční mzda z importu docházky za ';
    /**
     * Důvod verze podmínek, kterou zapisuje tenhle převod ze sjednané mzdy v PAMICA;
     * shodný s {@see PayrollTakeoverEmploymentWriter::wageNote()}.
     */
    private const PAMICA_WAGE_NOTE = 'Sjednaná měsíční mzda z PAMICA.';
    /** Proč export PAMICA nenese účet ČSSZ a finančního úřadu (do protokolu). */
    private const INSTITUTION_ACCOUNT_MISSING = ' (číselník úřadů je v nastavení programu, které se neexportuje, a vystavené závazky v exportu nejsou)';
    private const CHECKLIST_LABELS = [
        'employment_contract' => 'pracovní smlouva / dohoda',
        'tax_declaration' => 'prohlášení k dani',
        'health_insurance_registration' => 'registrace zdravotní pojišťovny',
        'social_jmhz_registration' => 'registrace ČSSZ / JMHZ',
        'contract_amendment' => 'dodatek smlouvy',
        'health_insurance_change' => 'změna pro zdravotní pojišťovnu',
        'social_jmhz_change' => 'změna pro ČSSZ / JMHZ',
        'termination_document' => 'doklad o skončení',
        'health_insurance_deregistration' => 'odhlášení zdravotní pojišťovny',
        'social_jmhz_deregistration' => 'odhlášení ČSSZ / JMHZ',
        'eldp_submission' => 'ELDP',
        'taxable_income_confirmation' => 'potvrzení o zdanitelných příjmech',
        'enforcement_insolvency_review' => 'kontrola exekucí a insolvence',
        'later_income_review' => 'kontrola pozdějších příjmů',
    ];

    /** Názvy druhů nepřítomnosti pro protokol. */
    private const ABSENCE_LABELS = [
        'vacation' => 'dovolená',
        'dpn' => 'nemoc',
        'quarantine' => 'karanténa',
        'ocr' => 'ošetřovné',
        'paternity' => 'otcovská',
        'employee_obstacle' => 'překážka na straně zaměstnance',
        'employer_obstacle' => 'překážka na straně zaměstnavatele',
        'unpaid_leave' => 'neplacené volno',
        'unexcused' => 'neomluvená absence',
        'compensatory_time_off' => 'náhradní volno',
    ];

    private int $messages = 0;
    private PayrollTakeoverRunState $state;
    /** @var array<string,array{employee_id:int,employment_id:int,relation_type:?string,activity_code:?string}> vztah v PAMICA => vztah v MyÚčtu */
    private array $matched = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTakeoverPersonWriter $people,
        private readonly PayrollTakeoverEmploymentWriter $employments,
        private readonly PayrollTakeoverAbsenceWriter $absences,
        private readonly PayrollTakeoverInstitutionWriter $institutions,
    ) {
        $this->state = new PayrollTakeoverRunState();
    }

    /**
     * Vztahy, které se při posledním zápisu podařilo spárovat: klíč z PAMICA => naše id.
     * Čte to srovnávací sestava převzatých mezd; jinde ta mapa nikde neexistuje.
     *
     * @return array<string,array{employee_id:int,employment_id:int}>
     */
    public function matchedRelations(): array
    {
        return $this->matched;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $institutions příjemci odvodů z PAMICA (zdravotní pojišťovny,
     *     ČSSZ, finanční úřad) - {@see PohodaPayrollPeople::institutions()}
     */
    public function write(int $supplierId, ?int $userId, array $records, int $year, bool $confirmIdentifiers, ImportProtocol $protocol, string $step, array $institutions = []): void
    {
        $this->messages = 0;
        $this->state = $state = new PayrollTakeoverRunState();
        $this->matched = [];
        $policy = PohodaPayrollTakeover::policy();
        $today = date('Y-m-d');
        $moduleStart = $this->moduleStart($supplierId);
        $employees = [];
        $unconfirmed = 0;
        foreach ($records as $record) {
            $number = (string) $record['personal_number'];
            $employment = $this->employmentByCode($supplierId, $number);
            if ($employment === null) {
                $protocol->count($step, 'people_not_found');
                $this->warn($protocol, $step, 'person_not_found', "Osobní číslo {$number}: pracovní vztah s tímto kódem ve firmě není, údaje z PAMICA se nepřevzaly.", $number);
                continue;
            }
            $employeeId = (int) $employment['employee_id'];
            $employmentId = (int) $employment['id'];
            // Párovací mapa pro srovnávací sestavu: převzatá mzda z PAMICA nese jen
            // své vlastní identifikátory, a spojit ji s naším přepočtem jde jedině tady,
            // kde je vztah právě dohledaný podle osobního čísla.
            $this->matched[(string) $record['relation_key']] = [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                // Druh vztahu a druh činnosti z MZ odvodit nejde (PAMICA má vlastní číselník),
                // ale evidenční list je bez nich nesestaví. Berou se proto z už převedeného
                // vztahu a jeho podmínek; chybí-li, zůstanou prázdné a list to řekne.
                'relation_type' => PayrollTakeoverFormat::text($employment['relation_type'] ?? null),
                'activity_code' => $this->employmentActivityCode($supplierId, $employmentId),
            ];
            $takeover = PohodaPayrollTakeover::record($record);
            $person = $takeover->person;
            $relation = $takeover->employment;
            foreach ($relation->regularBenefits as $benefit) {
                $state->regularBenefits[$benefit] = ($state->regularBenefits[$benefit] ?? 0) + 1;
            }
            if (!isset($employees[$employeeId])) {
                $employees[$employeeId] = true;
                $this->part($protocol, $step, $number, 'Údaje o narození a občanství', fn (): array => $this->people->identity($supplierId, $employeeId, $person, $policy));
                $this->part($protocol, $step, $number, 'Adresa a kontakt', fn (): array => $this->people->personCard($supplierId, $employeeId, $person, $relation->start, $userId, $policy));
                $this->part($protocol, $step, $number, 'Daňová rezidence a prohlášení poplatníka', fn (): array => $this->people->statutoryEvidence(
                    $supplierId, $employeeId, $person, $today, $userId, $policy,
                    function (string $manual) use ($state, $number): void {
                        if ($manual === 'tax_residence') {
                            throw new \DomainException('PAMICA vede zaměstnance jako daňového nerezidenta bez státu rezidence. Daňovou rezidenci doplňte ručně.');
                        }
                        // Příslušnost k sociálnímu pojištění osoby podléhající cizím právním
                        // předpisům se ručně ověří; souhrn je na konci protokolu.
                        $state->foreignLegislation[] = $number;
                    },
                ));
                $this->part($protocol, $step, $number, 'Počáteční stavy kumulací', fn (): array => $this->openingBalances($supplierId, $employeeId, $person, $year, $moduleStart, $userId, $policy));
                $this->part($protocol, $step, $number, 'Děti a daňové zvýhodnění', fn (): array => $this->people->children($supplierId, $employeeId, $person, $userId, $policy));
                $this->part($protocol, $step, $number, 'Výplatní účty', fn (): array => $this->people->payoutAccounts($supplierId, $employeeId, $person, $relation->start, $userId, $policy, $state));
            }
            $this->part($protocol, $step, $number, 'Sjednaná měsíční mzda', fn (): array => $this->employments->monthlyWage($supplierId, $employmentId, $relation, $userId, $policy));
            $this->part($protocol, $step, $number, 'Předpis měsíční mzdy', fn (): array => $this->employments->recurringWage($supplierId, $employmentId, $relation, $userId, $policy, $state));
            $this->part($protocol, $step, $number, 'Průměrný výdělek', fn (): array => $this->employments->averageEarnings($supplierId, $employmentId, $relation, $userId, $policy));
            $this->part($protocol, $step, $number, 'Nepřítomnosti', fn (): array => $this->absences->absences($supplierId, $employmentId, $relation, $userId, $policy, $state));
            $this->part($protocol, $step, $number, 'Zůstatek dovolené', fn (): array => $this->absences->leaveCarryover($supplierId, $employmentId, $relation, $userId, $policy, $state));
            $this->part($protocol, $step, $number, 'Pracoviště JMHZ', fn (): array => $this->employments->workplace($supplierId, $employmentId, $relation, $userId, $policy));
            $this->part($protocol, $step, $number, 'Kód CZ-ISCO', fn (): array => $this->employments->czIsco($supplierId, $employmentId, $relation, $userId, $policy));
            $this->partTimeDiscount($supplierId, $employmentId, (string) ($employment['relation_type'] ?? ''), $record, $number, $userId, $policy, $protocol, $step);
            if (($record['pensioner_discount_doubtful'] ?? false) === true) {
                $protocol->count($step, 'pensioner_discount_doubtful');
                $this->warn($protocol, $step, 'pensioner_discount_doubtful', "Osobní číslo {$number}: PAMICA v podaném hlášení uplatnila slevu "
                    . 'pracujícího důchodce (10490), osoba ale podle karty důchod nepobírá a podle data narození starobní důchod mít '
                    . 'nemůže. Převod slevu nepřevzal: ověřte, zda jde o chybu předchozího programu (pojistné bylo sraženo nižší), '
                    . 'a stav slevy zadejte v zákonné evidenci osoby.', $number);
            }
            if ($relation->oic !== null || $relation->idPpv !== null) {
                if ($confirmIdentifiers) {
                    $this->part($protocol, $step, $number, 'OIČ a ID PPV', fn (): array => $this->employments->identifiers($supplierId, $employeeId, $employmentId, $relation, $userId, $policy, $state));
                } else {
                    $unconfirmed++;
                }
            }
            $this->part($protocol, $step, $number, 'Skončení vztahu', fn (): array => $this->employments->termination($supplierId, $employmentId, $relation, $today, null, $userId, $policy));
            $this->part($protocol, $step, $number, 'Zákonné termíny', fn (): array => $this->employments->completeChecklist(
                $supplierId,
                $employmentId,
                $relation->checklistNotes,
                self::CHANGE_ITEMS,
                fn (): ?string => $this->changeArtefactNote($supplierId, $employmentId),
                $userId,
                $policy,
                $state,
                function (string $key, \Exception $e) use ($protocol, $step, $number): void {
                    $this->warn($protocol, $step, 'checklist_failed', "Osobní číslo {$number}: položku {$key} se nepodařilo odškrtnout: {$e->getMessage()}", $number);
                },
            ));
        }

        if ($unconfirmed > 0) {
            $protocol->count($step, 'identifiers_unconfirmed', $unconfirmed);
            $protocol->warn($step, 'identifiers_unconfirmed', "OIČ nebo ID PPV z PAMICA má {$unconfirmed} vztahů. Převod je bez potvrzení, že čísla pocházejí z protokolů ČSSZ, nepřevzal. Potvrďte to v průvodci a převod zopakujte.");
        }
        if ($state->invalidOic !== []) {
            $protocol->warn($step, 'oic_invalid', sprintf(
                'OIČ z PAMICA nesedí na kontrolní číslici u %d osob (osobní čísla %s). Porovnejte je s protokolem ČSSZ a doplňte ručně.',
                count($state->invalidOic),
                implode(', ', array_slice($state->invalidOic, 0, 30)) . (count($state->invalidOic) > 30 ? ', …' : ''),
            ));
        }
        if ($institutions !== []) {
            $this->part($protocol, $step, '-', 'Účty institucí', fn (): array => $this->institutions->institutionAccounts(
                $supplierId, $institutions, $year, $userId, $policy, $state, self::INSTITUTION_ACCOUNT_MISSING,
            ));
        }
        if ($state->institutionsToConfirm > 0) {
            $protocol->warn($step, 'institution_accounts_unconfirmed', sprintf(
                'Účtů ČSSZ a finančního úřadu převzatých z PAMICA: %d. Registr institucí je v PAMICA nemá, '
                . 'převod je odvodil z vystavených závazků podle předčíslí účtu u ČNB, a proto je založil s původem '
                . '„převzato z jiného systému". Platební dávka takový účet ODMÍTNE: než se z mezd zaplatí, otevřete '
                . 'Nastavení mezd → Účty institucí, porovnejte číslo účtu a symboly s rozhodnutím úřadu a uložte '
                . 'je jako ověřené.',
                $state->institutionsToConfirm,
            ));
        }
        if ($state->institutionGaps !== []) {
            $protocol->warn($step, 'institution_accounts_missing', sprintf(
                'Účty, které převod z PAMICA nedoložil a je nutné je zadat ručně v Nastavení mezd → Účty institucí: %s. '
                . 'Bez nich neprojde kontrola připravenosti běhu ani příprava plateb.',
                implode('; ', array_slice($state->institutionGaps, 0, 10))
                    . (count($state->institutionGaps) > 10 ? '; …' : ''),
            ));
        }
        if ($state->leaveTransferred > 0) {
            $protocol->info($step, 'leave_carryover', sprintf(
                'Zůstatek dovolené z PAMICA převzalo %d vztahů jako převod do knihy dovolené. Čerpání se nepřenáší: '
                . 'položku typu „čerpáno" kniha dovolené ručně zapsat neumí, vzniká jen ze schválené nepřítomnosti, '
                . 'a už je v převáděném zůstatku odečtené. V PAMICA bylo v převáděném roce vyčerpáno %d hodin.',
                $state->leaveTransferred,
                $state->leaveTakenHours,
            ));
        }
        if ($state->leaveShared > 0) {
            $protocol->warn($step, 'leave_shared', sprintf(
                'Zůstatek dovolené se nepřevzal u %d osob se souběžnými pracovními vztahy: PAMICA vede kartu dovolené '
                . 'na osobě, ne na vztahu, takže nejde poznat, kterému vztahu zůstatek patří. Zadejte ho ručně.',
                $state->leaveShared,
            ));
        }
        if ($state->regularBenefits !== []) {
            arsort($state->regularBenefits);
            $top = [];
            foreach (array_slice($state->regularBenefits, 0, 6, true) as $label => $count) {
                $top[] = "{$label} ({$count} vztahů)";
            }
            $protocol->warn($step, 'regular_benefits_manual', sprintf(
                'Pravidelná plnění z PAMICA, která převod nezakládá jako opakovanou složku: %s. Částka se u nich mění měsíc '
                . 'od měsíce (u zdanitelné části stravování nejde odvodit ani denní sazba, export nevede dny stravenek), takže '
                . 'pevný předpis by byl vymyšlený. V převedených měsících jsou tyto částky ve mzdových vstupech z PAMICA; '
                . 'pro další měsíce předpis nebo podklad nastavte ručně.',
                implode(', ', $top),
            ));
        }
        if ($state->absencesFromImport !== []) {
            arsort($state->absencesFromImport);
            $byType = [];
            foreach ($state->absencesFromImport as $type => $count) {
                $byType[] = (self::ABSENCE_LABELS[$type] ?? $type) . ' ' . $count;
            }
            $protocol->warn($step, 'absences_from_import', sprintf(
                'Nepřítomnosti, které převod nezapsal, protože tytéž hodiny nese souhrn z importu '
                . 'docházky: %s. Jeden údaj má mít jediný zdroj; kdyby ležely v měsíci se souhrnem, '
                . 'vedly by se dvakrát a krácení měsíční mzdy by se neprovedlo. Druhy, které souhrn '
                . 'nenese (peněžitá pomoc v mateřství, rodičovská, dlouhodobé ošetřovné), zapsané jsou.',
                implode(', ', $byType),
            ));
        }
        if ($state->absencesWithoutDates !== []) {
            $protocol->warn($step, 'absences_without_dates', sprintf(
                'Nepřítomností, které evidence vede jedině s daty od a do (nemoc, ošetřovné, otcovská, neplacené '
                . 'volno, neomluvená absence) a PAMICA k nim datum nemá: %d u osobních čísel %s. Zapsat je nejde, '
                . 'z hodin se den od ani do dopočítat nedá. Hodiny zůstaly v souhrnu z importu docházky, takže se '
                . 'neztratily, ale schválení měsíce si je vyžádá s daty: doplňte nepřítomnost v kartě zaměstnance '
                . 'a měsíc schvalte ručně.',
                array_sum($state->absencesWithoutDates),
                self::personalNumbers($state->absencesWithoutDates),
            ));
        }
        if ($state->absenceOverlaps !== []) {
            $protocol->warn($step, 'absences_overlap', sprintf(
                'Nepřítomností vynechaných pro překryv s jinou: %d u osobních čísel %s. Evidence dva druhy v týchž '
                . 'dnech nepovolí, takže se zapsala jen ta první a zbytek dne zůstal bez nepřítomnosti. Zkontrolujte '
                . 'je v kartě zaměstnance.',
                array_sum($state->absenceOverlaps),
                self::personalNumbers($state->absenceOverlaps),
            ));
        }
        if ($state->absencesRejected !== []) {
            $protocol->warn($step, 'absences_rejected', sprintf(
                'Nepřítomností, které evidence odmítla zapsat (datum mimo roky s mzdovými pravidly, uzavřené období '
                . 'nebo jiná kontrola): %d u osobních čísel %s. Převod je nezapsal; doplňte je v kartě zaměstnance.',
                array_sum($state->absencesRejected),
                self::personalNumbers($state->absencesRejected),
            ));
        }
        if ($state->hourlyWageRelations > 0) {
            $protocol->warn($step, 'recurring_wage_hourly', sprintf(
                'Předpis základní měsíční mzdy nedostalo %d vztahů: mzdu mají v převáděných měsících i hodinovou nebo úkolovou '
                . 'a ta jde do běhu ze zpracovaných mezd. Základní mzda je u nich v PAMICA obsažená v týchž složkách, ne vedle nich, '
                . 'takže předpis by ji započetl podruhé. Zkontrolujte je a případný předpis zadejte ručně.',
                $state->hourlyWageRelations,
            ));
        }
        if ($state->cashShareRepaired > 0) {
            $protocol->count($step, 'payout_cash_share_repaired', $state->cashShareRepaired);
            $protocol->info($step, 'payout_cash_share_repaired', sprintf(
                'U %d karet s výplatou na účet opravil převod podíl hotovosti ze 100 %% na 0 %% (dřívější převod ho ponechal '
                . 'z výchozí hotovostní karty a rozdělení výplaty dávalo dohromady 200 %%).',
                $state->cashShareRepaired,
            ));
        }
        if ($state->accountsVerified > 0 || $state->accountsToVerify > 0) {
            $protocol->count($step, 'payout_accounts_already_verified', $state->accountsAlreadyVerified);
            $withoutPayout = count(array_unique($state->accountsWithoutPayout));
            $protocol->warn($step, 'payout_accounts_unverified', sprintf(
                'Výplatní účty z PAMICA: nově ověřeno %d, ověřených už z dřívějška %d, k ověření %d. Za ověřený se bere '
                . 'účet, na který PAMICA opakovaně vyplácela mzdu; datem ověření je den poslední výplaty. K ověření '
                . 'zůstávají účty, které PAMICA vede jako neaktivní (%d), a aktivní účty osob, u kterých PAMICA žádnou '
                . 'výplatu na účet nedoložila (%d osob). Ověřte je v kartě osoby v části Výplata; na neověřený účet '
                . 'nejde připravit výplatní příkaz.',
                $state->accountsVerified,
                $state->accountsAlreadyVerified,
                $state->accountsToVerify,
                $state->accountsInactive,
                $withoutPayout,
            ));
        }
        if ($state->foreignLegislation !== []) {
            $protocol->count($step, 'social_jurisdiction_manual', count($state->foreignLegislation));
            $protocol->warn($step, 'social_jurisdiction_manual', sprintf(
                'Příslušnost k sociálnímu pojištění převod nevyplnil u %d osob: PAMICA je vede jako podléhající cizím právním předpisům. Stát a doklad A1 doplňte ručně.',
                count($state->foreignLegislation),
            ));
        }
        if ($state->completed !== []) {
            $protocol->info($step, 'checklist_completed', 'Zákonné termíny splněné podle dokladů z PAMICA: ' . self::describe($state->completed) . '.');
        }
        $open = $this->openChecklist($supplierId);
        if ($open !== []) {
            $protocol->info($step, 'checklist_open', 'Zákonné termíny, ke kterým PAMICA doklad nemá a zůstávají otevřené: ' . self::describe($open) . '.');
        }
    }

    /**
     * Pracoviště a CZ-ISCO hned po importu měsíce, dokud je verze podmínek toho měsíce
     * ta poslední.
     *
     * Oprava podmínek sahá vždy na POSLEDNÍ verzi a je to tak správně: přepsat starší
     * verzi by změnilo jiné období, než o které jde. Totéž pravidlo má hromadné doplnění
     * pracoviště, které takový vztah vyloučí jako `later_terms`. Kdyby se pracoviště
     * zapisovalo až po všech měsících, dostala by ho jen poslední verze a měsíce před ní
     * by zůstaly bez ověřeného pracoviště, takže by u nich nešlo zmrazit hlášení. Zapsané
     * po každém měsíci si ho každá další verze opíše z předchozí
     * ({@see \MyInvoice\Service\Payroll\PayrollEmploymentTermsBody}).
     *
     * @param list<array<string,mixed>> $records
     */
    public function writeWorkplaces(int $supplierId, ?int $userId, array $records, ImportProtocol $protocol, string $step): void
    {
        $policy = PohodaPayrollTakeover::policy();
        foreach ($records as $record) {
            $number = (string) $record['personal_number'];
            $employment = $this->employmentByCode($supplierId, $number);
            if ($employment === null) {
                continue;
            }
            $employmentId = (int) $employment['id'];
            $relation = PohodaPayrollTakeover::record($record)->employment;
            $this->part($protocol, $step, $number, 'Pracoviště JMHZ', fn (): array => $this->employments->workplace($supplierId, $employmentId, $relation, $userId, $policy));
            $this->part($protocol, $step, $number, 'Kód CZ-ISCO', fn (): array => $this->employments->czIsco($supplierId, $employmentId, $relation, $userId, $policy));
        }
    }

    /**
     * Počáteční stavy ročních kumulací za měsíce roku před prvním obdobím, které zpracovává
     * MyÚčto (začátek vedení mezd). Jen souvislá řada měsíců; stavy, které zapsal dřívější
     * převod z PAMICA, se srovnají se zdrojem, zadané jinak převod nikdy nepřepíše.
     *
     * @return array<string,int>
     */
    private function openingBalances(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, int $year, ?string $moduleStart, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if ($moduleStart === null || (int) substr($moduleStart, 0, 4) !== $year) {
            return [];
        }
        return match ($this->people->openingBalances($supplierId, $employeeId, $year, (int) substr($moduleStart, 5, 2), $person->openingMonths, $userId, $policy)) {
            PayrollTakeoverPersonWriter::OPENINGS_EXISTING, PayrollTakeoverPersonWriter::OPENINGS_UNCHANGED => ['openings_existing' => 1],
            PayrollTakeoverPersonWriter::OPENINGS_GAP => throw new \DomainException('mzdy v PAMICA před prvním obdobím MyÚčta nejsou za souvislou řadu měsíců, počáteční stavy zadejte ručně.'),
            PayrollTakeoverPersonWriter::OPENINGS_WRITTEN => ['openings' => 1],
            default => [],
        };
    }

    /**
     * Změnové položky checklistu, které vznikly jen tím, že import mezd z PAMICA zapsal
     * historickou verzi podmínek (měsíční mzda platná od převáděného měsíce). Změnu
     * zpracovala PAMICA; jiná změna podmínek vztahu (ruční, jiný import) položku nechá
     * otevřenou.
     */
    private function changeArtefactNote(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT effective_on, note FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'terms_changed' ORDER BY effective_on, id"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($events === []) {
            return null;
        }
        $pamica = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_attendance_imports
              WHERE supplier_id = ? AND period_start = ? AND files_json LIKE ? LIMIT 1"
        );
        $dates = [];
        foreach ($events as $event) {
            $on = (string) $event['effective_on'];
            $note = (string) $event['note'];
            if (substr($on, 8, 2) !== '01') {
                return null;
            }
            // Verzi zapsal buď import mezd ze sešitu měsíce, nebo tenhle převod ze sjednané
            // mzdy v PAMICA; u sešitu se navíc ověří, že měsíc opravdu z PAMICA přišel.
            if (str_starts_with($note, self::WAGE_CHANGE_NOTE)) {
                $pamica->execute([$supplierId, $on, '%' . PohodaPayrollConverter::SHEET . '-' . substr($on, 0, 7) . '%']);
                if ($pamica->fetchColumn() === false) {
                    return null;
                }
            } elseif ($note !== self::PAMICA_WAGE_NOTE) {
                return null;
            }
            $dates[] = PayrollTakeoverFormat::czechDate($on);
        }
        return self::NOTE . 'změnu měsíční mzdy od ' . implode(', ', array_unique($dates)) . ' zpracovala PAMICA, převod ji zapsal jako historickou verzi podmínek vztahu.';
    }

    /** @return array<string,int> položka checklistu => počet nevyřízených */
    private function openChecklist(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT item_key, COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND status = 'pending' GROUP BY item_key ORDER BY item_key"
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string,mixed>|null */
    private function employmentByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, relation_type FROM payroll_employments WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Druh činnosti z poslední verze podmínek vztahu; pro evidenční list. */
    private function employmentActivityCode(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?'
            . ' ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return PayrollTakeoverFormat::text($stmt->fetchColumn() ?: null);
    }

    private function moduleStart(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * Sleva zaměstnavatele na pojistném (§ 7a zák. č. 589/1992 Sb.) z karty PAMICA: důvod do
     * podmínek pracovního poměru, jen když ji PAMICA v roce přiznala (`SocPojSlevaNarok`) a důvod
     * sedí na věk osoby. Žádost bez nároku, neznámý nebo nemožný důvod se ohlásí, nic se nehádá.
     *
     * @param array<string,mixed> $record
     */
    private function partTimeDiscount(int $supplierId, int $employmentId, string $relationType, array $record, string $number, ?int $userId, PayrollTakeoverPolicy $policy, ImportProtocol $protocol, string $step): void
    {
        $discount = $record['part_time_discount'] ?? null;
        if (!is_array($discount)) {
            return;
        }
        if ($discount['granted'] !== true) {
            if ($discount['requested'] === true) {
                $protocol->count($step, 'part_time_discount_not_granted');
                $this->warn($protocol, $step, 'part_time_discount_not_granted', "Osobní číslo {$number}: PAMICA vede žádost o slevu "
                    . 'zaměstnavatele na pojistném (§ 7a zák. č. 589/1992 Sb.), nárok ale v převáděném roce nepřiznala a slevu '
                    . 'neuplatnila. Převod ji nepřevzal; pokud nárok je, zadejte důvod v podmínkách vztahu a podejte záměr OZUSPOJ.', $number);
            }
            return;
        }
        $reason = $discount['reason'];
        $doubt = match (true) {
            $relationType !== 'employment' => 'sleva náleží jen k pracovnímu poměru',
            $reason === null => "PAMICA vede neznámý důvod slevy ({$discount['source_reason']})",
            default => self::reasonAgeDoubt($reason, $record['birth_date'] ?? null, $discount['from'] ?? null),
        };
        if ($doubt !== null) {
            $protocol->count($step, 'part_time_discount_doubtful');
            $this->warn($protocol, $step, 'part_time_discount_doubtful', "Osobní číslo {$number}: slevu zaměstnavatele na pojistném "
                . "z PAMICA převod nepřevzal - {$doubt}. Ověřte důvod podle § 7a odst. 1 a zadejte ho v podmínkách vztahu.", $number);
            return;
        }
        $from = is_string($discount['from'] ?? null) ? PayrollTakeoverFormat::czechDate($discount['from']) : '?';
        $letter = SocialPartTimeDiscountReason::from((string) $reason)->paragraph7aLetter();
        $this->part($protocol, $step, $number, 'Sleva zaměstnavatele na pojistném', fn (): array => $this->employments->partTimeDiscountReason(
            $supplierId, $employmentId, (string) $reason,
            self::NOTE . "důvod slevy zaměstnavatele § 7a odst. 1 písm. {$letter}) od {$from}; nárok přiznaný v PAMICA.",
            $userId, $policy,
        ));
        $intent = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_discount_intents WHERE supplier_id = ? AND environment = 'production' AND employment_id = ? AND status IN ('accepted', 'ended') LIMIT 1"
        );
        $intent->execute([$supplierId, $employmentId]);
        if ($intent->fetchColumn() === false) {
            $protocol->count($step, 'part_time_discount_intent_missing');
            $this->warn($protocol, $step, 'part_time_discount_intent_missing', "Osobní číslo {$number}: PAMICA uplatňovala slevu zaměstnavatele "
                . "na pojistném (§ 7a odst. 1 písm. {$letter}), převod zapsal její důvod. Nárok ale zakládá až přijatý záměr OZUSPOJ, "
                . 'jehož obsah export PAMICA nenese (jen doručenku v datové schránce). Převezměte přijaté oznámení v Mzdy → Importy; '
                . 'do té doby mzdový běh vztah zastaví k ručnímu posouzení slevy.', $number);
        }
    }

    /** Důvod slevy, který věk osoby vylučuje (písm. a) od 55 let, písm. g) do 21 let). */
    private static function reasonAgeDoubt(string $reason, mixed $birthDate, mixed $from): ?string
    {
        if (!is_string($birthDate) || !in_array($reason, ['age_55_plus', 'under_21'], true)) {
            return null;
        }
        $on = new \DateTimeImmutable(is_string($from) ? $from : 'today');
        $age = (new \DateTimeImmutable($birthDate))->diff($on)->y;

        return match (true) {
            $reason === 'age_55_plus' && $age < 55 => "důvod a) věk nad 55 let, osobě je ale ke dni uplatnění {$age} let",
            $reason === 'under_21' && $age >= 21 => "důvod g) věk do 21 let, osobě je ale ke dni uplatnění {$age} let",
            default => null,
        };
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
            $protocol->count($step, 'people_failed');
            $this->warn($protocol, $step, 'person_data_failed', "Osobní číslo {$number}: {$label}: {$e->getMessage()}", $number);
            return;
        }
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $protocol->count($step, $key, $value);
            }
        }
    }

    private function warn(ImportProtocol $protocol, string $step, string $code, string $message, string $number): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $protocol->warn($step, $code, $message, ['personal_number' => $number]);
        } elseif ($this->messages === self::MESSAGE_LIMIT + 1) {
            $protocol->warn($step, 'messages_truncated', 'Další upozornění k údajům osob protokol nevypisuje, jejich počet je v počtech kroku.');
        }
    }

    /** @param array<string,int> $counts */
    private static function describe(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = (self::CHECKLIST_LABELS[$key] ?? $key) . ' ' . $count;
        }
        return implode(', ', $parts);
    }

    /**
     * Osobní čísla do protokolu, nejvýš třicet; bez nich by nález nešlo dohledat.
     *
     * @param array<string,int> $byNumber osobní číslo => počet
     */
    private static function personalNumbers(array $byNumber): string
    {
        $numbers = array_keys($byNumber);
        sort($numbers);

        return implode(', ', array_slice($numbers, 0, 30)) . (count($numbers) > 30 ? ', …' : '');
    }
}
