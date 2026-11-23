<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Repository\AdvanceDeductionRepository;
use PDO;

/**
 * Vazby mezi vydanými doklady převzatými z Fakturoidu. Fakturoid je vrací od nejnovějšího,
 * takže konečná faktura přichází dřív než její zálohy. Import si proto během průchodu
 * pamatuje jen vazební údaje dokladů ({@see self::remember()}) a navazuje až po převzetí
 * všech dokladů ({@see self::link()}), stejným modelem jako doklady vystavené v aplikaci:
 *
 *   - daňový doklad k přijaté platbě → zálohová faktura: `parent_invoice_id` a platba
 *     zálohy s `invoice_payments.tax_document_invoice_id` (Fakturoid vede vazbu na
 *     platbě zálohové faktury, `payments[].tax_document_id`),
 *   - konečná faktura → zálohová faktura: `parent_invoice_id`, odečítá-li zálohy jediné
 *     zálohové faktury. Konečná faktura, která vyúčtuje víc zálohových faktur, dostane
 *     místo vazby čerpání po dvojicích (`invoice_advance_deductions`, zdroj `import`)
 *     v základu odečtené zálohy, stejně jako převody z účetních programů. Zálohy najde
 *     přes odečtené daňové doklady (`paid_advances`) i přes `related_id` u nezdaněné zálohy,
 *   - dobropis → opravovaný doklad: `parent_invoice_id` (Fakturoid `correction_id`).
 *
 * Navazuje se jen doklad založený tímto importem a jen dosud nenavázaný: doklad převzatý
 * dřív nemá odpočtové řádky a doklad, který uživatel upravil, se nepřepisuje. Vazbu, kterou
 * navázat nejde (doklad v importu chybí), vrátí jako problém ke kontrole.
 */
final class FakturoidDocumentLinks
{
    private const CHUNK = 500;

    /**
     * @var array<int, array{id:int, number:string, type:string, related_id:int, correction_id:int,
     *     tax_document_ids:list<int>, advances:list<array{id:int, number:string, amount:float}>, untaxed:float}>
     */
    private array $docs = [];

    /** @var array<int,true> Fakturoid ID dokladů založených tímto importem (i nanečisto). */
    private array $created = [];

