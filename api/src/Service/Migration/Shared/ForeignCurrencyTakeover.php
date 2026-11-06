<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Převzetí dokladu v cizí měně (EUR…) z cizího účetního programu v jeho měně a kurzu.
 *
 * Zdrojové programy vedou u cizoměnového dokladu vedle částek v měně i částky v Kč, ze
 * kterých sestavily přiznání DPH, kontrolní hlášení i deník. Převod dřív zakládal takový
 * doklad v Kč bez měny a kurzu - DPH bylo správně, ale ztratila se měna dokladu, kurz
 * a částky v měně, takže nešlo spárovat platbu v EUR ani spočítat kurzový rozdíl.
 *
 * **Pojistka:** doklad se převezme v měně jen tehdy, když MyÚčto z částek v měně a kurzu
 * dokladu spočítá PŘESNĚ tytéž koruny, které má zdroj, a to na haléř:
 *  - u každé položky základ i daň, a to oběma způsoby, kterými aplikace cizí měnu
 *    přepočítává - bcmath HALF_UP ({@see CzkRecap::multiplyHalfUp()}, rekapitulace dokladu,
 *    SQL `ROUND(částka × kurz, 2)`) i `round(částka × kurz, 2)` evidence DPH
 *    ({@see \MyInvoice\Service\Report\VatLedgerService::normalize()}); evidence počítá po
 *    položkách, takže DPH, KH i OSS zůstávají v korunách beze změny;
 *  - celkem dokladu (evidence z něj bere hranici 10 000 Kč pro KH, převod podle něj
 *    kontroluje doklady proti deníku a páruje úhrady).
 * Kurz se bere zaokrouhlený na 6 míst, tak jak ho uloží sloupec `exchange_rate`.
 *
 * Jinak se doklad převezme v Kč jako dřív a důvod jde do poznámky dokladu a do protokolu.
 * Důvody, které pozná jen zdroj (samovyměření jiným kurzem, částečná úhrada, položky bez
 * částek v měně…), předá volající v `$blocked`.
 *
 * Převzatý doklad nese v měně i celkem (součet položek, zaokrouhlení 0) a úhradu - ta je
 * jen „nic" nebo „celá", protože částečnou úhradu v měně zdroje nenesou spolehlivě.
 * Kdo z převzatého dokladu potřebuje Kč, přepočte je {@see homeAmountSql()} / {@see toHome()};
 * pojistka zaručuje, že vyjdou koruny zdroje.
 */
final class ForeignCurrencyTakeover
{
    public const HOME = 'CZK';

