<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;
use PDO;

/**
 * Evidence drobného majetku z PREMIER ({@see PremierSmallAssets}).
 *
 * - **PREMIER evidenci vede** (karty řad drobného majetku v `MAJETEK` / `MAJ_H`, operativní
 *   evidence `MAJ_OST`): karty se převezmou 1:1 - název, inventární číslo, datum pořízení
 *   a zařazení, množství a cena, umístění, odpovědná osoba, vyřazení. Karta nic neúčtuje,
 *   náklad je v převedeném deníku.
 * - **PREMIER evidenci nevede**: karty založí {@see SmallAssetService} z položek přijatých
 *   faktur roku, které převod označil jako drobný majetek (účet drobného majetku, cena za
 *   kus od {@see PremierSmallAssets::THRESHOLD} Kč). Dobropis, který vrací věc, kartu
 *   vyřadí - PREMIER vazbu dobropisu na fakturu nevede, páruje se proto dodavatel a název
 *   karty, a to jen jednoznačně.
 */
final class SmallAssetImporter
{
    public const STEP = 'small_assets';

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly SmallAssetService $cards,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $policy = $ctx->smallAssets ?? PremierSmallAssets::fromBackup($ctx->backup);
        if ($policy->hasEvidence) {
            $this->importEvidence($ctx, $policy);
        } else {
            $this->fromPurchaseInvoices($ctx);
        }
        $p->finish(self::STEP);
    }

    /** Karty evidence drobného majetku PREMIER. */
    private function importEvidence(PremierContext $ctx, PremierSmallAssets $policy): void
    {
        $end = $ctx->endsOn();
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_SMALL_ASSET);
        $cards = [];
        foreach (['MAJETEK', 'MAJ_H'] as $table) {
            foreach ($ctx->backup->rows($table) as $r) {
                [$register, $kind] = $policy->register((string) ($r['DOKLAD'] ?? ''));
                if ($register !== PremierSmallAssets::REGISTER_SMALL) {
                    continue;
                }
                $count = (float) ($r['KUSY'] ?? 0);
                $cards[] = [
                    'key' => $table . '|' . self::id($r),
                    'kind' => $kind ?? 'tangible',
                    'name' => trim((string) ($r['POPIS'] ?? '')),
                    'number' => trim((string) ($r['CISLO'] ?? '')),
                    'acquired' => self::date($r['DATUM_P'] ?? null) ?? self::date($r['DATUM'] ?? null),
                    'in_use' => self::date($r['DATUM_UO'] ?? null) ?? self::date($r['DATUM'] ?? null),
                    'disposed' => self::date($r['DATUM_V'] ?? null),
                    'quantity' => $count > 0 ? $count : 1.0,
                    'price' => round((float) ($r['CENA'] ?? 0), 2),
                    'location' => trim((string) ($r['UMISTENI'] ?? '')),
                    'person' => trim((string) ($r['JMENO_ODP'] ?? '')),
                    'note' => trim((string) ($r['POZNAMKA'] ?? '')),
                ];
            }
        }
        foreach ($ctx->backup->rows('MAJ_OST') as $r) {
            [$register, $kind] = $policy->register((string) ($r['DOKLAD'] ?? ''));
            $quantity = (float) ($r['MNOZSTVI'] ?? 0);
            $price = round((float) ($r['CENA'] ?? 0), 2);
            if (abs($price) < 0.005) {
                $price = round((float) ($r['CENA_KS'] ?? 0) * ($quantity > 0 ? $quantity : 1.0), 2);
            }
            $cards[] = [
                'key' => 'MAJ_OST|' . self::id($r),
                'kind' => $register === PremierSmallAssets::REGISTER_SMALL ? ($kind ?? 'tangible') : 'tangible',
                'name' => trim((string) ($r['POPIS'] ?? '')) ?: trim((string) ($r['TYP'] ?? '')),
                'number' => trim((string) ($r['EVI_CIS'] ?? '')) ?: trim((string) ($r['CISLO'] ?? '')),
                'acquired' => self::date($r['ZARAZENO'] ?? null),
                'in_use' => self::date($r['ZARAZENO'] ?? null),
                'disposed' => self::date($r['VYRAZENO'] ?? null),
                'quantity' => $quantity > 0 ? $quantity : 1.0,
                'price' => $price,
                'location' => trim((string) ($r['UMISTENI'] ?? '')),
                'person' => trim((string) ($r['JMENO_ODP'] ?? '')),
                'note' => trim((string) ($r['POZNAMKA'] ?? '')),
            ];
        }
        foreach ($cards as $card) {
            if (isset($existing[$card['key']])) {
                $ctx->protocol->count(self::STEP, 'existing');
                continue;
            }
            if ($card['acquired'] === null || $card['acquired'] > $end) {
                $ctx->protocol->count(self::STEP, 'later_years');
                continue;
            }
            $disposed = SmallAssetCard::disposedWithin($card['disposed'], $card['acquired'], $end);
            $id = $this->cards->create($ctx->supplierId, SmallAssetCard::payload(
                $card['kind'],
                $card['name'] !== '' ? $card['name'] : SmallAssetCard::DEFAULT_NAME,
                SmallAssetCard::inventoryNumber($card['number'], true),
                $card['acquired'],
                $card['in_use'],
                $card['quantity'],
                UnitPricePrecision::normalize($card['price'] / $card['quantity']),
                $card['price'],
                $card['location'],
                $disposed,
                'Vyřazeno v PREMIER',
                'Převzato z evidence drobného majetku PREMIER' . ($card['note'] !== '' ? ': ' . $card['note'] : '.'),
                ['responsible_person' => $card['person'] !== '' ? mb_substr($card['person'], 0, 160) : null],
            ), $ctx->userOrNull());
            $this->map->put($ctx->supplierId, PremierImportRepository::KIND_SMALL_ASSET, $card['key'], $id, $ctx->runId);
            $ctx->protocol->count(self::STEP, $disposed !== null ? 'created_disposed' : 'created');
        }
    }

    /**
     * Karty z položek přijatých faktur roku označených jako drobný majetek; dobropis
     * vrácenou věc vyřadí.
     */
    private function fromPurchaseInvoices(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $ids = array_values(array_unique($ctx->purchaseInvoices));
        if ($ids === []) {
            return;
        }
        $pdo = $this->db->pdo();
        // Cena karty je v Kč - položka dokladu v cizí měně se přepočte kurzem dokladu.
        $stmt = $pdo->prepare(
            "SELECT pi.id, pi.document_kind, pi.vendor_id, pi.varsymbol, COALESCE(pi.tax_date, pi.issue_date) AS acquired,
                    pii.description, " . ForeignCurrencyTakeover::homeAmountSql('pii.total_without_vat', 'pi.exchange_rate') . " AS total_without_vat
               FROM purchase_invoices pi
               JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
              WHERE pi.supplier_id = ? AND pi.status <> 'draft' AND pii.expense_kind IN ('small_asset', 'small_intangible')
                AND pi.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY COALESCE(pi.tax_date, pi.issue_date), pi.id'
        );
        $stmt->execute(array_merge([$ctx->supplierId], $ids));
        $byInvoice = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byInvoice[(int) $row['id']][] = $row;
        }
        $handled = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_SMALL_ASSET);
        foreach ($byInvoice as $invoiceId => $rows) {
            $first = $rows[0];
            $isReturn = $first['document_kind'] === 'credit_note' || array_sum(array_column($rows, 'total_without_vat')) < 0;
            if ($isReturn) {
                foreach ($rows as $row) {
                    // Vyřízený dobropis (vyřazená karta i upozornění) se při opakovaném běhu nehlásí znovu.
                    $key = 'return|' . $invoiceId . '|' . md5(mb_strtolower(trim((string) $row['description'])) . '|' . $row['total_without_vat']);
                    if (isset($handled[$key])) {
                        $p->count(self::STEP, 'existing');
                        continue;
                    }
                    $cardId = $this->disposeReturned($ctx, $row);
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_SMALL_ASSET, $key, $cardId ?? $invoiceId, $ctx->runId);
                }
                continue;
            }
            $result = $this->cards->generateFromPurchaseInvoice($ctx->supplierId, $invoiceId, $ctx->userOrNull());
            if ($result['created'] !== []) {
                $p->count(self::STEP, 'created', count($result['created']));
            }
            if ($result['skipped'] > 0) {
                $p->count(self::STEP, 'existing', $result['skipped']);
            }
        }
    }

    /**
     * Dobropis vracející drobný majetek: vyřadí jedinou kartu téhož dodavatele a názvu,
     * která je v užívání a pořízená nejpozději k datu dobropisu; při více kandidátech
     * rozhodne shodná cena. Jinak jen upozorní.
     *
     * @param array<string,mixed> $row
     * @return int|null id vyřazené karty
     */
    private function disposeReturned(PremierContext $ctx, array $row): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, price FROM small_assets
              WHERE supplier_id = ? AND vendor_client_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))
                AND status = 'in_use' AND acquisition_date <= ?"
        );
        $stmt->execute([$ctx->supplierId, $row['vendor_id'], (string) $row['description'], $row['acquired']]);
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($found) > 1) {
            $returned = round(abs((float) $row['total_without_vat']), 2);
            $found = array_values(array_filter($found, static fn (array $c): bool => abs((float) $c['price'] - $returned) < 0.005));
        }
        if (count($found) !== 1) {
            $ctx->protocol->count(self::STEP, 'returns_review');
            $ctx->protocol->warn(self::STEP, 'return_not_matched', sprintf(
                'Dobropis %s vrací drobný majetek „%s", kartu k vyřazení ale nejde určit jednoznačně (%d kandidátů). Vyřaďte ji ručně v evidenci drobného majetku.',
                $row['varsymbol'], $row['description'], count($found)
            ), ['document_no' => $row['varsymbol']]);
            return null;
        }
        $id = (int) $found[0]['id'];
        $this->cards->dispose($ctx->supplierId, $id, (string) $row['acquired'], 'Vráceno dodavateli - dobropis ' . $row['varsymbol']);
        $ctx->protocol->count(self::STEP, 'disposed_by_credit_note');
        return $id;
    }

    /** @param array<string,mixed> $r */
    private static function id(array $r): string
    {
        $id = trim((string) ($r['ID'] ?? ''));
        return $id !== '' ? $id : (string) (int) ($r['INTER'] ?? 0);
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
