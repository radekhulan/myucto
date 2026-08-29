<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Krypto-výmaz vydaných mzdových dokumentů jedné osoby (W30 / C-06).
 *
 * Skládá se ze dvou kroků, protože osobní údaje leží ve dvou tvarech:
 *
 * 1. **Osobní dokumenty** (páska, mzdový list, zápočtový list, potvrzení) jsou
 *    zašifrované datovým klíčem té osoby. Stačí zahodit klíč
 *    ({@see PayrollDocumentKeyRing::destroy()}) — soubory i řádky v append-only
 *    evidenci zůstanou, obsah bude nevratně nečitelný.
 *
 * 2. **Měsíční balíček** ({@see PayrollDocumentKind::MonthlyBundle}) je odvozený
 *    agregát: jeden soubor za celou firmu, do kterého se pásky VŠECH osob
 *    zabalily v otevřené podobě. Je proto šifrovaný firemním klíčem, který
 *    zahodit nelze (zneškodnil by data ostatních). Bez druhého kroku by tedy
 *    páska vymazané osoby zůstala čitelná uvnitř balíčku a krypto-výmaz by byl
 *    jen zdánlivý.
 *
 *    U balíčku se proto maže SOUBOR. Jde to udělat, protože balíček není
 *    prvotní záznam — je celý rekonstruovatelný z jednotlivých dokumentů, které
 *    v archivu zůstávají. Řádek v `payroll_generated_documents` (a s ním doklad
 *    o vydání, hash i komu se balíček stáhl) se NEMAŽE; append-only ochrana
 *    z migrace 1231 zůstává nedotčená.
 *
 * ── Co z toho plyne dál ─────────────────────────────────────────────────────
 * Po výmazu už za dotčené období nejde vytvořit archivní export, který by nesl
 * všechny části — vymazané dokumenty se nedají přečíst a balíček fyzicky není.
 * To je vlastní důsledek výmazu, ne chyba: kdyby export dál uměl personální
 * data vydat, výmaz by neplatil. `PayrollPeriodExportService` se s tím zatím
 * neumí vypořádat jinak než selháním — naučit ho vynechat vymazané části
 * a říct to v manifestu je samostatná, dosud neudělaná práce.
 */
final class PayrollDocumentCryptoErasure
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollDocumentKeyRing $keyRing,
        private readonly PayrollDocumentStorage $storage,
    ) {}

    /**
     * @return array{
     *   keys_destroyed:int,bundles_purged:int,legacy_plaintext_purged:int
     * }
     */
    public function erase(
        int $supplierId,
        int $employeeId,
        ?int $actorUserId,
        string $reason,
    ): array {
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Identita krypto-výmazu mzdových dokumentů není platná.',
            );
        }

        return [
            'keys_destroyed' => $this->keyRing->destroy(
                $supplierId,
                $employeeId,
                $actorUserId,
                $reason,
            ),
            'bundles_purged' => $this->purgeBundles($supplierId, $employeeId),
            'legacy_plaintext_purged' => $this->purgeLegacyPlaintext(
                $supplierId,
                $employeeId,
            ),
        ];
    }

    /**
     * Smaže nešifrované soubory dokumentů osoby z doby před zavedením
     * šifrování (W30 / C-05).
     *
     * Na plaintext krypto-výmaz nedosáhne — není čím zahodit klíč. Kdyby se
     * takový soubor nechal ležet, výmaz by platil na dokumenty vydané po
     * změně a na starší ne, což je přesně ten stav, kvůli kterému čl. 17 GDPR
     * nešlo splnit. Řádek v evidenci zůstává, mizí jen soubor.
     */
    private function purgeLegacyPlaintext(
        int $supplierId,
        int $employeeId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT storage_key
               FROM payroll_generated_documents
              WHERE supplier_id = ? AND employee_id = ?',
        );
        $stmt->execute([$supplierId, $employeeId]);

        $purged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storageKey) {
            if (is_string($storageKey)
                && $this->storage->deleteLegacyPlaintext($supplierId, $storageKey)
            ) {
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * Smaže soubory měsíčních balíčků, ve kterých osoba figurovala.
     *
     * Balíček se váže na revizi běhu; „figurovala" tedy znamená, že za tutéž
     * revizi existuje dokument té osoby. Balíček nese i pásky ostatních —
     * to je cena za to, že jde o agregát, a je přijatelná právě proto, že
     * se z jednotlivých dokumentů dá vyrobit znovu.
     */
    private function purgeBundles(int $supplierId, int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT bundle.storage_key
               FROM payroll_generated_documents bundle
              WHERE bundle.supplier_id = ?
                AND bundle.document_kind = ?
                AND bundle.revision_id IS NOT NULL
                AND EXISTS (
                    SELECT 1
                      FROM payroll_generated_documents personal
                     WHERE personal.supplier_id = bundle.supplier_id
                       AND personal.revision_id = bundle.revision_id
                       AND personal.employee_id = ?
                )',
        );
        $stmt->execute([
            $supplierId,
            PayrollDocumentKind::MonthlyBundle->value,
            $employeeId,
        ]);

        $purged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storageKey) {
            if (!is_string($storageKey)) {
                continue;
            }
            $this->storage->delete(
                $supplierId,
                $storageKey,
                PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
            );
            ++$purged;
        }

        return $purged;
    }
}
