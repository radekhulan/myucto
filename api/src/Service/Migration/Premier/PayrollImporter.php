<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Geo\CountryNameMatcher;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInstitutionWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverOpeningMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use PDO;

/**
 * Zaměstnanci a zpracované mzdy z PREMIER ({@see PremierPayroll}).
 *
 * Mzdy jsou v PREMIER zpracované a zaúčtované: jejich zápisy přišly 1:1 s deníkem
 * a převod proto **nezakládá žádný účetní zápis**. Mzdové měsíce se ukládají jako
 * převzaté mzdy předchozího systému ({@see PayrollMigrationReferenceTotalsWriter}, zdroj
 * `other`) - stejná evidence jako u převodu z PAMICA: z ní vzniká převzatý mzdový běh,
 * evidenční list důchodového pojištění za rok přechodu a kontrolní sestava. Počáteční
 * stavy ročních kumulací (roční zúčtování, potvrzení o příjmech) se zapíší za měsíce
 * roku, ve kterém začíná vedení mezd v MyÚčtu, před jeho prvním měsícem.
 *
 * Osoba a vztah jdou toutéž cestou jako ruční založení ({@see PayrollPersonCreateService})
 * a údaje karty (identita, adresa, zákonná evidence, výplatní účet, sjednaná mzda,
 * skončení vztahu) doplňují jen to, co v MyÚčtu chybí. Karta se zapisuje společným
 * zápisem převzatých mezd ({@see PayrollTakeoverPersonWriter}, {@see PayrollTakeoverEmploymentWriter})
 * z kanonické podoby vztahu ({@see PremierPayrollTakeover}). Opakovaný převod nic nezdvojí:
 * osoby, vztahy i měsíce nese mapa převodu.
 *
 * Mzdy se nakonec porovnají s deníkem ({@see PremierPayroll::reconcile()}): hrubé
 * příjmy, pojistné zaměstnance a zaměstnavatele a daň po měsících. Rozdíl je upozornění,
 * převod nezastaví - deník je převedený přesně a mzdy jsou evidence, ne zápisy.
 */
final class PayrollImporter
{
    public const STEP = 'payroll';
    /** Zdroj převzatých mezd; `other` je obecný zdroj pro mzdové systémy bez vlastního podavače. */
    private const SOURCE = 'other';
    private const REFERENCE = 'PREMIER';
    private const NOTE = 'Převzato z PREMIER: ';
    private const SAVEPOINT = 'premier_payroll';
    private const MESSAGE_LIMIT = 20;

    private int $messages = 0;
    private ?CountryNameMatcher $countries = null;
    /** Souhrny zápisu přes všechny vztahy běhu (neplatné OIČ, splněné termíny…). */
    private PayrollTakeoverRunState $state;
    /** @var list<string> osobní čísla s OIČ nebo ID PPV bez přijatého formuláře JMHZ */
    private array $unconfirmedIdentifiers = [];
    /** @var array<string,int> osobní číslo => děti se zvýhodněním bez rodného čísla */
    private array $childrenWithoutBirthNumber = [];
    /** @var array<string,int> osobní číslo => děti s příznakem BVYZI */
    private array $childrenOtherCaregiver = [];
    /** @var array<string,int> osobní číslo => pobírá důchod bez uplatněné slevy */
    private array $pensionersWithoutDiscount = [];
    /** @var array<string,int> osobní číslo => trvající neschopnosti bez známého konce */
    private array $openSickness = [];
    /** První měsíc vedení mezd v MyÚčtu (`YYYY-MM`), nebo null. */
    private ?string $moduleStart = null;
    /** Vztahy s časovou evidencí roku, pro který MyÚčto nemá mzdová pravidla. */
    private int $timeEvidenceSkipped = 0;
    /** @var array<int,bool> rok => má pravidla pro průměry a náhrady */
    private array $timeYears = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly PayrollPersonCreateService $personCreate,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollTakeoverPersonWriter $people,
        private readonly PayrollTakeoverEmploymentWriter $employmentWriter,
        private readonly PayrollMigrationReferenceTotalsWriter $referenceTotals,
        private readonly PayrollHistoricalPeriodService $historical,
        private readonly PayrollPostingMapProposalService $postingMap,
        private readonly PayrollTakeoverInstitutionWriter $institutions,
        private readonly PayrollTakeoverAbsenceWriter $absences,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollTakeoverDeductionsWriter $deductionsWriter,
        private readonly PayrollMigrationModuleSetup $moduleSetup,
        private readonly PremierPayrollRegistrations $registrations,
        private readonly PremierPayrollSickness $sickness,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $this->messages = 0;
        $this->state = new PayrollTakeoverRunState();
        $this->unconfirmedIdentifiers = [];
        $this->childrenWithoutBirthNumber = [];
        $this->childrenOtherCaregiver = [];
        $this->pensionersWithoutDiscount = [];
        $this->openSickness = [];
        $this->timeEvidenceSkipped = 0;
        if ($ctx->payroll === null && !$ctx->backup->hasRows('PERSONAL') && !$ctx->backup->hasRows('MZDY')) {
            return;
        }
        $payroll = $ctx->payroll ?? PremierPayroll::fromBackup($ctx->backup);
        if (!$payroll->hasData()) {
            return;
        }
        if ($payroll->missingTables !== []) {
            $p->info(self::STEP, 'payroll_tables_missing', 'Záloha neobsahuje tabulky mezd (' . implode(', ', $payroll->missingTables)
                . '), zaměstnanci a mzdy se nepřevedou. Mzdové zápisy jsou v převedeném deníku.');
            $this->reconcile($ctx, $payroll);
            return;
        }
        $lastPeriod = substr($ctx->endsOn(), 0, 7);
        $relations = [];
        foreach ($payroll->relations as $relation) {
            if ($relation['start'] === null || $relation['start'] > $ctx->endsOn()) {
                $p->count(self::STEP, 'employees_later');
                continue;
            }
            if ($relation['relation_type'] === PremierPayroll::APPRENTICE) {
                $p->count(self::STEP, 'apprentices');
                $months = count(array_filter(array_keys($relation['months']), static fn (string $m): bool => $m <= $lastPeriod));
                $this->warn($p, 'relation_apprentice', "Osobní číslo {$relation['personal_number']}: PREMIER vede vztah jako učně (kategorie „{$relation['category']}\"). "
                    . 'MyÚčto pro žáka ani učně druh vztahu nemá a pracovní poměr to není, vztah se proto nezaložil'
                    . ($months > 0 ? " a jeho mzdové měsíce ({$months}) se nepřevzaly" : '') . '. K ověření: zadejte ho ručně.',
                    ['personal_number' => (string) $relation['personal_number']]);
                continue;
            }
            $relations[] = $relation;
        }
        // Firma, která mzdy vede, je dostane zapnuté převodem (dřív se mzdy přeskočily,
        // dokud účetní modul a účtárnu nezaložila ručně).
        $lastPayroll = self::lastPayrollPeriod($ctx->backup);
        if ($relations !== [] && $lastPayroll !== null) {
            PayrollMigrationModuleSetup::report($p, self::STEP, $this->moduleSetup->ensure(
                $ctx->supplierId, $ctx->userOrNull(), $lastPayroll, self::lastDataPeriod($ctx->backup),
            ), 'PREMIER');
        }
        $blocker = $this->prerequisite($ctx->supplierId);
        if ($blocker !== null) {
            $months = 0;
            foreach ($relations as $relation) {
                $months += count(array_filter(array_keys($relation['months']), static fn (string $m): bool => $m <= $lastPeriod));
            }
            $p->count(self::STEP, 'months_skipped', $months);
            $p->warn(self::STEP, 'payroll_module_missing', $blocker . ' Zaměstnanci (' . count($relations) . ') a mzdové měsíce (' . $months
                . ') se proto nepřevedly. Mzdové zápisy jsou v převedeném deníku; převod roku po nastavení mezd zopakujte a mzdy se doplní.');
            $this->reconcile($ctx, $payroll);
            return;
        }

