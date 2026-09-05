<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssDocumentCoherence;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssPeriod;
use MyInvoice\Service\Oss\OssRateCodebook;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné nastavení OSS parametrů nad výběrem dokladů (OSS-7).
 *
 *   POST /api/invoices/bulk-oss/preview   — co by se stalo (nic nezapisuje)
 *   POST /api/invoices/bulk-oss           — provedení, vyžaduje `confirm: true`
 *
 * ── Proč to vůbec je ────────────────────────────────────────────────────────────────
 * Po importu zůstanou řádky, u nichž systém místo plnění NEURČIL
 * (`invoice_items.oss_needs_manual_review`, migrace 1293), a řádky bez typu sazby, které
 * {@see \MyInvoice\Service\Oss\OssXmlExporter} do podání nepustí. U migrace 1 670 dokladů
 * je proklikat po jednom není reálné.
 *
 * ── Mění to účetní data, takže se doklad radši přeskočí ─────────────────────────────
 * Příznak `oss_applicable` rozhoduje, jestli řádek jde do ČESKÉHO přiznání k DPH, nebo do
 * OSS podání ({@see \MyInvoice\Service\Report\VatLedgerService}). Přepsat ho na dokladu,
 * který už je zaúčtovaný nebo v podaném období, znamená rozejít se s tím, co je odevzdané
 * na finanční správě. Hromadná akce proto nemá „force": co je zamčené, se PŘESKOČÍ a vypíše
 * i s důvodem. Náhled je povinný — na 200 dokladů se špatný filtr pozná jedině předem.
 *
 * Částky se nemění, takže se nic nepřepočítává; mění se jen zařazení řádku do výkazu.
 * Peněžní OSS sloupce (`oss_exchange_rate*`, `oss_*_amount_return`) akce ZÁMĚRNĚ nechává
 * být: dopočítává je {@see \MyInvoice\Service\Oss\OssLedgerService} a u řádku, který OSS
 * není, jsou stejně inertní — vynulovat je by zahodilo ručně zadaný kurz.
 *
 * ── VYPNUTÍ OSS JE STEJNĚ HLÍDANÉ JAKO ZAPNUTÍ ──────────────────────────────────────
 * Zápis jde přímým SQL, takže obchází guard „zahraniční sazbu lze použít jen na OSS
 * řádku" — a bez vlastní kontroly by hromadná akce byla dírou přesně do toho úniku, který
 * celý OSS epic zavírá: zhasnutím `oss_applicable` na řádku, který nese třeba PL 23 %, se
 * polská daň přesune na ř. 1 ČESKÉHO přiznání. Plán proto k bucketu `missingCountry`
 * (opačný směr: zapnout OSS nelze bez země spotřeby) přidává ZRCADLOVOU kontrolu
 * {@see domesticRateObstacle()}: vypnout OSS lze jedině tehdy, když číselník členských
 * států POZITIVNĚ potvrdí, že sazba řádku v zemi dodavatele k rozhodnému datu platí.
 * Ptáme se stejné autority a stejnou otázkou jako {@see OssItemDeriver} — číselníku
 * ({@see OssRateCodebook}), NIKDY tabulky `vat_rates` (viz docblock deriveru: uživatel si
 * do ní založí „PL 23 %" se zemí CZ a dotaz „zná ČR 23 %" pak vrátí ANO). „Nevím"
 * (chybí migrace 1152, zemi k datu nezná, datum plnění nejde zkanonizovat) je stejný
 * důvod k neprovedení jako tvrdé „neplatí": zablokovat je bezpečný směr, protože tím
 * nikam nic nepřesuneme, kdežto puštění dál znamená cizí daň v tuzemském přiznání.
 * Zákaz platí na řádek, který se STĚHUJE (byl OSS a přestává jím být); řádek, který mimo
 * OSS byl už předtím, akce nikam nepřesouvá a nepotvrzenou sazbu na něm jen VYVAROVÁ —
 * jinak by nešlo zhasnout „nevím" nových kanálů, což je hlavní důvod existence výběru
 * `needs_review`.
 *
 * ── Příznak z kontroly soudržnosti akce nezhasíná ──────────────────────────────────
 * `oss_needs_manual_review` má dva zdroje a v DB je to JEDEN boolean — rozlišit je bez
 * změny schématu nejde, takže se po zásahu soudržnost dokladu PŘEPOČÍTÁ
 * ({@see OssDocumentCoherence}, stejně jako to při každém uložení dělá editor i import)
 * a dotčené řádky příznak dostanou zpět. Kdyby akce `clear_needs_review` pustila naslepo,
 * uživatel by odklikl varování, které pořád platí — a rozpor „doklad leží ve dvou
 * přiznáních" by po zavření hlášky nezůstal nikde vidět.
 *
 * ── Vytištěné PDF se rozejde s daty, takže se cache zahazuje ────────────────────────
 * Doklad nese OSS doložku i podklad pro podání; přeřazením řádku by se vyrenderované PDF
 * rozešlo s tím, co je v datech. Cache se proto invaliduje stejně jako u ručního uložení
 * faktury ({@see \MyInvoice\Action\Invoice\UpdateInvoiceAction}) — renderer sám kontroluje
 * jen mtime šablon, ne dat.
 *
 * ── Selhání uprostřed dávky se hlásí, ne zamlčuje ──────────────────────────────────
 * Každý doklad má vlastní transakci, takže rozepsaný zůstat nemůže — ale u 200 dokladů je
 * holá 500 bez seznamu k ničemu: uživatel neví, které doklady už změněné jsou, a opakování
 * akce nad celým výběrem je sázka do loterie. Dávka se proto u první chyby ZASTAVÍ (další
 * doklady se ani nezkusí — když padá databáze, 199 dalších pokusů situaci nezlepší)
 * a odpověď i activity log nesou seznam hotových, nezpracovaných i toho jednoho, na kterém
 * to skončilo. Technická hláška jde jen do logu, klientovi ne.
 */
