<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Kroky převodu agendy, kterou Money vede jako daňovou evidenci.
 *
 * Peněžní deník Money ({@see Ms3CashBook}) se nepřebírá jako další platby: MyÚčto peněžní
 * deník počítá z dokladů, banky a pokladny (`CashJournalService`). Převod proto zapíše
 * doklady, bankovní pohyby a pokladní doklady (stávající kroky převodu) a z peněžního
 * deníku vezme jen to, co z dokladů vyčíst nejde:
 *
 *   - **úhrady** ({@see payments()}): přesná vazba pohybu na uhrazený doklad (`UZdroj`,
 *     `URok`, `URadek`), ne odhad podle čísla dokladu a data. Doklad označený zaplaceným
 *     bez vazby na pohyb by peněžní deník MyÚčta započetl jako virtuální pohyb podruhé,
 *   - **zápočty dobropisů** (interní doklady zápočtu bez peněz) jako zápočet dobropisu,
 *   - **zařazení pohybů bez dokladu** ({@see classification()}): vklad, výběr, pojistné,
 *     daň, DPH, dary, nedaňové příjmy podle sloupce peněžního deníku (pohyb s dokladem
 *     zařadí peněžní deník MyÚčta podle dokladu, úhradu zálohy jako výdaj při úhradě),
 *   - **otevřené ostatní závazky a pohledávky** ({@see otherItems()}),
 *   - **plátcovství DPH** firmy po letech ({@see vatStatus()}).
 *
 * Firma zůstává v daňové evidenci: převod režim nemění, deník ani osnovu nezakládá.
 */
final class TaxRecordsImporter
{
    public const STEP_PLAN = 'tax_records_plan';
    public const STEP_VAT_STATUS = 'vat_status';
    public const STEP_PAYMENTS = 'tax_records_payments';
    public const STEP_CLASSIFICATION = 'tax_records_classification';
    public const STEP_OTHER_ITEMS = 'tax_records_other_items';

    private const NOTE = 'Převzato z Money S3';

    /** @var array<string,array<string,array<int,array<string,mixed>>>> adresář => tabulka => Cislo => řádek */
    private array $documents = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly MigratedPaymentWriter $payments,
        private readonly MovementClassificationRepository $classifications,
        private readonly CreditNoteOffsetService $creditNotes,
        private readonly OtherItemService $otherItems,
        private readonly VatStatusService $vatStatus,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    /**
     * Roky daňové evidence k převodu (rok = rok Money, volba „Převést od roku") s peněžním
     * deníkem a kontrola zdroje ({@see TaxRecordsPlan}). Chyba kontroly převod zastaví.
     */
    public function plan(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $this->documents = [];
        $ctx->taxRecords = true;
        $ctx->lockedUntil = $this->settings->getLockedUntil($ctx->supplierId);
        $paths = [];
        foreach ($ctx->backup->yearDirs() as $path) {
            $paths[basename($path)] = $path;
        }
        foreach ($ctx->agenda->years as $y) {
            $year = $y['money_year'] ?? null;
            if (($y['accounting_mode'] ?? null) !== 'tax_evidence' || $year === null || !isset($paths[$y['dir']])
                || ($ctx->options->fromYear !== null && $year < $ctx->options->fromYear)) {
                continue;
            }
            $ctx->dirYears[(string) $y['dir']] = (int) $year;
            $ctx->cashBooks[(string) $y['dir']] = Ms3CashBook::read($ctx->backup, $paths[$y['dir']]);
        }
        asort($ctx->dirYears);
        $check = TaxRecordsPlan::check($ctx->backup, $ctx->agenda, $ctx->options->fromYear);
        foreach ($check['issues'] as $issue) {
            match ($issue['level']) {
                'error' => $p->error(self::STEP_PLAN, $issue['code'], $issue['message'], $issue['context']),
                'warning' => $p->warn(self::STEP_PLAN, $issue['code'], $issue['message'], $issue['context']),
                default => $p->info(self::STEP_PLAN, $issue['code'], $issue['message'], $issue['context']),
            };
        }
        $p->set('tax_records', $check['years']);
        $p->setCount(self::STEP_PLAN, 'years', count($ctx->dirYears));
        $p->setCount(self::STEP_PLAN, 'movements', array_sum(array_map(static fn (Ms3CashBook $b): int => count($b->movements), $ctx->cashBooks)));
        if ($ctx->dirYears === []) {
            $p->error(self::STEP_PLAN, 'no_tax_records_years', 'Záloha nemá žádný rok daňové evidence k převodu.');
        }
        $p->finish(self::STEP_PLAN);
    }

