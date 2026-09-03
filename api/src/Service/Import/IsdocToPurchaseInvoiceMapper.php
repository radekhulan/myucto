<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Oss\OssItemPlanner;

/**
 * Mapper z ISDOC normalized array (z IsdocParser) na purchase_invoice draft.
 *
 * Vstup: parsed `invoice` data (z `IsdocParser::parse()['invoices'][0]`).
 *        Klíčový rozdíl od issued: použijeme `supplier` party jako vendor,
 *        ne `client`. Buyer (`client` v parser výstupu) MUSÍ matchovat tenant
 *        — jinak ISDOC patří jiné firmě, nemůžeme jej importovat (cross-tenant guard).
 *
 * Výstup: id vytvořené purchase_invoice (status='draft', items naplněny, vendor resolved).
 *
 * Pravidla:
 *   - Buyer (client) IČ != tenant IČ → odmítnutí s reason
 *   - Vendor (supplier) nemá IČO ANI DIČ ANI název → odmítnutí (nejde identifikovat)
 *   - Vendor IČ existuje v clients → reuse, nastav is_vendor=1
 *   - Vendor IČ neznámé → vytvořit nového klienta s is_customer=0, is_vendor=1 + ARES lookup
 */
final class IsdocToPurchaseInvoiceMapper
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ClientResolver $clientResolver,
        private readonly PurchaseInvoiceCnbApplier $cnbApplier,
        private readonly OssItemPlanner $planner,
    ) {}

    /**
     * @param array<string,mixed> $parsed  Output z IsdocParser::parse()['invoices'][N]
     * @param 'draft'|'received' $status   Stav zakládaného dokladu. Strukturovaný zdroj
     *        (ISDOC, Pohoda XML) nese úplný doklad, takže dávková migrace z jiného
     *        systému má smysl jen jako `received` — koncepty se do nákladů, závazků ani
     *        do výkazů nezapočítávají a účetní by je musela otevřít jeden po druhém.
     *        Výchozí `draft` je ZÁMĚRNÝ rozdíl pro ostatní kanály, ne opomenutí:
     *        {@see PurchaseInvoiceInboxScanner}, {@see AiPdfExtractor} i dropzone na
     *        novém dokladu zakládají po jednom a uživatel doklad stejně otevírá —
     *        u AI extrakce je kontrola dokonce nutná. Stav volí tedy volající.
     * @return array{purchase_invoice_id:int, vendor_id:int, vendor_created:bool}
     * @throws \InvalidArgumentException pokud ISDOC nemá vendor / patří jinému tenantovi
     */
    public function map(array $parsed, int $supplierId, int $userId, string $status = 'draft'): array
    {
        // Cross-tenant guard: buyer (client) v ISDOC musí mít stejné IČ jako tenant supplier.
        // Pokud ne, ISDOC patří jiné firmě a nesmíme ho importovat (data leak prevention).
        $buyerIc = $this->normalizeIc((string) ($parsed['client']['ic'] ?? ''));
        $tenantIc = $this->fetchTenantIc($supplierId);
        if ($tenantIc !== null && $buyerIc !== null && $buyerIc !== $tenantIc) {
            throw new \InvalidArgumentException(
                "Doklad je vystavený na jinou firmu (odběratel IČO: {$buyerIc}, tenant IČO: {$tenantIc})."
            );
        }

        // Dodavatele musí jít IDENTIFIKOVAT — ne nutně podle IČO. Trvat na IČO je přísnější
        // než {@see ClientResolver::resolveVendor()}, který kartu umí dohledat i podle DIČ
        // (zahraniční dodavatelé bez českého IČO) a v poslední řadě podle názvu firmy.
        // Dokud tady stálo `empty($vendor['ic'])`, odmítal se přesně ten doklad, který by
        // resolver zpracoval — v migraci z Pohody takhle propadaly faktury od fyzických osob
        // bez IČO i od zahraničních dodavatelů, a to bez náhradní cesty, protože přijaté
        // faktury se jinak než importem hromadně nezadají.
        //
        // Bez jediného identifikátoru se doklad odmítá dál: založit kartu „bezejmenného"
        // dodavatele znamená vyrobit novou při každé faktuře a rozsypat evidenci závazků.
        $vendor = $parsed['supplier'] ?? null;
        $vendorIdentified = is_array($vendor) && (
            !empty($vendor['ic']) || !empty($vendor['dic']) || trim((string) ($vendor['company_name'] ?? '')) !== ''
        );
        if (!$vendorIdentified) {
            throw new \InvalidArgumentException(
                'Doklad neurčuje dodavatele (chybí IČO, DIČ i název firmy) — nelze ho zařadit '
                    . 'k žádné kartě dodavatele. Doplňte protistranu ve zdrojovém systému a doklad '
                    . 'importujte znovu, nebo ho zadejte ručně.',
            );
        }

        // Resolve vendor (najdi nebo vytvoř clients row s is_vendor=1)
        $resolved = $this->clientResolver->resolveVendor($vendor, $supplierId);

        // Build payload pro createDraft. Klíčové: currency_id lookup, items mapping.
        $currencyId = $this->resolveCurrencyId((string) ($parsed['currency'] ?? 'CZK'), $supplierId);
        // Sazba se páruje SDÍLENÝM resolverem, ne prostým hledáním procenta v celé tabulce:
        // ta obsahuje i sazby jiných členských států (OSS) a sazby s omezenou platností.
        // Slepé porovnání procent u tenanta s OSS číselníkem navázalo české 21 % klidně na
        // sazbu jiné země (rozhodlo pořadí řádků) a nenamapovanou cizí sazbu na nulu.
        // Resolver filtruje zemi dodavatele, platnost k datu i reverse charge — stejně jako
        // ostatní importní kanály (audit VAT klasifikací, M-6).
        $rateDate = (string) ($parsed['tax_date'] ?? '') ?: (string) ($parsed['issue_date'] ?? date('Y-m-d'));

        $items = [];
        foreach ((array) ($parsed['items'] ?? []) as $i => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $match = $this->planner->resolveDomesticRate($supplierId, $rate, $rateDate);
            if (!$match->found()) {
                throw new \InvalidArgumentException(sprintf('Položka č. %d: %s', $i + 1, $match->message));
            }
            $vatRateId = $match->id;
            $items[] = [
                'description'            => (string) ($line['description'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'unit'                   => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['unit_price_without_vat'] ?? 0),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $i,
            ];
        }

        // Snapshot plátcovství dodavatele na dokladu (migrace 0133) — explicitně.
        // Priorita: údaj nesený dokladem > výsledek ARES/VIES z resolveVendor (dnešní
        // stav, už propsaný do clients.is_vat_payer) > aktuální clients.is_vat_payer.
        // ISDOC/Pohoda parsery zatím vlastní signál nenesou, klíč je tu defensivně.
        $docVendorPayer = array_key_exists('is_vat_payer', $vendor) && $vendor['is_vat_payer'] !== null
            ? (bool) $vendor['is_vat_payer']
            : null;

        $payload = [
            'vendor_id'             => $resolved['id'],
            'vendor_is_vat_payer'   => $docVendorPayer
                ?? $resolved['is_vat_payer']
                ?? $this->liveVendorVatPayer((int) $resolved['id']),
            'vendor_invoice_number' => $this->safeVarsymbol((string) ($parsed['varsymbol'] ?? '')),
            'document_kind'         => $this->mapDocumentKind((string) ($parsed['invoice_type'] ?? 'invoice')),
            'issue_date'            => (string) ($parsed['issue_date'] ?? date('Y-m-d')),
            'tax_date'              => $parsed['tax_date'] !== null ? (string) $parsed['tax_date'] : null,
            'due_date'              => (string) ($parsed['due_date'] ?? date('Y-m-d', strtotime('+14 days'))),
            // Datum vlastního dokladu, ne datum importu. Na období odpočtu to nemá vliv —
            // o tom rozhoduje `received_at_source` (viz níž) — ale migrace tisíce let
            // starých dokladů jinak vyrobí sloupec „Datum přijetí", ve kterém má celá
            // historie firmy dnešek. Dnešní datum zůstává jen tam, kde doklad žádné
            // vlastní nemá, a budoucí datum se nedosazuje nikdy.
            'received_at'           => self::receivedAtFromDocument($parsed),
            // C6 (§ 73/1/a): received_at je jen otisk dat ze souboru, ne vědomé zadání data
            // držení dokladu účetní → 'import', aby VatLedgerService neposunul odpočet.
            'received_at_source'    => 'import',
            'currency_id'           => $currencyId,
            'exchange_rate'         => isset($parsed['exchange_rate']) && $parsed['exchange_rate'] !== null
                ? (float) $parsed['exchange_rate']
                : null,
            // Kurz přišel v ISDOCu od dodavatele, není odvozený z data (migrace 1303) →
            // automatické přenačtení po změně DUZP ho nepřepíše.
            'exchange_rate_source'  => 'import',
            'reverse_charge'        => !empty($parsed['reverse_charge']),
            'language'              => 'cs',
            'note_above_items'      => $parsed['note_above'] ?? null,
            'items'                 => $items,
            'status'                => $status === 'received' ? 'received' : 'draft',
        ];

        // Platební účet dodavatele z ISDOC <PaymentMeans> — pro „Zaplatit pomocí QR".
        // Repository nastaví source/checked_at jen pokud je účet skutečně použitelný.
        $isdocPayment = (array) ($parsed['payment'] ?? []);
        $payload['payment'] = [
            'account_number'  => $isdocPayment['account_number'] ?? null,
            'bank_code'       => $isdocPayment['bank_code'] ?? null,
            'iban'            => $isdocPayment['iban'] ?? null,
            'bic'             => $isdocPayment['bic'] ?? null,
            'variable_symbol' => $isdocPayment['variable_symbol'] ?? null,
            'source'          => 'isdoc',
        ];

        // Dedup guard — pokud (supplier, vendor, vendor_invoice_number, issue_date) tuple
        // už v systému je, vrátíme existující ID místo házení SQL duplicate key error.
        $existingId = $this->repo->findIdByVendorInvoice(
            $supplierId,
            $resolved['id'],
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            return [
                'purchase_invoice_id' => $existingId,
                'vendor_id'           => $resolved['id'],
                'vendor_created'      => $resolved['created'],
                'duplicate'           => true,
            ];
        }

        $id = $this->repo->createDraft($payload, $userId, $supplierId);
        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);

        // Seed override rekapitulace DPH dle dokladu (§ 73) — z <TaxTotal> (ISDOC)
        // nebo <invoiceSummary> (Pohoda). Drobné rozdíly zapeče dle dokladu, větší
        // jen ohlásí jako varování (PurchaseVatRecapSeeder). Stejný box jako AI.
        $docByRate = (array) ($parsed['vat_recap'] ?? []);
        if ($docByRate !== []) {
            $warning = (new PurchaseVatRecapSeeder($this->repo, $this->calc))->seed(
                $id,
                $supplierId,
                $docByRate,
                (string) ($parsed['currency'] ?? 'CZK'),
                $payload['document_kind'] === 'credit_note',
            );
            if ($warning !== null) {
                try {
                    $this->repo->appendExtractionWarning($id, $supplierId, $warning);
                } catch (\Throwable) {
                    // Varování je „nice to have" — faktura už je vytvořená správně.
                }
            }
        }

        // Auto-ČNB kurz pro non-CZK fakturu pokud ISDOC neobsahoval explicitní kurz
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            (string) ($parsed['currency'] ?? 'CZK'),
            (string) ($parsed['tax_date'] ?? $parsed['issue_date'] ?? ''),
            isset($parsed['exchange_rate']) ? (float) $parsed['exchange_rate'] : null,
        );

        // Zaokrouhlení „k úhradě" z ISDOC <LegalMonetaryTotal>/<PayableAmount>.
        // Až po recompute/seederu/ČNB (kdy je total_with_vat finální).
        $this->applyRoundingFromPayable($id, $supplierId, $parsed, $payload['document_kind'] === 'credit_note');

        return [
            'purchase_invoice_id' => $id,
            'vendor_id'           => $resolved['id'],
            'vendor_created'      => $resolved['created'],
            'duplicate'           => false,
        ];
    }

    /**
     * Uloží haléřové zaokrouhlení „k úhradě" z ISDOC <PayableAmount> jako rounding offset.
     *
     * Doklad nese přesnou částku k úhradě (už po <PayableRoundingAmount>); rozdíl proti
     * našemu deterministickému součtu položek (`total_with_vat`) = zaokrouhlení dodavatele.
     * Uloží se jen haléřový rozdíl (< 1 Kč), aby se nezapekla skutečná nesrovnalost dokladu.
     * Zrcadlí AiPdfExtractor::applyRoundingFromPdfTotal (PDF cesta), aby ISDOC i AI import
     * skončily se stejným amount_to_pay. `total_with_vat` zůstává základ+DPH (pro DPH/KH).
     *
     * @param array<string,mixed> $parsed
     */
    private function applyRoundingFromPayable(int $id, int $supplierId, array $parsed, bool $isCredit): void
    {
        $payable = isset($parsed['payable_amount']) && $parsed['payable_amount'] !== null
            ? abs((float) $parsed['payable_amount'])
            : null;
        if ($payable === null || $payable === 0.0) return;

        $current = $this->repo->find($id, $supplierId);
        if ($current === null) return;
        $exactTotal = abs((float) ($current['total_with_vat'] ?? 0));
        if ($exactTotal === 0.0) return;

        $diff = round($payable - $exactTotal, 2);
        if (abs($diff) > 0.0 && abs($diff) < 1.0) {
            try {
                $this->repo->setRounding($id, $supplierId, $isCredit ? -1.0 * $diff : $diff);
            } catch (\Throwable) {
                // rounding je „nice to have" — faktura je vytvořená správně i bez něj.
            }
        }
    }

    private function liveVendorVatPayer(int $vendorId): ?bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM clients WHERE id = ?');
        $stmt->execute([$vendorId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (bool) $v;
    }

    private function fetchTenantIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === '' || $ic === null) return null;
        return $this->normalizeIc((string) $ic);
    }

    /**
     * Datum přijetí odvozené z dokladu: DUZP, jinak datum vystavení, jinak dnešek.
     * Budoucí datum se nedosazuje — doklad, který ještě nenastal, jsme nemohli převzít.
     *
     * @param array<string,mixed> $parsed
     */
    private static function receivedAtFromDocument(array $parsed): string
    {
        $today = date('Y-m-d');
        foreach ([$parsed['tax_date'] ?? null, $parsed['issue_date'] ?? null] as $candidate) {
            $date = trim((string) ($candidate ?? ''));
            if ($date === '') continue;
            $date = substr($date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) continue;

            return $date <= $today ? $date : $today;
        }

        return $today;
    }

    /**
     * Zero-pad na 8 míst (kanonický tvar IČO) — {@see \MyInvoice\Support\CompanyIdNormalizer}.
     * ISDOC je strukturované, ale zdrojová data dodavatele mohou IČO uvádět bez
     * úvodní nuly stejně jako AI extrakce (BUG 2, vendor bugreport 2026-08-06) —
     * srovnání s tenant IČO musí být robustní i tady.
     */
    private function normalizeIc(string $ic): ?string
    {
        return \MyInvoice\Support\CompanyIdNormalizer::ic($ic);
    }

    /**
     * Mapuje ISDOC document_type → purchase_invoices document_kind enum.
     */
    private function mapDocumentKind(string $invoiceType): string
    {
        return match ($invoiceType) {
            'credit_note' => 'credit_note',
            'proforma'    => 'advance',
            default       => 'invoice',
        };
    }

    /**
     * Najdi currency_id z code (CZK/EUR/USD) per tenant. Pokud chybí, vytvoří
     * "jen pro nákup" měnu (is_active=0). To je konzistentní s UI flow (+ měna v editoru).
     */
    private function resolveCurrencyId(string $code, int $supplierId): int
    {
        $code = strtoupper(trim($code));
        if ($code === '') $code = 'CZK';

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        // Auto-create jako nákupní měna (is_active=0)
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0)'
        )->execute([$supplierId, $code, "{$code} — jen pro nákup", $code, $code, $code]);
        return (int) $pdo->lastInsertId();
    }


    /**
     * Sanitize vendor invoice number. ISDOC `ID` může být cokoliv — náš sloupec
     * vendor_invoice_number VARCHAR(50), takže ořezat. Nedovolit kontrolní znaky.
     */
    private function safeVarsymbol(string $vs): string
    {
        $vs = trim($vs);
        if ($vs === '') $vs = 'ISDOC-import';
        // Remove control chars
        $vs = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vs);
        if (strlen($vs) > 50) $vs = substr($vs, 0, 50);
        return $vs;
    }
}
