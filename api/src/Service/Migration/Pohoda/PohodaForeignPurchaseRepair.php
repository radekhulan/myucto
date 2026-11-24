<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyDecision;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;

/**
 * Oprava přijatých dokladů v cizí měně se samovyměřením DPH, které převod z POHODY dřív
 * převzal v Kč s důvodem „samovyměření DPH - zdroj daň vyměřil kurzem ke dni plnění".
 *
 * Měnu dokladu databáze zná jen z poznámky, kurz ani částku v měně ne. Kurz ČNB je nepozná:
 * POHODA bere kurz dokladu k různým datům (vystavení, DUZP dodavatele) i ručně. Oprava proto
 * čte původní export z POHODY (ZIP rozbalený do adresáře, jako při převodu): doklad najde
 * podle klíče mapy převodu a vezme měnu, kurz a celkem v měně ze souhrnu dokladu.
 *
 * Doklad se převede stejně jako při novém převodu ({@see PohodaSelfAssessedCurrency}): řádek
 * samovyměření a položka rozdílu kurzu se sloučí do jedné položky v měně, základ DPH v Kč
 * ze zdroje zůstane na položce, když vyšel jiným kurzem. Pojistka kurzu
 * ({@see ForeignCurrencyTakeover::check()}) musí potvrdit, že částka v měně kurzem dokladu
 * dá přesně Kč dokladu. Deník (převzatý z POHODY), úhrady, párování ani stav dokladu se
 * nemění; spárované platby a zápočty jsou v měně pohybu a deníku (Kč) stejně jako u dokladů,
 * které převod v měně převzal rovnou.
 *
 * Pojistky:
 *  - export: IČO firmy, každého souboru exportu i agendy a roku běhu převodu, který doklad
 *    založil, musí sedět ({@see exportProblem()});
 *  - doklad v uzavřeném, rozpracovaném či zamčeném období nebo v podaném období DPH (podle
 *    vystavení, DUZP, odpočtu i data navázaného účetního zápisu) se nemění, jen ohlásí
 *    ({@see MigrationCheckLocks}); znovu se to ověří v transakci pod zámkem období a podání
 *    těsně před zápisem;
 *  - jen ke kontrole zůstává doklad se zálohou, zápočtem, úhradou mimo evidenci, úhradou
 *    v měně, úhradou bez bankovního pohybu či pokladního dokladu (peněžní deník by ji
 *    v měně přecenil kurzem dne úhrady), rozpisem DPH a dimenzemi na položkách (sloučení
 *    položek by je posunulo);
 *  - evidence DPH, saldokonto v Kč a peněžní deník dotčených dokladů se před a po zápisu
 *    porovnají; doklad, kterému by se cokoli změnilo, se vrátí a ohlásí ke kontrole.
 * Nanečisto i naostro proběhne stejný zápis s porovnáním (kolo = samostatná transakce, bez
 * SAVEPOINT), nanečisto se pak vrátí. Každý převedený doklad má záznam v auditní stopě. Opakovaný běh
 * nic nemění.
 */
final class PohodaForeignPurchaseRepair
{
    public const AUDIT_ACTION = 'purchase_invoice.pohoda_foreign_currency_repaired';

    /** Poznámka dokladu převzatého v Kč kvůli samovyměření ({@see ForeignCurrencyTakeover::purchaseBlock()}). */
    private const NOTE_SQL = '%, převzat v Kč (samovyměření DPH%';
    private const NOTE_PATTERN = '/doklad v ([A-Z]{3}), převzat v Kč \(samovyměření DPH[^)]*\)/u';

    /** Postup u dokladu, který oprava nepřevede. */
    public const ADVICE = 'Doklad zůstává v Kč; DPH, KH i deník jsou správně. Měnu, kurz a částku v měně lze opravit ručně v detailu dokladu.';

    private readonly ForeignCurrencyTakeover $currencies;

