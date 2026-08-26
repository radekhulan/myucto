<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Oss\OssItemCarryOver;

/**
 * Vytvoří DRAFT finální faktury (typu `invoice`) k zaplacené proformě.
 *
 * Caller je zodpovědný za:
 *   - ověření vlastnictví (SupplierGuard)
 *   - ověření stavu (proforma musí být `paid` v okamžiku volání nebo v rámci
 *     stejné transakce před voláním)
 *
 * Idempotence: pokud už existuje child faktura (`parent_invoice_id = proformaId`,
 * `invoice_type = 'invoice'`), vrátí její id a nevytvoří duplikát.
 *
 * Bezpečné vůči vnořeným transakcím — pokud caller už má otevřenou transakci,
 * neotevírá vlastní a neflushuje.
 *
 * ── OSS se PŘENÁŠÍ ze zdrojových dokladů, nederivuje se znovu ───────────────────────
 * Vyúčtovací faktura je TOTÉŽ plnění TÉMUŽ odběrateli jako proforma, jen zapsané
 * daňovým dokladem — místo plnění se změnit nemůže. Do sjednocení se ale OSS sloupce
 * nekopírovaly vůbec, takže se OSS řádek proformy stal na vyúčtování TUZEMSKÝM a cizí
 * daň skončila na ř. 1 českého přiznání. Přenos (a proč ne derivace) řeší
 * {@see OssItemCarryOver}; platí pro OBOJE řádky dokladu:
 *   - zkopírované položky proformy → OSS profil položky proformy,
 *   - záporné odpočtové řádky § 37a → OSS profil řádku daňového dokladu, který
 *     odečítají. Bez toho by kladná polovina opravy ležela v OSS podání a záporná
 *     v tuzemském přiznání.
 */
