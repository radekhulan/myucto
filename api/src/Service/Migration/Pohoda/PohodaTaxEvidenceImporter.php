<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Převod agendy, kterou POHODA vede jako daňovou evidenci (typ jednotky `singleEntry`).
 *
 * Peněžní deník MyÚčto počítá z dokladů, banky a pokladny (`CashJournalService`). Převod
 * proto zapíše doklady, bankovní pohyby a pokladní doklady (stávající kroky převodu
 * podvojného účetnictví bez zaúčtování) a z exportu vezme navíc jen to, co z dokladů
 * vyčíst nejde:
 *
 *   - **úhrady** ({@see payments()}): přesná vazba pohybu na uhrazený doklad z úhrad dokladu
 *     POHODY (`liquidations`). Doklad označený zaplaceným bez vazby na pohyb by peněžní deník
 *     MyÚčta započetl jako virtuální pohyb vedle skutečného, proto se stav zaplacení dokladů
 *     počítá až z navázaných úhrad,
 *   - **úhrady minulého roku** u dokladů, které POHODA přenesla do agendy: doklad je nese
 *     jako uhrazenou část mimo peněžní deník roku,
 *   - **kontrolu** převodu ({@see reconcile()}): co z exportu chybí a které pohyby bez
 *     dokladu musí účetní zařadit v peněžním deníku.
 *
 * Pohyby bez dokladu (vklad, výběr, odvody) převod nezařazuje, předkontace daňové evidence
 * POHODY převod nečte. Firma zůstává v daňové evidenci: převod režim nemění, deník, osnovu,
 * předkontace ani účetní období nezakládá.
 */
