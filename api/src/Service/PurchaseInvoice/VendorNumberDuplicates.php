<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

/**
 * Vědomě povolený duplikát čísla přijatého dokladu (issue #140).
 *
 * Platby jednoho platebního kalendáře (typicky energie) nesou stejného dodavatele,
 * stejné číslo dokladu a často i stejné datum vystavení. Unikátní index
 * `uq_pi_vendor_invoice` je proto rozšířený o pořadí `vendor_number_seq`: běžný doklad
 * má 0 a kontrola funguje přesně jako dřív. Pořadí > 0 dostane doklad jen tehdy,
 * když uživatel shodu výslovně potvrdí (`allow_duplicate_number`).
 */
final class VendorNumberDuplicates
{
    public static function allowed(array $body): bool
    {
        return filter_var($body['allow_duplicate_number'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Nejbližší volné pořadí pro dvojici číslo + datum vystavení u dodavatele, nebo 0,
     * když shoda neexistuje. Volat uvnitř transakce, řádky se zamykají.
     */
    public static function nextSeq(
        PDO $pdo,
        int $supplierId,
        int $vendorId,
        string $vendorInvoiceNumber,
        string $issueDate,
        ?int $excludeId = null,
    ): int {
        $stmt = $pdo->prepare(
            'SELECT MAX(vendor_number_seq)
               FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ?
                AND id <> ?
                FOR UPDATE'
        );
        $stmt->execute([$supplierId, $vendorId, trim($vendorInvoiceNumber), $issueDate, $excludeId ?? 0]);
        $max = $stmt->fetchColumn();

        return $max === null || $max === false ? 0 : (int) $max + 1;
    }

    /**
     * Doklady, se kterými se číslo a datum vystavení u dodavatele shodují — pro hlášku
     * o duplicitě (je-li shoda jen se stornovaným dokladem, nabízí se i vrácení ze storna).
     *
     * @return list<array{id:int, status:string, varsymbol:?string}>
     */
    public static function existing(
        PDO $pdo,
        int $supplierId,
        int $vendorId,
        string $vendorInvoiceNumber,
        string $issueDate,
        ?int $excludeId = null,
    ): array {
        $stmt = $pdo->prepare(
            'SELECT id, status, varsymbol
               FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ?
                AND id <> ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $vendorId, trim($vendorInvoiceNumber), $issueDate, $excludeId ?? 0]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'varsymbol' => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Pravděpodobné duplicity s JINÝM datem vystavení: nestornovaný doklad téhož dodavatele
     * se stejným číslem dokladu, vystavený nejvýš rok před nebo po. Typicky tentýž doklad
     * převzatý z jiného systému a znovu vytěžený z PDF, kde se datum přečetlo jinak.
     * Unikátní index (číslo + datum) takovou shodu nechytí, proto jde jen o varování.
     *
     * Kandidáty vybírá indexovaný klíč `vendor_invoice_number_match_key` (číslice bez
     * vodicích nul, migrace 2010); mezi nimi se drží jen ty, které se shodují i v písmenech
     * (bez mezer a oddělovačů), aby zálohová „ZF 2025001" nehlásila fakturu „FV 2025001".
     * Číslo bez číslic (účtenka „BEZ-CISLA-…" má číslice v hashi) klíč nemá a shodu nehledá.
     *
     * @return list<array{id:int, vendor_invoice_number:string, issue_date:string, total_with_vat:float, currency:string, status:string, varsymbol:?string}>
     */
    public static function likely(
        PDO $pdo,
        int $supplierId,
        int $vendorId,
        ?string $vendorInvoiceNumber,
        ?string $issueDate,
        ?int $excludeId = null,
    ): array {
        $number = trim((string) $vendorInvoiceNumber);
        $key = VariableSymbolNormalizer::matchKey($number);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim((string) $issueDate), 0, 10));
        if ($vendorId <= 0 || $key === '' || $date === false) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT pi.id, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat, pi.status, pi.varsymbol,
                    cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.vendor_id = ? AND pi.vendor_invoice_number_match_key = ?
                AND pi.issue_date >= ? AND pi.issue_date <= ? AND pi.issue_date <> ?
                AND pi.status <> 'cancelled' AND pi.id <> ?
              ORDER BY pi.issue_date DESC, pi.id DESC
              LIMIT 20"
        );
        $stmt->execute([
            $supplierId,
            $vendorId,
            $key,
            $date->modify('-1 year')->format('Y-m-d'),
            $date->modify('+1 year')->format('Y-m-d'),
            $date->format('Y-m-d'),
            $excludeId ?? 0,
        ]);
        $own = self::comparable($number);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::comparable((string) $r['vendor_invoice_number']) !== $own) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'vendor_invoice_number' => (string) $r['vendor_invoice_number'],
                'issue_date' => substr((string) $r['issue_date'], 0, 10),
                'total_with_vat' => (float) $r['total_with_vat'],
                'currency' => (string) $r['currency'],
                'status' => (string) $r['status'],
                'varsymbol' => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            ];
        }