    /** Symbol a název (česky, anglicky) běžných měn, které převod zakládá; jiná měna dostane svůj kód. */
    private const NAMES = [
        'EUR' => ['€', 'Euro', 'Euro'],
        'USD' => ['$', 'Americký dolar', 'US Dollar'],
        'GBP' => ['£', 'Britská libra', 'British Pound'],
        'CHF' => ['CHF', 'Švýcarský frank', 'Swiss Franc'],
        'PLN' => ['zł', 'Polský zlotý', 'Polish Zloty'],
        'HUF' => ['Ft', 'Maďarský forint', 'Hungarian Forint'],
        'SEK' => ['kr', 'Švédská koruna', 'Swedish Krona'],
        'NOK' => ['kr', 'Norská koruna', 'Norwegian Krone'],
        'DKK' => ['kr', 'Dánská koruna', 'Danish Krone'],
        'JPY' => ['¥', 'Japonský jen', 'Japanese Yen'],
        'CNY' => ['¥', 'Čínský jüan', 'Chinese Yuan'],
        'CAD' => ['C$', 'Kanadský dolar', 'Canadian Dollar'],
        'AUD' => ['A$', 'Australský dolar', 'Australian Dollar'],
    ];

    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    /**
     * `$createMissingCurrency` = měnu dokladu, kterou firma v číselníku nemá, převod založí
     * ({@see createCurrency()}). Zapíná si ji převod, který ji chce; bez ní se doklad
     * s chybějící měnou převezme v Kč jako dřív.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly bool $createMissingCurrency = false,
    ) {}

    /**
     * @param list<array{base:float,vat:float,foreign_base?:?float,foreign_vat?:?float}>|array<int,array<string,mixed>> $items
     *        položky dokladu s částkami v Kč (`base`, `vat`) a v měně dokladu
     * @param float $rate kurz za `$units` jednotek měny
     * @param float $homeTotal celkem dokladu v Kč ze zdroje
     * @param string|null $blocked důvod ze zdroje, proč doklad v měně převzít nejde
     */
    public function decide(int $supplierId, string $currency, float $rate, float $units, array $items, float $homeTotal, ?string $blocked = null): ForeignCurrencyDecision
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === self::HOME) {
            return ForeignCurrencyDecision::home();
        }
        $reason = $blocked ?? self::check(self::rate($rate, $units), $items, $homeTotal);
        if ($reason !== null) {
            return ForeignCurrencyDecision::keptInHome($currency, $reason);
        }
        $currencyId = $this->currencyId($supplierId, $currency);
        $created = false;
        if ($currencyId === null && $this->createMissingCurrency && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $currencyId = $this->createCurrency($supplierId, $currency);
            $created = true;
        }
        if ($currencyId === null) {
            return ForeignCurrencyDecision::keptInHome($currency, 'měna ' . $currency . ' není v číselníku měn firmy');
        }
        return ForeignCurrencyDecision::foreign($currency, $currencyId, (float) self::rate($rate, $units), $created);
    }

    /**
     * Pojistka bez databáze: `null` = částky v měně dávají kurzem přesně Kč zdroje.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function check(?float $rate, array $items, float $homeTotal): ?string
    {
        if ($rate === null) {
            return 'doklad nemá kurz';
        }
        if ($items === []) {
            return 'doklad nemá položky';
        }
        $foreignTotal = 0.0;
        $sums = ['základ' => [0.0, 0.0], 'daň' => [0.0, 0.0]];
        $n = 0;
        foreach ($items as $item) {
            $n++;
            $fb = $item['foreign_base'] ?? null;
            $fv = $item['foreign_vat'] ?? null;
            if ($fb === null || $fv === null) {
                return 'položky dokladu nemají částky v měně dokladu';
            }
            foreach ([['základ', (float) $fb, (float) $item['base']], ['daň', (float) $fv, (float) $item['vat']]] as [$what, $foreign, $home]) {
                if (!self::convertsTo($foreign, $rate, $home)) {
                    return sprintf('%s %d. položky %s × kurz %s nedává %s Kč ze zdroje', $what, $n,
                        self::amount($foreign), self::amount($rate, 6), self::amount($home));
                }
            }
            $foreignTotal += (float) $fb + (float) $fv;
            $sums['základ'][0] += (float) $fb;
            $sums['základ'][1] += (float) $item['base'];
            $sums['daň'][0] += (float) $fv;
            $sums['daň'][1] += (float) $item['vat'];
        }
        // Součty dokladu: část aplikace (obrat § 4a, rekapitulace hlavičky) přepočítává
        // základ a daň z hlavičky, ne po položkách - i ty musí dát koruny zdroje.
        foreach ($sums as $what => [$foreign, $home]) {
            if (!self::convertsTo(round($foreign, 2), $rate, round($home, 2))) {
                return sprintf('%s dokladu celkem %s × kurz %s nedává %s Kč ze zdroje', $what,
                    self::amount(round($foreign, 2)), self::amount($rate, 6), self::amount(round($home, 2)));
            }
        }
        $foreignTotal = round($foreignTotal, 2);
        if (!self::convertsTo($foreignTotal, $rate, $homeTotal)) {
            return sprintf('celkem %s × kurz %s nedává celkem %s Kč ze zdroje',
                self::amount($foreignTotal), self::amount($rate, 6), self::amount($homeTotal));
        }
        return null;
    }

    /**
     * Položky bez částek v měně, u dokladu který má v hlavičce celkem v měně: základ a daň se
     * dopočítají z Kč kurzem dokladu. Jen když dopočet přesně vrací koruny položky a součet
     * sedí na celkem z hlavičky, jinak se položky vrátí beze změny a rozhodne {@see check()}.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function withDerivedAmounts(array $items, float $rate, float $units, float $foreignTotal): array
    {
        $perUnit = self::rate($rate, $units);
        if ($perUnit === null || abs($foreignTotal) < 0.005) {
            return $items;
        }
        $derived = [];
        $sum = 0.0;
        foreach ($items as $key => $item) {
            $base = $item['foreign_base'] ?? null;
            $vat = $item['foreign_vat'] ?? null;
            if ($base === null || $vat === null) {
                $base = round((float) $item['base'] / $perUnit, 2);
                $vat = round((float) $item['vat'] / $perUnit, 2);
                if (!self::convertsTo($base, $perUnit, (float) $item['base']) || !self::convertsTo($vat, $perUnit, (float) $item['vat'])) {
                    return $items;
                }
            }
            $derived[$key] = ['foreign_base' => (float) $base, 'foreign_vat' => (float) $vat] + $item;
            $sum += (float) $base + (float) $vat;
        }
        return abs(round($sum, 2) - $foreignTotal) < 0.005 ? $derived : $items;
    }

    /**
     * Přijatý doklad, který převzít v měně nejde bez ohledu na částky: samovyměření vyměřil
     * zdroj kurzem ke dni plnění (POHODA, Money S3 interním dokladem), ne kurzem faktury,
     * a evidence DPH by daň dopočetla z přepočteného základu jinak; poměrný nárok krátí
     * evidence až v měně dokladu, takže by haléře vyšly jinak než ve zdroji.
     */
    public static function purchaseBlock(bool $reverseCharge, string $vatDeduction): ?string
    {
        return match (true) {
            $reverseCharge => 'samovyměření DPH - zdroj daň vyměřil kurzem ke dni plnění, ne kurzem faktury',
            $vatDeduction === 'proportional' => 'poměrný nárok na odpočet',
            default => null,
        };
    }

    /**
     * Úhrada, kterou v měně dokladu vyjádřit nejde: odpočet nedaňové zálohy (zdroj ho vede
     * v Kč kurzem zálohy) a částečná úhrada (zdroj ji nese jen v Kč). Doklad bez úhrady
     * a doklad uhrazený celý projdou.
     */
    public static function paymentBlock(float $homeAdvance, float $homePaid, float $homeToPay): ?string
    {
        if (abs($homeAdvance) >= 0.005) {
            return 'odpočet nedaňové zálohy - zdroj ho vede v Kč';
        }
        if (abs($homePaid) >= 0.005 && abs($homePaid - $homeToPay) >= 0.005) {
            return 'doklad je uhrazený jen částečně - úhradu v měně dokladu zdroj nenese';
        }
        return null;
    }

    /** Uhrazená částka v měně dokladu pro doklad, který {@see paymentBlock()} pustil. */
    public static function paidInForeignCurrency(float $homePaid, float $foreignToPay): float
    {
        return abs($homePaid) < 0.005 ? 0.0 : $foreignToPay;
    }

    /** Protokol: počet dokladů v měně převzatých v ní / v Kč a u těch v Kč důvod. */
    public static function report(ImportProtocol $protocol, string $step, string $label, ForeignCurrencyDecision $decision): void
    {
        if (!$decision->isForeignDocument()) {
            return;
        }
        if ($decision->inForeignCurrency()) {
            $protocol->count($step, 'foreign_currency');
            if ($decision->currencyCreated) {
                $protocol->info($step, 'currency_created', sprintf('Měna %s v číselníku měn firmy chyběla, převod ji založil (doklad %s).', $decision->currency, $label),
                    ['document_no' => $label, 'currency' => $decision->currency]);
            }
            return;
        }
        $protocol->count($step, 'foreign_currency_in_home');
        $protocol->info($step, 'foreign_currency_in_home', sprintf('Doklad %s je v %s, převzat v Kč: %s.', $label, $decision->currency, $decision->reason),
            ['document_no' => $label, 'currency' => $decision->currency]);
    }

    /** Kurz za 1 jednotku měny s přesností sloupce `exchange_rate`; `null` = kurz chybí. */
    public static function rate(float $rate, float $units): ?float
    {
        if ($rate <= 0.0 || $units <= 0.0) {
            return null;
        }
        $perUnit = round($rate / $units, 6);
        return $perUnit > 0.0 ? $perUnit : null;
    }

    /** Částka v měně dokladu převedená na Kč kurzem dokladu (HALF_UP, jako rekapitulace i SQL). */
    public static function toHome(float $foreign, ?float $rate): float
    {
        return $rate === null ? round($foreign, 2) : CzkRecap::multiplyHalfUp($foreign, $rate);
    }

    /**
     * SQL výraz částky dokladu v Kč: doklad v Kč (bez kurzu) beze změny, doklad v měně
     * přepočtený kurzem dokladu. DECIMAL × DECIMAL a ROUND počítá MariaDB přesně
     * a zaokrouhluje od nuly - totéž co {@see toHome()}.
     */
    public static function homeAmountSql(string $amount, string $rate): string
    {
        return "(CASE WHEN {$rate} IS NULL THEN {$amount} ELSE ROUND({$amount} * {$rate}, 2) END)";
    }

    /**
     * Položky převáděné v měně: `base`/`vat`/`unit_price` v měně dokladu, koruny zdroje
     * zůstávají v `home_base`/`home_vat`.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function foreignItems(array $items): array
    {
        foreach ($items as $i => $item) {
            $base = round((float) $item['foreign_base'], 2);
            $qty = (float) ($item['quantity'] ?? 1.0);
            $items[$i]['home_base'] = $item['base'];
            $items[$i]['home_vat'] = $item['vat'];
            $items[$i]['base'] = $base;
            $items[$i]['vat'] = round((float) $item['foreign_vat'], 2);
            $items[$i]['unit_price'] = $qty != 0.0 ? round($base / $qty, 6) : $base;
        }
        return $items;
    }

    /**
     * Součty dokladu v měně z položek {@see foreignItems()}; zaokrouhlení dokladu je 0
     * (pojistka ověřila, že celkem v Kč vychází bez něj).
     *
     * @param array<int,array<string,mixed>> $items
     * @return array{base:float,vat:float,total:float}
     */
    public static function totals(array $items): array
    {
        $base = round(array_sum(array_map(static fn (array $i): float => (float) $i['base'], $items)), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => (float) $i['vat'], $items)), 2);
        return ['base' => $base, 'vat' => $vat, 'total' => round($base + $vat, 2)];
    }

    /**
     * Měna dokladu, kterou firma v číselníku nemá: převod ji založí jako výchozí měnu
     * daného kódu (bez bankovního účtu), stejně jako ji založí uživatel v nastavení.
     * Jinak by se doklad převzal v Kč a ztratila by se měna, kurz i částky v měně.
     */
    private function createCurrency(int $supplierId, string $code): int
    {
        [$symbol, $nameCs, $nameEn] = self::NAMES[$code] ?? [$code, $code, $code];
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                       VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)')
            ->execute([$supplierId, $code, $code . ' — výchozí', $symbol, $nameCs, $nameEn]);
        return (int) $pdo->lastInsertId();
    }

    /** id měny firmy podle kódu; `null` = firma ji v číselníku nemá. */
    public function currencyId(int $supplierId, string $code): ?int
    {
        $pdo = $this->db->pdo();
        $stmt = $this->stmts[spl_object_id($pdo) . '|currency'] ??= $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function convertsTo(float $foreign, float $rate, float $home): bool
    {
        $cents = (int) round($home * 100);
        return (int) round(CzkRecap::multiplyHalfUp($foreign, $rate) * 100) === $cents
            && (int) round(round($foreign * $rate, 2) * 100) === $cents;
    }

    private static function amount(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, ',', ' ');
        return $decimals > 2 ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
