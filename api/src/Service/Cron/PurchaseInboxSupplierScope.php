<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use RuntimeException;

/** Globální inbox má pro automatický sken právě jednu vlastní firmu. */
final class PurchaseInboxSupplierScope
{
    /** Firma odhadnutá bez konfigurace; cron ji ohlásí jako varování. */
    public ?string $warning = null;

    public function resolve(PDO $pdo, Config $config): ?int
    {
        $owner = $config->get('purchase_invoice.cron_supplier_id');
        if ($owner !== null) {
            return $this->configuredSupplier($pdo, $owner, 'purchase_invoice.cron_supplier_id');
        }

        // Historický výběr zachováme jen pro výslovně nakonfigurovanou firmu.
        // scan_all_suppliers nesmí stejný adresář importovat do více firem.
        $legacyOwner = $config->get('app.default_supplier_id');
        if (!(bool) $config->get('app.scan_all_suppliers', false) && $legacyOwner !== null) {
            return $this->configuredSupplier($pdo, $legacyOwner, 'app.default_supplier_id');
        }

        $this->warning = null;
        $ids = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) {
            // Instalace s více firmami bez vlastníka dosud importovala do firmy 1 (výchozí
            // app.default_supplier_id). Zastavit import po aktualizaci by bylo horší než
            // dosavadní volba, takže se zachová a jen se ohlásí, že ji má správce potvrdit.
            $owner = (int) ($pdo->query('SELECT id FROM supplier WHERE id = 1')->fetchColumn() ?: $ids[0]);
            $this->warning = "Inbox je společný pro více firem a vlastník není nastavený; importuje se do firmy {$owner}. "
                . 'Nastavte purchase_invoice.cron_supplier_id na ID firmy, které patří inbox_dir.';
            return $owner;
        }

        return $ids === [] ? null : (int) $ids[0];
    }

    private function configuredSupplier(PDO $pdo, mixed $value, string $key): int
    {
        $id = (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($id === false) {
            throw new RuntimeException("{$key} musí být kladné celé ID existující firmy; nastavte purchase_invoice.cron_supplier_id.");
        }

        $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Firma z {$key} neexistuje; opravte purchase_invoice.cron_supplier_id.");
        }

        return $id;
    }
}
