<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Sazby DPH zálohové faktury pro doklady, které z ní vznikají (konečná faktura, daňový
 * doklad k přijaté platbě). Jediné místo pravidla „položky zálohy sazbu plnění nenesou".
 *
 * Konečná faktura kopíruje položky zálohy a daňové doklady k platbám (DDKP) odečítá
 * zápornými řádky po jejich sazbách ({@see FinalFromProformaCreator}). Když položky zálohy
 * nesou jen 0 % a DDKP 21 %, vyjde faktura se zápornou DPH a sníží daň na výstupu.
 *
 *  - Záloha s položkami bez daně a DDKP s daní ({@see itemsWithoutVat()}): sazby plnění
 *    nesou DDKP, položky konečné faktury se vezmou z nich. Platí pro každou zálohu: DDKP
 *    vystavený v MyÚčtu má sazby položek zálohy ({@see PaymentTaxDocumentCreator}), takže
 *    nativní záloha do této větve nespadne; spadne do ní záloha převzatá z programu, jehož
 *    DDKP sazby nesou a záloha ne.
 *  - Záloha převzatá z Money S3 s položkami bez daně ({@see ratesUnknown()}): Money vede
 *    zálohové faktury jen částkou bez sazby (`Zaklad_0`), 0 % tu není sazba plnění. Mají-li
 *    všechny její DDKP jedinou sazbu ({@see singleRate()}), je sazbou plnění i pro DDKP
 *    k nové platbě a nepokrytý zbytek konečné faktury. Bez DDKP nebo při víc sazbách se
 *    u plátce konečná faktura ani DDKP automaticky nevystaví; DDKP vystaví uživatel se
 *    zvolenou sazbou.
 *    Převody z POHODY, ABRY, PREMIERu, Stereo NX, iDokladu a Fakturoidu přebírají položky
 *    zálohy se sazbou zdroje, 0 % je u nich skutečná sazba (osvobozené plnění), proto je
 *    pravidlo neblokuje.
 */
final class ProformaVatRates
{
    /** Kód výjimky „sazbu plnění zálohy musí zvolit uživatel" ({@see PaymentTaxDocumentNotApplicableException}). */
    public const RATE_REQUIRED = 4221;

    /** Chybový kód API pro zálohu bez sazeb DPH (konečná faktura i DDKP). */
    public const ERROR_CODE = 'proforma_without_vat_rates';

