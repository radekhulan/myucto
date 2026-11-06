<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService;

/** Syntetická akceptace výpočtu DPPO 504 pro testy finalizace, XML a podání. */
final class NonprofitDppoAcceptanceFixture
{
    public static function accept(Connection $db, int $supplierId, int $actorId): array
    {
        $service = new NonprofitDppoAcceptanceService($db);
        $state = $service->state($supplierId);
        if ($state['accepted']) return $state['head'];
        return $service->change($supplierId, NonprofitDppoAcceptanceService::ACCEPT, true,
            'Syntetické ověření výpočtu pro test', 'Syntetická akceptace testovací firmy',
            'synthetic-dppo-acceptance-' . $supplierId . '-' . bin2hex(random_bytes(4)), $state['head']['id'] ?? null, $actorId);
    }
}
