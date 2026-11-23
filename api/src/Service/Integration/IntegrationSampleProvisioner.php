<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Demo\DemoMode;

/**
 * Každá firma má předem připravené ukázkové napojení (Koncept, bez secretu
 * a přístupových údajů). Zakládá se jednou, při prvním otevření seznamu
 * integrací uživatelem s právem na úpravu; značka `supplier.integration_sample_seeded_at`
 * zajistí, že se smazaná ukázka už znovu nevrátí.
 *
 * Proč ne v SQL migraci: výchozí hodnoty skládá IntegrationConnectionDefaults
 * z definice konektoru a číselníků firmy a SQL by je jen zdvojilo. Migrace
 * přidává pouze značku.
 */
final class IntegrationSampleProvisioner
{
    public function __construct(
        private readonly Connection $db,
        private readonly IntegrationConnectionService $connections,
        private readonly DemoMode $demo,
    ) {}

    /** @return bool true, pokud ukázku právě založil */
    public function ensure(int $supplierId, ?int $createdBy = null): bool
    {
        // Ukázka je jen ke čtení: otevřením seznamu se napojení ani značka nezakládá.
        if ($this->demo->enabled()) {
            return false;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Ukázkové napojení se zakládá v samostatné transakci.');
        }
        $check = $pdo->prepare('SELECT integration_sample_seeded_at FROM supplier WHERE id = ?');
        $check->execute([$supplierId]);
        $seeded = $check->fetchColumn();
        if ($seeded === false || $seeded !== null) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            // Zámek řádku firmy serializuje souběžná otevření stránky: druhý požadavek
            // počká a po odemčení už uvidí nastavenou značku.
            $lock = $pdo->prepare('SELECT integration_sample_seeded_at FROM supplier WHERE id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $current = $lock->fetchColumn();
            if ($current === false || $current !== null) {
                $pdo->commit();
                return false;
            }
            // Firma, která už napojení má (založené dřív ručně nebo před touto funkcí),
            // ukázku nepotřebuje: jen se označí, ať se jí mezi skutečná napojení nepřidá.
            $existing = $pdo->prepare('SELECT 1 FROM integration_connections WHERE supplier_id = ? LIMIT 1');
            $existing->execute([$supplierId]);
            $created = $existing->fetchColumn() === false;
            if ($created) {
                $this->connections->createSample($supplierId, $createdBy);
            }
            $pdo->prepare('UPDATE supplier SET integration_sample_seeded_at = NOW() WHERE id = ?')->execute([$supplierId]);
            $pdo->commit();
            return $created;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
