<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use PDO;

final class PohodaIssuedAdvanceGroups
{
    public function __construct(private readonly Connection $db) {}

    public static function unsupportedLinksSql(): string
    {
        return "(EXISTS (SELECT 1 FROM invoices ch WHERE ch.parent_invoice_id = i.id AND ch.supplier_id = i.supplier_id
                            AND (i.invoice_type <> 'proforma' OR ch.invoice_type NOT IN ('invoice', 'tax_document')))
                 OR (i.parent_invoice_id IS NOT NULL AND NOT EXISTS (
                         SELECT 1 FROM invoices a WHERE a.id = i.parent_invoice_id AND a.supplier_id = i.supplier_id AND a.invoice_type = 'proforma'))
                 OR EXISTS (SELECT 1 FROM invoice_items it JOIN invoices f ON f.id = it.invoice_id WHERE it.advance_invoice_id = i.id
                             AND (i.invoice_type <> 'proforma' OR f.supplier_id <> i.supplier_id
                                  OR NOT (f.parent_invoice_id <=> i.id) OR f.invoice_type <> 'invoice'))
                 OR EXISTS (SELECT 1 FROM invoice_advance_deductions a WHERE a.supplier_id = i.supplier_id AND a.advance_invoice_id = i.id)
                 OR EXISTS (SELECT 1 FROM invoice_advance_deductions a WHERE a.supplier_id = i.supplier_id AND a.final_invoice_id = i.id)
                 OR EXISTS (SELECT 1 FROM invoice_payments p LEFT JOIN invoices td ON td.id = p.tax_document_invoice_id
                             WHERE p.supplier_id = i.supplier_id AND p.invoice_id = i.id AND p.tax_document_invoice_id IS NOT NULL
                               AND (td.id IS NULL OR td.supplier_id <> i.supplier_id OR td.invoice_type <> 'tax_document'
                                    OR NOT (td.parent_invoice_id <=> i.id))))";
    }

    /** @return array<int,array<int,array>> */
    public function families(int $supplierId, array $docs, bool $lock = false): array
    {
        $roots = [];
        foreach ($docs as $id => $doc) {
            if ($doc['parent_invoice_id'] !== null) {
                $roots[(int) $doc['parent_invoice_id']] = true;
            } elseif ($doc['invoice_type'] === 'proforma') {
                $roots[$id] = true;
            }
        }
        if ($roots === []) {
            return [];
        }
        $ids = array_keys($roots);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $unsupported = self::unsupportedLinksSql();
        $out = [];
        foreach (['id', 'parent_invoice_id'] as $column) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT i.id, i.parent_invoice_id, i.invoice_type, i.status, i.client_id, i.issue_date, i.effective_tax_date, i.paid_at,
                        c.code AS currency, i.exchange_rate, i.total_with_vat, i.import_total_with_vat_czk,
                        m.pohoda_key, r.agenda_year, r.agenda_ico, {$unsupported} AS has_unsupported_link,
                        EXISTS (SELECT 1 FROM invoice_payments p WHERE i.invoice_type = 'tax_document' AND p.tax_document_invoice_id = i.id
                                  AND (p.supplier_id <> i.supplier_id OR NOT (p.invoice_id <=> i.parent_invoice_id))) AS has_other_advance_payment
                   FROM invoices i JOIN currencies c ON c.id = i.currency_id
              LEFT JOIN pohoda_import_map m FORCE INDEX (ix_pohoda_import_map_target)
                     ON m.supplier_id = i.supplier_id AND m.kind = ? AND m.target_id = i.id
              LEFT JOIN pohoda_imports r ON r.id = m.run_id AND r.supplier_id = i.supplier_id
                  WHERE i.supplier_id = ? AND i.{$column} IN ({$marks}) ORDER BY i.id" . ($lock ? ' FOR UPDATE' : '')
            );
            $stmt->execute([PohodaImportRepository::KIND_INVOICE, $supplierId, ...$ids]);
            foreach ($stmt as $row) {
                $id = (int) $row['id'];
                $root = $column === 'id' ? $id : (int) $row['parent_invoice_id'];
                $row['multiple_keys'] = isset($out[$root][$id]);
                $out[$root][$id] = $row;
            }
        }
        return array_filter($out, static fn (array $members, int $root): bool => count($members) > 1 || !isset($members[$root]), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array{list<list<int>>,array<int,int>,array<int,array>,array<int,string>} */
    public static function scope(array $families, array $pending, array $sources, array $initial): array
    {
        $groups = $roots = $unchanged = $problems = [];
        foreach ($families as $root => $members) {
            $ids = array_keys($members);
            $groups[] = $ids;
            $roots += array_fill_keys($ids, $root);
            $reason = self::problem($root, $members);
            if ($reason !== null) {
                $problems += array_fill_keys($ids, $reason);
                continue;
            }
            foreach (array_diff_key($members, $pending) as $id => $member) {
                if ($member['currency'] !== 'CZK' && (float) $member['exchange_rate'] > 0.0
                    && in_array($member['invoice_type'], ['proforma', 'tax_document'], true)) {
                    $source = $sources[$id] ?? null;
                    if ($source !== null && ($source['key'] !== $member['pohoda_key'] || $source['export']->year !== (int) $member['agenda_year'])) {
                        $source = null;
                    }
                    $unchanged[$id] = ['doc' => $member, 'amounts' => ['total' => (float) $member['total_with_vat']],
                        'source' => $source, 'advance_group' => $root];
                    continue;
                }
                $detail = $initial[$id]['reason'] ?? 'Chybí doklad způsobilý ke společnému převodu.';
                $problems[$id] = sprintf('Zálohu a navázané doklady je nutné ověřit společně. %s', $detail);
            }
        }
        return [$groups, $roots, $unchanged, $problems];
    }

    private static function problem(int $root, array $members): ?string
    {
        $parent = $members[$root] ?? null;
        if ($parent === null || $parent['invoice_type'] !== 'proforma' || $parent['parent_invoice_id'] !== null) {
            return 'Skupina nemá jednoznačnou vazbu na zálohu a její daňové doklady.';
        }
        $finals = 0;
        foreach ($members as $id => $member) {
            if ($member['multiple_keys'] || $member['pohoda_key'] === null || $member['client_id'] !== $parent['client_id']
                || $member['status'] === 'cancelled' || (bool) $member['has_unsupported_link'] || (bool) $member['has_other_advance_payment']
                || ($id !== $root && !in_array($member['invoice_type'], ['invoice', 'tax_document'], true))) {
                return 'Skupina obsahuje nepodporovanou vazbu nebo doklad bez jednoznačného převodu z POHODY.';
            }
            if ($member['invoice_type'] === 'invoice') {
                $finals++;
            }
        }
        return $finals > 1 ? 'Záloha má více konečných faktur. Společné čerpání vyžaduje kontrolu.' : null;
    }

    /** @return array<int,string> */
    public static function taxDocumentProblems(array $plans, array $payments): array
    {
        $refs = $problems = [];
        foreach ($plans as $id => $plan) {
            $doc = $plan['doc'];
            if ($doc['invoice_type'] !== 'tax_document') {
                continue;
            }
            $reason = 'Vazbu DDKP na zálohu a její úhradu v měně dokladu nelze doložit z exportu.';
            $parentId = (int) $doc['parent_invoice_id'];
            $parent = $plans[$parentId] ?? null;
            $source = $plan['source'];
            if ($parent === null || $source === null || $doc['currency'] !== $parent['doc']['currency']
                || strtoupper($source['doc']['foreign']) !== $doc['currency']) {
                $problems[$id] = $reason;
                continue;
            }
            $export = $source['export'];
            $refs[$export->year] ??= $export->taxDocumentPaymentRefs();
            $targets = $refs[$export->year][$source['id']] ?? [];
            $parentNumber = explode('|', $parent['doc']['pohoda_key'], 3)[1] ?? '';
            $parentSourceId = ($parent['source']['export'] ?? null) === $export ? $parent['source']['id'] : '';
            $sourceMatches = $targets === []
                ? array_values(array_unique($source['advance_links'])) === [$parentNumber]
                : count(array_unique(array_column($targets, 'advance'))) === 1
                    && array_unique(array_column($targets, 'doc')) === ['invoice']
                    && array_unique(array_column($targets, 'number')) === [$parentNumber]
                    && ($parentSourceId === '' || (string) $targets[0]['advance'] === $parentSourceId);
            $linkedPayments = array_keys(array_filter($payments[$parentId]['links'] ?? [], static fn (mixed $linkedId): bool => (int) $linkedId === $id));
            $amountMatches = ($targets !== [] || $linkedPayments !== [])
                ? count($linkedPayments) === 1 && ($payments[$parentId]['rows'][$linkedPayments[0]] ?? null) === $plan['amounts']['total']
                : $plan['amounts']['total'] === $parent['amounts']['total'];
            if (!$sourceMatches || !$amountMatches) {
                $problems[$id] = $reason;
            }
        }
        return $problems;
    }

    /** @return array{number:string,home:float,foreign:float,currency:string}|null */
    public static function advance(array $doc, ?array $plan, ?array $source): ?array
    {
        [$agenda, $number] = explode('|', $doc['pohoda_key'], 3) + ['', ''];
        if ($number === '' || !in_array($agenda, ['issued_advance', 'issued_proforma'], true)) {
            return null;
        }
        $home = $plan === null ? IssuedCzkAmounts::gross($doc) : (float) $plan['doc']['total_with_vat'];
        $foreign = $plan['amounts']['total'] ?? (float) $doc['total_with_vat'];
        $currency = $plan['doc']['currency'] ?? $doc['currency'];
        if ($plan === null && $source !== null) {
            $header = $source['doc'];
            if ($home !== $source['amounts']['total'] || $currency !== strtoupper($header['foreign'])
                || ($header['foreign_total'] !== null && $foreign !== round($header['foreign_total'], 2))
                || (float) $doc['exchange_rate'] !== ForeignCurrencyTakeover::rate($header['foreign_rate'], $header['foreign_units'])) {
                return null;
            }
        }
        return $home > 0 && $foreign > 0 && $currency !== 'CZK'
            ? ['number' => $number, 'home' => $home, 'foreign' => $foreign, 'currency' => $currency]
            : null;
    }

    public static function deduction(array $amounts, array $advance): float|string
    {
        $problem = 'Odpočet zálohy a jeho částku v měně dokladu nelze doložit z exportu.';
        if ($amounts['advance_rows'] === []) {
            return $problem;
        }
        $foreign = $taxed = $home = $missingHome = 0.0;
        foreach ($amounts['advance_rows'] as $row) {
            if ($row['number'] !== $advance['number'] || $row['home'] <= 0.0
                || ($row['currency'] !== '' && $row['currency'] !== $advance['currency'])
                || ($row['foreign'] !== null && $row['foreign'] <= 0.0)) {
                return $problem;
            }
            $home += $row['home'];
            if ($row['item_index'] !== null) {
                if ($row['foreign'] === null) {
                    return $problem;
                }
                $taxed += $row['foreign'];
            } elseif ($row['foreign'] === null) {
                $missingHome += $row['home'];
            } else {
                $foreign += $row['foreign'];
            }
        }
        $home = round($home, 2);
        if ($home > $advance['home']) {
            return $problem;
        }
        $missingHome = round($missingHome, 2);
        if ($missingHome > 0.0) {
            $share = PohodaIssuedPaymentConversion::proportional($missingHome, $advance['home'], $advance['foreign']);
            if (PohodaIssuedPaymentConversion::proportional($share, $advance['foreign'], $advance['home']) !== $missingHome) {
                return 'Poměrný odpočet zálohy po přepočtu neodpovídá částce z exportu na haléř.';
            }
            $foreign += $share;
        }
        $total = round($foreign + $taxed, 2);
        return $total > 0 && $total <= $advance['foreign']
            ? round($foreign, 2)
            : $problem;
    }
}
