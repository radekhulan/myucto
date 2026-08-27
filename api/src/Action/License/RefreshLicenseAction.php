<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/refresh — okamžitá obnova rozsahu z licenčního serveru.
 *
 * Zaplacené navýšení míst i rozšíření úložiště se do instalace propíše až
 * novým licenčním tokenem. Ten se běžně obnovuje jednou denně, takže po platbě
 * — a hlavně po platbě, která proběhla JINDE než v aplikaci (odkaz z e-mailu,
 * ruční potvrzení obsluhou) — koukal zákazník na staré počty a nechápal, za co
 * zaplatil. Tohle je to tlačítko „aktualizovat", aby nemusel čekat na cron.
 *
 * ⚠️ Nic nekupuje a nic nemění. Jen si řekne o čerstvý token; co v něm přijde,
 * rozhoduje licenční server. Proto stačí superadmin bez dalšího potvrzování.
 *
 * ⚠️ Nedostupný licenční server NENÍ chyba instalace. Odpověď se vrací ze
 * současného (byť staršího) stavu, ať obrazovka nezhasne kvůli výpadku sítě.
 */
final class RefreshLicenseAction
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        try {
            $this->license->forceRenew();
        } catch (\Throwable) {
            // Výpadek licenčního serveru není chyba instalace — stav zůstane ten,
            // který instalace má, a klient si ho stejně načte znovu.
        }

        // ⚠️ Stav se ZÁMĚRNĚ nevrací. Payload `/license/status` je bohatší
        // (blok `instance`, `company`, odkazy) a kdyby se sem složil znovu,
        // rozešel by se: obrazovka provozu z chybějícího `instance` usoudila,
        // že instalace není spravovaná, a ukázala „tahle instalace u nás
        // neběží". Klient si po obnově načte stav běžnou cestou.
        return Json::ok($response, ['refreshed' => true]);
    }
}
