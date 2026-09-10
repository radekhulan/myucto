<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\AttachmentCheck;

/**
 * Porovná zaúčtovaný doklad s uloženým AI vytěžením jeho přílohy.
 *
 * Čistá funkce bez DB. Porovnává čtyři údaje: částku, DUZP, IČO protistrany a VS.
 * Jediný rozdíl s dopadem na daň je DUZP v jiném kalendářním měsíci u dokladu,
 * který vstupuje do DPH — ten posouvá doklad do jiného zdaňovacího období
 * i do jiného kontrolního hlášení (u právnické osoby je měsíční vždy, i při
 * čtvrtletním období DPH). Proto měsíc, ne čtvrtletí. Ostatní rozdíly jsou
 * informativní: částku, IČO ani VS vytěžení nedokazuje, jen na ně upozorní.
 *
 * Co se NEhlásí, protože by šlo o falešný poplach:
 *  - pole, které vytěžení nemá (null) — model ho nepřečetl, nic se tím nedokazuje,
 *  - zálohový doklad proti skenu konečného dokladu (a naopak) — jiný článek řetězu,
 *    a u zálohy nikdy DUZP, protože zálohová faktura není daňový doklad,
 *  - zaokrouhlení: pole `rounding` dokladu a zaokrouhlení na celé koruny u CZK,
 *  - znaménko dobropisu,
 *  - příloha v jiné měně než doklad — částky nejsou srovnatelné,
 *  - nulové částky („K úhradě 0" po záloze) — nesmí zamaskovat rozdílné celky.
 */
final class AttachmentComparator
{
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public const STATUS_MATCH = 'match';
    public const STATUS_MISMATCH = 'mismatch';
    /** Nebylo co porovnat (všechna pole vytěžení chybí nebo se neporovnávají). */
    public const STATUS_SKIPPED = 'skipped';

    public const FIELD_TAX_DATE_PERIOD = 'tax_date_period';
    public const FIELD_TAX_DATE = 'tax_date';
    public const FIELD_AMOUNT = 'amount';
    public const FIELD_COUNTERPARTY_ICO = 'counterparty_ico';
    public const FIELD_VARIABLE_SYMBOL = 'variable_symbol';

    public const DIRECTION_RECEIVED = 'received';
    public const DIRECTION_ISSUED = 'issued';

    private const EXACT_TOLERANCE = 0.01;
    /** Zaokrouhlení na celé koruny: rozdíl pod 1 Kč, když je jedna strana celé číslo. */
    private const CROWN_ROUNDING = 0.995;

