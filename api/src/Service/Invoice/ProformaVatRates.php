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
 *  - Záloha převzatá z Money S3 s položkami bez daně a bez DDKP s daní ({@see ratesUnknown()}):
 *    Money vede zálohové faktury jen částkou bez sazby (`Zaklad_0`), 0 % tu není sazba
 *    plnění. U plátce se konečná faktura ani DDKP z ní nevystaví, sazbu doplní uživatel.
 *    Převody z POHODY, ABRY, PREMIERu, Stereo NX, iDokladu a Fakturoidu přebírají položky
 *    zálohy se sazbou zdroje, 0 % je u nich skutečná sazba (osvobozené plnění), proto je
 *    pravidlo neblokuje.
 */
final class ProformaVatRates
{
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
