<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stats;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přepočítává cache tabulky `project_revenue_cache` a `client_revenue_cache`
 * po každé změně faktur (create/update/issue/cancel/delete).
 *
 * Dvě různé filtrace v rámci jednoho dotazu:
 *  - `revenue` / `invoice_count` — jen `invoice` + `credit_note` (proforma není daňový doklad)
 *  - `last_invoice_date` — jakákoli **aktivita** na projektu/klientu, vč. proform
 *    (status IN issued/sent/reminded/paid, type != cancellation). Reflektuje, kdy se na
 *    projektu naposled něco dělo, ne jen finální fakturace.
 *
 * Idempotentní — vždy mažeme všechny existující řádky pro danou entity a re-insertujeme.
 */
final class StatsRecomputer
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočte cache pro projekt podle aktuálního stavu invoices.
     */
    public function recomputeProject(int $projectId): void
    {
        if ($projectId <= 0) return;
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM project_revenue_cache WHERE project_id = ?')
                ->execute([$projectId]);

            $stmt = $pdo->prepare(
                "SELECT i.currency_id,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN CASE WHEN s.is_vat_payer = 1 THEN i.total_without_vat ELSE i.total_with_vat END
                                  ELSE 0 END) AS revenue,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN 1 ELSE 0 END) AS cnt,
                        MAX(COALESCE(i.tax_date, i.issue_date)) AS last_date
                   FROM invoices i
                   JOIN supplier s ON s.id = i.supplier_id
                  WHERE i.project_id = ?
                    AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                    AND i.invoice_type != 'cancellation'
               GROUP BY i.currency_id"
            );
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insert = $pdo->prepare(
                'INSERT INTO project_revenue_cache (project_id, currency_id, revenue, last_invoice_date, invoice_count)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($rows as $r) {
                $insert->execute([
                    $projectId,
                    (int) $r['currency_id'],
                    (float) $r['revenue'],
                    $r['last_date'] ?: null,
                    (int) $r['cnt'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Přepočte cache pro klienta — agreguje VŠECHNY jeho faktury (s project_id i bez).
     */
    public function recomputeClient(int $clientId): void
    {
        if ($clientId <= 0) return;
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')
                ->execute([$clientId]);

            $stmt = $pdo->prepare(
                "SELECT i.currency_id,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN CASE WHEN s.is_vat_payer = 1 THEN i.total_without_vat ELSE i.total_with_vat END
                                  ELSE 0 END) AS revenue,
                        SUM(CASE WHEN i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                                  THEN 1 ELSE 0 END) AS cnt,
                        MAX(COALESCE(i.tax_date, i.issue_date)) AS last_date
                   FROM invoices i
                   JOIN supplier s ON s.id = i.supplier_id
                  WHERE i.client_id = ?
                    AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                    AND i.invoice_type != 'cancellation'
               GROUP BY i.currency_id"
            );
            $stmt->execute([$clientId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insert = $pdo->prepare(
                'INSERT INTO client_revenue_cache (client_id, currency_id, revenue, last_invoice_date, invoice_count)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($rows as $r) {
                $insert->execute([
                    $clientId,
                    (int) $r['currency_id'],
                    (float) $r['revenue'],
                    $r['last_date'] ?: null,
                    (int) $r['cnt'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Convenience: zavolá recompute pro project i client jedné faktury.
     * Volat po create/update/issue/cancel/markPaid faktury.
     *
     * Pro DELETE faktury volat dvě sady — viz `recomputeForChangedInvoice()` níže.
     */
    public function recomputeForInvoiceId(int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT client_id, project_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $this->recomputeClient((int) $row['client_id']);
        if (!empty($row['project_id'])) {
            $this->recomputeProject((int) $row['project_id']);
        }
    }

    /**
     * Recompute pro klienta + projekt z předaných ID (potřebné po DELETE,
     * kdy už řádek faktury neexistuje).
     */
    public function recomputeForIds(?int $clientId, ?int $projectId): void
    {
        if ($clientId !== null && $clientId > 0) $this->recomputeClient($clientId);
        if ($projectId !== null && $projectId > 0) $this->recomputeProject($projectId);
    }

    /**
     * Dávkový přepočet po hromadné operaci (import, bulk reissue) — KAŽDÉHO dotčeného
     * klienta a projektu přepočte PRÁVĚ JEDNOU, bez ohledu na to, kolikrát se v předaných
     * polích opakuje.
     *
     * Volat MÍSTO opakovaného `recomputeForInvoiceId()`/`recomputeClient()` po každém
     * dokladu — dávka stovek dokladů nad týmiž klienty by jinak znamenala stovky
     * zbytečných DELETE+INSERT nad stejnými řádky cache.
     *
     * @param iterable<int> $clientIds
     * @param iterable<int> $projectIds
     */
    public function recomputeMany(iterable $clientIds, iterable $projectIds = []): void
    {
        $uniqueClients = [];
        foreach ($clientIds as $id) {
            $id = (int) $id;
            if ($id > 0) $uniqueClients[$id] = true;
        }
        foreach (array_keys($uniqueClients) as $id) {
            $this->recomputeClient($id);
        }

        $uniqueProjects = [];
        foreach ($projectIds as $id) {
            $id = (int) $id;
            if ($id > 0) $uniqueProjects[$id] = true;
        }
        foreach (array_keys($uniqueProjects) as $id) {
            $this->recomputeProject($id);
        }
    }
}
