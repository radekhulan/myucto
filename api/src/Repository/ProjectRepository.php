<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ProjectRepository
{
    public function __construct(private readonly Connection $db) {}

    private function saveBillingTemplate(int $id, int $supplierId, array $data): void
    {
        $current = $this->db->pdo()->prepare('SELECT p.billing_template_id, cur.code FROM projects p JOIN currencies cur ON cur.id = p.currency_id WHERE p.id = ?');
        $current->execute([$id]);
        $project = $current->fetch(PDO::FETCH_ASSOC);
        $templateId = array_key_exists('billing_template_id', $data) ? $data['billing_template_id'] : ($project['billing_template_id'] ?? null);
        if ($templateId !== null) {
            if (!filter_var($templateId, FILTER_VALIDATE_INT) || (int) $templateId <= 0) {
                throw new \InvalidArgumentException('Cenové ujednání nepatří aktuální firmě.');
            }
            $templateId = (int) $templateId;
            $template = (new WorkReportBillingTemplateRepository($this->db))->find($templateId, $supplierId);
            if ($template === null) throw new \InvalidArgumentException('Cenové ujednání nepatří aktuální firmě.');
            if ($template['currency'] !== $project['code']) throw new \InvalidArgumentException('Měna cenového ujednání se liší od měny zakázky.');
        }
        $this->db->pdo()->prepare('UPDATE projects SET billing_template_id = ? WHERE id = ?')->execute([$templateId, $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.supplier_id AS supplier_id,
                    c.payment_due_unit AS client_payment_due_unit,
                    cur.code AS currency
               FROM projects p
               JOIN clients   c   ON c.id   = p.client_id
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->cast($row);
        $row['billing_emails'] = $this->billingEmailsFor($id);
        return $row;
    }

    public function listForClient(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.status, p.currency_id, cur.code AS currency,
                    p.hourly_rate, p.payment_due_days, p.payment_due_unit, p.project_number,
                    p.contract_number, p.budget_total, p.budget_yearly, p.budget_monthly,
                    p.default_revenue_category_id, p.billing_template_id, p.billing_emails_mode, p.archived_at
               FROM projects p
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.client_id = ?
              ORDER BY p.archived_at IS NOT NULL, p.status = "active" DESC, p.name'
        );
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->cast($r), $rows);
    }

    public function listAll(array $filters = [], int $page = 1, int $perPage = 50, string $sort = 'name', bool $includeInvoices = true): array
    {
        $where = ['p.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'c.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'p.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        if (!$includeInvoices && in_array($sort, ['revenue', 'last_activity'], true)) $sort = 'name';

        // Whitelist řazení (defense proti SQLi přes user input)
        $orderBy = match ($sort) {
            'revenue'       => 'revenue DESC, p.name',
            'last_activity' => 'last_invoice_date IS NULL, last_invoice_date DESC, p.name',
            'client'        => 'c.company_name, p.name',
            default         => "p.status = 'active' DESC, p.name",
        };

        $offset = max(0, ($page - 1) * $perPage);
        // Cache `project_revenue_cache` per měnu projektu (přepočítáváno přes StatsRecomputer)
        $invoiceColumns = $includeInvoices ? ', COALESCE(prc.revenue, 0) AS revenue, prc.last_invoice_date, COALESCE(prc.invoice_count, 0) AS invoice_count' : '';
        $invoiceJoin = $includeInvoices ? 'LEFT JOIN project_revenue_cache prc ON prc.project_id = p.id AND prc.currency_id = p.currency_id' : '';
        $sql = "SELECT p.*, c.company_name AS client_company_name,
                       c.main_email AS client_main_email,
                       cur.code AS currency $invoiceColumns
                  FROM projects p
                  JOIN clients   c   ON c.id   = p.client_id
                  JOIN currencies cur ON cur.id = p.currency_id
                  $invoiceJoin
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emailsByProject = $this->billingEmailsForMany(array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'data' => array_map(function (array $r) use ($emailsByProject, $includeInvoices) {
                if (!$includeInvoices) unset($r['revenue'], $r['last_invoice_date'], $r['invoice_count']);
                $r = $this->cast($r);
                $r['billing_emails'] = $emailsByProject[(int) $r['id']] ?? [];
                return $r;
            }, $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $data): int
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $data['client_id'];
        // Currency lookup scope: supplier_id z klienta projektu
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $supplierId = (int) $stmt->fetchColumn();
        if ($supplierId === 0) {
            throw new \InvalidArgumentException("Client #$clientId nenalezen.");
        }

        $pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO projects
                (client_id, name, payment_due_days, payment_due_unit, project_number, contract_number,
                 budget_total, budget_yearly, budget_monthly, hourly_rate, currency_id, status,
                 requires_work_report_approval, note, default_revenue_category_id, billing_emails_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $clientId,
                (string) $data['name'],
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
                $this->resolveRevenueCategoryId($data, $supplierId),
                $this->billingEmailsMode($data),
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->saveBillingTemplate($id, $supplierId, $data);
            $this->saveBillingEmails($id, $data['billing_emails'] ?? []);

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Částečný update (#113): klíč, který v payloadu chybí, převezme uloženou hodnotu;
     * null / "" ji smaže. `$current` = řádek z {@see find()}. Fakturační e-maily se
     * nedoplňují — update() je bez klíče `billing_emails` vůbec nepřepisuje.
     */
    public function mergeWithStored(array $current, array $data): array
    {
        foreach ([
            'name', 'payment_due_days', 'payment_due_unit', 'project_number', 'contract_number',
            'budget_total', 'budget_yearly', 'budget_monthly', 'hourly_rate', 'status',
            'requires_work_report_approval', 'note', 'billing_emails_mode',
        ] as $key) {
            if (!array_key_exists($key, $data) && array_key_exists($key, $current)) {
                $data[$key] = $current[$key];
            }
        }
        if (!array_key_exists('currency_id', $data) && !array_key_exists('currency', $data)) {
            $data['currency_id'] = $current['currency_id'] ?? null;
        }
        return $data;
    }

    /**
     * Vrací počet vydaných faktur, do kterých byla doplněna výchozí kategorie tržby
     * (backfill při nastavení/změně default_revenue_category_id). 0 = žádný backfill.
     */
    public function update(int $id, array $data): int
    {
        $pdo = $this->db->pdo();
        $stored = $this->find($id);
        $writeBillingEmails = array_key_exists('billing_emails', $data);
        if ($stored !== null) {
            $data = $this->mergeWithStored($stored, $data);
        }
        // Supplier lookup pro currency scope + aktuální default kategorie tržby (přes client projektu — nemění se)
        $stmt = $pdo->prepare('SELECT c.supplier_id, p.default_revenue_category_id
                                 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $supplierId = (int) ($cur['supplier_id'] ?? 0);
        $oldDefaultRevenueCategory = ($cur['default_revenue_category_id'] ?? null) !== null
            ? (int) $cur['default_revenue_category_id']
            : null;
        // Pokud klíč v payloadu chybí, zachovat aktuální (BC).
        $newDefaultRevenueCategory = array_key_exists('default_revenue_category_id', $data)
            ? $this->resolveRevenueCategoryId($data, $supplierId)
            : $oldDefaultRevenueCategory;

        $pdo->beginTransaction();
        try {
            $sql = 'UPDATE projects SET
                    name = ?, payment_due_days = ?, payment_due_unit = ?, project_number = ?, contract_number = ?,
                    budget_total = ?, budget_yearly = ?, budget_monthly = ?, hourly_rate = ?,
                    currency_id = ?, status = ?, requires_work_report_approval = ?, note = ?,
                    default_revenue_category_id = ?, billing_emails_mode = ?
                    WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                (string) $data['name'],
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
                $newDefaultRevenueCategory,
                $this->billingEmailsMode($data),
                $id,
            ]);

            $this->saveBillingTemplate($id, $supplierId, $data);
            if ($writeBillingEmails) {
                $this->saveBillingEmails($id, is_array($data['billing_emails']) ? $data['billing_emails'] : []);
            }

            // Backfill: doplnit nově nastavenou kategorii do vydaných faktur zakázky,
            // které kategorii nemají vyplněnou (revenue_category_id IS NULL).
            $backfilled = 0;
            if ($newDefaultRevenueCategory !== null && $newDefaultRevenueCategory !== $oldDefaultRevenueCategory) {
                $bf = $pdo->prepare(
                    'UPDATE invoices SET revenue_category_id = ?
                      WHERE project_id = ? AND revenue_category_id IS NULL'
                );
                $bf->execute([$newDefaultRevenueCategory, $id]);
                $backfilled = $bf->rowCount();
            }

            $pdo->commit();
            return $backfilled;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function archive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE projects SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function billingEmailsFor(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT position, email, label, usages FROM project_billing_emails WHERE project_id = ? ORDER BY position'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'position' => (int) $r['position'],
            'email'    => $r['email'],
            'label'    => $r['label'],
            'usages'   => $this->decodeUsages($r['usages'] ?? null),
        ], $rows);
    }

    /**
     * @param int[] $projectIds
     * @return array<int, array<int, array{position:int,email:string,label:?string}>>
     */
    private function billingEmailsForMany(array $projectIds): array
    {
        if (!$projectIds) return [];
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT project_id, position, email, label, usages
               FROM project_billing_emails
              WHERE project_id IN ($placeholders)
              ORDER BY project_id, position"
        );
        $stmt->execute(array_map('intval', $projectIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int) $r['project_id'];
            $out[$pid][] = [
                'position' => (int) $r['position'],
                'email'    => $r['email'],
                'label'    => $r['label'],
                'usages'   => $this->decodeUsages($r['usages'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Účely e-mailu zakázky (#86): NULL = všechny typy zpráv (default).
     * V API reprezentujeme NULL — frontend zobrazí vše zaškrtnuté.
     *
     * @return list<string>|null
     */
    private function decodeUsages(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || $decoded === []) return null;
        return array_values(array_filter(array_map('strval', $decoded), static fn ($u) => $u !== ''));
    }

    private function saveBillingEmails(int $projectId, array $emails): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$projectId]);

        $stmt = $pdo->prepare(
            'INSERT INTO project_billing_emails (project_id, position, email, label, usages) VALUES (?, ?, ?, ?, ?)'
        );
        $validUsages = ['documents', 'reminders', 'approvals'];
        foreach ($emails as $entry) {
            if (!is_array($entry)) continue;
            $email = trim((string) ($entry['email'] ?? ''));
            $position = (int) ($entry['position'] ?? 0);
            if ($email === '' || $position < 1 || $position > 3) continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $label = trim((string) ($entry['label'] ?? '')) ?: null;
            // Účely (#86): pole stringů; NULL / prázdné / všechny tři = NULL (všechny typy).
            $usages = null;
            if (isset($entry['usages']) && is_array($entry['usages'])) {
                $filtered = array_values(array_unique(array_filter(
                    array_map(static fn ($u) => trim((string) $u), $entry['usages']),
                    static fn ($u) => in_array($u, $validUsages, true),
                )));
                if ($filtered !== [] && count($filtered) < count($validUsages)) {
                    $usages = json_encode($filtered, JSON_UNESCAPED_UNICODE);
                }
            }
            $stmt->execute([$projectId, $position, $email, $label, $usages]);
        }
    }

    private function cast(array $row): array
    {
        if (isset($row['id']))               $row['id'] = (int) $row['id'];
        if (isset($row['client_id']))        $row['client_id'] = (int) $row['client_id'];
        if (isset($row['supplier_id']))      $row['supplier_id'] = (int) $row['supplier_id'];
        if (isset($row['payment_due_days'])) $row['payment_due_days'] = (int) $row['payment_due_days'];
        if (isset($row['hourly_rate']))      $row['hourly_rate'] = (float) $row['hourly_rate'];
        foreach (['budget_total', 'budget_yearly', 'budget_monthly'] as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $row[$f] !== null ? (float) $row[$f] : null;
            }
        }
        if (isset($row['currency_id']))      $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['requires_work_report_approval'])) {
            $row['requires_work_report_approval'] = (bool) $row['requires_work_report_approval'];
        }
        if (array_key_exists('revenue', $row)) {
            $row['revenue'] = (float) $row['revenue'];
        }
        if (array_key_exists('last_invoice_date', $row)) {
            $row['last_invoice_date'] = $row['last_invoice_date'] ?: null;
        }
        if (array_key_exists('default_revenue_category_id', $row)) {
            $row['default_revenue_category_id'] = $row['default_revenue_category_id'] !== null
                ? (int) $row['default_revenue_category_id']
                : null;
        }
        return $row;
    }

    /**
     * Validace výchozí kategorie tržby z payloadu. Vrací int id nebo null.
     * NULL / 0 / prázdné → null. Jinak ověří, že kategorie patří danému tenantovi.
     * Symetrie k ClientRepository::resolveRevenueCategoryId.
     */
    private function resolveRevenueCategoryId(array $data, int $supplierId): ?int
    {
        if (!array_key_exists('default_revenue_category_id', $data)) {
            return null;
        }
        $raw = $data['default_revenue_category_id'];
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $catId = (int) $raw;
        $check = $this->db->pdo()->prepare(
            'SELECT 1 FROM revenue_categories WHERE id = ? AND supplier_id = ?'
        );
        $check->execute([$catId, $supplierId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException("Kategorie tržby #$catId nepatří tomuto tenantovi.");
        }
        return $catId;
    }

    /**
     * Resolve currency_id z `currency_id` (preferováno) nebo z `currency` (legacy code lookup).
     * Default = výchozí (is_default=1) řádek pro CZK v rámci supplier.
     * Pokud je dáno explicitní currency_id, ověří že patří danému supplier (anti cross-supplier).
     */
    private function resolveCurrencyId(array $data, int $supplierId): int
    {
        if (isset($data['currency_id'])) {
            $id = (int) $data['currency_id'];
            $check = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([$id, $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException("Currency #$id nepatří supplier #$supplierId.");
            }
            return $id;
        }
        $code = strtoupper((string) ($data['currency'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Currency not found: $code (supplier #$supplierId)");
        }
        return (int) $id;
    }

    private function nullable(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function nullableNumber(array $data, string $key): ?float
    {
        $v = $data[$key] ?? null;
        if ($v === null || $v === '') return null;
        return (float) $v;
    }

    /** Splatnost zakázky unit. NULL = dny (BC). Validuje 'days'|'month'. */
    private function nullablePaymentDueUnit(array $data, string $key): ?string
    {
        $v = $this->nullable($data, $key);
        if ($v === null) return null;
        if (!in_array($v, ['days', 'month'], true)) {
            throw new \InvalidArgumentException("{$key} musí být 'days' nebo 'month'.");
        }
        return $v;
    }

    /**
     * Režim kombinace e-mailů zakázky s kontakty klienta (#86).
     * Default 'auto' = dosavadní per-typ chování (viz RecipientResolver).
     */
    private function billingEmailsMode(array $data): string
    {
        $v = trim((string) ($data['billing_emails_mode'] ?? 'auto'));
        if ($v === '') return 'auto';
        if (!in_array($v, ['auto', 'append', 'replace'], true)) {
            throw new \InvalidArgumentException("billing_emails_mode musí být 'auto', 'append' nebo 'replace'.");
        }
        return $v;
    }
}
