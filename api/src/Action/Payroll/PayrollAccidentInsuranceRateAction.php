<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení sazby zákonného pojištění odpovědnosti zaměstnavatele
 * (vyhláška č. 125/1993 Sb.). `institution_code` odkazuje na řádek
 * v ověřeném registru institucí ({@see PayrollInstitutionAccountsAction},
 * `institution_type = 'statutory_insurance'`), kde firma vede účet a VS
 * pojistitele — tady se ukládá jen sazba a od kdy platí.
 */
final class PayrollAccidentInsuranceRateAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollAccidentInsuranceRateRepository $rates,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'rates' => $this->rates->list($this->currentSupplierId($request)),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $institutionCode = strtoupper(trim((string) ($body['institution_code'] ?? '')));
        $rateRaw = trim((string) ($body['rate_per_mille'] ?? ''));
        $effectiveFrom = trim((string) ($body['effective_from'] ?? ''));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $institutionCode) !== 1) {
            return Json::error(
                $response,
                'validation_failed',
                'Kód pojistitele musí odpovídat účtu vedenému v nastavení institucí.',
                422,
            );
        }
        if (preg_match('/^[0-9]{1,3}(?:[.,][0-9]{1,2})?$/D', $rateRaw) !== 1) {
            return Json::error(
                $response,
                'validation_failed',
                'Sazba pojistného musí být kladné číslo v promile s nejvýše dvěma desetinnými místy.',
                422,
            );
        }
        $rate = str_replace(',', '.', $rateRaw);
        if ((float) $rate <= 0 || (float) $rate > 1000) {
            return Json::error(
                $response,
                'validation_failed',
                'Sazba pojistného musí být kladné číslo v promile.',
                422,
            );
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $effectiveFrom) {
            return Json::error(
                $response,
                'validation_failed',
                'Datum účinnosti sazby musí být platné datum ve tvaru RRRR-MM-DD.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $id = $this->rates->insert(
                $supplierId,
                $institutionCode,
                $rate,
                $effectiveFrom,
                $this->userId($request),
            );
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return Json::error(
                $response,
                'accident_insurance_rate_effective_from_conflict',
                'Pro toto datum účinnosti už sazba existuje.',
                409,
            );
        }

        $created = array_values(array_filter(
            $this->rates->list($supplierId),
            static fn (array $row): bool => $row['id'] === $id,
        ))[0] ?? null;

        return Json::ok($response, ['rate' => $created], 201);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        $error = null;
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        if (!$this->requirePermission($request, $response, 'payroll.settings', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
