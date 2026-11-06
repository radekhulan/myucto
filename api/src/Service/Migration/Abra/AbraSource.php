<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;

/** Normalizace hodnot z JSON odpovědí ABRA Flexi. */
final class AbraSource
{
    /** Evidence dokladů: faktury, prodejky a ostatní pohledávky a závazky. */
    public const DOCUMENT_EVIDENCES = ['faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka'];

    /**
     * Úvěrový účet: ABRA ho vede jako bankovní účet, ale jeho „zůstatek" je nesplacená
     * jistina (231, 232, 461). Peníze firmy to nejsou, proto se nepřevádí do banky.
     */
    public static function isLoanAccount(mixed $account): bool
    {
        return in_array(substr(self::reference($account), 0, 3), ['231', '232', '461'], true);
    }

    /**
     * Druh v mapě převodu pro ostatní pohledávku či závazek. Liší se od druhu evidence,
     * protože {@see \MyInvoice\Service\Accounting\TakenOverRecord} pozná převzatou fakturu
     * jen podle druhu (`zavazek` = přijatá faktura) a id ostatní položky by si s ní spletl.
     */
    public static function otherItemMapKind(string $evidence): string
    {
        return 'ostatni-' . $evidence;
    }

    public static function evidenceOfMapKind(string $kind): string
    {
        return str_starts_with($kind, 'ostatni-') ? substr($kind, 8) : $kind;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(array $snapshot, string $evidence, string ...$aliases): array
    {
        foreach ([$evidence, ...$aliases] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                continue;
            }
            $value = $snapshot[$key];
            if (is_array($value) && isset($value['winstrom']) && is_array($value['winstrom'])) {
                $value = $value['winstrom'][$key] ?? $value['winstrom'][$evidence] ?? [];
            }
            if (!is_array($value)) {
                return [];
            }
            if (!array_is_list($value)) {
                $value = isset($value[$key]) && is_array($value[$key]) ? $value[$key] : [$value];
            }
            return array_values(array_filter($value, 'is_array'));
        }
        return [];
    }

    public static function hasEvidence(array $snapshot, string $evidence, string ...$aliases): bool
    {
        foreach ([$evidence, ...$aliases] as $key) {
            if (array_key_exists($key, $snapshot)) {
                return true;
            }
        }
        return false;
    }

    public static function sourceKey(array $row, string $fallback = ''): string
    {
        foreach (['id', 'idUcetniDenik', 'idDokl', 'kod', 'cisloDokl', 'cisDokl'] as $field) {
            $value = self::reference($row[$field] ?? null);
            if ($value !== '') {
                return $value;
            }
        }
        if ($fallback !== '') {
            return $fallback;
        }
        return '';
    }

    /**
     * Zdrojová firma ABRA a cílová firma jsou tatáž účetní jednotka. Chybějící IČO
     * na kterékoli straně shodu neprokazuje.
     */
    public static function sameIco(mixed $source, mixed $target): bool
    {
        $source = self::ico($source);
        $target = self::ico($target);
        return $source !== '' && $target !== '' && hash_equals($target, $source);
    }

    public static function ico(mixed $value): string
    {
        return ltrim(preg_replace('/\D+/', '', (string) $value) ?: '', '0');
    }

