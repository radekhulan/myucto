<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollOfficeRegistrationRepository;
use MyInvoice\Repository\Payroll\PayrollStateConflictException;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\PayrollEmployerLegacyIdentifierCarryOver;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\SupportMatrix;
use PDO;

/**
 * Zapnutí mezd firmě, u které převod z jiného programu zjistil, že mzdy vede.
 *
 * Společné pro všechny převody (PAMICA, PREMIER, Money S3). Dřív převod mzdy
 * přeskočil, dokud účetní ručně nezapnula modul a nezaložila mzdovou účtárnu,
 * a pak ho musela spustit znovu. Převod teď udělá totéž, co by udělala ona:
 *
 *  1. zapne modul Mzdy (`supplier.payroll_enabled`) stejnou licenční branou jako
 *     Nastavení → Moduly ({@see LicenseCapacityGate::mutateSeats()}),
 *  2. nastaví začátek vedení mezd v MyÚčtu na měsíc po posledních převzatých mzdách
 *     (stav modulu `setup`, jako uložení v Mzdy → Nastavení → Aktivace),
 *  3. když firma nastavení zaměstnavatele nemá, založí ho s výchozí mzdovou účtárnou
 *     a výchozími předkontacemi ({@see PayrollEmployerSettingsRepository::save()}),
 *  4. převezme z Nastavení firmy VS ČSSZ, kód OSSZ a číslo plátce zdravotního
 *     pojištění do prázdných míst v Mzdách ({@see PayrollEmployerLegacyIdentifierCarryOver}).
 *
 * ── Co se nikdy nepřepisuje ─────────────────────────────────────────────────
 * Zapnutý modul, existující stav modulu (i vědomě vypnutý) a jeho začátek, ani
 * existující nastavení zaměstnavatele. Převod doplňuje jen to, co chybí.
 *
 * ── Co se nevymýšlí ─────────────────────────────────────────────────────────
 * Variabilní symbol ČSSZ, kód OSSZ ani účty institucí: jsou to údaje z rozhodnutí
 * úřadů a zástupná hodnota by prošla do přehledů a plateb. Převezme se jen to, co
 * firma už má v Nastavení firmy; zbytek protokol vypíše k doplnění.
 *
 * ── Registrace účtárny ──────────────────────────────────────────────────────
 * Mzdový běh potřebuje k VS ČSSZ i datum, od kdy platí. Převod ho zná jen v podobě
 * začátku vedení mezd v MyÚčtu: dřívější měsíce MyÚčto nepočítá, takže registrace
 * od tohoto dne běhy odblokuje a nic nezkreslí. Založí ji jen k desetimístnému VS
 * a jen když účtárna registraci ještě nemá; skutečné datum registrace u ČSSZ může
 * účetní opravit (nejnovější verzi lze smazat a zadat znovu).
 *
 * ── Firma, která mzdy už nevede ─────────────────────────────────────────────
 * Poslední mzdy víc než rok před koncem převáděných dat znamenají, že firma
 * zaměstnance neměla ani v posledním roce. Modul (a s ním licenční místo) se jí
 * nezapíná; protokol to řekne.
 */
final class PayrollMigrationModuleSetup
{
    public const OFFICE_CODE = 'MZDY';
    private const OFFICE_NAME = 'Mzdová účtárna';
    /** O kolik měsíců smí poslední mzdy předcházet konci převáděných dat, aby firma mzdy „vedla". */
    private const ENDED_AFTER_MONTHS = 12;

    public const OUTCOME_READY = 'ready';
    public const OUTCOME_ENDED = 'ended';
    public const OUTCOME_UNLICENSED = 'unlicensed';
    public const OUTCOME_LICENSE_LIMIT = 'license_limit';
    public const OUTCOME_UNAVAILABLE = 'unavailable';

    /** Údaje, které převod nevymýšlí a účetní je musí doplnit. */
    public const TODO_SOCIAL_SECURITY_SYMBOL = 'social_security_variable_symbol';
    public const TODO_SOCIAL_SECURITY_REGISTRATION = 'social_security_registration';
    public const TODO_SOCIAL_SECURITY_OFFICE = 'social_security_office_code';
    public const TODO_INSTITUTION_ACCOUNTS = 'institution_accounts';
    public const TODO_START_PERIOD = 'start_period';

