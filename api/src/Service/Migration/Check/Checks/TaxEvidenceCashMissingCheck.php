<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\CashBankImporter;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Daňová evidence převedená z POHODY bez pokladních dokladů nebo úhrad z pokladny.
 *
 * Převod verze před podporou daňové evidence šel u agendy daňové evidence postupem
 * podvojného účetnictví. Pokladnu zakládal jen podle zápisů v deníku, který daňová evidence
 * nemá, takže pokladní doklady nepřevzal (`register_missing`) a úhrady z pokladny nenašel
 * (`payment_not_found`). Úhrady pak zakryl: přijatý doklad vedl uhrazený úhradou mimo
 * evidenci, vydaný evidovanou platbou `settlement` ({@see DocumentLinker::REMAINDER_NOTE}).
 * Peněžní deník ale počítá jen skutečné pohyby, takže příjmy v hotovosti v něm chybí
 * a výdaje jsou v něm k datu úhrady bez pokladního dokladu - mění se základ daně z příjmů.
 *
 * Kontrola čte protokol posledního převodu každého roku, který firma vede v daňové evidenci,
 * a doklady z mapy převodu: krytí úhrad z dřívějšího převodu v roce agendy a doklady, jejichž
 * úhradu pokladnou převod nenašel a evidované úhrady je nepokrývají. Oprava potřebuje nový
 * export agendy z POHODY a opravný převod (`api/bin/pohoda-relink.php`), proto se jen hlásí.
 */
final class TaxEvidenceCashMissingCheck extends SourceScopedCheck
{
    public const KEY = 'tax_evidence_cash_missing';