    public function __construct(
        private readonly Connection $db,
        private readonly MigrationCheckLocks $locks,
        private readonly ActivityLogger $activity,
        private readonly VatLedgerService $ledger,
        private readonly SaldoService $saldo,
        private readonly CashJournalRepository $cashJournal,
    ) {
        $this->currencies = new ForeignCurrencyTakeover($db, createMissingCurrency: true);
    }

    /** Firmy s doklady převzatými kvůli samovyměření v Kč. @return list<int> */
    public function affectedSuppliers(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT pi.supplier_id FROM purchase_invoices pi
               JOIN pohoda_import_map m ON m.supplier_id = pi.supplier_id AND m.kind = ? AND m.target_id = pi.id
              WHERE pi.exchange_rate IS NULL AND pi.reverse_charge = 1 AND pi.note_below_items LIKE ? ORDER BY pi.supplier_id'
        );
        $stmt->execute([PohodaImportRepository::KIND_PURCHASE_INVOICE, self::NOTE_SQL]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * IČO firmy - export se k firmě přiřazuje podle IČO, ne podle pořadí.
     */
    public function supplierIco(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return PartnerImporter::ico((string) $stmt->fetchColumn());
    }

    /**
     * Doklady firmy k opravě. Bez exportu (`null`) jen najde doklady a ohlásí zámky - stav
     * `needs_export`; s exportem rozhodne o každém dokladu a s `$commit` převede.
     *
     * @return list<array{id:int,document_no:string,vendor_invoice_number:string,issue_date:string,tax_date:?string,currency:string,
     *                    status:string,reason:?string,total_czk:float,rate:?float,total_foreign:?float,tax_base_czk:?float,removed_items:int}>
     */
    public function run(int $supplierId, ?PohodaExport $export, bool $commit, ?int $userId = null): array
    {
        $docs = $this->candidates($supplierId);
        if ($docs === []) {
            return [];
        }
        $this->locks->reset();
        $exportProblem = $export !== null ? $this->exportProblem($supplierId, $export) : null;
        $sources = $export !== null && $exportProblem === null ? self::sources($export) : null;
        $items = $this->items($supplierId, array_column($docs, 'id'));
        $out = [];
        $plans = [];
        foreach ($docs as $d) {
            $row = [
                'id' => (int) $d['id'], 'document_no' => (string) $d['varsymbol'], 'vendor_invoice_number' => (string) $d['vendor_invoice_number'],
                'issue_date' => (string) $d['issue_date'], 'tax_date' => $d['tax_date'], 'currency' => $d['currency'],
                'status' => 'convert', 'reason' => null, 'total_czk' => (float) $d['total_with_vat'],
                'rate' => null, 'total_foreign' => null, 'tax_base_czk' => null, 'removed_items' => 0,
            ];
            $lock = $this->lock($supplierId, $d);
            if ($lock !== null) {
                $out[] = ['status' => 'locked', 'reason' => $lock] + $row;
                continue;
            }
            $blocked = self::blocked($d);
            if ($blocked !== null) {
                $out[] = ['status' => 'review', 'reason' => $blocked] + $row;
                continue;
            }
            if ($exportProblem !== null) {
                $out[] = ['status' => 'review', 'reason' => $exportProblem] + $row;
                continue;
            }
            if ($sources === null || $export === null) {
                $out[] = ['status' => 'needs_export', 'reason' => 'Oprava potřebuje původní export z POHODY.'] + $row;
                continue;
            }
            if ($d['agenda_ico'] !== null && ($d['agenda_ico'] !== $export->ico || (int) $d['agenda_year'] !== $export->year)) {
                $out[] = ['status' => 'review', 'reason' => sprintf('Doklad převedl běh převodu agendy IČO %s za rok %d, export je IČO %s za rok %d. %s',
                    $d['agenda_ico'], (int) $d['agenda_year'], $export->ico, $export->year, self::ADVICE)] + $row;
                continue;
            }
            $plan = self::plan($d, $items[(int) $d['id']] ?? [], $sources[(string) $d['pohoda_key']] ?? null);
            if (is_string($plan)) {
                $out[] = ['status' => 'review', 'reason' => $plan] + $row;
                continue;
            }
            $row = ['rate' => $plan['rate'], 'total_foreign' => $plan['total'], 'tax_base_czk' => $plan['tax_base_czk'],
                'removed_items' => count($plan['remove'])] + $row;
            $plans[(int) $d['id']] = $plan + ['doc' => $d];
            $out[] = $row;
        }
        if ($plans !== []) {
            $failed = $this->apply($supplierId, $plans, $commit, $userId);
            foreach ($out as $i => $row) {
                if (isset($failed[$row['id']])) {
                    [$status, $reason] = $failed[$row['id']];
                    $out[$i] = ['status' => $status, 'reason' => $reason, 'rate' => null, 'total_foreign' => null, 'tax_base_czk' => null, 'removed_items' => 0] + $row;
                } elseif ($commit && isset($plans[$row['id']])) {
                    $out[$i]['status'] = 'converted';
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.issue_date, pi.tax_date, pi.received_at, pi.total_without_vat, pi.total_vat,
                    pi.total_with_vat, pi.rounding, pi.advance_paid_amount, pi.settled_outside_amount, pi.payment_currency_id,
                    pi.paid_amount_invoice_ccy, pi.paid_amount_payment_ccy, pi.note_below_items, pi.status, pi.paid_at, m.pohoda_key,
                    r.agenda_ico, r.agenda_year,
                    EXISTS (SELECT 1 FROM purchase_invoice_vat_allocations a WHERE a.purchase_invoice_id = pi.id) AS has_allocations,
                    EXISTS (SELECT 1 FROM invoice_settlements st WHERE st.supplier_id = pi.supplier_id AND st.doc_type = 'purchase_invoice'
                                AND st.doc_id = pi.id AND st.status = 'confirmed') AS has_settlements,
                    EXISTS (SELECT 1 FROM offset_agreement_items o WHERE o.supplier_id = pi.supplier_id AND o.doc_type = 'purchase_invoice'
                                AND o.doc_id = pi.id) AS has_offsets,
                    EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id) AS has_bank,
                    EXISTS (SELECT 1 FROM cash_documents cd WHERE cd.purchase_invoice_id = pi.id) AS has_cash,
                    (EXISTS (SELECT 1 FROM document_dimensions dd WHERE dd.supplier_id = pi.supplier_id AND dd.doc_type = 'purchase_invoice'
                                AND dd.doc_id = pi.id AND dd.item_no > 0)
                     OR EXISTS (SELECT 1 FROM document_dimension_splits ds WHERE ds.supplier_id = pi.supplier_id AND ds.doc_type = 'purchase_invoice'
                                AND ds.doc_id = pi.id AND ds.item_no > 0)) AS has_item_dimensions,
                    (SELECT GROUP_CONCAT(DISTINCT je.entry_date) FROM journal_entries je
                      WHERE je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice' AND je.source_id = pi.id) AS entry_dates
               FROM purchase_invoices pi
               JOIN pohoda_import_map m ON m.supplier_id = pi.supplier_id AND m.kind = ? AND m.target_id = pi.id
          LEFT JOIN pohoda_imports r ON r.id = m.run_id AND r.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pi.exchange_rate IS NULL AND pi.reverse_charge = 1 AND pi.note_below_items LIKE ?
              ORDER BY pi.issue_date, pi.id"
        );
        $stmt->execute([PohodaImportRepository::KIND_PURCHASE_INVOICE, $supplierId, self::NOTE_SQL]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if (preg_match(self::NOTE_PATTERN, (string) $d['note_below_items'], $m) !== 1) {
                continue;
            }
            $d['currency'] = $m[1];
            $d['agenda_ico'] = $d['agenda_ico'] !== null && $d['agenda_ico'] !== '' ? PartnerImporter::ico((string) $d['agenda_ico']) : null;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private function items(int $supplierId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT i.id, i.purchase_invoice_id, i.description, i.quantity, i.vat_rate_snapshot AS rate, i.total_without_vat AS base,
                        i.total_vat AS vat, i.vat_classification_code AS code, i.import_tax_base_czk, i.import_tax_vat_czk, i.import_tax_excluded,
                        i.import_projection_vat_czk
                   FROM purchase_invoice_items i JOIN purchase_invoices pi ON pi.id = i.purchase_invoice_id AND pi.supplier_id = ?
                  WHERE i.purchase_invoice_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                  ORDER BY i.purchase_invoice_id, i.order_index, i.id'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $out[(int) $i['purchase_invoice_id']][] = $i;
            }
        }
        return $out;
    }

    /**
     * Doklady přijaté faktury z exportu podle klíče mapy převodu.
     *
     * @return array<string,array{key:string,number:string,foreign:string,foreign_rate:float,foreign_units:float,foreign_total:float}>
     */
    private static function sources(PohodaExport $export): array
    {
        $out = [];
        foreach (array_keys(InvoiceImporter::PURCHASE_AGENDAS) as $agenda) {
            foreach ($export->records($agenda, 'invoice') as $r) {
                $s = InvoiceImporter::purchaseSource($r, $agenda);
                $out[$s['key']] = $s;
            }
        }
        return $out;
    }

    /** Zámek dokladu: vystavení, DUZP, datum odpočtu i datum navázaného účetního zápisu. */
    private function lock(int $supplierId, array $d): ?string
    {
        $entries = $d['entry_dates'] !== null && $d['entry_dates'] !== '' ? explode(',', (string) $d['entry_dates']) : [];
        foreach ([$d['issue_date'], $d['tax_date'], $d['received_at'], ...$entries] as $date) {
            $reason = $this->locks->reason($supplierId, $date !== null ? (string) $date : null, true);
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }

    /** Doklad, jehož částky nebo úhrady na měnu dokladu navázané nejsou jen celkem. */
    private static function blocked(array $d): ?string
    {
        return match (true) {
            abs((float) $d['advance_paid_amount']) >= 0.005 => 'Doklad má odečtenou zálohu. ' . self::ADVICE,
            abs((float) $d['settled_outside_amount']) >= 0.005 => 'Doklad má úhradu mimo evidenci v Kč. ' . self::ADVICE,
            (bool) $d['has_settlements'] || (bool) $d['has_offsets'] => 'Doklad má zápočet nebo vyrovnání v Kč. ' . self::ADVICE,
            $d['payment_currency_id'] !== null || $d['paid_amount_invoice_ccy'] !== null || $d['paid_amount_payment_ccy'] !== null
                => 'Doklad má úhradu evidovanou v měně. ' . self::ADVICE,
            ($d['status'] === 'paid' || $d['paid_at'] !== null) && !(bool) $d['has_bank'] && !(bool) $d['has_cash']
                => 'Doklad je uhrazený bez bankovního pohybu i pokladního dokladu, úhrada v měně by se přecenila kurzem dne úhrady. ' . self::ADVICE,
            (bool) $d['has_allocations'] => 'Doklad má rozpis DPH. ' . self::ADVICE,
            (bool) $d['has_item_dimensions'] => 'Doklad má dimenze přiřazené položkám. ' . self::ADVICE,
            default => null,
        };
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param ?array{foreign:string,foreign_rate:float,foreign_units:float,foreign_total:float} $source
     * @return array{rate:float,total:float,tax_base_czk:?float,keep:int,remove:list<int>,quantity:float,merged:list<array<string,mixed>>}|string
     */
    private static function plan(array $d, array $items, ?array $source): array|string
    {
        if ($source === null) {
            return 'Doklad v exportu z POHODY není (jiný rok nebo jiná firma). ' . self::ADVICE;
        }
        if (strtoupper($source['foreign']) !== $d['currency']) {
            return sprintf('Export vede doklad v %s, poznámka v %s. %s', $source['foreign'] !== '' ? $source['foreign'] : 'Kč', $d['currency'], self::ADVICE);
        }
        foreach ($items as $i) {
            if ($i['import_tax_base_czk'] !== null || $i['import_tax_vat_czk'] !== null || $i['import_projection_vat_czk'] !== null || (int) $i['import_tax_excluded'] !== 0) {
                return 'Položky dokladu už nesou daň v Kč ze zdroje. ' . self::ADVICE;
            }
        }
        $shaped = array_map(static fn (array $i): array => [
            'id' => (int) $i['id'], 'description' => (string) $i['description'], 'quantity' => (float) $i['quantity'],
            'base' => (float) $i['base'], 'vat' => (float) $i['vat'], 'rate' => (float) $i['rate'], 'code' => $i['code'],
        ], $items);
        $merged = PohodaSelfAssessedCurrency::merge($shaped, $source['foreign_total']);
        if (is_string($merged)) {
            return ucfirst($merged) . '. ' . self::ADVICE;
        }
        $total = round((float) $d['total_with_vat'], 2);
        if (abs(round(array_sum(array_column($shaped, 'base')), 2) - $total) >= 0.005 || abs((float) $d['rounding']) >= 0.005) {
            return 'Položky dokladu nedávají celkem dokladu. ' . self::ADVICE;
        }
        $rate = ForeignCurrencyTakeover::rate($source['foreign_rate'], $source['foreign_units']);
        $reason = ForeignCurrencyTakeover::check($rate, $merged, $total);
        if ($reason !== null || $rate === null) {
            return sprintf('Částka v měně kurzem nedává Kč dokladu (%s). %s', $reason ?? 'doklad nemá kurz', self::ADVICE);
        }
        $keep = (int) $merged[0]['id'];
        return [
            'rate' => $rate,
            'total' => round($source['foreign_total'], 2),
            'tax_base_czk' => $merged[0]['tax_base_czk'],
            'keep' => $keep,
            'quantity' => (float) $merged[0]['quantity'] !== 0.0 ? (float) $merged[0]['quantity'] : 1.0,
            'remove' => array_values(array_filter(array_column($shaped, 'id'), static fn (int $id): bool => $id !== $keep)),
            'merged' => $merged,
        ];
    }

    /**
     * Zápis s porovnáním po kolech, každé kolo v samostatné transakci pod zámkem období
     * a podání: zámky dokladů se ověří znovu, doklady se převedou a evidence DPH, saldokonto
     * a peněžní deník se porovnají. Při rozdílu se celá transakce vrátí a další kolo začne
     * novou transakcí bez dokladů s rozdílem. Nanečisto se vrátí i kolo bez rozdílu, naostro
     * se ve stejné transakci zapíše auditní stopa a potvrdí.
     *
     * Bez SAVEPOINT: `purchase_invoice_items` má FULLTEXT index a InnoDB (MariaDB 12.3.3)
     * po ROLLBACK TO SAVEPOINT spadne na assertion ve fts0fts.cc, když transakce znovu smaže
     * nebo změní tentýž řádek. Proto oprava nesmí běžet v cizí transakci.
     *
     * @param array<int,array<string,mixed>> $plans
     * @return array<int,array{string,string}> doklad => [stav, důvod] u dokladů, které se nepřevedou
     */
    private function apply(int $supplierId, array $plans, bool $commit, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Oprava dokladů v cizí měně potřebuje vlastní transakce, nesmí běžet v cizí transakci.');
        }
        $failed = [];
        $done = false;
        for ($round = 0; $plans !== [] && $round < 3; $round++) {
            $pdo->beginTransaction();
            try {
                $this->lockPeriodState($supplierId);
                $this->locks->reset();
                foreach ($plans as $id => $plan) {
                    $lock = $this->lock($supplierId, $plan['doc']);
                    if ($lock !== null) {
                        $failed[$id] = ['locked', $lock];
                        unset($plans[$id]);
                    }
                }
                if ($plans === []) {
                    $pdo->rollBack();
                    $done = true;
                    break;
                }
                $ids = array_keys($plans);
                [$from, $to] = $this->range($supplierId, $ids);
                $before = $this->snapshot($supplierId, $from, $to, $ids);
                $decisions = $this->write($supplierId, $plans);
                $changed = self::changed($before, $this->snapshot($supplierId, $from, $to, $ids), $ids);
                if ($changed === []) {
                    if ($commit) {
                        $this->audit($supplierId, $plans, $decisions, $userId);
                        $pdo->commit();
                    } else {
                        $pdo->rollBack();
                    }
                    $done = true;
                    break;
                }
                $pdo->rollBack();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            foreach ($changed as $id => $what) {
                $failed[$id] = ['review', sprintf('Převod na měnu by změnil %s. %s', $what, self::ADVICE)];
                unset($plans[$id]);
            }
        }
        if (!$done) {
            foreach (array_keys($plans) as $id) {
                $failed[$id] = ['review', 'Ověření převodu se nepodařilo uzavřít ani bez dokladů s rozdílem. ' . self::ADVICE];
            }
        }
        return $failed;
    }

    /** Zámek nastavení, období a podání firmy do konce transakce (jako {@see \MyInvoice\Service\Migration\Check\MigrationDataCheckService}). */
    private function lockPeriodState(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('SELECT supplier_id FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
        $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
        $pdo->prepare('SELECT id FROM tax_submissions FORCE INDEX (idx_supplier_form_period) WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
    }

    /**
     * @param array<int,array<string,mixed>> $plans
     * @return array<int,ForeignCurrencyDecision>
     */
    private function write(int $supplierId, array $plans): array
    {
        $pdo = $this->db->pdo();
        $header = $pdo->prepare(
            'UPDATE purchase_invoices SET currency_id = ?, exchange_rate = ?, total_without_vat = ?, total_vat = 0, total_with_vat = ?, rounding = 0,
                    note_below_items = ?
              WHERE id = ? AND supplier_id = ? AND exchange_rate IS NULL AND reverse_charge = 1'
        );
        $item = $pdo->prepare(
            'UPDATE purchase_invoice_items SET unit_price_without_vat = ?, total_without_vat = ?, total_vat = 0, total_with_vat = ?, import_tax_base_czk = ?
              WHERE id = ? AND purchase_invoice_id = ?'
        );
        $remove = $pdo->prepare('DELETE FROM purchase_invoice_items WHERE id = ? AND purchase_invoice_id = ?');
        $order = $pdo->prepare('UPDATE purchase_invoice_items SET order_index = 0 WHERE id = ? AND purchase_invoice_id = ?');
        $out = [];
        foreach ($plans as $id => $plan) {
            $d = $plan['doc'];
            $fx = $this->currencies->decide($supplierId, (string) $d['currency'], (float) $plan['rate'], 1.0, $plan['merged'], (float) $d['total_with_vat']);
            if (!$fx->inForeignCurrency()) {
                throw new \RuntimeException(sprintf('Doklad %s nejde převést na %s: %s', $d['varsymbol'], $d['currency'], (string) $fx->reason));
            }
            $header->execute([$fx->currencyId, $fx->rate, $plan['total'], $plan['total'], self::note((string) $d['note_below_items'], $fx, $plan['tax_base_czk']), $id, $supplierId]);
            if ($header->rowCount() !== 1) {
                throw new \RuntimeException(sprintf('Doklad %s se mezitím změnil, firma %d se neopravuje.', $d['varsymbol'], $supplierId));
            }
            $item->execute([round($plan['total'] / $plan['quantity'], 6), $plan['total'], $plan['total'], $plan['tax_base_czk'], $plan['keep'], $id]);
            foreach ($plan['remove'] as $itemId) {
                $remove->execute([$itemId, $id]);
            }
            $order->execute([$plan['keep'], $id]);
            $out[$id] = $fx;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $plans
     * @param array<int,ForeignCurrencyDecision> $decisions
     */
    private function audit(int $supplierId, array $plans, array $decisions, ?int $userId): void
    {
        foreach ($plans as $id => $plan) {
            $d = $plan['doc'];
            $this->activity->log(self::AUDIT_ACTION, $userId, 'purchase_invoice', $id, [
                'source' => 'pohoda_foreign_purchase_repair',
                'document_no' => $d['varsymbol'],
                'vendor_invoice_number' => $d['vendor_invoice_number'],
                'currency' => $d['currency'],
                'exchange_rate' => $decisions[$id]->rate,
                'total_with_vat_before' => (float) $d['total_with_vat'],
                'total_with_vat_after' => $plan['total'],
                'tax_base_czk' => $plan['tax_base_czk'],
                'removed_items' => $plan['remove'],
                'note_before' => $d['note_below_items'],
            ], null, null, $supplierId);
        }
    }

    /**
     * Otisk dotčených dokladů po dokladech: evidence DPH (řádky přiznání a KH), saldokonto
     * v Kč (podvojné účetnictví, každé účetní období v rozsahu) a peněžní deník (daňová
     * evidence). Řádek peněžního deníku, který k dokladu nepatří, jde pod klíč 0.
     *
     * @param list<int> $ids
     * @return array<int,array<string,list<string>>> doklad => oblast => řádky
     */
    private function snapshot(int $supplierId, string $from, string $to, array $ids): array
    {
        $out = [];
        foreach ($this->ledgerSnapshot($supplierId, $from, $to, $ids) as $key => $sums) {
            $out[(int) strtok($key, '|')]['evidenci DPH'][] = $key . '=' . implode('/', $sums);
        }
        $wanted = array_fill_keys($ids, true);
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) $mode->fetchColumn() === 'double_entry') {
            $periods = $this->db->pdo()->prepare('SELECT id, ends_on FROM accounting_periods WHERE supplier_id = ? AND ends_on >= ? AND starts_on <= ? ORDER BY starts_on');
            $periods->execute([$supplierId, $from, max($to, date('Y-m-d'))]);
            foreach ($periods->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $asOf = min((string) $p['ends_on'], date('Y-m-d'));
                foreach ($this->saldo->build($supplierId, (int) $p['id'], $asOf)['accounts'] as $block) {
                    foreach ($block['partners'] ?? [] as $partner) {
                        foreach ($partner['items'] as $it) {
                            if ($it['doc_type'] === 'purchase_invoice' && isset($wanted[(int) $it['doc_id']])) {
                                $out[(int) $it['doc_id']]['saldokonto'][] = implode('|', [$p['id'], $block['account']['code'] ?? '',
                                    number_format((float) $it['booked_czk'], 2, '.', ''), number_format((float) $it['paid_czk'], 2, '.', ''),
                                    number_format((float) $it['remaining_czk'], 2, '.', '')]);
                            }
                        }
                    }
                }
            }
        } else {
            foreach ([false, true] as $payer) {
                foreach ($this->cashJournal->movements($supplierId, $from, max($to, date('Y-m-d')), $payer) as $m) {
                    $doc = $m['source_type'] === 'purchase_invoice' && isset($wanted[(int) $m['source_id']]) ? (int) $m['source_id'] : 0;
                    $out[$doc]['peněžní deník'][] = implode('|', [(int) $payer, $m['movement_date'], $m['source_type'], $m['source_id'], $m['direction'],
                        number_format((float) $m['amount'], 2, '.', '')]);
                }
            }
        }
        return $out;
    }

    /**
     * Doklady, kterým se otisk změnil; změna mimo doklady (klíč 0) vrací všechny.
     *
     * @param array<int,array<string,list<string>>> $before
     * @param array<int,array<string,list<string>>> $after
     * @param list<int> $ids
     * @return array<int,string> doklad => co se změnilo
     */
    private static function changed(array $before, array $after, array $ids): array
    {
        $out = [];
        foreach ([0, ...$ids] as $id) {
            foreach (['evidenci DPH', 'saldokonto', 'peněžní deník'] as $area) {
                $a = $before[$id][$area] ?? [];
                $b = $after[$id][$area] ?? [];
                sort($a);
                sort($b);
                if ($a !== $b) {
                    $out[$id] = $area;
                    break;
                }
            }
        }
        if (isset($out[0])) {
            return array_fill_keys($ids, $out[0]);
        }
        return $out;
    }

    /**
     * Export patří firmě: IČO agendy i každého souboru exportu je IČO firmy.
     */
    private function exportProblem(int $supplierId, PohodaExport $export): ?string
    {
        $ico = $this->supplierIco($supplierId);
        if ($export->ico === '' || $export->ico !== $ico) {
            return sprintf('Export je agenda IČO %s, firma má IČO %s. %s', $export->ico, $ico, self::ADVICE);
        }
        foreach ($export->files() as $f) {
            if ($f['exists'] && $f['ico'] !== '' && $f['ico'] !== $export->ico) {
                return sprintf('Soubor exportu %s je z agendy IČO %s, ne %s. %s', $f['file'], $f['ico'], $export->ico, self::ADVICE);
            }
        }
        return null;
    }

    /** Poznámka dokladu: věta o převzetí v Kč se nahradí větou o převzetí v měně. */
    private static function note(string $note, ForeignCurrencyDecision $fx, ?float $taxBase): string
    {
        $sentence = (string) $fx->note();
        $taxNote = PohodaSelfAssessedCurrency::note($taxBase, $fx->currency);
        if ($taxNote !== null) {
            $sentence .= '; ' . $taxNote;
        }
        return (string) preg_replace(self::NOTE_PATTERN, $sentence, $note, 1);
    }

    /**
     * Období evidence DPH, do kterého doklady mohou patřit (DUZP, vystavení, odpočet).
     *
     * @param list<int> $ids
     * @return array{string,string}
     */
    private function range(int $supplierId, array $ids): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(LEAST(issue_date, COALESCE(tax_date, issue_date))), MAX(GREATEST(issue_date, COALESCE(tax_date, issue_date), COALESCE(DATE(received_at), issue_date)))
               FROM purchase_invoices WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$ids]);
        [$from, $to] = $stmt->fetch(PDO::FETCH_NUM);
        return [substr((string) $from, 0, 8) . '01', date('Y-m-t', (int) strtotime((string) $to))];
    }

    /**
     * Řádky evidence DPH dokladů, které jdou do přiznání nebo KH, sečtené po dokladu a řádku.
     *
     * @param list<int> $ids
     * @return array<string,array{float,float,float,float}>
     */
    private function ledgerSnapshot(int $supplierId, string $from, string $to, array $ids): array
    {
        $wanted = array_fill_keys($ids, true);
        $out = [];
        foreach ($this->ledger->iterateRows($supplierId, $from, $to, true, ['purchase' => $ids]) as $r) {
            if ($r['source'] !== 'purchase' || !isset($wanted[$r['invoice_id']]) || ($r['dphdp3_line'] === null && $r['kh_section'] === null)) {
                continue;
            }
            $key = implode('|', [$r['invoice_id'], $r['code'], $r['dphdp3_line'], $r['dphdp3_line_secondary'], $r['kh_section'],
                $r['claim_date'] ?? '', $r['tax_date'], (int) $r['is_reverse_charge'], number_format($r['vat_rate'], 2, '.', ''),
                number_format($r['total_with_vat_czk'], 2, '.', '')]);
            $sum = $out[$key] ?? [0.0, 0.0, 0.0, 0.0];
            $out[$key] = [round($sum[0] + $r['base_czk'], 2), round($sum[1] + $r['vat_czk'], 2),
                round($sum[2] + $r['deduction_base_czk'], 2), round($sum[3] + $r['deduction_vat_czk'], 2)];
        }
        ksort($out);
        return $out;
    }
}
