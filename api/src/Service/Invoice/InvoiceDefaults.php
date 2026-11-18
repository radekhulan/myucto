<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Resolver default hodnot pro novou fakturu — ze supplier, client, project.
 */
final class InvoiceDefaults
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Doplní chybějící pole v $data podle defaultů.
     *
     * @param bool $forNewInvoice Doklad teprve VZNIKÁ. Rozlišení je nutné, protože
     *        tentýž resolver běží i nad PUT (UpdateInvoiceAction) — defaulty, které
     *        smí platit jen při založení, by jinak dotekly i roky staré faktury při
     *        obyčejném přeuložení. Týká se výchozí poznámky pod položkami (#79).
     */
    public function resolve(array $data, bool $forNewInvoice = false, ?array $resolvedClient = null): array
    {
        $pdo = $this->db->pdo();
        $today = date('Y-m-d');
        $tz = (string) $this->config->get('app.timezone', 'Europe/Prague');

        $clientId = (int) ($data['client_id'] ?? 0);
        $projectId = isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null;

        $client = $resolvedClient;
        if ($client === null && $clientId) {
            $stmt = $pdo->prepare(
                'SELECT supplier_id, language, currency_default_id, reverse_charge,
                        payment_due_default, payment_due_unit
                   FROM clients WHERE id = ?'
            );
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $project = null;
        if ($projectId) {
            $stmt = $pdo->prepare(
                'SELECT client_id, currency_id, payment_due_days, payment_due_unit, hourly_rate
                   FROM projects WHERE id = ?'
            );
            $stmt->execute([$projectId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            // MS-P1-1: project musí patřit zadanému klientovi
            if ($project !== null && (int) $project['client_id'] !== $clientId) {
                throw new \InvalidArgumentException("Zakázka #$projectId nepatří klientovi #$clientId.");
            }
        }

        // Supplier defaults — bere z clientova supplier_id (invoice je vždy v rámci klientova supplier)
        $supplier = null;
        if ($client !== null && !empty($client['supplier_id'])) {
            $stmt = $pdo->prepare(
                'SELECT default_currency_id, default_payment_due_days, default_payment_due_unit, '
                . implode(', ', DefaultInvoiceNote::supplierColumns())
                . ' FROM supplier WHERE id = ?'
            );
            $stmt->execute([(int) $client['supplier_id']]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $supplierId = $client['supplier_id'] ?? 0;

        // Defaults
        $data['issue_date'] = (string) ($data['issue_date'] ?? $today);

        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if ($type === 'proforma') {
            $data['tax_date'] = null;
        } else {
            $data['tax_date'] = (string) ($data['tax_date'] ?? $today);
        }

        if (empty($data['currency_id'])) {
            // Legacy: pokud frontend posílá `currency` (code), resolve na id (scope per supplier)
            if (!empty($data['currency']) && is_string($data['currency']) && $supplierId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
                );
                $stmt->execute([(int) $supplierId, strtoupper($data['currency'])]);
                $found = (int) $stmt->fetchColumn();
                if ($found <= 0) {
                    // Zadaný kód je vůle volajícího: tichý pád na výchozí měnu by vyrobil
                    // doklad v jiné měně, než chtěl.
                    throw UnknownCurrencyException::forCode(strtoupper(trim($data['currency'])));
                }
                $data['currency_id'] = $found;
            }
        }
        if (empty($data['currency_id'])) {
            $data['currency_id'] = (int) (
                $project['currency_id']
                ?? $client['currency_default_id']
                ?? $supplier['default_currency_id']
                ?? 0
            );
            if ($data['currency_id'] <= 0 && $supplierId > 0) {
                // Fallback: vyber default CZK clientova supplier
                $stmt = $pdo->prepare(
                    "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC LIMIT 1"
                );
                $stmt->execute([(int) $supplierId]);
                $data['currency_id'] = (int) $stmt->fetchColumn();
            }
        }

        // MS-P1-2: ověř že currency_id patří klientovu supplier (cross-supplier integrity)
        if (!empty($data['currency_id']) && $supplierId > 0) {
            $check = $pdo->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([(int) $data['currency_id'], (int) $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException(
                    "Měna #{$data['currency_id']} nepatří supplier #{$supplierId} klienta."
                );
            }
        }

        if (empty($data['language'])) {
            $data['language'] = $client['language'] ?? 'cs';
        }

        // Výchozí poznámka pod položkami (#79) — AŽ ZA resolucí jazyka výš, text se
        // vybírá podle jazyka dokladu. Jen při zakládání: PUT chodí tímhle resolverem
        // taky a existující faktuře by přeuložení doplnilo text, který nikdy neměla.
        if ($forNewInvoice) {
            $data = self::withDefaultNote($data, $supplier);
            // Výchozí zaokrouhlení firmy (migrace 2031). Jen chybí-li klíč: editor ho
            // posílá vždy, API a MCP ho vynechávají.
            if (!array_key_exists('rounding_mode', $data)) {
                $data['rounding_mode'] = InvoiceRounding::supplierDefault($pdo, (int) $supplierId);
            }
        }

        if (!isset($data['reverse_charge'])) {
            $data['reverse_charge'] = (bool) ($client['reverse_charge'] ?? false);
        }

        if (empty($data['due_date'])) {
            $data['due_date'] = PaymentDueResolver::dueDate(
                $data['issue_date'],
                $project,
                $client,
                $supplier,
            );
        }

        return $data;
    }

    /**
     * Doplní výchozí poznámku pod položkami do payloadu ZAKLÁDANÉHO dokladu
     * (#79, migrace 1855).
     *
     * Doplní se jen tehdy, když klíč `note_below_items` v payloadu VŮBEC NENÍ:
     * editor i API ho posílají vždy (byť jako `null`), takže vědomě smazaný text
     * se tudy nevrátí zpátky. Předpokládá už vyřešený `language`.
     *
     * @param  array<string, mixed>      $data
     * @param  array<string, mixed>|null $supplier řádek `supplier` se sloupci
     *                                   {@see DefaultInvoiceNote::supplierColumns()}
     * @return array<string, mixed>
     */
    public static function withDefaultNote(array $data, ?array $supplier): array
    {
        if (array_key_exists('note_below_items', $data)) {
            return $data;
        }
        $note = DefaultInvoiceNote::forLanguage($supplier, (string) ($data['language'] ?? ''));
        if ($note !== '') {
            $data['note_below_items'] = $note;
        }

        return $data;
    }
}