    /**
     * Položky zálohy nenesou daň: žádná nemá kladnou sazbu ani daň.
     *
     * @param list<array<string,mixed>> $items
     */
    public static function itemsWithoutVat(array $items): bool
    {
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if ((float) ($item['vat_rate_snapshot'] ?? 0) > 0.0 || abs((float) ($item['total_vat'] ?? 0)) >= 0.005) {
                return false;
            }
        }
        return true;
    }

    /**
     * Řádky DDKP zálohy po sazbách (§ 37a odpočet na konečné faktuře): živé DDKP navázané
     * rodičem i vazbou platby, po dokladu, sazbě, klasifikaci a místu plnění, se základem,
     * daní a celkem. `$ossSelect` = sloupce místa plnění ({@see \MyInvoice\Service\Oss\OssItemCarryOver::selectList()})
     * nad aliasem `ii`. Jediný dotaz pro konečnou fakturu i DDKP k nové platbě.
     *
     * @return list<array<string,mixed>>
     */
    public static function taxDocumentRows(PDO $pdo, string $ossSelect, int $proformaId): array
    {
        // OSS profil vstupuje do SELECT i do GROUP BY: dva řádky daňového dokladu se
        // stejnou sazbou, ale různým místem plnění (OSS do PL × tuzemský), se nesmí
        // slít do jednoho odpočtového řádku. Totéž platí pro klasifikaci: odpočet ruší přesně
        // to, co doklad k platbě vykázal, takže 1c a 26c zůstávají na svých řádcích přiznání.
        // Doklady se hledají přes parent_invoice_id I přes vazbu plateb — kdyby vazba
        // parent chyběla (historicky rozpojený doklad), odpočet nesmí vypadnout.
        $stmt = $pdo->prepare(
            "SELECT td.id, td.varsymbol, ii.vat_rate_id, ii.vat_rate_snapshot, ii.vat_classification_code{$ossSelect},
                    SUM(ii.total_without_vat) AS base, SUM(ii.total_vat) AS vat,
                    SUM(ii.total_with_vat) AS gross
               FROM invoices td
               JOIN invoice_items ii ON ii.invoice_id = td.id
              WHERE td.invoice_type = 'tax_document'
                AND td.status NOT IN ('draft', 'cancelled')
                AND (td.parent_invoice_id = ?
                     OR td.id IN (SELECT p.tax_document_invoice_id FROM invoice_payments p
                                   WHERE p.invoice_id = ? AND p.tax_document_invoice_id IS NOT NULL))
           GROUP BY td.id, td.varsymbol, ii.vat_rate_id, ii.vat_rate_snapshot, ii.vat_classification_code{$ossSelect}
           ORDER BY td.id, ii.vat_rate_snapshot DESC"
        );
        $stmt->execute([$proformaId, $proformaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Jediná sazba DDKP zálohy: všechny řádky DDKP s daní mají stejnou sazbu, klasifikaci
     * i místo plnění. Vrátí její sloupce (bez částek), jinak `null` (žádný DDKP s daní, nebo
     * víc sazeb). Sazbou plnění zálohy bez sazeb je pak i pro DDKP k nové platbě a pro část
     * konečné faktury, kterou DDKP nepokrývají (záloha uhrazená přes hranici roku).
     *
     * @param list<array<string,mixed>> $rows {@see taxDocumentRows()}
     * @return array<string,mixed>|null
     */
    public static function singleRate(array $rows): ?array
    {
        $groups = [];
        foreach ($rows as $r) {
            if (abs((float) $r['vat']) < 0.005 && (float) $r['vat_rate_snapshot'] <= 0.0) {
                continue;
            }
            $rate = array_diff_key($r, array_flip(['id', 'varsymbol', 'base', 'vat', 'gross']));
            $groups[serialize(array_map(static fn ($v): string => (string) $v, $rate))] = $rate;
        }
        return count($groups) === 1 ? reset($groups) : null;
    }

    /**
     * Sazba zvolená uživatelem: kladná tuzemská sazba platná k datu plnění.
     *
     * @return array{vat_rate_id:int,vat_rate_snapshot:string,vat_classification_code:null}
     * @throws \RuntimeException neplatná sazba
     */
    public static function chosenRate(PDO $pdo, int $vatRateId, string $date): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, rate_percent FROM vat_rates
              WHERE id = ? AND rate_percent > 0 AND is_reverse_charge = 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$vatRateId, $date, $date]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException('Zvolená sazba DPH k datu platby neplatí.');
        }
        return ['vat_rate_id' => (int) $r['id'], 'vat_rate_snapshot' => (string) $r['rate_percent'], 'vat_classification_code' => null];
    }

    /**
     * Položka zálohy bez sazeb přeceněná na sazbu plnění: celá částka zálohy v jedné sazbě
     * (s místem plnění a klasifikací sazby). Pro rozpad úplaty na DDKP podle sazeb.
     *
     * @param array<string,mixed> $proforma
     * @param array<string,mixed> $rate {@see singleRate()} nebo {@see chosenRate()}
     * @return array<string,mixed>
     */
    public static function itemAtRate(array $proforma, array $rate): array
    {
        $first = ((array) ($proforma['items'] ?? []))[0] ?? [];
        return array_replace($first, $rate, ['total_with_vat' => (float) $proforma['total_with_vat']]);
    }

    /**
     * SQL podmínka „záloha `$alias` převzatá z Money S3 nenese sazby a sazbu jí neurčuje ani
     * jediná sazba DDKP" (bez živého DDKP s daní): DDKP k platbě automatika nevystaví.
     */
    public static function withoutRatesSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
        return "(NOT EXISTS (SELECT 1 FROM invoice_items pvr WHERE pvr.invoice_id = {$alias}.id AND (pvr.vat_rate_snapshot > 0 OR ABS(pvr.total_vat) >= 0.005))
                 AND EXISTS (SELECT 1 FROM money_s3_import_map pvm WHERE pvm.supplier_id = {$alias}.supplier_id AND pvm.kind = 'invoice' AND pvm.target_id = {$alias}.id)
                 AND NOT EXISTS (SELECT 1 FROM invoices pvt WHERE pvt.supplier_id = {$alias}.supplier_id AND pvt.parent_invoice_id = {$alias}.id
                                   AND pvt.invoice_type = 'tax_document' AND pvt.status NOT IN ('draft', 'cancelled') AND ABS(pvt.total_vat) >= 0.005))";
    }

    /** Záloha převzatá z Money S3, které zálohové faktury sazbu nenesou. */
    public static function fromSourceWithoutRates(PDO $pdo, int $supplierId, int $proformaId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM money_s3_import_map WHERE supplier_id = ? AND kind = ? AND target_id = ? LIMIT 1');
        $stmt->execute([$supplierId, MoneyS3ImportRepository::KIND_INVOICE, $proformaId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sazbu plnění zálohy nejde určit: záloha převzatá z Money S3, položky bez daně
     * a plátce DPH k datu `$date`.
     *
     * @param array<string,mixed> $proforma řádek {@see \MyInvoice\Repository\InvoiceRepository::find()} s položkami
     */
    public static function ratesUnknown(PDO $pdo, array $proforma, string $date): bool
    {
        return self::itemsWithoutVat((array) ($proforma['items'] ?? []))
            && self::fromSourceWithoutRates($pdo, (int) $proforma['supplier_id'], (int) $proforma['id'])
            && VatStatusService::payerAt($pdo, (int) $proforma['supplier_id'], $date);
    }
}