    /** `code:EUR`, `ucetni-osnova/311100` a relation objekty převede na vlastní hodnotu. */
    public static function reference(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['id', 'kod', 'code', 'value', 'evidencePath', '@ref'] as $key) {
                if (array_key_exists($key, $value)) {
                    $resolved = self::reference($value[$key]);
                    if ($resolved !== '') {
                        return $resolved;
                    }
                }
            }
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (str_contains($text, '/')) {
            $text = (string) preg_replace('~^.*/~', '', $text);
        }
        if (str_contains($text, ':')) {
            [, $suffix] = explode(':', $text, 2);
            if ($suffix !== '') {
                $text = $suffix;
            }
        }
        return trim($text);
    }

    /** @return array{evidence:string,key:string}|null */
    public static function relation(mixed $value): ?array
    {
        $path = '';
        if (is_array($value)) {
            $path = trim((string) ($value['evidencePath'] ?? $value['@ref'] ?? ''));
        } else {
            $path = is_scalar($value) ? trim((string) $value) : '';
        }
        if (str_ends_with($path, '.json')) {
            $path = substr($path, 0, -5);
        }
        $id = self::reference(is_array($value) ? ($value['id'] ?? $path) : $path);
        if ($id === '') {
            return null;
        }
        $evidence = '';
        if ($path !== '' && preg_match('~(?:^|/)([a-z][a-z0-9-]+)/[^/]+$~i', $path, $m) === 1) {
            $evidence = mb_strtolower($m[1]);
        }
        return ['evidence' => $evidence, 'key' => $id];
    }

    public static function date(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
        return $date !== false && $date->format('Y-m-d') === $m[1] ? $m[1] : null;
    }

    public static function periodYear(array $row): ?int
    {
        $code = self::reference($row['kod'] ?? null);
        if (preg_match('/^((?:19|20|21|22)\d{2})(?:\D|$)/', $code, $match) === 1) {
            return (int) $match[1];
        }
        $start = self::date($row['platiOdData'] ?? $row['datOd'] ?? null);
        return $start !== null ? (int) substr($start, 0, 4) : null;
    }

    public static function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && abs($number) <= 1.0e14 ? $number : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value === 1.0 ? true : ((float) $value === 0.0 ? false : null);
        }
        $text = mb_strtolower(trim((string) $value));
        return match ($text) {
            'true', 'yes', 'ano', '1' => true,
            'false', 'no', 'ne', '0' => false,
            default => null,
        };
    }

    public static function currency(mixed $value): string
    {
        $currency = mb_strtoupper(self::reference($value));
        if ($currency === '' || in_array($currency, ['KČ', 'CZK'], true)) {
            return 'CZK';
        }
        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : '';
    }

    public static function account(mixed $value): string
    {
        $code = preg_replace('/\s+/', '', self::reference($value));
        if (!is_string($code) || preg_match('/^[0-9A-Za-z._-]{1,10}$/D', $code) !== 1) return '';
        return preg_match('/^221([0-9]{1,6})$/D', $code, $parts) === 1
            ? BankAnalyticAssigner::codeFor($parts[1]) : $code;
    }

    public static function hash(array $row): string
    {
        return hash('sha256', json_encode(self::canonical($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function documentHash(array $row): string
    {
        $row = self::compactDocument($row);
        foreach (['stavUhrK', 'datUhr', 'zbyvaUhradit', 'zbyvaUhraditMen', 'vazby', 'vazebni-doklady'] as $field) {
            unset($row[$field], $row[$field . '@showAs']);
        }
        return self::hash($row);
    }

    public static function movementHash(array $row): string
    {
        $row = self::compactDocument($row);
        unset($row['vazby'], $row['vazebni-doklady']);
        if (self::bool($row['storno'] ?? null) !== true) unset($row['storno']);
        return self::hash($row);
    }

    public static function compactDocument(array $row): array
    {
        if (!is_array($row['polozkyDokladu'] ?? null) || !array_is_list($row['polozkyDokladu'])) return $row;
        $fields = array_fill_keys(['id', 'kod', 'idDokl', 'cisloDokl', 'cisDokl', 'nazev', 'popis', 'typPolozkyK',
            'mnozMj', 'mj', 'szbDph', 'cenaMj', 'cenaMjMen', 'sumZkl', 'sumZklMen', 'sumZklCelkem', 'sumZklCelkemMen',
            'sumDph', 'sumDphMen', 'sumDphCelkem', 'sumDphCelkemMen', 'sumCelkem', 'sumCelkemMen', 'typCenyDphK',
            'typSzbDphK', 'clenDph', 'clenDph@showAs', 'clenKontrolniHlas', 'clenKontrolniHlas@showAs',
            'mdUcet', 'dalUcet', 'stredisko', 'cinnost', 'zakazka', 'cenik', 'lastUpdate'], true);
        foreach ($row['polozkyDokladu'] as &$item) if (is_array($item)) $item = array_intersect_key($item, $fields);
        unset($item);
        return $row;
    }

    public static function compactPaymentDocument(array $row): array
    {
        $taxedCash = abs(self::number($row['sumDphCelkem'] ?? null) ?? 0.0) > 0.005;
        foreach (is_array($row['polozkyDokladu'] ?? null) ? $row['polozkyDokladu'] : [] as $item) {
            if (is_array($item) && abs(self::number($item['sumDph'] ?? $item['sumDphCelkem'] ?? null) ?? 0.0) > 0.005) {
                $taxedCash = true;
                break;
            }
        }
        $fields = array_fill_keys([
            'id', 'kod', 'idDokl', 'idDokl@evidencePath', 'cisloDokl', 'cisDokl', 'lastUpdate', 'datUcto', 'datVyst',
            'datum', 'postingPeriod', 'ucetniObdobi', 'mena', 'sumCelkem', 'sumCelkemMen',
            'typPohybuK', 'banka', 'banka@ref', 'pokladna', 'pokladna@ref', 'vypisCisDokl', 'bankaUcet', 'cisUctu',
            'bankaKod', 'kodBanky', 'varSym', 'konSym', 'specSym', 'protiUcet', 'ucetProti',
            'protiKodBanky', 'nazFirmy', 'firmaNazev', 'popis', 'poznam', 'zuctovano', 'storno', 'vazby',
            'vazebni-doklady',
        ], true);
        $row = array_intersect_key($row, $fields);
        if ($taxedCash) $row['_taxedCash'] = true;
        if (is_array($row['vazebni-doklady'] ?? null)) {
            $relationFields = array_fill_keys(['idDokl', 'idDokl@evidencePath', 'idVazby'], true);
            $row['vazebni-doklady'] = array_map(
                static fn (mixed $link): mixed => is_array($link) ? array_intersect_key($link, $relationFields) : $link,
                $row['vazebni-doklady'],
            );
        }
        return $row;
    }

    public static function compactJournal(array $row): array
    {
        $fields = array_fill_keys([
            'id', 'idUcetniDenik', 'idDokl', 'idDokl@evidencePath', 'kod', 'cisloDokl', 'cisDokl',
            'lastUpdate', 'datUcto', 'postingPeriod', 'ucetniObdobi', 'mdUcet', 'zklMdUcet',
            'dalUcet', 'zklDalUcet', 'sumTuz', 'sumMen', 'mena', 'kurz', 'kurzMnozstvi',
            'accountsSwapped', 'dimens', 'modulK', 'modul',
            'doklad', 'popis', 'text', 'storno', 'zuctovano',
        ], true);
        return array_intersect_key($row, $fields);
    }

    public static function compactAccountMovement(array $row): array
    {
        $fields = array_fill_keys([
            'id', 'idUcetniDenik', 'idDokl', 'idDokl@evidencePath', 'kod', 'cisloDokl', 'cisDokl',
            'lastUpdate', 'datUcto', 'postingPeriod', 'ucetniObdobi', 'ucet', 'mdUcet', 'dalUcet',
            'sumTuzMd', 'sumMd', 'sumTuzDal', 'sumDal',
        ], true);
        return array_intersect_key($row, $fields);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        unset($value['lastUpdate'], $value['@globalVersion']);
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = self::canonical($item);
        }
        unset($item);
        return $value;
    }
}
