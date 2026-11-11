<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Http\IsoDate;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollHourlyWageCollisionGuard;
use MyInvoice\Repository\Payroll\PayrollMealEntitlementBasisLockedException;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\PayrollEmploymentMergeService;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollFieldValidationException;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmploymentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly PayrollEmploymentJmhzEvidenceCatalog $jmhzEvidence,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollEmploymentDeletionRepository $deletion,
        private readonly PayrollEmploymentMergeService $merge,
        private readonly PayrollHourlyWageCollisionGuard $wageGuard,
        private readonly ?PayrollRulesetProvider $rulesets = null,
    ) {}

    public function jmhzEvidenceOptions(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        return Json::ok($response, ['options' => $this->jmhzEvidence->options()]);
    }

    public function remunerationOptions(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) return $error;
        $date = $request->getQueryParams()['effective_from'] ?? null;
        if (!is_string($date) || !IsoDate::isValid($date)) {
            return Json::error($response, 'validation_failed', 'Datum účinnosti není platné.', 422);
        }
        try {
            $version = $this->rulesets?->forCalculation(PayrollRulesetDomain::EmploymentThresholds, $date);
        } catch (PayrollRulesetException) {
            $version = null;
        }
        $floor = $version === null ? null : MinimumWageFloor::fromVersion($version);
        return Json::ok($response, [
            'minimum_hourly_40h_minor' => $floor?->baseHourlyMinor,
            'ruleset_id' => $floor?->rulesetId,
        ]);
    }

    public function jmhzMunicipalities(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $search = is_string($query['q'] ?? null) ? $query['q'] : '';
            $limitRaw = $query['limit'] ?? 20;
            $limit = filter_var($limitRaw, FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new \InvalidArgumentException('Limit vyhledávání obcí není celé číslo.');
            }
            return Json::ok($response, [
                'items' => $this->jmhzEvidence->municipalities($search, $limit),
                'external_codebooks' => $this->jmhzEvidence->externalCodebookProvenance(),
            ]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        $warnings = [];
        try {
            $employment = $this->employments->create(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->create($this->body($request), $warnings),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment, 'warnings' => $warnings], 201);
    }

    /** @param array{id:string} $args */
    public function addTerms(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, true)) !== null) {
            return $error;
        }
        $warnings = [];
        try {
            $body = $this->body($request);
            $supplierId = $this->currentSupplierId($request);
            $employmentId = (int) $args['id'];
            $body = $this->bearerSalaryBody(
                $request,
                $supplierId,
                $employmentId,
                $body,
                true,
            );
            $current = $this->employments->currentTerms($supplierId, $employmentId);
            $terms = $this->validator->terms(
                $body,
                // Uložený kód CZ-ISCO smí projít, i když v číselníku není —
                // hodnotu bere validátor odsud, nikdy z požadavku klienta.
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                // Zařazení pro srážkovou daň drží klient jen tehdy, když ho
                // zná; jinak se přebírá to uložené, ať ho uložení podmínek
                // nešoupne zpátky na „neurčeno".
                $this->employments->currentOtherWithholdingEligibility(
                    $supplierId,
                    $employmentId,
                ),
                $this->employments->currentRelationType($supplierId, $employmentId),
                // Číselníkové kontroly polí, která se proti uložené verzi
                // nemění, uložení neblokují — vrátí se jako varování.
                $current,
                $warnings,
            );
            $this->assertHourlySwitchKeepsImports($supplierId, $employmentId, $current, $terms, $body);
            $employment = $this->employments->addTerms(
                $supplierId,
                $employmentId,
                $terms,
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
                // Obrazovky, které mzdu nenabízejí, klíč vůbec neposílají
                // a do nové verze se přenese hodnota předchozí verze.
                array_key_exists('monthly_gross_minor', $body),
                $this->validator->optionalMonthlyGrossMinor($body),
                $this->endRecurringBase($body),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment, 'warnings' => $warnings]);
    }

    /**
     * OPRAVA platné verze podmínek — beze vzniku nové verze.
     *
     * Why: dokud byla jediná cesta k úpravě „nová verze podmínek", zakládal
     * překlep v úvazku nebo doplněná účtárna novou verzi s vlastním datem
     * účinnosti. Do historie se tím zapsalo, že se podmínky k tomu datu
     * změnily, přestože se jen opravoval už zapsaný údaj.
     *
     * `effective_from` se z těla bere JEN u relace přihlášeného uživatele a
     * jen jako výslovná oprava (`correct_effective_from: true`).
     *
     * Why: formulář nové verze nabízí jako výchozí účinnost DNEŠEK. Kdo přidal
     * verzi dřív, než si vzpomněl, že zvýšení platí od 1. 7., si tím zapsal
     * špatné datum — a už ho neuměl vrátit: opravu účinnost přepisovala
     * uloženou hodnotou (mlčky, bez chyby), nová verze musí začínat POZDĚJI
     * a smazat verzi nejde. Cesta zpět proto vede tudy; posunout se smí jen
     * za předchozí verzi a mimo období, za které je mzda zaúčtovaná nebo
     * vyplacená. Bez příznaku se chová beze změny — token jím nesmí hýbat.
     *
     * @param array{id:string} $args
     */
    public function correctTerms(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, true)) !== null) {
            return $error;
        }
        $warnings = [];
        try {
            $body = $this->body($request);
            $supplierId = $this->currentSupplierId($request);
            $employmentId = (int) $args['id'];
            $current = $this->employments->currentTermsEffectiveFrom($supplierId, $employmentId);
            if ($current === null) {
                throw new \DomainException('Pracovní vztah nemá žádnou verzi podmínek k opravě.');
            }
            $body = $this->bearerSalaryBody(
                $request,
                $supplierId,
                $employmentId,
                $body,
                false,
            );
            $correctEffectiveFrom = $body['correct_effective_from'] ?? false;
            if (!is_bool($correctEffectiveFrom)) {
                throw new \InvalidArgumentException('Pole correct_effective_from musí být boolean.');
            }
            if (!RequestAuthorization::isSessionAuth($request)) {
                $correctEffectiveFrom = false;
            }
            if (!$correctEffectiveFrom) {
                $body['effective_from'] = $current;
            }
            $currentTerms = $this->employments->currentTerms($supplierId, $employmentId);
            $terms = $this->validator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility(
                    $supplierId,
                    $employmentId,
                ),
                $this->employments->currentRelationType($supplierId, $employmentId),
                $currentTerms,
                $warnings,
            );
            $this->assertHourlySwitchKeepsImports($supplierId, $employmentId, $currentTerms, $terms, $body);
            $employment = $this->employments->correctTerms(
                $supplierId,
                $employmentId,
                $terms,
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
                array_key_exists('monthly_gross_minor', $body),
                $this->validator->optionalMonthlyGrossMinor($body),
                $correctEffectiveFrom,
                $this->endRecurringBase($body),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment, 'warnings' => $warnings]);
    }

    /**
     * Označení vztahu pro import docházky.
     *
     * Kód se generuje sám a uživatel ho běžně nevidí — jenže je to párovací klíč
     * CSV importu, takže kdo importuje z docházkového systému, musí ho umět
     * srovnat s tím, co posílá druhá strana. Dřív byl po založení neměnný, což
     * u importního klíče znamenalo založit vztah znovu.
     *
     * @param array{id:string} $args
     */
    public function rename(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $employment = $this->employments->rename(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->code($body),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Oprava druhu vztahu založeného s nesprávným druhem. Pojistky (běh,
     * podání, schválené vstupy a docházka…) hlídá repozitář pod zámkem vztahu.
     *
     * @param array{id:string} $args
     */
    public function changeRelationType(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $relationType = $body['relation_type'] ?? null;
            if (!is_string($relationType)
                || !in_array($relationType, PayrollEmploymentRepository::RELATION_TYPE_CORRECTABLE, true)
            ) {
                throw new PayrollFieldValidationException(
                    'Vyberte druh vztahu: pracovní poměr, zaměstnání malého rozsahu, DPP nebo DPČ.',
                    'relation_type',
                );
            }
            $note = $body['note'] ?? null;
            if ($note !== null && !is_string($note)) {
                throw new PayrollFieldValidationException('Důvod opravy musí být text.', 'note');
            }
            $note = $note === null ? null : mb_substr(trim($note), 0, 500);
            $result = $this->employments->changeRelationType(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $relationType,
                $this->validator->rowVersion($body),
                $note === '' ? null : $note,
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, $result);
    }

    /**
     * Sloučení vztahu do jiného vztahu téže osoby: data se přesunou k cíli
     * a tento vztah se smaže. Pojistky hlídá služba pod zámkem obou vztahů.
     *
     * @param array{id:string} $args
     */
    public function mergeInto(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $targetId = $body['target_employment_id'] ?? null;
            if (!is_int($targetId) || $targetId <= 0) {
                throw new PayrollFieldValidationException('Vyberte vztah, do kterého se má tento vztah sloučit.', 'target_employment_id');
            }
            $targetVersion = $body['target_row_version'] ?? null;
            if (!is_int($targetVersion) || $targetVersion <= 0) {
                throw new PayrollFieldValidationException('Chybí verze cílového vztahu.', 'target_row_version');
            }
            $adoptTerms = $body['adopt_terms'] ?? false;
            if (!is_bool($adoptTerms)) {
                throw new PayrollFieldValidationException('Převzetí podmínek musí být ano, nebo ne.', 'adopt_terms');
            }
            $note = $body['note'] ?? null;
            if ($note !== null && !is_string($note)) {
                throw new PayrollFieldValidationException('Poznámka musí být text.', 'note');
            }
            $note = $note === null ? null : mb_substr(trim($note), 0, 500);
            $result = $this->merge->merge(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $targetId,
                $this->validator->rowVersion($body),
                $targetVersion,
                $adoptTerms,
                $note === '' ? null : $note,
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, $result);
    }

    /** @param array{id:string} $args */
    public function setMealEntitlementBasis(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $employment = $this->employments->setMealEntitlementBasis(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->requiredMealEntitlementBasis($body),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Člen statutárního orgánu je zároveň společníkem (odměna za výkon funkce
     * proti 366 místo 331, viz PayrollAccountingDefaults::relationGrossCredit()).
     *
     * @param array{id:string} $args
     */
    public function setStatutoryPartner(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $partner = $body['statutory_partner'] ?? null;
            if (!is_bool($partner)) {
                throw new \InvalidArgumentException('statutory_partner musí být true nebo false.');
            }
            $employment = $this->employments->setStatutoryPartner(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $partner,
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Nástup odhadnutý z hlášení je ověřený (smlouva, přihláška) a platí.
     *
     * @param array{id:string} $args
     */
    public function confirmStart(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $employment = $this->employments->confirmEstimatedStart(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->rowVersion($this->body($request)),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['employment' => $employment]);
    }

    /** @param array{id:string,target:string} $args */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->transition($this->body($request));
            $employment = $this->employments->transition(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['target'],
                $data['row_version'],
                $data['effective_on'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Smazání vztahu, který vůbec neměl vzniknout.
     *
     * Nenahrazuje `no_show` — „nenástup" je záznam o tom, že něco nastalo (člověk
     * byl přijat a nenastoupil). Tohle je pro případ, kdy se to nemělo stát vůbec,
     * a nemá po sobě nechat fiktivní nenástup v evidenci.
     *
     * Právo je `payroll.employment.write`, tedy TOTÉŽ, kterým se vztah zakládá:
     * mazání omylem založeného vztahu je opak jeho založení, ne přísnější úkon.
     * Před skutečnými záznamy chrání blokátory v repozitáři, ne zvláštní právo.
     *
     * @param array{id:string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $cascade = $this->deletion->delete(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    /** @param array{id:string,item_key:string} $args */
    public function checklist(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->checklist($this->body($request));
            $employment = $this->employments->updateChecklist(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['item_key'],
                $data['row_version'],
                $data['status'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
                $data['requested_on'],
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    private function authorize(
        Request $request,
        Response $response,
        bool $allowBearer = false,
    ): ?Response
    {
        if (!$allowBearer && RequestAuthorization::isBearerAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }
        return null;
    }

    private function authorizeRead(Request $request, Response $response): ?Response
    {
        if (RequestAuthorization::isBearerAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
            $error,
        )) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }
        return null;
    }

    /** @param array<string,mixed> $body
     *  @return array<string,mixed>
     */
    private function bearerSalaryBody(
        Request $request,
        int $supplierId,
        int $employmentId,
        array $body,
        bool $newTerms,
    ): array {
        if (!RequestAuthorization::isBearerAuth($request)) {
            return $this->withStoredTerms($supplierId, $employmentId, $body);
        }
        $allowed = [
            'row_version' => true,
            'monthly_gross_minor' => true,
            'change_reason' => true,
        ];
        if ($newTerms) {
            $allowed['effective_from'] = true;
        }
        if (array_diff_key($body, $allowed) !== []) {
            throw new \InvalidArgumentException(
                'Token může v pracovních podmínkách změnit pouze sjednanou mzdu.',
            );
        }
        if (($newTerms && !array_key_exists('effective_from', $body))
            || !array_key_exists('monthly_gross_minor', $body)
            || !array_key_exists('change_reason', $body)
        ) {
            throw new \InvalidArgumentException(
                $newTerms
                    ? 'Změna sjednané mzdy vyžaduje datum účinnosti, částku a důvod.'
                    : 'Oprava sjednané mzdy vyžaduje částku a důvod.',
            );
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId);
        if ($current === null) {
            throw new \DomainException('Pracovní vztah nemá žádnou verzi podmínek.');
        }
        if (($current['remuneration_type'] ?? null) === 'hourly') {
            throw new \InvalidArgumentException('U hodinového vztahu nelze měnit měsíční mzdu. Sazbu upravte na kartě vztahu.');
        }
        return [...$current, ...$body];
    }

    /**
     * Klíč, který v těle chybí, drží hodnotu platné verze podmínek; výslovný
     * null ji maže. Nepřebírá se `effective_from` (nová verze ho musí poslat,
     * oprava ho dosazuje sama), `change_reason` (poznámka k tomuto zápisu),
     * `monthly_gross_minor` (dědí ho repozitář) ani `row_version` verze
     * podmínek — zámek patří pracovnímu vztahu.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function withStoredTerms(int $supplierId, int $employmentId, array $body): array
    {
        $current = $this->employments->currentTerms($supplierId, $employmentId);
        if ($current === null) {
            return $body;
        }
        unset(
            $current['id'],
            $current['effective_from'],
            $current['effective_to'],
            $current['change_reason'],
            $current['monthly_gross_minor'],
            $current['row_version'],
            $current['created_at'],
            $current['office_code'],
            // Odvozená hodnota sazbové kategorie; složená by s ní mohla kolidovat.
            $current['risky_work'],
        );
        if (array_key_exists('risky_work', $body)
            && !array_key_exists('social_employer_rate_category', $body)
        ) {
            unset($current['social_employer_rate_category']);
        }

        return [...$current, ...$body];
    }

    /**
     * Přechod na hodinové odměňování u vztahu, kterému se mzda importuje jako
     * hotová částka (typicky docházka), by tiše zablokoval každý další import:
     * {@see PayrollHourlyWageCollisionGuard::externalInputs()} ho pak odmítne.
     * Bez výslovného `confirm_imported_wage_switch` se proto přechod neuloží
     * a odpověď nabídne náhradu: pravděpodobný výdělek. Detekce je táž jako
     * v hromadné úpravě podmínek.
     *
     * @param array<string,mixed>|null $current
     * @param array<string,mixed> $terms
     * @param array<string,mixed> $body
     */
    private function assertHourlySwitchKeepsImports(
        int $supplierId,
        int $employmentId,
        ?array $current,
        array $terms,
        array $body,
    ): void {
        if ($terms['remuneration_type'] !== 'hourly'
            || ($current['remuneration_type'] ?? null) === 'hourly'
        ) {
            return;
        }
        $confirmed = $body['confirm_imported_wage_switch'] ?? false;
        if (!is_bool($confirmed)) {
            throw new PayrollFieldValidationException(
                'Pole confirm_imported_wage_switch musí být boolean.',
                'confirm_imported_wage_switch',
            );
        }
        if ($confirmed || $this->wageGuard->employmentsBlockingHourlySwitch(
            $supplierId,
            [$employmentId],
            (string) $terms['effective_from'],
        ) === []) {
            return;
        }
        throw new PayrollFieldValidationException(
            'Mzda se k vztahu importuje jako částka; hodinový výpočet ze směn by další import zablokoval. '
                . 'Nastavte místo sazby pravděpodobný výdělek, nebo potvrďte, že mzdu budete nově počítat '
                . 'z odpracovaných směn a import hodinové mzdy ukončíte.',
            'remuneration_type',
            'hourly_rate_imported_wage',
        );
    }

    /**
     * Výslovný souhlas s ukončením aktivního předpisu měsíční základní mzdy
     * ke dni před účinností hodinové verze. Bez něj repozitář přechod odmítne.
     *
     * @param array<string,mixed> $body
     */
    private function endRecurringBase(array $body): bool
    {
        $value = $body['end_recurring_base'] ?? false;
        if (!is_bool($value)) {
            throw new PayrollFieldValidationException('Pole end_recurring_base musí být boolean.', 'end_recurring_base');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof PayrollEmploymentNotFoundException => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            // Blokace mazání není chyba uživatele — nese kód a větu, podle které
            // se dá jednat, takže je frontend ukazuje místo zašedlého tlačítka.
            $e instanceof PayrollDeletionException => Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
                array_filter([
                    'employment_id' => $e->employmentId,
                    'employment_code' => $e->employmentCode,
                ], static fn ($value): bool => $value !== null),
            ),
            $e instanceof PayrollEmploymentConflictException => Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            ),
            $e instanceof PayrollMealEntitlementBasisLockedException => Json::error(
                $response,
                'meal_entitlement_basis_locked',
                $e->getMessage(),
                409,
            ),
            // Oprava na místě už nejde, ale nová verze ano — kód nese období,
            // od kterého je zúčtováno, aby frontend uměl nabídnout tu druhou cestu.
            $e instanceof PayrollTermsSettledException => Json::error(
                $response,
                'payroll_terms_settled',
                $e->getMessage(),
                409,
                ['settled_period' => $e->settledPeriod],
            ),
            $e instanceof \DomainException => Json::error(
                $response,
                'invalid_transition',
                $e->getMessage(),
                409,
            ),
            $e instanceof \InvalidArgumentException => Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
                PayrollFieldValidationException::responseExtra($e),
            ),
            $e instanceof \PDOException && $e->getCode() === '23000' => Json::error(
                $response,
                'employment_conflict',
                'Pracovní vztah koliduje s existujícím kódem, intervalem nebo primárním vztahem.',
                409,
            ),
            default => throw $e,
        };
    }
}
