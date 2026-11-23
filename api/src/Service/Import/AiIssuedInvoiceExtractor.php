<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Accrual\AccrualPeriodDetector;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\Oss\OssItemPlanner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AI import VYDANÉ faktury (zrcadlo {@see AiPdfExtractor} pro prodejní stranu).
 *
 * Pipeline (stejné pořadí jako přijatá strana, přes sdílený {@see InvoiceExtractionRouter}):
 *   1. ISDOC-first — validní ISDOC/ISDOCX/embedded ISDOC ⇒ deterministický parse (0 AI cost).
 *   2. Obrázek (fotka) → normalizace na PDF ({@see ImageToPdfConverter}).
 *   3. Fallback na LLM extrakci ({@see LlmGatewayInterface::extractInvoice}).
 *
 * Rozdíl proti přijaté straně: tenant je DODAVATEL (vystavil doklad), odběratel z dokladu
 * je náš KLIENT. Výsledkem je DRAFT vydané faktury (status 'draft'), který uživatel v editoru
 * zkontroluje a teprve pak vystaví — proto se číslo (varsymbol) generuje až při vystavení a
 * tady se nastavuje jen jako override, když je z dokladu k dispozici a nekoliduje.
 *
 * Vytváří draft stejnou cestou jako {@see \MyInvoice\Action\Invoice\CreateInvoiceAction}:
 * createDraft() → replaceItems() → recompute().
 */
final class AiIssuedInvoiceExtractor
{
    private readonly LoggerInterface $logger;