    private const PROTOCOL_CODES = ['register_missing', 'payment_not_found'];

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Daňová evidence z POHODY bez pokladních dokladů nebo úhrad';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12617-danova-evidence-z-pohody-bez-pokladnich-dokladu-nebo-uhrad';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        $notes = [];
        foreach ($this->latestRuns($supplierId) as $year => $protocol) {
            if ($this->modes->forYear($supplierId, $year) !== 'tax_evidence') {
                continue;
            }
            $found = self::protocolFindings($protocol);
            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-12-31', $year);
            $yearItems = [
                ...$this->issuedRemainders($supplierId, $from, $to),
                ...$this->purchasesSettledOutside($supplierId, $from, $to),
                ...$this->unpaidNotFound($supplierId, $found['documents']),
            ];
            foreach ($yearItems as $item) {
                $items[$item->entityType . '|' . $item->entityId] ??= $item;
            }
            if ($found['register_missing'] > 0 || $found['payment_not_found'] > 0) {
                $notes[] = sprintf(
                    'Převod z POHODY, rok %d: %d pokladních dokladů nepřevzato (chyběla pokladna), %d úhrad z pokladny nenalezeno%s. '
                    . 'Opravu provede opravný převod z nového exportu agendy: php api/bin/pohoda-relink.php --supplier=%d --zip=<export> --year=%d (nanečisto, zápis s --commit).',
                    $year, $found['register_missing'], $found['payment_not_found'],
                    $found['truncated'] ? ' (protokol je zkrácený, skutečný počet může být vyšší)' : '', $supplierId, $year,
                );
                if ($yearItems === [] && $found['register_missing'] > 0) {
                    $items['account|' . $year] = new CheckItem('account', null, "Pokladní doklady POHODY {$year}", $to, null, CheckItem::REVIEW,
                        'Pokladní doklady agendy se nepřevzaly, příjmy a výdaje v hotovosti v peněžním deníku chybí.',
                        null, null, ['year' => $year, 'register_missing' => $found['register_missing']]);
                }
            }
        }
        return new CheckResult(array_values($items), $notes);
    }

    /**
     * Protokol posledního dokončeného převodu z POHODY za každý rok agendy.
     *
     * @return array<int,array<string,mixed>>
     */
    private function latestRuns(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.agenda_year, p.protocol FROM pohoda_imports p
              WHERE p.supplier_id = ? AND p.agenda_year IS NOT NULL AND p.id = (
                    SELECT MAX(l.id) FROM pohoda_imports l
                     WHERE l.supplier_id = p.supplier_id AND l.agenda_year = p.agenda_year
                       AND l.mode = 'import' AND l.status IN ('completed', 'completed_with_warnings'))"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $protocol = is_string($r['protocol']) ? json_decode($r['protocol'], true) : null;
            $out[(int) $r['agenda_year']] = is_array($protocol) ? $protocol : [];
        }
        return $out;
    }

    /**
     * Počty nálezů převodu a čísla dokladů, jejichž úhradu z pokladny převod nenašel. Doklady
     * nálezů kontrola bere z dat, protokol jen doplňuje: převod daňové evidence zapíše úplný
     * seznam do oddílu `lists` (`cash_payment_missing`, {@see \MyInvoice\Service\Migration\MoneyS3\ImportProtocol::addList()}),
     * u starších převodů se čtou jednotlivé zprávy `payment_not_found` o pokladně (počet zpráv
     * kroku je omezený, `truncated` to hlásí).
     * Nenalezené bankovní pohyby nese stejný kód, ale pokladny se netýkají.
     *
     * @param array<string,mixed> $protocol
     * @return array{register_missing:int,payment_not_found:int,documents:list<string>,truncated:bool}
     */
    private static function protocolFindings(array $protocol): array
    {
        $out = ['register_missing' => 0, 'payment_not_found' => 0, 'documents' => [], 'truncated' => false];
        $lists = (array) ($protocol['lists'] ?? []);
        $complete = isset($lists['cash_payment_missing']) ? array_map('strval', (array) $lists['cash_payment_missing']) : null;
        foreach ((array) ($protocol['steps'] ?? []) as $step) {
            $truncated = (int) ($step['counts']['messages_truncated'] ?? 0) > 0;
            foreach ((array) ($step['messages'] ?? []) as $m) {
                $code = (string) ($m['code'] ?? '');
                if ($code === 'register_missing') {
                    $out['register_missing']++;
                    $out['truncated'] = $out['truncated'] || $truncated;
                } elseif ($code === 'payment_not_found' && str_contains((string) ($m['text'] ?? ''), 'pokladně')) {
                    $out['payment_not_found']++;
                    $out['truncated'] = $out['truncated'] || $truncated;
                    $number = (string) ($m['context']['document_no'] ?? '');
                    if ($number !== '') {
                        $out['documents'][$number] = $number;
                    }
                }
            }
            if (($step['key'] ?? '') === CashBankImporter::STEP_CASH && $out['register_missing'] > 0) {
                $out['register_missing'] += (int) ($step['counts']['messages_truncated'] ?? 0);
            }
        }
        if ($complete !== null) {
            $out['documents'] = array_combine($complete, $complete);
            $out['payment_not_found'] = max($out['payment_not_found'], count($complete));
            $out['truncated'] = false;
        }
        $out['documents'] = array_values($out['documents']);
        return $out;
    }

    /**
     * Vydané doklady z převodu uhrazené v roce agendy zbytkem úhrady bez pohybu
     * ({@see DocumentLinker::REMAINDER_NOTE}): peněžní deník příjem nezapočte.
     *
     * @return list<CheckItem>
     */
    private function issuedRemainders(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS document_no, MAX(ip.paid_on) AS paid_on, SUM(ip.amount) AS amount
               FROM invoice_payments ip
               JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ip.supplier_id
              WHERE ip.supplier_id = ? AND ip.source = 'settlement' AND ip.note = ? AND ip.paid_on >= ? AND ip.paid_on <= ?
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = 'invoice' AND m.target_id = i.id)
              GROUP BY i.id, i.varsymbol
             HAVING amount >= 1 OR NOT EXISTS (SELECT 1 FROM invoice_payments r WHERE r.supplier_id = ? AND r.invoice_id = i.id AND r.source IN ('bank', 'cash'))
              ORDER BY paid_on, i.id"
        );
        $stmt->execute([$supplierId, DocumentLinker::REMAINDER_NOTE, $from, $to, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = new CheckItem('invoice', (int) $r['id'], (string) $r['document_no'], substr((string) $r['paid_on'], 0, 10), round((float) $r['amount'], 2),
                CheckItem::REVIEW, 'Uhrazeno podle POHODY bez pohybu v bance ani pokladně (doplatek z převodu). Příjem v peněžním deníku chybí.',
                round((float) $r['amount'], 2), 'CZK', ['kind' => 'issued_remainder']);
        }
        return $out;
    }

    /**
     * Přijaté doklady z převodu uhrazené v roce agendy úhradou mimo evidenci. Daňová evidence
     * ji vede jen u úhrady minulého roku: doklad vystavený v roce agendy ji mít nemá a doklad
     * minulého roku ji smí nést vedle úhrad roku agendy (dokončený v roce), ne místo nich -
     * s datem úhrady v roce a bez jiné úhrady ji peněžní deník počítá jako výdaj bez dokladu.
     *
     * @return list<CheckItem>
     */
    private function purchasesSettledOutside(int $supplierId, string $from, string $to): array
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, COALESCE(NULLIF(pi.varsymbol, ''), NULLIF(pi.vendor_invoice_number, ''), CONCAT('#', pi.id)) AS document_no,
                    pi.paid_at, pi.settled_outside_amount AS outside
               FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.settled_outside_amount > 0 AND pi.paid_at >= ? AND pi.paid_at <= ?
                AND pi.status NOT IN ('draft', 'cancelled')
                AND (pi.issue_date >= ? OR ({$settled}) - pi.settled_outside_amount < 0.01)
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = pi.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = pi.id)
              ORDER BY pi.paid_at, pi.id"
        );
        $stmt->execute([$supplierId, $from, $to . ' 23:59:59', $from]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = new CheckItem('purchase_invoice', (int) $r['id'], (string) $r['document_no'], substr((string) $r['paid_at'], 0, 10), round((float) $r['outside'], 2),
                CheckItem::REVIEW, 'Uhrazeno podle POHODY úhradou mimo evidenci, bez pohybu v bance ani pokladně. Peněžní deník výdaj počítá bez dokladu úhrady.',
                round((float) $r['outside'], 2), null, ['kind' => 'purchase_settled_outside']);
        }
        return $out;
    }

    /**
     * Doklady z hlášení `payment_not_found`, které evidované úhrady nepokrývají: v POHODĚ
     * uhrazené pokladnou, v MyÚčtu otevřené nebo uhrazené jen krytím z převodu.
     *
     * @param list<string> $numbers čísla dokladů POHODY
     * @return list<CheckItem>
     */
    private function unpaidNotFound(int $supplierId, array $numbers): array
    {
        $out = [];
        $pdo = $this->db->pdo();
        foreach (array_chunk($numbers, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $issued = $pdo->prepare(
                "SELECT i.id, i.varsymbol AS document_no, i.issue_date, i.amount_to_pay - COALESCE(p.paid_real, 0) AS gap
                   FROM invoices i
                   LEFT JOIN (SELECT ip.invoice_id, SUM(ip.amount) AS paid_real FROM invoice_payments ip
                               WHERE ip.supplier_id = ? AND ip.source IN ('bank', 'cash') GROUP BY ip.invoice_id) p ON p.invoice_id = i.id
                  WHERE i.supplier_id = ? AND i.varsymbol IN ({$in}) AND i.status NOT IN ('draft', 'cancelled')
                    AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = 'invoice' AND m.target_id = i.id)
                 HAVING gap >= 0.01"
            );
            $issued->execute([$supplierId, $supplierId, ...$chunk]);
            foreach ($issued->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = self::notFoundItem('invoice', $r);
            }
            $purchases = $pdo->prepare(
                "SELECT pi.id, pi.varsymbol AS document_no, pi.issue_date, pi.amount_to_pay - COALESCE(pi.paid_amount_invoice_ccy, 0) AS gap
                   FROM purchase_invoices pi
                  WHERE pi.supplier_id = ? AND pi.varsymbol IN ({$in}) AND pi.status NOT IN ('draft', 'cancelled')
                    AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = pi.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = pi.id)
                 HAVING gap >= 0.01"
            );
            $purchases->execute([$supplierId, ...$chunk]);
            foreach ($purchases->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = self::notFoundItem('purchase_invoice', $r);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    private static function notFoundItem(string $type, array $r): CheckItem
    {
        $gap = round((float) $r['gap'], 2);
        return new CheckItem($type, (int) $r['id'], (string) $r['document_no'], substr((string) $r['issue_date'], 0, 10), $gap, CheckItem::REVIEW,
            'V POHODĚ uhrazeno pokladním dokladem, který převod nepřevzal. Úhrada v MyÚčtu chybí nebo ji nese jen krytí z převodu.',
            $gap, null, ['kind' => 'payment_not_found']);
    }
}