final class BulkOssUpdateAction
{
    private const MAX_DOCUMENTS = 200;

    /** Které položky dokladu akce bere. */
    private const SCOPES = ['needs_review', 'missing_rate_type', 'oss', 'all'];

    private const SUPPLY_TYPES = ['goods', 'services'];

    /** Důvod archivace zahozeného PDF — stejná taxonomie jako `invalidate_update`. */
    private const PDF_INVALIDATE_REASON = 'invalidate_oss_bulk';

    /** @var array<string, ?string> memoizace „období už podané" */
    private array $filedCache = [];

    /** @var array<string, bool> memoizace zadržení podle § 32 ZoÚ */
    private array $holdCache = [];

    /** @var array<string, list<array{rate_type:string, rate_percent:float}>> klíč "CC|Y-m-d" */
    private array $codebookCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly TaxSubmissionRepository $submissions,
        private readonly RetentionHoldRepository $holds,
        private readonly OssRateCodebook $codebook,
        private readonly OssItemDeriver $deriver,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, apply: false);
    }

    public function apply(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, apply: true);
    }

    private function handle(Request $request, Response $response, bool $apply): Response
    {
        // Route permission map pouští na `/api/invoices/*` obecné `invoices` WRITE, což je
        // na PŘEPIS DAŇOVÉHO ZAŘAZENÍ řádku málo — tady se rozhoduje, do kterého přiznání
        // doklad spadne. Držíme se proto stejné laťky jako u zakládání dokladu a klientskou
        // roli nepouštíme vůbec.
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit OSS parametry dokladů.', 403);
        }
        // Přeřazení stovek řádků mezi českým přiznáním a OSS je úkon s daňovou
        // odpovědností — stejná logika, kterou {@see \MyInvoice\Middleware\ApiScopeMiddleware}
        // uplatňuje na účetní vrstvu. Náhled přes token projde, provedení ne: to dělá
        // člověk v aplikaci, kde vidí, co se přeskočilo a proč.
        if ($apply && RequestAuthorization::isBearerAuth($request)) {
            return Json::error($response, 'token_write_forbidden',
                'Hromadnou změnu OSS lze provést jen z webového rozhraní, ne přes API token.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, (array) ($body['invoice_ids'] ?? [])),
            static fn (int $v) => $v > 0,
        )));
        if ($ids === []) {
            return Json::error($response, 'no_invoices', 'Není vybrán žádný doklad.', 400);
        }
        if (count($ids) > self::MAX_DOCUMENTS) {
            return Json::error($response, 'too_many',
                'Najednou lze upravit maximálně ' . self::MAX_DOCUMENTS . ' dokladů.', 422);
        }

        $scope = (string) ($body['scope'] ?? 'needs_review');
        if (!in_array($scope, self::SCOPES, true)) {
            return Json::error($response, 'validation_failed',
                'scope musí být ' . implode(' | ', self::SCOPES) . '.', 400);
        }

        try {
            $set = $this->parseSet((array) ($body['set'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if ($set === []) {
            return Json::error($response, 'nothing_to_set',
                'Nezadali jste, co se má nastavit.', 400);
        }

        // Náhled je povinný: bez vědomého potvrzení se na účetních datech nic nemění.
        if ($apply && ($body['confirm'] ?? false) !== true) {
            return Json::error($response, 'preview_required',
                'Hromadnou změnu OSS lze provést až po potvrzení náhledu (`confirm: true`).', 428);
        }

        $supplier = $this->supplierOss($supplierId);
        if (($set['oss_applicable'] ?? null) === true && !$supplier['oss_enabled']) {
            return Json::error($response, 'oss_disabled',
                'OSS režim není v nastavení firmy aktivní — nelze označit doklady jako OSS.', 409);
        }
        if (isset($set['oss_consumer_country'])
            && $supplier['identification_country'] !== null
            && $set['oss_consumer_country'] === $supplier['identification_country']) {
            return Json::error($response, 'validation_failed',
                'Země spotřeby se rovná státu identifikace dodavatele — takové plnění je tuzemské, ne OSS.', 400);
        }
        if (isset($set['oss_consumer_country']) && !$this->isEuCountry($set['oss_consumer_country'])) {
            return Json::error($response, 'validation_failed',
                'Země spotřeby ' . $set['oss_consumer_country'] . ' není v číselníku zemí vedena jako členský stát EU.', 400);
        }

        $plan = $this->buildPlan($supplierId, $ids, $scope, $set, $supplier);

        $outcome = null;
        if ($apply) {
            $outcome = $this->execute($plan, $set);

            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $userId = (int) ($user['id'] ?? 0);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $agent = $request->getHeaderLine('User-Agent');

            // Log se zapisuje i při selhání a nese SEZNAM hotových dokladů, ne jen počet:
            // z počtu se u 200 dokladů nedá zjistit, kde dávka skončila, a právě to
            // uživatel po chybě potřebuje. Technická hláška je jen tady, do odpovědi nejde.
            $this->logger->log('invoice.oss_bulk_updated', $userId ?: null, null, null, [
                'requested'             => count($ids),
                'changed'               => count($outcome['completed']),
                'planned'               => count(array_filter($plan, static fn ($d) => $d['action'] === 'update')),
                'skipped'               => count(array_filter($plan, static fn ($d) => $d['action'] === 'skip')),
                'scope'                 => $scope,
                'set'                   => $set,
                'completed_invoice_ids' => $outcome['completed'],
                'not_attempted_invoice_ids' => $outcome['pending'],
                'failed'                => $outcome['failed'],
                'pdf_not_invalidated'   => $outcome['pdf_failed'],
            ], $ip, $agent, $supplierId);

            if ($outcome['failed'] !== null) {
                // Druhý záznam visí přímo na dokladu, na kterém to skončilo — v jeho
                // historii ho uživatel najde i tehdy, když odpověď mezitím zahodil.
                $this->logger->log('invoice.oss_bulk_failed', $userId ?: null, 'invoice',
                    $outcome['failed']['invoice_id'], [
                        'scope'   => $scope,
                        'set'     => $set,
                        'detail'  => $outcome['failed']['detail'],
                        'done'    => count($outcome['completed']),
                        'pending' => count($outcome['pending']),
                    ], $ip, $agent, $supplierId);

                return Json::error($response, 'bulk_update_failed', sprintf(
                    'Hromadná změna se zastavila u dokladu %s: zapsáno %d dokladů, %d se už nezkoušelo. '
                        . 'Nezpracované doklady zůstaly beze změny — po odstranění příčiny spusťte akci '
                        . 'znovu jen nad nimi.',
                    $outcome['failed']['varsymbol'] ?? ('#' . $outcome['failed']['invoice_id']),
                    count($outcome['completed']),
                    count($outcome['pending']),
                ), 500, [
                    'completed_invoice_ids'     => $outcome['completed'],
                    'failed_invoice'            => [
                        'invoice_id' => $outcome['failed']['invoice_id'],
                        'varsymbol'  => $outcome['failed']['varsymbol'],
                    ],
                    'not_attempted_invoice_ids' => $outcome['pending'],
                    'pdf_not_invalidated'       => $outcome['pdf_failed'],
                ]);
            }
        }

        return Json::ok($response, [
            'applied'   => $apply,
            'scope'     => $scope,
            'set'       => $set,
            'documents' => array_values($plan),
            'summary'   => $this->summarize($plan),
        ] + ($outcome === null ? [] : [
            'completed_invoice_ids' => $outcome['completed'],
            'pdf_not_invalidated'   => $outcome['pdf_failed'],
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Vstup
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed> jen klíče, které klient skutečně poslal
     */
    private function parseSet(array $raw): array
    {
        $set = [];

        if (array_key_exists('oss_applicable', $raw) && $raw['oss_applicable'] !== null) {
            $set['oss_applicable'] = (bool) $raw['oss_applicable'];
        }
        if (array_key_exists('oss_consumer_country', $raw) && trim((string) $raw['oss_consumer_country']) !== '') {
            $country = strtoupper(trim((string) $raw['oss_consumer_country']));
            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                throw new \InvalidArgumentException('oss_consumer_country: dvoupísmenný ISO2 kód.');
            }
            $set['oss_consumer_country'] = $country;
        }
        if (array_key_exists('oss_rate_type', $raw) && trim((string) $raw['oss_rate_type']) !== '') {
            $rateType = (string) $raw['oss_rate_type'];
            if (!in_array($rateType, OssRateCodebook::RATE_TYPES, true)) {
                throw new \InvalidArgumentException(
                    'oss_rate_type: ' . implode(' | ', OssRateCodebook::RATE_TYPES) . '.'
                );
            }
            $set['oss_rate_type'] = $rateType;
        }
        if (array_key_exists('oss_supply_type', $raw) && trim((string) $raw['oss_supply_type']) !== '') {
            $supplyType = (string) $raw['oss_supply_type'];
            if (!in_array($supplyType, self::SUPPLY_TYPES, true)) {
                throw new \InvalidArgumentException('oss_supply_type: ' . implode(' | ', self::SUPPLY_TYPES) . '.');
            }
            $set['oss_supply_type'] = $supplyType;
        }
        if (array_key_exists('clear_needs_review', $raw)) {
            $set['clear_needs_review'] = (bool) $raw['clear_needs_review'];
        }

        // Potvrzení místa plnění je ROZHODNUTÍ člověka (migrace 1293) a tahle akce je
        // jediné místo, kde ho člověk dělá — po ruční úpravě proto příznak default zhasíná.
        if ($set !== [] && !array_key_exists('clear_needs_review', $set)) {
            $set['clear_needs_review'] = true;
        }
        if (array_keys($set) === ['clear_needs_review'] && $set['clear_needs_review'] === false) {
            return [];
        }

        return $set;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Plán
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $set
     * @param array{oss_enabled:bool, valid_from:?string, valid_to:?string, identification_country:?string} $supplier
     * @return array<int, array<string,mixed>>
     */
    private function buildPlan(int $supplierId, array $ids, string $scope, array $set, array $supplier): array
    {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, status, invoice_type, varsymbol, booked_at,
                    effective_tax_date, tax_date, issue_date
               FROM invoices
              WHERE supplier_id = ? AND id IN ({$place})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $invoices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoices[(int) $row['id']] = $row;
        }

        $lockMap = $this->locks->lockedMapForSources($supplierId, 'invoice', array_keys($invoices));
        $itemsByInvoice = $this->loadItems(array_keys($invoices));

        $plan = [];
        foreach ($ids as $id) {
            $plan[$id] = $this->planDocument($supplierId, $id, $invoices[$id] ?? null,
                $lockMap[$id] ?? null, $itemsByInvoice[$id] ?? [], $scope, $set, $supplier);
        }

        return $plan;
    }

    /**
     * @param array<string,mixed>|null $invoice
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $set
     * @return array<string,mixed>
     */
    private function planDocument(
        int $supplierId,
        int $id,
        ?array $invoice,
        ?\MyInvoice\Service\Accounting\DocumentLock $lock,
        array $items,
        string $scope,
        array $set,
        array $supplier,
    ): array {
        $base = ['invoice_id' => $id, 'varsymbol' => null, 'status' => null, 'tax_date' => null];
        if ($invoice === null) {
            // `keep_review` tu musí být taky, i když je prázdné: klient dostává jedno pole
            // dokladů a nemůže u každého zvlášť hádat, které klíče v něm jsou.
            return $base + ['action' => 'skip', 'skip_reason' => 'not_found',
                'skip_detail' => 'Doklad neexistuje nebo patří jiné firmě.',
                'items_matched' => 0, 'changes' => [], 'warnings' => [], 'keep_review' => []];
        }

        $refDate = DocumentLockService::invoiceRefDate($invoice);
        $base = [
            'invoice_id' => $id,
            'varsymbol'  => $invoice['varsymbol'] !== null ? (string) $invoice['varsymbol'] : null,
            'status'     => (string) $invoice['status'],
            'tax_date'   => $refDate,
        ];

        $skip = static fn (string $reason, string $detail): array => $base + [
            'action' => 'skip', 'skip_reason' => $reason, 'skip_detail' => $detail,
            'items_matched' => 0, 'changes' => [], 'warnings' => [], 'keep_review' => [],
        ];

        if ((string) $invoice['status'] === 'cancelled') {
            return $skip('cancelled', 'Stornovaný doklad se needituje.');
        }
        if ($lock !== null && $lock->lockedForClient()) {
            return $skip('locked', 'Doklad je uzamčen: ' . $this->lockLabel($lock) . '.');
        }
        if ($lock !== null && $lock->dateLocked) {
            return $skip('date_locked', 'Doklad spadá do období uzavřeného daňovým zámkem.');
        }
        if ($refDate !== null && $this->hasHold($supplierId, (int) substr($refDate, 0, 4))) {
            return $skip('retention_hold',
                'Záznamy roku ' . substr($refDate, 0, 4) . ' jsou zadržené podle § 32 ZoÚ.');
        }
        $filed = $refDate !== null ? $this->filedPeriod($supplierId, $refDate) : null;
        if ($filed !== null) {
            return $skip('period_filed',
                'Období už bylo podáno (' . $filed . ') — změnu řešte opravným/dodatečným tvrzením.');
        }
        // Zapnout OSS lze jen tam, kde registrace k datu plnění opravdu platila — jinak
        // by doklad zmizel z českého přiznání a v OSS podání se neobjevil.
        if (($set['oss_applicable'] ?? null) === true && $refDate !== null
            && !$this->withinRegistration($refDate, $supplier)) {
            return $skip('outside_oss_registration',
                'Datum plnění ' . $refDate . ' leží mimo platnost registrace k OSS.');
        }

        $matched = array_values(array_filter($items, static function (array $it) use ($scope): bool {
            return match ($scope) {
                'needs_review'      => (bool) $it['oss_needs_manual_review'],
                'missing_rate_type' => (bool) $it['oss_applicable']
                    && ($it['oss_rate_type'] === null || $it['oss_rate_type'] === ''),
                'oss'               => (bool) $it['oss_applicable'],
                default             => true,
            };
        }));
        if ($matched === []) {
            return $skip('no_matching_items', 'Doklad nemá položku, která by do výběru spadala.');
        }

        // Země dodavatele se bere z TÉŽE autority jako u derivace — zadrátovaná 'CZ' by
        // se u dodavatele identifikovaného mimo ČR rozešla s tím, co říká deriver.
        $domesticCountry = $this->deriver->domesticCountry($supplierId);

        $changes = [];
        $warnings = [];
        $missingCountry = [];
        $unverifiedDomestic = [];
        foreach ($matched as $item) {
            $next = $this->nextState($item, $set);
            if ($next['oss_applicable'] && ($next['oss_consumer_country'] ?? null) === null) {
                $missingCountry[] = (int) $item['id'];
                continue;
            }
            if (!$this->differs($item, $next, $set)) {
                continue;
            }
            // ZRCADLO k `missingCountry`: opačný směr přeřazení. Zápisem `oss_applicable = 0`
            // se řádek přesune do tuzemského přiznání, takže musí projít týmž invariantem,
            // jaký na tuhle větev klade deriver — jinak by hromadná akce byla obchvatem
            // guardu „zahraniční sazbu jen na OSS řádku".
            //
            // Blokuje se jen řádek, který se opravdu STĚHUJE (byl OSS a přestává jím být) —
            // tam akce daň přesouvá a smí to jedině s pozitivním potvrzením číselníku.
            // U řádku, který mimo OSS byl už předtím, se nic nepřesouvá; tvrdý zákaz by
            // jen znemožnil zhasnout „nevím" nových kanálů (a to je celý smysl výběru
            // `needs_review`), takže se místo něj VAROVÁNÍM řekne, co číselník tvrdí.
            if (!$next['oss_applicable']) {
                $obstacle = $this->domesticRateObstacle($domesticCountry, $item, $refDate);
                if ($obstacle !== null && (bool) $item['oss_applicable']) {
                    $unverifiedDomestic[(int) $item['id']] = $obstacle;
                    continue;
                }
                if ($obstacle !== null) {
                    $warnings[] = $obstacle . ' Řádek mimo OSS byl už před touhle změnou, takže se '
                        . 'nikam nepřesouvá — ověřte ale, jestli do tuzemského přiznání opravdu patří.';
                }
            }
            $changes[] = [
                'item_id'     => (int) $item['id'],
                'description' => mb_substr((string) $item['description'], 0, 80),
                'from'        => $this->snapshot($item),
                'to'          => $next,
            ];
            $warning = $this->rateWarning($item, $next, $refDate);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        if ($missingCountry !== []) {
            // Řádek bez země spotřeby projde do podání jako neúplný a exportér ho odmítne —
            // radši nezapisovat nic než nechat doklad v půli cesty.
            return $skip('missing_consumer_country',
                'Bez země spotřeby by OSS řádek nešel podat (položky: '
                    . implode(', ', $missingCountry) . ').');
        }
        if ($unverifiedDomestic !== []) {
            return $skip('unverified_domestic_rate', sprintf(
                '%s Řádek by tím zůstal mimo OSS a cizí daň by dopadla do českého přiznání (ř. 1), '
                    . 'proto doklad zůstal beze změny (položky: %s). Zařaďte řádek do OSS a doplňte '
                    . 'zemi spotřeby, nebo opravte sazbu na dokladu.',
                implode(' ', array_unique(array_values($unverifiedDomestic))),
                implode(', ', array_keys($unverifiedDomestic)),
            ));
        }
        if ($changes === []) {
            return $skip('no_change', 'Vybrané položky už požadované hodnoty mají.');
        }

        // Soudržnost dokladu se počítá nad stavem PO změně: příznak „k ručnímu posouzení"
        // nesmí zhasnout tam, kde ho drží rozpor celého dokladu, který tahle akce
        // nepřepočítává (a bez přepočtu by uživatel odklikl varování, které pořád platí).
        [$contradiction, $keepReview] = $this->contradictionAfterChange($items, $changes);
        if ($contradiction !== null) {
            $warnings[] = $contradiction->warning($domesticCountry);
            if (!empty($set['clear_needs_review'])) {
                $warnings[] = 'Příznak „k ručnímu posouzení" na dotčených řádcích proto zůstane '
                    . 'rozsvícený i po hromadné změně (položky: ' . implode(', ', $keepReview) . ').';
            }
        }

        return $base + [
            'action'        => 'update',
            'skip_reason'   => null,
            'skip_detail'   => null,
            'items_matched' => count($matched),
            'changes'       => $changes,
            'warnings'      => array_values(array_unique($warnings)),
            'keep_review'   => $keepReview,
        ];
    }

    /**
     * Proč řádek NESMÍ opustit OSS, nebo `null`, když smí.
     *
     * Zrcadlová kontrola k `missingCountry`: ptá se JEDINÉ otázky, která smí vpustit řádek
     * do tuzemské větve — „potvrdil číselník ČLENSKÝCH STÁTŮ sazbu v zemi dodavatele
     * k rozhodnému datu?". Autorita je stejná jako u {@see OssItemDeriver} a `vat_rates`
     * to není ani omylem: je to uživatelsky editovatelný číselník sazeb PRO doklad, kde
     * si zákazník založil „PL 23 %" se zemí CZ, takže by na otázku „zná ČR 23 %"
     * odpověděl ANO a cizí daň by prošla na ř. 1.
     *
     * Odpovědi jsou tři a jen jedna z nich pouští dál:
     *   PLATÍ   → `null`, řádek tuzemský být může
     *   NEPLATÍ → hláška, sazba do země dodavatele nepatří
     *   NEVÍM   → hláška; nevědomost není důkaz o tuzemsku a zablokovat je bezpečný směr,
     *             protože tím nic nikam nepřesuneme
     *
     * VÝJIMKA sazba 0 %: osvobození, přenesená daňová povinnost i vývoz se vykazují BEZ
     * DANĚ, takže z takového řádku nemá co uniknout — a číselník nulové sazby nevede, takže
     * by vynucení invariantu odmítlo každé osvobozené plnění. Shodně s deriverem.
     *
     * @param array<string,mixed> $item
     */
    private function domesticRateObstacle(string $domesticCountry, array $item, ?string $refDate): ?string
    {
        $rate = (float) $item['vat_rate_snapshot'];
        if ($rate <= OssItemDeriver::EPSILON) {
            return null;
        }

        $percent = self::fmtPercent($rate);
        $onDate = OssItemDeriver::canonicalDate($refDate);
        if ($onDate === null) {
            return sprintf(
                'Sazbu %s %% nelze bez použitelného data plnění ověřit v zemi dodavatele (%s).',
                $percent,
                $domesticCountry,
            );
        }
        // Chybějící migrace ≠ chybějící stát — sloučení obou hlášek posílá uživatele hledat
        // chybu v datech místo v instalaci (viz OssRateCodebook::checkRate()).
        if (!$this->codebook->isAvailable()) {
            return sprintf(
                'Sazbu %s %% nelze ověřit v zemi dodavatele (%s) — v databázi vůbec není číselník '
                    . 'sazeb členských států (migrace 1152), spusťte `php api/bin/migrate.php`.',
                $percent,
                $domesticCountry,
            );
        }

        $rates = $this->ratesFor($domesticCountry, $onDate);
        if ($rates === []) {
            return sprintf(
                'Sazbu %s %% se nepodařilo ověřit — číselník sazeb členských států zemi dodavatele '
                    . '(%s) k %s nevede.',
                $percent,
                $domesticCountry,
                $onDate,
            );
        }
        foreach ($rates as $known) {
            if (abs($known['rate_percent'] - $rate) <= OssItemDeriver::EPSILON) {
                return null;
            }
        }

        return sprintf(
            'Sazba %s %% podle číselníku sazeb členských států v zemi dodavatele (%s) k %s neplatí.',
            $percent,
            $domesticCountry,
            $onDate,
        );
    }

    /**
     * Rozpor dokladu PO plánované změně a seznam položek, které kvůli němu musí příznak
     * „k ručnímu posouzení" nést.
     *
     * Počítá se nad VŠEMI položkami dokladu, ne jen nad měněnými: rozpor je vlastnost
     * dokladu a {@see OssDocumentCoherence} označuje obě jeho strany — tuzemský řádek je
     * ten, který má člověk opravdu prověřit, a ten typicky do výběru vůbec nespadl.
     * Slevové řádky se přeskakují stejně jako v `flagItems()` (nesou sazbu své skupiny).
     *
     * @param list<array<string,mixed>> $items   všechny položky dokladu, stav PŘED změnou
     * @param list<array<string,mixed>> $changes plánované změny z {@see planDocument()}
     * @return array{0: ?OssDocumentCoherence, 1: list<int>}
     */
    private function contradictionAfterChange(array $items, array $changes): array
    {
        $next = [];
        foreach ($changes as $change) {
            $next[(int) $change['item_id']] = $change['to'];
        }

        $lines = [];
        foreach ($items as $item) {
            if ((string) ($item['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $id = (int) $item['id'];
            $state = $next[$id] ?? $this->snapshot($item);
            $lines[$id] = [
                'applicable' => (bool) $state['oss_applicable'],
                'country'    => (string) ($state['oss_consumer_country'] ?? ''),
                'rate'       => (float) $item['vat_rate_snapshot'],
            ];
        }

        $contradiction = OssDocumentCoherence::detect($lines);
        if ($contradiction === null) {
            return [null, []];
        }

        return [$contradiction, array_map(intval(...), $contradiction->affectedKeys)];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $set
     * @return array{oss_applicable:bool, oss_consumer_country:?string, oss_rate_type:?string, oss_supply_type:?string}
     */
    private function nextState(array $item, array $set): array
    {
        $applicable = array_key_exists('oss_applicable', $set)
            ? (bool) $set['oss_applicable']
            : (bool) $item['oss_applicable'];

        if (!$applicable) {
            // Řádek přestal být OSS — doprovodná pole by jinak zůstala viset a mátla
            // jak náhled podání, tak pozdější derivaci.
            return [
                'oss_applicable'       => false,
                'oss_consumer_country' => null,
                'oss_rate_type'        => null,
                'oss_supply_type'      => null,
            ];
        }

        return [
            'oss_applicable'       => true,
            'oss_consumer_country' => $set['oss_consumer_country'] ?? self::str($item['oss_consumer_country']),
            'oss_rate_type'        => $set['oss_rate_type']        ?? self::str($item['oss_rate_type']),
            'oss_supply_type'      => $set['oss_supply_type']      ?? self::str($item['oss_supply_type']),
        ];
    }

    /** @param array<string,mixed> $item */
    private function snapshot(array $item): array
    {
        return [
            'oss_applicable'       => (bool) $item['oss_applicable'],
            'oss_consumer_country' => self::str($item['oss_consumer_country']),
            'oss_rate_type'        => self::str($item['oss_rate_type']),
            'oss_supply_type'      => self::str($item['oss_supply_type']),
            'oss_needs_manual_review' => (bool) $item['oss_needs_manual_review'],
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $next
     * @param array<string,mixed> $set
     */
    private function differs(array $item, array $next, array $set): bool
    {
        $current = $this->snapshot($item);
        foreach (['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type'] as $field) {
            if ($current[$field] !== $next[$field]) {
                return true;
            }
        }
        return !empty($set['clear_needs_review']) && $current['oss_needs_manual_review'];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $next
     */
    private function rateWarning(array $item, array $next, ?string $refDate): ?string
    {
        if (!$next['oss_applicable'] || $next['oss_consumer_country'] === null || $refDate === null) {
            return null;
        }
        return $this->codebook->checkRate(
            $next['oss_consumer_country'],
            (float) $item['vat_rate_snapshot'],
            $next['oss_rate_type'],
            $refDate,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Zápis
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Provede plán a VRÁTÍ, co se stihlo — nevyhazuje. Volající z toho staví odpověď
     * i activity log; u 200 dokladů je samotná výjimka bez seznamu k nepoužití.
     *
     * @param array<int, array<string,mixed>> $plan
     * @param array<string,mixed> $set
     * @return array{completed: list<int>, pending: list<int>, pdf_failed: list<int>,
     *               failed: ?array{invoice_id:int, varsymbol:?string, detail:string}}
     */
    private function execute(array $plan, array $set): array
    {
        $pdo = $this->db->pdo();
        $hasReviewColumn = $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');
        $clearReview = !empty($set['clear_needs_review']) && $hasReviewColumn;

        $sql = 'UPDATE invoice_items
                   SET oss_applicable = ?, oss_consumer_country = ?, oss_rate_type = ?, oss_supply_type = ?'
             . ($clearReview ? ', oss_needs_manual_review = 0' : '')
             . ' WHERE id = ? AND invoice_id = ?';
        $itemStmt = $pdo->prepare($sql);
        // Doklad musí „zestárnout" i když se nezměnila ani koruna — fronta „změněno po
        // podání" ({@see \MyInvoice\Service\Report\VatPostFilingChangesService}) jede
        // z `updated_at` a přeřazení řádku mezi výkazy je pro ni stejná změna jako částka.
        $invoiceStmt = $pdo->prepare('UPDATE invoices SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');

        $completed = [];
        $pending = [];
        $pdfFailed = [];
        $failed = null;

        foreach ($plan as $doc) {
            if ($doc['action'] !== 'update') {
                continue;
            }
            $invoiceId = (int) $doc['invoice_id'];
            // Po první chybě se další doklady ani nezkoušejí: příčinou bývá stav databáze,
            // který dalších 199 pokusů nezlepší, a uživatel potřebuje jasnou hranici
            // „odsud dál je všechno beze změny".
            if ($failed !== null) {
                $pending[] = $invoiceId;
                continue;
            }

            $pdo->beginTransaction();
            try {
                foreach ($doc['changes'] as $change) {
                    $to = $change['to'];
                    $itemStmt->execute([
                        $to['oss_applicable'] ? 1 : 0,
                        $to['oss_consumer_country'],
                        $to['oss_rate_type'],
                        $to['oss_supply_type'],
                        $change['item_id'],
                        $doc['invoice_id'],
                    ]);
                }
                // Rozsvícení příznaku z kontroly soudržnosti jde AŽ ZA položkovým UPDATE,
                // takže přebije `clear_needs_review`: rozpor, který akce nepřepočítala,
                // by se jinak odkliknul, i když pořád platí.
                $keepReview = $doc['keep_review'] ?? [];
                if ($hasReviewColumn && $keepReview !== []) {
                    $place = implode(',', array_fill(0, count($keepReview), '?'));
                    $pdo->prepare(
                        "UPDATE invoice_items SET oss_needs_manual_review = 1
                          WHERE invoice_id = ? AND id IN ({$place})"
                    )->execute([$invoiceId, ...$keepReview]);
                }
                $invoiceStmt->execute([$invoiceId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $failed = [
                    'invoice_id' => $invoiceId,
                    'varsymbol'  => $doc['varsymbol'] !== null ? (string) $doc['varsymbol'] : null,
                    'detail'     => $e->getMessage(),
                ];
                continue;
            }
            $completed[] = $invoiceId;

            // Vytištěné PDF nese OSS doložku i podklad pro podání, takže po přeřazení řádku
            // ukazuje něco jiného než data. Invalidace je AŽ PO commitu a mimo transakci
            // (sahá na soubory a archivuje verzi); její selhání doklad neshazuje — zápis
            // je hotový a lhát o něm by bylo horší —, ale mlčet se o něm nesmí.
            try {
                $this->pdf->invalidate($invoiceId, self::PDF_INVALIDATE_REASON);
            } catch (\Throwable) {
                $pdfFailed[] = $invoiceId;
            }
        }

        return ['completed' => $completed, 'pending' => $pending,
                'pdf_failed' => $pdfFailed, 'failed' => $failed];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Pomocné
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $invoiceIds
     * @return array<int, list<array<string,mixed>>>
     */
    private function loadItems(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        // Instalace bez migrace 1293 sloupec nemá — dotaz ho dopočítá na 0, aby zbytek
        // akce nemusel řešit dvě varianty tvaru řádku.
        $reviewColumn = $this->db->hasColumn('invoice_items', 'oss_needs_manual_review')
            ? 'oss_needs_manual_review'
            : '0 AS oss_needs_manual_review';
        $place = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, invoice_id, description, item_kind, vat_rate_snapshot, oss_applicable,
                    oss_consumer_country, oss_rate_type, oss_supply_type, {$reviewColumn}
               FROM invoice_items
              WHERE invoice_id IN ({$place})
           ORDER BY invoice_id, order_index, id"
        );
        $stmt->execute($invoiceIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['invoice_id']][] = $row;
        }
        return $out;
    }

    /** @return array{oss_enabled:bool, valid_from:?string, valid_to:?string, identification_country:?string} */
    private function supplierOss(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'oss_enabled'            => !empty($row['oss_enabled']),
            'valid_from'             => self::str($row['oss_valid_from'] ?? null),
            'valid_to'               => self::str($row['oss_valid_to'] ?? null),
            'identification_country' => self::str($row['oss_identification_country'] ?? null),
        ];
    }

    /** @param array{valid_from:?string, valid_to:?string} $supplier */
    private function withinRegistration(string $refDate, array $supplier): bool
    {
        if ($supplier['valid_from'] !== null && $refDate < $supplier['valid_from']) {
            return false;
        }
        return !($supplier['valid_to'] !== null && $refDate > $supplier['valid_to']);
    }

    private function isEuCountry(string $iso2): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_eu FROM countries WHERE UPPER(iso2) = ? LIMIT 1');
        $stmt->execute([strtoupper($iso2)]);
        $v = $stmt->fetchColumn();

        return $v !== false && (int) $v === 1;
    }

    private function hasHold(int $supplierId, int $year): bool
    {
        $key = $supplierId . ':' . $year;
        return $this->holdCache[$key] ??= $this->holds->hasActiveHold($supplierId, $year);
    }

    /**
     * Sazby země k datu s memoizací — dávka 200 dokladů se ptá pořád na tutéž zemi
     * dodavatele a bez cache by to bylo tisíce dotazů. Shodně s cachí v deriveru.
     *
     * @return list<array{rate_type:string, rate_percent:float}>
     */
    private function ratesFor(string $country, string $onDate): array
    {
        return $this->codebookCache[$country . '|' . $onDate] ??= $this->codebook->ratesFor($country, $onDate);
    }

    /**
     * Popis podaného výkazu, který dané datum plnění pokrývá, nebo `null`.
     *
     * Kontrolují se OBĚ periodicity DPH (firma může být měsíční i čtvrtletní plátce a
     * v čase se to mění), kontrolní hlášení i samotné OSS podání. Za podané se považuje
     * jen prokazatelně odevzdaný snapshot — vygenerování XML podáním není
     * ({@see TaxSubmissionRepository::findLatestForPeriod()} filtruje na
     * `submitted`/`accepted`).
     */
    private function filedPeriod(int $supplierId, string $refDate): ?string
    {
        $key = $supplierId . ':' . $refDate;
        if (array_key_exists($key, $this->filedCache)) {
            return $this->filedCache[$key];
        }

        $year = (int) substr($refDate, 0, 4);
        $month = (int) substr($refDate, 5, 2);
        $quarter = (int) ceil($month / 3);
        // Každý formulář má vlastní sadu kódů varianty. Přiznání k DPH zná
        // B/O/D/E, kontrolní hlášení ale N (následné) místo D — se sadou od
        // přiznání by podané NÁSLEDNÉ kontrolní hlášení neplatilo za podání
        // a hromadná změna by prošla nad už odevzdaným obdobím.
        $variantsByForm = [
            'dphdp3' => ['B', 'O', 'D', 'E'],
            'dphkh1' => ['B', 'O', 'N', 'E'],
            'ossei1' => ['B', 'O', 'D', 'E'],
        ];

        $found = null;
        foreach ([['dphdp3', 'přiznání k DPH'], ['dphkh1', 'kontrolní hlášení']] as [$form, $label]) {
            $variants = $variantsByForm[$form];
            if ($this->submissions->findLatestForPeriod($supplierId, $form, $year, $month, null, $variants) !== null) {
                $found = sprintf('%s za %d/%d', $label, $month, $year);
                break;
            }
            if ($this->submissions->findLatestForPeriod($supplierId, $form, $year, null, $quarter, $variants) !== null) {
                $found = sprintf('%s za Q%d %d', $label, $quarter, $year);
                break;
            }
        }
        if ($found === null
            && $this->submissions->findLatestForPeriod(
                $supplierId,
                'ossei1',
                $year,
                null,
                $quarter,
                $variantsByForm['ossei1'],
            ) !== null) {
            $found = sprintf('OSS přiznání za %s', OssPeriod::quarterCode($refDate) ?? ('Q' . $quarter . ' ' . $year));
        }

        return $this->filedCache[$key] = $found;
    }

    private function lockLabel(\MyInvoice\Service\Accounting\DocumentLock $lock): string
    {
        $labels = [
            'posted'         => 'zaúčtováno v deníku',
            'booked'         => 'zaúčtováno',
            'period_closed'  => 'uzavřené účetní období',
            'period_closing' => 'období v uzávěrce',
            'date_locked'    => 'daňový zámek k datu',
        ];
        $reasons = array_map(static fn (string $r) => $labels[$r] ?? $r, $lock->reasons());

        return $reasons === [] ? 'zamčeno' : implode(', ', $reasons);
    }

    /**
     * @param array<int, array<string,mixed>> $plan
     * @return array<string,mixed>
     */
    private function summarize(array $plan): array
    {
        $byReason = [];
        $itemsToChange = 0;
        $toChange = 0;
        $warnings = 0;
        foreach ($plan as $doc) {
            if ($doc['action'] === 'update') {
                $toChange++;
                $itemsToChange += count($doc['changes']);
                $warnings += count($doc['warnings']);
                continue;
            }
            $reason = (string) $doc['skip_reason'];
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        return [
            'documents_total'    => count($plan),
            'documents_to_change' => $toChange,
            'documents_skipped'  => count($plan) - $toChange,
            'items_to_change'    => $itemsToChange,
            'warnings'           => $warnings,
            'skipped_by_reason'  => $byReason,
        ];
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** Shodný formát procenta jako v deriveru i v číselníku — jedna sazba, jedno psaní. */
    private static function fmtPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',');
    }
}
