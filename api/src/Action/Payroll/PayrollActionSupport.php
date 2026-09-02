<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait PayrollActionSupport
{
    private function currentSupplierId(Request $request): int
    {
        return SupplierGuard::currentId($request);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Uzavřený mzdový rok blokuje zápis napříč agendami — nepřítomnosti,
     * exekuce, závazky, podání i běhy. Věta o tom stála sama: řekla rok a
     * poradila „rok nejprve znovu otevřete", ale nikam nevedla. Účetní musela
     * uhodnout, že se to dělá na Roční uzávěrce mezd, a doklikat se tam.
     *
     * Rok proto jede v odpovědi jako údaj, ne jen ve větě, a frontend z něj
     * staví proklik rovnou na tu uzávěrku. Kód `payroll_year_closed` zůstává
     * beze změny, aby se stávající obsluha nerozbila.
     */
    private static function yearClosedError(
        Response $response,
        PayrollYearClosedException $exception,
    ): Response {
        return Json::error(
            $response,
            'payroll_year_closed',
            $exception->getMessage(),
            409,
            ['year' => $exception->year],
        );
    }

    /**
     * Zúžení seznamu na jeden vztah / jednu osobu z query stringu.
     *
     * Chybějící parametr znamená „bez zúžení". Cokoli jiného se předá dál jako
     * číslo, i když je nesmyslné — nečíselnou hodnotu odmítne repozitář hláškou
     * místo aby ji potichu zahodil a vrátil celý seznam. Tiché ignorování zúžení
     * je horší než chyba: z celého seznamu uživatel usoudí, že filtr nezabral.
     *
     * @param array<array-key,mixed> $query
     */
    private static function narrowingId(array $query, string $name): ?int
    {
        $value = $query[$name] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && trim($value) !== '' ? (int) $value : null;
    }

    private function requirePermission(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $minimum,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::allows($request, $permission, $minimum)) {
            $error = Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
            return false;
        }
        $error = null;
        return true;
    }

    private function requirePayrollEnabled(
        Request $request,
        Response $response,
        PayrollModuleAccess $access,
        ?Response &$error,
    ): bool {
        if (!$access->isEnabled($this->currentSupplierId($request))) {
            $error = Json::error(
                $response,
                'payroll_disabled',
                'Vedení mezd je pro tuto firmu vypnuté v nastavení.',
                403,
            );
            return false;
        }
        $error = null;
        return true;
    }
}