    /**
     * Plátcovství DPH firmy po letech z Money: rok plátce podle nastavení roku (`Rok.DAT`),
     * den registrace podle prvního podaného přiznání (`SzDPH`). Historii plátcovství firmy
     * převod doplní, jen když ji firma v převáděných letech nemá — zapsanou nepřepisuje.
     * Periodu přiznání (`vat_period`) doplní podle posledního podaného přiznání.
     */
    public function vatStatus(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->dirYears === []) {
            $p->finish(self::STEP_VAT_STATUS);
            return;
        }
        $filings = self::filings($ctx);
        $firstFiling = [];
        $lastType = null;
        foreach ($filings as $f) {
            if ($f['kind'] === 'DPH') {
                $firstFiling[$f['year']] = min($firstFiling[$f['year']] ?? $f['from'], $f['from']);
                $lastType = $f['type'];
            }
        }
        $paths = [];
        foreach ($ctx->backup->yearDirs() as $path) {
            $paths[basename($path)] = $path;
        }
        $timeline = [];
        $previous = null;
        foreach ($ctx->dirYears as $dir => $year) {
            $payer = $ctx->backup->yearSettings($paths[$dir])['vat_payer'] ?? isset($firstFiling[$year]);
            if ($payer === $previous) {
                continue;
            }
            $from = sprintf('%04d-01-01', $year);
            if ($payer && $previous === false && isset($firstFiling[$year]) && $firstFiling[$year] > $from) {
                $from = $firstFiling[$year];
            } elseif ($payer && $previous === null && isset($firstFiling[$year]) && $firstFiling[$year] > $from) {
                // Registrace v prvním převáděném roce: do dne registrace neplátce.
                $timeline[$from] = false;
                $from = $firstFiling[$year];
            }
            $timeline[$from] = $payer;
            $previous = $payer;
        }

        $since = (string) array_key_first($timeline);
        $existing = $this->db->pdo()->prepare('SELECT effective_from, is_vat_payer FROM supplier_vat_status_history
            WHERE supplier_id = ? AND effective_from >= ? ORDER BY effective_from');
        $existing->execute([$ctx->supplierId, $since]);
        $rows = $existing->fetchAll(PDO::FETCH_ASSOC);
        $describe = static fn (array $t): string => implode(', ', array_map(
            static fn (string $date, bool $payer): string => ($payer ? 'plátce od ' : 'neplátce od ') . $date, array_keys($t), $t));
        if ($rows !== []) {
            $current = [];
            foreach ($rows as $r) {
                $current[(string) $r['effective_from']] = (bool) $r['is_vat_payer'];
            }
            if ($current !== $timeline) {
                $p->warn(self::STEP_VAT_STATUS, 'vat_history_differs', 'Firma má v MyÚčtu historii plátcovství DPH (' . $describe($current)
                    . '), Money vede ' . $describe($timeline) . '. Převod historii nemění, zkontrolujte ji v nastavení firmy.');
            } else {
                $p->count(self::STEP_VAT_STATUS, 'existing');
            }
        } else {
            foreach ($timeline as $from => $payer) {
                $this->vatStatus->upsert($ctx->supplierId, $from, $payer, false, self::NOTE, $ctx->userId > 0 ? $ctx->userId : null);
                $p->count(self::STEP_VAT_STATUS, 'vat_history');
            }
            $p->info(self::STEP_VAT_STATUS, 'vat_history', 'Plátcovství DPH podle Money: ' . $describe($timeline) . '.');
        }

        if ($lastType !== null) {
            $period = $lastType === 'K' ? 'quarterly' : 'monthly';
            $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
            $stmt->execute([$ctx->supplierId]);
            $current = $stmt->fetchColumn();
            $label = $period === 'quarterly' ? 'čtvrtletní' : 'měsíční';
            if ($current === null || $current === false || $current === '') {
                // Zkouška nanečisto řádek firmy nemění (zámek by v transakci zkoušky blokoval doklady firmy).
                if (!$ctx->options->isDryRun()) {
                    $this->db->pdo()->prepare('UPDATE supplier SET vat_period = ? WHERE id = ?')->execute([$period, $ctx->supplierId]);
                    $this->vatStatus->refreshLiveCache($ctx->supplierId);
                }
                $p->info(self::STEP_VAT_STATUS, 'vat_period', "Zdaňovací období DPH firmy: {$label} (poslední přiznání v Money).");
            } elseif ($current !== $period) {
                $p->warn(self::STEP_VAT_STATUS, 'vat_period_differs', "Money podává přiznání DPH {$label}, firma má v MyÚčtu nastavené jiné zdaňovací období. Převod ho nemění.");
            }
        } elseif (!$ctx->options->isDryRun() && $rows === []) {
            $this->vatStatus->refreshLiveCache($ctx->supplierId);
        }
        $p->finish(self::STEP_VAT_STATUS);
    }