        // Měsíce od začátku vedení mezd v MyÚčtu počítá MyÚčto; převzatý údaj by vedle
        // spočítaného stál jako druhé, neověřené číslo za týž měsíc.
        $start = $this->historical->startPeriod($ctx->supplierId);
        $this->moduleStart = $start;
        $afterStart = 0;
        $totals = [];
        $byEmployee = [];
        /** @var array<string,float> osobní číslo => srážky v převáděných měsících (Kč) */
        $deductions = [];
        /** @var array<string,string> osobní číslo => poslední měsíc s vyloučenou dobou */
        $excluded = [];
        // Trvalé srážky (`MZ_SRAZ`) převod zakládá; bez nich zbývají jen sražené částky měsíců.
        $deductionCards = $ctx->backup->hasRows('MZ_SRAZ');
        $created = [];
        foreach ($relations as $relation) {
            $pair = $this->inSavepoint($ctx, $relation, fn (): ?array => $this->relation($ctx, $relation));
            if ($pair === null) {
                continue;
            }
            $created[] = $relation;
            [$employeeId, $employmentId] = $pair;
            $activity = $this->activityCode($ctx->supplierId, $employmentId);
            $number = (string) $relation['personal_number'];
            foreach ($relation['months'] as $period => $m) {
                if ($period > $lastPeriod) {
                    continue;
                }
                if ($m['deductions'] > 0 && !$deductionCards) {
                    $deductions[$number] = ($deductions[$number] ?? 0.0) + $m['deductions'];
                }
                // Vyloučenou dobu bez nepřítomností s daty (starší zálohy bez `DNY`) převod nezná.
                if ($m['excluded_days'] > 0 && ($relation['absences'] ?? []) === []) {
                    $excluded[$number] = (string) $period;
                }
                if ($start !== null && $period >= $start) {
                    $afterStart++;
                    continue;
                }
                $totals[] = self::referenceTotals($relation, (string) $period, $m, $employeeId, $employmentId, $activity);
                $key = $relation['key'] . '|' . $period;
                if ($this->map->get($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_MONTH, $key) === null) {
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_MONTH, $key, $employmentId, $ctx->runId);
                    $p->count(self::STEP, 'months');
                } else {
                    $p->count(self::STEP, 'months_existing');
                }
                $byEmployee[$employeeId][(string) $period][] = $m;
            }
        }
        if ($created !== []) {
            $this->submittedRegistrations($ctx);
            $this->submittedSickness($ctx);
        }
        if ($afterStart > 0) {
            $p->count(self::STEP, 'months_after_start', $afterStart);
            $this->info($p, 'months_after_start', "Mzdové měsíce od začátku vedení mezd v MyÚčtu ({$start}) se nepřevzaly (celkem {$afterStart}), počítá je MyÚčto.");
        }
        $this->detail($ctx, '-', 'Účty institucí', fn (): array => $this->institutions->institutionAccounts(
            $ctx->supplierId, PremierPayrollInstitutions::read($ctx->backup), $ctx->year, $ctx->userOrNull(), PremierPayrollTakeover::policy(), $this->state,
            ' (není v číselníku pojišťoven ani v nastavení mezd)',
        ));
        if ($deductionCards) {
            $this->deductions($ctx, $created, $payroll);
        }
        $this->identifierSummary($p);
        $this->cardSummary($p);
        $this->timeSummary($p, $ctx->year);
        $this->notConverted($p, $deductions, $excluded);
        if ($totals !== []) {
            $this->referenceTotals->store($ctx->supplierId, self::SOURCE, $totals, self::REFERENCE . ' ' . $ctx->backup->ico);
        }
        $this->openingBalances($ctx, $byEmployee, $payroll);
        $this->postingMap($ctx);
        $this->reconcile($ctx, $payroll);
    }

    /**
     * Registrace ČSSZ (REGZEC25, PREZEC26) odeslané z PREMIER a ČSSZ přijaté ({@see PremierPayrollSubmissions}),
     * převzaté produktovým importem registrací ({@see PremierPayrollRegistrations}). Jdou po osobách a vztazích,
     * protože přihláška, dohlášení a odhláška se páruje na vztah, který převod právě založil.
     */
    private function submittedRegistrations(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $result = $this->registrations->import($ctx);
        $counts = $result['counts'];
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $p->count(self::STEP, $name, $count);
            }
        }
        foreach ($result['problems'] as $problem) {
            $this->warn($p, $problem['code'], $problem['text'], $problem['context']);
        }
        if ($counts['registrations_files'] === 0) {
            return;
        }
        $p->info(self::STEP, 'registrations_imported', sprintf(
            'Registrace ČSSZ odeslané z PREMIER (MZ_VREP): přijatých vět %d, už dříve převzatých %d, zapsáno %d, beze změny %d, bez odpovídajícího vztahu %d, '
            . 'nezapsáno %d (zablokováno %d, selhalo %d), pozdějších než převáděné období %d. ČSSZ odmítla %d podání a %d vět '
            . 'v přijatých podáních, ty se nepřebírají%s.',
            $counts['registrations_sentences'],
            $counts['registrations_done'],
            $counts['registrations_applied'],
            $counts['registrations_unchanged'],
            $counts['registrations_unmatched'],
            $counts['registrations_blocked'] + $counts['registrations_failed'],
            $counts['registrations_blocked'],
            $counts['registrations_failed'],
            $counts['registrations_later'],
            $counts['registrations_files_rejected'],
            $counts['registrations_sentences_rejected'],
            $counts['registrations_files_unreadable'] > 0 ? sprintf(' (%d podání se nedalo přečíst)', $counts['registrations_files_unreadable']) : '',
        ));
    }

    /**
     * Podání dávek (NEMPRI, HZUPN) odeslaná z PREMIER a ČSSZ přijatá ({@see PremierPayrollSickness}): případy dávek
     * vyřízené předchozím programem k vztahům a nepřítomnostem, které převod právě založil.
     */
    private function submittedSickness(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $result = $this->sickness->import($ctx);
        $counts = $result['counts'];
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $p->count(self::STEP, $name, $count);
            }
        }
        foreach ($result['problems'] as $problem) {
            $this->warn($p, $problem['code'], $problem['text'], $problem['context']);
        }
        if ($counts['benefits_files'] === 0) {
            return;
        }
        $p->info(self::STEP, 'benefits_imported', sprintf(
            'Podání dávek ČSSZ odeslaná z PREMIER (NEMPRI, HZUPN): přijatých vět %d, už dříve převzatých %d, zapsáno jako vyřízené '
            . 'předchozím programem %d, beze změny %d, nezapsáno %d (zablokováno %d, selhalo %d), odeslaných po převáděném období %d. '
            . 'ČSSZ odmítla %d podání a %d vět v přijatých podáních, ty se nepřebírají%s.',
            $counts['benefits_sentences'],
            $counts['benefits_done'],
            $counts['benefits_applied'],
            $counts['benefits_unchanged'],
            $counts['benefits_blocked'] + $counts['benefits_failed'],
            $counts['benefits_blocked'],
            $counts['benefits_failed'],
            $counts['benefits_later'],
            $counts['benefits_files_rejected'],
            $counts['benefits_sentences_rejected'],
            $counts['benefits_files_unreadable'] > 0 ? sprintf(' (%d podání se nedalo přečíst)', $counts['benefits_files_unreadable']) : '',
        ));
    }

    /**
     * Trvalé srážky, exekuce a insolvence (`MZ_SRAZ`, {@see PremierPayrollDeductions})
     * společným zápisem srážek. Srážka je stav ke konci zpracovaných mezd, ne údaj roku:
     * zakládá se až v běhu roku, ve kterém PREMIER mzdy naposledy zpracoval (nejpozději
     * měsíc před začátkem vedení mezd v MyÚčtu), a jen ta, která tehdy trvá.
     *
     * @param list<array<string,mixed>> $relations vztahy, které převod v tomto běhu zapsal
     */
    private function deductions(PremierContext $ctx, array $relations, PremierPayroll $payroll): void
    {
        $last = null;
        foreach ($payroll->relations as $relation) {
            $period = array_key_last($relation['months']);
            $last = $period !== null && ($last === null || (string) $period > $last) ? (string) $period : $last;
        }
        if ($last === null) {
            return;
        }
        if ($this->moduleStart !== null) {
            $last = min($last, (new \DateTimeImmutable(substr($this->moduleStart, 0, 7) . '-01'))->modify('-1 month')->format('Y-m'));
        }
        // Běh dřívějšího roku by srážku zakládal podle stavu, který už neplatí. Běh pozdějšího
        // roku je neškodný: mapa převodu zapsané srážky přeskočí.
        if ((int) substr($last, 0, 4) > $ctx->year) {
            return;
        }
        $result = PremierPayrollDeductions::read($ctx->backup, $relations, $last, $ctx->year);
        if ($result['ended'] > 0) {
            $ctx->protocol->count(self::STEP, 'deductions_ended', $result['ended']);
        }
        $this->deductionsWriter->write($ctx->supplierId, $ctx->userOrNull(), $result, $ctx->year, $ctx->protocol, self::STEP,
            PremierPayrollTakeover::policy(), new PremierPayrollDeductionMap($this->map), $ctx->runId);
    }

    /**
     * Srážky a nepřítomnosti, které převod z PREMIER nezakládá, a u koho je zdroj má.
     *
     * Srážky: `MZDY` nese jen částky sražené v jednotlivých měsících, ne případ (věřitel,
     * pořadí, zbývající dluh, dohoda). Exekuci, insolvenci ani dohodu o srážkách z toho
     * založit nejde, a bez nich je MyÚčto od prvního vlastního měsíce nesrazí.
     *
     * Nepřítomnosti: `MZDY` nese jen počet vyloučených dnů měsíce (nemoc, ošetřovné,
     * mateřská…), ne druh a data. Pracovní neschopnost, která trvá přes začátek vedení
     * mezd, proto MyÚčto nezná a čtrnáctidenní období náhrady mzdy by počítalo znovu.
     *
     * Upozornění je souhrnné a nepodléhá limitu hlášek kroku: bez něj by převod o obojím
     * mlčel.
     *
     * @param array<string,float> $deductions osobní číslo => sražené Kč
     * @param array<string,string> $excluded osobní číslo => poslední měsíc s vyloučenou dobou
     */
    private function notConverted(ImportProtocol $p, array $deductions, array $excluded): void
    {
        if ($deductions !== []) {
            $p->count(self::STEP, 'deductions_not_converted', count($deductions));
            $p->warn(self::STEP, 'deductions_not_converted', sprintf(
                'Srážky ze mzdy z PREMIER se nepřevedly: záloha nese jen částky sražené v jednotlivých měsících, ne exekuce, '
                . 'insolvence ani dohody o srážkách (věřitel, pořadí, zbývající dluh). Srážky mají osobní čísla %s (celkem %s Kč). '
                . 'Trvající srážky založte ručně v Mzdy → Exekuce a insolvence, jinak je MyÚčto od prvního měsíce vedení mezd nesrazí.',
                self::personalNumbers(array_keys($deductions)),
                self::money(array_sum($deductions)),
            ), ['personal_numbers' => array_keys($deductions)]);
        }
        if ($excluded !== []) {
            $p->count(self::STEP, 'absences_not_converted', count($excluded));
            $p->warn(self::STEP, 'absences_not_converted', sprintf(
                'Nepřítomnosti a nemocenská z PREMIER se nepřevedly: záloha nese jen počet vyloučených dnů měsíce (nemoc, '
                . 'ošetřovné, mateřská a další), ne druh ani data. Vyloučené doby mají osobní čísla %s. Trvá-li nepřítomnost '
                . 'i v prvním měsíci vedení mezd v MyÚčtu (rozpracovaná pracovní neschopnost), založte ji ručně na kartě '
                . 'zaměstnance, jinak MyÚčto začne období náhrady mzdy počítat znovu.',
                self::personalNumbers(array_map(
                    static fn (string $number, string $period): string => "{$number} (naposledy {$period})",
                    array_keys($excluded),
                    array_values($excluded),
                )),
            ), ['personal_numbers' => array_keys($excluded)]);
        }
    }

    /**
     * OIČ a ID PPV, které převod nepřevzal: bez přijatého formuláře JMHZ nejsou doložené
     * ({@see PremierPayrollTakeover::identifiers()}), nebo OIČ nesedí na kontrolní číslici.
     * Souhrnné upozornění mimo limit hlášek kroku, s osobními čísly.
     */
    private function identifierSummary(ImportProtocol $p): void
    {
        if ($this->unconfirmedIdentifiers !== []) {
            $p->count(self::STEP, 'identifiers_unconfirmed', count($this->unconfirmedIdentifiers));
            $p->warn(self::STEP, 'identifiers_unconfirmed', sprintf(
                'OIČ nebo ID pracovněprávního vztahu z PREMIER nemá doložené %d vztahů (osobní čísla %s): PREMIER u nich nevede '
                . 'formulář JMHZ, který by ČSSZ přijala, takže převod čísla nepřevzal. K ověření: porovnejte je s protokolem ČSSZ '
                . 'a doplňte na kartě vztahu.',
                count($this->unconfirmedIdentifiers),
                self::personalNumbers($this->unconfirmedIdentifiers),
            ), ['personal_numbers' => $this->unconfirmedIdentifiers]);
        }
        if ($this->state->invalidOic !== []) {
            $p->warn(self::STEP, 'oic_invalid', sprintf(
                'OIČ z PREMIER nesedí na kontrolní číslici u %d vztahů (osobní čísla %s). Porovnejte je s protokolem ČSSZ a doplňte ručně.',
                count($this->state->invalidOic),
                self::personalNumbers($this->state->invalidOic),
            ), ['personal_numbers' => $this->state->invalidOic]);
        }
    }

    /**
     * Údaje karty osoby, které převod nezapsal nebo které je třeba ověřit, souhrnně
     * s osobními čísly (mimo limit hlášek kroku).
     */
    private function cardSummary(ImportProtocol $p): void
    {
        if ($this->state->institutionsToConfirm > 0) {
            $p->warn(self::STEP, 'institution_accounts_unconfirmed', sprintf(
                'Účtů ČSSZ a finančního úřadu převzatých z nastavení mezd PREMIER: %d. Nejsou to sdělení úřadu, převod je proto '
                . 'založil s původem „převzato z jiného systému" a platební dávka je ODMÍTNE: než se z mezd zaplatí, otevřete '
                . 'Nastavení mezd → Účty institucí, porovnejte číslo účtu a symboly s rozhodnutím úřadu a uložte je jako ověřené.',
                $this->state->institutionsToConfirm,
            ));
        }
        if ($this->state->institutionGaps !== []) {
            $p->warn(self::STEP, 'institution_accounts_missing', sprintf(
                'Účty, které převod z PREMIER nedoložil a je nutné je zadat ručně v Nastavení mezd → Účty institucí: %s.',
                implode('; ', array_slice($this->state->institutionGaps, 0, 10)) . (count($this->state->institutionGaps) > 10 ? '; …' : ''),
            ));
        }
        if ($this->childrenWithoutBirthNumber !== []) {
            $p->count(self::STEP, 'children_birth_number_missing', array_sum($this->childrenWithoutBirthNumber));
            $p->warn(self::STEP, 'children_birth_number_missing', sprintf(
                'Dětí s uplatněným daňovým zvýhodněním bez rodného čísla v PREMIER: %d (osobní čísla %s). Vyživovanou osobu bez '
                . 'rodného čísla převod nezakládá; doplňte dítě a nárok ručně na kartě zaměstnance.',
                array_sum($this->childrenWithoutBirthNumber),
                self::personalNumbers(array_keys($this->childrenWithoutBirthNumber)),
            ), ['personal_numbers' => array_keys($this->childrenWithoutBirthNumber)]);
        }
        if ($this->childrenOtherCaregiver !== []) {
            $p->count(self::STEP, 'children_other_caregiver', array_sum($this->childrenOtherCaregiver));
            $p->warn(self::STEP, 'children_other_caregiver', sprintf(
                'K ověření: u %d dětí se zvýhodněním vede PREMIER příznak „vyživuje i jiná osoba" (BVYZI), jehož význam se ze zálohy '
                . 'nedá spolehlivě určit; zvýhodnění se přitom uplatňovalo dál. Nárok se převzal jako uplatněný tímto zaměstnancem; '
                . 'ověřte v prohlášení, zda dítě v domácnosti nevyživuje i druhý z rodičů (osobní čísla %s).',
                array_sum($this->childrenOtherCaregiver),
                self::personalNumbers(array_keys($this->childrenOtherCaregiver)),
            ), ['personal_numbers' => array_keys($this->childrenOtherCaregiver)]);
        }
        if ($this->pensionersWithoutDiscount !== []) {
            $p->count(self::STEP, 'pensioners_without_discount', count($this->pensionersWithoutDiscount));
            $p->warn(self::STEP, 'pensioners_without_discount', sprintf(
                'K ověření: PREMIER vede u %d vztahů pobírání důchodu, sleva na pojistném pracujícího důchodce se ale v mzdách '
                . 'neuplatňovala; převod ji zapsal jako neuplatněnou. Zkontrolujte, zda o ni zaměstnanec nepožádal '
                . '(osobní čísla %s).',
                count($this->pensionersWithoutDiscount),
                self::personalNumbers(array_keys($this->pensionersWithoutDiscount)),
            ), ['personal_numbers' => array_keys($this->pensionersWithoutDiscount)]);
        }
    }

    private function timeEvidenceYear(int $year): bool
    {
        return $this->timeYears[$year] ??= PayrollRulesetYearCoverage::coversYear($this->rulesets, PayrollRulesetDomain::CompensationAverages, $year);
    }

    /** Nepřítomnosti a dovolená převzaté z PREMIER: co se převzalo a co je k ověření. */
    private function timeSummary(ImportProtocol $p, int $year): void
    {
        if ($this->timeEvidenceSkipped > 0) {
            $p->count(self::STEP, 'time_evidence_unsupported_year', $this->timeEvidenceSkipped);
            $p->info(self::STEP, 'time_evidence_unsupported_year', sprintf(
                'Nepřítomnosti, zůstatek dovolené a průměrné výdělky roku %d se nepřevzaly (%d vztahů): MyÚčto pro tento rok nemá '
                . 'mzdová pravidla a mzdy za něj nepočítá. Převezmou se z roku, od kterého MyÚčto mzdy vede.',
                $year,
                $this->timeEvidenceSkipped,
            ));
        }
        if ($this->state->leaveTransferred > 0) {
            $p->info(self::STEP, 'leave_carryover', sprintf(
                'Zůstatek dovolené z PREMIER (DOV_DNY, v hodinách) převzalo %d vztahů jako převod do knihy dovolené. Čerpání '
                . 'se nepřenáší, v zůstatku je už odečtené; v převáděném roce bylo v PREMIER vyčerpáno %d hodin.',
                $this->state->leaveTransferred,
                $this->state->leaveTakenHours,
            ));
        }
        if ($this->state->absenceOverlaps !== []) {
            $p->warn(self::STEP, 'absences_overlap', sprintf(
                'Nepřítomností vynechaných pro překryv s jinou: %d u osobních čísel %s. Evidence dva druhy v týchž dnech nepovolí, '
                . 'zapsala se jen ta první. Zkontrolujte je v kartě zaměstnance.',
                array_sum($this->state->absenceOverlaps),
                self::personalNumbers(array_keys($this->state->absenceOverlaps)),
            ));
        }
        if ($this->state->absencesRejected !== []) {
            $p->warn(self::STEP, 'absences_rejected', sprintf(
                'Nepřítomností, které evidence odmítla zapsat (datum mimo roky s mzdovými pravidly, uzavřené období '
                . 'nebo jiná kontrola): %d u osobních čísel %s. Převod je nezapsal; doplňte je v kartě zaměstnance.',
                array_sum($this->state->absencesRejected),
                self::personalNumbers(array_keys($this->state->absencesRejected)),
            ), ['personal_numbers' => array_keys($this->state->absencesRejected)]);
        }
        if ($this->openSickness !== []) {
            $p->count(self::STEP, 'sickness_open', array_sum($this->openSickness));
            $p->warn(self::STEP, 'sickness_open', sprintf(
                'K ověření: pracovní neschopnost trvá přes poslední převáděný měsíc a PREMIER nezná její konec (%d případů, osobní '
                . 'čísla %s). Nepřítomnost je zapsaná do konce převáděného období; až neschopnost skončí, prodlužte ji v kartě '
                . 'zaměstnance (ne novou nepřítomností), jinak MyÚčto začne období náhrady mzdy počítat znovu.',
                array_sum($this->openSickness),
                self::personalNumbers(array_keys($this->openSickness)),
            ), ['personal_numbers' => array_keys($this->openSickness)]);
        }
    }

    /** @param list<int|string> $numbers */
    private static function personalNumbers(array $numbers): string
    {
        return implode(', ', array_slice(array_map('strval', $numbers), 0, 30)) . (count($numbers) > 30 ? ', …' : '');
    }

    /**
     * Návrh mzdových předkontací z mzdových zápisů deníku roku, stejnou cestou jako
     * u převodu z PAMICA ({@see PremierPayrollPostingMap}). Ukládá se jen návrh; do
     * nastavení mezd sáhne teprve potvrzení účetní. Kontrola mezd proti deníku
     * ({@see self::reconcile()}) je jiná otázka a běží dál vedle něj.
     */
    private function postingMap(PremierContext $ctx): void
    {
        $stored = $this->postingMap->refresh(
            $ctx->supplierId,
            PremierPayrollPostingMap::fromBackup($ctx->backup, $ctx->journal, $ctx->year),
            $ctx->year,
            self::REFERENCE . ' ' . $ctx->backup->ico,
        );
        if ($stored === null) {
            return;
        }
        $ctx->protocol->set('posting_map', $stored['proposal']);
        $conflicts = (int) ($stored['proposal']['summary']['conflict'] ?? 0);
        if ($conflicts > 0) {
            $ctx->protocol->count(self::STEP, 'posting_map_conflicts', $conflicts);
        }
        $this->info($ctx->protocol, 'posting_map', sprintf(
            'Ze mzdových zápisů deníku %d vznikl návrh kontací mezd (Kontace mezd z původního programu). '
            . 'Nastavení se nemění, dokud návrh nepotvrdíte%s.',
            $ctx->year,
            $conflicts > 0 ? "; u {$conflicts} kontací jsou v deníku různé účty a vybrat musíte sami" : '',
        ));
    }

    /**
     * Bez zapnutých mezd a výchozí mzdové účtárny pracovní vztah založit nejde (stejná
     * podmínka jako u převodu mezd z PAMICA).
     */
    public function prerequisite(int $supplierId): ?string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return 'Firma nemá zapnutý modul Mzdy (Nastavení → Moduly).';
        }
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return 'Chybí nastavení mezd zaměstnavatele.';
        }
        $office = $pdo->prepare(
            'SELECT 1 FROM payroll_employer_settings s
               JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id AND o.is_active = 1
              WHERE s.supplier_id = ?'
        );
        $office->execute([$supplierId]);
        return $office->fetchColumn() === false ? 'Chybí výchozí mzdová účtárna zaměstnavatele (Mzdy → Nastavení).' : null;
    }

    /**
     * Co by převod udělal s nastavením mezd firmy, která je ještě nemá (kontrola před
     * převodem, nic nezapisuje); `null`, když záloha mzdy nemá.
     *
     * @return array<string,mixed>|null {@see PayrollMigrationModuleSetup::plan()}
     */
    public function moduleSetupPlan(int $supplierId, PremierBackup $backup): ?array
    {
        $last = self::lastPayrollPeriod($backup);
        return $last === null ? null : $this->moduleSetup->plan($supplierId, $last, self::lastDataPeriod($backup));
    }

    /** Poslední měsíc zpracovaných mezd v záloze (`MZDY`, `YYYY-MM`), nebo `null`. */
    public static function lastPayrollPeriod(PremierBackup $backup): ?string
    {
        return $backup->lastPayrollPeriod();
    }

    /** Konec dat zálohy: prosinec posledního účetního roku. */
    private static function lastDataPeriod(PremierBackup $backup): ?string
    {
        $years = $backup->years();
        return $years === [] ? null : sprintf('%04d-12', max($years));
    }

    /**
     * Osoba a pracovní vztah: z mapy převodu, převzetím vztahu se stejným osobním číslem
     * a jménem, nebo nově. Pak údaje karty, sjednaná mzda, skončení a zákonné termíny.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}|null
     */
    private function relation(PremierContext $ctx, array $relation): ?array
    {
        $p = $ctx->protocol;
        $supplierId = $ctx->supplierId;
        $userId = $ctx->userOrNull();
        $number = (string) $relation['personal_number'];
        $employmentId = $this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT, (string) $relation['key']);
        $employeeId = $this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key']);
        if ($employmentId !== null) {
            $row = $this->employmentById($supplierId, $employmentId);
            if ($row === null) {
                $p->count(self::STEP, 'deleted');
                $this->warn($p, 'employment_deleted', "Osobní číslo {$number}: pracovní vztah převedený dřívějším převodem ve firmě už není, mzdy se k němu nepřevedly.");
                return null;
            }
            $employeeId = (int) $row['employee_id'];
            $p->count(self::STEP, 'existing');
        } else {
            $adopted = $this->adoptable($supplierId, $number, $relation, $employeeId);
            if ($adopted !== null) {
                [$employeeId, $employmentId] = $adopted;
                $p->count(self::STEP, 'matched');
            } elseif ($employeeId !== null) {
                $employmentId = $this->addEmployment($ctx, $employeeId, $relation);
                $p->count(self::STEP, 'employments_created');
            } else {
                [$employeeId, $employmentId] = $this->createPerson($ctx, $relation);
                $p->count(self::STEP, 'employees_created');
                $p->count(self::STEP, 'employments_created');
            }
            if ($this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key']) === null) {
                $this->map->put($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key'], $employeeId, $ctx->runId);
            }
            $this->map->put($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT, (string) $relation['key'], $employmentId, $ctx->runId);
            if ($relation['relation_type_derived']) {
                $this->info($p, 'relation_type_default', "Osobní číslo {$number}: druh vztahu z kategorie „{$relation['category']}\" nejde určit, "
                    . 'vztah je založený jako pracovní poměr. Zkontrolujte ho na kartě zaměstnance.');
            }
            if (($relation['statutory_flag'] ?? false) === true && $relation['relation_type'] !== 'statutory_body') {
                $type = match ($relation['relation_type']) {
                    'employment' => 'pracovní poměr',
                    'dpc' => 'dohoda o pracovní činnosti',
                    'dpp' => 'dohoda o provedení práce',
                    default => (string) $relation['relation_type'],
                };
                $activity = $relation['registry']['jmhz']['activity'] ?? null;
                $this->warn($p, 'relation_type_statutory_flag', "Osobní číslo {$number}: PREMIER má u vztahu příznak jednatele, ale hlášení JMHZ, které přijala ČSSZ, "
                    . 'vykazuje ' . (is_string($activity) ? "druh činnosti {$activity} ({$type})" : "druh činnosti {$type}") . ". Vztah je založený jako {$type}: "
                    . 'přednost dostalo přijaté hlášení, protože podle něj vztah eviduje ČSSZ a další hlášení z MyÚčta s ním musí souhlasit. '
                    . 'K ověření: je-li osoba ve skutečnosti jednatel (člen statutárního orgánu), změňte druh vztahu na kartě zaměstnance '
                    . 'a ČSSZ podejte opravné hlášení.',
                    ['personal_number' => $number, 'relation_type' => $relation['relation_type'], 'jmhz_activity' => $activity]);
            }
        }

        $this->activate($ctx, $employmentId, $relation);
        $policy = PremierPayrollTakeover::policy();
        $this->countries ??= CountryNameMatcher::fromDatabase($this->db);
        $takeover = PremierPayrollTakeover::record($relation, $ctx->endsOn(), $this->countries, $this->moduleStart);
        $person = $takeover->person;
        $state = $this->state;
        $this->detail($ctx, $number, 'Sjednaná mzda', fn (): array => $this->wages($ctx, $employmentId, $relation));
        $this->detail($ctx, $number, 'Údaje o narození a občanství', fn (): array => $this->people->identity($supplierId, $employeeId, $person, $policy));
        $this->detail($ctx, $number, 'Adresa a kontakt', fn (): array => $this->people->personCard($supplierId, $employeeId, $person, $takeover->employment->start, $userId, $policy));
        $this->detail($ctx, $number, 'Zákonná evidence', fn (): array => $this->people->statutoryEvidence(
            $supplierId, $employeeId, $person, date('Y-m-d'), $userId, $policy,
            function (string $manual) use ($ctx, $number): void {
                if ($manual === 'tax_residence') {
                    $this->warn($ctx->protocol, 'tax_residence_manual', "Osobní číslo {$number}: PREMIER vede osobu jako daňového nerezidenta. Daňovou rezidenci doplňte ručně.");
                    return;
                }
                $this->warn($ctx->protocol, 'social_jurisdiction_manual', "Osobní číslo {$number}: PREMIER vede osobu jako vyslanou nebo pojištěnou v cizině. Příslušnost k sociálnímu pojištění doplňte ručně.");
            },
        ));
        $this->detail($ctx, $number, 'Děti a daňové zvýhodnění', fn (): array => $this->people->children($supplierId, $employeeId, $person, $userId, $policy));
        $children = PremierPayrollTakeover::children($relation, $ctx->endsOn());
        if ($children['without_birth_number'] > 0) {
            $this->childrenWithoutBirthNumber[$number] = $children['without_birth_number'];
        }
        if ($children['other_caregiver'] > 0) {
            $this->childrenOtherCaregiver[$number] = $children['other_caregiver'];
        }
        if (is_array($relation['pension'] ?? null) && !in_array(true, array_column($relation['months'], 'pensioner_discount'), true)) {
            $this->pensionersWithoutDiscount[$number] = 1;
        }
        $this->detail($ctx, $number, 'Výplatní účet', fn (): array => $this->people->payoutAccounts($supplierId, $employeeId, $person, $takeover->employment->start, $userId, $policy, $state));
        $employment = $takeover->employment;
        // Rok, pro který MyÚčto nemá mzdová pravidla, nepočítá: nepřítomnost ani průměr
        // se k němu zapsat nedají (validátor je odmítne) a zůstatek dovolené by visel v roce,
        // který kniha dovolené nevede.
        if ($this->timeEvidenceYear($ctx->year)) {
            $this->detail($ctx, $number, 'Průměrný výdělek', fn (): array => $this->employmentWriter->averageEarnings($supplierId, $employmentId, $employment, $userId, $policy));
            $this->detail($ctx, $number, 'Nepřítomnosti', fn (): array => $this->absences->absences($supplierId, $employmentId, $employment, $userId, $policy, $state));
            $this->detail($ctx, $number, 'Zůstatek dovolené', fn (): array => $this->absences->leaveCarryover($supplierId, $employmentId, $employment, $userId, $policy, $state));
            $open = PremierPayrollTakeover::absences($relation, $ctx->endsOn(), $this->moduleStart)['open_sickness'];
            if ($open > 0) {
                $this->openSickness[$number] = $open;
            }
        } elseif ($employment->absences !== [] || $employment->averages !== [] || $employment->leave !== null) {
            $this->timeEvidenceSkipped++;
        }
        $this->detail($ctx, $number, 'Pracoviště JMHZ', fn (): array => $this->employmentWriter->workplace($supplierId, $employmentId, $employment, $userId, $policy));
        $this->detail($ctx, $number, 'Kód CZ-ISCO', fn (): array => $this->employmentWriter->czIsco($supplierId, $employmentId, $employment, $userId, $policy));
        if ($employment->oic !== null || $employment->idPpv !== null) {
            $this->detail($ctx, $number, 'OIČ a ID PPV', fn (): array => $this->employmentWriter->identifiers($supplierId, $employeeId, $employmentId, $employment, $userId, $policy, $this->state));
        } else {
            $identifiers = PremierPayrollTakeover::identifiers($relation);
            if ($identifiers['oic'] !== null || $identifiers['id_ppv'] !== null) {
                $this->unconfirmedIdentifiers[] = $number;
            }
        }
        $this->detail($ctx, $number, 'Skončení vztahu', fn (): array => $this->employmentWriter->termination(
            $supplierId, $employmentId, $employment, date('Y-m-d'), $ctx->endsOn(), $userId, $policy,
        ));
        $this->detail($ctx, $number, 'Zákonné termíny', fn (): array => $this->employmentWriter->completeChecklist(
            $supplierId, $employmentId, $this->checklistNotes($ctx, $employmentId, $relation, $employment->checklistNotes), [], null, $userId, $policy, $this->state,
            static function (): void {},
        ));
        return [$employeeId, $employmentId];
    }

    /**
     * Vztah se stejným osobním číslem, který ve firmě už je (založený ručně nebo jiným
     * importem): převezme se, jen když sedí i jméno osoby. Jinak se nesahá na cizí vztah.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}|null
     */
    private function adoptable(int $supplierId, string $number, array $relation, ?int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.employee_id, p.full_name FROM payroll_employments e
               JOIN payroll_employees p ON p.id = e.employee_id AND p.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.code = ?'
        );
        $stmt->execute([$supplierId, $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $same = mb_strtolower(trim((string) $row['full_name'])) === mb_strtolower(trim((string) $relation['full_name']));
        if (!$same || ($employeeId !== null && $employeeId !== (int) $row['employee_id'])) {
            return null;
        }
        if (in_array((int) $row['id'], $this->map->targets($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT), true)) {
            return null;
        }
        return [(int) $row['employee_id'], (int) $row['id']];
    }

    /**
     * Nová osoba s prvním vztahem. Rodné číslo nebo kód pojišťovny, které kontrola
     * odmítne, osobu nezastaví: založí se bez nich a protokol to řekne.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}
     */
    private function createPerson(PremierContext $ctx, array $relation): array
    {
        $number = (string) $relation['personal_number'];
        $name = (string) $relation['full_name'];
        if ($name === '') {
            $name = 'Zaměstnanec ' . $number;
            $this->warn($ctx->protocol, 'person_name_missing', "Osobní číslo {$number}: záloha nemá jméno osoby, zaměstnanec je založený jako „{$name}\". Doplňte jméno na kartě.");
        }
        $input = [
            'full_name' => $name,
            'first_name' => $relation['first_name'],
            'last_name' => $relation['last_name'],
            'birth_date' => $relation['birth_date'],
            'birth_number' => $relation['birth_number'],
            // Historii pojišťoven z oznámení zapíše zákonná evidence celou; založení osoby
            // by jinak zapsalo jen poslední pojišťovnu od nástupu a historie by se nevešla.
            'health_insurer_code' => ($relation['insurer_history'] ?? []) === [] ? $relation['insurer_code'] : null,
            'relation_type' => $relation['relation_type'],
            'planned_start_on' => $relation['start'],
            'monthly_gross' => self::firstWage($relation, $ctx->endsOn()),
            'weekly_hours' => self::weeklyHours($relation, $ctx->endsOn()),
            'employment_code' => $this->employmentWriter->employmentCodeAvailable($ctx->supplierId, $number) ? $number : null,
        ];
        $attempts = [$input];
        if ($input['birth_number'] !== null) {
            $attempts[] = ['birth_number' => null] + $input;
        }
        if ($input['health_insurer_code'] !== null) {
            $attempts[] = ['birth_number' => null, 'health_insurer_code' => null] + $input;
        }
        $last = null;
        foreach ($attempts as $index => $attempt) {
            $this->db->pdo()->exec('SAVEPOINT premier_payroll_person');
            try {
                $person = $this->personCreate->create($ctx->supplierId, $attempt, $ctx->userOrNull(), null, null);
                $this->db->pdo()->exec('RELEASE SAVEPOINT premier_payroll_person');
            } catch (\InvalidArgumentException|\DomainException $e) {
                $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT premier_payroll_person');
                $this->db->pdo()->exec('RELEASE SAVEPOINT premier_payroll_person');
                $last = $e;
                continue;
            }
            if ($index > 0) {
                $dropped = $attempt['health_insurer_code'] === null && $input['health_insurer_code'] !== null ? 'rodné číslo ani kód zdravotní pojišťovny' : 'rodné číslo';
                $this->warn($ctx->protocol, 'person_partial', "Osobní číslo {$number}: {$dropped} z PREMIER neprošlo kontrolou ({$last?->getMessage()}), osoba je založená bez nich. Doplňte je na kartě.");
            }
            if ($input['employment_code'] === null) {
                $this->info($ctx->protocol, 'personal_number_taken', "Osobní číslo {$number} už ve firmě má jiná osoba, vztah dostal číslo přidělené aplikací.");
            }
            $employeeId = (int) ($person['id'] ?? 0);
            $employment = $this->latestEmployment($ctx->supplierId, $employeeId)
                ?? throw new \LogicException('Nově založený pracovní vztah nebyl nalezen.');
            return [$employeeId, (int) $employment['id']];
        }
        throw $last ?? new \LogicException('Osobu se nepodařilo založit.');
    }

    /**
     * Další vztah osoby, která už ve firmě je (souběh nebo opakovaný nástup).
     *
     * @param array<string,mixed> $relation
     */
    private function addEmployment(PremierContext $ctx, int $employeeId, array $relation): int
    {
        $number = (string) $relation['personal_number'];
        return $this->employmentWriter->addEmployment(
            $ctx->supplierId,
            $employeeId,
            (string) ($relation['full_name'] ?: 'Zaměstnanec ' . $number),
            $number,
            (string) $relation['relation_type'],
            (string) $relation['start'],
            self::firstWage($relation, $ctx->endsOn()),
            self::weeklyHours($relation, $ctx->endsOn()),
            $ctx->userOrNull(),
        );
    }

    /** @param array<string,mixed> $relation */
    private function activate(PremierContext $ctx, int $employmentId, array $relation): void
    {
        $row = $this->employmentById($ctx->supplierId, $employmentId);
        $start = (string) $relation['start'];
        if ($row === null || $row['status'] !== 'planned' || $start > date('Y-m-d')) {
            return;
        }
        $this->employments->transition($ctx->supplierId, $employmentId, 'active', (int) $row['row_version'], $start,
            self::NOTE . 'vztah vedený v PREMIER od ' . self::czechDate($start) . '.', $ctx->userOrNull(), null, null);
    }

    /**
     * Změny sjednané mzdy z historie PREMIER (`PERS_HYS`) jako verze podmínek vztahu od
     * měsíce změny. První mzdu dostal vztah při založení.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function wages(PremierContext $ctx, int $employmentId, array $relation): array
    {
        $written = 0;
        foreach ($relation['wages'] as $from => $amount) {
            if ($from > $ctx->endsOn()) {
                continue;
            }
            $row = $this->employmentById($ctx->supplierId, $employmentId);
            if ($row === null || !in_array($row['status'], ['planned', 'active', 'suspended'], true)) {
                break;
            }
            $current = $this->employments->currentTerms($ctx->supplierId, $employmentId);
            $minor = (int) round(((float) $amount) * 100);
            if ($current === null || (string) $current['effective_from'] >= $from || (int) ($current['monthly_gross_minor'] ?? 0) === $minor) {
                continue;
            }
            $terms = $this->employmentValidator->terms(
                RegistrationImportWriter::termsBody($current, 'Sjednaná mzda z PREMIER.') + ['effective_from' => (string) $from],
                $this->employments->currentCzIscoCode($ctx->supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($ctx->supplierId, $employmentId),
                $this->employments->currentRelationType($ctx->supplierId, $employmentId),
            );
            $this->employments->addTerms($ctx->supplierId, $employmentId, $terms, (int) $row['row_version'], $ctx->userOrNull(), null, null, true, $minor);
            $written++;
        }
        return $written > 0 ? ['wage_changes' => $written] : [];
    }

    /**
     * Doklady k položkám Zákonných termínů, které proběhly v PREMIER
     * ({@see PremierPayrollTakeover}), a doklad o skončení, pokud je vztah v MyÚčtu skončený.
     *
     * @param array<string,mixed> $relation
     * @param array<string,string> $notes
     * @return array<string,string>
     */
    private function checklistNotes(PremierContext $ctx, int $employmentId, array $relation, array $notes): array
    {
        $row = $this->employmentById($ctx->supplierId, $employmentId);
        if ($row !== null && $row['status'] === 'ended' && is_string($relation['end'])) {
            $notes['termination_document'] = self::NOTE . 'vztah skončil ' . self::czechDate($relation['end']) . '.';
        }
        return $notes;
    }

    /**
     * Počáteční stavy ročních kumulací za měsíce roku, ve kterém začíná vedení mezd
     * v MyÚčtu, před jeho prvním měsícem - jen souvislá řada a jen tam, kde stavy nejsou.
     *
     * @param array<int,array<string,list<array<string,mixed>>>> $byEmployee
     */
    private function openingBalances(PremierContext $ctx, array $byEmployee, PremierPayroll $payroll): void
    {
        $p = $ctx->protocol;
        $start = $this->historical->startPeriod($ctx->supplierId);
        if ($start === null) {
            $last = null;
            foreach ($payroll->relations as $relation) {
                foreach (array_keys($relation['months']) as $period) {
                    $last = $last === null || $period > $last ? (string) $period : $last;
                }
            }
            if ($last !== null && $byEmployee !== []) {
                $next = (new \DateTimeImmutable($last . '-01'))->modify('+1 month')->format('Y-m');
                $this->info($p, 'payroll_start_missing', "Firma nemá nastavený začátek vedení mezd v MyÚčtu (Mzdy → Nastavení). Poslední mzdy zpracované v PREMIER jsou za {$last}, "
                    . "začátek tedy nejspíš bude {$next}. Po jeho nastavení převod roku zopakujte: doplní počáteční stavy ročních kumulací za měsíce před ním.");
            }
            return;
        }
        $startYear = (int) substr($start, 0, 4);
        $startMonth = (int) substr($start, 5, 2);
        if ($startYear > $ctx->year) {
            return;
        }
        foreach ($byEmployee as $employeeId => $periods) {
            $months = [];
            foreach ($periods as $period => $rows) {
                if ((int) substr($period, 0, 4) !== $startYear || (int) substr($period, 5, 2) >= $startMonth) {
                    continue;
                }
                $sums = ['social' => 0.0, 'health' => 0.0, 'health_employee' => 0.0, 'health_employer' => 0.0, 'health_top_up' => 0.0, 'advance_base' => 0.0, 'advance_tax' => 0.0, 'withholding_base' => 0.0, 'withholding_tax' => 0.0,
                    'non_refundable' => 0.0, 'child' => 0.0, 'bonus' => 0.0];
                foreach ($rows as $m) {
                    $sums['social'] += $m['social_base'];
                    $sums['health'] += $m['health_base'];
                    $sums['health_employee'] += $m['employee_health'];
                    $sums['health_employer'] += $m['employer_health'];
                    $sums['health_top_up'] += $m['health_top_up'];
                    $sums['advance_base'] += $m['advance_base'];
                    $sums['advance_tax'] += $m['advance_tax'];
                    $sums['withholding_base'] += $m['withholding_base'];
                    $sums['withholding_tax'] += $m['withholding_tax'];
                    $sums['non_refundable'] += $m['non_refundable'];
                    $sums['child'] += $m['child'];
                    $sums['bonus'] += $m['tax_bonus'];
                }
                $month = (int) substr($period, 5, 2);
                $months[$month] = new PayrollTakeoverOpeningMonth(
                    month: $month,
                    socialBase: self::minor($sums['social']),
                    advanceBase: self::minor($sums['advance_base']),
                    advanceTax: self::minor($sums['advance_tax']),
                    withholdingBase: self::minor($sums['withholding_base']),
                    withholdingTax: self::minor($sums['withholding_tax']),
                    nonRefundableCredits: self::minor($sums['non_refundable']),
                    childCredit: self::minor($sums['child']),
                    taxBonus: self::minor($sums['bonus']),
                    healthBase: self::minor($sums['health']),
                    healthEmployee: self::minor($sums['health_employee']),
                    healthEmployer: self::minor($sums['health_employer']),
                    healthTopUp: self::minor($sums['health_top_up']),
                );
            }
            if ($months === []) {
                continue;
            }
            ksort($months);
            $this->inSavepoint($ctx, ['personal_number' => (string) $employeeId], function () use ($ctx, $employeeId, $months, $startYear, $startMonth): void {
                // Stavy zadané jinak než tímto převodem (ručně, z hlášení) převod nepřepisuje.
                // Vlastní stavy uloží znovu: shodná čísla nic nezapíšou, změněná záloha je
                // opraví novou verzí.
                $status = $this->people->openingBalances($ctx->supplierId, $employeeId, $startYear, $startMonth, $months, $ctx->userOrNull(), PremierPayrollTakeover::policy());
                match ($status) {
                    PayrollTakeoverPersonWriter::OPENINGS_GAP => $this->warn($ctx->protocol, 'openings_gap', "Zaměstnanec {$employeeId}: mzdy v PREMIER před začátkem vedení mezd nejsou za souvislou řadu měsíců, počáteční stavy kumulací zadejte ručně."),
                    PayrollTakeoverPersonWriter::OPENINGS_WRITTEN => $ctx->protocol->count(self::STEP, 'openings'),
                    PayrollTakeoverPersonWriter::OPENINGS_EXISTING, PayrollTakeoverPersonWriter::OPENINGS_UNCHANGED => $ctx->protocol->count(self::STEP, 'openings_existing'),
                    default => null,
                };
            });
        }
    }

    /** Mzdy roku převodu proti deníku PREMIER. */
    private function reconcile(PremierContext $ctx, PremierPayroll $payroll): void
    {
        $p = $ctx->protocol;
        $months = PremierPayroll::reconcile($payroll->monthTotals($ctx->year), PremierPayroll::ledgerTotals($ctx->journal, $ctx->year));
        if ($months === []) {
            return;
        }
        $p->set('payroll_reconciliation', [['year' => $ctx->year, 'ok' => array_filter($months, static fn (array $m): bool => !$m['ok']) === [], 'months' => $months]]);
        $p->setCount(self::STEP, 'reconciled_months', count($months));
        $labels = ['gross' => 'hrubé příjmy', 'employee_insurance' => 'pojistné zaměstnance', 'employer_insurance' => 'pojistné zaměstnavatele', 'tax' => 'daň'];
        $diffs = 0;
        foreach ($months as $m) {
            if ($m['ok']) {
                continue;
            }
            $diffs++;
            $parts = [];
            foreach ($m['diffs'] as $key => $difference) {
                $parts[] = sprintf('%s o %s Kč (mzdy %s, deník %s)', $labels[$key], self::money($difference), self::money($m['payroll'][$key]), self::money($m['ledger'][$key]));
            }
            $this->warn($p, 'payroll_ledger_diff', "{$m['period']}: mzdy PREMIER a deník se liší - " . implode(', ', $parts) . '.', ['period' => $m['period']]);
        }
        if ($diffs > 0) {
            $p->setCount(self::STEP, 'reconciliation_diffs', $diffs);
            return;
        }
        $p->info(self::STEP, 'payroll_reconciled', sprintf('Mzdy roku %d sedí na deník v %d měsících: hrubé příjmy, pojistné zaměstnance i zaměstnavatele a daň.', $ctx->year, count($months)));
    }

    /**
     * @param array<string,mixed> $relation
     * @param array<string,mixed> $m
     */
    private static function referenceTotals(array $relation, string $period, array $m, int $employeeId, int $employmentId, ?string $activity): PayrollMigrationReferenceTotals
    {
        $end = is_string($relation['end']) && $relation['end'] >= (string) $relation['start'] ? $relation['end'] : null;
        return PayrollMigrationReferenceTotals::fromAmounts(
            $period,
            'premier:' . $relation['person_key'],
            'premier:' . $relation['key'],
            $employeeId,
            $employmentId,
            $m,
            PayrollMigrationTakeoverFacts::fromMonth($m, $relation['start'], $end, $relation['relation_type'], $activity),
        );
    }


    /**
     * Stanovená týdenní pracovní doba vztahu: úvazek z první verze `PERS_HYS`, jinak
     * z formuláře JMHZ (`X10261`). Bez údaje zůstane výchozí doba založení.
     *
     * @param array<string,mixed> $relation
     */
    private static function weeklyHours(array $relation, string $until): ?string
    {
        foreach ((array) ($relation['working_time'] ?? []) as $from => $time) {
            if ($from <= $until) {
                return sprintf('%.2f', $time['weekly']);
            }
        }
        $jmhz = $relation['registry']['jmhz']['weekly_hours'] ?? null;
        return is_float($jmhz) || is_int($jmhz) ? sprintf('%.2f', $jmhz) : null;
    }

    /** @param array<string,mixed> $relation */
    private static function firstWage(array $relation, string $until): ?int
    {
        foreach ($relation['wages'] as $from => $amount) {
            if ($from <= $until) {
                return (int) round((float) $amount);
            }
        }
        return null;
    }

    /**
     * Každá osoba ve vlastním savepointu: chyba jedné osoby zbytek nezastaví.
     *
     * @template T
     * @param array<string,mixed> $relation
     * @param callable():T $work
     * @return T|null
     */
    private function inSavepoint(PremierContext $ctx, array $relation, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $result = $work();
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            return $result;
        } catch (PremierException|\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            $ctx->protocol->count(self::STEP, 'failed');
            $this->warn($ctx->protocol, 'person_failed', "Osobní číslo {$relation['personal_number']}: zaměstnance se nepodařilo převést - {$e->getMessage()}");
            return null;
        }
    }

    /** Údaj karty ve vlastním savepointu: když neprojde kontrolou, zbytek se zapíše. */
    private function detail(PremierContext $ctx, string $number, string $label, callable $work): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT premier_payroll_detail');
        try {
            foreach ($work() as $key => $count) {
                $ctx->protocol->count(self::STEP, (string) $key, $count);
            }
            $pdo->exec('RELEASE SAVEPOINT premier_payroll_detail');
        } catch (\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT premier_payroll_detail');
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT premier_payroll_detail');
            $pdo->exec('RELEASE SAVEPOINT premier_payroll_detail');
            $ctx->protocol->count(self::STEP, 'details_failed');
            $this->warn($ctx->protocol, 'detail_failed', "Osobní číslo {$number}: {$label} se nepřevzal - {$e->getMessage()}");
        }
    }

    /** @return array{id:int,employee_id:int,status:string,row_version:int}|null */
    private function employmentById(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id, status, row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'employee_id' => (int) $row['employee_id'], 'status' => (string) $row['status'], 'row_version' => (int) $row['row_version']];
    }

    /** @return array<string,mixed>|null */
    private function latestEmployment(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function activityCode(int $supplierId, int $employmentId): ?string
    {
        $terms = $this->employments->currentTerms($supplierId, $employmentId);
        $code = is_array($terms) ? trim((string) ($terms['activity_code'] ?? '')) : '';
        return $code === '' ? null : $code;
    }

    /** @param array<string,mixed> $context */
    private function warn(ImportProtocol $p, string $code, string $text, array $context = []): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $p->warn(self::STEP, $code, $text, $context);
            return;
        }
        $p->count(self::STEP, 'messages_truncated');
        $p->finish(self::STEP, 'warning');
    }

    private function info(ImportProtocol $p, string $code, string $text): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $p->info(self::STEP, $code, $text);
            return;
        }
        $p->count(self::STEP, 'messages_truncated');
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private static function czechDate(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->format('j. n. Y');
    }
}