final class PohodaTaxEvidenceImporter
{
    /** Stejný klíč kroku jako u podvojného převodu: úhrady ostatních položek hlásí {@see DocumentLinker}. */
    public const STEP_PAYMENTS = DocumentLinker::STEP_PAYMENTS;
    public const STEP_RECONCILIATION = 'tax_evidence_reconciliation';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly MigratedPaymentWriter $payments,
        private readonly DocumentLinker $linker,
        private readonly AccountingModeRepository $modes,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly PohodaPriorYearPayments $priorYear,
    ) {}

    /**
     * Kontrola cílové firmy pro agendu daňové evidence: právnická osoba daňovou evidenci
     * vést nemůže, daňová evidence se vede po kalendářních letech a převod nepřimíchá
     * doklady do roku, který firma vede jako podvojné účetnictví nebo už v MyÚčtu eviduje.
     *
     * Opravný převod (`$relinkOnly`, {@see PohodaContext::$relinkOnly}) jde jen do firmy, kterou
     * už převod z POHODY převedl, a nechá v roce i záznamy zadané v MyÚčtu.
     *
     * @param array{taxpayer_type:?string} $supplier
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, array $supplier, int $year, bool $relinkOnly = false): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        if (($supplier['taxpayer_type'] ?? null) === 'po') {
            $add('error', 'tax_evidence_legal_entity', 'Agenda POHODY je vedená v daňové evidenci, firma v MyÚčtu je ale právnická osoba, '
                . 'která vede účetnictví. Daňovou evidenci převod převede jen do firmy fyzické osoby.');
        }
        if ($this->periods->fiscalCalendar($supplierId)->startMonth() !== 1) {
            $add('error', 'tax_evidence_fiscal_year', 'Daňová evidence se vede po kalendářních letech, firma má v MyÚčtu nastavený '
                . 'hospodářský rok (Firma → Nastavení, Daně a účetnictví, Účetní rok začíná). Nastavte leden a převod spusťte znovu.');
        }
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $journal = $this->db->pdo()->prepare('SELECT EXISTS (SELECT 1 FROM journal_entries WHERE supplier_id = ? AND entry_date >= ? AND entry_date <= ?)');
        $journal->execute([$supplierId, $from, $to]);
        if ($this->modes->forYear($supplierId, $year) === 'double_entry' || $this->periods->findByYear($supplierId, $year) !== null || (bool) $journal->fetchColumn()) {
            $add('error', 'target_double_entry', "Agenda POHODY je vedená v daňové evidenci, firma v MyÚčtu vede rok {$year} jako podvojné účetnictví. "
                . 'Převod režim firmy nemění, daňovou evidenci převede jen do firmy v daňové evidenci.', ['year' => $year]);
        } else {
            $add('info', 'tax_evidence_kept', 'Agenda POHODY je vedená v daňové evidenci. Firma zůstane v daňové evidenci, převod její režim nemění, deník ani osnovu nezakládá.');
        }
        foreach ($this->targetChecks($supplierId, $year, $relinkOnly) as $m) {
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Rok agendy nesmí obsahovat doklady, výpisy ani pokladní doklady, které nevznikly
     * převodem z POHODY (do rozjeté evidence se převod nepřimíchává). Převod ani jeho opakování
     * nezapisuje pod zámek dokladů firmy ani do roku s dokončenou roční uzávěrkou daňové
     * evidence ({@see TaxEvidenceYearLock}): mění pokladní doklady i krytí úhrad.
     *
     * Opravný převod doplňuje do už převedené firmy pokladní doklady a úhrady, které dřívější
     * převod nepřevzal. Záznamy zadané v MyÚčtu nechá ({@see CashBankImporter::importCash()}
     * nepřevezme pokladní doklady POHODY od data prvního vlastního pokladního dokladu), do
     * uzamčeného období ale nezapisuje.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function targetChecks(int $supplierId, int $year, bool $relinkOnly = false): array
    {
        $out = [];
        $pdo = $this->db->pdo();
        if ($relinkOnly && !$this->hasConversion($supplierId)) {
            return [['level' => 'error', 'code' => 'relink_without_conversion', 'message' => 'Opravný převod předpokládá firmu převedenou z POHODY; doklady ani bankovní pohyby z převodu tu nejsou.', 'context' => []]];
        }
        $foreign = [
            ["SELECT COUNT(*) FROM invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'invoice' AND m.target_id = d.id)", 'vydaných dokladů'],
            ["SELECT COUNT(*) FROM purchase_invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = d.id)", 'přijatých dokladů'],
            ["SELECT COUNT(*) FROM cash_documents d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'cash_document' AND m.target_id = d.id)", 'pokladních dokladů'],
            ["SELECT COUNT(*) FROM bank_transactions d JOIN bank_statements s ON s.id = d.statement_id
                WHERE s.supplier_id = ? AND d.posted_at BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = d.id)", 'bankovních pohybů'],
        ];
        $found = [];
        foreach ($foreign as [$sql, $label]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31 23:59:59', $year)]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $found[] = "{$count} {$label}";
            }
        }
        if ($found !== [] && $relinkOnly) {
            $out[] = ['level' => 'info', 'code' => 'relink_keeps_own_records', 'message' => "Rok {$year} obsahuje " . implode(', ', $found)
                . ' zadaných v MyÚčtu. Opravný převod je nechá beze změny a doplní jen pokladní doklady a úhrady z POHODY.', 'context' => ['year' => $year]];
        } elseif ($found !== []) {
            $out[] = ['level' => 'error', 'code' => 'tax_evidence_year_not_empty', 'message' => "Rok {$year} už v MyÚčtu obsahuje " . implode(', ', $found)
                . ', které nevznikly převodem z POHODY. Daňová evidence z POHODY se do rozjeté evidence nepřimíchává.', 'context' => ['year' => $year]];
        }
        if (TaxEvidenceYearLock::isFinal($this->db, $supplierId, $year)) {
            $out[] = ['level' => 'error', 'code' => TaxEvidenceYearLock::ERROR_CODE, 'message' => TaxEvidenceYearLock::message($year)
                . ' Převod by měnil pokladní doklady a úhrady, ze kterých uzávěrka vychází.', 'context' => ['year' => $year]];
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId);
        if ($lockedUntil !== null && sprintf('%04d-01-01', $year) <= $lockedUntil) {
            $out[] = ['level' => 'error', 'code' => 'tax_evidence_locked', 'message' => 'Doklady firmy jsou v MyÚčtu uzamčené do ' . date('j. n. Y', (int) strtotime($lockedUntil))
                . ", převod by zapisoval do uzamčeného období (rok {$year}). Zámek zrušte a převod spusťte znovu.", 'context' => ['locked_until' => $lockedUntil]];
        }
        return $out;
    }

    /**
     * Úhrady faktur z úhrad dokladu v POHODĚ: úhrada bankovním nebo pokladním dokladem roku
     * agendy se naváže na převzatý pohyb ({@see MigratedPaymentWriter::attach()}), stav
     * zaplacení dokladů se pak spočte z navázaných úhrad. Úhrada z minulého roku je u dokladu
     * přeneseného do agendy uhrazenou částí mimo peněžní deník. Úhrada bez pohybu (zápočet,
     * interní doklad) se nenaváže a doklad zůstane neuhrazený s upozorněním. Úhrady ostatních
     * pohledávek, závazků a záloh na daň přiřadí {@see DocumentLinker::matchOtherItemPayments()}.
     * Opakovaný převod nic nezdvojí.
     */
    public function payments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $start = $ctx->periodStart();
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT);
        $fromPohoda = array_fill_keys(array_map('intval', array_values($existing)), true);
        $documents = ['invoice' => [], 'purchase_invoice' => []];
        $previous = ['invoice' => [], 'purchase_invoice' => []];
        // Úhrady roku agendy z exportu a jejich navázaná část: krytí z dřívějšího převodu smí
        // zůstat jen do výše úhrad, které se navázat nepodařilo.
        $unlinked = ['invoice' => [], 'purchase_invoice' => []];
        $all = ['invoice' => [], 'purchase_invoice' => []];
        foreach ($ctx->liquidations as $l) {
            if (!isset($documents[$l['doc']]) || $ctx->skipsDate($l['date'])) {
                continue;
            }
            $all[$l['doc']][(int) $l['id']] = (int) $l['id'];
            if ($l['date'] !== null && $l['date'] < $start) {
                $previous[$l['doc']][(int) $l['id']] = round(($previous[$l['doc']][(int) $l['id']] ?? 0.0) + abs((float) $l['amount']), 2);
            } else {
                $unlinked[$l['doc']][(int) $l['id']] = round(($unlinked[$l['doc']][(int) $l['id']] ?? 0.0) + abs((float) $l['amount']), 2);
            }
        }
        $home = [
            'invoice' => array_flip($this->homeDocuments($ctx->supplierId, 'invoices', array_values($all['invoice']))),
            'purchase_invoice' => array_flip($this->homeDocuments($ctx->supplierId, 'purchase_invoices', array_values($all['purchase_invoice']))),
        ];
        $linked = static function (array $l) use (&$unlinked): void {
            $unlinked[$l['doc']][(int) $l['id']] = round(max(0.0, ($unlinked[$l['doc']][(int) $l['id']] ?? 0.0) - abs((float) $l['amount'])), 2);
        };
        $statements = [];
        $withoutMovement = [];
        $notFound = [];
        $alreadyPaid = [];
        $foreign = [];
        $pdo = $this->db->pdo();
        $sameMatch = $pdo->prepare('SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id <=> ? AND purchase_invoice_id <=> ? LIMIT 1');
        $txMatches = $pdo->prepare('SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?');
        foreach ($ctx->liquidations as $l) {
            if (!isset($documents[$l['doc']])) {
                continue;
            }
            $id = (int) $l['id'];
            $isIssued = $l['doc'] === 'invoice';
            if ($ctx->skipsDate($l['date'])) {
                $p->count(self::STEP_PAYMENTS, 'later_year_skipped');
                continue;
            }
            $documents[$l['doc']][$id] = $id;
            if ($l['date'] !== null && $l['date'] < $start) {
                $p->count(self::STEP_PAYMENTS, 'previous_period');
                continue;
            }
            $mapKey = $l['doc'] . '|' . $id . '|' . $l['liq'];
            if (isset($existing[$mapKey])) {
                $linked($l);
                $p->count(self::STEP_PAYMENTS, 'existing');
                continue;
            }
            $movementKind = match ($l['agenda']) { 'bank' => 'bank', 'voucher' => 'cash', default => null };
            if ($movementKind === null) {
                $withoutMovement[$l['number']] = true;
                $p->count(self::STEP_PAYMENTS, $l['agenda'] !== '' ? 'other_' . $l['agenda'] : 'without_document');
                continue;
            }
            if (!isset($home[$l['doc']][$id])) {
                // Úhrady POHODY jsou v Kč, k dokladu v cizí měně je převod nenaváže.
                $foreign[$l['number']] = true;
                $p->count(self::STEP_PAYMENTS, 'foreign_currency_payment');
                continue;
            }
            if ($movementKind === 'bank') {
                $movementId = $this->linker->bankTransactionFor($ctx, $l);
            } else {
                $movementId = $ctx->cashDocuments[$l['source']] ?? null;
                if ($movementId === null) {
                    // Úplný seznam nese `cash_payment_missing` na konci kroku.
                    $notFound[$l['number']] = $l['source'];
                    $p->count(self::STEP_PAYMENTS, 'not_found');
                }
            }
            $amount = round(abs((float) $l['amount']), 2);
            if ($movementId === null || $amount < 0.005) {
                continue;
            }
            if ($movementKind === 'bank') {
                // Pohyb mohl mezi převody spárovat uživatel v MyÚčtu: stejná dvojice se nevkládá
                // podruhé, pohyb spárovaný ručně s jiným dokladem se nepřebírá ({@see DocumentLinker::matchPayments()}).
                $sameMatch->execute([$ctx->supplierId, (int) $movementId, $isIssued ? $id : null, $isIssued ? null : $id]);
                $already = $sameMatch->fetchColumn();
                if ($already !== false) {
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $already, $ctx->runId);
                    $linked($l);
                    $p->count(self::STEP_PAYMENTS, 'already_matched');
                    continue;
                }
                $txMatches->execute([$ctx->supplierId, (int) $movementId]);
                if (array_diff_key(array_fill_keys(array_map('intval', $txMatches->fetchAll(PDO::FETCH_COLUMN)), true), $fromPohoda) !== []) {
                    $p->count(self::STEP_PAYMENTS, 'transaction_consumed');
                    $p->warn(self::STEP_PAYMENTS, 'transaction_consumed', "Úhrada {$l['source']} dokladu {$l['number']}: pohyb je v MyÚčtu už spárovaný ručně s jiným dokladem, úhrada nepřevzata.", ['document_no' => $l['number']]);
                    continue;
                }
            }
            // Doklad uhrazený mezitím úhradou zadanou v MyÚčtu: úhrada z POHODY by ho uhradila
            // podruhé. Přeplatek z POHODY (vyšší úhrada odběratele, haléřový rozdíl) se naváže,
            // peněžní deník přeplatek rozdělí sám.
            if ($this->openWithoutConversion($ctx, $l['doc'], $id) < 0.01) {
                $alreadyPaid[$l['number']] = $l['source'];
                $p->count(self::STEP_PAYMENTS, 'already_paid');
                continue;
            }
            try {
                $linkId = $this->payments->attach($ctx->supplierId, $ctx->userOrNull(), $isIssued ? 'issued' : 'purchase',
                    $movementKind, $id, (int) $movementId, $amount);
            } catch (\InvalidArgumentException $e) {
                $p->count(self::STEP_PAYMENTS, 'not_linked');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_linked', sprintf(
                    'Úhradu %s dokladu %s převod nenavázal (%s), doklad zůstává neuhrazený. Navažte ji ručně.',
                    $l['source'], $l['number'], match ($e->getMessage()) {
                        'cash_payment_ambiguous' => 'pokladní doklad hradí víc dokladů nebo jinou částku',
                        'payment_currency_unverified' => 'doklad nebo pohyb v cizí měně',
                        default => 'doklad nebo pohyb chybí',
                    },
                ), ['document_no' => $l['number']]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $linkId, $ctx->runId);
            $fromPohoda[$linkId] = true;
            $linked($l);
            if ($movementKind === 'bank') {
                $statementId = $this->payments->statementId($ctx->supplierId, (int) $movementId);
                if ($statementId !== null) {
                    $statements[$statementId] = $statementId;
                }
            }
            $p->count(self::STEP_PAYMENTS, $movementKind);
        }
        $recorded = $this->priorYear->record($ctx->supplierId, $ctx->userOrNull(), $start, $previous['invoice'], false);
        if ($recorded['inserted'] > 0) {
            $p->count(self::STEP_PAYMENTS, 'previous_period_paid', $recorded['inserted']);
        }
        $issued = array_values(array_filter($documents['invoice'], static fn (int $id): bool => isset($home['invoice'][$id])));
        $purchases = array_values(array_filter($documents['purchase_invoice'], static fn (int $id): bool => isset($home['purchase_invoice'][$id])));
        $coverage = $this->purchaseCoverage($ctx, array_values($documents['purchase_invoice']), $previous['purchase_invoice'], $unlinked['purchase_invoice']);
        $kept = [...$this->trimIssuedRemainders($ctx, $issued, $unlinked['invoice']), ...$coverage['kept']];
        $this->payments->refreshBalances($ctx->supplierId, $issued, $purchases, array_values($statements));
        $this->settleRemainders($ctx, $issued, $purchases);
        $this->linker->matchOtherItemPayments($ctx);
        $this->reportList($ctx, 'payment_without_movement', array_keys($withoutMovement),
            '%d dokladů POHODA vyrovnala bez bankovního nebo pokladního dokladu (zápočet, interní doklad). Peněžní deník je bez pohybu nezapočte: vyrovnání doplňte ručně (%s).');
        $this->reportList($ctx, 'cash_payment_missing', array_keys($notFound),
            '%d dokladů má v POHODĚ úhradu pokladním dokladem, který v převodu není (%s). Úhradu doplňte ručně nebo převezměte pokladní doklad.');
        $this->reportList($ctx, 'already_paid', array_keys($alreadyPaid),
            '%d dokladů je v MyÚčtu už uhrazených úhradou zadanou v MyÚčtu (ne převodem), úhradu z POHODY k nim převod nenavázal (%s). Ověřte, že jde o tutéž úhradu, a pohyb z POHODY případně zařaďte ručně.');
        $this->reportList($ctx, 'foreign_currency_payment', array_keys($foreign),
            '%d dokladů v cizí měně má v POHODĚ úhradu z banky nebo pokladny. Úhrady POHODY jsou v Kč, převod je k dokladům v měně nenavazuje: navažte je ručně (%s).');
        $this->reportList($ctx, 'foreign_converter_settlement', $this->foreignRemainders($ctx, array_values(array_diff(array_values($documents['invoice']), $issued))),
            '%d vydaných dokladů v cizí měně nese zbytek úhrady z dřívějšího převodu bez pohybu v bance nebo pokladně (%s). Převod ho u dokladu v měně neupravuje: úhradu zkontrolujte ručně.');
        $this->reportList($ctx, 'converter_coverage_partial', $coverage['partial'],
            '%d přijatých dokladů má část úhrady navázanou na pohyb a zbytek jen krytím z dřívějšího převodu (%s). Peněžní deník u nich počítá výdaj jen ve výši navázaných úhrad, zbytek do základu daně nevstoupí, dokud úhradu nedoplníte ručně.');
        $this->reportList($ctx, 'converter_settlement_kept', $kept,
            '%d dokladů zůstává uhrazených krytím z dřívějšího převodu bez navázaného pohybu v bance nebo pokladně (%s). Peněžní deník u nich příjem nezapočte a výdaj počítá bez dokladu úhrady: úhradu doplňte ručně.');
        $this->reportList($ctx, 'overpaid_after_link', $this->overpaid($ctx->supplierId, $issued, $purchases),
            '%d dokladů je po navázání úhrad přeplacených (%s). Krytí z dřívějšího převodu (vyrovnání saldokonta, kurzové vyrovnání) zkontrolujte ručně.');
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Upozornění se seznamem dokladů. Celý seznam nese oddíl `lists` protokolu
     * ({@see ImportProtocol::addList()}), který počet zpráv kroku neomezuje; zpráva sama může
     * u velké firmy vypadnout a její text uvádí jen prvních 20.
     *
     * @param list<string> $documents
     */
    private function reportList(PohodaContext $ctx, string $code, array $documents, string $text): void
    {
        $documents = array_values(array_unique(array_map('strval', $documents)));
        if ($documents === []) {
            return;
        }
        $ctx->protocol->addList($code, $documents);
        $ctx->protocol->setCount(self::STEP_PAYMENTS, $code . '_documents', count($documents));
        $ctx->protocol->warn(self::STEP_PAYMENTS, $code, sprintf($text, count($documents),
            implode(', ', array_slice($documents, 0, 20)) . (count($documents) > 20 ? ' …' : '')), ['documents' => $documents]);
    }

    /**
     * Kolik zbývá uhradit bez úhrad, které zapsal převod (navázané pohyby z mapy převodu, zbytek
     * úhrady a úhrada minulého roku z POHODY, úhrada mimo evidenci): jen úhrady zadané
     * v MyÚčtu, zápočty a odečtená záloha. Doklad v Kč ({@see homeDocuments()}).
     */
    private function openWithoutConversion(PohodaContext $ctx, string $doc, int $id): float
    {
        if ($doc === 'invoice') {
            $stmt = $this->db->pdo()->prepare("SELECT ABS(i.total_with_vat) - i.advance_paid_amount - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip
                    WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id
                      AND NOT (ip.source = 'settlement' AND (ip.note <=> ? OR ip.note <=> ?))
                      AND NOT EXISTS (SELECT 1 FROM payment_matches pm JOIN pohoda_import_map m ON m.supplier_id = pm.supplier_id AND m.kind = 'payment' AND m.target_id = pm.id
                                       WHERE pm.supplier_id = ip.supplier_id AND pm.invoice_id = ip.invoice_id AND pm.bank_transaction_id = ip.bank_transaction_id)
                      AND NOT EXISTS (SELECT 1 FROM cash_documents cd JOIN pohoda_import_map m ON m.supplier_id = cd.supplier_id AND m.kind = 'cash_document' AND m.target_id = cd.id
                                       WHERE cd.supplier_id = ip.supplier_id AND cd.invoice_payment_id = ip.id)), 0)
                  FROM invoices i WHERE i.supplier_id = ? AND i.id = ?");
            $stmt->execute([DocumentLinker::REMAINDER_NOTE, PohodaPriorYearPayments::NOTE, $ctx->supplierId, $id]);
        } else {
            $settled = PurchaseSettledExpr::settled('pi');
            $stmt = $this->db->pdo()->prepare("SELECT ABS(pi.amount_to_pay) - (({$settled}) - pi.settled_outside_amount)
                    + COALESCE((SELECT SUM(pm.amount) FROM payment_matches pm JOIN pohoda_import_map m ON m.supplier_id = pm.supplier_id AND m.kind = 'payment' AND m.target_id = pm.id
                                 WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id), 0)
                    + COALESCE((SELECT SUM(cd.total_amount) FROM cash_documents cd JOIN pohoda_import_map m ON m.supplier_id = cd.supplier_id AND m.kind = 'cash_document' AND m.target_id = cd.id
                                 WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id AND cd.status = 'posted' AND cd.doc_type = 'out'), 0)
                  FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id = ?");
            $stmt->execute([$ctx->supplierId, $id]);
        }
        $open = $stmt->fetchColumn();
        return $open === false ? 0.0 : (float) $open;
    }

    /**
     * Vydané doklady v cizí měně se zbytkem úhrady z dřívějšího převodu v roce agendy
     * ({@see trimIssuedRemainders()} je neupravuje, úhrady POHODY jsou v Kč).
     *
     * @param list<int> $ids
     * @return list<string>
     */
    private function foreignRemainders(PohodaContext $ctx, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare("SELECT DISTINCT i.varsymbol FROM invoices i JOIN invoice_payments ip ON ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id
                 WHERE i.supplier_id = ? AND i.id IN (" . implode(',', array_map('intval', $chunk)) . ") AND ip.source = 'settlement' AND ip.note = ? AND ip.paid_on >= ?");
            $stmt->execute([$ctx->supplierId, DocumentLinker::REMAINDER_NOTE, $ctx->periodStart()]);
            array_push($out, ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        return $out;
    }

    /**
     * Doklady v korunách: úhrady z exportu jsou v Kč a {@see MigratedPaymentWriter::refreshBalances()}
     * doklad v cizí měně odmítne.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function homeDocuments(int $supplierId, string $table, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare("SELECT d.id FROM {$table} d JOIN currencies c ON c.id = d.currency_id AND c.supplier_id = d.supplier_id
                 WHERE d.supplier_id = ? AND c.code = 'CZK' AND d.id IN (" . implode(',', array_map('intval', $chunk)) . ')');
            $stmt->execute([$supplierId]);
            array_push($out, ...array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        return $out;
    }

    /**
     * Zbytek úhrady vydaného dokladu, který zapsal dřívější převod agendy postupem podvojného
     * účetnictví (verze převodu před podporou daňové evidence) jako evidovanou platbu
     * `settlement` s poznámkou {@see DocumentLinker::REMAINDER_NOTE}: převod tehdy pokladnu
     * nepřevzal a úhrady pokladnou jím zakryl. Odstraní se jen ta část, kterou teď pokrývají
     * jiné úhrady (navázané pohyby, platby zadané v MyÚčtu, úhrada minulého roku). Zbytek bez
     * pohybu zůstává (nejvýš do úhrad roku z exportu, které se nenavázaly) a hlásí se: bez něj
     * by doklad ztratil úhradu, kterou POHODA vede.
     * Jen platby v roce agendy; opakovaný převod nic nemění.
     *
     * @param list<int> $issued vydané doklady v Kč s úhradami v exportu
     * @param array<int,float> $unlinked id dokladu => úhrady roku agendy z exportu, které se nenavázaly
     * @return list<string> doklady, kterým zbytek z dřívějšího převodu zůstal
     */
    private function trimIssuedRemainders(PohodaContext $ctx, array $issued, array $unlinked): array
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $kept = [];
        $removed = 0;
        foreach (array_chunk($issued, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $pdo->prepare("SELECT i.id, i.varsymbol, ABS(i.total_with_vat) - i.advance_paid_amount AS to_pay,
                       SUM(CASE WHEN ip.source = 'settlement' AND ip.note = ? AND ip.paid_on >= ? THEN ip.amount ELSE 0 END) AS remainder,
                       SUM(CASE WHEN ip.source = 'settlement' AND ip.note = ? AND ip.paid_on >= ? THEN 0 ELSE ip.amount END) AS other,
                       MAX(CASE WHEN ip.source = 'settlement' AND ip.note = ? AND ip.paid_on >= ? THEN ip.paid_on END) AS paid_on,
                       MAX(ip.currency) AS currency
                  FROM invoices i JOIN invoice_payments ip ON ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id
                 WHERE i.supplier_id = ? AND i.id IN ({$in})
                 GROUP BY i.id, i.varsymbol, i.total_with_vat, i.advance_paid_amount
                HAVING remainder > 0");
            $note = DocumentLinker::REMAINDER_NOTE;
            $from = $ctx->periodStart();
            $rows->execute([$note, $from, $note, $from, $note, $from, $s]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $remainder = round((float) $r['remainder'], 2);
                $keep = round(max(0.0, min($remainder, (float) $r['to_pay'] - (float) $r['other'], $unlinked[(int) $r['id']] ?? 0.0)), 2);
                if ($keep >= 0.01) {
                    $kept[] = (string) $r['varsymbol'];
                }
                if ($remainder - $keep < 0.005) {
                    continue;
                }
                $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement' AND note = ? AND paid_on >= ?")
                    ->execute([$s, (int) $r['id'], $note, $from]);
                if ($keep >= 0.01) {
                    $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by) VALUES (?, ?, ?, ?, ?, ?, 'settlement', ?)")
                        ->execute([$s, (int) $r['id'], $r['paid_on'], number_format($keep, 2, '.', ''), $r['currency'], $note, $ctx->userOrNull()]);
                }
                $removed++;
            }
        }
        if ($removed > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'converter_settlement_removed', $removed);
        }
        return $kept;
    }

    /**
     * Úhrada přijatého dokladu mimo evidenci (`settled_outside_amount`). Daňová evidence ji vede
     * za úhrady minulého roku z exportu (jen doklady v korunách, nejvýš do částky k úhradě), ta
     * část se nastavuje absolutně. Krytí, které nad ni zapsal dřívější převod postupem podvojného
     * účetnictví (úhrady pokladnou, kterou tehdy nepřevzal), zůstává jen do výše úhrad roku
     * z exportu, které se nenavázaly, a do části, kterou nepokrývají jiné úhrady
     * ({@see PurchaseSettledExpr}: banka, pokladna, zápočty, dobropisy). Opakovaný převod nic
     * nezdvojí a nižší úhradu minulého roku v novém exportu převezme.
     *
     * Doklad s navázaným pohybem a zbytkem krytí z převodu se hlásí zvlášť: peněžní deník počítá
     * virtuální výdaj jen u dokladu bez pohybu, zbytek krytí tak do základu daně nevstoupí.
     * Převod ho tam nedoplňuje (výdaj bez pohybu by základ daně snížil bez dokladu úhrady),
     * účetní úhradu doplní ručně.
     *
     * @param list<int> $purchases přijaté doklady s úhradami v exportu
     * @param array<int,float> $previous id dokladu => úhrady minulého roku v Kč
     * @param array<int,float> $unlinked id dokladu => úhrady roku agendy z exportu, které se nenavázaly
     * @return array{kept:list<string>,partial:list<string>} doklady se zbytkem krytí z převodu, z nich s navázaným pohybem
     */
    private function purchaseCoverage(PohodaContext $ctx, array $purchases, array $previous, array $unlinked): array
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $settled = PurchaseSettledExpr::settled('pi');
        $update = $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = ? WHERE supplier_id = ? AND id = ?');
        $out = ['kept' => [], 'partial' => []];
        foreach (array_chunk($purchases, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $pdo->prepare("SELECT pi.id, pi.varsymbol, pi.amount_to_pay, c.code AS currency, pi.settled_outside_amount AS outside,
                       ({$settled}) - pi.settled_outside_amount AS other,
                       (EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id)
                        OR EXISTS (SELECT 1 FROM cash_documents cd WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id AND cd.status = 'posted')) AS has_movement
                  FROM purchase_invoices pi JOIN currencies c ON c.id = pi.currency_id AND c.supplier_id = pi.supplier_id
                 WHERE pi.supplier_id = ? AND pi.id IN ({$in}) AND pi.status NOT IN ('draft', 'cancelled')");
            $rows->execute([$s]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['id'];
                $toPay = (float) $r['amount_to_pay'];
                $outside = round((float) $r['outside'], 2);
                $prior = $r['currency'] === 'CZK' && isset($previous[$id]) ? round(min($previous[$id], max($toPay, 0.0)), 2) : 0.0;
                $converter = round(max(0.0, min($outside - $prior, $unlinked[$id] ?? 0.0, $toPay - (float) $r['other'] - $prior)), 2);
                $target = round($prior + $converter, 2);
                if ($converter >= 0.01) {
                    $out['kept'][] = (string) $r['varsymbol'];
                    if ((int) $r['has_movement'] === 1) {
                        $out['partial'][] = (string) $r['varsymbol'];
                    }
                }
                if (abs($target - $outside) < 0.005) {
                    continue;
                }
                $update->execute([number_format($target, 2, '.', ''), $s, $id]);
                $ctx->protocol->count(self::STEP_PAYMENTS, $target > $outside ? 'previous_period_paid' : 'converter_settlement_removed');
            }
        }
        return $out;
    }

    /**
     * Doklady přeplacené po navázání úhrad: krytí z dřívějšího převodu jiného druhu než zbytek
     * úhrady a úhrada mimo evidenci (vyrovnání skupiny saldokonta, kurzové vyrovnání) převod
     * nesnižuje, jen ohlásí.
     *
     * @param list<int> $issued
     * @param list<int> $purchases
     * @return list<string>
     */
    private function overpaid(int $supplierId, array $issued, array $purchases): array
    {
        $out = [];
        $pdo = $this->db->pdo();
        foreach (array_chunk($issued, 500) as $chunk) {
            $stmt = $pdo->prepare('SELECT varsymbol FROM invoices WHERE supplier_id = ? AND id IN (' . implode(',', array_map('intval', $chunk)) . ')
                AND paid_total + advance_paid_amount > ABS(total_with_vat) + 0.01');
            $stmt->execute([$supplierId]);
            array_push($out, ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $settled = PurchaseSettledExpr::settled('pi');
        foreach (array_chunk($purchases, 500) as $chunk) {
            $stmt = $pdo->prepare("SELECT pi.varsymbol FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id IN (" . implode(',', array_map('intval', $chunk)) . ")
                AND ({$settled}) > ABS(pi.amount_to_pay) + 0.01");
            $stmt->execute([$supplierId]);
            array_push($out, ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        return $out;
    }

    /**
     * Doklad uhrazený navázanými úhradami spolu s odečtenou zálohou nebo úhradou z minulého
     * roku: {@see MigratedPaymentWriter::refreshBalances()} je neporovnává. Uhrazený je jen
     * doklad, který má aspoň jednu navázanou úhradu z banky nebo pokladny - datum úhrady
     * přijatého dokladu bez ní by peněžní deník započetl jako virtuální výdaj.
     *
     * @param list<int> $issued
     * @param list<int> $purchases
     */
    private function settleRemainders(PohodaContext $ctx, array $issued, array $purchases): void
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        foreach (array_chunk($issued, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $pdo->prepare("UPDATE invoices i
                   SET i.status = 'paid',
                       i.paid_at = (SELECT MAX(ip.paid_on) FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND ip.source IN ('bank', 'cash'))
                 WHERE i.supplier_id = ? AND i.id IN ({$in}) AND i.paid_at IS NULL AND i.status NOT IN ('draft', 'cancelled')
                   AND i.paid_total + i.advance_paid_amount >= ABS(i.total_with_vat) - 0.01
                   AND EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND ip.source IN ('bank', 'cash'))")
                ->execute([$s]);
        }
        foreach (array_chunk($purchases, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $pdo->prepare("UPDATE purchase_invoices pi
                   SET pi.status = 'paid',
                       pi.paid_at = NULLIF(GREATEST(
                           COALESCE((SELECT MAX(bt.posted_at) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                                      WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id), '1000-01-01'),
                           COALESCE((SELECT MAX(cd.issue_date) FROM cash_documents cd
                                      WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id AND cd.status = 'posted'), '1000-01-01')
                       ), '1000-01-01')
                 WHERE pi.supplier_id = ? AND pi.id IN ({$in}) AND pi.paid_at IS NULL AND pi.status NOT IN ('draft', 'cancelled')
                   AND pi.paid_amount_invoice_ccy > 0
                   AND pi.paid_amount_invoice_ccy + pi.settled_outside_amount >= ABS(pi.amount_to_pay) - 0.01")
                ->execute([$s]);
        }
    }

    /**
     * Kontrola převodu daňové evidence: pohyby banky a pokladny z exportu proti převzatým
     * a pohyby bez dokladu, úhrady ani zařazení. Ty peněžní deník nezapočte, dokud je účetní
     * nezařadí (vklad, výběr, odvody, poplatky): převod je nezařazuje, předkontace daňové
     * evidence POHODY nečte. Rok agendy se zapamatuje pro vypořádání koeficientu § 76.
     */
    public function reconcile(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $s = $ctx->supplierId;
        $pdo = $this->db->pdo();
        $bank = 0;
        foreach ($ctx->bankTransactions as $txs) {
            $bank += count($txs);
        }
        $p->setCount(self::STEP_RECONCILIATION, 'bank_transactions', $bank);
        $p->setCount(self::STEP_RECONCILIATION, 'cash_documents', count($ctx->cashDocuments));
        $p->setCount(self::STEP_RECONCILIATION, 'issued_documents', count($ctx->issuedInvoices) + count($ctx->receivables) + count($ctx->internalSales));
        $p->setCount(self::STEP_RECONCILIATION, 'purchase_documents', count($ctx->purchaseInvoices) + count($ctx->commitments) + count($ctx->internalPurchases));

        $ids = [];
        foreach ($ctx->bankTransactions as $txs) {
            foreach ($txs as $tx) {
                $ids[] = (int) $tx['id'];
            }
        }
        $unclassified = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            $unclassified += (int) $pdo->query(
                "SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = {$s}
                  WHERE bt.id IN ({$in})
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = {$s} AND pm.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM other_item_allocations a WHERE a.supplier_id = {$s} AND a.bank_transaction_id = bt.id AND a.reversed_on IS NULL)
                    AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules t WHERE t.supplier_id = {$s} AND t.matched_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM de_movement_classification c WHERE c.supplier_id = {$s} AND c.source_type = 'bank' AND c.bank_transaction_id = bt.id)"
            )->fetchColumn();
        }
        $p->setCount(self::STEP_RECONCILIATION, 'bank_unclassified', $unclassified);
        if ($unclassified > 0) {
            $p->warn(self::STEP_RECONCILIATION, 'bank_unclassified', sprintf(
                '%d bankovních pohybů nemá doklad ani úhradu (vklad, výběr, poplatky, odvody). Převod je nezařazuje: zařaďte je v Daňová evidence → Peněžní deník, jinak je peněžní deník nezapočte.',
                $unclassified,
            ), ['transactions' => $unclassified]);
        }
        if ($this->map->get($s, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR, (string) $ctx->year()) === null) {
            $this->map->put($s, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR, (string) $ctx->year(), $ctx->year(), $ctx->runId);
        }
        $p->info(self::STEP_RECONCILIATION, 'tax_evidence_summary', sprintf(
            'Daňová evidence %d: vydané doklady %d, přijaté doklady %d, bankovní pohyby %d, pokladní doklady %d. Firma zůstala v daňové evidenci; peněžní deník sestaví MyÚčto z dokladů, banky a pokladny.',
            $ctx->year(), count($ctx->issuedInvoices) + count($ctx->receivables) + count($ctx->internalSales),
            count($ctx->purchaseInvoices) + count($ctx->commitments) + count($ctx->internalPurchases), $bank, count($ctx->cashDocuments),
        ));
        $p->finish(self::STEP_RECONCILIATION);
    }

    /** Firma má doklady nebo bankovní pohyby z dřívějšího převodu z POHODY. */
    public function hasConversion(int $supplierId): bool
    {
        return $this->map->hasAny($supplierId, PohodaImportRepository::KIND_INVOICE)
            || $this->map->hasAny($supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE)
            || $this->map->hasAny($supplierId, PohodaImportRepository::KIND_BANK_TRANSACTION);
    }

    /** @return list<int> roky daňové evidence převedené z POHODY (i dřívějšími běhy) */
    public function convertedYears(int $supplierId): array
    {
        return array_map('intval', array_keys($this->map->all($supplierId, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR)));
    }
}