    /**
     * Varování k derivaci OSS a k párování sazby za právě zakládaný doklad. Vrací se
     * volajícímu v odpovědi — u AI extrakce je to jediné místo, kde je uživatel uvidí,
     * a „místo plnění je sporné" nesmí zmizet jen proto, že import proběhl úspěšně.
     *
     * @var list<string>
     */
    private array $ossWarnings = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LlmGatewayInterface $llm,
        private readonly ClientResolver $clientResolver,
        private readonly InvoiceRepository $repo,
        private readonly InvoiceCalculator $calc,
        private readonly InvoiceExtractionRouter $router,
        private readonly IsdocParser $isdoc,
        private readonly ImageToPdfConverter $imageToPdf,
        private readonly OssItemPlanner $planner,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Schopnosti aktivního providera (whitelist modelů) — proxy na bránu, aby caller
     * mohl validovat `?model=` override proti povolenému whitelistu.
     */
    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        return $this->llm->capabilities($supplierId);
    }

    /**
     * Extract + create draft vydané faktury.
     *
     * @return array{ok:bool, invoice_id?:int, client_id?:int, source:string,
     *               error?:string, ai_data?:array<string,mixed>, model?:string,
     *               usage?:array<string,int>, provider?:string, region?:string,
     *               warnings?:list<string>}
     */
    public function extractAndCreate(int $supplierId, int $userId, string $bytes, ?string $modelOverride = null, ?string $originalFilename = null): array
    {
        // Služba může v jednom requestu obsloužit víc souborů — bez resetu by druhý
        // doklad zdědil varování prvního.
        $this->ossWarnings = [];
        $tenantIc = $this->fetchTenantIc($supplierId);

        // ISDOC-first rozhodnutí (sdílený router se stejnou sémantikou jako přijatá strana).
        $decision = $this->router->decide($bytes);

        // Validní ISDOC (isdocx balíček / embedded v PDF/A-3) ⇒ AI se NIKDY nevolá.
        if ($decision->isdocPresent && !$decision->useLlm && $decision->parsed !== null) {
            try {
                return $this->createFromIsdoc((array) $decision->parsed, $supplierId, $userId, $tenantIc, (string) $decision->source);
            } catch (\Throwable $e) {
                $this->logger->warning('AI issued import: ISDOC mapování selhalo', [
                    'supplier_id' => $supplierId,
                    'error'       => $e->getMessage(),
                ]);
                return ['ok' => false, 'error' => $e->getMessage(), 'source' => 'isdoc_map_failed'];
            }
        }

        // Obrázek (fotka z telefonu) → normalizuj na PDF pro AI cestu.
        if (!str_starts_with($bytes, '%PDF')) {
            $imgMime = $this->imageToPdf->detectImageMime($bytes);
            if ($imgMime !== null) {
                try {
                    $bytes = $this->imageToPdf->convert($bytes, $imgMime);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => $e->getMessage(), 'source' => 'image_convert_failed'];
                }
            }
        }

        // AI extrakce (fallback).
        $extracted = $this->llm->extractInvoice($supplierId, $bytes, $modelOverride);
        if (!$extracted['ok']) {
            $notConfigured = ($extracted['code'] ?? $extracted['error'] ?? null) === 'provider_not_configured';
            return [
                'ok'     => false,
                'error'  => $extracted['error'] ?? 'AI extrakce selhala',
                'source' => $notConfigured ? 'ai_not_configured' : 'ai_failed',
            ];
        }
        $data = (new AiExtractedDataClipper($this->db))->clipIssued((array) ($extracted['data'] ?? []));

        $validationError = $this->validateAiData($data);
        if ($validationError !== null) {
            return [
                'ok'      => false,
                'error'   => 'AI extrakce neprošla validací: ' . $validationError,
                'ai_data' => $data,
                'source'  => 'ai_invalid',
                'model'   => $extracted['model'] ?? null,
                'usage'   => $extracted['usage'] ?? null,
            ];
        }

        // Orientace prodejní strany: tenant je DODAVATEL (vendor), odběratel (customer) = náš klient.
        // AI občas prohodí vendor↔customer (dá tenanta do customer slotu) → prohodit zpět.
        $vendorIc   = $this->normalizeIc((string) ($data['vendor']['ic'] ?? ''));
        $customerIc = $this->normalizeIc((string) ($data['customer']['ic'] ?? ''));
        if ($tenantIc !== null && $customerIc === $tenantIc && $vendorIc !== $tenantIc && $vendorIc !== null) {
            $this->logger->info('AI issued import: detected vendor↔customer swap (tenant v customer slotu), swapping back', [
                'vendor_ic' => $vendorIc, 'customer_ic' => $customerIc, 'tenant_ic' => $tenantIc,
            ]);
            $tmp = $data['vendor'] ?? [];
            $data['vendor']   = $data['customer'] ?? [];
            $data['customer'] = $tmp;
        }

        $customerData = (array) ($data['customer'] ?? []);
        if (empty($customerData['ic']) && empty($customerData['company_name'])) {
            return ['ok' => false, 'error' => 'AI nevrátila odběratele (customer)', 'ai_data' => $data, 'source' => 'no_customer'];
        }

        try {
            $resolved = $this->clientResolver->resolve($customerData, $supplierId);
            $invoiceId = $this->createDraft($this->mapAiToDraft($data, $resolved['id'], $supplierId), $userId, $supplierId);
            return [
                'ok'         => true,
                'invoice_id' => $invoiceId,
                'client_id'  => $resolved['id'],
                'source'     => 'ai',
                'model'      => $extracted['model'] ?? null,
                'usage'      => $extracted['usage'] ?? null,
                'provider'   => $extracted['provider'] ?? null,
                'region'     => $extracted['region'] ?? null,
                'ai_data'    => $data,
                'warnings'   => $this->ossWarnings,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'error'   => 'Vytvoření draftu selhalo: ' . $e->getMessage(),
                'ai_data' => $data,
                'source'  => 'create_failed',
            ];
        }
    }

    /**
     * Vytvoří draft vydané faktury z naparsovaného ISDOC ($parsed z routeru).
     * ISDOC nese `supplier` (dodavatel = my) i `client` (odběratel). Ověříme, že dodavatel
     * z dokladu je tenant (jinak doklad patří jinému dodavateli), a klienta resolvneme.
     *
     * @param array{supplier_ic?:?string, invoices:list<array<string,mixed>>} $parsed
     */
    private function createFromIsdoc(array $parsed, int $supplierId, int $userId, ?string $tenantIc, string $source): array
    {
        $inv = (array) (($parsed['invoices'][0] ?? []));
        if ($inv === [] || isset($inv['__error'])) {
            return ['ok' => false, 'error' => (string) ($inv['__error'] ?? 'ISDOC neobsahuje fakturu'), 'source' => 'isdoc_map_failed'];
        }

        // Dodavatel z dokladu musí být tenant (prodejní strana). Pokud IČO tenanta známe
        // a nesedí ani se supplier IČO na dokladu, doklad patří jinému dodavateli.
        $docSupplierIc = $this->normalizeIc((string) ($inv['supplier']['ic'] ?? $inv['__supplier_ic'] ?? ($parsed['supplier_ic'] ?? '')));
        if ($tenantIc !== null && $docSupplierIc !== null && $docSupplierIc !== $tenantIc) {
            return [
                'ok'     => false,
                'error'  => "ISDOC patří jinému dodavateli (dodavatel IČO: {$docSupplierIc}, tenant: {$tenantIc}).",
                'source' => 'wrong_tenant',
            ];
        }

        $clientData = (array) ($inv['client'] ?? []);
        if (empty($clientData['ic']) && empty($clientData['company_name'])) {
            return ['ok' => false, 'error' => 'ISDOC nemá odběratele', 'source' => 'no_customer'];
        }

        // Dedup na varsymbolu — mirror pdf-hash dedupu přijaté strany. Pokud faktura se
        // stejným číslem už u tenanta existuje, vrať ji (idempotentní re-import).
        $varsymbol = $this->sanitizeVarsymbol((string) ($inv['varsymbol'] ?? ''));
        if ($varsymbol !== null && ($existing = $this->findByVarsymbol($supplierId, $varsymbol)) !== null) {
            return ['ok' => true, 'invoice_id' => $existing, 'client_id' => 0, 'source' => 'duplicate'];
        }

        $resolved = $this->clientResolver->resolve($clientData, $supplierId);

        $draft = [
            'client_id'          => $resolved['id'],
            'invoice_type'       => $this->normalizeInvoiceType((string) ($inv['invoice_type'] ?? 'invoice')),
            'issue_date'         => (string) ($inv['issue_date'] ?? date('Y-m-d')),
            'tax_date'           => $inv['tax_date'] ?? null,
            'due_date'           => (string) ($inv['due_date'] ?? $inv['issue_date'] ?? date('Y-m-d')),
            'currency_id'        => $this->resolveCurrencyId((string) ($inv['currency'] ?? 'CZK'), $supplierId),
            'reverse_charge'     => !empty($inv['reverse_charge']),
            'prices_include_vat' => false,
            'language'           => 'cs',
            'varsymbol'          => $varsymbol,
            'payment_variable_symbol' => VariableSymbolNormalizer::importedPaymentOverride(
                (string) ($inv['varsymbol'] ?? ''),
                isset($inv['payment']['variable_symbol']) ? (string) $inv['payment']['variable_symbol'] : null,
            ),
            'items'              => $this->mapItems((array) ($inv['items'] ?? [])),
        ];

        $invoiceId = $this->createDraft($draft, $userId, $supplierId);

        // Odpočet záloh a nesedící součet: tady doklad vzniká jako koncept tak jako tak,
        // uživatel ale musí vědět, že ho před vystavením musí navázat na daňový doklad
        // k záloze. Pravidlo je společné s importem ({@see ImportedIssuedDocumentPolicy}).
        $total = $this->db->pdo()->prepare('SELECT total_with_vat FROM invoices WHERE id = ?');
        $total->execute([$invoiceId]);
        $linesTotal = (float) $total->fetchColumn();
        $assessment = ImportedIssuedDocumentPolicy::assess($inv, $linesTotal);
        ImportedIssuedDocumentPolicy::applyPayableRounding($this->db->pdo(), $invoiceId, $inv, $linesTotal);

        return [
            'ok' => true,
            'invoice_id' => $invoiceId,
            'client_id' => $resolved['id'],
            'source' => $source,
            'warnings' => array_merge($this->ossWarnings, $assessment['review'], $assessment['notes']),
        ];
    }

    /**
     * Zmapuje AI-extrahovaná data (vendor/customer/items) na payload draftu vydané faktury.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapAiToDraft(array $data, int $clientId, int $supplierId): array
    {
        $documentKind = strtolower((string) ($data['document_kind'] ?? 'invoice'));
        // Číslo dokladu z AI = varsymbol vydané faktury (jen když nekoliduje). VS z dokladu
        // jde zvlášť do payment_variable_symbol, když se od čísla liší (#249).
        $documentNumber = (string) (($data['vendor_invoice_number'] ?? '') ?: ($data['payment']['variable_symbol'] ?? ''));
        $varsymbol = $this->sanitizeVarsymbol($documentNumber);
        if ($varsymbol !== null && $this->findByVarsymbol($supplierId, $varsymbol) !== null) {
            $varsymbol = null; // nech vygenerovat při vystavení, ať draft nekoliduje na uq indexu
        }

        return [
            'client_id'          => $clientId,
            'invoice_type'       => $this->normalizeInvoiceType($documentKind),
            'issue_date'         => (string) $data['issue_date'],
            'tax_date'           => isset($data['tax_date']) && $data['tax_date'] ? (string) $data['tax_date'] : null,
            'due_date'           => (string) ($data['due_date'] ?? $data['issue_date']),
            'currency_id'        => $this->resolveCurrencyId((string) $data['currency'], $supplierId),
            'reverse_charge'     => !empty($data['reverse_charge']),
            'prices_include_vat' => !empty($data['unit_prices_include_vat']),
            'language'           => 'cs',
            'varsymbol'          => $varsymbol,
            'payment_variable_symbol' => VariableSymbolNormalizer::importedPaymentOverride(
                $documentNumber,
                isset($data['payment']['variable_symbol']) ? (string) $data['payment']['variable_symbol'] : null,
            ),
            'items'              => $this->mapItems((array) ($data['items'] ?? [])),
        ];
    }

    /**
     * Společné založení draftu vydané faktury (stejná cesta jako CreateInvoiceAction).
     *
     * Tady — a ne v {@see mapItems()} — se řádky dostávají k derivaci OSS a k párování
     * sazby: rozhodnutí potřebuje odběratele, datum plnění i hlavičkový příznak
     * přenesené daňové povinnosti, tedy údaje, které existují až na hotovém draftu.
     * Plánuje se PŘED `createDraft()`, aby odmítnutý řádek nenechal v databázi
     * prázdnou fakturu.
     *
     * @param array<string,mixed> $draft
     */
    private function createDraft(array $draft, int $userId, int $supplierId): int
    {
        $draft['items'] = $this->planner->planIssuedItems(
            $supplierId,
            (int) $draft['client_id'],
            (string) ($draft['tax_date'] ?? '') ?: (string) $draft['issue_date'],
            !empty($draft['reverse_charge']),
            (array) $draft['items'],
            $this->ossWarnings,
        );

        $id = $this->repo->createDraft($draft, $userId);
        $this->repo->replaceItems($id, (array) $draft['items']);
        $this->calc->recompute($id);
        if (ImportedIssuedDocumentPolicy::isPaidByNature((string) ($draft['invoice_type'] ?? ''))) {
            ImportedIssuedDocumentPolicy::settleTaxDocument($this->db->pdo(), $id);
        }
        return $id;
    }

    /**
     * Řádky z AI/ISDOC → surový item payload. Procento sazby zůstává jako `vat_rate`;
     * na `vat_rate_id` (a na OSS sloupce) ho převede až {@see createDraft()} přes
     * sdílený plánovač.
     *
     * Dřívější vlastní párování hledalo NEJBLIŽŠÍ procento napříč celou tabulkou
     * `vat_rates`, takže polských 23 % se navázalo na českých 21 % — a doklad tím tiše
     * změnil odvedenou daň. Nenalezená sazba je od sjednocení chyba dokladu s návodem,
     * ne tichá náhrada.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array{description:string, quantity:float, unit:string, unit_price_without_vat:float, vat_rate:float, order_index:int, accrual_from:?string, accrual_to:?string}>
     */
    private function mapItems(array $lines): array
    {
        $items = [];
        foreach (array_values($lines) as $idx => $line) {
            $line = (array) $line;
            // Období plnění (časové rozlišení 384): hodnota z modelu, jinak rozpoznání
            // z popisu. ISDOC období nenese, u něj rozhoduje jen popis.
            $accrual = AccrualPeriodDetector::resolveForItem(
                $line['accrual_from'] ?? null,
                $line['accrual_to'] ?? null,
                (string) ($line['description'] ?? ''),
            );
            $items[] = [
                'description'            => (string) ($line['description'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'duration_minutes'       => $line['duration_minutes']
                    ?? TimeBilling::inferDurationMinutes($line['quantity'] ?? 1, $line['unit'] ?? 'ks'),
                'unit'                   => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['unit_price_without_vat'] ?? 0),
                'vat_rate'               => (float) ($line['vat_rate'] ?? 0),
                'order_index'            => $idx,
                'accrual_from'           => $accrual['from'] ?? null,
                'accrual_to'             => $accrual['to'] ?? null,
            ];
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validateAiData(array $data): ?string
    {
        if (empty($data['issue_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['issue_date'])) {
            return 'invalid issue_date (musí být YYYY-MM-DD)';
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'invalid currency (musí být ISO 4217, např. CZK)';
        }
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return 'chybí items (alespoň jedna položka)';
        }
        foreach ($data['items'] as $i => $item) {
            if (empty($item['description'])) return "item[{$i}] chybí description";
            if (!isset($item['quantity'])) return "item[{$i}] chybí quantity";
            if (!isset($item['unit_price_without_vat'])) return "item[{$i}] chybí unit_price_without_vat";
        }
        return null;
    }

    /**
     * Druh dokladu z ISDOC (`invoice_type`) nebo z AI (`document_kind`) na druh vydaného dokladu.
     *
     * Daňový doklad k přijaté platbě (ISDOC DocumentType 5, AI `tax_document`) zůstává
     * `tax_document`. Dřív propadl do `invoice`, takže z DDPP vznikla řádná faktura
     * a po vystavení se tržba i DPH zaevidovaly podruhé vedle konečné faktury.
     *
     * Vzniká KONCEPT se správným druhem a `amount_to_pay` = 0 (úplata už přišla, viz
     * {@see ImportedIssuedDocumentPolicy::settleTaxDocument()}). Import souborů ho zakládá
     * rovnou jako zaplacený; tady ne, protože doklad z AI vždycky čeká na kontrolu
     * uživatele. Vazbu na zálohovou fakturu (`parent_invoice_id`) doklad nemá, takže ho
     * vystavení nepárově neoznačí jako zaplacený (InvoiceAmountPolicy to u DDPP bez
     * zálohy záměrně nedělá) — do pohledávek ale nespadne, protože nemá co doplatit.
     */
    private function normalizeInvoiceType(string $kind): string
    {
        return match ($kind) {
            'credit_note', 'creditnote' => 'credit_note',
            'proforma', 'advance'       => 'proforma',
            'tax_document'              => 'tax_document',
            default                     => 'invoice',
        };
    }

    private function resolveCurrencyId(string $code, int $supplierId): int
    {
        $code = strtoupper($code) ?: 'CZK';
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Měna {$code} není nakonfigurovaná pro tohoto dodavatele.");
        }
        return (int) $id;
    }

    private function findByVarsymbol(int $supplierId, string $varsymbol): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1');
        $stmt->execute([$supplierId, $varsymbol]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Whitelist znaků + max délka (DB sloupec varsymbol VARCHAR(20)); jinak null. */
    private function sanitizeVarsymbol(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
        if ($clean === '') return null;
        return substr($clean, 0, 20);
    }

    /**
     * Zero-pad na 8 míst (kanonický tvar IČO) — {@see \MyInvoice\Support\CompanyIdNormalizer}.
     * Stejná třída dokladů (AI extrakce) je stejně náchylná ke ztrátě úvodní nuly
     * IČO jako přijatá strana (BUG 2, vendor bugreport 2026-08-06).
     */
    private function normalizeIc(string $ic): ?string
    {
        return \MyInvoice\Support\CompanyIdNormalizer::ic($ic);
    }

    private function fetchTenantIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === null) return null;
        return $this->normalizeIc((string) $ic);
    }
}
