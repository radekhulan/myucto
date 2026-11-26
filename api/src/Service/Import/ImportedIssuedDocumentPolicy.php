<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Invoice\InvoiceRounding;
use PDO;

/**
 * Pravidla pro VYDANÝ doklad převzatý z cizího souboru: obecný import ISDOC/Pohoda
 * ({@see InvoiceImportService}), AI import vydaných faktur ({@see AiIssuedInvoiceExtractor})
 * a import dokladů Shoptetu, který jede přes obecný import. Pravidlo žije tady a všechny
 * tři cesty ho volají, aby se nerozešlo mezi kanálem a importem, který ho obaluje.
 *
 * ── Odpočet záloh a nesedící součet → koncept ───────────────────────────────────────
 * Import ukládá řádky dokladu a daň počítá z nich (výkazy sumují řádky). Konečná faktura,
 * která ODEČÍTÁ ZÁLOHU, ji ale v ISDOC nese mimo řádky: `PaidDepositsAmount` sníží jen
 * částku k úhradě a zdaněná záloha (`TaxedDeposits`, `AlreadyClaimed*`) sníží daň, kterou
 * už přiznal daňový doklad k přijaté platbě. Z řádků by vznikla plná tržba i plná daň
 * a spolu s importovaným DDPP by se táž úplata zdanila podruhé. Převod odpočtu na záporné
 * řádky (jak to dělá {@see \MyInvoice\Service\Invoice\FinalFromProformaCreator}) si bez
 * reálného souboru nevymýšlíme, proto takový doklad zůstane KONCEPTEM: do DPH ani do
 * pohledávek nejde a uživatel ho naváže na daňový doklad k záloze ručně.
 *
 * Totéž platí pro doklad, jehož součet řádků nesedí na celkovou částku dokladu nebo na
 * částku k úhradě o víc než {@see self::TOTAL_TOLERANCE}: rozdíl je buď nečitelný odpočet,
 * nebo vadný soubor, a v obou případech by vystavený doklad nesl jinou tržbu, než jakou
 * zákazník zaplatil. Tolerance je v měně dokladu. Zaokrouhlení dokladu
 * (`PayableRoundingAmount`) import převezme do `invoices.rounding`
 * ({@see self::applyPayableRounding()}), částka k úhradě se proto porovnává se součtem
 * po zaokrouhlení. Nulová částka k úhradě bez odpočtu zálohy znamená „neuvedeno".
 *
 * Pohoda XML (i export z Fakturoidu) nese odpočet zálohy jako `<inv:invoiceAdvancePaymentItem>`
 * mimo položky dokladu (klíč `advance_deduction`). Odpočet NEZDANĚNÉ zálohy (proformy, DPH 0)
 * tržbu ani daň nemění, jen snižuje částku k úhradě: doklad se uloží s `advance_paid_amount`
 * ({@see self::settleAdvanceDeduction()}). Odpočet ZDANĚNÉ zálohy snižuje i daň, kterou už
 * přiznal daňový doklad k platbě — ten doklad zůstane konceptem jako u ISDOC výše.
 *
 * ── Daňový doklad k přijaté platbě je zaplacený ─────────────────────────────────────
 * DDPP (§ 28 odst. 2 ZDPH) dokumentuje úplatu, která už přišla. Aplikace ho proto zakládá
 * s `advance_paid_amount` = brutto platby, takže `amount_to_pay` (generovaný sloupec) je 0
 * ({@see \MyInvoice\Service\Invoice\PaymentTaxDocumentCreator}). Převzatý doklad dostane
 * totéž, jinak by visel v pohledávkách a po splatnosti v plné výši.
 */
final class ImportedIssuedDocumentPolicy
{
    /** Povolený rozdíl součtu řádků proti dokladu v měně dokladu (haléřové zaokrouhlení). */
    public const TOTAL_TOLERANCE = 1.00;

    private const CENT = 0.005;

