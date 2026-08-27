<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementConflictException;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementAnnualClaims;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementFilingObligation;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPriorEmployers;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequest;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequestStatus;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementUnavailableException;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP).
 *
 * Čtyři operace: přehled roku, evidence žádosti, náhled výsledku a jeho
 * provedení. Náhled je oddělený od provedení schválně — provedení je právní
 * úkon plátce daně a nesmí se stát jen tím, že se někdo podívá.
 */
final class PayrollAnnualSettlementAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly AnnualTaxSettlementService $settlements,
        private readonly PayrollAnnualSettlementRepository $repository,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $year = self::year($args);
        if ($year === null) {
            return self::invalid($response);
        }
        $supplierId = $this->currentSupplierId($request);
        $query = $request->getQueryParams();
        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollAnnualSettlementRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollAnnualSettlementRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $search = is_string($query['search'] ?? null) ? trim($query['search']) : '';
        $state = is_string($query['state'] ?? null) ? trim($query['state']) : 'all';
        try {
            $page = $this->repository->listForYear(
                $supplierId,
                $year,
                $limit,
                $offset,
                $search,
                $state,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'tax_year' => $year,
            'request_deadline' =>
                AnnualSettlementStatute::requestDeadline($year)->format('Y-m-d'),
            'settlement_deadline' =>
                AnnualSettlementStatute::settlementDeadline($year)->format('Y-m-d'),
            'payout_period' =>
                AnnualSettlementStatute::payoutPeriodStart($year)->format('Y-m'),
            'payout_threshold_minor' =>
                AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            'items' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'state' => $state,
        ]);
    }

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        if ($year === null || $employeeId <= 0) {
            return self::invalid($response);
        }
        try {
            $preview = $this->settlements->preview(
                $this->currentSupplierId($request),
                $employeeId,
                $year,
            );
        } catch (AnnualSettlementUnavailableException $exception) {
            return Json::error(
                $response,
                'annual_settlement_unavailable',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'tax_year' => $year,
            'employee_id' => $employeeId,
            'request' => $preview['request'],
            'result' => $preview['result']->jsonSerialize(),
            'credit_rows' => $preview['credit_rows'],
            'child_rows' => $preview['child_rows'],
            'certificates' => $preview['certificates'],
            'already_settled' => $preview['already_settled'],
        ]);
    }

    /**
     * Zápis potvrzení od předchozích plátců daně (§ 38ch odst. 3).
     *
     * Pod `payroll.approve`, ne pod `payroll.documents`. Ta čísla jdou přímo do
     * úhrnu, ze kterého vychází přeplatek — kdo je smí zadat, ten fakticky
     * rozhoduje o penězích, stejně jako ten, kdo zúčtování provede. Volněji by
     * to znamenalo, že zúčtování sice schvaluje jeden člověk, ale podklad pro
     * jeho výsledek může beze stopy změnit kdokoli s právem na tisk.
     *
     * @param array<string,string> $args
     */
    public function saveCertificates(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        if (!$this->approvalGuard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $userId = $this->userId($request);
        if ($year === null || $employeeId <= 0 || $userId === null) {
            return self::invalid($response);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $rows = $body['certificates'] ?? null;
        if (!is_array($rows)) {
            return self::invalid($response);
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return self::invalid($response);
            }
            $clean[] = $row;
        }
        $supplierId = $this->currentSupplierId($request);

        try {
            $saved = $this->settlements->saveCertificates(
                $supplierId,
                $employeeId,
                $year,
                $clean,
                $userId,
            );
        } catch (PayrollAnnualSettlementConflictException $exception) {
            return Json::error($response, 'row_version_conflict', $exception->getMessage(), 409);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        $this->activity->log(
            'payroll.annual_settlement_certificates_saved',
            $userId,
            'payroll_employee',
            $employeeId,
            ['tax_year' => $year, 'count' => count($saved)],
            $this->ipMatcher->clientIpFromRequest(self::serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'tax_year' => $year,
            'employee_id' => $employeeId,
            'certificates' => $saved,
        ]);
    }

    /** @param array<string,string> $args */
    public function saveRequest(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        if ($year === null || $employeeId <= 0) {
            return self::invalid($response);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $status = AnnualSettlementRequestStatus::tryFrom(
            (string) ($body['request_status'] ?? ''),
        );
        $prior = AnnualSettlementPriorEmployers::tryFrom(
            (string) ($body['prior_employers'] ?? ''),
        );
        $filing = AnnualSettlementFilingObligation::tryFrom(
            (string) ($body['filing_obligation'] ?? ''),
        );
        $claims = AnnualSettlementAnnualClaims::tryFrom(
            (string) ($body['annual_claims'] ?? ''),
        );
        if ($status === null || $prior === null || $filing === null || $claims === null) {
            return self::invalid($response);
        }

        $caregiverStatus = (string) ($body['other_household_caregiver_status'] ?? 'unknown');
        try {
            $caregivers = self::caregivers(
                $caregiverStatus,
                $body['other_household_caregivers'] ?? [],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        // Sestavením domény se vynutí tytéž podmínky, jaké hlídají CHECK
        // constrainty — validace tak žije jednou, ne zvlášť v akci a v databázi.
        try {
            $candidate = new AnnualSettlementRequest(
                $year,
                $status,
                self::date($body['requested_on'] ?? null),
                self::text($body['request_evidence_reference'] ?? null),
                $prior,
                self::date($body['prior_documents_received_on'] ?? null),
                $filing,
                self::text($body['filing_obligation_reason'] ?? null),
                $claims,
                self::text($body['annual_claims_note'] ?? null),
                self::text($body['note'] ?? null),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $expectedRowVersion = isset($body['row_version'])
            ? (int) $body['row_version']
            : null;
        try {
            $saved = $this->repository->saveRequest(
                $this->currentSupplierId($request),
                $employeeId,
                $year,
                [
                    'request_status' => $candidate->status->value,
                    'requested_on' => $candidate->requestedOn?->format('Y-m-d'),
                    'request_evidence_reference' => $candidate->requestEvidenceReference,
                    'prior_employers' => $candidate->priorEmployers->value,
                    'prior_documents_received_on' =>
                        $candidate->priorDocumentsReceivedOn?->format('Y-m-d'),
                    'filing_obligation' => $candidate->filingObligation->value,
                    'filing_obligation_reason' => $candidate->filingObligationReason,
                    'annual_claims' => $candidate->annualClaims->value,
                    'annual_claims_note' => $candidate->annualClaimsNote,
                    'other_household_caregiver_status' => $caregiverStatus,
                    'other_household_caregivers' => $caregivers,
                    'note' => $candidate->note,
                ],
                $expectedRowVersion,
                $this->userId($request),
            );
        } catch (PayrollAnnualSettlementConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
            );
        }

        return Json::ok($response, ['request' => $saved]);
    }

    /** @param array<string,string> $args */
    public function settle(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        if (!$this->approvalGuard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $userId = $this->userId($request);
        if ($year === null || $employeeId <= 0 || $userId === null) {
            return self::invalid($response);
        }
        $supplierId = $this->currentSupplierId($request);

        try {
            $settled = $this->settlements->settle(
                $supplierId,
                $employeeId,
                $year,
                $userId,
            );
        } catch (AnnualSettlementUnavailableException $exception) {
            return Json::error(
                $response,
                'annual_settlement_unavailable',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'annual_settlement_failed',
                'Roční zúčtování se nepodařilo provést. Zkontrolujte schválené '
                . 'mzdy za celý rok a zákonnou evidenci zaměstnance.',
                409,
            );
        }

        // Odmítnutí NENÍ chyba serveru: je to řádná odpověď na otázku, jestli
        // zúčtování provést lze. Vrací se 200 se seznamem překážek, aby je UI
        // uměl vypsat všechny najednou.
        if (!$settled['result']->performed) {
            return Json::ok($response, [
                'tax_year' => $year,
                'employee_id' => $employeeId,
                'performed' => false,
                'result' => $settled['result']->jsonSerialize(),
                'already_settled' => $settled['outcome'],
            ]);
        }

        if ($settled['created']) {
            $this->activity->log(
                'payroll.annual_settlement_performed',
                $userId,
                'payroll_employee',
                $employeeId,
                [
                    'tax_year' => $year,
                    'outcome' => $settled['result']->outcome?->value,
                    'settlement_difference_minor' =>
                        $settled['result']->settlementDifferenceMinorUnits,
                    'payable_minor' => $settled['result']->payableMinorUnits,
                ],
                $this->ipMatcher->clientIpFromRequest(self::serverParams($request)),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return Json::ok($response, [
            'tax_year' => $year,
            'employee_id' => $employeeId,
            'performed' => true,
            'created' => $settled['created'],
            'result' => $settled['result']->jsonSerialize(),
            'outcome' => $settled['outcome'],
            'document' => self::publicDocument($settled['document'] ?? []),
        ], $settled['created'] ? 201 : 200);
    }

    /**
     * Peněžní rozhodnutí — provedení zúčtování i zadání podkladu, ze kterého
     * vychází úhrn. Obojí pod `payroll.approve`, ne pod `payroll.documents`.
     */
    private function approvalGuard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        return $this->permissionGuard($request, $response, 'payroll.approve', $level, $error);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        return $this->permissionGuard($request, $response, 'payroll.documents', $level, $error);
    }

    private function permissionGuard(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        ) || !$this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        )) {
            $error ??= Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );

            return false;
        }

        return true;
    }

    /** @param array<string,string> $args */
    private static function year(array $args): ?int
    {
        $year = (int) ($args['year'] ?? 0);

        return $year >= 2000 && $year <= 2199 ? $year : null;
    }

    private static function invalid(Response $response): Response
    {
        return Json::error(
            $response,
            'validation_failed',
            'Požadavek na roční zúčtování není platný.',
            422,
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false || $date->format('Y-m-d') !== trim($value)
            ? null
            : $date;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{given_name:string,family_name:string,birth_date:string,months_mask:string}>
     */
    private static function caregivers(string $status, mixed $value): array
    {
        if (!in_array($status, ['unknown', 'none', 'present'], true)) {
            throw new \InvalidArgumentException('Stav jiného pečujícího není platný.');
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Seznam jiných pečujících není platný.');
        }
        if (count($value) > 100) {
            throw new \InvalidArgumentException(
                'Seznam jiných pečujících může obsahovat nejvýše 100 osob.',
            );
        }

        $rows = [];
        foreach (array_values($value) as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Údaj o jiném pečujícím není platný.');
            }
            $givenName = self::text($row['given_name'] ?? null);
            $familyName = self::text($row['family_name'] ?? null);
            $birthDate = self::date($row['birth_date'] ?? null);
            $monthsMask = strtoupper(trim((string) ($row['months_mask'] ?? '')));
            if ($givenName === null || mb_strlen($givenName) > 100
                || $familyName === null || mb_strlen($familyName) > 100
                || $birthDate === null
                || preg_match('/^[AN]{12}$/D', $monthsMask) !== 1
                || !str_contains($monthsMask, 'A')
            ) {
                throw new \InvalidArgumentException(
                    'Jméno, datum narození nebo měsíce jiného pečujícího nejsou platné.',
                );
            }
            $rows[] = [
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => $birthDate->format('Y-m-d'),
                'months_mask' => $monthsMask,
            ];
        }

        if ($status === 'present' && $rows === []) {
            throw new \InvalidArgumentException(
                'Při stavu „ano“ zadejte alespoň jednoho jiného pečujícího.',
            );
        }
        if ($status !== 'present' && $rows !== []) {
            throw new \InvalidArgumentException(
                'Seznam jiných pečujících smí být vyplněný jen při stavu „ano“.',
            );
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private static function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id', 'annual_revision_id', 'annual_revision_no', 'tax_year', 'purpose',
            'employee_id', 'employee_name', 'document_kind', 'document_revision_no',
            'file_sha256', 'size_bytes', 'mime_type', 'suggested_filename', 'created_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $document)) {
                $result[$key] = $document[$key];
            }
        }

        return $result;
    }
}
