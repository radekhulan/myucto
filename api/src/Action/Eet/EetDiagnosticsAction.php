<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eet\EetDiagnosticsService;
use MyInvoice\Service\Eet\EetException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Ověřovací test EET (`overeni=true`). Úspěch (`ok`) znamená `Chyba kod=0`,
 * nikoli evidovanou tržbu; nic se neukládá do evidence.
 */
final class EetDiagnosticsAction
{
    public function __construct(
        private readonly EetDiagnosticsService $diagnostics,
        private readonly LoggerInterface $log,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = EetActionSupport::body($request);
        $rawDevice = $body['device_id'] ?? null;
        $deviceId = null;
        if ($rawDevice !== null && $rawDevice !== '') {
            $deviceId = filter_var($rawDevice, FILTER_VALIDATE_INT);
            if (!is_int($deviceId) || $deviceId <= 0) {
                return Json::error($response, EetException::VALIDATION, 'Neplatné zařízení.', 422);
            }
        }

        try {
            $result = $this->diagnostics->run(SupplierGuard::currentId($request), $deviceId, EetActionSupport::userId($request));
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        } catch (\Throwable $exception) {
            // Chyba transportu nesmí do odpovědi propsat nic o certifikátu ani zprávě.
            $this->log->error('EET diagnostika selhala', ['exception' => $exception::class]);
            return Json::error($response, 'eet_diagnostics_failed', 'Ověření spojení s EET se nepodařilo dokončit. Zkuste to znovu.', 502);
        }

        return Json::ok($response, $result);
    }
}