    /**
     * Posouzení převzatého dokladu proti údajům ze souboru.
     *
     * `review` neprázdné = doklad musí zůstat konceptem (texty patří do varování dávky).
     * `notes` = drobnosti, které doklad nezastaví (zaokrouhlení).
     *
     * @param array<string,mixed> $inv        rozparsovaný doklad (výstup IsdocParser / PohodaXmlParser)
     * @param float               $linesTotal součet řádků s DPH po přepočtu dokladu
     * @return array{review:list<string>, notes:list<string>}
     */
    public static function assess(array $inv, float $linesTotal): array
    {
        $advance = self::advanceDeduction($inv);
        if ($advance !== null && $advance['vat'] > self::CENT) {
            return ['review' => [sprintf(
                'Doklad odečítá zdaněnou zálohu %s (z toho DPH %s). Odpočet snižuje i daň, kterou už '
                    . 'přiznal daňový doklad k záloze, takže by se úplata zdanila podruhé. Doklad jsme '
                    . 'uložili jako koncept (nejde do DPH ani do pohledávek): zkontrolujte ho a navažte '
                    . 'daňový doklad k záloze ručně.',
                self::money($advance['gross']),
                self::money($advance['vat']),
            )], 'notes' => []];
        }

        $monetary = $inv['monetary'] ?? null;
        if (!is_array($monetary)) {
            return ['review' => [], 'notes' => []];
        }
        $type = (string) ($inv['invoice_type'] ?? 'invoice');
        $review = [];
        $notes = [];

        $paidDeposits = self::amount($monetary['paid_deposits'] ?? null);
        $alreadyClaimed = self::amount($monetary['already_claimed'] ?? null);
        $taxedDeposits = is_array($inv['taxed_deposits'] ?? null) ? $inv['taxed_deposits'] : [];
        if (($paidDeposits !== null && abs($paidDeposits) > self::CENT)
            || ($alreadyClaimed !== null && abs($alreadyClaimed) > self::CENT)
            || $taxedDeposits !== []) {
            $refs = array_values(array_filter(array_map(
                static fn (mixed $d): string => is_array($d) ? trim((string) ($d['varsymbol'] ?? $d['id'] ?? '')) : '',
                $taxedDeposits,
            )));
            $review[] = sprintf(
                'Doklad odečítá zálohy%s%s. Odpočet v souboru není mezi řádky, takže by se tržba '
                    . 'i DPH zaevidovaly v plné výši a úplata zdaněná daňovým dokladem k záloze '
                    . 'podruhé. Doklad jsme uložili jako koncept (nejde do DPH ani do pohledávek): '
                    . 'zkontrolujte ho a navažte daňový doklad k záloze ručně.',
                $paidDeposits !== null && abs($paidDeposits) > self::CENT ? ' ' . self::money($paidDeposits) : '',
                $refs !== [] ? ' (' . implode(', ', $refs) . ')' : '',
            );
        }

        $total = self::amount($monetary['total'] ?? null);
        if ($total !== null) {
            $diff = abs(round(abs($linesTotal) - abs($total), 2));
            if ($diff > self::TOTAL_TOLERANCE) {
                $review[] = sprintf(
                    'Součet řádků dokladu %s se liší od celkové částky dokladu %s o %s. Doklad jsme '
                        . 'uložili jako koncept: zkontrolujte odpočet zálohy nebo položky a teprve pak ho vystavte.',
                    self::money(abs($linesTotal)),
                    self::money(abs($total)),
                    self::money($diff),
                );
            }
        }

        // Částka k úhradě u DDPP je z podstaty 0 (úplata už přišla), s řádky se tedy
        // neporovnává. Rozdíl proti celkové částce dokladu hlídá kontrola výše.
        // Porovnává se se součtem PO zaokrouhlení, které import převezme ({@see self::payableRounding()}).
        $payable = self::payable($inv);
        if ($type !== 'tax_document' && $payable !== null) {
            $diff = abs(round(abs($linesTotal + self::payableRounding($inv, $linesTotal)) - abs($payable), 2));
            if ($diff > self::TOTAL_TOLERANCE) {
                $review[] = sprintf(
                    'Součet řádků dokladu %s se liší od částky k úhradě %s o %s. Doklad jsme uložili '
                        . 'jako koncept: zkontrolujte odpočet zálohy nebo zaokrouhlení a navažte daňový '
                        . 'doklad k záloze ručně.',
                    self::money(abs($linesTotal)),
                    self::money(abs($payable)),
                    self::money($diff),
                );
            } elseif ($diff > self::CENT) {
                $notes[] = sprintf('Součet řádků se od částky k úhradě liší o %s (zaokrouhlení dokladu).', self::money($diff));
            }
        }

        $rounding = self::payableRounding($inv, $linesTotal);
        if ($rounding !== 0.0) {
            $notes[] = sprintf(
                'Převzali jsme zaokrouhlení dokladu %s, k úhradě je %s.',
                self::money($rounding),
                self::money(abs($linesTotal + $rounding)),
            );
        }

        return ['review' => $review, 'notes' => $notes];
    }

