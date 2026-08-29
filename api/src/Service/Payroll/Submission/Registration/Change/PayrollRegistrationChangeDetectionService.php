<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentitySnapshotRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyKind;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollEmployeeRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use Psr\Clock\ClockInterface;

/**
 * Detekce změn hlásitelných do registru pojištěnců.
 *
 * ## Proč to vzniklo
 *
 * Registrační události A2–A8 uměla aplikace jen SCHVÁLIT — někdo musel vědět,
 * že se něco změnilo, a přijít to zadat. Nic nesledovalo, že se údaj rozešel
 * s tím, co bylo nahlášeno; jediná existující připomínka byla položka
 * checklistu `social_jmhz_change`, tedy to-do pro člověka, ne podání. Kdo
 * změnil adresu nebo druh činnosti, se od aplikace nedozvěděl nic — a lhůta
 * osmi dnů (§ 19 odst. 5 zákona č. 323/2025 Sb.) přitom běžela.
 *
 * ## Kdy se detekce spouští
 *
 * TAHEM, ne zápisovým hákem. Rozvaha mezi variantami:
 *
 * - **hák u každého zápisu** by musel být v každé cestě, která hlásitelný údaj
 *   mění (rychlá editace osoby, změna podmínek, uložení profilu A1, importy,
 *   opravné migrace). Kterákoliv zapomenutá cesta = tiše nedetekovaná změna,
 *   a to je přesně ta chyba, kterou má detekce odstranit;
 * - **porovnání v SQL** nejde: obě strany porovnání jsou kontextově šifrované
 *   (`enc:v2:`) a chráněné klíčovaným otiskem, takže diff musí proběhnout nad
 *   dešifrovaným obsahem v aplikaci;
 * - **tah** přepočítá z obou autoritativních pramenů, takže se nemá jak
 *   rozejít. Jeho jediná nevýhoda — cena dešifrování — je vyřešená vodoznakem
 *   ({@see PayrollRegistrationChangeProposalRepository::staleEmployments()}):
 *   firma s pěti sty zaměstnanci zaplatí jeden SQL dotaz a dešifruje jen ty
 *   vztahy, u kterých se zdroj opravdu pohnul.
 *
 * ## Jak se zabrání opakovaným návrhům
 *
 * Návrh je jednoznačně určený otiskem AKTUÁLNÍHO stavu (`current_fingerprint`)
 * a unikátním klíčem v databázi. Dokud se stav nezmění, druhý návrh na tutéž
 * změnu vzniknout nemůže. Když se stav pohne dál, starý otevřený návrh se
 * uzavře jako `superseded` — ne smaže: lhůta, která existovala, má zůstat
 * dohledatelná.
 *
 * ## Zdravotní pojišťovna je past
 *
 * Kód pojišťovny (atribut 10102) je v OBOU světech: patří do A3 a zároveň se
 * podle § 10 odst. 1 písm. b) zákona č. 48/1997 Sb. hlásí oběma pojišťovnám
 * (kód O u původní, P u nové), rovněž do osmi dnů. JMHZ tuhle povinnost
 * NENAHRAZUJE, proto z jedné změny vzniknou DVĚ povinnosti, každá s vlastním
 * termínem.
 */