        return $out;
    }

    /**
     * Pravděpodobné duplicity už uloženého dokladu (číslo, dodavatel a datum bere z řádku).
     *
     * @return list<array{id:int, vendor_invoice_number:string, issue_date:string, total_with_vat:float, currency:string, status:string, varsymbol:?string}>
     */
    public static function likelyForInvoice(PDO $pdo, int $id, int $supplierId): array
    {
        $stmt = $pdo->prepare(
            'SELECT vendor_id, vendor_invoice_number, issue_date FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }

        return self::likely(
            $pdo,
            $supplierId,
            (int) $row['vendor_id'],
            (string) $row['vendor_invoice_number'],
            (string) $row['issue_date'],
            $id,
        );
    }

    /**
     * Věta do hlášení u dokladu (`extraction_warning`) o pravděpodobné duplicitě.
     *
     * @param list<array{id:int, vendor_invoice_number:string, issue_date:string, total_with_vat:float, currency:string, status:string, varsymbol:?string}> $matches
     */
    public static function likelyWarning(array $matches): ?string
    {
        if ($matches === []) {
            return null;
        }
        $lines = array_map(static function (array $m): string {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m['issue_date']);
            $internal = $m['varsymbol'] !== null && $m['varsymbol'] !== '' ? $m['varsymbol'] : '#' . $m['id'];

            return sprintf(
                'Možná duplicita: doklad č. %s od tohoto dodavatele už existuje (datum vystavení %s, částka %s %s, v evidenci %s).',
                $m['vendor_invoice_number'],
                $date !== false ? $date->format('j. n. Y') : $m['issue_date'],
                number_format($m['total_with_vat'], 2, ',', ' '),
                $m['currency'],
                $internal,
            );
        }, $matches);

        return implode("\n", $lines) . "\nZkontrolujte, zda nejde o tentýž doklad s jinak přečteným datem vystavení.";
    }

    /** Číslo dokladu pro porovnání: velká písmena a číslice, bez mezer a oddělovačů. */
    private static function comparable(string $number): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($number)));
    }

    /**
     * Doplňující údaje k chybě vendor_invoice_duplicate: stornovaný protějšek, je-li
     * jediný, a text pro uživatele (stornovaný doklad lze vrátit ze storna, nebo uložit
     * nový doklad přesto).
     *
     * @param list<array{id:int, status:string, varsymbol:?string}> $existing
     * @return array{message: ?string, details: array<string,mixed>}
     */
    public static function describe(array $existing, ?string $vendorInvoiceNumber): array
    {
        $active = array_values(array_filter($existing, static fn (array $r): bool => $r['status'] !== 'cancelled'));
        if ($existing === [] || $active !== []) {
            return ['message' => null, 'details' => ['duplicate_ids' => array_column($existing, 'id')]];
        }
        $cancelled = $existing[count($existing) - 1];
        $num = trim((string) ($vendorInvoiceNumber ?? ''));
        $label = $cancelled['varsymbol'] !== null && $cancelled['varsymbol'] !== '' ? $cancelled['varsymbol'] : '#' . $cancelled['id'];

        return [
            'message' => ($num !== '' ? "Přijatá faktura číslo '{$num}' od tohoto dodavatele s tímto datem vystavení" : 'Přijatá faktura s tímto číslem a datem vystavení')
                . " už existuje, ale je stornovaná ({$label}). Můžete ji vrátit ze storna, nebo uložit nový doklad přesto.",
            'details' => [
                'duplicate_ids' => array_column($existing, 'id'),
                'cancelled_duplicate_id' => $cancelled['id'],
                'cancelled_duplicate_number' => $label,
            ],
        ];
    }
}