    /**
     * Zaokrouhlení částky k úhradě, které převzatý doklad nese, ve znaménku NAŠEHO součtu
     * řádků (dobropis má v MyÚčtu záporné řádky). Nula = doklad zaokrouhlení nemá nebo
     * ho převzít nejde.
     *
     * Zdroj je `PayableRoundingAmount`; chybí-li (nebo je nulové), dopočítá se z rozdílu
     * uvedené částky k úhradě proti součtu řádků. Převezme se jen rozdíl pod jednu jednotku
     * měny: větší rozdíl není zaokrouhlení a hlídá ho {@see self::assess()}. Dopočet se
     * nedělá u dokladu s odpočtem záloh (rozdíl je odpočet, ne zaokrouhlení) a jen u faktury
     * a dobropisu, tedy u týchž druhů, které zaokrouhluje {@see InvoiceRounding}.
     *
     * Zaokrouhlení mění jen částku k úhradě, do základu ani DPH nevstupuje: výkazy sumují
     * řádky dokladu.
     *
     * @param array<string,mixed> $inv
     */
    public static function payableRounding(array $inv, float $linesTotal): float
    {
        $monetary = $inv['monetary'] ?? null;
        $type = (string) ($inv['invoice_type'] ?? 'invoice');
        if (!is_array($monetary) || !in_array($type, ['invoice', 'credit_note'], true) || abs($linesTotal) <= self::CENT) {
            return 0.0;
        }
        $ourSign = $linesTotal < 0 ? -1.0 : 1.0;

        $explicit = self::amount($monetary['rounding'] ?? null);
        if ($explicit !== null && abs($explicit) >= self::CENT) {
            // Soubor může vést dobropis kladně (Shoptet) i záporně (náš export ISDOC).
            // Zaokrouhlení se proto vztáhne ke znaménku částek SOUBORU a přenese na naše.
            $rounding = round($explicit * self::fileSign($inv) * $ourSign, 2);
        } else {
            $payable = self::payable($inv);
            if ($payable === null || self::hasDeposits($inv)) {
                return 0.0;
            }
            $rounding = round((abs($payable) - abs($linesTotal)) * $ourSign, 2);
        }

        return abs($rounding) >= self::CENT && abs($rounding) < 1.0 ? $rounding : 0.0;
    }