    /** Původ registrace účtárny založené převodem (sloupec `source_reference`). */
    public const REGISTRATION_SOURCE = 'Převod mezd z jiného programu: účinnost od začátku vedení mezd v MyÚčtu';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollModuleAccess $access,
        private readonly LicenseCapacityGate $capacity,
        private readonly PayrollModuleStateRepository $state,
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly SupportMatrix $support,
        private readonly PayrollEmployerLegacyIdentifierCarryOver $identifiers,
        private readonly PayrollOfficeRegistrationRepository $registrations,
    ) {}

    /**
     * Co by převod udělal - nic nezapisuje (kontrola před převodem).
     *
     * @param string $lastPayrollPeriod poslední měsíc mezd ve zdroji (`YYYY-MM`)
     * @param ?string $lastDataPeriod poslední měsíc převáděných dat (`YYYY-MM`); bez něj
     *        se konec mezd neposuzuje
     * @return array{outcome:string,enable:bool,create_office:bool,start_period:?string,start_unsupported:?string,last_period:string}
     */
    public function plan(int $supplierId, string $lastPayrollPeriod, ?string $lastDataPeriod = null): array
    {
        $last = self::period($lastPayrollPeriod);
        $plan = [
            'outcome' => self::OUTCOME_READY,
            'enable' => false,
            'create_office' => false,
            'start_period' => null,
            'start_unsupported' => null,
            'last_period' => $last,
        ];
        if (!$this->schemaAvailable()) {
            return ['outcome' => self::OUTCOME_UNAVAILABLE] + $plan;
        }
        $enabled = $this->payrollEnabled($supplierId);
        if (!$enabled && self::ended($last, $lastDataPeriod)) {
            return ['outcome' => self::OUTCOME_ENDED] + $plan;
        }
        if (!$enabled && !$this->access->isLicensed()) {
            return ['outcome' => self::OUTCOME_UNLICENSED] + $plan;
        }
        $plan['enable'] = !$enabled;
        $plan['create_office'] = !$this->hasSettings($supplierId);
        if (!$this->hasModuleState($supplierId)) {
            $start = self::startAfter($last);
            if ($this->support->supportsYear((int) substr($start, 0, 4))) {
                $plan['start_period'] = $start;
            } else {
                $plan['start_unsupported'] = $start;
            }
        }

        return $plan;
    }

    /**
     * Zapne mzdy, pokud je firma vede; nic existujícího nepřepíše.
     *
     * @return array{
     *   outcome:string,enabled_now:bool,office_created:bool,start_period:?string,start_set:bool,
     *   start_unsupported:?string,last_period:string,carried:array<string,string>,registration_from:?string,
     *   todo:list<string>
     * }
     */
    public function ensure(int $supplierId, ?int $userId, string $lastPayrollPeriod, ?string $lastDataPeriod = null): array
    {
        $plan = $this->plan($supplierId, $lastPayrollPeriod, $lastDataPeriod);
        $result = [
            'outcome' => $plan['outcome'],
            'enabled_now' => false,
            'office_created' => false,
            'start_period' => null,
            'start_set' => false,
            'start_unsupported' => $plan['start_unsupported'],
            'last_period' => $plan['last_period'],
            'carried' => [],
            'registration_from' => null,
            'todo' => [],
        ];
        if ($plan['outcome'] !== self::OUTCOME_READY) {
            return $result;
        }

        if ($plan['enable']) {
            try {
                $this->capacity->mutateSeats(fn (): bool => $this->db->pdo()
                    ->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
                    ->execute([$supplierId]));
            } catch (LicensePayrollLimitExceeded|LicenseSeatLimitExceeded) {
                return ['outcome' => self::OUTCOME_LICENSE_LIMIT] + $result;
            }
            $result['enabled_now'] = true;
        }
        if ($plan['create_office']) {
            $this->createSettings($supplierId);
            $result['office_created'] = true;
        }
        if ($plan['start_period'] !== null) {
            try {
                $this->state->setActivation($supplierId, true, $plan['start_period'] . '-01', 0, $userId);
                $result['start_set'] = true;
            } catch (PayrollStateConflictException) {
                // Stav mezitím založil někdo jiný; jeho začátek platí.
            }
        }
        $result['start_period'] = $this->state->get($supplierId)['start_period'];
        $result['carried'] = $this->identifiers->settle($supplierId, $userId)['carried'];
        $result['registration_from'] = $this->ensureOfficeRegistration($supplierId, $userId, self::REGISTRATION_SOURCE);

        if ($result['enabled_now'] || $result['office_created'] || $result['start_set'] || $result['carried'] !== []
            || $result['registration_from'] !== null
        ) {
            $result['todo'] = $this->todo($supplierId, $result['start_period']);
        }

        return $result;
    }

    /**
     * Zprávy do protokolu převodu - stejné texty pro všechny převody.
     *
     * @param array<string,mixed> $result {@see self::ensure()}
     * @param string $program název zdrojového programu do textu (`PREMIER`, `Money S3`…)
     */
    public static function report(ImportProtocol $protocol, string $step, array $result, string $program): void
    {
        $last = self::monthLabel((string) $result['last_period']);
        switch ($result['outcome']) {
            case self::OUTCOME_ENDED:
                $protocol->info($step, 'payroll_ended', sprintf(
                    'Mzdy vedla firma v %s naposledy za %s, v posledním roce převáděných dat už ne. Modul Mzdy se proto nezapnul; '
                    . 'převzaté mzdy zůstávají jen v převedeném deníku.',
                    $program,
                    $last,
                ));
                return;
            case self::OUTCOME_UNLICENSED:
                $protocol->warn($step, 'payroll_module_unlicensed', sprintf(
                    'Firma vede mzdy (v %s naposledy za %s), modul Mzdy ale nejde zapnout: licence nezahrnuje mzdový doplněk. '
                    . 'Po jeho zakoupení převod zopakujte.',
                    $program,
                    $last,
                ));
                return;
            case self::OUTCOME_LICENSE_LIMIT:
                $protocol->warn($step, 'payroll_module_license_limit', sprintf(
                    'Firma vede mzdy (v %s naposledy za %s), zapnutí modulu Mzdy by ale překročilo zaplacený počet uživatelů '
                    . 'mzdového doplňku. Navyšte rozsah doplňku a převod zopakujte.',
                    $program,
                    $last,
                ));
                return;
            case self::OUTCOME_UNAVAILABLE:
                return;
        }

        $done = [];
        if ($result['enabled_now']) {
            $done[] = 'zapnul modul Mzdy';
        }
        if ($result['office_created']) {
            $done[] = 'založil nastavení zaměstnavatele s mzdovou účtárnou ' . self::OFFICE_CODE . ' a výchozími předkontacemi';
        }
        if ($result['start_set']) {
            $done[] = 'nastavil začátek vedení mezd v MyÚčtu na ' . self::monthLabel((string) $result['start_period']);
        }
        $carriedLabels = [
            PayrollEmployerLegacyIdentifierCarryOver::SOCIAL_SECURITY_SYMBOL => 'variabilní symbol ČSSZ %s k mzdové účtárně',
            PayrollEmployerLegacyIdentifierCarryOver::SOCIAL_SECURITY_OFFICE => 'kód OSSZ %s',
            PayrollEmployerLegacyIdentifierCarryOver::HEALTH_INSURANCE_NUMBER => 'číslo plátce zdravotního pojištění %s k účtu výchozí pojišťovny',
        ];
        $carried = [];
        foreach ((array) ($result['carried'] ?? []) as $field => $value) {
            $carried[] = sprintf($carriedLabels[$field] ?? ($field . ' %s'), $value);
        }
        if ($carried !== []) {
            $done[] = 'převzal z Nastavení firmy ' . self::joinCzech($carried);
        }
        if (($result['registration_from'] ?? null) !== null) {
            $done[] = 'založil registraci mzdové účtárny u ČSSZ s účinností od ' . self::dateLabel((string) $result['registration_from'])
                . ' (začátek vedení mezd v MyÚčtu; skutečné datum registrace u ČSSZ zkontrolujte v Mzdy → Nastavení)';
        }
        if ($done !== []) {
            $protocol->count($step, 'payroll_module_setup');
            $protocol->info($step, 'payroll_module_enabled', sprintf(
                'Firma vede mzdy (v %s naposledy za %s). Převod %s.',
                $program,
                $last,
                self::joinCzech($done),
            ), ['start_period' => $result['start_period']]);
        }
        if ($result['start_unsupported'] !== null) {
            $protocol->warn($step, 'payroll_start_unsupported', sprintf(
                'Začátek vedení mezd v MyÚčtu by po posledních mzdách z %s připadl na %s, MyÚčto ale pro rok %s mzdová pravidla '
                . 'zatím nemá. Začátek se nenastavil; nastavte ho v Mzdy → Nastavení → Aktivace, až bude rok podporovaný.',
                $program,
                self::monthLabel((string) $result['start_unsupported']),
                substr((string) $result['start_unsupported'], 0, 4),
            ));
        }
        $todo = $result['todo'] ?? [];
        if ($todo !== []) {
            $labels = [
                self::TODO_SOCIAL_SECURITY_SYMBOL => 'variabilní symbol plátce pojistného ČSSZ u mzdové účtárny',
                self::TODO_SOCIAL_SECURITY_REGISTRATION => 'datum, od kdy variabilní symbol ČSSZ platí (registrace mzdové účtárny)',
                self::TODO_SOCIAL_SECURITY_OFFICE => 'kód příslušné OSSZ',
                self::TODO_INSTITUTION_ACCOUNTS => 'účty ČSSZ, zdravotních pojišťoven a finančního úřadu (Účty institucí)',
                self::TODO_START_PERIOD => 'začátek vedení mezd v MyÚčtu',
            ];
            $protocol->warn($step, 'payroll_setup_incomplete', sprintf(
                'Nastavení mezd je třeba doplnit v Mzdy → Nastavení: %s. Převod tyto údaje nevymýšlí, jsou z rozhodnutí úřadů. '
                . 'Předkontace mezd zkontrolujte podle návrhu z původního programu (Mzdy → Importy → Kontace mezd).',
                implode('; ', array_map(static fn (string $code): string => $labels[$code] ?? $code, $todo)),
            ), ['todo' => $todo]);
        }
    }

    /**
     * Doplní identifikátory zaměstnavatele u ČSSZ, které předchozí program uvedl
     * v přijatých podáních: VS ČSSZ výchozí účtárně a registrační číslo zaměstnavatele
     * (ČSSZ podle něj páruje hlášení, je to týž desetimístný symbol), kód OSSZ do nastavení
     * zaměstnavatele. Jen do prázdných míst; jinou vyplněnou hodnotu vrátí jako rozpor.
     *
     * @return array{filled:array<string,string>,conflicts:array<string,array{current:string,source:string}>,registration_from:?string}
     */
    public function fillEmployerIdentifiers(int $supplierId, ?int $userId, ?string $symbol, ?string $officeCode, string $sourceReference): array
    {
        $result = ['filled' => [], 'conflicts' => [], 'registration_from' => null];
        $symbol = PayrollEmployerLegacyIdentifierCarryOver::variableSymbol($symbol);
        $symbol = $symbol !== null && strlen($symbol) === 10 ? $symbol : null;
        $officeCode = PayrollEmployerLegacyIdentifierCarryOver::socialSecurityOfficeCode($officeCode);
        if (($symbol === null && $officeCode === null) || !$this->hasSettings($supplierId)) {
            return $result;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.social_security_variable_symbol AS symbol, s.employer_registration_number AS registration, s.social_security_office_code AS office
               FROM payroll_employer_settings s
               LEFT JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $check = static function (string $key, ?string $source) use (&$result, $current): bool {
            $value = trim((string) ($current[$key] ?? ''));
            if ($source === null) {
                return false;
            }
            if ($value !== '' && $value !== $source) {
                $result['conflicts'][$key] = ['current' => $value, 'source' => $source];
            }
            return $value === '';
        };
        if ($check('symbol', $symbol) && $this->settings->fillEmptyDefaultOfficeSocialSecuritySymbol($supplierId, (string) $symbol)) {
            $result['filled']['symbol'] = (string) $symbol;
        }
        if ($check('registration', $symbol) && $this->settings->fillEmptyEmployerRegistrationNumber($supplierId, (string) $symbol)) {
            $result['filled']['registration'] = (string) $symbol;
        }
        if ($check('office', $officeCode) && $this->settings->fillEmptySocialSecurityOfficeCode($supplierId, (string) $officeCode)) {
            $result['filled']['office'] = (string) $officeCode;
        }
        if (isset($result['filled']['symbol'])) {
            $result['registration_from'] = $this->ensureOfficeRegistration($supplierId, $userId, $sourceReference);
        }

        return $result;
    }

    /**
     * Založí registraci výchozí účtárny s účinností od začátku vedení mezd v MyÚčtu,
     * když účtárna má desetimístný VS ČSSZ a registraci ještě nemá. Volá ji převod
     * po převzetí VS i každý, kdo VS doplní až později (import hlášení JMHZ).
     *
     * @return ?string datum účinnosti založené registrace (`YYYY-MM-DD`), jinak null
     */
    public function ensureOfficeRegistration(int $supplierId, ?int $userId, string $sourceReference): ?string
    {
        if (!$this->db->hasTable('payroll_office_registration_versions') || $this->hasOfficeRegistration($supplierId)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.id, o.social_security_variable_symbol
               FROM payroll_employer_settings s
               JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $office = $stmt->fetch(PDO::FETCH_ASSOC);
        $symbol = is_array($office) ? trim((string) ($office['social_security_variable_symbol'] ?? '')) : '';
        $start = $this->state->get($supplierId)['start_period'] ?? null;
        if (preg_match('/^[0-9]{10}$/D', $symbol) !== 1 || !is_string($start) || $start === '') {
            return null;
        }
        $from = self::period($start) . '-01';
        $this->registrations->add($supplierId, (int) $office['id'], $from, $symbol, $sourceReference, $userId);

        return $from;
    }

    /**
     * Posune začátek vedení mezd za měsíce, které zpracoval předchozí program.
     *
     * Začátek nastavený dřív (starší export, ruční nastavení) mohl zůstat před
     * měsíci, které předchozí program mezitím zpracoval a podal. MyÚčto pak
     * tvrdilo, že je počítá samo, za ně ale žádný běh neexistoval a převzít je
     * nešlo (převzatý měsíc smí ležet jen před začátkem). Sám převod začátek
     * NEposouvá — může jít o vědomé rozhodnutí přepočítat podané měsíce —, jen
     * to ohlásí; posun je krok účetní z obrazovky běhů.
     *
     * Posouvá se jen DOPŘEDU, jen když každý přeskočený měsíc má úhrny z převodu
     * (předchozí program ho zpracoval) a MyÚčto za něj nemá vlastní nezrušený běh.
     *
     * @return array{from:string,to:string}
     * @throws \DomainException když posun nesplní podmínky
     */
    public function advanceStartTo(int $supplierId, ?int $userId, string $targetPeriod): array
    {
        $target = self::period($targetPeriod);
        $problem = $this->advanceStartProblem($supplierId, $target);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }
        $state = $this->state->get($supplierId);
        $from = self::period((string) $state['start_period']);
        $this->state->setActivation($supplierId, true, $target . '-01', $state['row_version'], $userId);

        return ['from' => $from, 'to' => $target];
    }

    /**
     * Proč začátek vedení mezd NEjde posunout na `$target` (`YYYY-MM`), nebo `null`,
     * když jde. Jediné pravidlo pro nabídku na obrazovce běhů i pro samotný posun.
     */
    public function advanceStartProblem(int $supplierId, string $target): ?string
    {
        $target = self::period($target);
        $state = $this->state->get($supplierId);
        $current = $state['start_period'];
        if (!is_string($current) || $current === '' || $state['status'] === 'disabled') {
            return 'Firma nemá nastavený začátek vedení mezd; nastavte ho v Mzdy → Nastavení → Aktivace.';
        }
        $from = self::period($current);
        if ($target <= $from) {
            return 'Začátek vedení mezd jde tímhle krokem jen posunout dopředu.';
        }
        if (!$this->support->supportsYear((int) substr($target, 0, 4))) {
            return 'MyÚčto pro rok ' . substr($target, 0, 4) . ' zatím nemá mzdová pravidla.';
        }
        $check = $this->startAdvance($supplierId, self::previous($target));
        if ($check['blocking_runs'] !== []) {
            return sprintf(
                'MyÚčto už má vlastní mzdové běhy za %s. Zrušte je, nebo začátek neposouvejte.',
                implode(', ', array_map(self::monthLabel(...), $check['blocking_runs'])),
            );
        }
        $missing = $this->monthsWithoutReferenceTotals($supplierId, $from, $target);
        if ($missing !== []) {
            return sprintf(
                'Za %s nemá MyÚčto mzdy z předchozího programu, takže by je po posunu nikdo nespočítal. '
                . 'Převeďte je nebo je spočítejte v MyÚčtu.',
                implode(', ', array_map(self::monthLabel(...), $missing)),
            );
        }

        return null;
    }

    /**
     * Měsíce v `[$from, $to)`, za které nejsou úhrny zpracovaných mezd z převodu.
     *
     * @return list<string>
     */
    public function monthsWithoutReferenceTotals(int $supplierId, string $from, string $to): array
    {
        if (!$this->db->hasTable('payroll_migration_reference_totals')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT DATE_FORMAT(period_start, "%Y-%m")
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND period_start >= ? AND period_start < ?'
        );
        $stmt->execute([$supplierId, $from . '-01', $to . '-01']);
        $have = array_flip(array_map(static fn (mixed $p): string => (string) $p, $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $missing = [];
        for ($cursor = $from; $cursor < $to; $cursor = self::startAfter($cursor)) {
            if (!isset($have[$cursor])) {
                $missing[] = $cursor;
            }
        }

        return $missing;
    }

    /**
     * Posun začátku vedení mezd PŘED převodem, který přebírá měsíce zpracované
     * předchozím programem až do `$lastProcessedPeriod` (volba „Posunout začátek
     * a převést" v náhledu převodu).
     *
     * Na rozdíl od {@see advanceStartTo()} nevyžaduje úhrny z převodu za přeskočené
     * měsíce: přináší je právě tenhle převod. Bez posunu by je převod bral jako
     * měsíce počítané MyÚčtem (vstupy z docházky, dohody o srážkách). Vlastní
     * nezrušený běh v přeskočených měsících posun zastaví stejně jako na obrazovce běhů.
     *
     * @return ?array{from:string,to:string} null, když začátek už za posledním zpracovaným měsícem leží
     * @throws \DomainException když MyÚčto za přeskočené měsíce už má vlastní běhy
     */
    public function advanceStartBeforeTakeover(int $supplierId, ?int $userId, string $lastProcessedPeriod): ?array
    {
        $advance = $this->startAdvance($supplierId, $lastProcessedPeriod);
        if ($advance['to'] === null || $advance['from'] === null) {
            return null;
        }
        if ($advance['blocking_runs'] !== []) {
            throw new \DomainException(sprintf(
                'MyÚčto už má vlastní mzdové běhy za %s. Zrušte je, nebo začátek neposouvejte.',
                implode(', ', array_map(self::monthLabel(...), $advance['blocking_runs'])),
            ));
        }
        $state = $this->state->get($supplierId);
        $this->state->setActivation($supplierId, true, $advance['to'] . '-01', $state['row_version'], $userId);

        return ['from' => $advance['from'], 'to' => $advance['to']];
    }

    private static function previous(string $period): string
    {
        return (new \DateTimeImmutable($period . '-01'))->modify('-1 month')->format('Y-m');
    }

    /**
     * Posun začátku vedení mezd za poslední zpracovaný měsíc — nic nezapisuje.
     * `to` = null, když začátek už za ním leží. `blocking_runs` = vlastní běhy,
     * které by posun schoval.
     *
     * @return array{from:?string,to:?string,blocking_runs:list<string>}
     */
    public function startAdvance(int $supplierId, string $lastPayrollPeriod): array
    {
        $none = ['from' => null, 'to' => null, 'blocking_runs' => []];
        if (!$this->schemaAvailable()) {
            return $none;
        }
        $state = $this->state->get($supplierId);
        $current = $state['start_period'];
        if (!is_string($current) || $current === '' || $state['status'] === 'disabled') {
            return $none;
        }
        $currentPeriod = self::period($current);
        $target = self::startAfter($lastPayrollPeriod);
        if ($target <= $currentPeriod || !$this->support->supportsYear((int) substr($target, 0, 4))) {
            return $none;
        }
        $runs = $this->db->pdo()->prepare(
            'SELECT DISTINCT DATE_FORMAT(period_start, "%Y-%m")
               FROM payroll_runs
              WHERE supplier_id = ?
                AND run_kind <> "takeover"
                AND status <> "cancelled"
                AND period_start >= ? AND period_start < ?
              ORDER BY 1'
        );
        $runs->execute([$supplierId, $currentPeriod . '-01', $target . '-01']);
        $blocking = array_map(static fn (mixed $p): string => (string) $p, $runs->fetchAll(PDO::FETCH_COLUMN));

        return ['from' => $currentPeriod, 'to' => $target, 'blocking_runs' => $blocking];
    }

    /**
     * Kam posunout začátek vedení mezd: měsíc po posledním měsíci, který
     * zpracoval předchozí program (poslední úhrny z převodu). Jediné pravidlo
     * pro obrazovku běhů i pro kontrolu převodu ({@see startAdvance()}) — dřív
     * běhy nabízely právě zobrazené období (srpen) a převod pak radil září.
     * `null`, když posun nedává smysl nebo by nesplnil podmínky.
     */
    public function suggestedStart(int $supplierId): ?string
    {
        if (!$this->schemaAvailable() || !$this->db->hasTable('payroll_migration_reference_totals')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(MAX(period_start), "%Y-%m")
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $last = $stmt->fetchColumn();
        if (!is_string($last) || $last === '') {
            return null;
        }
        $target = self::startAfter($last);

        return $this->advanceStartProblem($supplierId, $target) === null ? $target : null;
    }

    /** Měsíc po posledních převzatých mzdách (`YYYY-MM`). */
    public static function startAfter(string $lastPayrollPeriod): string
    {
        return (new \DateTimeImmutable(self::period($lastPayrollPeriod) . '-01'))->modify('+1 month')->format('Y-m');
    }

    /** Skončily mzdy víc než rok před koncem převáděných dat? */
    public static function ended(string $lastPayrollPeriod, ?string $lastDataPeriod): bool
    {
        if ($lastDataPeriod === null) {
            return false;
        }
        $limit = (new \DateTimeImmutable(self::period($lastDataPeriod) . '-01'))
            ->modify('-' . self::ENDED_AFTER_MONTHS . ' months')->format('Y-m');

        return self::period($lastPayrollPeriod) < $limit;
    }

    private function createSettings(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, name, test_social_security_variable_symbol, is_active FROM payroll_offices WHERE supplier_id = ? ORDER BY is_active DESC, id'
        );
        $stmt->execute([$supplierId]);
        $offices = [];
        $default = null;
        // Účtárny, které firma už má, se do sady vezmou beze změny: uložení nastavení
        // vypíná účtárny, které v sadě nejsou.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $offices[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'social_security_variable_symbol' => null,
                'social_security_variable_symbol_provided' => false,
                'test_social_security_variable_symbol' => $row['test_social_security_variable_symbol'] === null ? null : (string) $row['test_social_security_variable_symbol'],
                'is_active' => (bool) $row['is_active'],
            ];
            if ($default === null && (bool) $row['is_active']) {
                $default = (string) $row['code'];
            }
        }
        if ($default === null) {
            $offices = array_values(array_filter($offices, static fn (array $o): bool => $o['code'] !== self::OFFICE_CODE));
            $offices[] = [
                'code' => self::OFFICE_CODE,
                'name' => self::OFFICE_NAME,
                'social_security_variable_symbol' => null,
                'social_security_variable_symbol_provided' => false,
                'test_social_security_variable_symbol' => null,
                'is_active' => true,
            ];
            $default = self::OFFICE_CODE;
        }
        $defaults = $this->settings->get($supplierId);
        $this->settings->save($supplierId, [
            'default_office_code' => $default,
            'employer_registration_number' => null,
            'social_security_office_code' => null,
            'default_health_insurer_code' => null,
            'payroll_contact_name' => null,
            'payroll_contact_email' => null,
            'payroll_contact_phone' => null,
            'accounts' => $defaults['accounts'],
            'offices' => $offices,
        ], 0);
    }

    /** @return list<string> */
    private function todo(int $supplierId, ?string $startPeriod): array
    {
        $pdo = $this->db->pdo();
        $todo = [];
        $office = $pdo->prepare(
            'SELECT o.social_security_variable_symbol, s.social_security_office_code
               FROM payroll_employer_settings s
               JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id
              WHERE s.supplier_id = ?'
        );
        $office->execute([$supplierId]);
        $row = $office->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || trim((string) ($row['social_security_variable_symbol'] ?? '')) === '') {
            $todo[] = self::TODO_SOCIAL_SECURITY_SYMBOL;
        } elseif (!$this->hasOfficeRegistration($supplierId)) {
            // Převzatý VS nemá doloženou účinnost; bez ní mzdový běh neprojde.
            $todo[] = self::TODO_SOCIAL_SECURITY_REGISTRATION;
        }
        if (!is_array($row) || trim((string) ($row['social_security_office_code'] ?? '')) === '') {
            $todo[] = self::TODO_SOCIAL_SECURITY_OFFICE;
        }
        if ($this->db->hasTable('payroll_institution_accounts')) {
            $accounts = $pdo->prepare('SELECT COUNT(*) FROM payroll_institution_accounts WHERE supplier_id = ?');
            $accounts->execute([$supplierId]);
            if ((int) $accounts->fetchColumn() === 0) {
                $todo[] = self::TODO_INSTITUTION_ACCOUNTS;
            }
        }
        if ($startPeriod === null) {
            $todo[] = self::TODO_START_PERIOD;
        }

        return $todo;
    }

    private function hasOfficeRegistration(int $supplierId): bool
    {
        if (!$this->db->hasTable('payroll_office_registration_versions')) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employer_settings settings
               JOIN payroll_office_registration_versions version
                 ON version.supplier_id = settings.supplier_id
                AND version.office_id = settings.default_office_id
              WHERE settings.supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    private function schemaAvailable(): bool
    {
        return $this->db->hasColumn('supplier', 'payroll_enabled')
            && $this->db->hasTable('payroll_module_state')
            && $this->db->hasTable('payroll_employer_settings')
            && $this->db->hasTable('payroll_offices');
    }

    private function payrollEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function hasSettings(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employer_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Stav modulu jakýkoli, i vědomě vypnutý: rozhodnutí účetní převod nepřepisuje. */
    private function hasModuleState(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    private static function period(string $value): string
    {
        $period = substr(trim($value), 0, 7);
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException("Mzdové období musí být ve tvaru YYYY-MM: {$value}.");
        }

        return $period;
    }

    private static function dateLabel(string $date): string
    {
        return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})/', $date, $m) === 1
            ? ((int) $m[3]) . '. ' . ((int) $m[2]) . '. ' . $m[1]
            : $date;
    }

    private static function monthLabel(string $period): string
    {
        return preg_match('/^([0-9]{4})-([0-9]{2})/', $period, $m) === 1 ? ((int) $m[2]) . '/' . $m[1] : $period;
    }

    /** @param list<string> $parts */
    private static function joinCzech(array $parts): string
    {
        if (count($parts) <= 1) {
            return implode('', $parts);
        }
        $last = array_pop($parts);

        return implode(', ', $parts) . ' a ' . $last;
    }
}
