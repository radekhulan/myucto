<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Employment\PayrollRelationType;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Zákonné pojištění odpovědnosti zaměstnavatele za škodu při pracovním úrazu
 * a nemoci z povolání (vyhláška č. 125/1993 Sb.) — čtvrtletní, na rozdíl od
 * ostatních závazků v tomhle adresáři, které vznikají z JEDNÉ měsíční revize.
 *
 * Volá se stejně jako ostatní materializery — z revize schváleného měsíce —
 * ale REÁLNĚ pracuje jen tehdy, když je ten měsíc POSLEDNÍ MĚSÍC ČTVRTLETÍ
 * (březen/červen/září/prosinec). V ostatních měsících je to no-op. Kdyby
 * některý z předchozích dvou měsíců čtvrtletí ještě neměl schválenou revizi
 * s vypočteným výsledkem sociálního pojištění, materializace založí chybu —
 * NIKDY neodhaduje vyměřovací základ z neúplných dat. Výjimkou je měsíc před
 * `start_period`: ten MyÚčto nepočítalo a jeho základ nesou převzaté mzdy
 * (viz {@see self::takeoverMonthAssessmentBase()}).
 *
 * Pojištění platí jen zaměstnavatel, který měl ve čtvrtletí aspoň jeden
 * pracovněprávní vztah (pracovní poměr, DPP, DPČ). Firma, která vyplácí jen
 * jednatele nebo společníka, závazek nedostane vůbec. Volající (lenient
 * endpoint `POST /payroll/revisions/{id}/payments/liabilities`) chybu ukáže
 * jako `preparation_issue`, ne jako tvrdé selhání zbytku přípravy plateb —
 * proto se tenhle materializer záměrně NEZAPOJUJE do fail-closed
 * {@see \MyInvoice\Service\Payroll\Run\PayrollRunPaymentPreparationService},
 * která by jinak zablokovala přechod běhu do `payment_ready` u firem, které
 * ještě nemají spočítané všechny tři měsíce čtvrtletí.
 */
final class PayrollAccidentInsuranceLiabilityMaterializer
{
    private const LIABILITY_KIND = 'statutory_insurance';

    /**
     * Druh vztahu, který do základu zákonného pojištění odpovědnosti nepatří.
     * Projekt sem mapuje příjem společníka i odměnu za výkon funkce.
     */
    private const EXCLUDED_RELATIONSHIP_KIND = 'corporate_body';

    /** Otisk zdroje ze zastropovaného základu — předchází opravě výkladu § 15a. */
    private const SOURCE_SCHEMA_CAPPED = 'payroll-payment-accident-insurance-source.v1';

    /** Aktuální otisk: základ bez ročního maxima, s vlastním názvem. */
    private const SOURCE_SCHEMA_CURRENT = 'payroll-payment-accident-insurance-source.v2';