    /**
     * Úhrady z peněžního deníku: každá část pohybu banky nebo pokladny s vazbou na vydanou
     * nebo přijatou fakturu či převedenou ostatní pohledávku se naváže na převzatý pohyb
     * ({@see MigratedPaymentWriter::attach()}), částka = součet částí pohybu k dokladu.
     * Stav zaplacení dokladů se pak spočte z navázaných úhrad. Zápočty dobropisů bez peněz
     * se převezmou jako zápočet dobropisu ({@see CreditNoteOffsetService}).
     */
    public function payments(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $issued = [];
        $purchases = [];
        $statements = [];
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_PAYMENT);
        foreach ($ctx->cashBooks as $dir => $book) {
            foreach ($book->movements as $movement) {
                $kind = match ($movement['source']) {
                    'BK' => 'bank',
                    'PK' => 'cash',
                    default => null,
                };
                $movementId = $kind !== null ? ($ctx->movements[$kind][$dir . '|' . $movement['number']] ?? null) : null;
                if ($movementId === null) {
                    continue;
                }
                foreach (self::linkedAmounts($movement) as $target => $amount) {
                    [$table, $targetDir, $number] = explode('|', $target);
                    $document = $this->document($ctx, $table, $targetDir, (int) $number);
                    if ($document === null) {
                        if (in_array($table, ['FV', 'FP'], true)) {
                            $p->warn(self::STEP_PAYMENTS, 'payment_document_missing', "Úhrada {$movement['document_no']} ({$ctx->dirYears[$dir]}): uhrazený doklad "
                                . "převod nepřevzal (jiný rok, uzavřené období nebo koncept), úhrada se nenaváže.", ['document_no' => $movement['document_no']]);
                        }
                        continue;
                    }
                    [$documentKind, $documentId] = $document;
                    $key = $dir . '|' . $movement['source'] . '|' . $movement['number'] . '|' . $target;
                    if ($documentKind === 'issued') {
                        $issued[$documentId] = $documentId;
                    } else {
                        $purchases[$documentId] = $documentId;
                    }
                    if ($kind === 'bank') {
                        $statementId = $this->payments->statementId($ctx->supplierId, $movementId);
                        if ($statementId !== null) {
                            $statements[$statementId] = $statementId;
                        }
                    }
                    if (isset($existing[$key])) {
                        $p->count(self::STEP_PAYMENTS, 'existing');
                        continue;
                    }
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    try {
                        $linkId = $this->payments->attach($ctx->supplierId, $ctx->userId > 0 ? $ctx->userId : null, $documentKind, $kind,
                            $documentId, $movementId, round(abs($amount), 2));
                    } catch (\InvalidArgumentException $e) {
                        $p->difference(self::STEP_PAYMENTS, 'payment_not_linked', sprintf(
                            'Úhradu %s (%d) převod nenavázal na doklad %s (%s). Navažte ji ručně.',
                            $movement['document_no'], $ctx->dirYears[$dir], $document[2], match ($e->getMessage()) {
                                'cash_payment_ambiguous' => 'pokladní doklad hradí víc dokladů nebo jinou částku',
                                'payment_currency_unverified' => 'doklad nebo pohyb v cizí měně',
                                default => 'doklad nebo pohyb chybí',
                            },
                        ), ['document_no' => $movement['document_no'], 'year' => $ctx->dirYears[$dir]]);
                        continue;
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_PAYMENT, $key, $linkId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, $kind);
                }
            }
        }
        $this->payments->refreshBalances($ctx->supplierId, array_values($issued), array_values($purchases), array_values($statements));
        $this->creditNoteOffsets($ctx);
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Zápočty bez peněz: interní doklady Money se stejným číslem zápočtu (`CisloZapoc`),
     * které v peněžním deníku řádky nemají. Doklad, který zápočet vyrovnal, nese číslo
     * interního dokladu jako doklad úhrady (`UDoklad`). Zápočet faktury a dobropisu téhož
     * partnera se převezme jako zápočet dobropisu; jiný zápočet (pohledávka proti závazku)
     * daňová evidence MyÚčta neumí a doklady zůstanou neuhrazené s upozorněním.
     */
    private function creditNoteOffsets(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_OFFSET);
        foreach ($ctx->cashBooks as $dir => $book) {
            $groups = [];
            foreach ($this->rows($ctx, $dir, 'IntDokl', ['Cislo', 'Doklad', 'CisloZapoc']) as $r) {
                $offset = (int) ($r['CisloZapoc'] ?? 0);
                if ($offset > 0 && !isset($book->movements[Ms3CashBook::key('ID', (int) ($r['Cislo'] ?? 0))])) {
                    $groups[$offset][trim((string) ($r['Doklad'] ?? ''))] = true;
                }
            }
            foreach ($groups as $offset => $internal) {
                $key = $dir . '|' . $offset;
                if (isset($existing[$key])) {
                    $p->count(self::STEP_PAYMENTS, 'offsets_existing');
                    continue;
                }
                $members = [];
                foreach (['FV' => 'VFaktury', 'FP' => 'PFaktury'] as $table => $name) {
                    foreach ($this->rows($ctx, $dir, $name, ['Cislo', 'Doklad', 'UDoklad', 'Dobropis']) as $r) {
                        if (isset($internal[trim((string) ($r['UDoklad'] ?? ''))])) {
                            $members[] = ['table' => $table, 'credit_note' => (int) ($r['Dobropis'] ?? 0) === 1,
                                'document' => $this->document($ctx, $table, $dir, (int) ($r['Cislo'] ?? 0)), 'doc' => trim((string) ($r['Doklad'] ?? ''))];
                        }
                    }
                }
                $tables = array_values(array_unique(array_column($members, 'table')));
                $notes = array_values(array_filter($members, static fn (array $m): bool => $m['credit_note']));
                $invoices = array_values(array_filter($members, static fn (array $m): bool => !$m['credit_note']));
                $docs = implode(', ', array_column($members, 'doc')) ?: implode(', ', array_keys($internal));
                if (count($tables) !== 1 || count($notes) !== 1 || count($invoices) !== 1 || $notes[0]['document'] === null || $invoices[0]['document'] === null) {
                    $p->warn(self::STEP_PAYMENTS, 'offset_not_supported', "Zápočet {$offset} ({$ctx->dirYears[$dir]}, doklady {$docs}): zápočet bez peněz převod "
                        . 'přebírá jen mezi fakturou a dobropisem téhož dokladu. Vyrovnání dokladů doplňte ručně.', ['year' => $ctx->dirYears[$dir], 'documents' => $docs]);
                    continue;
                }
                $creditNoteId = $notes[0]['document'][1];
                $invoiceId = $invoices[0]['document'][1];
                $purchase = $tables[0] === 'FP';
                $column = $purchase ? 'parent_purchase_invoice_id' : 'parent_invoice_id';
                $table = $purchase ? 'purchase_invoices' : 'invoices';
                $this->db->pdo()->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ? AND supplier_id = ? AND {$column} IS NULL")
                    ->execute([$invoiceId, $creditNoteId, $ctx->supplierId]);
                $result = $purchase
                    ? $this->creditNotes->applyForPurchase($ctx->supplierId, $creditNoteId, $ctx->userId > 0 ? $ctx->userId : null)
                    : $this->creditNotes->applyForInvoice($ctx->supplierId, $creditNoteId, $ctx->userId > 0 ? $ctx->userId : null);
                if ($result['offset_id'] === null) {
                    $p->warn(self::STEP_PAYMENTS, 'offset_not_applied', "Zápočet {$offset} ({$ctx->dirYears[$dir]}, doklady {$docs}): převod ho nezapsal ({$result['reason']}). "
                        . 'Vyrovnání dokladů doplňte ručně.', ['year' => $ctx->dirYears[$dir], 'documents' => $docs, 'reason' => $result['reason']]);
                    continue;
                }
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_OFFSET, $key, (int) $result['offset_id'], $ctx->runId);
                $p->count(self::STEP_PAYMENTS, 'offsets');
            }
        }
    }

    /**
     * Zařazení pohybů peněžního deníku, které daňový základ nevezmou z dokladu: pohyb bez
     * vazby na převzatý doklad (vklad, výběr, pojistné, daň a DPH placené přes závazek,
     * dary, nedaňové příjmy) podle sloupce peněžního deníku Money. Pohyb s dokladem zařadí
     * peněžní deník MyÚčta podle dokladu. Pokladní doklad se zařazuje jen jako vklad nebo
     * výběr — ostatní zařazení nese jeho účel ({@see CashBankImporter}).
     */
    public function classification(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_CLASSIFICATION);
        foreach ($ctx->cashBooks as $dir => $book) {
            foreach ($book->movements as $movement) {
                $kind = match ($movement['source']) {
                    'BK' => 'bank',
                    'PK' => 'cash',
                    default => null,
                };
                $id = $kind !== null ? ($ctx->movements[$kind][$dir . '|' . $movement['number']] ?? null) : null;
                if ($id === null || abs($movement['amount']) < 0.005) {
                    continue;
                }
                $linked = false;
                foreach (array_keys(self::linkedAmounts($movement)) as $target) {
                    [$table, $targetDir, $number] = explode('|', $target);
                    $linked = $linked || $this->document($ctx, $table, $targetDir, (int) $number) !== null;
                }
                [$bucket, $mixed] = self::bucketOf($movement);
                // Daňový základ pohybu s dokladem vezme peněžní deník z dokladu.
                if ($linked || ($kind === 'cash' && $bucket !== 'private')) {
                    continue;
                }
                $key = $kind . '|' . $id;
                if (isset($existing[$key])) {
                    $p->count(self::STEP_CLASSIFICATION, 'existing');
                    continue;
                }
                if ($mixed) {
                    $p->difference(self::STEP_CLASSIFICATION, 'classification_mixed', sprintf(
                        'Pohyb %s (%d) má v peněžním deníku Money víc zařazení (%s) bez dokladu, který by je rozdělil. Zařazen celý jako %s, zkontrolujte ho.',
                        $movement['document_no'], $ctx->dirYears[$dir], implode(', ', $mixed), $bucket,
                    ), ['document_no' => $movement['document_no'], 'year' => $ctx->dirYears[$dir]]);
                }
                $this->classifications->upsert($ctx->supplierId, $kind, $id, $bucket, self::NOTE, $ctx->userId > 0 ? $ctx->userId : null);
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_TAX_CLASSIFICATION, $key, $id, $ctx->runId);
                $p->count(self::STEP_CLASSIFICATION, $bucket);
            }
        }
        $p->finish(self::STEP_CLASSIFICATION);
    }

    /**
     * Ostatní závazky (`KnihZav`) a pohledávky (`KnihPohl`, které nejsou vydaným dokladem
     * v přiznání DPH), které Money k datu zálohy vede jako neuhrazené (`UhZbyva`), jako
     * ostatní závazky a pohledávky MyÚčta na zbývající částku. Uhrazené už jsou v peněžním
     * deníku jako zařazené pohyby.
     */
    public function otherItems(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_OTHER_ITEM);
        foreach ($ctx->cashBooks as $dir => $book) {
            $year = $ctx->dirYears[$dir];
            foreach (['KZ' => ['KnihZav', 'payable'], 'KP' => ['KnihPohl', 'receivable']] as $source => [$table, $side]) {
                foreach ($this->rows($ctx, $dir, $table, ['Doklad', 'Popis', 'DatVyst', 'DatUcPr', 'DatSpl', 'UhZbyva', 'Storno']) as $r) {
                    $docNo = trim((string) ($r['Doklad'] ?? ''));
                    $remaining = round((float) ($r['UhZbyva'] ?? 0), 2);
                    if ($docNo === '' || $remaining < 0.005 || (int) ($r['Storno'] ?? 0) === 1
                        || ($source === 'KP' && isset($ctx->otherReceivables[$year . '|' . $docNo]))) {
                        continue;
                    }
                    $key = $source . '|' . $year . '|' . $docNo;
                    if (isset($existing[$key])) {
                        $p->count(self::STEP_OTHER_ITEMS, 'existing');
                        continue;
                    }
                    $issued = InvoiceImporter::date($r, ['DatVyst', 'DatUcPr']) ?? sprintf('%04d-12-31', $year);
                    try {
                        $item = $this->otherItems->create($ctx->supplierId, [
                            'side' => $side,
                            'kind' => 'other',
                            'title' => mb_substr(trim((string) ($r['Popis'] ?? '')) ?: ($side === 'payable' ? 'Závazek ' : 'Pohledávka ') . $docNo, 0, 255),
                            'issued_on' => $issued,
                            'due_on' => InvoiceImporter::date($r, ['DatSpl']) ?? $issued,
                            'amount' => $remaining,
                            'note' => self::NOTE . ', ' . ($side === 'payable' ? 'závazek ' : 'pohledávka ') . $docNo . ', zbývá k úhradě k datu zálohy',
                        ], $ctx->userId > 0 ? $ctx->userId : null);
                        $this->otherItems->post($ctx->supplierId, (int) $item['id'], $ctx->userId > 0 ? $ctx->userId : null);
                    } catch (OtherItemException $e) {
                        $p->warn(self::STEP_OTHER_ITEMS, 'other_item_failed', "Neuhrazený doklad {$docNo} ({$year}) převod nezapsal: {$e->getMessage()}", ['document_no' => $docNo, 'year' => $year]);
                        continue;
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_OTHER_ITEM, $key, (int) $item['id'], $ctx->runId);
                    $p->count(self::STEP_OTHER_ITEMS, $side);
                }
            }
        }
        $p->finish(self::STEP_OTHER_ITEMS);
    }

    /**
     * Podaná přiznání DPH a kontrolní hlášení v evidenci Money (`SzDPH`) v převáděných
     * letech: druh (`DPH`, `DPHKH`), typ období (`M` měsíc, `K` čtvrtletí), období a rok.
     *
     * @return list<array{kind:string,type:string,period:int,year:int,from:string,to:string,form:int}>
     */
    public static function filings(ImportContext $ctx): array
    {
        $years = array_flip(array_values($ctx->dirYears));
        $table = $ctx->backup->table('SzDPH');
        if ($table === null || !$table->hasData()) {
            return [];
        }
        $out = [];
        foreach ($table->rows(['DruhPodani', 'ObdobiTyp', 'Obdobi', 'ObdobiRok', 'DatumOd', 'DatumDo', 'Forma']) as $r) {
            $kind = strtoupper(trim((string) ($r['DruhPodani'] ?? '')));
            $type = strtoupper(trim((string) ($r['ObdobiTyp'] ?? '')));
            $year = (int) ($r['ObdobiRok'] ?? 0);
            $period = (int) ($r['Obdobi'] ?? 0);
            if (!in_array($kind, ['DPH', 'DPHKH'], true) || !in_array($type, ['M', 'K'], true) || !isset($years[$year])
                || $period < 1 || $period > ($type === 'M' ? 12 : 4) || !is_string($r['DatumOd'] ?? null)) {
                continue;
            }
            $out[] = ['kind' => $kind, 'type' => $type, 'period' => $period, 'year' => $year, 'from' => $r['DatumOd'],
                'to' => is_string($r['DatumDo'] ?? null) ? $r['DatumDo'] : $r['DatumOd'], 'form' => (int) ($r['Forma'] ?? 0)];
        }
        usort($out, static fn (array $a, array $b): int => [$a['from'], $a['kind']] <=> [$b['from'], $b['kind']]);
        return $out;
    }

    /**
     * Částky pohybu po uhrazených dokladech: "tabulka|adresář|číslo" => součet částí se
     * znaménkem peněžního toku. Jen doklady, které převod přebírá (faktury, pohledávky).
     *
     * @param array{parts:list<array{amount:float,link:?array{table:string,dir:string,number:int}}>} $movement
     * @return array<string,float>
     */
    private static function linkedAmounts(array $movement): array
    {
        $out = [];
        foreach ($movement['parts'] as $part) {
            $link = $part['link'];
            if ($link !== null && in_array($link['table'], ['FV', 'FP', 'KP'], true)) {
                $key = $link['table'] . '|' . $link['dir'] . '|' . $link['number'];
                $out[$key] = round(($out[$key] ?? 0.0) + $part['amount'], 2);
            }
        }
        return $out;
    }

    /**
     * Zařazení pohybu podle jeho částí (bez DPH, které jde mimo základ): převažující
     * zařazení podle částky a seznam zařazení, má-li jich pohyb víc. Pohyb jen s DPH (odvod,
     * vratka) je nedaňový výdaj nebo příjem.
     *
     * @param array{amount:float,parts:list<array{bucket:?string,amount:float}>} $movement
     * @return array{0:string,1:list<string>}
     */
    private static function bucketOf(array $movement): array
    {
        $sums = [];
        foreach ($movement['parts'] as $part) {
            if ($part['bucket'] !== null && $part['bucket'] !== Ms3CashBook::VAT) {
                $sums[$part['bucket']] = ($sums[$part['bucket']] ?? 0.0) + abs($part['amount']);
            }
        }
        if ($sums === []) {
            return [$movement['amount'] >= 0 ? 'income_nontax' : 'expense_nontax', []];
        }
        arsort($sums);
        return [(string) array_key_first($sums), count($sums) > 1 ? array_keys($sums) : []];
    }

    /**
     * Převzatý doklad, na který vazba peněžního deníku vede: [druh, id, číslo dokladu].
     * Vydaná faktura a převedená ostatní pohledávka jsou vydané doklady, přijatá faktura
     * přijatý. Null = doklad převod nepřevzal (rok mimo převod, uzavřené období) nebo vazba
     * vede na závazek, který doklad v MyÚčtu nemá.
     *
     * @return array{0:string,1:int,2:string}|null
     */
    private function document(ImportContext $ctx, string $table, string $dir, int $number): ?array
    {
        $year = $ctx->dirYears[$dir] ?? null;
        if ($year === null || $table === 'KZ') {
            return null;
        }
        $docNo = $this->linkedDocumentNo($ctx, ['table' => $table, 'dir' => $dir, 'number' => $number]);
        if ($docNo === null) {
            return null;
        }
        $id = match ($table) {
            'FV' => $ctx->issuedInvoices[$year . '|' . $docNo] ?? null,
            'FP' => $ctx->purchaseInvoices[$year . '|' . $docNo] ?? null,
            'KP' => $ctx->otherReceivables[$year . '|' . $docNo] ?? null,
        };
        return $id === null ? null : [$table === 'FP' ? 'purchase' : 'issued', (int) $id, $docNo];
    }

    /**
     * Číslo dokladu Money, na který vede vazba peněžního deníku: vydaná a přijatá faktura,
     * ostatní závazek a pohledávka. Null = doklad v záloze není.
     *
     * @param array{table:string,dir:string,number:int} $link
     */
    public function linkedDocumentNo(ImportContext $ctx, array $link): ?string
    {
        $name = Ms3CashBook::LINK_TABLES[$link['table']] ?? null;
        $docNo = $name !== null ? trim((string) ($this->rows($ctx, $link['dir'], $name, ['Cislo', 'Doklad'])[$link['number']]['Doklad'] ?? '')) : '';
        return $docNo !== '' ? $docNo : null;
    }

    /**
     * Řádky tabulky adresáře podle čísla dokladu (`Cislo`), načtené jednou za převod.
     *
     * @param list<string> $fields
     * @return array<int,array<string,mixed>>
     */
    private function rows(ImportContext $ctx, string $dir, string $table, array $fields): array
    {
        $cacheKey = $table . '|' . implode(',', $fields);
        if (!isset($this->documents[$dir][$cacheKey])) {
            $rows = [];
            $t = $ctx->backup->table($table, $ctx->backup->dir() . DIRECTORY_SEPARATOR . $dir);
            if ($t !== null && $t->hasData()) {
                $i = 0;
                foreach ($t->rows(array_values(array_unique([...$fields, 'Cislo']))) as $r) {
                    $rows[isset($r['Cislo']) ? (int) $r['Cislo'] : --$i] = $r + ['__dir' => $dir];
                }
            }
            $this->documents[$dir][$cacheKey] = $rows;
        }
        return $this->documents[$dir][$cacheKey];
    }
}
