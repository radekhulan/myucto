<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * SSOT pro narativní popis účetního zápisu (`journal_entries.description`).
 *
 * PROČ EXISTUJE
 * ------------------------------------------------------------------------------
 * Deník je účetní kniha (§13 ZoÚ) a popis je jediné místo, kde účetní v seznamu
 * pozná, o jaký účetní případ jde. Dokud si popis skládala každá cesta sama, vznikly
 * desítky řádků se SHODNÝM textem: vydané faktury nesly text prvního řádku dokladu
 * („Fakturujeme Vám za …"), přijaté předmět faktury, bankovní zápisy jen název
 * protistrany. Bez čísla dokladu a protistrany se zápisy od sebe nedaly odlišit.
 *
 * Pravidlo bylo dřív schované jako `private PostingService::defaultDescription()`,
 * tedy přesně ten případ, před kterým varuje AGENTS.md: „SSOT musí jít ZAVOLAT."
 * Proto je tady jako veřejná služba, kterou volá KAŽDÁ cesta:
 *   - {@see PostingService::postDocument()} (auto-post, bulk, doúčtování, repost),
 *   - {@see Bank\BankPostingService::entryDescription()} a
 *     {@see Bank\TransferPairService} (bankovní zápisy),
 *   - {@see Cash\CashDocumentService} (pokladna),
 *   - převody z POHODY a Money S3 ({@see composeParts()} bez DB),
 *   - zpětné dogenerování {@see JournalDescriptionRebuilder}.
 *
 * TVAR POPISU
 * ------------------------------------------------------------------------------
 * Segmenty oddělené {@see SEPARATOR}, vždy v pořadí „doklad — protistrana — detail":
 *
 *   FV 2099001234 — Odběratel s.r.o. — pronájem místa
 *   PF 2099-0007 / dod. VF-2099-88 — Dodavatel a.s. — leasing vozidla
 *   Banka 2099/004 — příchozí platba — Odběratel s.r.o. (VS 2099001234)
 *   PPD-2099-0042 — Pokladna Hlavní — Jan Novák — nákup kancelářských potřeb
 *
 * ZÁRUKY
 * ------------------------------------------------------------------------------
 * - DETERMINISMUS: popis je čistá funkce dat dokladu, takže idempotentní re-post
 *   ({@see PostingService::rewriteExisting()}) vygeneruje TÝŽ text.
 * - IDEMPOTENCE SKLÁDÁNÍ: už složený popis, vrácený zpátky jako `$detail`, dá TÝŽ
 *   výsledek — segmenty shodné s už umístěnými se zahazují ({@see composeParts()}).
 *   Na tom stojí opakované spuštění {@see JournalDescriptionRebuilder}.
 * - ŽÁDNÉ PRÁZDNÉ SEGMENTY: chybějící údaj (neznámá protistrana u nespárované
 *   platby) segment vynechá, nikdy nevznikne „— — ".
 * - DÉLKA: ořez na {@see MAX_LENGTH} (= šířka sloupce `journal_entries.description`,
 *   migrace 1005) na hranici slova, ne uprostřed.
 *
 * CO BUILDER NEDĚLÁ
 * ------------------------------------------------------------------------------
 * Skládá popis jen pro typy v {@see BUILDABLE}. Uzávěrkové, mzdové, majetkové a
 * zápočtové zápisy si popis tvoří u zdroje z dat, která v deníku nejsou
 * („Uzavření účetních knih 2099", „Daňový odpis HIM — …", „Zápočet ZAP-1 proti
 * účtu 311"); ty už číslo i věcný obsah nesou a builder by je jen zduplikoval.
 * Rozdíl je záměr, ne drift.
 *
 * Popis, který uživatel zadal ručně (dialog zaúčtování, ruční zápis, inline editace
 * přes {@see \MyInvoice\Repository\JournalEntryRepository::updateDescription()}),
 * builder NIKDY nepřepisuje — volající mu takový text předává jako `$detail`,
 * nebo ho použije beze změny.
 */
final class JournalDescriptionBuilder
{
    /** Šířka sloupce `journal_entries.description` (migrace 1005). */
    public const MAX_LENGTH = 255;

    /** Oddělovač segmentů. Shodný s oddělovačem, který používal starý bankovní popis. */
    public const SEPARATOR = ' — ';

    /** Nejdelší volný text z dokladu v jednom segmentu (aby detail nesežral celý popis). */
    public const MAX_DETAIL = 90;

    /** Typy zápisů, pro které builder umí sestavit popis z dokladu. */
    public const BUILDABLE = ['invoice', 'purchase_invoice', 'bank', 'cash'];

    /**
     * Texty, které nenesou informaci a do popisu nepatří — dosavadní zástupné popisy
     * z importů a z bankovní cesty bez protistrany i bez zprávy.
     */
    private const NOISE = [
        'účetní zápis z money s3',
        'účetní zápis z pohody',
        'bez popisu',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Popis zápisu podle zdrojového dokladu. Vrací NULL, když doklad neexistuje,
     * nepatří tenantovi, nebo typ zápisu není v {@see BUILDABLE} — volající si pak
     * nechá vlastní text.
     *
     * `$detail` je volný text, který má doplnit věcný obsah (zpráva pro příjemce,
     * popis z pravidla automatiky, dosavadní popis zápisu při zpětném dogenerování).
     * Když chybí, vezme se detail z dokladu samotného.
     */
    public function forSource(int $supplierId, string $sourceType, ?int $sourceId, ?string $detail = null): ?string
    {
        if ($sourceId === null || $sourceId <= 0) {
            return null;
        }

        return match ($sourceType) {
            'invoice'          => $this->forInvoice($supplierId, $sourceId, $detail),
            'purchase_invoice' => $this->forPurchaseInvoice($supplierId, $sourceId, $detail),
            'bank'             => $this->forBankTransaction($supplierId, $sourceId, $detail),
            'cash'             => $this->forCashDocument($supplierId, $sourceId, $detail),
            default            => null,
        };
    }

    /** `FV 2099001234 — Odběratel s.r.o. — pronájem místa` */
    public function forInvoice(int $supplierId, int $invoiceId, ?string $detail = null): ?string
    {
        $row = $this->one(
            'SELECT i.id, i.varsymbol, i.invoice_type, i.client_snapshot,
                    c.company_name, c.first_name, c.last_name
               FROM invoices i
               LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.id = ? AND i.supplier_id = ?',
            [$invoiceId, $supplierId],
        );
        if ($row === null) {
            return null;
        }

        // Textový řádek (item_kind 'text') není plnění, popis bere první skutečná položka.
        $textFilter = $this->db->hasColumn('invoice_items', 'item_kind') ? " AND item_kind <> 'text'" : '';
        $detail = self::clean($detail) ?? $this->firstItemText(
            'SELECT description FROM invoice_items WHERE invoice_id = ?' . $textFilter
                . ' ORDER BY order_index ASC, id ASC LIMIT 1',
            [$invoiceId],
        );

        return self::composeParts([
            self::issuedLabel((string) ($row['invoice_type'] ?? 'invoice')) . ' ' . self::docNumber($row['varsymbol'], $invoiceId),
            DocumentPartnerName::from($row, 'client_snapshot'),
            $detail,
        ]);
    }

    /** `PF 2099-0007 / dod. VF-2099-88 — Dodavatel a.s. — leasing vozidla` */
    public function forPurchaseInvoice(int $supplierId, int $purchaseInvoiceId, ?string $detail = null): ?string
    {
        $row = $this->one(
            'SELECT p.id, p.varsymbol, p.vendor_invoice_number, p.document_kind, p.vendor_snapshot,
                    c.company_name, c.first_name, c.last_name
               FROM purchase_invoices p
               LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              WHERE p.id = ? AND p.supplier_id = ?',
            [$purchaseInvoiceId, $supplierId],
        );
        if ($row === null) {
            return null;
        }

        $own    = self::clean($row['varsymbol']);
        $vendor = self::clean($row['vendor_invoice_number']);
        // Vlastní číslo řady, jinak aspoň dodavatelské — interní id („#123") je
        // poslední záchrana, ne první volba: v deníku nikomu nic neřekne.
        $head = self::receivedLabel((string) ($row['document_kind'] ?? 'invoice'))
            . ' ' . ($own ?? $vendor ?? ('#' . $purchaseInvoiceId));
        // Dodavatelské číslo se přidá jen když nese NOVOU informaci — účetní podle něj
        // doklad dohledá u dodavatele, ale zdvojené vlastní číslo je jen šum.
        if ($own !== null && $vendor !== null && self::normalize($vendor) !== self::normalize($own)) {
            $head .= ' / dod. ' . $vendor;
        }

        $detail = self::clean($detail) ?? $this->firstItemText(
            'SELECT description FROM purchase_invoice_items WHERE purchase_invoice_id = ?
              ORDER BY order_index ASC, id ASC LIMIT 1',
            [$purchaseInvoiceId],
        );

        return self::composeParts([
            $head,
            DocumentPartnerName::from($row, 'vendor_snapshot'),
            $detail,
        ]);
    }

    /** `Banka 2099/004 — příchozí platba — Odběratel s.r.o. (VS 2099001234)` */
    public function forBankTransaction(int $supplierId, int $txId, ?string $detail = null): ?string
    {
        // bank_transactions NEMÁ supplier_id — tenant se vynucuje JOINem na výpis
        // (stejně jako v JournalSourceSummaryService::bank()).
        $row = $this->one(
            'SELECT t.id, t.amount, t.variable_symbol, t.counterparty_account, t.counterparty_bank,
                    t.counterparty_name, t.description,
                    s.statement_number, s.account_number, s.bank_code
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ?',
            [$txId, $supplierId],
        );

        return $row === null ? null : self::forBankRow($row, $detail);
    }

    /**
     * Táž skládačka nad UŽ NAČTENÝM řádkem pohybu — bankovní cesta si pohyb načítá
     * sama (a ve své vlastní podobě), takže druhý dotaz do DB by byl jen režie navíc.
     * Identifikaci výpisu vezme z čehokoli, co řádek nese: číslo výpisu, jinak číslo
     * bankovního účtu; nic z toho → zůstane jen „Banka".
     *
     * @param array<string,mixed> $tx řádek `bank_transactions` (volitelně + výpis)
     */
    public static function forBankRow(array $tx, ?string $detail = null): string
    {
        $statement = self::clean($tx['statement_number'] ?? null)
            ?? self::joinAccount($tx['account_number'] ?? null, $tx['bank_code'] ?? null)
            ?? self::joinAccount($tx['recipient_account'] ?? null, $tx['recipient_bank'] ?? null);
        $head = $statement !== null ? 'Banka ' . $statement : 'Banka';

        // Protistrana: název, jinak alespoň protiúčet — u nespárované platby je to
        // jediné, co plátce identifikuje.
        $partner = self::clean($tx['counterparty_name'] ?? null)
            ?? self::joinAccount($tx['counterparty_account'] ?? null, $tx['counterparty_bank'] ?? null);
        $vs = self::clean($tx['variable_symbol'] ?? null);
        if ($vs !== null) {
            $partner = $partner !== null ? $partner . ' (VS ' . $vs . ')' : 'VS ' . $vs;
        }

        // clean() i na dodaném detailu: volající často předává '' (prázdný popis
        // pravidla), a to musí spadnout na zprávu z pohybu, ne detail vypnout.
        $detail = self::clean($detail) ?? self::clean($tx['description'] ?? null);

        return self::composeParts([
            $head,
            (float) ($tx['amount'] ?? 0) >= 0 ? 'příchozí platba' : 'odchozí platba',
            $partner,
            $detail,
        ]);
    }

    /** `PPD-2099-0042 — Pokladna Hlavní — Jan Novák — nákup kancelářských potřeb` */
    public function forCashDocument(int $supplierId, int $cashDocumentId, ?string $detail = null): ?string
    {
        $row = $this->one(
            'SELECT d.id, d.doc_type, d.doc_number, d.partner_name, d.description,
                    r.name AS register_name
               FROM cash_documents d
               LEFT JOIN cash_registers r ON r.id = d.register_id AND r.supplier_id = d.supplier_id
              WHERE d.id = ? AND d.supplier_id = ?',
            [$cashDocumentId, $supplierId],
        );
        return $row === null ? null : self::forCashRow($row, $detail);
    }

    /**
     * Táž skládačka nad UŽ NAČTENÝM pokladním dokladem — číslo dokladu se přiděluje
     * až při prvním zaúčtování, takže v okamžiku postu ještě není v databázi a druhý
     * dotaz by vrátil `doc_number = NULL`.
     *
     * @param array<string,mixed> $doc řádek `cash_documents` (+ `register_name`)
     */
    public static function forCashRow(array $doc, ?string $detail = null): string
    {
        // doc_number už typ dokladu nese (PPD-2099-0001); koncept ho ještě nemá,
        // proto zástupné „PPD #12" — ať zápis nikdy nezačíná jen pomlčkou.
        $type   = (string) ($doc['doc_type'] ?? 'in') === 'out' ? 'VPD' : 'PPD';
        $number = self::clean($doc['doc_number'] ?? null) ?? ($type . ' #' . (int) ($doc['id'] ?? 0));

        $detail = self::clean($detail) ?? self::clean($doc['description'] ?? null);

        return self::composeParts([
            $number,
            self::clean($doc['register_name'] ?? null),
            self::clean($doc['partner_name'] ?? null),
            $detail,
        ]);
    }

    // ───────────────────────────────────────────────────── čisté skládání (bez DB) ──

    /**
     * Poskládá popis z částí. Volatelné bez DB, aby ho mohly použít i převody
     * (POHODA, Money S3), které deník píšou mimo {@see PostingService}.
     *
     * Pravidla:
     *  - prázdná / bílá část se zahodí (nikdy nevznikne „— — "),
     *  - část, která je JIŽ UMÍSTĚNÁ, se zahodí — proto lze bez následků poslat
     *    dosavadní popis zpátky jako detail (viz idempotence v hlavičce třídy),
     *  - poslední část (detail) se navíc rozpadá na vlastní segmenty podle
     *    {@see SEPARATOR}, takže se dedup uplatní i na složený text,
     *  - výsledek se ořízne na {@see MAX_LENGTH} na hranici slova.
     *
     * @param list<string|null> $parts  poslední prvek je volný detail
     */
    public static function composeParts(array $parts): string
    {
        $placed = [];
        $seen   = [];
        $last   = count($parts) - 1;

        foreach ($parts as $i => $part) {
            // Detail (poslední část) může sám nést oddělovače — rozpadni ho, ať se
            // dedup chytí i na už složeném popisu.
            $pieces = $i === $last ? explode(self::SEPARATOR, (string) $part) : [(string) $part];
            foreach ($pieces as $piece) {
                $piece = self::clean($piece);
                if ($piece === null || in_array(self::normalize($piece), self::NOISE, true)) {
                    continue;
                }
                if ($i === $last) {
                    $piece = self::shorten($piece, self::MAX_DETAIL);
                }
                if (self::alreadyPlaced($piece, $seen)) {
                    continue;
                }
                $placed[] = $piece;
                $seen[]   = self::normalize($piece);
            }
        }

        return self::truncate(implode(self::SEPARATOR, $placed));
    }

    /**
     * Ořez na šířku sloupce NA HRANICI SLOVA. Uřezané slovo v deníku vypadá jako
     * poškozený zápis, proto se řeže na poslední mezeře a doplní výpustka.
     */
    public static function truncate(string $text, int $max = self::MAX_LENGTH): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $pos = mb_strrpos($cut, ' ');
        // Půlka limitu: u textu bez mezer (dlouhý identifikátor) je ořez na slově
        // horší než tvrdý — zbyl by z popisu pahýl.
        if ($pos !== false && $pos >= (int) ($max / 2)) {
            $cut = mb_substr($cut, 0, $pos);
        }

        return self::trimTail($cut) . '…';
    }

    /** Kratší varianta pro jeden segment — stejná pravidla, jen jiný strop. */
    public static function shorten(string $text, int $max): string
    {
        return self::truncate($text, $max);
    }

    /** Text bez bílých znaků navíc; prázdný → NULL. */
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = preg_replace('/\s+/u', ' ', trim((string) $value));

        return ($text === null || $text === '') ? null : $text;
    }

    // ─────────────────────────────────────────────────────────────── popisky typů ──

    /** Zkratka vydaného dokladu; „FV" je to, jak doklad v deníku pojmenuje účetní. */
    public static function issuedLabel(string $invoiceType): string
    {
        return match ($invoiceType) {
            'credit_note'      => 'Dobropis FV',
            'cancellation'     => 'Storno FV',
            'proforma'         => 'Proforma',
            'tax_document'     => 'DD k platbě',
            'penalty'          => 'Penalizační FV',
            'payment_calendar', 'rent_calendar' => 'Splátkový kalendář',
            'advance_calendar' => 'Platební kalendář',
            default            => 'FV',
        };
    }

    /** Zkratka přijatého dokladu. */
    public static function receivedLabel(string $documentKind): string
    {
        return match ($documentKind) {
            'credit_note'  => 'Dobropis PF',
            'receipt'      => 'Účtenka',
            'advance'      => 'Záloha PF',
            'tax_document' => 'DD k platbě',
            default        => 'PF',
        };
    }

    // ─────────────────────────────────────────────────────────────────── interní ──

    /** Číslo dokladu, jinak zástupné „#id" — popis nikdy nezůstane bez identifikace. */
    private static function docNumber(mixed $number, int $id): string
    {
        return self::clean($number) ?? ('#' . $id);
    }

    /** „1000000005/0100" z čísla účtu a kódu banky; bez čísla účtu NULL. */
    private static function joinAccount(mixed $account, mixed $bankCode): ?string
    {
        $account = self::clean($account);
        if ($account === null) {
            return null;
        }
        $bank = self::clean($bankCode);

        return $bank !== null ? $account . '/' . $bank : $account;
    }

    /**
     * Je segment už v popisu? Kromě shody bere i segment uříznutý výpustkou jako
     * duplicitu svého celého tvaru — bez toho by opakované dogenerování popisu,
     * který se do sloupce nevešel, pokaždé vrátilo jiný text.
     *
     * @param list<string> $seen normalizované už umístěné segmenty
     */
    private static function alreadyPlaced(string $piece, array $seen): bool
    {
        $norm = self::normalize($piece);
        if (in_array($norm, $seen, true)) {
            return true;
        }
        if (!str_ends_with($piece, '…')) {
            return false;
        }
        $stem = self::normalize((string) preg_replace('/…+$/u', '', $piece));
        if (mb_strlen($stem) < 4) {
            return false;
        }
        foreach ($seen as $s) {
            if (str_starts_with($s, $stem)) {
                return true;
            }
        }

        return false;
    }

    /** Porovnávací tvar segmentu — velikost písmen ani mezery nerozlišují popis. */
    private static function normalize(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /** Odřízne koncovou interpunkci a pomlčky, ať výpustka nenavazuje na „ — ". */
    private static function trimTail(string $text): string
    {
        return (string) preg_replace('/[\s\p{Pd},;:.]+$/u', '', $text);
    }

    /** První řádek dokladu jako věcný obsah případu. */
    private function firstItemText(string $sql, array $params): ?string
    {
        $row = $this->one($sql, $params);

        return $row === null ? null : self::clean($row['description'] ?? null);
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