    /**
     * Schémata, která smí nést dřívější závazek v řetězu oprav. Musí tu zůstat
     * i to staré — opravná revize se dělá i po letech.
     *
     * @var list<string>
     */
    private const SOURCE_SCHEMA_REFERENCES = [
        self::SOURCE_SCHEMA_CAPPED,
        self::SOURCE_SCHEMA_CURRENT,
    ];

    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollAccidentInsuranceRateRepository $rates,
        private readonly PayrollInstitutionPaymentTargetResolver $targets,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly Connection $db,
        private readonly PayrollLevyDeadlinePolicy $deadlines,
        private readonly PayrollAccidentInsuranceCalculator $calculator,
        private readonly PayrollAccidentInsurancePosting $posting,
        private readonly PayrollHistoricalPeriodService $historical,
        private readonly PayrollTakeoverReader $takeovers,
    ) {}

    /**
     * `posting` nese výsledek předpisu do deníku ({@see PayrollAccidentInsurancePosting}):
     * `null`, když žádný řádek závazku nevznikl ani nepřehrál.
     *
     * `warnings` jsou upozornění k závazku, které přípravu nezastavují
     * (typicky chybějící základ za převzatý měsíc).
     *
     * @return array{
     *   liability_ids:list<int>,
     *   created_count:int,
     *   warnings?:list<string>,
     *   posting?:array{status:string,journal_entry_id:?int,reason:?string}|null
     * }
     */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize zákonného pojištění odpovědnosti musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace zákonného pojištění odpovědnosti není platný.',
            );
        }

        return $this->liabilities->transaction(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): array {
            $revision = $this->liabilities->lockRevision($supplierId, $revisionId);
            if ($revision === null) {
                throw new PayrollPaymentPreparationException('revision_missing', 'Mzdová revize neexistuje.');
            }
            if (($revision['revision_status'] ?? null) !== 'approved'
                || ($revision['revision_no'] ?? null)
                    !== ($revision['current_revision_no'] ?? null)
            ) {
                throw new PayrollPaymentPreparationException('revision_not_ready',
                    'Závazek lze vytvořit jen z aktuální schválené revize.',
                );
            }
            $periodStart = $revision['period_start'];
            $month = (int) substr($periodStart, 5, 2);
            if (!in_array($month, [3, 6, 9, 12], true)) {
                return ['liability_ids' => [], 'created_count' => 0];
            }
            $year = (int) substr($periodStart, 0, 4);
            $quarterMonths = [$month - 2, $month - 1, $month];
            $quarter = $this->quarterBases($supplierId, $year, $quarterMonths);
            $liabilityBaseMinor = $quarter['base'];
            $hasEmployees = $quarter['has_employees'];
            $takeoverPeriods = $quarter['takeover_periods'];
            $missingTakeoverPeriods = $quarter['missing_takeover_periods'];
            $costKey = $quarter['cost_key'];
            $quarterStart = sprintf('%04d-%02d-01', $year, $quarterMonths[0]);
            $reference = sprintf(
                'accident-insurance:quarter:%04d-%02d',
                $year,
                $quarterMonths[0],
            );
            $warnings = array_map(
                static fn (string $period): string => sprintf(
                    'Chybí vyměřovací základ za převzatý měsíc %d/%s. Doplňte ho '
                        . 'v převzatých mzdách; do té doby je čtvrtletní pojistné '
                        . 'spočtené bez tohoto měsíce.',
                    (int) substr($period, 5, 2),
                    substr($period, 0, 4),
                ),
                $missingTakeoverPeriods,
            );

            // Bez jediného pracovněprávního vztahu (pracovní poměr, DPP, DPČ)
            // firma zákonné pojištění odpovědnosti neplatí vůbec: jednatel ani
            // společník zaměstnancem podle § 205d zák. č. 65/1965 Sb. není.
            // Sazba ani účet pojistitele se pak nehledají, protože je taková
            // firma nemá mít vyplněné. Jedinou výjimkou je dřívější závazek
            // téhož čtvrtletí, který musí opravná revize umět vynulovat.
            if (!$hasEmployees
                && $this->priorState(
                    $this->liabilities->lockEarlierInstitutionalLiabilities(
                        $supplierId,
                        $revision['run_id'],
                        $revision['revision_no'],
                        self::LIABILITY_KIND,
                    ),
                    $reference,
                ) === null
            ) {
                return ['liability_ids' => [], 'created_count' => 0, 'warnings' => []];
            }

            $rate = $this->rates->effectiveOn($supplierId, $quarterStart);
            if ($rate === null) {
                throw new \DomainException(
                    'Sazba zákonného pojištění odpovědnosti není nastavena. '
                    . 'Doplňte ji v Nastavení mezd podle výměru pojišťovny.',
                );
            }
            $premiumMinor = $this->calculator->premiumMinor(
                $liabilityBaseMinor,
                $rate['rate_per_mille'],
            );
            $dueOn = $this->deadlines->dueOn(
                PayrollLevyDeadlinePolicy::ACCIDENT_INSURANCE,
                $periodStart,
            );

            $target = $this->target(
                $supplierId,
                $rate['institution_code'],
                $dueOn,
            );
            $source = [
                // v2: základ se počítá bez ročního maxima podle § 15a a nese
                // vlastní název, aby z otisku bylo poznat, kterým pravidlem
                // závazek vznikl. Starší závazky s klíčem `assessment_base_…`
                // stojí na zastropovaném základu.
                'schema_reference' => self::SOURCE_SCHEMA_CURRENT,
                'run_id' => $revision['run_id'],
                'revision_id' => $revisionId,
                'revision_no' => $revision['revision_no'],
                'quarter_start' => $quarterStart,
                'quarter_end' => $periodStart,
                'employer_liability_assessment_base_minor_units' => $liabilityBaseMinor,
                'rate_per_mille' => $rate['rate_per_mille'],
                'rate_effective_from' => $rate['effective_from'],
                'logical_reference' => $reference,
                'recipient_reference' => $target['recipient_reference'],
                ...$target['target_snapshot'],
                'target_amount_minor' => $premiumMinor,
            ];
            // Klíče jen u čtvrtletí s převzatým měsícem, aby otisk čtvrtletí
            // spočtených celých v MyÚčtu zůstal bajtově stejný jako dřív.
            if ($takeoverPeriods !== []) {
                $source['takeover_periods'] = $takeoverPeriods;
                $source['takeover_periods_missing_base'] = $missingTakeoverPeriods;
            }

            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    self::LIABILITY_KIND,
                ),
                $reference,
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== null) {
                throw new \DomainException(
                    'Další revize zákonného pojištění odpovědnosti musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }
            if ($prior !== null
                && ($prior['recipient_reference'] !== $target['recipient_reference']
                    || $prior['target_snapshot'] !== $target['target_snapshot'])
            ) {
                throw new \DomainException(
                    'Ověřený cíl zákonného pojištění odpovědnosti se proti '
                    . 'předchozímu závazku změnil.',
                );
            }
            $priorSigned = $prior['signed_minor'] ?? 0;
            $delta = $premiumMinor - $priorSigned;
            if ($delta === 0) {
                return ['liability_ids' => [], 'created_count' => 0, 'warnings' => $warnings];
            }
            $direction = $delta > 0 ? 'outgoing' : 'incoming';
            $amount = abs($delta);
            $source['prior_signed_minor'] = $priorSigned;
            $source['delta_signed_minor'] = $delta;
            $sourceJson = CanonicalJson::encode($source);
            $sourceHash = hash('sha256', $sourceJson);
            $idempotencyHash = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-payment-accident-insurance-idempotency.v1',
                    'supplier_id' => $supplierId,
                    'revision_id' => $revisionId,
                    'logical_reference' => $reference,
                    'source_snapshot_hash' => $sourceHash,
                ]),
                true,
            );

            $existing = $this->liabilities->findAnyForUpdate(
                $supplierId,
                $revisionId,
                $reference,
            );
            if ($existing !== null) {
                if (($existing['direction'] ?? null) !== $direction
                    || ($existing['amount_minor'] ?? null) !== $amount
                    || !is_string($existing['source_snapshot_hash'] ?? null)
                    || !hash_equals($existing['source_snapshot_hash'], $sourceHash)
                    || !is_string($existing['idempotency_key_hash'] ?? null)
                    || !hash_equals($existing['idempotency_key_hash'], $idempotencyHash)
                ) {
                    throw new \DomainException(match (true) {
                        $this->wasBuiltOnCappedBase($existing) =>
                            'Toto čtvrtletí bylo předepsáno ze zastropovaného '
                                . 'vyměřovacího základu (roční maximum podle § 15a). '
                                . 'Zákonné pojištění odpovědnosti se počítá ze základu '
                                . 'bez ročního maxima, takže je předepsaná částka nižší, '
                                . 'než má být. Rozdíl doplňte opravnou revizí — přepsat '
                                . 'už předepsaný závazek na místě by smazalo stopu, '
                                . 'podle které se dohledá, co se pojišťovně poslalo.',
                        $this->wasBuiltWithoutTakeoverBase($existing) =>
                            'Toto čtvrtletí bylo předepsáno bez vyměřovacího základu '
                                . 'za některý převzatý měsíc. Po doplnění převzatých mezd '
                                . 'předepište rozdíl opravnou revizí posledního měsíce '
                                . 'čtvrtletí; už předepsaný závazek se na místě nepřepisuje.',
                        default => 'Idempotentní replay zákonného pojištění odpovědnosti nesouhlasí.',
                    });
                }

                // Závazek vzniklý dřív, než se pojistné předepisovalo do deníku
                // (nebo v zamčeném období), dostane předpis při dalším běhu
                // přípravy plateb — replay je jediné místo, kde se to dá dohnat.
                return [
                    'liability_ids' => [$existing['id']],
                    'created_count' => 0,
                    'warnings' => $warnings,
                    'posting' => $this->posting->post(
                        $supplierId,
                        $existing['id'],
                        $direction,
                        $amount,
                        $periodStart,
                        $actorUserId,
                        $costKey,
                    ),
                ];
            }

            $id = $this->liabilities->insertInstitutional(
                $supplierId,
                $revisionId,
                $reference,
                self::LIABILITY_KIND,
                $direction,
                $target['recipient_reference'],
                $dueOn,
                $amount,
                $prior['latest_id'] ?? null,
                $sourceJson,
                $sourceHash,
                $idempotencyHash,
                $actorUserId,
            );

            return [
                'liability_ids' => [$id],
                'created_count' => 1,
                'warnings' => $warnings,
                'posting' => $this->posting->post(
                    $supplierId,
                    $id,
                    $direction,
                    $amount,
                    $periodStart,
                    $actorUserId,
                    $costKey,
                ),
            ];
        });
    }

    /**
     * Klíč rozpadu nákladu čtvrtletí na střediska a dimenze z aktuálních
     * schválených revizí jeho měsíců — tentýž, se kterým materializace
     * předepisuje pojistné do deníku. `null`, když čtvrtletí nejde doložit
     * (chybí revize nebo výsledek sociálního pojištění).
     *
     * @param string $quarterEndPeriod první den posledního měsíce čtvrtletí
     */
    public function quarterCostKey(int $supplierId, string $quarterEndPeriod): ?PayrollAccidentInsuranceCostKey
    {
        $month = (int) substr($quarterEndPeriod, 5, 2);
        if (!in_array($month, [3, 6, 9, 12], true)) {
            throw new \InvalidArgumentException('Čtvrtletí končí březnem, červnem, zářím nebo prosincem.');
        }
        try {
            return $this->quarterBases(
                $supplierId,
                (int) substr($quarterEndPeriod, 0, 4),
                [$month - 2, $month - 1, $month],
            )['cost_key'];
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * Základ zákonného pojištění odpovědnosti za tři měsíce čtvrtletí.
     *
     * @param list<int> $quarterMonths
     * @return array{
     *   base:int,
     *   has_employees:bool,
     *   takeover_periods:list<string>,
     *   missing_takeover_periods:list<string>,
     *   cost_key:PayrollAccidentInsuranceCostKey
     * }
     */
    private function quarterBases(int $supplierId, int $year, array $quarterMonths): array
    {
        $startPeriod = $this->historical->startPeriod($supplierId);
        $takeoverYear = null;
        $base = 0;
        $hasEmployees = false;
        $takeoverPeriods = [];
        $missingTakeoverPeriods = [];
        $costMonths = [];
        foreach ($quarterMonths as $quarterMonth) {
            $monthStart = sprintf('%04d-%02d-01', $year, $quarterMonth);
            $calculated = $this->monthLiabilityAssessmentBase($supplierId, $monthStart);
            if ($calculated !== null) {
                $costMonths[] = [
                    'revision_id' => $calculated['revision_id'],
                    'employment_bases' => $calculated['employment_bases'],
                    'unattributed_base' => $calculated['unattributed_base'],
                ];
            } else {
                if (!PayrollHistoricalPeriodService::precedesStart($startPeriod, $monthStart)) {
                    throw new \DomainException(sprintf(
                        'Čtvrtletí není kompletní: měsíc %s nemá schválenou mzdovou revizi.',
                        $monthStart,
                    ));
                }
                $takeoverYear ??= $this->takeovers->forSupplier($supplierId, $year);
                $calculated = $this->takeoverMonthAssessmentBase(
                    $supplierId,
                    $takeoverYear,
                    $monthStart,
                );
                $takeoverPeriods[] = substr($monthStart, 0, 7);
                if ($calculated['missing_base']) {
                    $missingTakeoverPeriods[] = substr($monthStart, 0, 7);
                }
                // Převzatý měsíc revizi nemá, jeho základ zůstane v nákladu bez dimenzí.
                $costMonths[] = [
                    'revision_id' => null,
                    'employment_bases' => [],
                    'unattributed_base' => $calculated['base'],
                ];
            }
            $base += $calculated['base'];
            $hasEmployees = $hasEmployees || $calculated['has_employees'];
        }

        return [
            'base' => $base,
            'has_employees' => $hasEmployees,
            'takeover_periods' => $takeoverPeriods,
            'missing_takeover_periods' => $missingTakeoverPeriods,
            'cost_key' => new PayrollAccidentInsuranceCostKey($costMonths),
        ];
    }

    /**
     * Vznikl už existující závazek na starém pravidle (zastropovaný základ)?
     *
     * Rozlišuje se kvůli hlášce: „idempotentní replay nesouhlasí" by účetní
     * poslalo hledat rozbitý zápis, přestože jde o doložený rozdíl ve výkladu,
     * který se řeší opravnou revizí, ne opravou dat.
     *
     * @param array<string,mixed> $existing
     */
    private function wasBuiltOnCappedBase(array $existing): bool
    {
        $json = $existing['source_snapshot_json'] ?? null;
        if (!is_string($json)) {
            return false;
        }
        try {
            $source = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($source)
            && ($source['schema_reference'] ?? null) === self::SOURCE_SCHEMA_CAPPED;
    }

    /**
     * Vyměřovací základ zákonného pojištění odpovědnosti za JEDEN měsíc
     * čtvrtletí, čtený ze schváleného a otiskem ověřeného výsledku sociálního
     * pojištění — nepočítá se znovu vlastní cestou a nikdy se nebere z hrubé
     * mzdy, která započitatelnému základu odpovídat nemusí.
     *
     * ⚠️ NENÍ to základ sociálního pojištění, a to ze dvou nezávislých důvodů:
     * bere se PŘED ročním maximem a bez vztahů druhu `corporate_body`
     * (viz {@see self::sumLiabilityRelationships()}). Celofiremní součet ve
     * výsledku se proto použít nedá, ani ten nezastropovaný.
     *
     * § 12 odst. 2 vyhlášky č. 125/1993 Sb. přebírá ze zákona
     * č. 589/1992 Sb. jen to, KTERÉ příjmy do základu patří (§ 5 odst. 1
     * písm. a) — roční maximum je samostatné omezení až v § 15a a součástí
     * definice základu není. Shodně to vykládají Kooperativa i Generali Česká
     * pojišťovna a stanovisko Ministerstva financí z 20. 5. 2008: shoda se
     * sociálním pojištěním se týká složek příjmu, ne maximálního základu.
     *
     * Praktický důsledek, na kterém ten rozdíl stojí: zaměstnanci nad ročním
     * stropem mají od překročení `capped_…` nulové, ale do základu zákonného
     * pojištění se počítají celý rok dál. Čtení `capped_…` proto předepisovalo
     * nižší pojistné, než má být, a u nedoplatku § 12 odst. 9 přidává 10 % za
     * každý započatý měsíc prodlení.
     *
     * `null` = měsíc nemá aktuální schválenou revizi. Jestli je to chyba,
     * rozhoduje volající podle hranice `start_period`: převzatý měsíc revizi
     * mít nemůže a jeho základ nese převzatá mzda.
     *
     * @return array{base:int,has_employees:bool,missing_base:bool,employment_bases:array<int,int>,unattributed_base:int,revision_id:int}|null
     */
    private function monthLiabilityAssessmentBase(int $supplierId, string $monthStart): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND run.period_start = ?
                AND revision.status = "approved"
                AND revision.revision_no = run.current_revision_no'
        );
        $statement->execute([$supplierId, $monthStart]);
        $revisionId = $statement->fetchColumn();
        if ($revisionId === false) {
            return null;
        }

        $result = $this->statutoryResults->find(
            $supplierId,
            (int) $revisionId,
            'social_insurance',
        );
        if ($result === null || ($result['result_status'] ?? null) !== 'calculated') {
            throw new \DomainException(sprintf(
                'Měsíc %s nemá vypočtený výsledek sociálního pojištění.',
                $monthStart,
            ));
        }
        $root = $result['result_snapshot'] ?? null;
        if (!is_array($root) || array_is_list($root)
            || ($root['status'] ?? null) !== 'calculated'
        ) {
            throw new \DomainException('Výsledek sociálního pojištění není úplný.');
        }
        $rootHash = $result['result_snapshot_hash'] ?? null;
        if (!is_string($rootHash)
            || !hash_equals($rootHash, hash('sha256', CanonicalJson::encode($root)))
        ) {
            throw new \DomainException('Otisk výsledku sociálního pojištění nesouhlasí.');
        }
        // Osoby a vztahy výpočet neukládá do hlavičky výsledku, ale jako samostatné
        // řádky výsledku ({@see PayrollStatutoryResultRepository::find()}); výsledek
        // vztahu je jeho `result_snapshot`, pracovní vztah nese řádek.
        $people = [];
        $employmentIds = [];
        foreach ((array) ($result['people'] ?? []) as $person) {
            $relationships = array_values(array_filter((array) ($person['relationships'] ?? []), 'is_array'));
            $people[] = ['relationships' => array_map(
                static fn (array $relationship): mixed => $relationship['result_snapshot'] ?? null,
                $relationships,
            )];
            $employmentIds[] = array_map(
                static fn (array $relationship): ?int => is_int($relationship['employment_id'] ?? null)
                    ? $relationship['employment_id']
                    : null,
                $relationships,
            );
        }

        return [
            ...$this->sumLiabilityRelationships(['people' => $people], $monthStart, $employmentIds),
            'revision_id' => (int) $revisionId,
        ];
    }

    /**
     * Sečte základ přes JEDNOTLIVÉ vztahy, ne přes celofiremní součet.
     *
     * Celofiremní `participating_assessment_base_minor_units` zahrnuje i vztahy
     * druhu `corporate_body`, kam projekt mapuje příjem společníka a odměnu za
     * výkon funkce. Ty do základu zákonného pojištění odpovědnosti nepatří:
     * pojištěni jsou zaměstnanci pro případ pracovního úrazu a nemoci
     * z povolání, a Kooperativa je jako provozovatel pojištění ze základu
     * výslovně vylučuje, přestože se za ně sociální pojistné odvádí.
     *
     * Bere se `assessment_base_minor_units` vztahu, tedy hodnota PŘED ročním
     * maximem podle § 15a - to se na tohle pojištění nevztahuje. Vztahy, které
     * se sociálního pojištění neúčastní, se nepočítají stejně jako u
     * celofiremního součtu.
     *
     * `has_employees` hlásí, že měsíc měl aspoň jeden pracovněprávní vztah
     * (pracovní poměr, DPP, DPČ), i když se sociálního pojištění neúčastnil:
     * povinnost pojištění odpovědnosti vzniká zaměstnáváním, ne výší základu.
     *
     * `employment_bases` je týž základ po pracovních vztazích — klíč rozpadu
     * nákladu na střediska a dimenze ({@see PayrollAccidentInsuranceCostKey}).
     * Základ vztahu, o kterém výsledek neví, ke kterému pracovnímu vztahu patří,
     * jde do `unattributed_base`.
     *
     * @param array<string,mixed> $root
     * @param list<list<?int>> $employmentIds pracovní vztahy ve stejném pořadí jako `people.*.relationships.*`
     * @return array{base:int,has_employees:bool,missing_base:bool,employment_bases:array<int,int>,unattributed_base:int}
     */
    private function sumLiabilityRelationships(array $root, string $monthStart, array $employmentIds = []): array
    {
        $people = $root['people'] ?? null;
        if (!is_array($people)) {
            throw new \DomainException(sprintf(
                'Výsledek sociálního pojištění za %s neobsahuje pracovní vztahy.',
                $monthStart,
            ));
        }

        $base = 0;
        $hasEmployees = false;
        $employmentBases = [];
        $unattributed = 0;
        foreach ($people as $personIndex => $person) {
            $relationships = is_array($person) ? ($person['relationships'] ?? null) : null;
            if (!is_array($relationships)) {
                continue;
            }
            foreach ($relationships as $relationshipIndex => $relationship) {
                if (!is_array($relationship)) {
                    continue;
                }
                if (($relationship['kind'] ?? null) === self::EXCLUDED_RELATIONSHIP_KIND) {
                    continue;
                }
                $hasEmployees = true;
                $participation = $relationship['participation'] ?? null;
                if (!is_array($participation)
                    || ($participation['status'] ?? null) !== 'participates'
                ) {
                    continue;
                }
                $relationshipBase = $relationship['assessment_base_minor_units'] ?? null;
                if (!is_int($relationshipBase) || $relationshipBase < 0) {
                    throw new \DomainException(sprintf(
                        'Vyměřovací základ pracovního vztahu za %s není platné číslo.',
                        $monthStart,
                    ));
                }
                $base += $relationshipBase;
                $employmentId = $employmentIds[$personIndex][$relationshipIndex] ?? null;
                if ($employmentId === null) {
                    $unattributed += $relationshipBase;
                } else {
                    $employmentBases[$employmentId] = ($employmentBases[$employmentId] ?? 0) + $relationshipBase;
                }
            }
        }
        ksort($employmentBases, SORT_NUMERIC);

        return [
            'base' => $base,
            'has_employees' => $hasEmployees,
            'missing_base' => false,
            'employment_bases' => $employmentBases,
            'unattributed_base' => $unattributed,
        ];
    }

    /**
     * Základ za měsíc před `start_period`, který vedl předchozí mzdový program.
     *
     * Čte se výhradně přes {@see PayrollTakeoverReader} (převzaté mzdy roku
     * přechodu), stejně jako ELDP a průměrný výdělek. Bere se
     * `social_base_minor`, tedy vyměřovací základ sociálního pojištění, který
     * vydal předchozí program. Jestli ho tam zastropoval ročním maximem, se
     * z převzatého řádku poznat nedá; na rok přechodu to dopadá jen
     * u zaměstnance nad maximem.
     *
     * Vyloučení je stejné jako u počítaných měsíců: vztah člena orgánu nebo
     * společníka ({@see PayrollRelationType::isCompanyBody()}) se nepočítá.
     * Druh vztahu nese převzatý řádek; když chybí, doplní se z převedeného
     * vztahu, a když není ani ten, řádek se započte. Neznámý vztah je spíš
     * zaměstnanec než jednatel a vynechaný základ by znamenal nedoplatek.
     *
     * Měsíc bez převzatého řádku pracovněprávního vztahu:
     *  - firma v něm žádný pracovněprávní vztah neměla → nulový základ, žádná
     *    povinnost (firma začala zaměstnávat později),
     *  - měla, ale převzatá mzda chybí → nulový základ s příznakem
     *    `missing_base`; závazek přesto vznikne a hláška řekne, co doplnit.
     *
     * @return array{base:int,has_employees:bool,missing_base:bool}
     */
    private function takeoverMonthAssessmentBase(
        int $supplierId,
        PayrollTakeoverYear $takeoverYear,
        string $monthStart,
    ): array {
        $base = 0;
        $hasRows = false;
        foreach ($takeoverYear->forPeriod(substr($monthStart, 0, 7)) as $row) {
            $relationType = PayrollRelationType::tryFrom((string) $row->relationType)
                ?? ($row->employmentId !== null
                    ? $this->employmentRelationType($supplierId, $row->employmentId)
                    : null);
            if ($relationType !== null && $relationType->isCompanyBody()) {
                continue;
            }
            if ($row->socialBaseMinor < 0) {
                throw new \DomainException(sprintf(
                    'Převzatý vyměřovací základ za %s je záporný.',
                    substr($monthStart, 0, 7),
                ));
            }
            $hasRows = true;
            $base += $row->socialBaseMinor;
        }
        if ($hasRows) {
            return ['base' => $base, 'has_employees' => true, 'missing_base' => false];
        }
        $employed = $this->hasEmploymentRelationshipIn($supplierId, $monthStart);

        return ['base' => 0, 'has_employees' => $employed, 'missing_base' => $employed];
    }

    private function employmentRelationType(int $supplierId, int $employmentId): ?PayrollRelationType
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT relation_type FROM payroll_employments
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $employmentId]);
        $value = $statement->fetchColumn();

        return is_string($value) ? PayrollRelationType::tryFrom($value) : null;
    }

    /**
     * Trval v měsíci aspoň jeden převedený pracovněprávní vztah?
     *
     * Rozhoduje jen o tom, jestli je chybějící převzatý měsíc mezera v datech,
     * nebo měsíc, kdy firma ještě nikoho nezaměstnávala. Vztah bez data
     * nástupu se nepočítá, protože jeho trvání nejde doložit.
     */
    private function hasEmploymentRelationshipIn(int $supplierId, string $monthStart): bool
    {
        $companyBody = array_values(array_map(
            static fn (PayrollRelationType $type): string => $type->value,
            array_filter(
                PayrollRelationType::cases(),
                static fn (PayrollRelationType $type): bool => $type->isCompanyBody(),
            ),
        ));
        $placeholders = implode(',', array_fill(0, count($companyBody), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_employments
              WHERE supplier_id = ?
                AND status NOT IN ('draft', 'cancelled')
                AND relation_type NOT IN ({$placeholders})
                AND start_date IS NOT NULL
                AND start_date <= LAST_DAY(?)
                AND (end_date IS NULL OR end_date >= ?)
              LIMIT 1",
        );
        $statement->execute([$supplierId, ...$companyBody, $monthStart, $monthStart]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Vznikl existující závazek bez základu za některý převzatý měsíc?
     *
     * @param array<string,mixed> $existing
     */
    private function wasBuiltWithoutTakeoverBase(array $existing): bool
    {
        $json = $existing['source_snapshot_json'] ?? null;
        if (!is_string($json)) {
            return false;
        }
        try {
            $source = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($source)
            && is_array($source['takeover_periods_missing_base'] ?? null)
            && $source['takeover_periods_missing_base'] !== [];
    }

    /**
     * @return array{recipient_reference:string,target_snapshot:array<string,mixed>}
     */
    private function target(
        int $supplierId,
        string $rateInstitutionCode,
        string $dueOn,
    ): array {
        // Kód pojistitele je u zákonného pojištění odpovědnosti jen značka
        // uvedená u sazby (Nastavení mezd → sazba pojištění odpovědnosti),
        // zatímco účet je vedený pod svým vlastním kódem v Účtech institucí.
        // Obě obrazovky vyplňuje účetní zvlášť a nic jí nenapoví, že se ty dva
        // kódy musí trefit — proto se při neshodě použije jednoznačný ověřený
        // účet pojistitele. Víc ověřených účtů = fail-closed.
        $resolved = $this->targets->resolve(
            $supplierId,
            'statutory_insurance',
            $rateInstitutionCode,
            'CZK',
            $dueOn,
            'Pojistitel zákonného pojištění odpovědnosti',
            'Nastavení mezd → sazba zákonného pojištění odpovědnosti, kód'
                . ' pojistitele',
            PayrollInstitutionFallbackPolicy::UNIQUE_VERIFIED_ACCOUNT,
        );
        $account = $resolved['account'];
        $institutionCode = $resolved['institution_code'];
        $this->assertVerifiedAccount($supplierId, $dueOn, $account);
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-accident-insurance-target-verification.v1',
                'institution_type' => 'statutory_insurance',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'variable_symbol' => $account['variable_symbol'],
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ]),
        );

        return [
            'recipient_reference' =>
                "institution:statutory_insurance:{$institutionCode}:account:"
                . $account['id'],
            'target_snapshot' => [
                'institution_type' => 'statutory_insurance',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payment_target_verification_hash' => $verificationHash,
                'variable_symbol' => $account['variable_symbol'],
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
            ],
        ];
    }

    /** @param array<string,mixed> $account */
    private function assertVerifiedAccount(
        int $supplierId,
        string $dueOn,
        array $account,
    ): void {
        if (!in_array($account['source_kind'], [
            'official_registry',
            'official_document',
            'institution_notice',
            'user_verified',
        ], true)
            || $account['verified_by'] === null
            || $account['verified_by'] <= 0
            || $account['verified_on'] > $dueOn
            || preg_match('/^[0-9a-f]{64}$/D', $account['bank_account_hash']) !== 1
        ) {
            throw new \DomainException(
                'Účet pojistitele zákonného pojištění odpovědnosti není ověřený.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $account['id'],
            PayrollRevealPurpose::PAYMENT_LIABILITY_ACCOUNT,
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $actualHash)) {
            throw new \DomainException(
                'Obsah účtu pojistitele neodpovídá uloženému otisku.',
            );
        }
    }

    /**
     * @param list<array{
     *   id:int,revision_no:int,liability_reference:string,direction:string,
     *   recipient_reference:string,amount_minor:int,
     *   source_snapshot_json:string,source_snapshot_hash:string
     * }> $rows
     * @return array{
     *   recipient_reference:string,signed_minor:int,latest_id:int,
     *   target_snapshot:array<string,mixed>
     * }|null
     */
    private function priorState(array $rows, string $reference): ?array
    {
        $state = null;
        foreach ($rows as $row) {
            if ($row['liability_reference'] !== $reference) {
                continue;
            }
            $source = json_decode($row['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            // Obě schémata, ne jen v1. Řetěz oprav běží přes roky a po přechodu
            // na v2 by jinak PRVNÍ oprava jakéhokoli nově předepsaného čtvrtletí
            // skončila výjimkou — dřívějším závazkem už by byl v2.
            if (!is_array($source)
                || !in_array(
                    $source['schema_reference'] ?? null,
                    self::SOURCE_SCHEMA_REFERENCES,
                    true,
                )
                || CanonicalJson::encode($source) !== $row['source_snapshot_json']
                || !hash_equals($row['source_snapshot_hash'], hash('sha256', $row['source_snapshot_json']))
            ) {
                throw new \DomainException(
                    'Dřívější závazek zákonného pojištění odpovědnosti nemá platný zdroj.',
                );
            }
            $target = [];
            foreach ([
                'institution_type', 'institution_code', 'payment_target_id',
                'payment_target_hash', 'payment_target_row_version',
                'payment_target_verification_hash', 'variable_symbol',
                'specific_symbol', 'constant_symbol',
            ] as $field) {
                $target[$field] = $source[$field] ?? null;
            }
            if ($state === null) {
                $state = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $target,
                ];
            } elseif ($state['recipient_reference'] !== $row['recipient_reference']
                || $state['target_snapshot'] !== $target
            ) {
                throw new \DomainException(
                    'Řetězec zákonného pojištění odpovědnosti změnil zmrazený cíl.',
                );
            }
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $state['signed_minor'] += $signed;
            $state['latest_id'] = $row['id'];
        }
        if ($state !== null && $state['signed_minor'] < 0) {
            throw new \DomainException(
                'Dřívější závazky zákonného pojištění odpovědnosti mají záporný zůstatek.',
            );
        }

        return $state;
    }
}
