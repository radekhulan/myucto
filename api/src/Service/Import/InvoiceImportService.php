<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Oss\OssItemDecision;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Oss\OssRateCodebook;
use MyInvoice\Service\Vat\VatRateResolver;
use ZipArchive;

/**
 * Orchestrace importu vystavených faktur z Pohoda XML / ISDOC (single nebo ZIP balík).
 *
 * Pravidla:
 *   - Supplier IČO z XML musí odpovídat aktuálnímu scope. Jinak fail per file.
 *   - Klient: lookup po IČO; pokud chybí, ARES → vytvoř.
 *   - Project:
 *       a) faktura má project_number → najít nebo vytvořit (per-klient unikátní project_number).
 *       b) napříč balíkem má klient >1 odlišných emailů → per-(client, email) projekt s názvem
 *          "{company_name} – {email}", projekt se přiřadí podle emailu faktury.
 *       c) jinak project_id = NULL.
 *   - Duplicity: pokud (supplier_id, varsymbol) existuje u dokladu TÉHOŽ druhu → skip
 *     s reportem. Existuje-li u dokladu JINÉHO druhu (opravný daňový doklad nese symbol
 *     opravované faktury), jde o kolizi, ne o duplicitu — symbol se odvodí z čísla dokladu
 *     a náhrada jde do reportu ({@see processOne()}).
 *   - Doklad bez jediné položky se NEZAKLÁDÁ (odmítne se) — vznikl by doklad na nulu.
 *   - Status: pokud je due_date starší než 30 dní od dnešního data → 'paid'
 *     (paid_at = tax_date|issue_date), jinak 'issued' (paid_at = NULL).
 *   - Snapshoty: vyrobí čerstvé z aktuálního supplier/client/bank.
 *
 * ── Sazba, klasifikace a OSS na řádku ───────────────────────────────────────────────
 * Řádky se PLÁNUJÍ ({@see planItems()}) ještě před vznikem hlavičky a teprve pak zapisují.
 * Import totiž nejede v transakci per doklad, a nenapárovaná sazba je podle kontraktu
 * tvrdá chyba CELÉHO dokladu (doklad s vynechanou položkou má špatné součty) — kdyby se
 * plánovalo až po insertu hlavičky, zůstala by v databázi faktura bez položek.
 *
 * ── Co skutečně drží cizí daň mimo ř. 1 tuzemského přiznání ─────────────────────────
 * Jediný účinný zámek je příznak `oss_applicable`: {@see \MyInvoice\Service\Report\VatLedgerService}
 * i {@see \MyInvoice\Service\Report\DphPriznaniBuilder} filtrují přes
 * `COALESCE(oss_applicable, 0) = 0`. Předání země odběratele do
 * {@see InvoiceRepository::defaultSaleClassificationCode()} na tom NEMĚNÍ NIC — u NENULOVÉ
 * sazby klasifikátor zemi vůbec nečte (větev s `$clientCountryIso2` platí jen pro sazbu
 * 0 %, dál už rozhoduje samo `if ($r >= 21) return '1';`). Polských 23 % dostane kód '1'
 * se zemí i bez ní.
 *
 * Důsledek pro derivaci: `oss_applicable = 0` je jediná chyba, kterou dál po trase už nic
 * nezachytí. Chyby proto NEJSOU symetricky viditelné — chybně označený OSS řádek se objeví
 * v náhledu OSS podání, což je krátký seznam procházený před odesláním, kdežto chybně
 * označený tuzemský řádek zmizí mezi stovkami řádků přiznání k DPH. Nejistotu proto
 * {@see OssItemDeriver} řeší VE PROSPĚCH OSS a řádek označí jako případ k ručnímu
 * posouzení; a tam, kde řádek OSS být nemůže a sazba přitom v zemi dodavatele podle
 * číselníku členských států neplatí, položku rovnou ODMÍTNE
 * ({@see \MyInvoice\Service\Oss\OssItemDecision::isRejected()}). Odmítnutí bere import jako tvrdou chybu
 * CELÉHO dokladu — viz {@see planItems()}.
 *
 * ── Hranice: data se kanonizují a číselník se ověřuje PŘED zápisem ──────────────────
 * Invariant proti úniku stojí a padá s tím, na co se derivace ptá. Import proto ošetřuje
 * dvě věci na hranici, ne až uvnitř:
 *   - datum vystavení / DUZP / splatnost se převádí na kanonický 'Y-m-d'
 *     ({@see normalizeDates()}), protože platnost sazby i registrace do OSS se porovnává
 *     jako ŘETĚZEC — nekanonický, ale databází přijímaný tvar ('2096-5-15') proto obešel
 *     invariant ještě dřív, než se vyhodnotil;
 *   - dostupnost číselníku sazeb členských států se ověřuje JEDNOU za běh
 *     ({@see assertRateCodebookAvailable()}), protože bez něj by se odmítla každá položka
 *     se sazbou vyšší než 0 % a uživatel by dostal tisíce hlášek o jedné příčině.
 *
 * ── Sazba, kterou soubor sám neurčuje ───────────────────────────────────────────────
 * Parsery vrací `vat_rate = null` všude, kde procento ze souboru nejde ani přečíst
 * (`inv:percentVAT`, `rateVAT/@value`), ani dopočítat z rekapitulace TÉHOŽ souboru.
 * Zbývá jim pak jen ČESKÁ SAZBOVÁ ÚROVEŇ z `<inv:rateVAT>` (`vat_rate_level`), a ta
 * procento neurčuje: dosadit za `high` aktuálních 21 % je dohad, kterým polských 23 %
 * projde kvadrantem „sazba platí jen v tuzemsku" a skončí na ř. 1 českého přiznání —
 * bez jediného varování, protože 21 % v ČR k datu plnění skutečně platí. Překlad úrovně
 * na procento proto dělá až import ({@see itemRate()}), a jen tam, kde to dohad není:
 *   - odběratel musí být TUZEMSKÝ; u zahraničního je enum bez procenta nejednoznačný,
 *     protože Pohoda schema zahraniční sazby nezná (viz hlášení zákazníka v analýze OSS)
 *     a právě tam by dosazení vyrobilo z cizí daně českou;
 *   - procento se bere z ČÍSELNÍKU SAZEB ČLENSKÝCH STÁTŮ pro zemi dodavatele K DATU
 *     PLNĚNÍ, tedy z téhož podkladu, proti kterému rozhoduje invariant — nikdy
 *     z konstanty v kódu: 'reduced' je v ČR 15 % do konce roku 2023 a 12 % od roku 2024,
 *     takže konstanta by zpětně datovaný doklad přepočítala dnešní sazbou.
 * Nejde-li ani to, zůstává sazba neznámá a položka je tvrdá chyba CELÉHO dokladu
 * ({@see unresolvedRateMessage()} říká, co konkrétně v souboru chybí).
 *
 * ── U NULOVÉ sazby země naopak rozhoduje o hodně ────────────────────────────────────
 * Tam se klasifikace překlopí z '3' na '20'/'22' (dodání zboží / poskytnutí služby do
 * jiného členského státu), a tyhle dva kódy jdou do SOUHRNNÉHO HLÁŠENÍ
 * ({@see \MyInvoice\Service\Report\SouhrnneHlaseniBuilder}). SH se ale podává za plnění
 * osobě registrované k dani v JČS — u B2C spotřebitele bez DIČ by vznikl řádek výkazu
 * bez protistrany. Země se proto předává jen tam, kde to dává smysl
 * ({@see classificationCountry()}).
 *
 * ── Report ──────────────────────────────────────────────────────────────────────────
 * Přesný tvar výstupu (per doklad i souhrn za běh) popisuje {@see importBundle()}.
 */
final class InvoiceImportService
{
    /** Pod touhle hodnotou je součet dokladu v haléřích nula — znaménko z něj neurčujeme. */
    private const CREDIT_SIGN_EPSILON = 0.005;

    /** Druhy dokladů pojmenované tak, jak jim uživatel říká — do hlášek reportu. */
    private const INVOICE_TYPE_LABELS = [
        'invoice'          => 'faktura',
        'proforma'         => 'zálohová faktura',
        'credit_note'      => 'opravný daňový doklad (dobropis)',
        'tax_document'     => 'daňový doklad k přijaté platbě',
        'cancellation'     => 'storno',
        'penalty'          => 'penalizační faktura',
        'payment_calendar' => 'splátkový kalendář',
    ];

    /** Bezpečnostní limity proti zip-bomb / DoS. */
    private const MAX_ZIP_ENTRIES = 500;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 50 * 1024 * 1024; // 50 MiB
    private const MAX_SINGLE_ENTRY_BYTES = 10 * 1024 * 1024;       // 10 MiB

