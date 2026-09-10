<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/documents/link-search?q=&types=invoice,purchase_invoice,client,project,cash_document
 *
 * Našeptávač pro párování dokumentů s entitami. Hledá napříč vystavenými i
 * přijatými fakturami (číslo dokladu / VS / číslo dodavatele), klienty/dodavateli
 * (název firmy, e-mail, IČ, DIČ), projekty (název, číslo projektu) a pokladními
 * doklady (číslo, partner, popis). Vrací
 * normalizovaný seznam s popisky pro pohodlný výběr. Tenant scope per-supplier.
 */
final class LinkSearchAction
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $sid = SupplierGuard::currentId($request);

        $types = isset($params['types']) && $params['types'] !== ''
            ? array_map('trim', explode(',', (string) $params['types']))
            : ['invoice', 'purchase_invoice', 'client', 'project', 'cash_document'];

        if (mb_strlen($q) < 2) {
            return Json::ok($response, ['results' => [], 'query' => $q]);
        }

        $results = [];

        if (in_array('invoice', $types, true)) {
            foreach ($this->searchInvoicesMixed($q, $sid) as $r) {
                $results[] = [
                    'entity_type' => 'invoice',
                    'entity_id'   => (int) $r['id'],
                    'label'       => $r['varsymbol'] ?? ('#' . $r['id']),
                    'sublabel'    => trim(((string) ($r['company_name'] ?? '')) . ' · ' . ((string) ($r['issue_date'] ?? ''))),
                    'meta'        => $this->fmtMoney(isset($r['total_with_vat']) ? (float) $r['total_with_vat'] : null, (string) ($r['currency'] ?? 'CZK')),
                ];
            }
        }

        if (in_array('purchase_invoice', $types, true)) {
            foreach ($this->searchPurchaseMixed($q, $sid) as $r) {
                $num = $r['varsymbol'] ?? $r['vendor_invoice_number'] ?? ('#' . $r['id']);
                $results[] = [
                    'entity_type' => 'purchase_invoice',
                    'entity_id'   => (int) $r['id'],
                    'label'       => $num,
                    'sublabel'    => trim(((string) ($r['company_name'] ?? '')) . ' · ' . ((string) ($r['issue_date'] ?? ''))),
                    'meta'        => $this->fmtMoney(isset($r['total_with_vat']) ? (float) $r['total_with_vat'] : null, (string) ($r['currency'] ?? 'CZK')),
                ];
            }
        }

        if (in_array('client', $types, true)) {
            foreach ($this->clients->searchQuick($q, $sid, 8) as $r) {
                $roles = [];
                if (!empty($r['is_customer'])) $roles[] = 'odběratel';
                if (!empty($r['is_vendor'])) $roles[] = 'dodavatel';
                $results[] = [
                    'entity_type' => 'client',
                    'entity_id'   => $r['id'],
                    'label'       => $r['company_name'],
                    'sublabel'    => $r['main_email'] ?? implode(', ', $roles),
                    'meta'        => implode(', ', $roles),
                ];
            }
        }

        if (in_array('project', $types, true)) {
            foreach ($this->searchProjects($q, $sid) as $r) {
                $results[] = [
                    'entity_type' => 'project',
                    'entity_id'   => (int) $r['id'],
                    'label'       => (string) $r['name'],
                    'sublabel'    => (string) ($r['company_name'] ?? ''),
                    'meta'        => $r['project_number'] !== null ? (string) $r['project_number'] : '',
                ];
            }
        }

        if (in_array('cash_document', $types, true)) {
            foreach ($this->searchCashDocuments($q, $sid) as $r) {
                $who = (string) ($r['partner_name'] ?: $r['description'] ?: '');
                $results[] = [
                    'entity_type' => 'cash_document',
                    'entity_id'   => (int) $r['id'],
                    'label'       => $r['doc_number'] !== null ? (string) $r['doc_number'] : ('#' . $r['id']),
                    'sublabel'    => trim($who . ' · ' . (string) $r['issue_date']),
                    'meta'        => $this->fmtMoney((float) $r['total_amount'], (string) $r['currency_code']),
                ];
            }
        }

        return Json::ok($response, ['results' => $results, 'query' => $q]);
    }

    /** Pokladní doklady: každý token matchne číslo dokladu, partnera nebo popis. */
    private function searchCashDocuments(string $q, int $sid): array
    {
        $toks = $this->tokens($q);
        if ($toks === []) return [];
        $where = ['supplier_id = ?'];
        $params = [$sid];
        foreach ($toks as $tk) {
            $esc = '%' . addcslashes($tk, '%_\\') . '%';
            $where[] = '(doc_number LIKE ? OR partner_name LIKE ? OR description LIKE ?)';
            $params[] = $esc;
            $params[] = $esc;
            $params[] = $esc;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, doc_number, issue_date, total_amount, currency_code, partner_name, description
               FROM cash_documents
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY issue_date DESC, id DESC
              LIMIT 8'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Rozdělí dotaz na neprázdné tokeny (mix search „fialka 2605"). @return list<string> */
    private function tokens(string $q): array
    {
        $parts = preg_split('/\s+/u', trim($q)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $t): bool => $t !== ''));
    }

    /**
     * Mix search vystavených faktur: každý token musí matchnout VS **nebo** název
     * klienta (AND napříč tokeny). „fialka 2605" → klient „Fialka…" + VS „2605".
     */
    private function searchInvoicesMixed(string $q, int $sid): array
    {
        $toks = $this->tokens($q);
        if ($toks === []) return [];
        $where = ['i.supplier_id = ?'];
        $params = [$sid];
        foreach ($toks as $tk) {
            $esc = '%' . addcslashes($tk, '%_\\') . '%';
            $where[] = '(i.varsymbol LIKE ? OR c.company_name LIKE ?)';
            $params[] = $esc;
            $params[] = $esc;
        }
        $sql = 'SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat,
                       COALESCE(cur.code, \'CZK\') AS currency, c.company_name
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN currencies cur ON cur.id = i.currency_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY i.issue_date DESC, i.id DESC
                 LIMIT 8';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Mix search přijatých faktur: token matchne VS / číslo dodavatele / název dodavatele. */
    private function searchPurchaseMixed(string $q, int $sid): array
    {
        $toks = $this->tokens($q);
        if ($toks === []) return [];
        $where = ['pi.supplier_id = ?'];
        $params = [$sid];
        foreach ($toks as $tk) {
            $esc = '%' . addcslashes($tk, '%_\\') . '%';
            $where[] = '(pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ? OR c.company_name LIKE ?)';
            $params[] = $esc;
            $params[] = $esc;
            $params[] = $esc;
        }
        $sql = 'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat,
                       COALESCE(cur.code, \'CZK\') AS currency, c.company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY pi.issue_date DESC, pi.id DESC
                 LIMIT 8';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Projekty dle názvu / čísla projektu (scope přes klienta — projects nemá supplier_id). */
    private function searchProjects(string $q, int $sid): array
    {
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.project_number, c.company_name
               FROM projects p
               JOIN clients c ON c.id = p.client_id
              WHERE c.supplier_id = ? AND p.archived_at IS NULL
                AND (p.name LIKE ? OR p.project_number LIKE ?)
              ORDER BY p.name
              LIMIT 8'
        );
        $stmt->execute([$sid, '%' . $esc . '%', '%' . $esc . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fmtMoney(?float $amount, string $currency): string
    {
        if ($amount === null) return '';
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }
}
