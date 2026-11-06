<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Jediné pravidlo, kolik z přijaté platby je „platba určená k následnému čerpání nebo
 * zúčtování" (`urceno_cerp_zuct`, § 17 odst. 2 písm. a) ZoET): podíl položek dokladu
 * se skladovou kartou druhu `stored_value` (hodnotový poukaz na blíže nespecifikované
 * zboží, dobití kreditu v omezené síti).
 *
 * Dárkový poukaz na konkrétní zboží či službu (`specific_voucher`) je běžný prodej
 * toho plnění a do podílu nepatří, stejně jako běžné zboží (`standard`).
 *
 * Podíl se počítá z řádků dokladu (součet `invoice_items.total_with_vat`), ne z hlavičky.
 * Platba dokladu nese poměrnou část: částečná úhrada kryje položky poměrem, jako by
 * každá koruna platby kryla celý doklad stejným dílem. Zaokrouhlení je na celé haléře
 * (polovina nahoru) a počítá se KUMULATIVNĚ ze součtu úhrad před platbou a po ní:
 * část této platby = podíl(uhrazeno po) − podíl(uhrazeno před). Haléřové rozdíly se
 * tak mezi úhradami nesčítají, součet přes všechny úhrady nikdy nepřesáhne hodnotu
 * položek `stored_value` a úhrada celého dokladu dá přesně tuto hodnotu. Úhrada nad
 * hrubou částku řádků (zaokrouhlení dokladu) další kredit nenese.
 *
 * Doklad v cizí měně: podíl se určí v měně dokladu a na Kč se převede poměrem CZK částky
 * a cizí částky téže platby (tedy kurzem, kterým se eviduje celá tržba), nikdy nad CZK
 * částku platby.
 *
 * Pokladní doklad nemá vlastní řádky: příjmový doklad k úhradě faktury nese podíl
 * faktury, samostatný prodej z pokladny kredit neurčuje.
 */
final class EetStoredValueCalculator
{
    public const KIND_STANDARD = 'standard';
    public const KIND_SPECIFIC_VOUCHER = 'specific_voucher';
    public const KIND_STORED_VALUE = 'stored_value';

    /** Povolené hodnoty `stock_items.eet_item_kind` (pořadí = ENUM v migraci 2185). */
    public const ITEM_KINDS = [self::KIND_STANDARD, self::KIND_SPECIFIC_VOUCHER, self::KIND_STORED_VALUE];

    public function __construct(private readonly Connection $db) {}

    /**
     * Hrubá částka řádků dokladu a z ní hodnota položek `stored_value` (haléře měny dokladu).
     *
     * @return array{gross_cents:int, stored_value_cents:int}
     */
    public function forInvoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(ii.total_with_vat), 0) AS gross,
                    COALESCE(SUM(CASE WHEN si.eet_item_kind = 'stored_value' THEN ii.total_with_vat ELSE 0 END), 0) AS stored
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
               LEFT JOIN stock_items si ON si.id = ii.stock_item_id AND si.supplier_id = i.supplier_id
              WHERE i.id = ? AND i.supplier_id = ?"
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['gross' => '0', 'stored' => '0'];
        return [
            'gross_cents' => EetValues::toCents(EetValues::amount((string) $row['gross'])),
            'stored_value_cents' => EetValues::toCents(EetValues::amount((string) $row['stored'])),
        ];
    }

    /**
     * Kredit (`urceno_cerp_zuct`) v haléřích Kč, který nese úhrada faktury, nebo null.
     *
     * @param int $paidBeforeCents úhrady faktury před touto platbou (měna faktury)
     * @param int $amountCents     tato platba v měně faktury
     * @param int $amountCzkCents  tatáž platba v Kč (částka tržby)
     */
    public function forInvoicePayment(int $supplierId, int $invoiceId, int $paidBeforeCents, int $amountCents, int $amountCzkCents): ?int
    {
        $doc = $this->forInvoice($supplierId, $invoiceId);
        $part = self::share($doc['gross_cents'], $doc['stored_value_cents'], $paidBeforeCents, $amountCents);
        if ($part <= 0 || $amountCents <= 0 || $amountCzkCents <= 0) {
            return null;
        }
        $czk = $amountCzkCents === $amountCents ? $part : self::proportion($part, $amountCzkCents, $amountCents);
        $czk = min($czk, $amountCzkCents);
        return $czk > 0 ? $czk : null;
    }

    /**
     * Kredit příjmového pokladního dokladu. Doklad k úhradě faktury nese podíl faktury
     * (úhrada dokladu je v té chvíli už zapsaná v `paid_total`), samostatný prodej nic.
     *
     * @param array<string,mixed> $doc řádek cash_documents
     */
    public function forCashDocument(int $supplierId, array $doc, int $amountCzkCents): ?int
    {
        $invoiceId = (int) ($doc['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT paid_total FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $paid = $stmt->fetchColumn();
        if ($paid === false) {
            return null;
        }
        // Valutová pokladna: úhrada faktury je v cizí částce dokladu, CZK je jen její ekvivalent.
        $docAmount = ($doc['amount_foreign'] ?? null) !== null ? $doc['amount_foreign'] : ($doc['total_amount'] ?? 0);
        $docCents = EetValues::toCents(EetValues::amount((float) $docAmount));
        $paidAfter = EetValues::toCents(EetValues::amount((string) $paid));
        return $this->forInvoicePayment($supplierId, $invoiceId, $paidAfter - $docCents, $docCents, $amountCzkCents);
    }

    /**
     * Podíl platby na položkách `stored_value` v haléřích měny dokladu (kumulativně, viz třída).
     */
    public static function share(int $grossCents, int $storedCents, int $paidBeforeCents, int $amountCents): int
    {
        if ($grossCents <= 0 || $storedCents <= 0 || $amountCents <= 0) {
            return 0;
        }
        $stored = min($storedCents, $grossCents);
        $before = max(0, min($paidBeforeCents, $grossCents));
        $after = max(0, min($paidBeforeCents + $amountCents, $grossCents));
        $portion = static fn (int $paid): int => $paid >= $grossCents ? $stored : self::proportion($paid, $stored, $grossCents);
        return max(0, min($amountCents, $portion($after) - $portion($before)));
    }

    /**
     * Kladné `value × numerator / denominator` zaokrouhlené na celé haléře (polovina
     * nahoru), bez přetečení a bez plovoucí čárky.
     */
    public static function proportion(int $value, int $numerator, int $denominator): int
    {
        if ($value <= 0 || $numerator <= 0 || $denominator <= 0) {
            return 0;
        }
        $twice = bcmul(bcmul((string) $value, (string) $numerator), '2');
        return (int) bcdiv(bcadd($twice, (string) $denominator), bcmul((string) $denominator, '2'), 0);
    }
}
