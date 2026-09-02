<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use DateTimeImmutable;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollForeignPermitException;
use MyInvoice\Repository\Payroll\PayrollForeignPermitRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollForeignPermitAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollForeignPermitRepository $permits,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{id:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď.');
        }
        $asOf = $this->asOf($request->getQueryParams()['as_of'] ?? null);
        if ($asOf === null) {
            return Json::error($response, 'validation_failed', 'as_of musí být datum YYYY-MM-DD.', 422);
        }
        $view = $this->permits->view($this->currentSupplierId($request), (int) $args['id'], $asOf);
        if ($view !== null && (
            !RequestAuthorization::allows($request, 'documents', AccessLevel::READ)
            || !RequestAuthorization::allows($request, 'payroll.person.write', AccessLevel::READ)
        )) {
            foreach ($view['history'] as &$permit) {
                $permit['document_id'] = null;
            }
            unset($permit);
        }
        $result = $view === null
            ? Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404)
            : Json::ok($response, ['permits' => $view]);
        return $result->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď.');
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
        }
        /*
         * Týž endpoint zapisuje i OPRAVU a SMAZÁNÍ.
         *
         * Oprávnění bývalo neměnné (trigger zakazoval UPDATE i DELETE) a
         * formulář nabízí jako výchozí účinnost dnešek, takže překlep byl
         * trvalý. Cesta zpět musí existovat a nesmí čekat na novou routu:
         * `id` v těle znamená „oprav tenhle záznam", `delete: true` znamená
         * „smaž ho". Bez `id` je chování beze změny — vloží se nový záznam.
         */
        $permitId = $this->permitId($body['id'] ?? null);
        if ($permitId === false) {
            return Json::error($response, 'validation_failed', 'Pole id musí být kladné celé číslo.', 422);
        }
        $delete = $body['delete'] ?? false;
        if (!is_bool($delete)) {
            return Json::error($response, 'validation_failed', 'Pole delete musí být boolean.', 422);
        }
        if ($delete && $permitId === null) {
            return Json::error($response, 'validation_failed', 'Smazání vyžaduje id oprávnění.', 422);
        }

        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['id'];
        $actorId = $this->userId($request) ?? throw new \LogicException('Chybí uživatel relace.');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        try {
            $view = match (true) {
                $delete => $this->permits->remove($supplierId, $employeeId, (int) $permitId, $actorId, $today),
                $permitId !== null => $this->permits->correct(
                    $supplierId,
                    $employeeId,
                    $permitId,
                    $body,
                    $actorId,
                    $today,
                ),
                default => $this->permits->create($supplierId, $employeeId, $body, $actorId, $today),
            };
        } catch (PayrollForeignPermitException $exception) {
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->getCode());
        }

        return Json::ok($response, ['permits' => $view])
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function guard(Request $request, Response $response, AccessLevel $level, ?Response &$error): bool
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            $error = Json::error($response, 'session_required', 'Tento endpoint je dostupný pouze z přihlášené relace.', 403);
            return false;
        }
        if ($level === AccessLevel::READ) {
            if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
                return false;
            }
        } elseif (!$this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error)
            || !$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return false;
        }
        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    /** @return int|null|false false = neplatná hodnota */
    private function permitId(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : false;
    }

    private function asOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
