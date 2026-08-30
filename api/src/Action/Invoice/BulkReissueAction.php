<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PaymentDueResolver;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadně klonuje označené faktury do dalšího měsíce — vytvoří DRAFTy.
 *
 * Body:
 *   {
 *     "invoice_ids": [101, 102, 103],
 *     "increment_month_in_descriptions": true,
 *     "issue_date": null  // null = today
 *   }
 *
 * Pro každou source fakturu:
 *   - vytvoří draft (status='draft', varsymbol=null)
 *   - kopie items + work_report (zatím work_report ne — M5)
 *   - auto-increment měsíce v popisech (regex /\b(\d{1,2})\/(\d{4})\b/)
 *   - tax_date = today, due_date podle PaymentDueResolver (zakázka → klient → dodavatel)
 *
 * Žádný draft se neodesílá ani nevystavuje. User musí každý ručně otevřít.
 */
final class BulkReissueAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = (array) ($body['invoice_ids'] ?? []);
        $incrementMonth = (bool) ($body['increment_month_in_descriptions'] ?? true);
        $issueDate = isset($body['issue_date']) && $body['issue_date'] !== null && $body['issue_date'] !== ''
            ? (string) $body['issue_date']
            : date('Y-m-d');

        if (empty($ids)) {
            return Json::error($response, 'no_invoices', 'Není vybrána žádná faktura.', 400);
        }
        if (count($ids) > 200) {
            return Json::error($response, 'too_many', 'Najednou lze klonovat maximálně 200 faktur.', 422);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $created = [];
        $errors = [];

        foreach ($ids as $sourceId) {
            $sourceId = (int) $sourceId;
            // Ownership: nedovol klonovat cizí faktury
            if (!SupplierGuard::owns($request, $this->repo->find($sourceId))) {
                $errors[] = ['source_id' => $sourceId, 'error' => 'not_found'];
                continue;
            }
            try {
                $newId = $this->cloneOne($sourceId, $issueDate, $incrementMonth, $userId);
                $created[] = ['source_id' => $sourceId, 'draft_id' => $newId];
            } catch (\Throwable $e) {
                $errors[] = ['source_id' => $sourceId, 'error' => $e->getMessage()];
            }
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.reissued_bulk', $userId, null, null, [
            'source_count'  => count($ids),
            'created_count' => count($created),
            'error_count'   => count($errors),
            'increment_month' => $incrementMonth,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'created' => $created,
            'errors'  => $errors,
        ], 201);
    }

    public function cloneOne(int $sourceId, string $issueDate, bool $incrementMonth, int $userId): int
    {
        $source = $this->repo->find($sourceId);
        if ($source === null) {
            throw new \RuntimeException("Faktura #$sourceId nenalezena");
        }

        $type = $source['invoice_type'] === 'proforma' ? 'proforma' : 'invoice';

        // Splatnost: stejná pravidla jako u nové faktury — řeší je PaymentDueResolver
        // (hodnota zakázka → klient → dodavatel → 7, jednotka se dědí nahoru).
        // Bez tohoto fallbacku dostal klon faktury bez zakázky splatnost = datum
        // vystavení (0 dní). Klienta i dodavatele načítáme vždy, i když hodnotu
        // dodá zakázka — může z nich totiž dědit jednotku.
        $project = null;
        if (!empty($source['project_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT payment_due_days, payment_due_unit FROM projects WHERE id = ?'
            );
            $stmt->execute([(int) $source['project_id']]);
            $project = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $client = null;
        // Země odběratele patří ke stejnému dotazu — auto-klasifikace níž ji potřebuje,
        // aby klon legacy položky bez kódu nedostal tuzemský kód pro zahraničního klienta.
        $clientCountryIso2 = null;
        if (!empty($source['client_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT c.payment_due_default, c.payment_due_unit, co.iso2
                   FROM clients c
              LEFT JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $stmt->execute([(int) $source['client_id']]);
            $client = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $iso = strtoupper(trim((string) ($client['iso2'] ?? '')));
            $clientCountryIso2 = $iso !== '' ? $iso : null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT default_payment_due_days, default_payment_due_unit FROM supplier WHERE id = ?'
        );
        $stmt->execute([(int) $source['supplier_id']]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $dueDate = PaymentDueResolver::dueDate($issueDate, $project, $client, $supplier);

        $taxDate = $type === 'proforma' ? null : $issueDate;

        // Klon vzniká k NOVÉMU datu, takže i „základní sazba" v auto-klasifikaci se bere
        // podle roku KLONU, ne zdrojové faktury — přišpendlené sazby položek stejně musí
        // být platné k DUZP klonu (VatRateValidityGuard níž).
        $standardRate = $this->taxConstants->vatRateStandard(
            (int) substr($taxDate ?? $issueDate, 0, 4)
        );

        $pdo = $this->db->pdo();

        // Safeguard: klonujeme do NOVÉHO data — přišpendlené sazby zdrojových položek
        // musí být platné k DUZP klonu (jinak by se po změně sazby vystavila stará).
        \MyInvoice\Service\Invoice\VatRateValidityGuard::assertValidOn(
            $pdo,
            array_map(static fn ($it) => (int) $it['vat_rate_id'], $source['items']),
            $taxDate ?? $issueDate,
        );

        // Per-faktura přepínač upomínek (migrace 0088) musí klon zdědit — jinak by se
        // vědomě opt-outovaná faktura po klonu tiše vrátila na DB default 1 (upomínky
        // zapnuté). Guard na existenci sloupce kvůli instalacím pozadu s migrací.
        $hasReminders = $this->db->hasColumn('invoices', 'auto_send_reminders');
        $supportsOss = $this->db->hasColumn('invoice_items', 'oss_applicable');
        // Vlastní guard, ne společný s ostatními OSS sloupci: mezi migracemi 0137 a 1293
        // je řada verzí, takže instance s OSS schématem a bez příznaku je běžný stav
        // (shodně s InvoiceRepository::supportsOssManualReview() a importem).
        $supportsManualReview = $supportsOss
            && $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, discount_percent, payment_method,
                    revenue_category_id,'
                . ($hasReminders ? ' auto_send_reminders,' : '')
                . ' status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,'
                . ($hasReminders ? ' ?,' : '')
                . ' "draft", ?)'
            );
            $params = [
                $type,
                $source['client_id'],
                $source['project_id'],
                (int) $source['supplier_id'],
                $source['branding_profile_id'] ?? null,
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $source['currency_id'],
                $source['reverse_charge'] ? 1 : 0,
                // Reissue (kopie/dobropis) musí dědit režim „ceny s DPH" — jinak by se
                // zkopírované brutto jednotkové ceny přepočítaly jako netto (nafouknuté totály).
                !empty($source['prices_include_vat']) ? 1 : 0,
                $source['language'],
                $source['note_above_items'],
                $source['note_below_items'],
                (float) ($source['discount_percent'] ?? 0),
                (string) ($source['payment_method'] ?? 'bank_transfer'),
                // Reissue zachová kategorii tržby zdrojové faktury.
                $source['revenue_category_id'] ?? null,
            ];
            if ($hasReminders) {
                $params[] = !empty($source['auto_send_reminders']) ? 1 : 0;
            }
            $params[] = $userId;
            $stmt->execute($params);
            $newId = (int) $pdo->lastInsertId();

            // Zkopíruj položky s případným inkrementem měsíce
            // Zachovává vat_classification_code ze source položky pokud existuje,
            // jinak auto-derive (typicky pro legacy faktury vystavené před fixem).
            // Sloupce se skládají místo dvou ručně psaných variant SQL: guard na příznak
            // „k ručnímu posouzení" je samostatný, takže variant by jinak byly tři a počet
            // otazníků by se s nimi rozešel.
            $ossColumns = $supportsOss
                ? ['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type']
                : [];
            if ($supportsManualReview) {
                $ossColumns[] = 'oss_needs_manual_review';
            }
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code,
                    stock_item_id, warehouse_id'
                . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
                . ') VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?'
                . str_repeat(', ?', count($ossColumns))
                . ')'
            );
            foreach ($source['items'] as $item) {
                $kind = (string) ($item['item_kind'] ?? 'standard');
                // Slevovou položku needitujeme přes MonthIncrementer (popis "Sleva X %").
                $description = ($incrementMonth && $kind !== 'discount')
                    ? \MyInvoice\Service\Invoice\MonthIncrementer::increment((string) $item['description'])
                    : (string) $item['description'];

                $code = $item['vat_classification_code']
                    ?? \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode(
                        (float) $item['vat_rate_snapshot'],
                        (bool) ($source['reverse_charge'] ?? false),
                        $clientCountryIso2,
                        (string) ($item['unit'] ?? '') ?: null,
                        $standardRate,
                    );
                $params = [
                    $newId,
                    $description,
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    $kind,
                    $code !== null ? (string) $code : null,
                    // Klon přenáší vazbu na skladovou kartu (Epic SKLAD A15).
                    $item['stock_item_id'] ?? null,
                    $item['warehouse_id'] ?? null,
                ];
                if ($supportsOss) {
                    $ossApplicable = !empty($item['oss_applicable']);
                    array_push(
                        $params,
                        $ossApplicable ? 1 : 0,
                        $ossApplicable ? ($item['oss_consumer_country'] ?? null) : null,
                        $ossApplicable ? ($item['oss_rate_type'] ?? null) : null,
                        $ossApplicable ? ($item['oss_supply_type'] ?? null) : null,
                    );
                }
                if ($supportsManualReview) {
                    // Klon je KOPIE dokladu i s jeho nejistotou: sporné místo plnění ani
                    // rozpor mezi OSS a tuzemským přiznáním se přeúčtováním do dalšího
                    // měsíce nevyřeší. Bez přenosu příznaku by se z označeného dokladu
                    // stal tichý klon a kontrola by po prvním hromadném klonování zmizela
                    // — tichý přesně u těch dokladů, které se opakují každý měsíc.
                    // Nese ho i tuzemský řádek smíšeného dokladu (proto bez vazby na
                    // `$ossApplicable`) — viz InvoiceRepository::ossItemParams().
                    $params[] = !empty($item['oss_needs_manual_review']) ? 1 : 0;
                }
                $itemStmt->execute($params);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->calc->recompute($newId);
        return $newId;
    }

    /**
     * @deprecated Use \MyInvoice\Service\Invoice\MonthIncrementer::increment() directly.
     *             Wrapper zachovaný pro zpětnou kompatibilitu.
     */
    public function incrementMonthInString(string $text): string
    {
        return \MyInvoice\Service\Invoice\MonthIncrementer::increment($text);
    }
}