    /**
     * Memoizace sazeb země dodavatele k datu pro překlad sazbové ÚROVNĚ na procento
     * ({@see itemRate()}). Import 1 670 dokladů se nesmí ptát číselníku za každý řádek;
     * klíč je „země|datum plnění", protože odpověď na obojí závisí.
     *
     * @var array<string, list<array{rate_type:string, rate_percent:float}>>
     */
    private array $domesticScaleRates = [];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly ProjectRepository $projects,
        private readonly ClientResolver $clientResolver,
        private readonly PohodaXmlParser $pohoda,
        private readonly IsdocParser $isdoc,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoiceCalculator $calculator,
        private readonly IsdocToPurchaseInvoiceMapper $purchaseMapper,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly PurchaseInvoicePdfArchiver $pdfArchiver,
        private readonly OssItemDeriver $ossDeriver,
        private readonly VatRateResolver $vatRateResolver,
        private readonly OssRateCodebook $codebook,
        private readonly OssItemPlanner $planner,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly StatsRecomputer $stats,
        private readonly AccountingPeriodProvisioner $periodProvisioner,
    ) {}

    /**
     * Import balíku souborů — vystavené i přijaté faktury.
     *
     * `$kind` parametr:
     *   - `'issued'`   — všechny soubory zpracovat jako vydané faktury (legacy behavior).
     *                    Soubory s buyer-tenant IČO (= my zákazník) skipnout jako odmítnuté.
     *   - `'purchase'` — všechny zpracovat jako přijaté faktury (vendor je supplier z ISDOC).
     *   - `'auto'`     — per-soubor detekce dle IČO:
     *       supplier IČO == tenant → my dodavatel → issued cesta
     *       customer IČO == tenant → my zákazník → purchase cesta
     *       ani jedno → reject (cizí ISDOC)
     *
     * ── Tvar reportu ────────────────────────────────────────────────────────────────
     * Uživatel s 850 doklady report nepřečte po dokladech; potřebuje NEJDŘÍV jedno číslo
     * na obrazovku („17 řádků čeká na typ sazby") a teprve pak se proklikat k dokladům.
     * Souhrn proto není odvoditelný z `results` na frontendu — počítá se tady, aby se obě
     * čísla nemohla rozejít.
     *
     * `summary` (celý běh):
     *   - `created` / `skipped` / `failed` — počty DOKLADŮ, beze změny;
     *   - `oss_items` — kolik ŘÁDKŮ se zařadilo do OSS;
     *   - `oss_rate_type_unknown` — z toho řádků bez potvrzeného typu sazby (do podání
     *     se bez ručního doplnění nedostanou);
     *   - `oss_manual_review` — ŘÁDKŮ, u nichž je MÍSTO PLNĚNÍ SPORNÉ. Tři různé situace:
     *     sazba platí v zemi dodavatele i ve státě spotřeby, číselníku se nedalo zeptat,
     *     nebo je řádek tuzemský přesto, že jde o přeshraniční B2C plnění při aktivní
     *     registraci do OSS. Kategorie se s `oss_items` PŘEKRÝVÁ, ale není jeho
     *     podmnožinou: první dvě situace řádek do OSS zařadí, třetí ho nechává tuzemský,
     *     a započítaný je i řádek dokladu, který si protiřečí ({@see planItems()}).
     *     Příznak se zároveň ukládá k položce (`invoice_items.oss_needs_manual_review`,
     *     migrace 1293), aby šly řádky dohledat i po zavření reportu — a odtud ho čte
     *     i náhled OSS podání ({@see \MyInvoice\Service\Oss\OssLedgerService::preview()});
     *   - `oss_credit_notes_pending_period` — DOKLADŮ typu dobropis s OSS řádkem, kterým
     *     chybí původní OSS období (import ho nedoplňuje, viz {@see planItems()});
     *   - `varsymbol_substituted` — DOKLADŮ, kterým jsme variabilní symbol dosadili;
     *   - `with_warnings` — DOKLADŮ s aspoň jedním varováním.
     *
     * Doklad, jehož některá položka se ODMÍTLA (invariant proti úniku cizí daně, viz
     * {@see planItems()}), má `status = 'failed'` a hlášku s návodem v `reason` — do OSS
     * čítačů nepřispěje ničím, protože se nic nezapsalo.
     *
     * Chybí-li číselník sazeb členských států, běh se ZASTAVÍ výjimkou ještě před prvním
     * zápisem a report nevznikne vůbec ({@see assertRateCodebookAvailable()}).
     *
     * Řádek v `results` nese vždy `file`, `status`, `kind` a `reason`. Doklad, který došel
     * až k plánování řádků, navíc `notes` / `warnings` (obojí `list<string>`),
     * `varsymbol_substituted` (bool) a `document_number` (číslo z původního systému).
     * Doklad rozstřelený dřív — nečitelný soubor, cizí IČO, výjimka — má z toho jen
     * `reason`, takže frontend čte VŠECHNY tyhle klíče přes `?? []` / `?? false`.
     *
     * Per-doklad čítače `oss_items`, `oss_rate_type_unknown`, `oss_manual_review`
     * a `oss_credit_note_pending_period` posílá jen `status = 'created'`: u odmítnutého
     * dokladu se nic nezapsalo a číslo „3 řádky v OSS" by tvrdilo opak.
     *
     * ── Běh na pozadí ───────────────────────────────────────────────────────────────
     * Dávka z jiného systému má běžně tisíce dokladů, takže synchronní request na ni
     * nestačí. `$onProgress` a `$shouldCancel` jsou tu proto, aby tentýž kód uměl běžet
     * i jako job ({@see \MyInvoice\Service\Import\FileImportJobService}) — ne aby vznikla
     * druhá kopie importu pro pozadí.
     *
     * `$shouldCancel` vrátí `true` → smyčka se zastaví, ale **závěrečné kroky doběhnou**
     * (dorovnání číselných řad, přepočet statistik klientů). Právě tyhle dva kroky
     * chyběly, když uživateli běh spadl na timeout uprostřed: doklady byly založené,
     * ale seznam klientů ukazoval stará čísla a další faktura se vystavila s číslem,
     * které už v importu bylo. Report se vrátí za tu část, která se stihla.
     *
     * @param list<array{name:string, content:string}> $files Vstupní soubory (rozbalené ze ZIP / single).
     * @param (\Closure(int,int,array<string,int>):void)|null $onProgress fn(zpracováno, celkem, souhrn)
     * @param (\Closure():bool)|null $shouldCancel Vrátí `true`, má-li se běh ukončit předčasně.
     * @param 'draft'|'received' $purchaseStatus Stav zakládaných PŘIJATÝCH dokladů. Koncept
     *        se nezapočítává do nákladů, závazků ani do výkazů, takže dávková migrace
     *        z jiného systému má smysl jen jako `received` — jinak by účetní musela
     *        stovky dokladů otevřít jeden po druhém. Vydané strany se netýká.
     * @return array{summary:array<string,int>, results:list<array<string,mixed>>, cancelled:bool, not_processed:int}
     */
    public function importBundle(
        array $files,
        int $supplierId,
        int $userId,
        string $kind = 'auto',
        ?\Closure $onProgress = null,
        ?\Closure $shouldCancel = null,
        string $purchaseStatus = 'draft',
    ): array
    {
        if (!in_array($kind, ['auto', 'issued', 'purchase'], true)) {
            throw new \InvalidArgumentException("Neznámý kind '{$kind}', použij auto|issued|purchase.");
        }

        $supplierIc = $this->loadSupplierIc($supplierId);
        if ($supplierIc === null) {
            throw new \RuntimeException("Supplier #$supplierId nemá vyplněné IČO — import nemůže ověřit shodu.");
        }
        $tenantIc = preg_replace('/\D/', '', $supplierIc);

        // 1. Rozbalení ZIPů na ploché soubory.
        $flat = [];
        foreach ($files as $f) {
            // ISDOCX balíček (ZIP s .isdoc + PDF + manifest) → vytáhni vnitřní .isdoc.
            // Musí předcházet obecnému isZip(): jinak by se rozbalil jako bundle a
            // manifest.xml / PDF by dělaly šum. Čitelné PDF z balíčku si neseme dál a
            // u přijaté faktury ho archivujeme (issue #149) — stejně jako AI/dropzone
            // a inbox scan; vystavená (issued) cesta ho ignoruje.
            if ($this->isIsdocx($f['name'], $f['content'])) {
                $pkg = (new IsdocxExtractor())->unwrap($f['content']);
                if ($pkg !== null) {
                    $flat[] = [
                        'name'     => $f['name'] . '/' . $pkg['isdoc_name'],
                        'content'  => $pkg['isdoc'],
                        'pdf'      => $pkg['pdf'],
                        'pdf_name' => $pkg['pdf_name'] ?? preg_replace('/\.isdocx?$/i', '.pdf', basename($f['name'])),
                        // Zdrojový artefakt = ORIGINÁLNÍ .isdocx as-is (NEROZBALENÉ — zachová
                        // podpis ZIP obálky; vnitřní .isdoc XML jde do `content` jen pro parsing).
                        'source'        => $f['content'],
                        'source_name'   => basename($f['name']),
                        'source_format' => 'isdocx',
                    ];
                } else {
                    // Nepodařilo se rozbalit → necháme na parseRaw čitelnou chybu.
                    $flat[] = $f;
                }
                continue;
            }
            if ($this->isZip($f['name'], $f['content'])) {
                foreach ($this->unzip($f['content']) as $sub) {
                    $flat[] = ['name' => $f['name'] . '/' . $sub['name'], 'content' => $sub['content']];
                }
            } else {
                $flat[] = $f;
            }
        }

        // 2. Parsování všech souborů — žádná supplier_ic validation tady, ta se dělá při dispatch
        //    (rozdíl mezi issued vs purchase route). K položce si přibalíme i čitelné PDF
        //    (z ISDOCX balíčku, nebo přímo nahrané PDF/A-3 s embedded ISDOC), aby ho
        //    processPurchase() mohl zaarchivovat k vytvořené přijaté faktuře (issue #149).
        $parsed = [];
        foreach ($flat as $f) {
            $r = $this->parseRaw($f['name'], $f['content']);
            $entry = ['file' => $f['name']] + $r;
            // Zdrojový artefakt: ISDOCX nese originál .isdocx z rozbalení (flat entry) —
            // přebije `source` z parseRaw (ten viděl jen vnitřní .isdoc XML).
            if (isset($f['source'])) {
                $entry['source']        = $f['source'];
                $entry['source_name']   = $f['source_name'] ?? null;
                $entry['source_format'] = $f['source_format'] ?? null;
            }
            $pdf     = $f['pdf'] ?? null;
            $pdfName = $f['pdf_name'] ?? null;
            if ($pdf === null && $this->isPdf($f['name'], $f['content']) && str_starts_with($f['content'], '%PDF')) {
                $pdf     = $f['content'];
                $pdfName = basename($f['name']);
            }
            if ($pdf !== null) {
                $entry['pdf']      = $pdf;
                $entry['pdf_name'] = $pdfName;
            }
            $parsed[] = $entry;
        }

        // 3. Číselník sazeb členských států musí být k dispozici DŘÍV, než se zapíše
        //    první doklad — jinak by se odmítl každý řádek se sazbou vyšší než 0 %.
        $this->assertRateCodebookAvailable($parsed, $supplierId, $tenantIc, $kind);

        // 3b. Účetní období musí pokrývat data importovaných dokladů.
        //
        // Import sám do deníku NEÚČTUJE — doklady vznikají nezaúčtované a na chybějící
        // období narazí uživatel až ve chvíli, kdy u dokladu klikne „Zaúčtovat". U migrace
        // z jiného systému to znamená narazit na to měsíce po importu, u jednoho dokladu
        // z tisíce a bez souvislosti s tím, co dělal: doklady jsou roky staré, kdežto
        // účetnictví má firma aktivované od letoška. Rozsah dávky je jediné místo, kde se
        // to dá vyřešit dopředu a JEDNOU za běh místo u každého dokladu zvlášť.
        //
        // Zakládá se jen období, které NEEXISTUJE, a jen v podvojném účetnictví — obojí
        // hlídá provisioner, který je společný s účtováním a údržbovým CLI. Neúspěch
        // jednoho roku běh nezastaví; na ten zbytek narazí uživatel u „Zaúčtovat"
        // s hláškou, která ho pošle na Uzávěrku.
        $span = ImportDateSpan::of($parsed);
        if ($span !== null) {
            $this->periodProvisioner->ensureOpenPeriodsForRange(
                $supplierId,
                $span[0],
                $span[1],
                AccountingPeriodProvisioner::REASON_IMPORT,
                $userId ?: null,
            );
        }

        // 4. Cross-batch analýza emailů (jen pro issued cesta).
        $emailMap = $this->buildEmailMap($parsed);

        // 5. Dispatch + processing.
        $results = [];
        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $failed = 0;
        // Souhrnné čítače za celý běh — viz kontrakt v docblocku metody.
        $totals = [
            'oss_items'                       => 0,
            'oss_rate_type_unknown'           => 0,
            'oss_manual_review'               => 0,
            'oss_credit_notes_pending_period' => 0,
            'varsymbol_substituted'           => 0,
            'with_warnings'                   => 0,
        ];
        // Scopes vydaných faktur, jejichž číselné řady je po importu třeba dorovnat
        // (counter pozadu za historickými čísly). Klíč = typ|client|datum (idempotentní).
        $counterScopes = [];
        // Klienti/projekty dotčení VYDANÝMI doklady tohoto běhu — cache seznamu klientů
        // (`client_revenue_cache`/`project_revenue_cache`) se přepočte dávkově až na konci,
        // ne po každém dokladu (import umí mít stovky dokladů nad týmiž klienty).
        $touchedClientIds = [];
        $touchedProjectIds = [];
        // Doklady založené TÍMHLE během — dostanou značku dávky, aby šly hromadně
        // zahodit, když se zákazník s exportem ze zdrojového systému netrefil.
        $batchId = bin2hex(random_bytes(8));
        $createdInvoiceIds = [];
        $createdPurchaseIds = [];

        // Celkem dokladů, ne souborů: jeden Pohoda dataPack nese tisíce dokladů, takže
        // „1 z 1" by při běhu na pozadí neřeklo vůbec nic. Nečitelný soubor se počítá
        // jako jeden doklad — jinak by se jmenovatel po cestě měnil.
        $totalDocs = 0;
        foreach ($parsed as $entry) {
            $totalDocs += isset($entry['error']) ? 1 : count($entry['invoices']);
        }
        $processedDocs = 0;
        $cancelled = false;
        $report = function () use (&$processedDocs, $totalDocs, &$created, &$duplicates, &$skipped, &$failed, $onProgress): void {
            if ($onProgress === null) return;
            $onProgress($processedDocs, $totalDocs, [
                'created'    => $created,
                'duplicates' => $duplicates,
                'skipped'    => $skipped,
                'failed'     => $failed,
            ]);
        };
        $report();

        foreach ($parsed as $entry) {
            if ($cancelled) break;
            if (isset($entry['error'])) {
                $results[] = ['file' => $entry['file'], 'status' => 'failed', 'reason' => $entry['error']];
                $failed++;
                $processedDocs++;
                $report();
                continue;
            }
            foreach ($entry['invoices'] as $inv) {
                // Přerušení se ptáme MEZI doklady, ne uvnitř zápisu — rozepsaný doklad
                // se vždycky dopíše celý, aby po zrušení nezůstala hlavička bez řádků.
                if ($shouldCancel !== null && $shouldCancel()) {
                    $cancelled = true;
                    break;
                }
                // Číslo dokladu z původního systému patří do labelu vždy, když se liší od
                // varsymbolu: u dokladů s dosazeným VS (§ D9) je to jediný údaj, pod kterým
                // uživatel doklad ve zdrojové aplikaci najde.
                $label = $entry['file'] . ' / ' . ($inv['varsymbol'] ?? '?');
                $docNumber = trim((string) ($inv['document_number'] ?? ''));
                if ($docNumber !== '' && $docNumber !== (string) ($inv['varsymbol'] ?? '')) {
                    $label .= ' (doklad ' . $docNumber . ')';
                }
                if (isset($inv['__error'])) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $inv['__error']];
                    $failed++;
                    $processedDocs++;
                    $report();
                    continue;
                }

                // Auto-detekce per soubor: dle IČO buyer vs supplier
                $route = $this->detectRoute($inv, $tenantIc, $kind);

                try {
                    if ($route === 'issued') {
                        $r = $this->processOne($inv, $supplierId, $userId, $emailMap);
                    } elseif ($route === 'purchase') {
                        // Zdrojový artefakt: úsek JEN tohoto dokladu, má-li ho parser
                        // (Pohoda export nese celou agendu v jednom souboru). Teprve
                        // když ho nemá — ISDOC, kde soubor = jeden doklad — jdou k
                        // dokladu bajty celého souboru.
                        $docSource = isset($inv['__source_xml']) && is_string($inv['__source_xml'])
                            ? $inv['__source_xml']
                            : ($entry['source'] ?? null);
                        $docSourceName = isset($inv['__source_xml'])
                            ? self::fragmentName($entry['source_name'] ?? null, $inv)
                            : ($entry['source_name'] ?? null);
                        $r = $this->processPurchase(
                            $inv, $supplierId, $userId,
                            $entry['pdf'] ?? null, $entry['pdf_name'] ?? null,
                            $docSource, $docSourceName, $entry['source_format'] ?? null,
                            $purchaseStatus,
                        );
                    } else {
                        // 'reject' — ISDOC patří jinému plátci (neshoda IČO s tenantem)
                        $r = ['status' => 'failed', 'reason' => $route];
                    }
                    // Přidej kind do response pro UI
                    $r['kind'] = $route === 'issued' || $route === 'purchase' ? $route : null;
                    $results[] = ['file' => $label, 'status' => $r['status']] + $r;
                    $this->accumulate($totals, $r);
                    // Duplicita se do „vytvořeno" nepočítá. Doklad existoval už před
                    // importem, takže hlásit ho jako založený zkresluje jediné číslo,
                    // podle kterého se dávka vyhodnocuje — u opakovaně nahrané dávky
                    // by souhrn tvrdil, že vznikly stovky dokladů, a nešlo by poznat,
                    // že nevznikl ani jeden.
                    if (!empty($r['duplicate'])) {
                        $duplicates++;
                    } elseif ($r['status'] === 'created') {
                        $created++;
                        if ($route === 'purchase' && (int) ($r['purchase_invoice_id'] ?? 0) > 0) {
                            $createdPurchaseIds[] = (int) $r['purchase_invoice_id'];
                        }
                        if ($route === 'issued') {
                            if ((int) ($r['invoice_id'] ?? 0) > 0) {
                                $createdInvoiceIds[] = (int) $r['invoice_id'];
                            }
                            $type = (string) ($inv['invoice_type'] ?? 'invoice');
                            $cli  = (int) ($r['client_id'] ?? 0);
                            $cat  = (int) ($r['revenue_category_id'] ?? 0);
                            // Kanonický tvar i tady: klíč scope se jinak u téhož dne
                            // rozdvojí podle toho, jestli soubor psal vodicí nuly.
                            $date = OssItemDeriver::canonicalDate($inv['issue_date'] ?? null) ?? '';
                            $counterScopes[$type . '|' . $cli . '|' . $cat . '|' . $date] = [$type, $cli, $cat, $date];
                            if ($cli > 0) $touchedClientIds[$cli] = true;
                            $proj = (int) ($r['project_id'] ?? 0);
                            if ($proj > 0) $touchedProjectIds[$proj] = true;
                        }
                    }
                    elseif ($r['status'] === 'skipped') {
                        $skipped++;
                        // Doklad už v systému je — a právě proto může mít jeho klient
                        // zastaralou cache po běhu, který nedoběhl do konce. Opakované
                        // nahrání téže dávky je jediná cesta, kterou má uživatel po ruce,
                        // takže musí čísla dorovnat, ne je znovu nechat být.
                        if ($route === 'issued') {
                            $cli = (int) ($r['client_id'] ?? 0);
                            if ($cli > 0) $touchedClientIds[$cli] = true;
                            $proj = (int) ($r['project_id'] ?? 0);
                            if ($proj > 0) $touchedProjectIds[$proj] = true;
                        }
                    }
                    else $failed++;
                } catch (\Throwable $e) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $e->getMessage()];
                    $failed++;
                }
                $processedDocs++;
                $report();
            }
        }

        // Dorovnání číselných řad po importu vydaných faktur: counter se posune za
        // nejvyšší importované číslo odpovídající aktuálnímu template (jinak no-op).
        // Selhání syncu nesmí shodit import — jen se přeskočí (generátor je i tak
        // duplicate-aware při dalším vystavení).
        foreach ($counterScopes as [$type, $cli, $cat, $date]) {
            if (!in_array($type, ['invoice', 'proforma', 'credit_note'], true)) {
                continue;
            }
            try {
                $for = $date !== '' ? new \DateTimeImmutable($date) : null;
                $this->varsymbol->syncCounter($supplierId, $type, $for, $cli, $cat);
            } catch (\Throwable) {
                // ignore — best-effort dorovnání
            }
        }

        // Cache seznamu klientů (počet faktur/obrat/poslední aktivita) je JEN cache —
        // import už doběhl a nesmí kvůli jejímu selhání spadnout. Chyba se přesto
        // nesmí ztratit tiše: zaloguje se, ať je vidět, že cache zůstala stará.
        if ($touchedClientIds !== [] || $touchedProjectIds !== []) {
            try {
                $this->stats->recomputeMany(array_keys($touchedClientIds), array_keys($touchedProjectIds));
            } catch (\Throwable $e) {
                error_log('InvoiceImportService: recompute stats cache selhal: ' . $e->getMessage());
            }
        }

        // Značka dávky na doklady, které tenhle běh založil. Bez ní nejde „to, co jsem
        // právě naimportoval" znovu najít, a zákazník s vadnou dávkou nemá jak ji zahodit
        // ({@see ImportBatchEraser}). Zapisuje se AŽ TEĎ jedním UPDATE po tisícovkách,
        // ne u každého dokladu zvlášť.
        $this->stampBatch($supplierId, $batchId, $createdInvoiceIds, $createdPurchaseIds);

        // Zbytek dávky, ke kterému se běh po zrušení nedostal. Bez tohohle čísla vypadá
        // zrušený import v reportu jako hotový — jen s menším počtem dokladů.
        $remaining = max(0, $totalDocs - $processedDocs);

        return [
            'batch_id' => $batchId,
            'summary' => ['created' => $created, 'duplicates' => $duplicates, 'skipped' => $skipped, 'failed' => $failed] + $totals,
            'results' => $results,
            'cancelled' => $cancelled,
            'not_processed' => $cancelled ? $remaining : 0,
        ];
    }

    /**
     * Označí doklady založené tímhle během jednou značkou dávky.
     *
     * Jedním UPDATE po blocích, ne u každého dokladu při vzniku: import s tisíci doklady
     * by jinak zaplatil další zápis na každý z nich. Selhání označení nesmí shodit import,
     * který už proběhl — jen se zaloguje, protože bez značky nepůjde dávku hromadně
     * zahodit a uživatel by to jinak zjistil až ve chvíli, kdy to potřebuje.
     *
     * @param list<int> $invoiceIds
     * @param list<int> $purchaseIds
     */
    private function stampBatch(int $supplierId, string $batchId, array $invoiceIds, array $purchaseIds): void
    {
        foreach ([['invoices', $invoiceIds], ['purchase_invoices', $purchaseIds]] as [$table, $ids]) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $this->db->pdo()->prepare(
                        "UPDATE {$table} SET import_batch_id = ?
                          WHERE supplier_id = ? AND id IN ({$placeholders})"
                    )->execute(array_merge([$batchId, $supplierId], $chunk));
                } catch (\Throwable $e) {
                    error_log('InvoiceImportService: označení importní dávky selhalo: ' . $e->getMessage());
                    return;
                }
            }
        }
    }

    /**
     * Přičte jeden výsledek do souhrnu za běh.
     *
     * `varsymbol_substituted` a `with_warnings` se počítají i u přeskočených a odmítnutých
     * dokladů — právě tam jsou nejdůležitější (kolize po náhradě VS, § D9). OSS čítače
     * naopak jen u vytvořených: u odmítnutého dokladu se nic nezapsalo a číslo „3 řádky
     * v OSS" by tvrdilo opak.
     *
     * @param array<string,int>   $totals
     * @param array<string,mixed> $result
     */
    private function accumulate(array &$totals, array $result): void
    {
        if (!empty($result['varsymbol_substituted'])) {
            $totals['varsymbol_substituted']++;
        }
        if (!empty($result['warnings'])) {
            $totals['with_warnings']++;
        }
        if (($result['status'] ?? '') !== 'created') {
            return;
        }
        $totals['oss_items']             += (int) ($result['oss_items'] ?? 0);
        $totals['oss_rate_type_unknown'] += (int) ($result['oss_rate_type_unknown'] ?? 0);
        $totals['oss_manual_review']     += (int) ($result['oss_manual_review'] ?? 0);
        if ((int) ($result['oss_credit_note_pending_period'] ?? 0) > 0) {
            $totals['oss_credit_notes_pending_period']++;
        }
    }

    /**
     * Pre-flight číselníku sazeb členských států: chybí-li, běh skončí JEDNOU hláškou
     * dřív, než se zapíše první doklad.
     *
     * ── Proč to musí spadnout tady, a ne po dokladech ───────────────────────────────
     * Invariant proti úniku cizí daně pustí do tuzemské větve jen řádek, u kterého
     * číselník POZITIVNĚ potvrdil sazbu v zemi dodavatele k datu plnění
     * ({@see OssItemDeriver}). Bez tabulky `oss_member_state_rates` je odpověď „nevím"
     * u KAŽDÉ země, takže by se odmítla každá položka se sazbou vyšší než 0 % — u migrace
     * 1 670 dokladů tisíc a půl hlášek o jedné jediné příčině, mezi kterými se ta příčina
     * ztratí. Jedna hlasitá věta na začátku je nesrovnatelně lepší; a protože plánování
     * řádků běží před insertem hlavičky, po odstranění příčiny se týž balík naimportuje
     * znovu jako celek.
     *
     * ── Proč se to NEVÁŽE na `oss_enabled` ──────────────────────────────────────────
     * Odmítnutí není OSS větev. Řádek firmy s VYPNUTÝM OSS projde týmž rozhodnutím
     * s blokujícím důvodem `SupplierOssDisabled` a bez číselníku dopadne stejně, protože
     * jediná otázka, která ho může prohlásit za tuzemský, zůstane nezodpovězená. Kontrola
     * vázaná na přepínač OSS by tedy minula přesně ty instalace, kterých se to týká
     * nejvíc — ty, kde nedoběhly migrace.
     *
     * ── Proč to přesto nezdrží běžný import ─────────────────────────────────────────
     * Ptáme se jedině tehdy, když je v balíku aspoň jeden VYDANÝ doklad s nenulovou
     * sazbou — jen ten derivací prochází. Import přijatých faktur
     * ({@see IsdocToPurchaseInvoiceMapper}) ani balík samých osvobozených plnění se
     * nezablokuje. Cena odpovědi je jedno `hasTable()` (cachované schéma), nastavení
     * dodavatele (cachované derivací, které si ho tak jako tak vyžádá) a jeden agregační
     * dotaz nad číselníkem — všechno jednou za celý běh, ne za doklad.
     *
     * ── ČÍSELNÍK CHYBÍ vs. ČÍSELNÍK TOHLE OBDOBÍ NEPOKRÝVÁ ──────────────────────────
     * Zastavit celý běh smí jedině stav, který nejde spravit ničím jiným než zásahem do
     * instalace: chybějící tabulka (neproběhla migrace 1152) a číselník, který o zemi
     * DODAVATELE nevede vůbec nic. V obou případech je odpověď „nevím" u KAŽDÉHO řádku
     * a naimportovat by nešlo nic, takže jedna hlasitá věta je jediný užitečný výstup.
     *
     * Naproti tomu „sazby země dodavatele nepokrývají data v balíku" se ZÁMĚRNĚ neřeší
     * zastavením. Tenhle stav nastane i na instalaci s KOMPLETNĚ nasazenými migracemi —
     * stačí balík historických dokladů staršího data, než kam seed sahá — a uživatel pak
     * dostal návod spustit migrace, které dávno doběhly. Radit „spusťte migrate.php" tam,
     * kde to nic nezmění, je horší než mlčet: pošle uživatele hledat příčinu jinam, než
     * kde je, a přesně tuhle třídu zavádějící hlášky celá OSS vlna napravuje. Dotčené
     * doklady se odmítnou po jednom, hláškou od invariantu, která pojmenuje zemi i datum
     * plnění ({@see \MyInvoice\Service\Oss\OssItemDeriver}) — a doklady uvnitř pokrytého
     * období se naimportují místo toho, aby je zastavil balík jako celek.
     *
     * U druhé podmínky je vědomě přijatý jeden falešný poplach: balík, jehož KAŽDÝ řádek
     * by skončil v OSS, by se bez číselníkové znalosti země dodavatele naimportoval
     * (o tuzemsko se nikdo neptá). Zastavíme ho taky. Opačné pořadí — zeptat se, jestli
     * je řádek OSS, a teprve pak ověřit číselník — vyžaduje derivaci, tedy per doklad,
     * a tím se vrací přesně ta situace, kterou pre-flight řeší. Firma identifikovaná ve
     * státě, jehož sazby číselník nevede, navíc nemá jak spolehlivě zařadit ani jeden
     * tuzemský řádek; hláška proto říká, že si má sazby doplnit.
     *
     * @param list<array<string,mixed>> $parsed výstup {@see parseRaw()} za celý balík
     */
    private function assertRateCodebookAvailable(array $parsed, int $supplierId, string $tenantIc, string $kind): void
    {
        if ($this->datesNeedingRateCodebook($parsed, $tenantIc, $kind) === []) {
            return;
        }
        $problem = $this->rateCodebookProblem($supplierId);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }

    /**
     * Data plnění řádků, u kterých na odpovědi číselníku záleží — prázdné pole znamená
     * „neptej se". Datum se sbírá, i když se dneska rozhoduje jen podle toho, jestli je
     * seznam prázdný: číselník odpovídá K DATU, takže tady je jediné místo, kde se dá
     * levně zjistit, na která období se ho běh vůbec bude ptát.
     *
     * Započítá se řádek se skutečnou sazbou (invariant se ho ptá, jestli je tuzemská)
     * i řádek, kterému soubor sazbu neurčuje, ale nese SAZBOVOU ÚROVEŇ (`vat_rate_level`)
     * — tomu se procento z číselníku teprve dosadí ({@see itemRate()}), takže bez
     * číselníku se odmítne úplně stejně. Kdyby se nezapočítal, oněměla by brána přesně
     * u běžného tuzemského exportu z Pohody, který `inv:percentVAT` nepíše — tedy u toho
     * druhu souborů, kvůli kterému existuje.
     *
     * Nezapočítává se řádek s nulovou sazbou (invariant se na něj neuplatní), řádek bez
     * sazby i bez úrovně (vada dokladu s vlastní hláškou v {@see planItems()}, na kterou
     * by se číselníku stejně nedalo zeptat), doklad s nečitelným datem (odmítne ho
     * {@see normalizeDates()}) ani přijatá faktura, která derivací vůbec neprochází.
     *
     * @param  list<array<string,mixed>> $parsed
     * @return list<string> unikátní kanonická data 'Y-m-d'
     */
    private function datesNeedingRateCodebook(array $parsed, string $tenantIc, string $kind): array
    {
        $dates = [];
        foreach ($parsed as $entry) {
            if (isset($entry['error'])) {
                continue;
            }
            foreach ($entry['invoices'] ?? [] as $inv) {
                if (!is_array($inv) || isset($inv['__error'])) {
                    continue;
                }
                if ($this->detectRoute($inv, $tenantIc, $kind) !== 'issued') {
                    continue;
                }
                // Efektivní datum plnění = DUZP s fallbackem na datum vystavení, stejně
                // jako v {@see processOne()}; jiná hodnota by se ptala na jiné období.
                $date = OssItemDeriver::canonicalDate(
                    ($inv['tax_date'] ?? null) ?: ($inv['issue_date'] ?? null)
                );
                if ($date === null) {
                    continue;
                }
                foreach ($inv['items'] ?? [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $rate = $item['vat_rate'] ?? null;
                    $needsCodebook = $rate === null
                        ? self::rateLevel($item) !== null
                        : (float) $rate > OssItemDeriver::EPSILON;
                    if ($needsCodebook) {
                        $dates[$date] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($dates);
    }

    /**
     * Může číselník na otázku, na které stojí zařazení KAŽDÉ položky — „platí tahle sazba
     * v zemi dodavatele k datu plnění?" — vůbec odpovědět? Vrací hlášku pro uživatele,
     * nebo `null`, když ano.
     *
     * Dva stavy se rozlišují schválně, protože každý vede k jinému kroku: chybějící
     * tabulka znamená neproběhlou migraci, kdežto země dodavatele bez jediné sazby
     * znamená firmu identifikovanou ve státě, který seed nepokrývá — tam migrace nepomůže
     * a sazby si musí doplnit uživatel. Sloučení do jedné věty je táž chyba, kvůli které
     * zákazník z analýzy hledal chybějící PL/HU/SK v datech místo v neproběhlé migraci.
     *
     * Otázka „pokrývá číselník OBDOBÍ dokladů" se tu ZÁMĚRNĚ neklade — viz
     * {@see assertRateCodebookAvailable()}; je to vada jednotlivých dokladů, ne důvod
     * zastavit celý běh.
     *
     * Prázdná tabulka je z pohledu derivace totéž jako chybějící (na každou zemi odpoví
     * „nevím") a spadne sem druhou podmínkou. {@see OssRateCodebook} tyhle otázky nemá
     * a mít nemusí: jeho úkolem je odpovídat per země a datum, kdežto tady jde o to, jestli
     * ta odpověď vůbec může existovat.
     */
    private function rateCodebookProblem(int $supplierId): ?string
    {
        // Text i obě podmínky žijí v {@see OssItemPlanner::codebookProblem()}, protože
        // TOTÉŽ pre-flight potřebuje každý kanál, který zakládá vydanou fakturu
        // (iDoklad, Fakturoid, AI extrakce) — bez číselníku by se u něj odmítlo úplně
        // všechno, včetně běžné české faktury českému odběrateli. Dvě kopie by se
        // rozešly a jedna instalace by pak dostala radu, kterou druhá nedostane.
        // Liší se jedině NÁSLEDEK, a ten je parametrem.
        return $this->planner->codebookProblem($supplierId, 'Zatím se neuložilo nic, import opakujte.');
    }

    private static function fmtDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('j. n. Y');
        } catch (\Exception) {
            return $date;
        }
    }

    /** Procento bez zbytečných nul — shodně s hláškami `OssItemDeriver` a `VatRateResolver`. */
    private static function fmtPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',');
    }

    /**
     * Per-soubor detekce: kam (issued / purchase / reject) faktura patří.
     * `$kind='auto'` — směr ze souboru, jinak porovnání tenant IČO s supplier/customer.
     * `$kind='issued'|'purchase'` — vynutí směr, jen ověří že tenant je ve správné roli.
     *
     * SMĚR ZE SOUBORU MÁ PŘEDNOST před porovnáváním IČO, kdykoli ho soubor uvádí
     * ({@see PohodaXmlParser::DOCUMENT_TYPES} podle `<inv:invoiceType>`). Odhad podle IČO
     * je totiž u exportu z Pohody systematicky špatně: soubor má přijaté i vydané doklady
     * v témže tvaru a `root@ico` je u obou IČO exportující firmy, takže „jsme dodavatel"
     * vyjde jako pravda i u přijaté faktury. Při `kind=auto` se tak celá dávka přijatých
     * faktur TICHE založila jako vydané — obrácená strana evidence DPH i přiznání, a to
     * bez jediné chybové hlášky.
     *
     * Ohlášený nesoulad mezi zvoleným tabem a obsahem souboru se hlásí VLASTNÍ hláškou:
     * „nahrál jsi vydané faktury do přijatých" je jiná vada s jiným řešením než
     * „tenhle doklad patří jiné firmě", a splynutí obou do cross-tenant hlášky posílalo
     * uživatele hledat chybu v exportu, který byl v pořádku.
     *
     * @param array<string,mixed> $inv
     * @return string 'issued'|'purchase' nebo error message (reject reason)
     */
    private function detectRoute(array $inv, string $tenantIc, string $kind): string
    {
        $supplierIc = preg_replace('/\D/', '', (string) ($inv['supplier']['ic'] ?? '')) ?: '';
        $customerIc = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? '')) ?: '';

        $declared = $inv['direction'] ?? null;
        $declared = $declared === 'issued' || $declared === 'purchase' ? $declared : null;

        // `__supplier_ic` je IČO firmy, KTERÁ SOUBOR VYVEZLA (Pohoda `root@ico`) — u vydané
        // faktury je to dodavatel, u přijaté odběratel. Doplňuje se jen tam, kde stranu
        // neurčil sám doklad: starší Pohoda XML `<inv:myIdentity>` nepíše, a bez fallbacku
        // by přijatá faktura z takového souboru spadla na „patří jinému odběrateli"
        // s prázdným IČO, přestože soubor pochází z účetnictví tenanta.
        $fileOwnerIc = preg_replace('/\D/', '', (string) ($inv['__supplier_ic'] ?? '')) ?: '';
        if ($fileOwnerIc !== '') {
            if ($declared === 'purchase') {
                $customerIc = $customerIc !== '' ? $customerIc : $fileOwnerIc;
            } else {
                $supplierIc = $supplierIc !== '' ? $supplierIc : $fileOwnerIc;
            }
        }

        $weAreSupplier = $supplierIc !== '' && $supplierIc === $tenantIc;
        $weAreCustomer = $customerIc !== '' && $customerIc === $tenantIc;

        if ($kind === 'issued') {
            if ($declared === 'purchase') {
                return 'Tenhle doklad je v souboru vedený jako PŘIJATÝ (' . self::directionLabel($inv)
                    . '), ale importuje se mezi vydané faktury. Nahrajte ho v sekci přijatých faktur.';
            }
            if (!$weAreSupplier) return "Doklad patří jinému dodavateli (IČO dodavatele: {$supplierIc}, tenant: {$tenantIc}).";
            return 'issued';
        }
        if ($kind === 'purchase') {
            if ($declared === 'issued') {
                return 'Tenhle doklad je v souboru vedený jako VYDANÝ (' . self::directionLabel($inv)
                    . '), ale importuje se mezi přijaté faktury. Nahrajte ho v sekci vydaných faktur.';
            }
            if (!$weAreCustomer) return "Doklad patří jinému odběrateli (IČO odběratele: {$customerIc}, tenant: {$tenantIc}).";
            return 'purchase';
        }
        // auto — směr ze souboru je jistota, porovnání IČO jen dohad (viz docblock).
        if ($declared !== null) return $declared;
        if ($weAreSupplier)  return 'issued';
        if ($weAreCustomer)  return 'purchase';
        return "Auto-detekce: ani jeden IČO nematchuje tenant (supplier: {$supplierIc}, buyer: {$customerIc}, tenant: {$tenantIc}).";
    }

    /**
     * Název archivovaného úseku: jméno zdrojového souboru doplněné o doklad, ke kterému
     * úsek patří. Bez toho by se stovky úseků z jedné dávky jmenovaly stejně a v seznamu
     * příloh by nešlo poznat, který je který.
     *
     * @param array<string,mixed> $inv
     */
    private static function fragmentName(?string $sourceName, array $inv): string
    {
        $base = $sourceName !== null && $sourceName !== '' ? $sourceName : 'pohoda.xml';
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr($base, 0, -(strlen($ext) + 1)) : $base;
        $doc = trim((string) ($inv['document_number'] ?? '')) ?: trim((string) ($inv['varsymbol'] ?? ''));
        $doc = preg_replace('/[^A-Za-z0-9._-]+/', '-', $doc) ?? '';

        return $doc !== ''
            ? $stem . '-' . $doc . ($ext !== '' ? '.' . $ext : '')
            : $base;
    }

    /** Popisek dokladu do hlášky o špatně zvolené sekci — číslo dokladu, jinak varsymbol. */
    private static function directionLabel(array $inv): string
    {
        $number = trim((string) ($inv['document_number'] ?? ''));

        return $number !== '' ? $number : (string) ($inv['varsymbol'] ?? '?');
    }

    /**
     * Zpracuje fakturu jako purchase invoice (přijatá).
     * Reuse IsdocToPurchaseInvoiceMapper.
     *
     * @param array<string,mixed> $inv
     * @param string|null $pdfBytes Čitelné PDF k archivaci (ISDOCX balíček / nahrané PDF/A-3).
     * @return array<string,mixed>
     */
    private function processPurchase(
        array $inv,
        int $supplierId,
        int $userId,
        ?string $pdfBytes = null,
        ?string $pdfName = null,
        ?string $sourceBytes = null,
        ?string $sourceName = null,
        ?string $sourceFormat = null,
        string $purchaseStatus = 'draft',
    ): array {
        try {
            $r = $this->purchaseMapper->map($inv, $supplierId, $userId, $purchaseStatus);
            $duplicate = !empty($r['duplicate']);
            // Čitelné PDF (z ISDOCX balíčku nebo nahraného PDF/A-3 s embedded ISDOC)
            // zaarchivuj k faktuře pro náhled/stažení (issue #149) — stejná archivace
            // jako AI/dropzone a inbox scan (sdílený PurchaseInvoicePdfArchiver).
            if ($pdfBytes !== null && $pdfBytes !== '') {
                $this->pdfArchiver->archiveBytes(
                    (int) $r['purchase_invoice_id'],
                    $supplierId,
                    $pdfBytes,
                    $pdfName,
                );
            }
            // Strojový ZDROJOVÝ artefakt (ISDOC/ISDOCX/Pohoda XML) → source_* (write-once,
            // oddělený sources/ podstrom). Důkazní stopa s možností re-extrakce (issue #175).
            if ($sourceBytes !== null && $sourceBytes !== '' && $sourceFormat !== null) {
                $this->pdfArchiver->archiveSourceBytes(
                    (int) $r['purchase_invoice_id'],
                    $supplierId,
                    $sourceBytes,
                    $sourceName,
                    $sourceFormat,
                );
            }
            return [
                'status' => 'created',
                'reason' => $duplicate
                    ? 'přijatá faktura již existuje'
                    : (!empty($r['vendor_created']) ? 'vytvořen vendor + draft přijaté faktury' : 'draft přijaté faktury (vendor reuse)'),
                'purchase_invoice_id' => $r['purchase_invoice_id'],
                'vendor_id'           => $r['vendor_id'],
                'duplicate'           => $duplicate,
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Parse jediný soubor bez validation IČO (ta proběhne při dispatch dle kind).
     * Pro Pohoda XML (nemá AccountingSupplierParty struct v parser výstupu) doplníme
     * top-level supplier_ic do každé invoice jako `__supplier_ic` hint pro detectRoute.
     *
     * @return array{invoices:list<array<string,mixed>>}|array{error:string}
     */
    private function parseRaw(string $name, string $content): array
    {
        // Zdrojový artefakt = originál nahraných bajtů as-is (ISDOCX přebije volající).
        $sourceBytes  = $content;
        $sourceName   = basename($name);
        $sourceFormat = null;
        try {
            if ($this->isPdf($name, $content)) {
                $extracted = $this->pdfIsdoc->extract($content);
                if ($extracted === null) {
                    return ['error' => 'PDF neobsahuje ISDOC přílohu (PDF/A-3). Nahraj prosím .isdoc / .xml soubor, nebo PDF, který má ISDOC embed.'];
                }
                $content = $extracted;
                // Embedded ISDOC v PDF/A-3 → zdroj je vytažený XML (PDF samotné jde do pdf_*).
                $sourceBytes  = $extracted;
                $sourceName   = preg_replace('/\.pdf$/i', '.isdoc', basename($name)) ?: basename($name);
                $sourceFormat = 'isdoc';
            }
            $content = self::stripBom($content);
            $isIsdoc = self::looksLikeIsdoc($name, $content);
            $parsed = $isIsdoc
                ? $this->isdoc->parse($content)
                : $this->pohoda->parse($content);
            $sourceFormat ??= ($isIsdoc ? 'isdoc' : 'pohoda_xml');
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $topSupplierIc = (string) ($parsed['supplier_ic'] ?? '');
        $invoices = $parsed['invoices'] ?? [];
        // IČO firmy, která soubor vyvezla (Pohoda `root@ico`) — u vydané faktury dodavatel,
        // u přijaté odběratel. Slouží jen jako fallback pro stranu, kterou doklad sám
        // neuvádí; do které role patří, rozhoduje `detectRoute()` podle směru dokladu.
        foreach ($invoices as &$inv) {
            if (is_array($inv) && !isset($inv['__supplier_ic']) && $topSupplierIc !== '') {
                $inv['__supplier_ic'] = $topSupplierIc;
            }
        }
        unset($inv);

        return [
            'invoices'      => $invoices,
            'source'        => $sourceBytes,
            'source_name'   => $sourceName,
            'source_format' => $sourceFormat,
        ];
    }

    /**
     * Mapa IČO → e-maily odběratelů, kterou se při zakládání klienta doplní kontakt
     * chybějící na konkrétním dokladu.
     *
     * PŘIJATÉ doklady se přeskakují. Jejich `client` je totiž tenant sám (viz
     * {@see PohodaXmlParser}), takže by mapa spárovala IČO tenanta s jeho vlastní
     * e-mailovou adresou a ta by se pak dosadila do klientské karty, kdyby si tenant
     * někdy sám sebe zavedl jako odběratele. Do mapy patří výhradně protistrany.
     *
     * @param list<array<string,mixed>> $parsedFiles
     * @return array<string, array<string,bool>>  IČO → set emailů
     */
    private function buildEmailMap(array $parsedFiles): array
    {
        $map = [];
        foreach ($parsedFiles as $entry) {
            foreach ($entry['invoices'] ?? [] as $inv) {
                if (($inv['direction'] ?? null) === 'purchase') continue;
                $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
                $email = trim((string) ($inv['client']['email'] ?? ''));
                if ($ic === '' || $email === '') continue;
                $map[$ic][$email] = true;
            }
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     * @return array<string,mixed>
     */
    private function processOne(array $inv, int $supplierId, int $userId, array $emailMap): array
    {
        $varsymbol = (string) $inv['varsymbol'];

        // Charset whitelist — varsymbol importovaný z ISDOC/Pohoda XML protéká do
        // emailových šablon, PDF cache filenamů, ZIP entry names a CSV cell. Bez
        // omezení by ISDOC `<inv:symVar><a href=//evil></inv:symVar>` zaškodil
        // (HTML injection v emailu, CSV formula injection apod. — viz security
        // report @andrejtomci #3). Pravidlo žije JEN v PohodaXmlParser::VARSYMBOL_PATTERN:
        // parser podle něj pozná, kdy sáhnout po náhradě, a import podle něj validuje.
        // Dvě kopie regexu by se rozešly a parser by nabízel náhradu za hodnoty, které
        // import ve skutečnosti bere (nebo naopak).
        if (!PohodaXmlParser::isAcceptableVarsymbol($varsymbol)) {
            return [
                'status' => 'failed',
                'reason' => "Neplatný varsymbol '{$varsymbol}' (povoleno: A-Z, a-z, 0-9, _, -; max 20 znaků).",
            ];
        }

        // Náhrada VS musí být v reportu — doklad se pod symbolem z původního systému
        // (typicky interní GUID) už nenajde a uživatel to musí vědět dřív, než ho začne
        // hledat. Příznak emituje zatím jen PohodaXmlParser, proto `??` s odvozením ze
        // `varsymbol_source` (ISDOC cesta neemituje ani jedno).
        $vsSource = (string) ($inv['varsymbol_source'] ?? 'symVar');
        $vsSubstituted = (bool) ($inv['varsymbol_substituted'] ?? ($vsSource !== 'symVar'));
        $vsOriginal = trim((string) ($inv['varsymbol_original'] ?? ''));
        $docNumber = trim((string) ($inv['document_number'] ?? ''));
        $notes = [];
        if ($vsSubstituted) {
            // Riziko kolize nese KAŽDÁ náhrada, ne jen sanitizovaný tvar: číslo dokladu
            // z jednoho systému se běžně shoduje s variabilním symbolem z jiného.
            $notes[] = sprintf(
                '%s %s „%s" — pod původním symbolem doklad v systému nedohledáte a dosazený tvar '
                    . 'může kolidovat s jiným dokladem, zkontrolujte ho.',
                $vsOriginal !== '' ? "Variabilní symbol „{$vsOriginal}\" ze souboru nahrazen" : 'Variabilní symbol v souboru chyběl, doplněn',
                $vsSource === 'number_sanitized' ? 'upraveným tvarem čísla dokladu' : 'číslem dokladu',
                $varsymbol,
            );
        }

        $invoiceType = (string) ($inv['invoice_type'] ?? 'invoice');
        // Symbol tak, jak ho nesl SOUBOR (po případné náhradě GUIDu, ale PŘED náhradou
        // kvůli kolizi druhů dokladu níž). Pod ním se hledá opravovaný doklad —
        // viz {@see resolveCorrectedInvoiceId()}.
        $fileVarsymbol = $varsymbol;

        // Doklad BEZ JEDINÉ POLOŽKY se nezakládá — a to u KAŽDÉHO druhu vydaného dokladu.
        //
        // Součty vydané faktury se počítají výhradně z řádků ({@see InvoiceCalculator::recompute()}),
        // takže by vznikl doklad na nulu: v evidenci by vypadal jako naimportovaný, ale do
        // žádného výkazu by nepřispěl ničím. SuperFaktura přesně takhle vyváží opravné daňové
        // doklady (`<inv:invoiceDetail>` v nich chybí úplně), takže by migrace vyrobila
        // 99 prázdných dobropisů a uživatel by si myslel, že vratky v systému jsou.
        //
        // ODMÍTNUTÍ, ne založení s varováním: report je jednorázová stránka, kdežto doklad
        // zůstane. Varování by po jejím zavření nikdo nedohledal a nulový dobropis je pak
        // v evidenci nerozeznatelný od záměrně vystaveného. Odmítnutý doklad naopak
        // nezanechá nic k úklidu a po opravě zdroje se doimportuje. Je to zároveň táž
        // úroveň přísnosti, jakou má import na JEDINOU položku s nenapárovanou sazbou —
        // ta odmítá celý doklad, protože doklad s vynechaným řádkem má špatné součty.
        //
        // Přijaté faktury tenhle guard nemají: jedou vlastní cestou ({@see processPurchase()}),
        // vznikají jako `draft` k ruční kontrole (prázdný draft je pracovní stav, ne tichá
        // nula v účetnictví) a nahlášené vady se netýkají jich.
        if (($inv['items'] ?? []) === []) {
            return [
                'status' => 'failed',
                'reason' => sprintf(
                    'Doklad %s (%s) nemá v souboru jedinou položku — nezaložili jsme ho. Vznikl by '
                        . 'doklad na nulu, který v seznamu vypadá jako naimportovaný, ale do žádného '
                        . 'výkazu nepřispěje. Doplňte položky ve zdrojovém systému a doklad importujte '
                        . 'znovu, nebo ho zadejte ručně.',
                    $docNumber !== '' ? '„' . $docNumber . '"' : '„' . $varsymbol . '"',
                    self::invoiceTypeLabel($invoiceType),
                ),
                'notes' => $notes,
                'warnings' => [],
                'varsymbol_substituted' => $vsSubstituted,
                'document_number' => $docNumber !== '' ? $docNumber : null,
            ];
        }

        // Duplicita vs. KOLIZE po náhradě (§ D9). U dokladu s dosazeným VS není shoda
        // důkazem duplicity — pod stejným symbolem může být uložený úplně jiný doklad,
        // a ten by se tichým „již existuje" schoval mezi stovky legitimních přeskočení.
        $existing = $this->findInvoiceByVarsymbol($supplierId, $varsymbol);

        // KOLIZE DRUHŮ DOKLADU (§ D). Opravný daňový doklad běžně nese TÝŽ variabilní symbol
        // jako opravovaná faktura, aby vratka došla na stejný symbol. To NENÍ duplicita —
        // je to jiný doklad. Uložit ho pod tím symbolem ale nejde: unikátní index
        // `uq_inv_supplier_varsymbol (supplier_id, varsymbol)` druhý řádek odmítne, takže
        // by pouhé zúžení duplicitní kontroly na typ dokladu vyměnilo tiché přeskočení za
        // pád na duplicitním klíči. Symbol proto ODVODÍME z čísla dokladu — týmž
        // mechanismem, jakým se nahrazuje nepoužitelný GUID ze SuperFaktury
        // ({@see PohodaXmlParser::varsymbolFromDocumentNumber()}).
        //
        // Index se ZÁMĚRNĚ nerozšiřuje o `invoice_type`: variabilní symbol je při párování
        // plateb z banky jediný identifikátor úhrady a dva doklady pod jedním symbolem by
        // párování znejednoznačnily. To je zásah do účetního jádra a nemá vzniknout jako
        // vedlejší efekt migrace dat.
        if ($existing !== null && $existing['invoice_type'] !== $invoiceType) {
            $derived = PohodaXmlParser::varsymbolFromDocumentNumber($docNumber);
            // Odvození, které vrátí týž symbol, nic neřeší (VS už z čísla dokladu pochází).
            $usable = $derived !== null && $derived[0] !== $varsymbol;
            $taken  = $usable ? $this->findInvoiceByVarsymbol($supplierId, $derived[0]) : null;

            if ($taken !== null && $taken['invoice_type'] === $invoiceType) {
                // Odvozený symbol patří dokladu TÉHOŽ druhu — tenhle doklad tedy v systému
                // už je, jen pod svým odvozeným symbolem (typicky podruhé nahraný týž soubor).
                // To je obyčejná duplicita, ne kolize: přeskočí se dole stejnou hláškou jako
                // každá jiná, jinak by opakovaný import vyráběl varovné hlášky o kolizi.
                $varsymbol = $derived[0];
                $existing  = $taken;
            } elseif (!$usable || $taken !== null) {
                $reason = sprintf(
                    'Doklad %s se nenaimportoval: variabilní symbol „%s" už u tohohle dodavatele patří '
                        . 'dokladu #%d (%s), a tenhle doklad je %s. Dva doklady pod jedním symbolem '
                        . 'databáze neuloží a náhradní symbol %s. Zadejte dokladu jiný variabilní symbol '
                        . 'a importujte ho znovu.',
                    $docNumber !== '' ? '„' . $docNumber . '"' : 'ze souboru',
                    $varsymbol,
                    $existing['id'],
                    self::invoiceTypeLabel($existing['invoice_type']),
                    self::invoiceTypeLabel($invoiceType),
                    $taken !== null
                        ? 'z čísla dokladu („' . $derived[0] . '") už patří dokladu #' . $taken['id']
                        : 'z čísla dokladu odvodit nejde',
                );

                return [
                    'status' => 'skipped',
                    'reason' => $reason,
                    'invoice_id' => $existing['id'],
                    'notes' => $notes,
                    'warnings' => [$reason],
                    'varsymbol_substituted' => $vsSubstituted,
                    'document_number' => $docNumber !== '' ? $docNumber : null,
                ];
            } else {
                // Náhrada patří do reportu: doklad má v systému JINÝ symbol, než měl v souboru,
                // takže se pod tím původním nedohledá a úhrada se na něj sama nenapáruje.
                $notes[] = sprintf(
                    'Variabilní symbol „%s" ze souboru už u tohohle dodavatele patří dokladu #%d (%s), '
                        . 'a tenhle doklad je %s — dva doklady pod jedním symbolem databáze neuloží. '
                        . 'Uložili jsme ho pod symbolem „%s" odvozeným z čísla dokladu; pod symbolem '
                        . 'ze souboru ho v systému nedohledáte a platba se na něj sama nenapáruje.',
                    $varsymbol,
                    $existing['id'],
                    self::invoiceTypeLabel($existing['invoice_type']),
                    self::invoiceTypeLabel($invoiceType),
                    $derived[0],
                );
                $varsymbol     = $derived[0];
                $vsSource      = $derived[1];
                $vsSubstituted = true;
                $existing      = null;
            }
        }

        if ($existing !== null) {
            $reason = $vsSubstituted
                ? sprintf(
                    'Doklad %s se nenaimportoval: variabilní symbol „%s" jsme mu dosadili %s (%s), '
                        . 'a pod tímtéž symbolem už je v systému faktura #%d. NEMUSÍ jít o duplicitu — '
                        . 'ověřte fakturu #%d, a jde-li o jiný doklad, zadejte importovanému dokladu '
                        . 'jiný variabilní symbol.',
                    $docNumber !== '' ? '„' . $docNumber . '"' : 'ze souboru',
                    $varsymbol,
                    $vsSource === 'number_sanitized' ? 'z upraveného tvaru čísla dokladu' : 'z čísla dokladu',
                    $vsOriginal !== '' ? 'v souboru byl symbol „' . $vsOriginal . '"' : 'v souboru symbol chyběl',
                    $existing['id'],
                    $existing['id'],
                )
                : "Faktura s varsymbolem $varsymbol již existuje (#{$existing['id']}).";

            return [
                'status' => 'skipped',
                'reason' => $reason,
                'invoice_id' => $existing['id'],
                // Klient existujícího dokladu patří do přepočtu cache stejně jako klient
                // nově založeného. Právě tohle chybělo po utnutém importu: opakované
                // nahrání téže dávky doklady přeskočilo jako duplicitní, takže seznam
                // klientů zůstal na starých číslech a nešlo to napravit ničím z aplikace.
                'client_id' => $existing['client_id'] ?? 0,
                'project_id' => $existing['project_id'] ?? 0,
                // Poznámka o náhradě se dřív v téhle větvi zahodila, takže se uživatel
                // u přeskočeného dokladu o dosazeném symbolu vůbec nedozvěděl.
                'notes' => $notes,
                'warnings' => $vsSubstituted ? [$reason] : [],
                'varsymbol_substituted' => $vsSubstituted,
                'document_number' => $docNumber !== '' ? $docNumber : null,
            ];
        }

        // Data se normalizují na HRANICI — dřív, než se o dokladu cokoli rozhodne, a dřív
        // než {@see ClientResolver} sáhne na ARES/VIES a případně založí klienta. Doklad
        // s nečitelným datem se nesmí propadnout dál (viz {@see normalizeDates()}).
        $dates = self::normalizeDates($inv);
        if (isset($dates['error'])) {
            return [
                'status'   => 'failed',
                'reason'   => $dates['error'],
                'notes'    => $notes,
                'warnings' => [],
                'varsymbol_substituted' => $vsSubstituted,
                'document_number' => $docNumber !== '' ? $docNumber : null,
            ];
        }
        $issueDate = $dates['issue_date'];
        $taxDate   = $dates['tax_date'];
        $dueDate   = $dates['due_date'];

        // Client
        $clientResult = $this->clientResolver->resolve($inv['client'] ?? [], $supplierId);
        $clientId = $clientResult['id'];

        // Vazba opravného dokladu na opravovaný. U dobropisu s ODVOZENÝM symbolem je to
        // jediné, co ho s originálem spojuje: symbol ze souboru už nenese a číslo dokladu
        // z původního systému se nikam neukládá.
        $parentInvoiceId = $this->resolveCorrectedInvoiceId($inv, $supplierId, $clientId, $fileVarsymbol);
        $correctedRef = trim((string) ($inv['original_document_number'] ?? ''));
        if ($parentInvoiceId !== null) {
            $notes[] = sprintf('Opravný doklad jsme navázali na opravovaný doklad #%d.', $parentInvoiceId);
        } elseif ($correctedRef !== '' && $invoiceType === 'credit_note') {
            $notes[] = sprintf(
                'Doklad opravuje doklad č. „%s", ten ale u tohohle odběratele v systému není — '
                    . 'vazbu na opravovaný doklad doplňte ručně.',
                $correctedRef,
            );
        }

        // Project
        $projectId = $this->resolveProject($inv, $clientId, $emailMap);

        // Currency
        $currencyId = $this->currencyId($supplierId, (string) ($inv['currency'] ?? 'CZK'));

        // Status: due_date starší než 30 dní → paid, jinak sent.
        // Logika: importované doklady už klient prokazatelně dostal (jinak by je
        // nezaznamenali v původním systému), takže status='sent' je správnější
        // než 'issued'. Staré splatné → 'paid' (předpoklad zaplaceno).
        // sent_at = issue_date — nemáme přesnější údaj z původního systému,
        // den vystavení je nejlepší aproximace okamžiku odeslání.
        $threshold = (new \DateTimeImmutable('today'))->modify('-30 days');
        $isPaid = new \DateTimeImmutable($dueDate) < $threshold;
        $status = $isPaid ? 'paid' : 'sent';
        $paidAt = $isPaid ? ($taxDate ?? $issueDate) : null;
        $sentAt = $issueDate . ' 12:00:00';

        // Řádky se připraví PŘED hlavičkou — nenapárovaná sazba shodí celý doklad a
        // nesmí po sobě nechat fakturu bez položek (import nejede v transakci).
        // Efektivní datum plnění = DUZP s fallbackem na datum vystavení; deriver ani
        // resolver si ho nedomýšlejí, stejnou hodnotu používají i snapshoty níže.
        $reverseCharge = !empty($inv['reverse_charge']);
        $plan = $this->planItems(
            $inv['items'] ?? [],
            $supplierId,
            $clientId,
            $taxDate ?? $issueDate,
            $reverseCharge,
            // Klient tak, jak ho nese DOKLAD (§ D3) — uložený klient je až fallback,
            // protože ClientResolver ukládá neznámou zemi jako 'CZ' a derivace by z toho
            // udělala tuzemské plnění.
            $inv['client'] ?? null,
            (string) ($inv['invoice_type'] ?? 'invoice') === 'credit_note',
        );
        $header = $this->headerReport($inv);
        $notes = array_merge($notes, $header['notes'], $plan['notes']);
        $warnings = array_merge($header['warnings'], $plan['warnings']);
        if ($plan['errors'] !== []) {
            return [
                'status'   => 'failed',
                'reason'   => implode(' ', $plan['errors']),
                'warnings' => $warnings,
                'notes'    => $notes,
                'varsymbol_substituted' => $vsSubstituted,
                'document_number' => $docNumber !== '' ? $docNumber : null,
            ];
        }

        // Výchozí kategorie tržby — default zakázky > klienta (sdílený helper, stejná
        // logika jako createDraft / recurring), aby i importovaná faktura dostala kategorii.
        $revenueCategoryId = InvoiceRepository::resolveDefaultRevenueCategoryId($this->db->pdo(), $clientId, $projectId);

        // Insert invoice
        $pdo = $this->db->pdo();
        $sql = 'INSERT INTO invoices
            (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, project_id,
             issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
             reverse_charge, language,
             total_without_vat, total_vat, total_with_vat,
             status, sent_at, paid_at, revenue_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?)';

        $pdo->prepare($sql)->execute([
            $supplierId,
            $varsymbol,
            $invoiceType,
            $parentInvoiceId,
            $clientId,
            $projectId,
            $issueDate,
            $taxDate,
            $dueDate,
            $currencyId,
            $inv['exchange_rate'] !== null ? (float) $inv['exchange_rate'] : null,
            $inv['exchange_rate'] !== null ? $issueDate : null,
            $reverseCharge ? 1 : 0,
            'cs',
            $status,
            $sentAt,
            $paidAt,
            $revenueCategoryId,
            $userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        // Items
        $this->insertItems($invoiceId, $plan['rows']);

        // Recompute totals (z položek)
        $this->calculator->recompute($invoiceId);

        // Snapshoty z aktuálního supplier/client/bank; plátcovství DPH firmy k datu
        // importovaného dokladu (zpětně datovaná faktura dostane stav k svému datu).
        $snapshots = $this->snapshots->build(
            $clientId,
            $currencyId,
            $supplierId,
            null,
            $taxDate ?? $issueDate,
        );
        $pdo->prepare(
            'UPDATE invoices SET client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        return [
            'status' => 'created',
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            // Kategorie tržby je od migrace 1333 další osa scope číselné řady — bez ní
            // by se po importu dorovnal counter jiné řady, než ze které číslo pochází.
            'revenue_category_id' => $revenueCategoryId,
            'client_created' => $clientResult['created'],
            'project_id' => $projectId,
            'varsymbol' => $varsymbol,
            'imported_status' => $status,
            // Report nesmí být tichý: náhrada VS, nezjištěný typ OSS sazby, sazba použitá
            // mimo platnost, dobropis bez původního OSS období i přepočtené ceny jsou věci,
            // které má uživatel po importu zkontrolovat.
            'notes' => $notes,
            'warnings' => $warnings,
            'varsymbol_substituted' => $vsSubstituted,
            'document_number' => $docNumber !== '' ? $docNumber : null,
            'oss_items' => $plan['oss_items'],
            'oss_rate_type_unknown' => $plan['oss_rate_type_unknown'],
            'oss_manual_review' => $plan['oss_manual_review'],
            'oss_credit_note_pending_period' => $plan['oss_credit_note_pending_period'],
        ];
    }

    /**
     * Doklad daného variabilního symbolu u dodavatele — i s jeho DRUHEM.
     *
     * Druh se čte schválně: shoda symbolu u dokladu JINÉHO druhu není duplicita, ale
     * kolize (opravný daňový doklad nese symbol opravované faktury). Bez téhle informace
     * se obojí slilo do jediné hlášky „již existuje".
     *
     * @return array{id:int, invoice_type:string}|null
     */
    private function findInvoiceByVarsymbol(int $supplierId, string $varsymbol): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, invoice_type, client_id, project_id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : [
                'id'           => (int) $row['id'],
                'invoice_type' => (string) $row['invoice_type'],
                // Klient a zakázka existujícího dokladu — přeskočený doklad je díky nim
                // pořád důvod přepočítat cache ({@see importBundle()}).
                'client_id'    => (int) ($row['client_id'] ?? 0),
                'project_id'   => (int) ($row['project_id'] ?? 0),
            ];
    }

    private static function invoiceTypeLabel(string $type): string
    {
        return self::INVOICE_TYPE_LABELS[$type] ?? $type;
    }

    /**
     * Doklad, který tenhle OPRAVNÝ doklad opravuje — pro `invoices.parent_invoice_id`.
     *
     * Tutéž vazbu zakládá aplikace i sama ({@see \MyInvoice\Action\Invoice\CancelInvoiceAction}),
     * takže naimportovaný dobropis skončí ve stejném stavu jako vystavený a detail dokladu
     * i navazující dotazy na rodiče čtou týž sloupec. Bez vazby by dobropis s odvozeným
     * symbolem nebyl s originálem spojený vůbec ničím.
     *
     * Hledá se podle DVOU odkazů, protože každý pokrývá jinou situaci:
     *   1. číslo opravovaného dokladu ze souboru (`original_document_number`) — sedí tam,
     *      kde se ORIGINÁLU variabilní symbol dosadil z jeho čísla (GUID ze SuperFaktury);
     *   2. variabilní symbol, který tenhle doklad nesl v SOUBORU — sedí tam, kde opravný
     *      doklad úmyslně nese symbol opravované faktury (a právě proto se mu musel
     *      odvodit jiný).
     *
     * Guardy jsou dva a oba jsou podstatné: hledá se u TÉHOŽ odběratele (shoda symbolu
     * u cizího klienta je náhoda, ne vazba) a jen mezi doklady, které lze opravovat
     * (dobropis dobropisu by byl nesmysl). Špatně navázaný dobropis je horší než
     * nenavázaný — FK má `ON DELETE CASCADE`, takže smazání „rodiče" vezme i jeho.
     *
     * @param array<string,mixed> $inv
     */
    private function resolveCorrectedInvoiceId(array $inv, int $supplierId, int $clientId, string $fileVarsymbol): ?int
    {
        if ((string) ($inv['invoice_type'] ?? 'invoice') !== 'credit_note') {
            return null;
        }

        $refs = [];
        foreach ([(string) ($inv['original_document_number'] ?? ''), $fileVarsymbol] as $ref) {
            $ref = trim($ref);
            if ($ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }
        if ($refs === []) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM invoices
              WHERE supplier_id = ? AND client_id = ? AND varsymbol = ?
                AND invoice_type IN ('invoice', 'tax_document')
           ORDER BY id LIMIT 1"
        );
        foreach ($refs as $ref) {
            $stmt->execute([$supplierId, $clientId, $ref]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     */
    private function resolveProject(array $inv, int $clientId, array $emailMap): ?int
    {
        $projectNumber = trim((string) ($inv['project_number'] ?? ''));
        if ($projectNumber !== '') {
            return $this->findOrCreateProjectByNumber($clientId, $projectNumber);
        }

        // Multi-email rule
        $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
        $email = trim((string) ($inv['client']['email'] ?? ''));
        if ($ic !== '' && $email !== '' && count($emailMap[$ic] ?? []) > 1) {
            $companyName = (string) ($inv['client']['company_name'] ?? '');
            return $this->findOrCreateProjectByEmail($clientId, $companyName, $email);
        }

        return null;
    }

    private function findOrCreateProjectByNumber(int $clientId, string $projectNumber): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND project_number = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $projectNumber]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $projectNumber,
            'project_number'   => $projectNumber,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    private function findOrCreateProjectByEmail(int $clientId, string $companyName, string $email): int
    {
        $name = trim($companyName . ' – ' . $email);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $name,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    /**
     * Data dokladu na kanonický tvar 'Y-m-d' — na HRANICI, dřív než se o dokladu cokoli
     * rozhodne a než se cokoli zapíše.
     *
     * ── Proč to nestačí nechat na databázi ──────────────────────────────────────────
     * Parser datum jen ořeže: `<inv:date>2096-5-15</inv:date>` projde beze změny a MariaDB
     * ho do sloupce `DATE` uloží taky. Jenže po cestě se datum POROVNÁVÁ JAKO ŘETĚZEC —
     * platnost sazby v číselníku členských států i platnost registrace do OSS
     * ({@see OssItemDeriver}) — a tam '2096-5-15' odpovídá na jinou otázku, než jaká byla
     * položena. Přesně tudy prošel druhý reprodukovaný únik cizí daně na ř. 1 českého
     * přiznání: nekanonické datum obešlo invariant ještě dřív, než se stihl vyhodnotit.
     *
     * Kanonizace proto sdílí SSOT s derivací ({@see OssItemDeriver::canonicalDate()}).
     * Vlastní kopie regexu by se rozešla a doklad by prošel s datem, na které deriver
     * odpoví jinak než zápis do databáze — tedy s obnovenou původní chybou.
     *
     * ── Co je vada dokladu a co legitimní prázdno ───────────────────────────────────
     * Nečitelné datum (jiný formát, neexistující den) je vada DOKLADU: doklad se odmítne
     * s hláškou, protože tiché propadnutí dál je horší než odmítnutí, které je v reportu
     * jmenovitě a po opravě souboru se doklad doimportuje.
     *
     * Prázdné DUZP je naproti tomu legitimní stav — zálohová faktura (proforma) daňový
     * doklad není a `invoices.tax_date` je kvůli tomu nullable; oba parsery ho v tom
     * případě posílají jako `null` a efektivní datum plnění se dál odvozuje od data
     * vystavení. Prázdná splatnost se dorovnává datem vystavení: obojí parser (`inv:dateDue`
     * i `PaymentDueDate`) to dělá už sám, tady je to pojistka pro ostatní vstupy — a hlavně
     * lepší než prázdný řetězec, který by v `DATE` sloupci skončil chybou z databáze.
     *
     * @param  array<string,mixed> $inv
     * @return array{issue_date:string, tax_date:?string, due_date:string}|array{error:string}
     */
    private static function normalizeDates(array $inv): array
    {
        $issueDate = OssItemDeriver::canonicalDate($inv['issue_date'] ?? null);
        if ($issueDate === null) {
            return ['error' => self::unusableDateMessage('datum vystavení', $inv['issue_date'] ?? null)];
        }

        $taxDate = null;
        $taxRaw = trim((string) ($inv['tax_date'] ?? ''));
        if ($taxRaw !== '') {
            $taxDate = OssItemDeriver::canonicalDate($taxRaw);
            if ($taxDate === null) {
                return ['error' => self::unusableDateMessage('datum uskutečnění zdanitelného plnění', $taxRaw)];
            }
        }

        $dueRaw = trim((string) ($inv['due_date'] ?? ''));
        if ($dueRaw === '') {
            return ['issue_date' => $issueDate, 'tax_date' => $taxDate, 'due_date' => $issueDate];
        }
        $dueDate = OssItemDeriver::canonicalDate($dueRaw);
        if ($dueDate === null) {
            return ['error' => self::unusableDateMessage('datum splatnosti', $dueRaw)];
        }

        return ['issue_date' => $issueDate, 'tax_date' => $taxDate, 'due_date' => $dueDate];
    }

    /**
     * Hláška o nečitelném datu. Uvádí hodnotu ZE SOUBORU, aby ji uživatel v souboru našel —
     * u 1 670 dokladů je „neplatné datum" bez hodnoty k ničemu. Zkrácená a bez zalomení,
     * protože report se vypisuje jako jeden řádek.
     */
    private static function unusableDateMessage(string $what, mixed $raw): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', (string) ($raw ?? '')));

        return sprintf(
            'Doklad má nepoužitelné %s%s — očekává se tvar RRRR-MM-DD. Opravte ho v souboru a import '
                . 'opakujte; datum nelze dosadit odhadem, protože z něj plyne zdaňovací období i to, '
                . 'která sazba k tomu dni platila.',
            $what,
            $value !== '' ? sprintf(' („%s")', mb_substr($value, 0, 40)) : ' (v souboru chybí)',
        );
    }

    /**
     * Hlášky, které patří CELÉMU dokladu, ne jednotlivému řádku — tvar výstupu parseru
     * se u těchhle věcí liší od toho, co se nakonec uloží, a bez zmínky v reportu
     * to vypadá jako chyba importu.
     *
     * Kromě toho sem patří vady, kde si SOUBOR ODPORUJE SÁM SE SEBOU (`file_issues`):
     * položky deklarují jiné sazby nebo jiné základy než rekapitulace v témž souboru.
     * Importují se čísla z POLOŽEK, takže je to varování, ne chyba — ale tichý průchod
     * sebeodporujícího souboru je vada sama o sobě: přesně tak vypadá doklad, u kterého
     * položka nese jen `high` a rekapitulace k témuž základu 23% daň.
     *
     * ISDOC cesta část klíčů neemituje, proto všude `??`.
     *
     * @param  array<string,mixed> $inv
     * @return array{notes:list<string>, warnings:list<string>}
     */
    private function headerReport(array $inv): array
    {
        $notes = [];
        $warnings = [];

        foreach ($inv['file_issues'] ?? [] as $issue) {
            $issue = trim((string) $issue);
            if ($issue !== '') {
                $warnings[] = $issue;
            }
        }

        $currency = strtoupper(trim((string) ($inv['currency'] ?? 'CZK')));
        if ($currency !== '' && $currency !== 'CZK' && ($inv['exchange_rate'] ?? null) === null) {
            // Parser nově vrací `null` i tam, kde dřív propadla 0.0 (nečitelný kurz).
            // Doklad se uloží bez kurzu a přepočty na něj narazí až v účetnictví.
            $warnings[] = sprintf(
                'Doklad je v měně %s, ale v souboru není použitelný kurz — doplňte ho na dokladu, '
                    . 'jinak se přepočet do Kč neudělá.',
                $currency,
            );
        }

        $rateAmount = $inv['exchange_rate_amount'] ?? null;
        if ($rateAmount !== null && (int) $rateAmount !== 1) {
            $notes[] = sprintf(
                'Kurz byl v souboru uveden na %d jednotek měny; uložili jsme ho přepočtený na jednu.',
                (int) $rateAmount,
            );
        }

        foreach ($inv['items'] ?? [] as $item) {
            if (!empty($item['prices_included_vat'])) {
                $notes[] = 'Doklad byl v souboru v cenách VČETNĚ DPH — jednotkové ceny jsme přepočetli '
                    . 'na základ daně, takže se liší od částek na původním dokladu. Celkové částky sedí.';
                break;
            }
        }

        if (($inv['items_source'] ?? 'detail') === 'summary_recap') {
            $notes[] = 'Doklad neměl v souboru rozpis položek — řádky jsme dopočetli z jeho '
                . 'rekapitulace DPH, jeden na každou sazbu. Základy i daň odpovídají původnímu '
                . 'dokladu, popis řádku je převzatý z textu hlavičky.';
        }

        return ['notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * Připraví řádky dokladu — sazbu, klasifikaci i OSS parametry — JEŠTĚ NEŽ vznikne
     * hlavička faktury, aby nenapárovaná sazba nenechala v databázi doklad bez položek.
     *
     * Zásadní je invariant párování země: sazba se hledá v TÉŽE zemi, kterou má řádek
     * v ostatních polích. OSS řádek → země spotřeby a žádný tuzemský kód plnění; ne-OSS
     * řádek → země DODAVATELE ({@see OssItemDeriver::domesticCountry()}), tedy táž země,
     * ze které si deriver bere odpověď „zná tuhle sazbu tuzemsko". Vlastní konstanta
     * `'CZ'` na téhle straně obě strany rozešla u dodavatele identifikovaného mimo ČR.
     *
     * Že polských 23 % neskončí na ř. 1 přiznání, drží `oss_applicable` — NE klasifikační
     * kód (viz docblock třídy); klasifikace jen doplňuje správný řádek výkazu tam, kde
     * plnění do přiznání skutečně patří.
     *
     * `oss_original_period` se u dobropisů ZÁMĚRNĚ nedoplňuje: import nemá odkud spolehlivě
     * zjistit, do kterého OSS období patřil původní doklad (odkaz na něj v Pohoda XML ani
     * v ISDOCu nemusí být a odhad „předchozí kvartál" by opravu vykázal do cizího období).
     * Místo hádání jde každý takový řádek do reportu.
     *
     * ── Odmítnutá položka shodí CELÝ doklad, ne jen sebe ────────────────────────────
     * Odmítnutí položky ({@see \MyInvoice\Service\Oss\OssItemDecision::isRejected()}) padá
     * do téhož `errors` bucketu jako nenapárovaná sazba a má tentýž následek: doklad se
     * nevytvoří vůbec. Vynechat jen vadný řádek je u migrace 1 670 dokladů HORŠÍ varianta —
     * doklad by v seznamu vypadal kompletně, ale měl by nižší součty, a chybějící řádek
     * by byl zrovna ten se zahraniční sazbou, kvůli kterému celá tahle vlna existuje;
     * uživatel by ho našel jedině porovnáním všech dokladů se zdrojem. Odmítnutý doklad
     * je naproti tomu v reportu jmenovitě, nezanechá v databázi nic (plán běží PŘED
     * insertem hlavičky) a po odstranění příčiny se týž balík naimportuje znovu —
     * duplicita se hlídá přes varsymbol, takže se doplní právě jen odmítnuté doklady.
     *
     * Hláška nese prefix `Položka č. N`, aby uživatel věděl, KTERÝ řádek dokladem pohnul.
     * Do téhož bucketu padá i položka, u které se sazbu nepodařilo určit ANI ZE SOUBORU,
     * ANI Z ČÍSELNÍKU ({@see itemRate()}): dosazená nula by z ní udělala osvobozené plnění,
     * které invariant proti úniku neprověřuje, takže by cizí daň zmizela úplně místo aby
     * skončila ve špatné zemi.
     *
     * @param  list<array<string,mixed>> $items
     * @param  ?array<string,mixed>      $documentClient klient z importovaného dokladu (§ D3)
     * @return array{rows:list<array<string,mixed>>, notes:list<string>,
     *               warnings:list<string>, errors:list<string>, oss_items:int,
     *               oss_rate_type_unknown:int, oss_manual_review:int,
     *               oss_credit_note_pending_period:int}
     */
    private function planItems(
        array $items,
        int $supplierId,
        int $clientId,
        string $taxDate,
        bool $reverseCharge,
        ?array $documentClient = null,
        bool $isCreditNote = false,
    ): array {
        $plan = [
            'rows' => [], 'notes' => [], 'warnings' => [], 'errors' => [],
            'oss_items' => 0, 'oss_rate_type_unknown' => 0, 'oss_manual_review' => 0,
            'oss_credit_note_pending_period' => 0,
        ];
        if (empty($items)) {
            return $plan;
        }

        $client = $this->ossDeriver->clientContext($clientId, $documentClient);
        $domestic = $this->ossDeriver->domesticCountry($supplierId);
        $seen = [];
        $signSum = 0.0;

        foreach (array_values($items) as $i => $item) {
            $label = 'Položka č. ' . ($i + 1);
            // Sazba, kterou nedá ani soubor, ani číselník, je tvrdá chyba dokladu.
            // Přetypování `null` na 0.0 by z ní udělalo osvobozené plnění — a nulová sazba
            // je z invariantu proti úniku cizí daně vyňatá (bez daně není co unikat),
            // takže by zahraniční plnění prošlo druhou cestou: tiše, bez varování
            // a bez daně vůbec.
            $rate = $this->itemRate($item, $client, $domestic, $taxDate);
            if ($rate === null) {
                self::addOnce($plan['errors'], $seen, $label,
                    $this->unresolvedRateMessage($item, $client, $domestic, $taxDate));
                continue;
            }
            $unit = (string) ($item['unit'] ?? 'ks');
            $quantity = (float) ($item['quantity'] ?? 1);
            // Netto cena z parseru je PROVIZORNÍ všude, kde byl doklad v cenách s DPH
            // a sazba se určila až tady — koeficient, kterým parser dělil, byl jen odhad.
            $unitPrice = self::netUnitPrice($item, $rate);
            $signSum += $quantity * $unitPrice;
            if (($item['vat_rate'] ?? null) === null) {
                // Dosazená sazba do reportu patří: soubor procento neuvedl, takže je to
                // jediné číslo na dokladu, které nepochází ze zdrojového systému.
                self::addOnce($plan['notes'], $seen, $label, sprintf(
                    'Soubor u položky neuvádí procento DPH (inv:percentVAT) ani rekapitulaci, ze které by '
                        . 'šlo dopočítat — nese jen sazbovou úroveň „%s". Dosadili jsme %s %% podle číselníku '
                        . 'sazeb členských států pro zemi dodavatele (%s) k %s; zkontrolujte, že to je sazba, '
                        . 'kterou doklad skutečně nesl.',
                    self::rateLevelLabel($item),
                    self::fmtPercent($rate),
                    $domestic,
                    self::fmtDate($taxDate),
                ));
            }

            $decision = $this->ossDeriver->derive($supplierId, $client, $rate, $unit, $taxDate, $reverseCharge);
            $report = $decision->toReport();
            if ($report['rejected']) {
                // Invariant proti úniku cizí daně: sazba v zemi dodavatele podle číselníku
                // členských států neplatí, ale řádek nemůže být ani OSS. Hláška už nese
                // návod, co doplnit — musí se ošetřit DŘÍV, než se sáhne po sloupcích:
                // `OssItemDecision::toItemColumns()` na odmítnuté položce schválně vyhodí
                // výjimku, aby se odmítnutí nedalo přehlédnout.
                self::addOnce($plan['errors'], $seen, $label, (string) $report['rejection_message']);
                continue;
            }
            // Řádek k ručnímu posouzení JE zároveň OSS řádek (nejednoznačnost se řeší ve
            // prospěch OSS), takže se započítá do obou čítačů — `oss_items` níž se s tímhle
            // nevylučuje.
            if ($report['needs_manual_review']) {
                $plan['oss_manual_review']++;
            }

            if ($decision->applicable) {
                // Sazba se hledá ve státě SPOTŘEBY. Nenajde-li se, je to tvrdá chyba
                // dokladu s návodem — číselník `vat_rates` je globální tabulka bez
                // supplier_id, takže z importu se do něj nesmí zapisovat.
                $match = $this->vatRateResolver->resolve((string) $decision->consumerCountry, $rate, $taxDate);
                // OSS plnění se do tuzemského přiznání ani do KH nevykazuje, takže
                // tuzemský kód by byl mrtvá metadata — a v okamžiku, kdy někdo
                // oss_applicable zhasne, by se řádek objevil na ř. 1.
                $code = null;
                $plan['oss_items']++;
                if ($decision->rateType === null) {
                    $plan['oss_rate_type_unknown']++;
                }
                if ($isCreditNote) {
                    $plan['oss_credit_note_pending_period']++;
                }
            } else {
                $match = $this->vatRateResolver->resolve($domestic, $rate, $taxDate);
                $code = $item['vat_classification_code']
                    ?? InvoiceRepository::defaultSaleClassificationCode(
                        $rate,
                        $reverseCharge,
                        $this->classificationCountry($client, $rate),
                        $unit !== '' ? $unit : null,
                        // Základní sazba § 47 ZDPH pro ROK DUZP importovaného dokladu, ne
                        // pro dnešek: import běžně nese doklady z minulého období a natvrdo
                        // 21 by po změně sazby přeřadil plnění na špatný řádek přiznání.
                        $this->taxConstants->vatRateStandard(
                            (int) substr((string) ($taxDate ?: date('Y-m-d')), 0, 4)
                        ),
                    );
            }

            foreach ($report['warnings'] as $warning) {
                self::addOnce($plan['warnings'], $seen, $label, $warning);
            }

            if (!$match->found()) {
                // Tvrdá chyba CELÉHO dokladu, ne jen položky: doklad s vynechaným
                // řádkem má špatné součty. Žádné „nejbližší" ani nulová sazba.
                self::addOnce($plan['errors'], $seen, $label, $match->message);
                continue;
            }
            if ($match->message !== '') {
                self::addOnce($plan['warnings'], $seen, $label, $match->message);
            }
            if ($decision->applicable) {
                self::addOnce($plan['notes'], $seen, $label, sprintf(
                    'Řádek zařazen do OSS (stát spotřeby %s, %s, %s) — do českého přiznání k DPH nejde',
                    (string) $decision->consumerCountry,
                    $decision->rateType !== null
                        ? 'typ sazby ' . $decision->rateType
                        : 'typ sazby nezjištěn, doplňte ho',
                    $decision->supplyType === 'goods' ? 'zboží' : 'služba',
                ));
                if ($isCreditNote) {
                    // Bez původního období se oprava vykáže do BĚŽNÉHO kvartálu, ne do toho,
                    // do kterého patří — v OSS podání jsou to dva různé oddíly (VetaO).
                    self::addOnce($plan['warnings'], $seen, $label,
                        'OSS řádek na dobropisu vyžaduje doplnění PŮVODNÍHO OSS období (RRRRQn) — '
                            . 'import ho nedoplňuje a bez něj se oprava vykáže do běžného období');
                }
            }

            $plan['rows'][] = [
                'description'             => (string) ($item['description'] ?? ''),
                'quantity'                => $quantity,
                'unit'                    => $unit,
                'unit_price_without_vat'  => $unitPrice,
                'vat_rate_id'             => (int) $match->id,
                // Snapshot z DB, ne z dokladu: výkazy počítají ze snapshotu, takže musí
                // odpovídat sazbě, na kterou je řádek navázaný (liší se max. o toleranci).
                'vat_rate_snapshot'       => $match->ratePercent ?? $rate,
                'vat_classification_code' => $code !== null ? (string) $code : null,
                'oss'                     => $decision->toItemColumns(),
            ];
        }

        // Soudržnost DOKLADU se dá zkontrolovat až tady — per položku je neviditelná.
        $this->flagContradictoryDocument($plan, $domestic);

        if ($isCreditNote && $signSum > self::CREDIT_SIGN_EPSILON) {
            $plan['rows'] = self::negateCreditNoteRows($plan['rows']);
            $plan['warnings'][] = 'Dobropis měl v souboru KLADNÉ částky — otočili jsme u položek '
                . 'znaménko, aby doklad daň SNÍŽIL. Systém vede dobropis jako doklad se zápornými '
                . 'řádky a žádný výkaz znaménko podle typu dokladu neotáčí, takže ponechané kladné '
                . 'částky by daň naopak zvýšily. Zkontrolujte na dokladu částky i celkový součet.';
        }

        return $plan;
    }

    /**
     * SOUDRŽNOST DOKLADU: leží týž doklad zároveň v OSS podání i v tuzemském přiznání?
     *
     * Samotné pravidlo (kdy je doklad rozporný, které řádky se označí a jak zní hláška)
     * bydlí v {@see OssDocumentCoherence} — stejný rozpor totiž umí vyrobit i editor
     * a API, ne jen import. Tady zůstává jen napojení na PLÁN položek: rozpor je
     * vlastnost DOKLADU a vidět je až tam, kde jsou pohromadě všechny jeho položky,
     * to je konec smyčky {@see planItems()}. Volající ({@see processOne()}) už dostane
     * jen hotový plán a per-doklad čítače, takže výš by se rozpor musel rekonstruovat
     * ze součtů — a mezi „dvě položky, každá jinam" a „dva doklady po jedné položce"
     * by se z čísel nedalo rozhodnout.
     *
     * @param array{rows:list<array<string,mixed>>, warnings:list<string>,
     *              oss_manual_review:int, ...} $plan
     */
    private function flagContradictoryDocument(array &$plan, string $domestic): void
    {
        $contradiction = OssDocumentCoherence::detect(array_map(
            static fn (array $row): array => [
                'applicable' => (int) ($row['oss']['oss_applicable'] ?? 0) === 1,
                // OSS řádek je vždy zdaněný (nulovou sazbu deriver do OSS nepustí).
                'country' => (string) ($row['oss']['oss_consumer_country'] ?? ''),
                'rate' => (float) $row['vat_rate_snapshot'],
            ],
            $plan['rows'],
        ));

        if ($contradiction === null) {
            return;
        }

        $plan['warnings'][] = $contradiction->warning($domestic);

        foreach ($contradiction->affectedKeys as $index) {
            if ((int) ($plan['rows'][$index]['oss']['oss_needs_manual_review'] ?? 0) === 1) {
                continue;
            }
            $plan['rows'][$index]['oss']['oss_needs_manual_review'] = 1;
            // Čítač reportu musí sedět s tím, co se skutečně zapíše — jinak by uživatel
            // hledal v datech míň řádků, než kolik jich je označených.
            $plan['oss_manual_review']++;
        }
    }

    /**
     * Sazba položky v procentech, nebo `null`, když ji nelze určit.
     *
     * Poslední článek řetězu zdrojů pravdy, jehož první tři kroky jsou v parserech
     * (`inv:percentVAT` → `rateVAT/@value` → dopočet z rekapitulace téhož souboru). Tady
     * zbývá jediný krok, na který parser NEMÁ VSTUPY: překlad ČESKÉ SAZBOVÉ ÚROVNĚ
     * (`vat_rate_level`) na procento podle číselníku sazeb členských států.
     *
     * ── Dvě podmínky, bez kterých by to byl dohad ───────────────────────────────────
     * 1. ODBĚRATEL MUSÍ BÝT TUZEMSKÝ. Enum je jen česká sazbová úroveň a Pohoda schema
     *    zahraniční sazby nezná — zákazníkův exportér proto polských 23 % posílá jako
     *    `historyHigh`/`high`. Dosadit tam sazbu země dodavatele znamená prohlásit cizí
     *    daň za tuzemskou; číselník ji pak POZITIVNĚ potvrdí, invariant řádek pustí jako
     *    tuzemský a daň skončí na ř. 1 přiznání. Přesně tenhle únik měřila review.
     *    Neznámá země odběratele tuzemsko NENÍ: `ClientResolver` neznámou zemi ukládá
     *    jako 'CZ', takže opačný výklad tentýž únik obnoví jinou cestou.
     * 2. PROCENTO SE BERE Z ČÍSELNÍKU K DATU PLNĚNÍ, ne z konstanty. Sazby se v čase mění
     *    (v ČR 'reduced' 15 % do 2023-12-31 a 12 % od 2024-01-01, 'second_reduced' 10 %
     *    jen 2015–2023, viz migrace 1294), takže zadrátovaná hodnota by zpětně datovaný
     *    doklad přepočítala dnešní sazbou. Je to zároveň TÝŽ podklad, proti kterému pak
     *    rozhoduje invariant — dosazená sazba tedy nikdy nemůže být sazba, kterou by
     *    číselník vzápětí neuznal.
     *
     * @param array<string,mixed> $item položka z parseru
     */
    private function itemRate(array $item, OssClientContext $client, string $domestic, string $taxDate): ?float
    {
        $fromFile = $item['vat_rate'] ?? null;
        if ($fromFile !== null) {
            return (float) $fromFile;
        }

        $level = self::rateLevel($item);
        if ($level === null || OssClientContext::iso2OrNull($client->countryIso2) !== $domestic) {
            return null;
        }

        foreach ($this->scaleRates($domestic, $taxDate) as $rate) {
            if ($rate['rate_type'] === $level) {
                return $rate['rate_percent'];
            }
        }

        return null;
    }

    /**
     * Sazbová úroveň položky, nebo `null`. Hodnoty jsou tytéž jako
     * `oss_member_state_rates.rate_type`, takže se dají číselníku položit rovnou jako
     * dotaz; cokoli mimo tu doménu (překlep v ručně upraveném souboru) se zahazuje —
     * úroveň, kterou číselník nevede, není na co přeložit.
     *
     * @param array<string,mixed> $item
     */
    private static function rateLevel(array $item): ?string
    {
        $level = $item['vat_rate_level'] ?? null;

        return is_string($level) && in_array($level, OssItemDecision::RATE_TYPES, true) ? $level : null;
    }

    /**
     * Co k úrovni stálo v souboru — pro hlášky je čitelnější syrový enum než náš název.
     *
     * @param array<string,mixed> $item
     */
    private static function rateLevelLabel(array $item): string
    {
        $enum = trim((string) ($item['vat_rate_enum'] ?? ''));

        return $enum !== '' ? $enum : (string) self::rateLevel($item);
    }

    /**
     * Sazby země dodavatele k datu plnění, memoizované — u 1 670 dokladů se stejným datem
     * by se jinak číselníku ptal každý řádek zvlášť.
     *
     * @return list<array{rate_type:string, rate_percent:float}>
     */
    private function scaleRates(string $country, string $taxDate): array
    {
        return $this->domesticScaleRates[$country . '|' . $taxDate]
            ??= $this->codebook->ratesFor($country, $taxDate);
    }

    /**
     * Jednotková cena BEZ daně. Parser vrací brutto v `unit_price_with_vat` právě tehdy,
     * když doklad byl v cenách s DPH a netto se muselo spočítat koeficientem sazby, kterou
     * ovšem soubor neurčoval — netto cena je pak PROVIZORNÍ, spočtená odhadem. Jakmile je
     * sazba rozhodnutá, musí se cena přepočítat přesně: u dokladu z roku 2020 se sazbovou
     * úrovní 'reduced' je rozdíl mezi provizorními 12 % a skutečnými 15 % na každém řádku.
     *
     * @param array<string,mixed> $item
     */
    private static function netUnitPrice(array $item, float $rate): float
    {
        $gross = $item['unit_price_with_vat'] ?? null;
        $coefficient = 1 + $rate / 100;
        if ($gross === null || $coefficient <= 0) {
            return (float) ($item['unit_price_without_vat'] ?? 0);
        }

        return (float) $gross / $coefficient;
    }

    /**
     * Proč se sazba položky nedala určit. Tři různé příčiny vedou ke třem různým krokům,
     * takže se nesmějí slít do jedné věty — původní znění mluvilo jen o `history*` bez
     * `inv:percentVAT` a u zbylých dvou příčin radilo mimo.
     *
     * @param array<string,mixed> $item
     */
    private function unresolvedRateMessage(array $item, OssClientContext $client, string $domestic, string $taxDate): string
    {
        $level = self::rateLevel($item);
        if ($level === null) {
            // Soubor nedává ani úroveň: `history*`, neznámý kód, nebo ISDOC bez
            // <ClassifiedTaxCategory><Percent>. Není z čeho překládat.
            $enum = trim((string) ($item['vat_rate_enum'] ?? ''));

            return sprintf(
                'Doklad u položky neurčuje sazbu DPH%s a nenese ani sazbovou úroveň, ze které by šlo '
                    . 'procento dosadit — odhadem to nejde, protože ze sazby plyne i to, komu se daň '
                    . 'odvádí. Doplňte do souboru inv:percentVAT (ISDOC: ClassifiedTaxCategory/Percent) '
                    . 'a import opakujte.',
                $enum !== '' ? sprintf(' (v souboru je jen „%s")', $enum) : '',
            );
        }

        $clientCountry = OssClientContext::iso2OrNull($client->countryIso2);
        if ($clientCountry !== $domestic) {
            // Neznámá země se pojmenuje jako neznámá, ne jako cizí: uživatel má u takového
            // dokladu doplnit protistranu, ne hledat chybu v sazbě.
            $who = $clientCountry !== null
                ? sprintf('odběratel je z %s, dodavatel z %s', $clientCountry, $domestic)
                : sprintf('země odběratele v dokladu chybí, dodavatel je z %s', $domestic);

            return sprintf(
                'Soubor u položky neuvádí procento DPH (inv:percentVAT) ani rekapitulaci, ze které by šlo '
                    . 'dopočítat — nese jen sazbovou úroveň „%s". Odběratel přitom není tuzemský (%s) '
                    . 'a Pohoda schema zahraniční sazby nezná, takže dosadit za úroveň sazbu země dodavatele '
                    . 'by z cizí daně udělalo tuzemskou. Doplňte do souboru inv:percentVAT a import opakujte.',
                self::rateLevelLabel($item),
                $who,
            );
        }

        return sprintf(
            'Soubor u položky neuvádí procento DPH (inv:percentVAT) ani rekapitulaci, ze které by šlo '
                . 'dopočítat — nese jen sazbovou úroveň „%s". Číselník sazeb členských států pro zemi '
                . 'dodavatele (%s) k %s sazbu téhle úrovně nevede, takže ji nelze dosadit. Spusťte '
                . 'php api/bin/migrate.php (historii tuzemských sazeb doplňuje migrace 1294), nebo doplňte '
                . 'inv:percentVAT do souboru.',
            self::rateLevelLabel($item),
            $domestic,
            self::fmtDate($taxDate),
        );
    }

    /**
     * Otočí znaménko řádkům dobropisu, který přišel s KLADNÝMI částkami.
     *
     * ── Proč se otáčí, a ne odmítá ──────────────────────────────────────────────────
     * Kladný dobropis není vada dat, ale JINÁ KONVENCE: řada systémů exportuje opravný
     * doklad jako „dobropis na 1 000" v absolutní hodnotě a znaménko nechává na typu
     * dokladu. MyÚčto vede dobropis se ZÁPORNÝM množstvím a kladnou jednotkovou cenou —
     * tak ho zakládá {@see \MyInvoice\Action\Invoice\CancelInvoiceAction} a tak ho
     * normalizuje i AI cesta ({@see AiPdfExtractor}, jejíž prompt si absolutní hodnoty
     * výslovně vyžádá právě proto, že znaménko dosadí importér). Dvě importní cesty
     * téhož produktu se nesmějí lišit v tom, co je dobropis, takže se normalizuje i tady.
     *
     * Odmítnutí by tu na rozdíl od invariantu proti úniku ({@see planItems()}) nebylo
     * akční: uživatel migrující 99 dobropisů nemá jak přepsat znaménka v exportu cizí
     * aplikace, takže by mu zbylo jen ruční pořízení. Přeznačkování je navíc podmíněné
     * KLADNÝM součtem dokladu — správně vyexportovaný dobropis (záporné řádky) se
     * nedotkne, takže riziko „otočíme i to, co bylo dobře" neexistuje.
     *
     * ── Otáčí se řádek po řádku, ne doklad jako celek ───────────────────────────────
     * Uvnitř dobropisu může být řádek se záporným součtem (sleva). Negace se proto počítá
     * z totálu ŘÁDKU, ne z totálu dokladu, jinak by se takový řádek posunul na špatnou
     * stranu. Cena zůstává v absolutní hodnotě a znaménko nese množství, protože
     * {@see \MyInvoice\Service\Validation\InvoiceAmountPolicy::validateItem()} zakazuje
     * obojí záporné — doklad by po importu nešlo v editoru uložit.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function negateCreditNoteRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $quantity = (float) $row['quantity'];
            $unitPrice = (float) $row['unit_price_without_vat'];
            $lineTotal = $quantity * $unitPrice;

            $row['unit_price_without_vat'] = abs($unitPrice);
            $row['quantity'] = $lineTotal > 0 ? -abs($quantity) : abs($quantity);
        }
        unset($row);

        return $rows;
    }

    /**
     * Země odběratele předávaná do {@see InvoiceRepository::defaultSaleClassificationCode()},
     * nebo `null`, když ji předat NESMÍME.
     *
     * U nenulové sazby je předání bez účinku — klasifikátor v té větvi zemi nečte (viz
     * docblock třídy). U NULOVÉ sazby ale rozhoduje o všem: zahraniční odběratel překlopí
     * kód z '3' na '20'/'22' (dodání zboží / poskytnutí služby do JČS), a právě tyhle dva
     * kódy plní SOUHRNNÉ HLÁŠENÍ. To se ale podává za plnění osobě REGISTROVANÉ k dani
     * v jiném členském státě — u B2C spotřebitele bez DIČ by vznikl řádek výkazu bez
     * protistrany. Takový řádek proto zemi nedostane a zůstane '3', tedy přesně to, co
     * systém dělal před zavedením derivace.
     *
     * Vývoz do třetí země ('26') se souhrnného hlášení netýká, tam se země předává vždy.
     */
    private function classificationCountry(OssClientContext $client, float $rate): ?string
    {
        if ($client->countryIso2 === null) {
            return null;
        }
        if ($rate > OssItemDeriver::EPSILON || !$client->isEu) {
            return $client->countryIso2;
        }

        return $client->hasVatId() ? $client->countryIso2 : null;
    }

    /**
     * Přidá hlášku do reportu jen jednou — u dvacetipoložkové faktury by se jinak tatáž
     * věta („stát není v číselníku") zopakovala dvacetkrát. Prefix nese první výskyt.
     *
     * @param list<string>       $bucket
     * @param array<string,bool> $seen
     */
    private static function addOnce(array &$bucket, array &$seen, string $label, string $message): void
    {
        if ($message === '' || isset($seen[$message])) {
            return;
        }
        $seen[$message] = true;
        $bucket[] = $label . ': ' . $message;
    }

    /**
     * Zápis připravených řádků. OSS sloupce se zapisují jen tam, kde je má schéma
     * (migrace 0137) — na starší instalaci import nesmí spadnout.
     *
     * `oss_exchange_rate*`, `oss_taxable_amount_return`, `oss_vat_amount_return`
     * a `oss_original_period` se ZÁMĚRNĚ nevyplňují: přepočet do měny podání dělá až
     * OssLedgerService kurzem ECB zveřejněným pro POSLEDNÍ DEN zdaňovacího období —
     * ten je jednotný pro celý kvartál a při importu se ještě nemusí znát.
     *
     * `oss_needs_manual_review` (migrace 1293) má guard VLASTNÍ, ne společný se zbytkem
     * OSS sloupců: mezi migracemi 0137 a 1293 je řada verzí, takže instance s OSS
     * schématem a bez příznaku je běžný, ne teoretický stav.
     *
     * @param list<array<string,mixed>> $rows z {@see planItems()}
     */
    private function insertItems(int $invoiceId, array $rows): void
    {
        if (empty($rows)) return;

        // Názvy sloupců jsou zároveň klíči `OssItemDecision::toItemColumns()`, takže se
        // seznam a dosazované hodnoty nemohou rozejít pořadím.
        $ossColumns = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? ['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type']
            : [];
        if ($ossColumns !== [] && $this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $ossColumns[] = 'oss_needs_manual_review';
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code'
            . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?'
            . str_repeat(', ?', count($ossColumns))
            . ')'
        );

        foreach (array_values($rows) as $i => $row) {
            $params = [
                $invoiceId,
                $row['description'],
                $row['quantity'],
                $row['unit'],
                $row['unit_price_without_vat'],
                $row['vat_rate_id'],
                $row['vat_rate_snapshot'],
                $i,
                $row['vat_classification_code'],
            ];
            foreach ($ossColumns as $column) {
                $params[] = $row['oss'][$column];
            }
            $stmt->execute($params);
        }
    }

    private function currencyId(int $supplierId, string $code): int
    {
        $code = strtoupper($code);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Měna $code není nakonfigurovaná pro tohoto dodavatele.");
        }
        return (int) $id;
    }

    private function loadSupplierIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === null || $ic === '') return null;
        return (string) $ic;
    }

    /**
     * ISDOCX balíček (ISDOC Package) — ZIP s vnitřním `.isdoc`. Poznáme ho podle
     * přípony `.isdocx` (content je ZIP magic, ale `.isdocx` NEchceme rozbalovat
     * generickým unzip()em — má vlastní strukturu manifest+PDF).
     */
    private function isIsdocx(string $name, string $content): bool
    {
        return str_ends_with(strtolower($name), '.isdocx')
            && IsdocxExtractor::isZip($content);
    }

    private function isZip(string $name, string $content): bool
    {
        // `.isdocx` je sice ZIP, ale má vlastní cestu (isIsdocx) — sem nepatří.
        if (str_ends_with(strtolower($name), '.isdocx')) return false;
        if (str_ends_with(strtolower($name), '.zip')) return true;
        // Magic bytes — PK\x03\x04 nebo PK\x05\x06 (empty zip).
        // PDF má sice taky neuzipped magic, ale začíná `%PDF-`, takže nedojde
        // k falešné shodě. Defenzivně přidáme explicit PDF guard.
        if (str_starts_with($content, '%PDF-')) return false;
        return strncmp($content, "PK\x03\x04", 4) === 0 || strncmp($content, "PK\x05\x06", 4) === 0;
    }

    private function isPdf(string $name, string $content): bool
    {
        return str_ends_with(strtolower($name), '.pdf') || str_starts_with($content, '%PDF-');
    }

    /**
     * Odstraní vedoucí UTF-8 BOM (EF BB BF). Někteří producenti (iDoklad) jím
     * prefixují ISDOC XML; ltrim ho neodstraní a rozbíjel detekci formátu níže.
     */
    private static function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * Rozliší ISDOC vs Pohoda XML. ISDOC poznáme podle přípony .isdoc nebo podle
     * přítomnosti ISDOC namespace (Pohoda XML ho neobsahuje). Dřív se testoval i
     * prefix '<?xml', což rozbíjel UTF-8 BOM → iDoklad PDF (BOM + namespace) padalo
     * na Pohoda parser s chybou "root není dataPack" (issue #39).
     */
    private static function looksLikeIsdoc(string $name, string $content): bool
    {
        return str_contains(strtolower($name), '.isdoc')
            || str_contains($content, 'isdoc.cz/namespace');
    }

    /**
     * @return list<array{name:string, content:string}>
     */
    private function unzip(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp-zip-');
        file_put_contents($tmp, $content);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Nelze otevřít ZIP.');
        }
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            @unlink($tmp);
            throw new \RuntimeException('ZIP obsahuje příliš mnoho souborů (max ' . self::MAX_ZIP_ENTRIES . ').');
        }

        $out = [];
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $name = $stat['name'];
            // Defense in depth — odmítni absolutní cesty / traversal v entry name
            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name)) {
                continue;
            }
            // Skip složky a non-XML/ISDOC
            if (str_ends_with($name, '/')) continue;
            // ISDOCX manifest (pokud balíček přišel pojmenovaný jako .zip) není faktura
            // — přeskočíme, ať neparsujeme manifest jako ISDOC a netvoříme failed řádek.
            if (strtolower(basename($name)) === 'manifest.xml') continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xml', 'isdoc'], true)) continue;

            $entrySize = (int) ($stat['size'] ?? 0);
            if ($entrySize > self::MAX_SINGLE_ENTRY_BYTES) {
                $zip->close();
                @unlink($tmp);
                throw new \RuntimeException("Položka {$name} v ZIP je příliš velká (max " . self::MAX_SINGLE_ENTRY_BYTES . " B).");
            }
            $totalBytes += $entrySize;
            if ($totalBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                $zip->close();
                @unlink($tmp);
                throw new \RuntimeException('Celková velikost ZIP po rozbalení překračuje povolený limit (zip-bomb ochrana).');
            }

            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $out[] = ['name' => basename($name), 'content' => $data];
            }
        }
        $zip->close();
        @unlink($tmp);
        return $out;
    }
}