final readonly class PayrollRegistrationChangeDetectionService
{
    public const SCHEMA_REFERENCE = 'payroll-registration-change-findings.v1';

    public const DUTY_REGISTRATION = 'regzec_change';
    public const DUTY_HEALTH_INSURER = 'health_insurer_change';

    /**
     * Druhy povinnosti, které detekce umí vyrobit. Klientský union se s tímhle
     * seznamem páruje; kdyby přibyl třetí druh jen na backendu, UI by o něm
     * mlčelo a povinnost by nikdo neviděl.
     *
     * @var list<string>
     */
    public const DUTY_KINDS = [
        self::DUTY_REGISTRATION,
        self::DUTY_HEALTH_INSURER,
    ];

    /** Kolik vztahů smí jeden hromadný průchod porovnat. */
    public const SWEEP_LIMIT = 200;

    private const HEALTH_INSURER_SOURCE =
        '§ 10 odst. 1 písm. b) zákona č. 48/1997 Sb.';

    public function __construct(
        private PayrollRegistrationChangeProposalRepository $proposals,
        private PayrollRegistrationIdentitySnapshotRepository $snapshots,
        private PayrollRegistrationIdentitySnapshotService $snapshotReader,
        private PayrollRegistrationIdentityService $identities,
        private PayrollRegistrationEventService $events,
        private PayrollRegistrationChangeDetector $detector,
        private PayrollRegistrationChangeDeltaPlanner $planner,
        private PayrollRegistrationReportableProfileBuilder $profiles,
        private PayrollEmployeeRegistrationDeadlinePolicy $registrationDeadlines,
        private HealthNotificationDeadlinePolicy $healthDeadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * Přepočítá stav jednoho vztahu a vrátí jeho otevřené povinnosti.
     *
     * @return array<string,mixed>
     */
    public function detect(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $this->assertScope($supplierId, $environment, $employmentId);

        return $this->proposals->transaction(
            fn (): array => $this->detectLocked(
                $supplierId,
                $environment,
                $employmentId,
            ),
        );
    }

    /**
     * Hromadný průchod za celou firmu. Porovná jen vztahy, u kterých se od
     * posledního porovnání pohnul zdroj hlásitelných údajů.
     *
     * @return array{scanned:int,changed:int,skipped:int}
     */
    public function sweep(
        int $supplierId,
        string $environment,
        int $limit = self::SWEEP_LIMIT,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma detekce není platná.');
        }
        $this->assertEnvironment($environment);
        $scanned = 0;
        $changed = 0;
        $skipped = 0;
        foreach ($this->proposals->staleEmployments(
            $supplierId,
            $environment,
            $limit,
        ) as $candidate) {
            ++$scanned;
            try {
                $result = $this->proposals->transaction(
                    fn (): array => $this->detectLocked(
                        $supplierId,
                        $environment,
                        $candidate['employment_id'],
                    ),
                );
            } catch (\Throwable) {
                // Jeden nečitelný vztah nesmí shodit přehled termínů celé
                // firmy. Vodoznak se u něj neuloží, takže se příště zkusí
                // znovu — a jeho případná povinnost se neztratí tím, že by
                // se tvářil jako porovnaný.
                ++$skipped;
                continue;
            }
            if ($result['proposals'] !== []) {
                ++$changed;
            }
        }

        return ['scanned' => $scanned, 'changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * Podá navrženou změnu jako neměnnou registrační událost A3.
     *
     * Jedno kliknutí, žádný formulář a žádné čtyři oči: obsah události je
     * plně odvozený z porovnání, takže není co doplňovat a není koho volat.
     * Existující tok se dodržuje — vzniká schválený neměnný zdroj, ze kterého
     * se teprve samostatným krokem připraví podání.
     *
     * @return array<string,mixed>
     */
    public function file(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $proposalId,
        int $userId,
    ): array {
        $this->assertScope($supplierId, $environment, $employmentId);
        if ($proposalId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Rozsah návrhu není platný.');
        }

        return $this->proposals->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $proposalId,
            $userId,
        ): array {
            $stored = $this->proposals->find(
                $supplierId,
                $environment,
                $proposalId,
                true,
            );
            if ($stored === null
                || (int) $stored['employment_id'] !== $employmentId
            ) {
                throw new \OutOfBoundsException(
                    'Návrh registrační povinnosti nebyl nalezen.',
                );
            }
            if ((string) $stored['status'] !== 'open') {
                throw new \DomainException(
                    'Návrh registrační povinnosti už je uzavřený.',
                );
            }
            if ((string) $stored['duty_kind'] !== self::DUTY_REGISTRATION
                || (int) $stored['action_code']
                    !== PayrollRegistrationReportableCatalog::ACTION_CHANGE
            ) {
                throw new PayrollRegistrationXmlException(
                    'registration_change_duty_not_fileable',
                    'Tuhle povinnost aplikace registrační akcí A3 nepodává; uzavřete ji ručně.',
                );
            }
            // Před podáním se stav přepočítá znovu. Mezi detekcí a kliknutím
            // mohl někdo údaj vrátit nebo změnit dál — schválit neměnný zdroj
            // podle zastaralého porovnání by znamenalo nahlásit něco, co
            // v datech není.
            $fresh = $this->detectLocked($supplierId, $environment, $employmentId);
            $match = null;
            foreach ($fresh['proposals'] as $candidate) {
                if ((int) $candidate['id'] === $proposalId) {
                    $match = $candidate;
                    break;
                }
            }
            if ($match === null) {
                throw new \DomainException(
                    'Navržená změna už neodpovídá aktuálním údajům; načtěte detekci znovu.',
                );
            }
            if ($match['unsupported'] !== []) {
                throw new PayrollRegistrationXmlException(
                    'registration_change_payload_unsupported',
                    'Část změněných údajů datová věta A3 v tomhle jádru nenese; návrh uzavřete ručně.',
                );
            }
            if ($match['changes'] === []) {
                throw new PayrollRegistrationXmlException(
                    'registration_change_payload_empty',
                    'Z navržené změny nevznikl žádný podávaný údaj.',
                );
            }
            $event = $this->events->approve(
                $supplierId,
                $environment,
                $employmentId,
                [
                    'interaction' => 'change',
                    'effective_on' => (string) $stored['detected_on'],
                    'source_reference' => 'change-detection:' . $proposalId,
                    'changes' => $match['changes'],
                ],
                $userId,
            );
            $this->proposals->resolve(
                $supplierId,
                $environment,
                $proposalId,
                'filed',
                (int) $event['id'],
                $userId,
                null,
            );

            return ['event' => $event, 'proposal_id' => $proposalId, 'created' => true];
        });
    }

    /**
     * Uzavře návrh, který účetní vyřídila jinak (jiným podáním, formulářem
     * pojišťovny, opravou omylem zadaného údaje).
     *
     * Poznámka je povinná. Nesplněná zákonná lhůta, která zmizí bez důvodu,
     * je horší než nesplněná lhůta, která je vidět.
     *
     * @return array<string,mixed>
     */
    public function dismiss(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $proposalId,
        int $userId,
        string $note,
    ): array {
        $this->assertScope($supplierId, $environment, $employmentId);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new \InvalidArgumentException(
                'Důvod ručního vyřízení musí mít 1 až 500 znaků.',
            );
        }
        $resolved = $this->proposals->resolve(
            $supplierId,
            $environment,
            $proposalId,
            'dismissed',
            null,
            $userId,
            $note,
        );
        if (!$resolved) {
            throw new \DomainException(
                'Návrh registrační povinnosti nebyl nalezen nebo už je uzavřený.',
            );
        }

        return ['proposal_id' => $proposalId, 'status' => 'dismissed'];
    }

    /** @return array<string,mixed> */
    private function detectLocked(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $today = $this->today();
        $watermark = $this->proposals->watermark($supplierId, $employmentId);
        $context = $this->proposals->employmentContext(
            $supplierId,
            $employmentId,
            $today,
        );
        if ($context === null) {
            throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
        }
        $employeeId = (int) $context['employee_id'];
        $open = $this->proposals->openForEmployment(
            $supplierId,
            $environment,
            $employmentId,
            true,
        );

        $baselineRow = $this->snapshots->latestSubmittedForEmployment(
            $supplierId,
            $environment,
            $employmentId,
        );
        if ($baselineRow === null) {
            // Registrace ještě neodešla, takže není co „změnit oproti
            // nahlášenému". A3 hlásí změnu ÚDAJE, který už úřad má.
            $this->proposals->rememberScan(
                $supplierId,
                $environment,
                $employmentId,
                $watermark,
                null,
            );

            return $this->result(
                [],
                $open,
                'registration_not_submitted_yet',
                $today,
            );
        }
        $baselineSnapshot = $this->snapshotReader->sensitiveSnapshot(
            $supplierId,
            (int) $baselineRow['id'],
            $environment,
        );
        $baseline = $this->profiles->build(
            $this->object($baselineSnapshot, 'identity'),
            $this->identifiers($baselineSnapshot),
            $this->optionalObject($baselineSnapshot, 'regzec_a1'),
        );
        $live = $this->identities->sensitiveIdentityAt(
            $supplierId,
            $employeeId,
            $today,
        );
        $current = $this->profiles->build(
            $live['identity'],
            $live['identifiers'],
            $this->identities->a1Profile($supplierId, $employmentId),
            $this->overlay($context),
        );
        $findings = $this->detector->compare($baseline, $current);

        $created = [];
        foreach ($this->group($findings) as $duty) {
            $plan = $duty['duty_kind'] === self::DUTY_REGISTRATION
                && $duty['action_code']
                    === PayrollRegistrationReportableCatalog::ACTION_CHANGE
                ? $this->planner->plan($duty['findings'], $current, $today)
                : ['changes' => [], 'unsupported' => []];
            $deadline = $this->deadline($duty, $today, $context);
            $stored = $this->proposals->insert([
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'environment' => $environment,
                'duty_kind' => $duty['duty_kind'],
                'action_code' => $duty['action_code'],
                'baseline_fingerprint' => $baseline->fingerprint(),
                'current_fingerprint' => $this->dutyFingerprint(
                    $duty,
                    $current->fingerprint(),
                ),
                'detected_on' => $today,
                'due_on' => $deadline['due_on'],
                'deadline_ruleset_id' => $deadline['ruleset_id'],
                'deadline_source' => $deadline['source'],
                'findings_json' => CanonicalJson::encode([
                    'schema_reference' => self::SCHEMA_REFERENCE,
                    'findings' => array_map(
                        static fn (PayrollRegistrationChangeFinding $f): array
                            => $f->toArray(),
                        $duty['findings'],
                    ),
                    'unsupported' => $plan['unsupported'],
                    'without_baseline' => array_keys(
                        PayrollRegistrationReportableCatalog::WITHOUT_BASELINE,
                    ),
                ]),
            ]);
            $created[] = [
                'id' => (int) $stored['row']['id'],
                'duty_kind' => $duty['duty_kind'],
                'action_code' => $duty['action_code'],
                'status' => (string) $stored['row']['status'],
                'detected_on' => (string) $stored['row']['detected_on'],
                'due_on' => (string) $stored['row']['due_on'],
                'deadline_source' => (string) $stored['row']['deadline_source'],
                'deadline_ruleset_id' => (string) $stored['row']['deadline_ruleset_id'],
                'findings' => array_map(
                    static fn (PayrollRegistrationChangeFinding $f): array
                        => $f->toArray(),
                    $duty['findings'],
                ),
                'changes' => $plan['changes'],
                'unsupported' => $plan['unsupported'],
                'fileable' => $plan['changes'] !== [] && $plan['unsupported'] === [],
                'created' => $stored['created'],
            ];
        }
        $this->proposals->rememberScan(
            $supplierId,
            $environment,
            $employmentId,
            $watermark,
            (int) $baselineRow['id'],
        );

        return $this->result($created, $open, null, $today);
    }

    /**
     * Uzavře otevřené návrhy, které aktuálnímu stavu už neodpovídají,
     * a vrátí odpověď detekce.
     *
     * @param list<array<string,mixed>> $created
     * @param list<array<string,mixed>> $open
     * @return array<string,mixed>
     */
    private function result(
        array $created,
        array $open,
        ?string $reasonCode,
        string $today,
    ): array {
        $keep = [];
        foreach ($created as $proposal) {
            $keep[(int) $proposal['id']] = true;
        }
        foreach ($open as $row) {
            $id = (int) $row['id'];
            if (isset($keep[$id])) {
                continue;
            }
            $this->proposals->supersede(
                (int) $row['supplier_id'],
                (string) $row['environment'],
                $id,
            );
        }

        return [
            'as_of' => $today,
            'reason_code' => $reasonCode,
            'proposals' => $created,
            'without_baseline' => PayrollRegistrationReportableCatalog::WITHOUT_BASELINE,
        ];
    }

    /**
     * Rozdělení nálezů na jednotlivé povinnosti.
     *
     * Změny podávané jednou akcí patří do JEDNOHO podání (datová věta A3 má
     * jedno datum účinnosti pro celou dávku), zato akce A6 a A7 mají vlastní
     * událost — a změna pojišťovny navíc vyrábí druhou, nesouvisející
     * povinnost vůči pojišťovnám.
     *
     * @param list<PayrollRegistrationChangeFinding> $findings
     * @return list<array{
     *   duty_kind:string,action_code:?int,
     *   findings:list<PayrollRegistrationChangeFinding>
     * }>
     */
    private function group(array $findings): array
    {
        $byAction = [];
        $insurerChange = null;
        foreach ($findings as $finding) {
            $byAction[$finding->actionCode][] = $finding;
            if ($finding->path === 'health_insurance_code') {
                $insurerChange = $finding;
            }
        }
        ksort($byAction, SORT_NUMERIC);
        $duties = [];
        foreach ($byAction as $actionCode => $group) {
            $duties[] = [
                'duty_kind' => self::DUTY_REGISTRATION,
                'action_code' => (int) $actionCode,
                'findings' => $group,
            ];
        }
        if ($insurerChange !== null) {
            $duties[] = [
                'duty_kind' => self::DUTY_HEALTH_INSURER,
                'action_code' => null,
                'findings' => [$insurerChange],
            ];
        }

        return $duties;
    }

    /**
     * Otisk povinnosti. Kromě stavu vztahu nese i druh a akci, aby dvě
     * povinnosti z TÉŽE změny (registr + pojišťovna) nekolidovaly na
     * unikátním klíči.
     *
     * @param array{duty_kind:string,action_code:?int,findings:list<PayrollRegistrationChangeFinding>} $duty
     */
    private function dutyFingerprint(array $duty, string $stateFingerprint): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => self::SCHEMA_REFERENCE,
            'duty_kind' => $duty['duty_kind'],
            'action_code' => $duty['action_code'],
            'state' => $stateFingerprint,
            'paths' => array_map(
                static fn (PayrollRegistrationChangeFinding $f): string => $f->path,
                $duty['findings'],
            ),
        ]));
    }

    /**
     * @param array{duty_kind:string,action_code:?int,findings:list<PayrollRegistrationChangeFinding>} $duty
     * @param array<string,mixed> $context
     * @return array{due_on:string,ruleset_id:string,source:string}
     */
    private function deadline(array $duty, string $today, array $context): array
    {
        if ($duty['duty_kind'] === self::DUTY_HEALTH_INSURER) {
            $window = $this->healthDeadlines->forNotification(
                HealthNotificationDutyKind::InsurerChange,
                $today,
                is_string($context['relation_type'] ?? null)
                    ? $context['relation_type']
                    : null,
            );

            return [
                'due_on' => $window->dueOn,
                'ruleset_id' => $window->rulesetId,
                'source' => self::HEALTH_INSURER_SOURCE,
            ];
        }
        $window = $this->registrationDeadlines->forFollowUp(
            (int) $duty['action_code'],
            $today,
        );

        return [
            'due_on' => $window->dueOn,
            'ruleset_id' => $window->rulesetId,
            'source' => '§ 19 odst. 5 zákona č. 323/2025 Sb.',
        ];
    }

    /**
     * Živý překryv: druh výdělečné činnosti a bližší určení vztahu se po
     * přihlášení mění v podmínkách vztahu, ne v profilu A1.
     *
     * @param array<string,mixed> $context
     * @return array<string,?string>
     */
    private function overlay(array $context): array
    {
        $overlay = [];
        foreach ([
            'employment.activity_code' => 'activity_code',
            'employment.relationship_detail_code' => 'jmhz_relationship_detail_code',
        ] as $path => $column) {
            $value = $context[$column] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $overlay[$path] = trim($value);
            }
        }

        return $overlay;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{birth_number:?string,ecp:?string,vcp:?string}
     */
    private function identifiers(array $snapshot): array
    {
        $stored = $this->object($snapshot, 'identifiers');
        $result = [];
        foreach (['birth_number', 'ecp', 'vcp'] as $field) {
            $value = $stored[$field] ?? null;
            $result[$field] = is_string($value) ? $value : null;
        }

        /** @var array{birth_number:?string,ecp:?string,vcp:?string} $result */
        return $result;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function object(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>|null
     */
    private function optionalObject(array $source, string $key): ?array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    private function assertScope(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): void {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah detekce registračních změn není platný.',
            );
        }
        $this->assertEnvironment($environment);
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí detekce musí být production nebo test.',
            );
        }
    }

    private function today(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }
}