    /** @param array<string,mixed> $inv */
    public function remember(array $inv): void
    {
        $id = (int) ($inv['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $taxDocuments = [];
        foreach (($inv['payments'] ?? []) as $payment) {
            if (is_array($payment) && (int) ($payment['tax_document_id'] ?? 0) > 0) {
                $taxDocuments[] = (int) $payment['tax_document_id'];
            }
        }
        $advances = [];
        foreach (FakturoidSourceDocument::paidAdvances($inv) as $advance) {
            $advances[] = ['id' => $advance['id'], 'number' => $advance['number'], 'amount' => -$advance['base']];
        }
        $this->docs[$id] = [
            'id'               => $id,
            'number'           => (string) ($inv['number'] ?? ''),
            'type'             => (string) ($inv['document_type'] ?? 'invoice'),
            'related_id'       => (int) ($inv['related_id'] ?? 0),
            'correction_id'    => (int) ($inv['correction_id'] ?? 0),
            'tax_document_ids' => array_values(array_unique($taxDocuments)),
            'advances'         => $advances,
            'untaxed'          => min(FakturoidSourceDocument::untaxedAdvance($inv), max(0.0, (float) ($inv['total'] ?? 0))),
        ];
    }

    public function created(int $fakturoidId): void
    {
        $this->created[$fakturoidId] = true;
    }

    /**
     * @return array{tax_documents:int, finals:int, credit_notes:int,
     *     problems: list<array{doc: array{id:int, number:string}, reason:string, local_id:?int}>}
     */
    public function link(PDO $pdo, AdvanceDeductionRepository $deductions, int $supplierId, ?int $userId, bool $dryRun): array
    {
        $out = ['tax_documents' => 0, 'finals' => 0, 'credit_notes' => 0, 'problems' => []];
        if ($this->created === []) {
            return $out;
        }
        $local = $this->localDocuments($pdo, $supplierId);
        $exists = fn (int $fid): bool => $fid > 0 && (isset($local[$fid]) || ($dryRun && isset($this->created[$fid])));
        $problem = function (array $doc, string $reason) use (&$out, $local): void {
            $out['problems'][] = [
                'doc' => ['id' => $doc['id'], 'number' => $doc['number']],
                'reason' => $reason,
                'local_id' => $local[$doc['id']]['id'] ?? null,
            ];
        };

        // Daňový doklad k platbě → zálohová faktura, jejíž platbu dokumentuje.
        $proformaOf = [];
        foreach ($this->docs as $doc) {
            if ($doc['type'] !== 'proforma') {
                continue;
            }
            foreach ($doc['tax_document_ids'] as $td) {
                $proformaOf[$td] = $doc['id'];
                if (!isset($this->created[$td]) || !$exists($td) || !$exists($doc['id'])) {
                    continue;
                }
                if ($dryRun) {
                    $out['tax_documents']++;
                    continue;
                }
                $out['tax_documents'] += $this->setParent($pdo, $supplierId, $local[$td]['id'], $local[$doc['id']]['id'], 'tax_document');
                if (count($doc['tax_document_ids']) === 1) {
                    $this->linkPayment($pdo, $supplierId, $local[$doc['id']]['id'], $local[$td]['id']);
                }
            }
        }
        foreach ($local as $fid => $row) {
            if ($row['invoice_type'] === 'tax_document' && !isset($proformaOf[$fid])
                && $row['parent_type'] === 'proforma' && $row['parent_fakturoid_id'] > 0) {
                $proformaOf[$fid] = $row['parent_fakturoid_id'];
            }
        }

        foreach ($this->docs as $doc) {
            if (!isset($this->created[$doc['id']])) {
                continue;
            }

            // Konečná faktura → zálohové faktury, jejichž zálohy odečítá.
            if ($doc['advances'] !== [] || $doc['type'] === 'final_invoice') {
                $proformas = [];
                foreach ($doc['advances'] as $advance) {
                    $label = $advance['number'] !== '' ? $advance['number'] : '#' . $advance['id'];
                    if (!$exists($advance['id'])) {
                        $problem($doc, sprintf('Odečtený daňový doklad k platbě %s v importu není. Odpočet je na faktuře převzatý, vazbu na zálohu doplňte ručně.', $label));
                        continue;
                    }
                    $proforma = $proformaOf[$advance['id']] ?? 0;
                    if (!$exists($proforma)) {
                        $problem($doc, sprintf('Daňový doklad k platbě %s není navázaný na zálohovou fakturu. Odpočet je na faktuře převzatý, vazbu na zálohu doplňte ručně.', $label));
                        continue;
                    }
                    $proformas[$proforma] = round(($proformas[$proforma] ?? 0.0) + $advance['amount'], 2);
                }
                $related = $doc['related_id'];
                $relatedIsProforma = ($this->docs[$related]['type'] ?? $local[$related]['invoice_type'] ?? '') === 'proforma';
                if ($related > 0 && $relatedIsProforma && $exists($related)) {
                    $proformas[$related] = round(($proformas[$related] ?? 0.0) + $doc['untaxed'], 2);
                }

                if ($proformas !== [] && $dryRun) {
                    $out['finals']++;
                } elseif (count($proformas) === 1) {
                    $out['finals'] += $this->setParent($pdo, $supplierId, $local[$doc['id']]['id'], $local[array_key_first($proformas)]['id'], 'invoice');
                } elseif ($proformas !== []) {
                    foreach ($proformas as $proforma => $amount) {
                        if ($amount >= 0.01) {
                            $deductions->insert($supplierId, 'invoice', $local[$doc['id']]['id'], $local[$proforma]['id'], $amount, $userId, 'import');
                        }
                    }
                    $out['finals']++;
                }
            }

            // Dobropis → opravovaný doklad.
            if ($doc['type'] === 'correction' && $doc['correction_id'] > 0) {
                if (!$exists($doc['correction_id'])) {
                    $problem($doc, sprintf('Opravovaný doklad (Fakturoid #%d) v importu není, vazbu dobropisu doplňte ručně.', $doc['correction_id']));
                } elseif ($dryRun) {
                    $out['credit_notes']++;
                } else {
                    $out['credit_notes'] += $this->setParent($pdo, $supplierId, $local[$doc['id']]['id'], $local[$doc['correction_id']]['id'], 'credit_note');
                }
            }
        }

        return $out;
    }

    /**
     * Doklady firmy podle Fakturoid ID, která vazby potřebují, s typem rodiče.
     *
     * @return array<int, array{id:int, invoice_type:string, parent_fakturoid_id:int, parent_type:string}>
     */
    private function localDocuments(PDO $pdo, int $supplierId): array
    {
        $ids = [];
        foreach ($this->docs as $doc) {
            $ids[] = $doc['id'];
            $ids[] = $doc['related_id'];
            $ids[] = $doc['correction_id'];
            array_push($ids, ...$doc['tax_document_ids']);
            foreach ($doc['advances'] as $advance) {
                $ids[] = $advance['id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $stmt = $pdo->prepare(
                'SELECT i.id, i.fakturoid_id, i.invoice_type, p.fakturoid_id AS parent_fakturoid_id, p.invoice_type AS parent_type
                   FROM invoices i
                   LEFT JOIN invoices p ON p.id = i.parent_invoice_id AND p.supplier_id = i.supplier_id
                  WHERE i.supplier_id = ? AND i.fakturoid_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['fakturoid_id']] = [
                    'id' => (int) $row['id'],
                    'invoice_type' => (string) $row['invoice_type'],
                    'parent_fakturoid_id' => (int) ($row['parent_fakturoid_id'] ?? 0),
                    'parent_type' => (string) ($row['parent_type'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Rodič dokladu, jen dosud nenavázaného. Doklad ani rodič nesmí čerpat zálohu po
     * dvojicích: vazba by čerpání zdvojila ({@see AdvanceDeductionRepository::assertLinkAllowed()}).
     */
    private function setParent(PDO $pdo, int $supplierId, int $childId, int $parentId, string $childType): int
    {
        $stmt = $pdo->prepare(
            'UPDATE invoices f SET f.parent_invoice_id = ?
              WHERE f.id = ? AND f.supplier_id = ? AND f.invoice_type = ? AND f.parent_invoice_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_advance_deductions d
                                 WHERE d.supplier_id = f.supplier_id AND (d.final_invoice_id = f.id OR d.advance_invoice_id = ?))'
        );
        $stmt->execute([$parentId, $childId, $supplierId, $childType, $parentId]);
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    /** Platba zálohy, kterou daňový doklad dokumentuje (jediná platba bez dokladu). */
    private function linkPayment(PDO $pdo, int $supplierId, int $proformaId, int $taxDocumentId): void
    {
        $stmt = $pdo->prepare('SELECT id, tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?');
        $stmt->execute([$supplierId, $proformaId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($payments) !== 1 || $payments[0]['tax_document_invoice_id'] !== null) {
            return;
        }
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$taxDocumentId, (int) $payments[0]['id'], $supplierId]);
    }
}
