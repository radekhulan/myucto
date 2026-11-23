<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Kontrola s jednoznačnou opravou.
 *
 * Oprava běží v jedné transakci, je idempotentní (opakované spuštění nic nezdvojí), každou
 * změnu zapíše do auditní stopy a doklady v uzavřeném, rozpracovaném nebo zamčeném účetním
 * období či v podaném období DPH nemění, jen je ohlásí ({@see MigrationCheckLocks}).
 */
interface FixableMigrationDataCheck extends MigrationDataCheck
{
    /**
     * Volby opravy, které musí uživatel výslovně potvrdit (klíč => popis). Bez nich oprava
     * proběhne v základním rozsahu.
     *
     * @return array<string,string>
     */
    public function options(): array;

    /**
     * Co by oprava změnila (bez zápisu).
     *
     * @param array<string,bool> $options
     */
    public function preview(int $supplierId, array $options = []): FixPreview;

    /**
     * Provede opravu. Volá se uvnitř transakce ({@see MigrationDataCheckService::fix()}): kontrola
     * spočte plán znovu nad zamčenými daty, ověří jeho otisk proti potvrzenému náhledu
     * ({@see FixPreview::assertConfirmed()}) a zapíše právě tento plán.
     *
     * @param array<string,bool> $options
     * @param ?string $fingerprint otisk potvrzeného náhledu; `null` jen z CLI (plán bez náhledu)
     */
    public function fix(int $supplierId, ?int $userId, array $options = [], ?string $fingerprint = null): FixResult;
}
