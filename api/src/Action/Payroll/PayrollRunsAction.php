<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunDeletionException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Payment\PayrollPaydayResolver;
use MyInvoice\Service\Payroll\PayrollLegacyRecapitulationService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\Run\PayrollRunAutoSettlementService;
use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandResult;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentsUnsettledException;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSourceDriftDetector;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Run\PayrollTakeoverRunService;
use MyInvoice\Service\Payroll\Security\PayrollEnforcementDisclosure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollRunsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRunCommandService $commands,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunWorkflow $workflow,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollPeriodOwnershipService $ownership,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollPaydayResolver $payday,
        private readonly PayrollLegacyRecapitulationService $legacyRecapitulations,
        private readonly PayrollRunReadinessService $readiness,
        // Doplňuje prokazatelně nulové počáteční stavy kumulací při založení
        // běhu — viz komentář v create().
        private readonly \MyInvoice\Service\Payroll\PayrollOpeningBalanceService $openingBalances,
        /*
         * Stav úhrady není rozhodnutí účetní, ale fakt z platebního ledgeru.
         * Po `prepare_payments` se rovnou zkusí, jestli je pokrytí úplné
         * (běh, kde není co platit, jím je hned), a do seznamu běhů se
         * doplní přehled „doloženo / čeká na výpis".
         */
        private readonly PayrollRunAutoSettlementService $autoSettlement,
        // Otevřená revize se zastaralým snímkem — varování s počtem u běhu.
        private readonly PayrollRunSourceDriftDetector $sourceDrift,
        // Rok přechodu z jiného mzdového programu — převzatý běh (PAM-17)
        // a doložení jeho plateb (PAM-18). Vědomě mimo `PayrollRunCommandService`:
        // převzatý běh neprochází workflow.
        private readonly PayrollTakeoverRunService $takeoverRuns,
        // Nabídka posunout začátek vedení mezd za měsíce zpracované předchozím
        // programem, když seznam běhů hlásí díru před zvoleným obdobím.
        private readonly \MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup $migrationSetup,
        // Trvale skrytá varování se do kontrol běhu ani do jejich počtů nepočítají.
        private readonly \MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionService $warningSuppressions,
    ) {}

    /**
     * Stav rezervace mzdového období.
     *
     * @param array<string,string> $args
     */
    public function periodOwnership(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $supplierId = $this->currentSupplierId($request);

        return Json::ok($response, [
            'ownership' => $this->ownership->legacyClaimStatus(
                $supplierId,
                $year,
                $month,
            ),
            /*
             * Druhý směr musí obrazovka vidět taky. Bez něj uměla nabídnout jen
             * „převzít měsíc z původního zaúčtování"; cesta zpátky nebyla vidět,
             * protože neexistovala.
             */
            'payroll_ownership' => $this->ownership->payrollClaimStatus(
                $supplierId,
                $year,
                $month,
            ),
        ]);
    }

    /**
     * Uvolnění rezervace, kterou drží MZDOVÝ MODUL — cesta zpět k původnímu
     * ruční zaúčtování.
     *
     * Rezervaci pro modul zabírá už samo založení běhu, tedy dřív, než se
     * cokoliv spočítá. Kdo běh založil na špatný měsíc, zabral ho natrvalo:
     * zrušení běhu rezervaci neuvolní a smazat běh po prvním „Uzamknout
     * vstupy" už nejde. Uvolnění je fail-closed — služba ho odmítne, dokud za
     * období existuje živý běh, schválená revize, zaúčtování, platba, podání
     * nebo vydaný doklad — a je auditované včetně povinného důvodu.
     *
     * @param array<string,string> $args
     */
    public function releasePayrollPeriod(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.reopen',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
            $this->ownership->releasePayroll(
                $this->currentSupplierId($request),
                $year,
                $month,
                $this->requiredUserId($request),
                $this->requiredString($body, 'reason'),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error(
                $response,
                'payroll_period_owned',
                $e->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'released' => true,
            'payroll_ownership' => $this->ownership->payrollClaimStatus(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /**
     * Uvolnění rezervace, kterou drží PŮVODNÍ ruční zaúčtování.
     *
     * Bez téhle cesty stačilo, aby legacy větev (i z cronu) zabrala měsíc dřív
     * než modul, a mzdový běh za ten měsíc už nešlo založit jinak než ručním
     * zásahem do databáze. Uvolnění je fail-closed — služba ho odmítne, dokud
     * za období existuje aktivní legacy zaúčtování nebo záznam ve mzdovém
     * listu — a je auditované včetně povinného důvodu.
     *
     * @param array<string,string> $args
     */
    public function releaseLegacyPeriod(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.reopen',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
            $this->ownership->releaseLegacy(
                $this->currentSupplierId($request),
                $year,
                $month,
                $this->requiredUserId($request),
                $this->requiredString($body, 'reason'),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error(
                $response,
                'payroll_period_owned',
                $e->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'released' => true,
            'ownership' => $this->ownership->legacyClaimStatus(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /**
     * Co za období zbývá po RUČNÍ mzdové rekapitulaci — bez zápisu.
     *
     * @param array<string,string> $args
     */
    public function legacyRecapitulation(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'legacy_recapitulation' => $this->legacyRecapitulations->status(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /**
     * Předání měsíce od ruční rekapitulace modulu Mzdy.
     *
     * Bez téhle cesty šlo do modulu vzít jen měsíce OD přechodu dál. Starší
     * měsíc držela legacy rezervace a `release-legacy` ji fail-closed odmítala
     * uvolnit, dokud za období existoval řádek ve mzdovém listu — a ten se
     * nedal odstranit odnikud než ruční editací databáze. Rok, který začal
     * ruční rekapitulací, tak zůstal navždy rozpůlený mezi dvě agendy.
     *
     * @param array<string,string> $args
     */
    public function handOverLegacyRecapitulation(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.reopen',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        /*
         * Převod hrubé mzdy z odloženého listu do mzdových vstupů je volba,
         * výchozí chování zůstává beze změny. Převedené vstupy vznikají jako
         * koncepty; rovnou schválené jen na výslovné přání a s právem schvalovat.
         */
        $carryOverInputs = filter_var($body['carry_over_inputs'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $approveCarriedInputs = $carryOverInputs
            && filter_var($body['approve_carried_inputs'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($approveCarriedInputs
            && !RequestAuthorization::allows($request, 'payroll.approve', AccessLevel::WRITE)
        ) {
            return Json::error(
                $response,
                'forbidden',
                'Schválit převedené mzdové vstupy smí jen uživatel s právem schvalovat mzdy.',
                403,
            );
        }
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
            $result = $this->legacyRecapitulations->handOverToModule(
                $this->currentSupplierId($request),
                $year,
                $month,
                $this->requiredUserId($request),
                $this->requiredString($body, 'reason'),
                isset($body['reversal_date']) && $body['reversal_date'] !== ''
                    ? (string) $body['reversal_date']
                    : null,
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                $carryOverInputs,
                $approveCarriedInputs,
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error(
                $response,
                'payroll_period_owned',
                $e->getMessage(),
                409,
            );
        } catch (PostingException $e) {
            // Storno zamčeného nebo uzavřeného období je odmítnutí účetnictví,
            // ne vada požadavku — kód i stav si nese výjimka sama.
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus === 404 ? 404 : 409,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'hand_over' => $result,
            'legacy_recapitulation' => $this->legacyRecapitulations->status(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /** @return array{0:int,1:int} */
    private static function periodParts(mixed $period): array
    {
        if (!is_string($period)
            || preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $period, $m) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Období musí mít formát YYYY-MM.',
            );
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function clientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        return $this->ipMatcher->clientIpFromRequest($serverParams);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
            true,
        )) !== null) {
            return $error;
        }
        try {
            $period = $this->optionalPeriod(
                $request->getQueryParams()['period'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $query = $request->getQueryParams();
        // Období je volitelné, takže bez stropu tenhle endpoint četl kompletní
        // mzdovou historii firmy. Strop je tvrdý (ne jen výchozí), aby ho nešlo
        // obejít parametrem z URL.
        $limit = max(1, min(
            PayrollRunRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollRunRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->runs->list(
            $this->currentSupplierId($request),
            $period === null ? null : "{$period}-01",
            $limit,
            $offset,
        );
        $items = $page['items'];
        $suppressions = $this->warningSuppressions->activeSet($this->currentSupplierId($request));
        // Součet exekučních srážek běhu je u malého zaměstnavatele srážka
        // jednoho člověka; bez práva na agendu se nevrací.
        $canSeeEnforcement = PayrollEnforcementDisclosure::canSeeEnforcement($request);
        foreach ($items as &$item) {
            $status = PayrollTimeValue::string(
                $item['status'] ?? null,
                'run.status',
            );
            $revisionId = ($item['revision_id'] ?? null) === null
                ? null
                : PayrollTimeValue::int(
                    $item['revision_id'],
                    'run.revision_id',
                );
            $item['available_commands'] = self::offeredCommands(
                self::withCombinedCommand(array_map(
                    static fn ($command): string => $command->value,
                    $this->workflow->availableCommands(
                        PayrollRunStatus::from($status),
                    ),
                )),
            );
            /*
             * Kolik závazků je doložených bankou nebo pokladnou a kolik ještě
             * čeká na výpis. Informace, ne úkol: obrazovka běhu z toho píše
             * stav, tlačítko z toho nevzniká. `null` znamená, že za běh ještě
             * nejsou platební závazky (před `prepare_payments`) nebo že není
             * co platit.
             */
            $item['payment_coverage'] = $this->autoSettlement->coverageSummary(
                $this->currentSupplierId($request),
                $revisionId,
            );
            $filtered = $suppressions->filterRows($revisionId === null
                ? []
                : $this->runs->validations(
                    $this->currentSupplierId($request),
                    $revisionId,
                ));
            $item['validations'] = $filtered['visible'];
            $item['hidden_validation_count'] = $filtered['hidden_count'];
            $item['source_drift'] = $this->sourceDriftFor(
                $this->currentSupplierId($request),
                PayrollRunStatus::from($status),
                PayrollTimeValue::int($item['id'] ?? null, 'run.id'),
            );
            $deletion = $this->runs->canDelete(
                $this->currentSupplierId($request),
                PayrollTimeValue::int($item['id'] ?? null, 'run.id'),
            );
            $item['can_delete'] = $deletion !== null && $deletion->canDelete;
            /*
             * Samotné `can_delete` jen schová tlačítko a účetní se nedozví NIC.
             * Přitom rozhodnutí důvod zná a zbytek modulu ho vedle příznaku
             * posílá běžně ({@see PayrollRowDeletionRepository}) — běhy byly
             * jediné, kde se zahazoval.
             */
            $item['delete_blocker'] = $deletion === null
                ? null
                : $deletion->blockerMessage;
            $snapshot = $item['result_snapshot'] ?? null;
            if (!$canSeeEnforcement && is_array($snapshot)) {
                $item['result_snapshot'] = PayrollEnforcementDisclosure::redactRunTotals($snapshot);
            }
        }
        unset($item);

        // Klíč `runs` zůstává, aby stávající volající nespadli; `total`/`limit`/`offset`
        // přibyly vedle něj, protože seznam už nemusí být úplný.
        //
        // `suggested_payment_date` je návrh výplatního termínu ze sjednané
        // mzdové politiky. Bez něj nabízel zakládací formulář natvrdo
        // patnáctého následujícího měsíce a uloženou politiku ignoroval —
        // a datum výplaty není kosmetika: visí na něm splatnost odvodů, lhůty
        // hlášení i mez podle § 141 odst. 1 zákoníku práce. Počítá se na
        // serveru, protože posun na pracovní den musí znát státní svátky.
        $suggestedPaymentDate = $period === null
            ? null
            : $this->payday->suggest(
                $this->currentSupplierId($request),
                "{$period}-01",
            );

        $missingPeriods = $period === null
            ? []
            : $this->runs->missingPreviousPeriods($this->currentSupplierId($request), "{$period}-01");

        return Json::ok($response, [
            'runs' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'suggested_payment_date' => $suggestedPaymentDate,
            /*
             * Měsíce od začátku vedení mezd před dotázaným obdobím, za které
             * neexistuje žádný běh (ani převzatý). Běh staví na předchozích
             * měsících — roční kumulace, průměry, zálohová daň — a převod, který
             * nastavil začátek vedení mezd dřív, než skončil předchozí program,
             * nechal mezi převzatými měsíci a prvním během díru, o které
             * obrazovka mlčela.
             */
            'missing_previous_periods' => $missingPeriods,
            // Měsíc po posledním měsíci, který zpracoval předchozí program — týž
            // cíl, jaký radí kontrola převodu. Dřív se nabízelo právě zobrazené
            // období a po posunu převod hlásil, že začátek je pořád pozadu.
            // `null` = posun nenabízet.
            'advance_start_to' => $missingPeriods !== []
                ? $this->migrationSetup->suggestedStart($this->currentSupplierId($request))
                : null,
            /*
             * KONTROLA PŘED ZAHÁJENÍM. Čtecí, nic neukládá, nic neblokuje —
             * stejný vzor jako `GET /payroll/year-close/{year}`, který vrací
             * `blockers` k prohlédnutí. Jede jen tam, kde má smysl: dokud za
             * období není běh, nebo je teprve v konceptu. Po zamknutí už tytéž
             * nálezy visí na revizi ve `validations`, takže druhá kopie by je
             * jen zdvojila.
             */
            'readiness' => $this->readinessForPeriod(
                $request,
                $period,
                $suggestedPaymentDate,
                $items,
            ),
        ]);
    }

    /**
     * Kolik podkladů se změnilo od vzniku snímku otevřené revize.
     *
     * Jen tam, kde jde podklady obnovit (stejná podmínka jako nabídka příkazu
     * `refresh_inputs`), jinak `null`. Chyba detekce seznam neshodí — schválení
     * si totéž ověří samo a bez výjimky ho zastaví.
     *
     * @return array<string,int|string>|null
     */
    private function sourceDriftFor(
        int $supplierId,
        PayrollRunStatus $status,
        int $runId,
    ): ?array {
        if (!in_array(
            PayrollRunCommand::REFRESH_INPUTS,
            $this->workflow->availableCommands($status),
            true,
        )) {
            return null;
        }
        try {
            $revision = $this->runs->currentRevision($supplierId, $runId);
            if (!is_array($revision['input_snapshot'] ?? null)) {
                return null;
            }

            return [
                ...$this->sourceDrift
                    ->detect($supplierId, $revision['input_snapshot'])
                    ->toArray(),
                'snapshot_created_at' => (string) $revision['created_at'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function readinessForPeriod(
        Request $request,
        ?string $period,
        ?string $suggestedPaymentDate,
        array $items,
    ): ?array {
        if ($period === null) {
            return null;
        }
        $periodStart = "{$period}-01";
        $paymentDate = $suggestedPaymentDate;
        foreach ($items as $item) {
            if ((string) ($item['period_start'] ?? '') !== $periodStart) {
                continue;
            }
            if ((string) ($item['status'] ?? '') !== PayrollRunStatus::DRAFT->value) {
                // Běh už je za zámkem: nálezy jsou na revizi, ne tady.
                return null;
            }
            $paymentDate = (string) $item['payment_date'];
        }
        if ($paymentDate === null) {
            return null;
        }

        try {
            return $this->readiness->inspect(
                $this->currentSupplierId($request),
                $periodStart,
                $paymentDate,
                null,
            );
        } catch (\Throwable) {
            // Kontrola nesmí shodit seznam běhů. Když se nepovede, obrazovka
            // prostě nic nepředvyplní a účetní pokračuje jako dřív.
            return null;
        }
    }

    /**
     * Sloučený krok „Spočítat mzdy" (zamknout vstupy + spočítat) je nabídka
     * navíc, ne náhrada: jednotlivé příkazy zůstávají v seznamu, protože je
     * používají opravné revize i API.
     *
     * @param list<string> $commands
     * @return list<string>
     */
    private static function withCombinedCommand(array $commands): array
    {
        if (!in_array(PayrollRunCommand::LOCK_INPUTS->value, $commands, true)) {
            return $commands;
        }
        array_unshift($commands, PayrollRunCommand::LOCK_AND_CALCULATE->value);

        return $commands;
    }

    /**
     * Příkazy, které se nabízejí ČLOVĚKU.
     *
     * `mark_paid` mezi ně nepatří: úhrada není rozhodnutí účetní, ale fakt.
     * Buď peníze odešly, nebo ne — a to aplikace pozná z platebního ledgeru
     * sama ({@see PayrollRunAutoSettlementService}). Tlačítko po účetní chtělo
     * potvrdit něco, co ví banka, a dokud to sama nespárovala, drželo i
     * `close`. Platební příkaz přitom odchází hned, ABO výpis o měsíc později.
     *
     * Samotný příkaz ve workflow ZŮSTÁVÁ — používá ho automatické překlopení,
     * starší data ho mají v historii a přes API se dá zavolat ručně. Mizí jen
     * z nabídky na obrazovce.
     *
     * @param list<string> $commands
     * @return list<string>
     */
    private static function offeredCommands(array $commands): array
    {
        return array_values(array_filter(
            $commands,
            static fn (string $command): bool
                => $command !== PayrollRunCommand::MARK_PAID->value,
        ));
    }

    /**
     * Přehled roku přechodu: které měsíce jdou převzít a které už převzaté jsou.
     *
     * @param array<string,string> $args
     */
    public function takeoverOverview(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $overview = $this->takeoverRuns->overview(
                $this->currentSupplierId($request),
                (int) ($args['year'] ?? 0),
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['takeover_overview' => $overview]);
    }

    /**
     * Postaví převzatý mzdový běh za jeden historický měsíc.
     *
     * Není to příkaz workflow a ani se tak nejmenuje: převzatý běh neprochází
     * výpočtem, kontrolou ani schválením, takže by ho `commands/{command}`
     * jen zamaskoval mezi kroky, které s ním nemají nic společného.
     */
    public function takeoverBuild(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            $result = $this->takeoverRuns->build(
                $this->currentSupplierId($request),
                $this->requiredString($body, 'period'),
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result, 201);
    }

    /** @param array<string,string> $args */
    public function takeoverDetail(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
            true,
        )) !== null) {
            return $error;
        }
        try {
            $result = $this->takeoverRuns->detail(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function takeoverDiscard(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            $result = $this->takeoverRuns->discard(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                (string) ($body['reason'] ?? ''),
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
    }

    /**
     * Detail běhu s CELÝM výsledkovým snapshotem — objemná data, která seznam
     * záměrně neposílá. Frontend si je dotahuje pro jeden rozbalený běh.
     *
     * @param array<string,string> $args
     */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
            true,
        )) !== null) {
            return $error;
        }
        $run = $this->runs->detail(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        if ($run === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }
        $run['payment_coverage'] = $this->autoSettlement->coverageSummary(
            $this->currentSupplierId($request),
            ($run['revision_id'] ?? null) === null
                ? null
                : (int) $run['revision_id'],
        );
        // Snímek nese u každé osoby celý vstup výpočtu srážek. Obecné `payroll`
        // READ na něj nestačí, platí totéž co pro exekuční a insolvenční agendu.
        $snapshot = $run['result_snapshot'] ?? null;
        $run['result_snapshot'] = PayrollEnforcementDisclosure::redactRunSnapshot(
            is_array($snapshot) ? $snapshot : null,
            PayrollEnforcementDisclosure::canSeeEnforcement($request),
            PayrollEnforcementDisclosure::canSeeInsolvency($request),
        );

        return Json::ok($response, ['run' => $run]);
    }

    /** @param array<string,string> $args */
    public function history(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        $history = $this->runs->history(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        if ($history === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }

        if (!PayrollEnforcementDisclosure::canSeeEnforcement($request)) {
            $history = PayrollEnforcementDisclosure::redactRunHistory($history);
        }

        return Json::ok($response, ['history' => $history]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $officeId = $body['office_id'] ?? null;
        if ($officeId !== null) {
            $officeId = filter_var($officeId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if (!is_int($officeId)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'office_id musí být kladné celé číslo.',
                    422,
                );
            }
        }
        try {
            $run = $this->commands->createRun(
                $this->currentSupplierId($request),
                $this->requiredString($body, 'period_start'),
                $this->requiredString($body, 'payment_date'),
                $officeId,
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error($response, 'payroll_period_owned', $e->getMessage(), 409);
        } catch (\InvalidArgumentException|\DomainException|\OutOfBoundsException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        /*
         * Nulové počáteční stavy kumulací tam, kde je nula prokazatelná.
         *
         * Bez nich spadl zákonný výpočet do `manual_review` u KAŽDÉHO
         * zaměstnance a účetní musela u každého ručně uložit prázdnou tabulku
         * úhrnů z „předchozího programu", který žádný neexistoval. V lednu to
         * platilo pro celou firmu každý rok znovu. Zaměstnanec, jehož část roku
         * zpracoval jiný program, tudy neprojde a zůstává na ručním zadání —
         * viz {@see PayrollOpeningBalanceService::seedProvableZeroOpenings()}.
         */
        $this->openingBalances->seedProvableZeroOpenings(
            $this->currentSupplierId($request),
            substr((string) ($run['period_start'] ?? ''), 0, 10),
            [],
            $this->requiredUserId($request),
        );

        return Json::ok($response, ['run' => $run], 201);
    }

    /** @param array<string,string> $args */
    public function delete(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $runId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($version) || !is_int($runId)) {
            return Json::error(
                $response,
                'validation_failed',
                'Smazání vyžaduje platné ID běhu a row_version.',
                422,
            );
        }
        try {
            $this->commands->deleteRun(
                $this->currentSupplierId($request),
                $runId,
                $version,
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollRunConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRunDeletionException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'deleted' => true,
            'run_id' => $runId,
        ]);
    }

    /** @param array<string,string> $args */
    public function command(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $command = (string) ($args['command'] ?? '');
        $permission = match ($command) {
            'calculate' => 'payroll.calculate',
            // Sloučený krok „Spočítat mzdy" pod sebou udělá obojí, takže musí
            // projít OBĚMA branami — druhá se ověřuje hned pod tímhle blokem.
            'lock_and_calculate' => 'payroll.calculate',
            // Obnova podkladů je přepočet nad novým snímkem — a stejně jako
            // sloučený krok zamyká nově schválené vstupy, takže i ona projde
            // oběma branami.
            'refresh_inputs' => 'payroll.calculate',
            'review', 'request_correction' => 'payroll.review',
            'approve' => 'payroll.approve',
            'reopen' => 'payroll.reopen',
            // `post` vytváří účetní zápis v hlavní knize — patří pod stejné
            // právo jako ostatní mzdové zaúčtování („Zaúčtovat mzdy"), ne pod
            // právo na zápis mzdových vstupů.
            'post' => 'payroll.post',
            // `prepare_payments` materializuje platební závazky a `mark_paid`
            // uzavírá platební ledger. Obojí je táž agenda jako platební dávky
            // a párování úhrad, které už `payroll.payments` chrání.
            'prepare_payments', 'mark_paid' => 'payroll.payments',
            // `close` je poslední pečeť běhu — období se tím uzavře stejně
            // závazně, jako ho `approve` schválil. `cancel` je naopak nevratné
            // zneplatnění už rozpracovaného (i schváleného) běhu, tedy stejná
            // třída zásahu jako odemknutí. Ani jedno není zápis mzdového
            // vstupu, takže catch-all `payroll.inputs.write` na ně nestačí.
            'close' => 'payroll.approve',
            'cancel' => 'payroll.reopen',
            default => 'payroll.inputs.write',
        };
        if (($error = $this->authorize(
            $request,
            $response,
            $permission,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        if (in_array($command, ['lock_and_calculate', 'refresh_inputs'], true)
            && ($error = $this->authorize(
                $request,
                $response,
                'payroll.inputs.write',
                AccessLevel::WRITE,
            )) !== null
        ) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $runId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if (!is_int($version) || !is_int($runId) || $idempotencyKey === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Příkaz vyžaduje row_version a hlavičku Idempotency-Key.',
                422,
            );
        }
        $reason = isset($body['reason']) && is_string($body['reason'])
            ? trim($body['reason'])
            : '';
        try {
            $result = $this->dispatch(
                $command,
                $this->currentSupplierId($request),
                $runId,
                $version,
                $idempotencyKey,
                $this->requiredUserId($request),
                $reason,
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollRunConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRunIdempotencyException $e) {
            return Json::error(
                $response,
                'idempotency_conflict',
                $e->getMessage(),
                409,
            );
        } catch (PayrollRunPaymentsUnsettledException $e) {
            $hasIncomingRefund =
                $e->coverage['incoming_unsettled_count'] > 0;
            return Json::error(
                $response,
                $hasIncomingRefund
                    ? 'payroll_incoming_refund_unresolved'
                    : 'payroll_payments_unsettled',
                $e->getMessage(),
                422,
                [
                    'required_minor' => $e->coverage['required_minor'],
                    'settled_minor' => $e->coverage['settled_minor'],
                    'uncovered_count' => count($e->coverage['uncovered']),
                    'incoming_unsettled_count' =>
                        $e->coverage['incoming_unsettled_count'],
                    'liability_count' => $e->coverage['liability_count'],
                ],
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $payload = $this->serialize($result, $request);
        if ($command === PayrollRunCommand::PREPARE_PAYMENTS->value) {
            /*
             * Běh, kde není co platit (celá čistá mzda je zápočet na účet
             * společníka), by na tlačítko čekal marně — pokrytí je úplné hned.
             * Stejně tak běh, jehož závazky se stihly spárovat dřív, než se
             * platební dávka připravila. Ostatní zůstanou v `payment_ready`
             * a překlopí je až spárovaný výpis.
             */
            $settlement = $this->autoSettlement->settleRun(
                $this->currentSupplierId($request),
                $runId,
                $this->requiredUserId($request),
            );
            $payload['run_settlement'] = $settlement->toArray();
        }

        return Json::ok($response, $payload);
    }

    private function dispatch(
        string $command,
        int $supplierId,
        int $runId,
        int $version,
        string $idempotencyKey,
        int $userId,
        string $reason,
    ): PayrollRunCommandResult {
        return match ($command) {
            'lock_inputs' => $this->commands->lockInputs(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            // Jeden krok účetní, dva příkazy v jedné transakci a dvě auditní
            // události. Samostatný `lock_inputs` zůstává dostupný pro API
            // a pro opravné revize.
            'lock_and_calculate' => $this->commands->lockAndCalculate(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'calculate' => $this->commands->calculate(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'refresh_inputs' => $this->commands->refreshInputs(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'review' => $this->commands->review(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'approve' => $this->commands->approve(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'request_correction' => $this->commands->requestCorrection(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'reopen' => $this->commands->reopen(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'cancel' => $this->commands->cancel(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'post' => $this->commands->post(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'prepare_payments' => $this->commands->preparePayments(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'mark_paid' => $this->commands->markPaid(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'close' => $this->commands->close(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            default => throw new \InvalidArgumentException(
                'Nepodporovaný příkaz mzdového běhu.',
            ),
        };
    }

    /** @return array<string,mixed> */
    private function serialize(PayrollRunCommandResult $result, Request $request): array
    {
        return [
            'command' => $result->command->value,
            'from_status' => $result->from->value,
            'to_status' => $result->to->value,
            'run' => $result->run,
            'revision' => self::disclosedRevision($result->revision, $request),
            'idempotent_replay' => $result->idempotentReplay,
            'outcome' => $result->outcome?->toArray(),
        ];
    }

    /**
     * Revize v odpovědi příkazu nese oba snímky. Výsledný se zúží stejně jako
     * v detailu běhu; vstupní nese podklady srážek všech osob a žádná obrazovka
     * ho z odpovědi nečte, takže ho volající bez práv k exekucím a insolvencím
     * nedostane vůbec.
     *
     * @param array<string,mixed>|null $revision
     * @return array<string,mixed>|null
     */
    private static function disclosedRevision(?array $revision, Request $request): ?array
    {
        if ($revision === null) {
            return null;
        }
        $enforcement = PayrollEnforcementDisclosure::canSeeEnforcement($request);
        $insolvency = PayrollEnforcementDisclosure::canSeeInsolvency($request);
        if ($enforcement && $insolvency) {
            return $revision;
        }
        $snapshot = $revision['result_snapshot'] ?? null;
        $revision['result_snapshot'] = PayrollEnforcementDisclosure::redactRunSnapshot(
            is_array($snapshot) ? $snapshot : null,
            $enforcement,
            $insolvency,
        );
        if (array_key_exists('input_snapshot', $revision)) {
            $revision['input_snapshot'] = null;
        }
        // Surové JSON sloupce nesou totéž v nezúžené podobě.
        unset($revision['result_snapshot_json'], $revision['input_snapshot_json']);

        return $revision;
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
        bool $allowBearer = false,
    ): ?Response {
        if (!$allowBearer && !RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Pole {$key} je povinné.");
        }
        return trim($value);
    }

    private function requiredUserId(Request $request): int
    {
        return $this->userId($request)
            ?? throw new \DomainException('Uživatel mzdového příkazu není dostupný.');
    }

    private function optionalPeriod(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('Období musí mít formát YYYY-MM.');
        }
        return $value;
    }
}
