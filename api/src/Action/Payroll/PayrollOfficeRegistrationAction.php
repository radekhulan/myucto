<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollOfficeRegistrationConflictException;
use MyInvoice\Repository\Payroll\PayrollOfficeRegistrationRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollOfficeRegistrationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollOfficeRegistrationRepository $registrations,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{officeId:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) return $error;
        return Json::ok($response, ['registrations' => $this->registrations->list(
            $this->currentSupplierId($request), (int) $args['officeId'],
        )]);
    }

    /** @param array{officeId:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $date = trim((string) ($body['effective_from'] ?? ''));
        $symbol = trim((string) ($body['social_security_variable_symbol'] ?? ''));
        $source = trim((string) ($body['source_reference'] ?? ''));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateIsValid = $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
        // Zdroj (odkaz na výměr) je nepovinný: byla to naše poznámka do evidence,
        // ne požadavek ČSSZ, a přesto kvůli ní nešlo uložit VS opsaný z papíru.
        // VS povinný zůstává — ČSSZ ho přiděluje jako identifikátor plátce
        // pojistného a jde na každý platební příkaz i do podání.
        if (!$dateIsValid || !preg_match('/^\\d{10}$/', $symbol) || mb_strlen($source) > 500) {
            return Json::error($response, 'validation_failed', 'Registrace vyžaduje datum účinnosti a desetimístný variabilní symbol ČSSZ.', 422);
        }
        // Kontrolní číslici a kód okresu hlídá stejné pravidlo jako příprava
        // podání ČSSZ. Jen u nového nebo měněného symbolu: verze se stejným VS
        // jako dosud nic nemění a zástupné symboly starších instalací ji
        // blokovat nesmí.
        $newest = $this->registrations->list($this->currentSupplierId($request), (int) $args['officeId'])[0] ?? null;
        $reason = ($newest['social_security_variable_symbol'] ?? null) === $symbol
            ? null
            : CsszEmployerVariableSymbol::invalidReason($symbol);
        if ($reason !== null) {
            return Json::error(
                $response,
                'payroll_office_variable_symbol_invalid',
                "Variabilní symbol ČSSZ {$symbol} není platný: {$reason}. ČSSZ by podání "
                    . 'odmítla. Opište symbol přesně z oznámení ČSSZ.',
                422,
                ['field' => 'social_security_variable_symbol', 'reason' => $reason],
            );
        }
        try {
            $registration = $this->registrations->add($this->currentSupplierId($request), (int) $args['officeId'], $date, $symbol, $source, $this->userId($request));
        } catch (PayrollOfficeRegistrationConflictException $exception) {
            return Json::error(
                $response,
                'office_registration_effective_from_conflict',
                'Datum účinnosti musí navazovat za poslední uloženou verzi (účinná od '
                    . $exception->latestEffectiveFrom . ').',
                409,
                ['latest_effective_from' => $exception->latestEffectiveFrom],
            );
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '45000'], true)) {
                throw $exception;
            }
            return Json::error($response, 'office_registration_effective_from_conflict', 'Datum účinnosti musí navazovat za poslední uloženou verzi.', 409);
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        }
        return Json::ok($response, ['registration' => $registration], 201);
    }

    /**
     * Vezme zpět nejnovější verzi registrace.
     *
     * Existuje kvůli jediné, ale zákeřné chybě: variabilní symbol přiděluje
     * ČSSZ při registraci zaměstnavatele, ne v den, kdy ho účetní opíše.
     * Kdo ho uložil s dnešním datem, zablokoval si mzdy za předchozí měsíce
     * a dřívější účinnost už doplnit nešlo. Bez téhle cesty z toho aplikace
     * ven nevedla vůbec.
     *
     * @param array{officeId:string,registrationId:string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $supplierId = $this->currentSupplierId($request);
        $officeId = (int) $args['officeId'];
        $registrationId = (int) $args['registrationId'];
        try {
            $removed = $this->registrations->deleteNewest($supplierId, $officeId, $registrationId);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '45000'], true)) {
                throw $exception;
            }
            // Trigger pustí jen nejnovější verzi. Starší se opravují novou
            // verzí, ne přepsáním — podle nich se už mohlo počítat a podávat.
            return Json::error(
                $response,
                'office_registration_not_newest',
                'Vzít zpět jde jen poslední uloženou registraci. Starší verze '
                    . 'opravte tím, že přidáte novou s pozdějším datem účinnosti.',
                409,
            );
        }
        if (!$removed) {
            return Json::error($response, 'not_found', 'Registrace nebyla nalezena.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        $error = null;
        if (!RequestAuthorization::actsAsSession($request)) return Json::sessionRequired($response);
        if (!$this->requirePermission($request, $response, 'payroll.settings', $level, $error)) return $error;
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) return $error;
        return null;
    }
}