final class FinalFromProformaCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoiceCalculator $calc,
        private readonly AdvanceCycleLock $cycleLock,
        private readonly OssItemCarryOver $ossCarry,
    ) {}

    /**
     * @param int         $proformaId  ID proformy (musí mít invoice_type='proforma')
     * @param int         $userId      created_by; 0 = systémová akce (auto-match)
     * @param string|null $taxDate     YYYY-MM-DD; default = dnes
     * @param string|null $dueDate     YYYY-MM-DD; default = dnes
     * @param float|null  $advance     Výše odečtu zálohy; default = total_with_vat proformy
     * @param float|null  $finalTotal  Celková cena zakázky ve stejném základu, v jakém
     *                                 jsou ceny proformy (netto / brutto podle
     *                                 `prices_include_vat`). Je-li vyšší než součet
     *                                 zkopírovaných položek, doplní se rozdílový řádek —
     *                                 viz {@see self::appendRemainder()}. null = jen
     *                                 rozsah proformy (dosavadní chování).
     * @return int  ID nového draftu (nebo již existující final faktury)
     */
    public function create(
        int $proformaId,
        int $userId = 0,
        ?string $taxDate = null,
        ?string $dueDate = null,
        ?float $advance = null,
        ?float $finalTotal = null,
    ): int {
        return $this->cycleLock->synchronized(
            $proformaId,
            fn (): int => $this->createUnlocked($proformaId, $userId, $taxDate, $dueDate, $advance, $finalTotal),
        );
    }

    private function createUnlocked(
        int $proformaId,
        int $userId,
        ?string $taxDate,
        ?string $dueDate,
        ?float $advance,
        ?float $finalTotal = null,
    ): int {
        $proforma = $this->repo->find($proformaId);
        if ($proforma === null) {
            throw new \RuntimeException("Proforma {$proformaId} nenalezena.");
        }
        if (($proforma['invoice_type'] ?? '') !== 'proforma') {
            throw new \RuntimeException("Faktura {$proformaId} není zálohová.");
        }

        $pdo = $this->db->pdo();

        // Idempotence — pokud už existuje child final, vrátit její id
        $existing = $pdo->prepare(
            "SELECT id FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'invoice'
                AND status <> 'cancelled'
              ORDER BY id LIMIT 1"
        );
        $existing->execute([$proformaId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $taxDate = $taxDate ?? date('Y-m-d');
        $dueDate = $dueDate ?? date('Y-m-d');

        VatRateValidityGuard::assertValidOn(
            $pdo,
            array_column(
                array_filter(
                    $proforma['items'],
                    static fn (array $item): bool => (float) ($item['total_with_vat'] ?? 0) > 0.0,
                ),
                'vat_rate_id',
            ),
            $taxDate,
        );

        // Daňové doklady k přijatým platbám proformy (§ 37a ZDPH): jejich základ/daň
        // se na vyúčtování odečte zápornými řádky per doklad per sazba — daň na finálu
        // pak vychází jen ze zbytku (už zdaněná část se nedaní podruhé). Drafty a
        // storna se nepočítají (nejsou daňovým dokladem).
        // Doklady hledáme přes parent_invoice_id I přes vazbu plateb — kdyby vazba
        // parent chyběla (historicky rozpojený doklad), odpočet nesmí vypadnout.
        // OSS profil vstupuje do SELECT i do GROUP BY: dva řádky daňového dokladu se
        // stejnou sazbou, ale různým místem plnění (OSS do PL × tuzemský), se nesmí
        // slít do jednoho odpočtového řádku — u zákazníkovy konfigurace mají i totéž
        // `vat_rate_id`, protože polská 23% sazba je ve `vat_rates` vedená se zemí CZ.
        $ossSelect = $this->ossCarry->selectList('ii');
        $tdStmt = $pdo->prepare(
            "SELECT td.id, td.varsymbol, ii.vat_rate_id, ii.vat_rate_snapshot{$ossSelect},
                    SUM(ii.total_without_vat) AS base, SUM(ii.total_vat) AS vat,
                    SUM(ii.total_with_vat) AS gross
               FROM invoices td
               JOIN invoice_items ii ON ii.invoice_id = td.id
              WHERE td.invoice_type = 'tax_document'
                AND td.status NOT IN ('draft', 'cancelled')
                AND (td.parent_invoice_id = ?
                     OR td.id IN (SELECT p.tax_document_invoice_id FROM invoice_payments p
                                   WHERE p.invoice_id = ? AND p.tax_document_invoice_id IS NOT NULL))
           GROUP BY td.id, td.varsymbol, ii.vat_rate_id, ii.vat_rate_snapshot{$ossSelect}
           ORDER BY td.id, ii.vat_rate_snapshot DESC"
        );
        $tdStmt->execute([$proformaId, $proformaId]);
        $taxDocRates = $tdStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $taxDocGross = 0.0;
        foreach ($taxDocRates as $r) {
            $taxDocGross += (float) $r['gross'];
        }
        $taxDocGross = round($taxDocGross, 2);

        if ($advance === null) {
            $paidTotal = (float) ($proforma['paid_total'] ?? 0);
            if ($paidTotal > 0 || $taxDocGross > 0) {
                // Odpočet „zálohy" = přijaté platby BEZ vlastního daňového dokladu
                // (platby s dokladem se odečítají zápornými řádky výše — jinak 2×).
                $advance = max(0.0, round($paidTotal - $taxDocGross, 2));
            } else {
                // Legacy: zaplacená proforma bez evidence plateb → plná záloha.
                $advance = (float) $proforma['total_with_vat'];
            }
        } elseif ($taxDocGross > 0) {
            // Explicitní advance z API nese historickou sémantiku „celkem zaplacená
            // záloha" — část krytou daňovými doklady ale odečítají záporné řádky výše;
            // bez korekce by se odečetla dvakrát (záporný amount_to_pay).
            $advance = max(0.0, round($advance - $taxDocGross, 2));
        }
        if ($advance < 0) {
            throw new \RuntimeException('Záloha nesmí být záporná.');
        }

        $noteAbove = ($proforma['language'] ?? 'cs') === 'en'
            ? "Tax document for advance invoice {$proforma['varsymbol']}"
            : "Daňový doklad k zálohové faktuře {$proforma['varsymbol']}";

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, advance_paid_amount, discount_percent, payment_method,
                    revenue_category_id, status, created_by)
                 VALUES ("invoice", ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $proformaId,
                $proforma['client_id'],
                $proforma['project_id'],
                (int) $proforma['supplier_id'],
                $proforma['branding_profile_id'] ?? null,
                $taxDate,
                $dueDate,
                (int) $proforma['currency_id'],
                $proforma['reverse_charge'] ? 1 : 0,
                // Režim „ceny s DPH" musí dědit z proformy — jinak by se zkopírované brutto
                // jednotkové ceny přepočítaly jako netto a daňový doklad by měl nafouknuté totály.
                !empty($proforma['prices_include_vat']) ? 1 : 0,
                $proforma['language'],
                $noteAbove,
                // Poznámku „pod položkami" zdědíme z proformy (text nad položkami nahrazuje
                // marker daňového dokladu, ale spodní poznámka uživatele se má zachovat).
                $proforma['note_below_items'] ?? null,
                $advance,
                (float) ($proforma['discount_percent'] ?? 0),
                (string) ($proforma['payment_method'] ?? 'bank_transfer'),
                // Kategorii tržby zdědíme z proformy (daňový doklad patří do stejné kategorie).
                $proforma['revenue_category_id'] ?? null,
                $userId ?: null,
            ]);
            $finalId = (int) $pdo->lastInsertId();

            // Položky kopírujeme včetně případné slevové (item_kind='discount') —
            // zachová částku po slevě. Marker item_kind umožní pozdější re-save přepočítat.
            // OSS sloupce se skládají místo ručně psaných variant SQL — guard na příznak
            // „k ručnímu posouzení" je samostatný, takže variant by jinak byly tři a počet
            // otazníků by se s nimi rozešel (shodně s `InvoiceRepository::replaceItems()`).
            $ossColumns = $this->ossCarry->columns();
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, item_kind,
                    stock_item_id, warehouse_id, small_asset_id, asset_id'
                . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
                . ') VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?'
                . $this->ossCarry->placeholders()
                . ')'
            );
            $maxOrder = 0;
            foreach ($proforma['items'] as $item) {
                $itemStmt->execute([
                    $finalId,
                    $item['description'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    (string) ($item['item_kind'] ?? 'standard'),
                    // Proforma → finál přenáší vazbu na skladovou kartu (Epic SKLAD §5.4:
                    // sklad hýbe až finál, proto vazba nesmí cestou zaniknout).
                    $item['stock_item_id'] ?? null,
                    $item['warehouse_id'] ?? null,
                    // Stejný důvod u prodeje majetku (1177): kartu uzavírá až vyúčtovací
                    // faktura (proforma není doklad o prodeji), takže vazba musí přejít s ní.
                    $item['small_asset_id'] ?? null,
                    $item['asset_id'] ?? null,
                    // Místo plnění se vyúčtováním nemění — přenáší se z proformy, protože
                    // ta už derivací i případnou ruční opravou prošla (viz OssItemCarryOver).
                    ...$this->ossCarry->values($item),
                ]);
                $maxOrder = max($maxOrder, (int) $item['order_index']);
            }

            // Doplatek zakázky: proforma bývá jen DÍLČÍ akontace (70 000 Kč ze zakázky
            // za 100 000 Kč). Kopie jejích položek proto popisuje jen rozsah zálohy —
            // po odečtu § 37a by vyúčtování vyšlo na nulu a zbytek by uživatel dopisoval
            // ručně (issue #39). Zadá-li celkovou cenu, doplní se rozdílový řádek.
            //
            // Částka se ZÁMĚRNĚ nebere z `projects.budget_total`: ten se v reportu
            // ziskovosti porovnává s NÁKLADY, je to tedy nákladový rozpočet, ne sjednaná
            // cena. Odvodit fakturovanou částku z rozpočtu nákladů by bylo tiše špatně.
            if ($finalTotal !== null) {
                $maxOrder = $this->appendRemainder(
                    $itemStmt,
                    $finalId,
                    (array) $proforma['items'],
                    $finalTotal,
                    !empty($proforma['prices_include_vat']),
                    ($proforma['language'] ?? 'cs') === 'en',
                    $maxOrder,
                );
            }

            // Záporné odpočtové řádky za vystavené daňové doklady k platbám (§ 37a):
            // v režimu cen s DPH jde do unit_price brutto dokladu (DPH shora si dopočte
            // InvoiceMath), v režimu netto jde základ (DPH zdola z rozdílu základů —
            // přesně dikce § 37a, případný haléřový rozdíl proti koeficientu je legální).
            $grossMode = !empty($proforma['prices_include_vat']);
            $isEn = ($proforma['language'] ?? 'cs') === 'en';
            foreach ($taxDocRates as $r) {
                $unitPrice = $grossMode ? -(float) $r['gross'] : -(float) $r['base'];
                if ($unitPrice === 0.0) {
                    continue;
                }
                $desc = $isEn
                    ? "Advance deduction — tax document {$r['varsymbol']}"
                    : "Odpočet zálohy — daňový doklad {$r['varsymbol']}";
                $itemStmt->execute([
                    $finalId,
                    $desc,
                    1,
                    '',
                    $unitPrice,
                    (int) $r['vat_rate_id'],
                    $r['vat_rate_snapshot'],
                    ++$maxOrder,
                    'standard',
                    // Odpočtový řádek není zboží ani majetek — bez skladové vazby i bez karty.
                    null,
                    null,
                    null,
                    null,
                    // Zato OSS profil nese: odpočet ruší přesně tu daň, kterou přiznal
                    // daňový doklad k platbě, takže musí jít do TÉŽE evidence. Jinak by
                    // kladná polovina zůstala v OSS podání a záporná spadla na ř. 1
                    // tuzemského přiznání — daň by se odečetla dvakrát v jedné zemi
                    // a vůbec ve druhé.
                    ...$this->ossCarry->values($r),
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->calc->recompute($finalId);
        return $finalId;
    }

    /**
     * Doplní řádek na rozdíl mezi celkovou cenou zakázky a rozsahem zkopírované proformy.
     *
     * Sazbu bere z NEJVĚTŠÍHO řádku proformy, ne z prvního: u vícesazbové zálohy je
     * dominantní sazba jediný odhad, který dává smysl, a uživatel ho stejně vidí
     * v konceptu a může ho přepsat. Řádek se proto i výslovně pojmenuje, aby bylo
     * poznat, že jde o dopočet, ne o položku opsanou z proformy.
     *
     * Rozdíl <= 0 (zadaná cena nepřevyšuje zálohu) je legitimní stav, ne chyba —
     * neděláme nic a vyúčtování zůstane v rozsahu proformy.
     *
     * @param  array<array-key,array<string,mixed>> $items  položky proformy
     * @return int  nový nejvyšší order_index
     */
    private function appendRemainder(
        \PDOStatement $itemStmt,
        int $finalId,
        array $items,
        float $finalTotal,
        bool $grossMode,
        bool $isEn,
        int $maxOrder,
    ): int {
        $covered = 0.0;
        $dominant = null;
        $dominantAmount = -1.0;
        foreach ($items as $item) {
            $amount = (float) ($grossMode ? ($item['total_with_vat'] ?? 0) : ($item['total_without_vat'] ?? 0));
            $covered += $amount;
            if ($amount > $dominantAmount) {
                $dominantAmount = $amount;
                $dominant = $item;
            }
        }

        $remainder = round($finalTotal - $covered, 2);
        if ($remainder <= 0.0 || $dominant === null) {
            return $maxOrder;
        }

        $itemStmt->execute([
            $finalId,
            $isEn ? 'Remaining scope of the contract' : 'Doplatek zakázky',
            1,
            $isEn ? 'pcs' : 'ks',
            $remainder,
            $dominant['vat_rate_id'],
            $dominant['vat_rate_snapshot'],
            ++$maxOrder,
            'standard',
            // Dopočtený řádek není konkrétní zboží ani majetek — bez vazeb.
            null,
            null,
            null,
            null,
            // OSS profil dědí po dominantním řádku: zbytek téže zakázky patří do téže
            // evidence jako to, co už bylo fakturováno zálohou.
            ...$this->ossCarry->values($dominant),
        ]);

        return $maxOrder;
    }
}
