<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/managed/license {envelope} — převzetí licence od provozovatele.
 *
 * Spravovanou instalaci dostává zákazník hotovou a licenční klíč nemá kam
 * opsat. Prvotní zřízení ho nese v setupu; tenhle endpoint je pro instalaci,
 * která už běží — dodatečné doručení, výměna klíče, oprava po nepovedeném
 * zřízení.
 *
 * ⚠️ BEZ session přihlášení schválně. Volá to licenční server, ne člověk,
 * a přihlásit se do zákazníkovy instalace nemá čím. Autentizace je proto
 * v samotné obálce: podepisuje ji Ed25519 klíč licenčního serveru a ověřuje
 * se veřejným klíčem, který aplikace už má zabudovaný
 * ({@see LicenseService::acceptManagedLicense()}). Nepodepsaný požadavek
 * neudělá nic.
 *
 * ⚠️ Odpověď je záměrně strohá. Podrobnosti o cizí licenci nemá komu na téhle
 * adrese sloužit — diagnostiku má volající u sebe.
 */
final class ManagedLicenseAction
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $envelope = trim((string) ($body['envelope'] ?? ''));
        if ($envelope === '') {
            return Json::error($response, 'validation_failed', 'Chybí podepsaná obálka.', 400);
        }

        $res = $this->license->acceptManagedLicense($envelope);
        if (($res['ok'] ?? false) !== true) {
            $error = (string) ($res['error'] ?? 'activation_failed');
            // Neplatný podpis a cizí adresát jsou odmítnutí, ne chyba vstupu.
            $status = in_array($error, ['invalid_signature', 'instance_mismatch', 'stale_envelope', 'wrong_purpose'], true)
                ? 403
                : ($error === 'server_unreachable' ? 503 : 422);
            return Json::error($response, $error, 'Licenci se nepodařilo převzít.', $status);
        }

        return Json::ok($response, ['activated' => true]);
    }
}