    /**
     * @param array{direction:string, kind:string, total:?float, rounding?:float, amount_to_pay?:?float,
     *              advance_paid?:?float, currency:string, tax_date:?string, counterparty_ico:?string,
     *              own_ico?:?string, vs?:?string, vat_relevant:bool} $doc
     * @param array<string,mixed> $ext normalizované vytěžení (sloupce `document_extractions`)
     * @return array{status:string, severity:?string, findings:list<array<string,mixed>>, fingerprint:string}
     */
    public static function compare(array $doc, array $ext): array
    {
        $findings = [];
        $compared = 0;

        $docKind = (string) ($doc['kind'] ?? 'invoice');
        $extKind = isset($ext['document_kind']) && is_string($ext['document_kind']) ? $ext['document_kind'] : null;
        $chainMismatch = $extKind !== null && (($docKind === 'advance') !== ($extKind === 'advance'));

        // ── DUZP ──
        $docTax = self::date($doc['tax_date'] ?? null);
        $extTax = self::date($ext['tax_date'] ?? null);
        if ($docKind !== 'advance' && !$chainMismatch && $docTax !== null && $extTax !== null) {
            $compared++;
            if ($docTax !== $extTax) {
                $otherMonth = substr($docTax, 0, 7) !== substr($extTax, 0, 7);
                $findings[] = [
                    'field' => $otherMonth ? self::FIELD_TAX_DATE_PERIOD : self::FIELD_TAX_DATE,
                    'severity' => $otherMonth && !empty($doc['vat_relevant']) ? self::SEVERITY_WARNING : self::SEVERITY_INFO,
                    'doc' => $docTax,
                    'attachment' => $extTax,
                ];
            }
        }

        // ── Částka ──
        $docCurrency = strtoupper((string) ($doc['currency'] ?? 'CZK')) ?: 'CZK';
        $extCurrency = isset($ext['currency']) && is_string($ext['currency']) && $ext['currency'] !== '' ? strtoupper($ext['currency']) : null;
        $docAmounts = self::docAmounts($doc);
        $extAmounts = self::amounts([$ext['total_with_vat'] ?? null, $ext['amount_due'] ?? null]);
        if (!$chainMismatch && ($extCurrency === null || $extCurrency === $docCurrency) && $docAmounts !== [] && $extAmounts !== []) {
            $compared++;
            $best = null;
            foreach ($docAmounts as $d) {
                foreach ($extAmounts as $e) {
                    $diff = round($e - $d, 2);
                    if ($best === null || abs($diff) < abs($best)) {
                        $best = $diff;
                    }
                }
            }
            $crownRounded = $docCurrency === 'CZK' && abs((float) $best) < self::CROWN_ROUNDING
                && (self::anyWhole($docAmounts) || self::anyWhole($extAmounts));
            if (abs((float) $best) > self::EXACT_TOLERANCE && !$crownRounded) {
                $findings[] = [
                    'field' => self::FIELD_AMOUNT,
                    'severity' => self::SEVERITY_INFO,
                    'doc' => $docAmounts[0],
                    'attachment' => $extAmounts[0],
                    'diff' => round($extAmounts[0] - $docAmounts[0], 2),
                    'currency' => $docCurrency,
                ];
            }
        }

        // ── IČO protistrany ──
        $docIco = self::digits($doc['counterparty_ico'] ?? null);
        $extIco = self::counterpartyIco($doc, $ext);
        if ($docIco !== null && $extIco !== null) {
            $compared++;
            if ($docIco !== $extIco) {
                $findings[] = [
                    'field' => self::FIELD_COUNTERPARTY_ICO,
                    'severity' => self::SEVERITY_INFO,
                    'doc' => (string) $doc['counterparty_ico'],
                    'attachment' => $extIco,
                ];
            }
        }

        // ── VS ──
        $docVs = self::digits($doc['vs'] ?? null);
        $extVs = self::digits($ext['variable_symbol'] ?? null);
        if ($docVs !== null && $extVs !== null) {
            $compared++;
            if ($docVs !== $extVs) {
                $findings[] = [
                    'field' => self::FIELD_VARIABLE_SYMBOL,
                    'severity' => self::SEVERITY_INFO,
                    'doc' => (string) $doc['vs'],
                    'attachment' => (string) $ext['variable_symbol'],
                ];
            }
        }

        usort($findings, static fn (array $a, array $b): int =>
            ($a['severity'] === self::SEVERITY_WARNING ? 0 : 1) <=> ($b['severity'] === self::SEVERITY_WARNING ? 0 : 1));

        $severity = null;
        foreach ($findings as $f) {
            if ($f['severity'] === self::SEVERITY_WARNING) {
                $severity = self::SEVERITY_WARNING;
                break;
            }
            $severity = self::SEVERITY_INFO;
        }

        return [
            'status' => $findings !== [] ? self::STATUS_MISMATCH : ($compared > 0 ? self::STATUS_MATCH : self::STATUS_SKIPPED),
            'severity' => $severity,
            'findings' => $findings,
            'fingerprint' => self::fingerprint($doc, $ext),
        ];
    }