    /**
     * Převezme zaokrouhlení dokladu ({@see self::payableRounding()}) do `invoices.rounding`
     * a `total_with_vat` (stejná skladba jako u {@see \MyInvoice\Service\Invoice\InvoiceCalculator::recompute()}),
     * `amount_to_pay` (generovaný sloupec) pak sedí na částku k úhradě ze souboru. Volá se
     * po přepočtu dokladu s ČISTÝM součtem řádků.
     *
     * Když stejné zaokrouhlení dává režim „na celé koruny", dostane doklad i ten režim:
     * pozdější přepočet (úprava dokladu) by jinak ruční zaokrouhlení vynuloval.
     *
     * @param array<string,mixed> $inv
     */
    public static function applyPayableRounding(PDO $pdo, int $invoiceId, array $inv, float $linesTotal): float
    {
        $rounding = self::payableRounding($inv, $linesTotal);
        if ($rounding === 0.0) {
            return 0.0;
        }
        $stmt = $pdo->prepare(
            'SELECT i.rounding, i.advance_paid_amount, i.payment_method, i.invoice_type, c.code AS currency
               FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Zaokrouhlení, které už dal režim dokladu, se nepřepisuje.
        if (!is_array($row) || abs((float) $row['rounding']) >= self::CENT) {
            return 0.0;
        }
        $wholeCzk = InvoiceRounding::adjustment(
            $linesTotal - (float) $row['advance_paid_amount'],
            'whole_czk',
            (string) $row['currency'],
            (string) $row['payment_method'],
            (string) $row['invoice_type'],
        );
        $pdo->prepare(
            'UPDATE invoices SET rounding = ?, total_with_vat = ?' . (abs($wholeCzk - $rounding) < self::CENT ? ", rounding_mode = 'whole_czk'" : '')
                . ' WHERE id = ?'
        )->execute([$rounding, round($linesTotal + $rounding, 2), $invoiceId]);

        return $rounding;
    }

    /** Druh vydaného dokladu, který z podstaty dokumentuje už přijatou úplatu. */
    public static function isPaidByNature(string $invoiceType): bool
    {
        return $invoiceType === 'tax_document';
    }

    /**
     * Převzatý daňový doklad k přijaté platbě: přijatá úplata kryje doklad celý, takže
     * `amount_to_pay` = 0 stejně jako u dokladu, který aplikace založí k platbě zálohy.
     * Volá se až po přepočtu dokladu (součet musí být hotový).
     */
    public static function settleTaxDocument(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare(
            "UPDATE invoices SET advance_paid_amount = total_with_vat
              WHERE id = ? AND invoice_type = 'tax_document'"
        )->execute([$invoiceId]);
    }

    /**
     * Odpočet nezdaněné zálohy (proformy) ze souboru: sníží částku k úhradě. Vrací zbývající
     * částku k úhradě po odpočtu, nebo null, když doklad odpočet nemá. Volá se po přepočtu
     * dokladu a jen pro doklad, který {@see self::assess()} nepustil do konceptu.
     *
     * @param array<string,mixed> $inv
     */
    public static function settleAdvanceDeduction(PDO $pdo, int $invoiceId, array $inv): ?float
    {
        $advance = self::advanceDeduction($inv);
        if ($advance === null || $advance['vat'] > self::CENT || (string) ($inv['invoice_type'] ?? 'invoice') !== 'invoice') {
            return null;
        }
        $pdo->prepare(
            "UPDATE invoices SET advance_paid_amount = LEAST(total_with_vat, ?)
              WHERE id = ? AND invoice_type = 'invoice'"
        )->execute([$advance['gross'], $invoiceId]);
        $left = $pdo->prepare('SELECT amount_to_pay FROM invoices WHERE id = ?');
        $left->execute([$invoiceId]);

        return (float) $left->fetchColumn();
    }

    /**
     * @param array<string,mixed> $inv
     * @return array{gross:float, vat:float}|null
     */
    private static function advanceDeduction(array $inv): ?array
    {
        $advance = $inv['advance_deduction'] ?? null;
        if (!is_array($advance) || (self::amount($advance['gross'] ?? null) ?? 0.0) <= self::CENT) {
            return null;
        }
        return ['gross' => (float) $advance['gross'], 'vat' => (float) (self::amount($advance['vat'] ?? null) ?? 0.0)];
    }

    /**
     * Částka k úhradě ze souboru, nebo null, když ji doklad neuvádí. Nulová částka bez
     * odpočtu zálohy znamená totéž: exportér (např. Fakturovač) pole vyplní nulou, i když
     * doklad k úhradě je. Záloha nulu vysvětluje, proto se s ní nula bere doslova.
     *
     * @param array<string,mixed> $inv
     */
    private static function payable(array $inv): ?float
    {
        $payable = self::amount($inv['monetary']['payable'] ?? null);
        if ($payable !== null && abs($payable) <= self::CENT && !self::hasDeposits($inv)) {
            return null;
        }

        return $payable;
    }

    /** @param array<string,mixed> $inv */
    private static function hasDeposits(array $inv): bool
    {
        $monetary = is_array($inv['monetary'] ?? null) ? $inv['monetary'] : [];
        foreach (['paid_deposits', 'already_claimed'] as $key) {
            $value = self::amount($monetary[$key] ?? null);
            if ($value !== null && abs($value) > self::CENT) {
                return true;
            }
        }

        return (is_array($inv['taxed_deposits'] ?? null) && $inv['taxed_deposits'] !== [])
            || self::advanceDeduction($inv) !== null;
    }

    /**
     * Znaménko částek dokladu tak, jak je vede SOUBOR: podle celkové částky, částky
     * k úhradě, nebo nakonec součtu řádků ze souboru.
     *
     * @param array<string,mixed> $inv
     */
    private static function fileSign(array $inv): float
    {
        $monetary = is_array($inv['monetary'] ?? null) ? $inv['monetary'] : [];
        foreach ([self::amount($monetary['total'] ?? null), self::payable($inv)] as $value) {
            if ($value !== null && abs($value) > self::CENT) {
                return $value < 0 ? -1.0 : 1.0;
            }
        }
        $sum = 0.0;
        foreach ((array) ($inv['items'] ?? []) as $item) {
            if (is_array($item)) {
                $sum += (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price_without_vat'] ?? 0);
            }
        }

        return $sum < 0 ? -1.0 : 1.0;
    }

    private static function amount(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