    /**
     * Otisk všech hodnot, ze kterých porovnání vychází. Potvrzení „v pořádku" platí
     * jen pro tenhle otisk — změna dokladu i nové vytěžení dají jiný.
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $ext
     */
    public static function fingerprint(array $doc, array $ext): string
    {
        $pick = static function (array $src, array $keys): array {
            $out = [];
            foreach ($keys as $k) {
                $v = $src[$k] ?? null;
                $out[$k] = is_float($v) || is_int($v) ? round((float) $v, 2) : (is_bool($v) ? $v : ($v === null ? null : (string) $v));
            }
            return $out;
        };
        return hash('sha256', (string) json_encode([
            $pick($doc, ['direction', 'kind', 'total', 'rounding', 'amount_to_pay', 'advance_paid', 'currency', 'tax_date', 'counterparty_ico', 'own_ico', 'vs', 'vat_relevant']),
            $pick($ext, ['company_role', 'document_kind', 'vendor_ico', 'buyer_ico', 'variable_symbol', 'tax_date', 'total_with_vat', 'amount_due', 'currency']),
        ]));
    }

    /**
     * Částky dokladu, kterým příloha může odpovídat: celkem, celkem se zaokrouhlením,
     * k úhradě a celkem po odečtení zálohy. Příloha konečné faktury po záloze uvádí
     * „K úhradě" jen zbytek, a právě ten sedí na `amount_to_pay`.
     *
     * @param array<string,mixed> $doc
     * @return list<float>
     */
    private static function docAmounts(array $doc): array
    {
        $total = isset($doc['total']) && is_numeric($doc['total']) ? (float) $doc['total'] : null;
        $rounding = isset($doc['rounding']) && is_numeric($doc['rounding']) ? (float) $doc['rounding'] : 0.0;
        $advance = isset($doc['advance_paid']) && is_numeric($doc['advance_paid']) ? (float) $doc['advance_paid'] : 0.0;
        return self::amounts([
            $total,
            $total !== null && $rounding !== 0.0 ? $total + $rounding : null,
            $doc['amount_to_pay'] ?? null,
            $total !== null && $advance > 0.0 ? abs($total) - abs($advance) : null,
        ]);
    }

    /**
     * Absolutní nenulové částky (znaménko dobropisu nehraje roli, nula nic nedokazuje).
     *
     * @param list<mixed> $values
     * @return list<float>
     */
    private static function amounts(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_numeric($v)) {
                continue;
            }
            $a = round(abs((float) $v), 2);
            if ($a > 0.0 && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** @param list<float> $amounts */
    private static function anyWhole(array $amounts): bool
    {
        foreach ($amounts as $a) {
            if (abs($a - round($a)) < 0.005) {
                return true;
            }
        }
        return false;
    }

    /**
     * IČO protistrany ze vytěžení podle směru dokladu. U přijatého dokladu je
     * protistrana dodavatel, u vydaného odběratel. Když model firmu posadil na
     * opačnou stranu, než odpovídá směru dokladu, IČO se nehodnotí — nevíme, které
     * pole je které. Když je na straně protistrany vlastní IČO firmy (strany
     * prohozené bez uvedení role), vezme se druhá strana.
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $ext
     */
    private static function counterpartyIco(array $doc, array $ext): ?string
    {
        $received = ($doc['direction'] ?? self::DIRECTION_RECEIVED) !== self::DIRECTION_ISSUED;
        $role = $ext['company_role'] ?? null;
        if (($received && $role === 'vendor') || (!$received && $role === 'buyer')) {
            return null;
        }
        $own = self::digits($doc['own_ico'] ?? null);
        $counter = self::digits($received ? ($ext['vendor_ico'] ?? null) : ($ext['buyer_ico'] ?? null));
        if ($counter !== null && $own !== null && $counter === $own) {
            $counter = self::digits($received ? ($ext['buyer_ico'] ?? null) : ($ext['vendor_ico'] ?? null));
            return $counter === $own ? null : $counter;
        }
        return $counter;
    }

    /** Jen číslice bez úvodních nul; „CZ 012 34 567" i „1234567" dají totéž. */
    private static function digits(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $d = ltrim((string) preg_replace('/\D/', '', (string) $v), '0');
        return $d === '' ? null : $d;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}/', $v) !== 1) {
            return null;
        }
        return substr($v, 0, 10);
    }
}
