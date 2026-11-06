<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Deterministické pseudonymy osobních a obchodních identifikátorů.
 *
 * - Stejný vstup dá v rámci běhu vždy stejný pseudonym, takže párování (IČO
 *   partnera ve faktuře i v bankovní transakci, účet v pravidle i ve výpisu)
 *   zůstane konzistentní napříč tabulkami.
 * - Pseudonym se odvozuje HMAC klíčem běhu. Bez klíče nejde originál dopočítat
 *   ani zkoušením všech IČO — proto se klíč nikam neukládá.
 * - Identifikátory s kontrolní číslicí zůstávají platné: IČO (vážený součet
 *   mod 11), rodné číslo (dělitelnost 11, datum narození a pohlaví zachované),
 *   české číslo účtu (mod 11 předčíslí i základu), IBAN (mod 97). Aplikace nad
 *   klonem tak prochází validacemi jako nad originálem.
 * - Kde na jedinečnosti záleží (IČO, účty, rodná čísla, e-maily…), je mapování
 *   prosté: kolize se řeší dalším pokusem, dva originály nikdy nedostanou týž
 *   pseudonym.
 *
 * Každá dvojice originál → pseudonym se zapisuje do {@see ReplacementDictionary},
 * ze kterého se potom přepisují volné texty.
 */
final class Pseudonymizer
{
    public const EMAIL_DOMAIN = 'example.invalid';

    private const TITLES = [
        'ing', 'ingarch', 'mgr', 'bc', 'mudr', 'mvdr', 'mddr', 'judr', 'phdr', 'rndr', 'paeddr',
        'thdr', 'phd', 'csc', 'drsc', 'mba', 'dis', 'doc', 'prof', 'dr', 'llm', 'msc', 'bsc', 'ma',
    ];

    /** @var array<string, array<string,string>> druh → originál → pseudonym */
    private array $maps = [];

    /** @var array<string, array<string,string>> druh → pseudonym → originál */
    private array $taken = [];

    /** @var array<string,bool> účty (předčíslí-základ bez nul), které se nemění */
    private array $preservedAccounts = [];

    /** @var array<string,string> normalizovaný klíč účtu (číslice) → pseudonym */
    private array $accountKeys = [];

    private readonly ReplacementDictionary $dictionary;

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Klíč pseudonymizace musí mít aspoň 16 bajtů.');
        }
        $this->dictionary = new ReplacementDictionary();
    }

    public function dictionary(): ReplacementDictionary
    {
        return $this->dictionary;
    }

    /**
     * Účet, který se pseudonymizovat NESMÍ — veřejné účty institucí (finanční
     * úřad, ČSSZ, pojišťovny, platební brány). Nejsou to osobní údaje a aplikace
     * podle nich rozpoznává platby.
     */
    public function preserveAccount(string $account): void
    {
        $parsed = self::parseNationalAccount($account);
        if ($parsed !== null) {
            $this->preservedAccounts[$parsed['prefix'] . '-' . $parsed['base']] = true;
        }
    }

    // ── Subjekty a osoby ─────────────────────────────────────────────────

    public function partyName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (!self::hasLetters($value)) {
            // Místo názvu jen číslo (terminál, číslo smlouvy) — i to identifikuje.
            return $this->shape($value);
        }
        [$core, $separator, $suffix] = self::splitLegalForm($value);
        if ($suffix === '' && self::looksLikePersonName($core)) {
            return $this->personName($value);
        }

        $key = mb_strtolower(ReplacementDictionary::fold($core), 'UTF-8');
        $pseudoCore = $this->unique('company', $key, function (int $attempt) use ($key): string {
            $first = PseudonymPools::COMPANY_FIRST;
            $second = PseudonymPools::COMPANY_SECOND;
            $n = $this->number('company', $key, $attempt, count($first) * count($second) * 50);
            $name = $first[$n % count($first)] . ' ' . $second[intdiv($n, count($first)) % count($second)];
            $round = intdiv($n, count($first) * count($second));

            return $round === 0 ? $name : $name . ' ' . ($round + 1);
        });
        if (self::isUpper($core)) {
            $pseudoCore = mb_strtoupper($pseudoCore, 'UTF-8');
        }
        $result = $suffix === '' ? $pseudoCore : $pseudoCore . $separator . $suffix;
        $this->dictionary->add($value, $result);
        $coreMin = str_contains($core, ' ') ? 4 : 5;
        $this->dictionary->add($core, $pseudoCore, $coreMin);

        return $result;
    }

    public function personName(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !self::hasLetters($value)) {
            return $value;
        }
        $tokens = preg_split('/\s+/u', $value) ?: [];
        $nameIndexes = [];
        foreach ($tokens as $i => $token) {
            if (!self::isTitle($token)) {
                $nameIndexes[] = $i;
            }
        }
        if ($nameIndexes === []) {
            return $value;
        }
        $female = false;
        foreach ($nameIndexes as $i) {
            if (preg_match('/á[,.]?$/u', mb_strtolower($tokens[$i], 'UTF-8')) === 1) {
                $female = true;
            }
        }
        $firstIndex = $nameIndexes[0];
        if (count($nameIndexes) >= 2 && self::looksLikeSurname($tokens[$nameIndexes[0]])) {
            $firstIndex = $nameIndexes[count($nameIndexes) - 1];
        }
        if (count($nameIndexes) === 1) {
            $firstIndex = -1;
        }
        $out = $tokens;
        foreach ($nameIndexes as $i) {
            $out[$i] = $i === $firstIndex
                ? $this->firstName($tokens[$i], $female)
                : $this->lastName($tokens[$i], $female);
        }
        $result = implode(' ', $out);
        $this->dictionary->add($value, $result);
        if (count($nameIndexes) === 2) {
            $a = $nameIndexes[0];
            $b = $nameIndexes[1];
            $this->dictionary->add($tokens[$b] . ' ' . $tokens[$a], $out[$b] . ' ' . $out[$a]);
        }

        return $result;
    }

    public function firstName(string $value, ?bool $female = null): string
    {
        $value = trim($value);
        if ($value === '' || !self::hasLetters($value)) {
            return $value;
        }
        [$core, $trail] = self::splitTrailingPunctuation($value);
        $female ??= preg_match('/[aeá]$/u', mb_strtolower($core, 'UTF-8')) === 1;
        $key = ($female ? 'f:' : 'm:') . mb_strtolower(ReplacementDictionary::fold($core), 'UTF-8');
        $pool = $female ? PseudonymPools::FEMALE_FIRST : PseudonymPools::MALE_FIRST;
        $pseudo = $this->mapped('first', $key, function () use ($key, $pool): string {
            return $pool[$this->number('first', $key, 0, count($pool))];
        });
        if ($pseudo === $core) {
            $pseudo = $pool[($this->number('first', $key, 0, count($pool)) + 1) % count($pool)];
        }

        return (self::isUpper($core) ? mb_strtoupper($pseudo, 'UTF-8') : $pseudo) . $trail;
    }

    public function lastName(string $value, ?bool $female = null): string
    {
        $value = trim($value);
        if ($value === '' || !self::hasLetters($value)) {
            return $value;
        }
        [$core, $trail] = self::splitTrailingPunctuation($value);
        if (str_contains($core, '-')) {
            $parts = array_map(fn (string $p): string => $this->lastName($p, $female), explode('-', $core));

            return implode('-', $parts) . $trail;
        }
        $female ??= preg_match('/á$/u', mb_strtolower($core, 'UTF-8')) === 1;
        $key = mb_strtolower(ReplacementDictionary::fold($core), 'UTF-8');
        $index = (int) $this->mapped('last', $key, function () use ($key): string {
            return (string) $this->number('last', $key, 0, count(PseudonymPools::SURNAMES));
        });
        $pair = PseudonymPools::SURNAMES[$index];
        $pseudo = $female ? $pair[1] : $pair[0];
        if (mb_strtolower($pseudo, 'UTF-8') === mb_strtolower($core, 'UTF-8')) {
            $pair = PseudonymPools::SURNAMES[($index + 1) % count(PseudonymPools::SURNAMES)];
            $pseudo = $female ? $pair[1] : $pair[0];
        }
        if (self::isUpper($core)) {
            $pseudo = mb_strtoupper($pseudo, 'UTF-8');
        }
        if (self::startsUpper($core)) {
            $this->dictionary->add($core, $pseudo, 4);
        }

        return $pseudo . $trail;
    }

    // ── Adresy ───────────────────────────────────────────────────────────

    public function street(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (preg_match('/^(.*?\D)?\s*(\d[\w\/\-]*)$/u', $value, $m) === 1 && trim($m[1]) !== '') {
            $name = trim($m[1]);
            $number = $m[2];
        } elseif (preg_match('/^\d[\w\/\-]*$/u', $value) === 1) {
            return $this->houseNumber($value);
        } else {
            $name = $value;
            $number = '';
        }
        $key = mb_strtolower(ReplacementDictionary::fold($name), 'UTF-8');
        $pseudoName = PseudonymPools::STREETS[$this->number('street', $key, 0, count(PseudonymPools::STREETS))];
        $result = $number === '' ? $pseudoName : $pseudoName . ' ' . $this->houseNumber($number);
        $this->dictionary->add($value, $result, 6);
        if ($number !== '') {
            $this->dictionary->add($name, $pseudoName, 6);
        }

        return $result;
    }

    private function houseNumber(string $number): string
    {
        $pseudo = (string) (1 + $this->number('house', $number, 0, 180));
        if (str_contains($number, '/')) {
            $pseudo = (string) (100 + $this->number('house', $number, 1, 2800)) . '/' . (1 + $this->number('house', $number, 2, 60));
        }

        return $pseudo;
    }

    public function city(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !self::hasLetters($value)) {
            return $value;
        }
        $key = mb_strtolower(ReplacementDictionary::fold($value), 'UTF-8');
        $city = PseudonymPools::CITIES[$this->number('city', $key, 0, count(PseudonymPools::CITIES))][0];

        return self::isUpper($value) ? mb_strtoupper($city, 'UTF-8') : $city;
    }

    public function zip(string $value): string
    {
        $value = trim($value);
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 5) {
            return $value === '' ? $value : $this->shape($value);
        }
        $zip = PseudonymPools::CITIES[$this->number('zip', $digits, 0, count(PseudonymPools::CITIES))][1];

        return str_contains($value, ' ') ? $zip : str_replace(' ', '', $zip);
    }

    /** Jednořádková adresa „Ulice 12, 110 00 Obec" — celá se nahradí vymyšlenou. */
    public function address(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !self::hasLetters($value)) {
            return $value;
        }
        $key = mb_strtolower(ReplacementDictionary::fold($value), 'UTF-8');
        $street = PseudonymPools::STREETS[$this->number('address', $key, 0, count(PseudonymPools::STREETS))];
        $city = PseudonymPools::CITIES[$this->number('address', $key, 1, count(PseudonymPools::CITIES))];
        $result = $street . ' ' . (1 + $this->number('address', $key, 2, 180)) . ', ' . $city[1] . ' ' . $city[0];
        $this->dictionary->add($value, $result, 6);

        return $result;
    }

    // ── Identifikátory s kontrolní číslicí ──────────────────────────────

    public function ico(string $value): string
    {
        $trimmed = trim($value);
        $digits = preg_replace('/\s+/', '', $trimmed) ?? '';
        if (preg_match('/^\d{6,8}$/D', $digits) !== 1) {
            return $trimmed === '' ? $trimmed : $this->shape($trimmed);
        }
        $digits = str_pad($digits, 8, '0', STR_PAD_LEFT);
        $pseudo = $this->unique('ico', $digits, function (int $attempt) use ($digits): string {
            $body = str_pad((string) $this->number('ico', $digits, $attempt, 9_999_999), 7, '0', STR_PAD_LEFT);

            return $body . self::icoCheckDigit($body);
        });
        $this->dictionary->add($digits, $pseudo, 6);
        $this->dictionary->add(ltrim($digits, '0'), ltrim($pseudo, '0') === '' ? $pseudo : $pseudo, 6);
        $this->dictionary->add(substr($digits, 0, 3) . ' ' . substr($digits, 3, 2) . ' ' . substr($digits, 5), substr($pseudo, 0, 3) . ' ' . substr($pseudo, 3, 2) . ' ' . substr($pseudo, 5), 6);

        return $pseudo;
    }

    public function dic(string $value): string
    {
        $trimmed = trim($value);
        $compact = strtoupper(preg_replace('/\s+/', '', $trimmed) ?? '');
        if ($compact === '') {
            return $trimmed;
        }
        if (preg_match('/^\d{8}$/D', $compact) === 1) {
            return $this->ico($compact);
        }
        if (preg_match('/^([A-Z]{2})(\w+)$/D', $compact, $m) !== 1) {
            return $this->shape($trimmed);
        }
        [$country, $rest] = [$m[1], $m[2]];
        if ($country === 'CZ' && preg_match('/^\d{8}$/D', $rest) === 1) {
            $pseudo = 'CZ' . $this->ico($rest);
        } elseif ($country === 'CZ' && preg_match('/^\d{9,10}$/D', $rest) === 1 && self::isValidBirthNumber($rest)) {
            $pseudo = 'CZ' . str_replace('/', '', $this->birthNumber($rest));
        } else {
            $pseudo = $this->unique('dic', $compact, fn (int $attempt): string => $country . $this->shapeWith('dic', $rest, $attempt));
        }
        $this->dictionary->add($compact, $pseudo, 6);

        return $pseudo;
    }

    /**
     * Rodné číslo: datum narození a pohlaví (prvních šest číslic) zůstává, mění
     * se koncovka. Mzdy tak dál počítají věk a slevy stejně jako nad originálem
     * a číslo prochází kontrolou dělitelnosti jedenácti.
     */
    public function birthNumber(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^(\d{6})\s*\/?\s*(\d{3,4})$/D', $trimmed, $m) !== 1) {
            return $trimmed === '' ? $trimmed : $this->shape($trimmed);
        }
        $digits = $m[1] . $m[2];
        $withSlash = str_contains($trimmed, '/');
        $pseudoDigits = $this->unique('rc', $digits, function (int $attempt) use ($m, $digits): string {
            $head = $m[1];
            if (strlen($m[2]) === 3) {
                $serial = str_pad((string) $this->number('rc', $digits, $attempt, 1000), 3, '0', STR_PAD_LEFT);

                return $head . $serial;
            }
            for ($try = 0; $try < 50; $try++) {
                $serial = str_pad((string) $this->number('rc', $digits, $attempt * 50 + $try, 1000), 3, '0', STR_PAD_LEFT);
                $nine = (int) ($head . $serial);
                $check = (11 - (($nine * 10) % 11)) % 11;
                if ($check < 10) {
                    return $head . $serial . $check;
                }
            }

            return $head . '000' . ((11 - (((int) ($head . '000')) * 10 % 11)) % 11 % 10);
        });
        $format = static fn (string $d): string => substr($d, 0, 6) . '/' . substr($d, 6);
        $this->dictionary->add($digits, $pseudoDigits, 6);
        $this->dictionary->add($format($digits), $format($pseudoDigits), 6);

        return $withSlash ? $format($pseudoDigits) : $pseudoDigits;
    }

    /**
     * Číslo účtu v kterémkoli uloženém tvaru: `předčíslí-základ/banka`, bez banky,
     * 16 cifer z GPC, `banka:16 cifer` (klíč měsíčních výpisů) nebo IBAN.
     * Kód banky a předčíslí zůstávají, mění se základ (platný mod 11).
     */
    public function bankAccount(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || str_contains($trimmed, '*')) {
            return $trimmed === '' ? $trimmed : $this->shapeWith('masked', $trimmed, 0);
        }
        $compact = strtoupper(preg_replace('/\s+/', '', $trimmed) ?? '');
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/D', $compact) === 1) {
            return $this->iban($trimmed);
        }
        if (preg_match('/^(\d{4}):(\d{16})$/D', $compact, $m) === 1) {
            $pseudo = $this->nationalAccount(substr($m[2], 0, 6), substr($m[2], 6));

            return $m[1] . ':' . str_pad($pseudo['prefix'], 6, '0', STR_PAD_LEFT) . str_pad($pseudo['base'], 10, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(?:(\d{1,6})-)?(\d{1,10})(\/\d{4})?$/D', $compact, $m) === 1) {
            $pseudo = $this->nationalAccount($m[1], $m[2]);
            $base = strlen($m[2]) > strlen($pseudo['base']) ? str_pad($pseudo['base'], strlen($m[2]), '0', STR_PAD_LEFT) : $pseudo['base'];

            return ($m[1] !== '' ? $m[1] . '-' : '') . $base . ($m[3] ?? '');
        }
        if (preg_match('/^(\d{16})(\/\d{4})?$/D', $compact, $m) === 1) {
            $pseudo = $this->nationalAccount(substr($m[1], 0, 6), substr($m[1], 6));

            return str_pad($pseudo['prefix'], 6, '0', STR_PAD_LEFT) . str_pad($pseudo['base'], 10, '0', STR_PAD_LEFT) . ($m[2] ?? '');
        }

        return $this->shapeWith('account', $trimmed, 0);
    }

    /**
     * Klíč účtu uložený jen jako číslice bez nul (`account_key`, `account_canonical`).
     * Vede se na tentýž pseudonym jako plný zápis účtu, pokud už prošel mapováním.
     */
    public function accountKey(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        if (isset($this->accountKeys[$trimmed])) {
            return $this->accountKeys[$trimmed];
        }
        if (preg_match('/^\d{1,10}$/D', $trimmed) === 1) {
            return ltrim($this->nationalAccount('', $trimmed)['base'], '0');
        }
        if (preg_match('/^(\d{1,6})(\d{10})$/D', $trimmed, $m) === 1) {
            $pseudo = $this->nationalAccount($m[1], $m[2]);

            return ltrim($pseudo['prefix'] . str_pad($pseudo['base'], 10, '0', STR_PAD_LEFT), '0');
        }

        return $this->bankAccount($trimmed);
    }

    public function iban(string $value): string
    {
        $trimmed = trim($value);
        $compact = strtoupper(preg_replace('/\s+/', '', $trimmed) ?? '');
        if (preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/D', $compact, $m) !== 1) {
            return $trimmed === '' ? $trimmed : $this->shape($trimmed);
        }
        [$country, $bban] = [$m[1], $m[3]];
        if (in_array($country, ['CZ', 'SK'], true) && preg_match('/^(\d{4})(\d{6})(\d{10})$/D', $bban, $b) === 1) {
            $pseudo = $this->nationalAccount($b[2], $b[3]);
            $newBban = $b[1] . str_pad($pseudo['prefix'], 6, '0', STR_PAD_LEFT) . str_pad($pseudo['base'], 10, '0', STR_PAD_LEFT);
        } else {
            $newBban = $this->unique('iban', $compact, fn (int $attempt): string => $this->shapeWith('iban', $bban, $attempt, true));
        }
        $result = $country . self::ibanCheckDigits($country, $newBban) . $newBban;
        $this->dictionary->add($compact, $result, 6);
        $this->dictionary->add(trim(chunk_split($compact, 4, ' ')), trim(chunk_split($result, 4, ' ')), 6);
        if (str_contains($trimmed, ' ')) {
            return trim(chunk_split($result, 4, ' '));
        }

        return $result;
    }

    /** @return array{prefix:string,base:string} */
    private function nationalAccount(?string $prefix, string $base): array
    {
        $prefixNorm = ltrim((string) $prefix, '0');
        $baseNorm = ltrim($base, '0');
        if ($baseNorm === '') {
            return ['prefix' => $prefixNorm, 'base' => $base];
        }
        if (isset($this->preservedAccounts[$prefixNorm . '-' . $baseNorm])) {
            return ['prefix' => $prefixNorm, 'base' => $baseNorm];
        }
        $key = $prefixNorm . '-' . $baseNorm;
        $pseudoBase = $this->unique('account', $key, function (int $attempt) use ($key, $baseNorm): string {
            $length = max(2, strlen($baseNorm));
            for ($try = 0; $try < 40; $try++) {
                $candidate = self::digitsFrom($this->hmac('account', $key, $attempt * 40 + $try), $length - 1, true);
                $check = self::accountCheckDigit($candidate);
                if ($check !== null && $candidate . $check !== $baseNorm) {
                    return $candidate . $check;
                }
            }

            return str_pad('1', $length - 1, '0') . '0';
        });
        $this->accountKeys[ltrim($prefixNorm . str_pad($baseNorm, 10, '0', STR_PAD_LEFT), '0')] = ltrim($prefixNorm . str_pad($pseudoBase, 10, '0', STR_PAD_LEFT), '0');
        if ($prefixNorm === '') {
            $this->accountKeys[$baseNorm] = $pseudoBase;
        }
        $national = ($prefixNorm !== '' ? $prefixNorm . '-' : '') . $baseNorm;
        $pseudoNational = ($prefixNorm !== '' ? $prefixNorm . '-' : '') . $pseudoBase;
        $this->dictionary->add($national, $pseudoNational, 6);
        if ($prefixNorm !== '') {
            $this->dictionary->add($prefixNorm . $baseNorm, $prefixNorm . $pseudoBase, 6);
        }
        $this->dictionary->add(str_pad($prefixNorm, 6, '0', STR_PAD_LEFT) . str_pad($baseNorm, 10, '0', STR_PAD_LEFT), str_pad($prefixNorm, 6, '0', STR_PAD_LEFT) . str_pad($pseudoBase, 10, '0', STR_PAD_LEFT), 6);

        return ['prefix' => $prefixNorm, 'base' => $pseudoBase];
    }

    // ── Kontakty a ostatní ──────────────────────────────────────────────

    public function email(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        $count = 0;
        $result = preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            fn (array $m): string => $this->singleEmail($m[0]),
            $trimmed,
            -1,
            $count,
        );
        if ($count === 0) {
            return $this->singleEmail($trimmed);
        }

        return (string) $result;
    }

    private function singleEmail(string $email): string
    {
        $key = mb_strtolower(trim($email), 'UTF-8');
        if (str_ends_with($key, '@' . self::EMAIL_DOMAIN)) {
            return $email;
        }
        $pseudo = $this->unique('email', $key, fn (int $attempt): string => 'osoba-' . self::alnumFrom($this->hmac('email', $key, $attempt), 8) . '@' . self::EMAIL_DOMAIN);
        $this->dictionary->add($email, $pseudo, 6);

        return $pseudo;
    }

    public function phone(string $value): string
    {
        $trimmed = trim($value);
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';
        if ($digits === '') {
            return $trimmed;
        }
        $national = strlen($digits) > 9 ? substr($digits, -9) : $digits;
        $pseudoNational = $this->unique('phone', $national, function (int $attempt) use ($national): string {
            $lead = $national[0] ?? '6';

            return $lead . self::digitsFrom($this->hmac('phone', $national, $attempt), max(0, strlen($national) - 1), false);
        });
        $position = 0;
        $digitIndex = 0;
        $offset = strlen($digits) - strlen($national);
        $out = '';
        $length = strlen($trimmed);
        for ($position = 0; $position < $length; $position++) {
            $char = $trimmed[$position];
            if (ctype_digit($char)) {
                $out .= $digitIndex >= $offset ? $pseudoNational[$digitIndex - $offset] : $char;
                $digitIndex++;
            } else {
                $out .= $char;
            }
        }
        if (strlen($national) >= 9) {
            $this->dictionary->add($national, $pseudoNational, 9);
            $this->dictionary->add($trimmed, $out, 9);
        }

        return $out;
    }

    public function cardLast4(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}$/D', $trimmed) !== 1) {
            return $trimmed === '' ? $trimmed : $this->shape($trimmed);
        }

        return $this->unique('card', $trimmed, fn (int $attempt): string => self::digitsFrom($this->hmac('card', $trimmed, $attempt), 4, false));
    }

    public function dataBox(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        $pseudo = $this->unique('databox', $trimmed, function (int $attempt) use ($trimmed): string {
            $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
            $bytes = $this->hmac('databox', $trimmed, $attempt);
            $out = '';
            for ($i = 0; $i < max(1, strlen($trimmed)); $i++) {
                $out .= $alphabet[ord($bytes[$i % 32]) % strlen($alphabet)];
            }

            return $out;
        });
        $this->dictionary->add($trimmed, $pseudo, 7);

        return $pseudo;
    }

    public function web(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        $scheme = preg_match('#^(https?://)#i', $trimmed, $m) === 1 ? strtolower($m[1]) : '';

        return $scheme . 'www.' . $this->hostLabel($trimmed) . '.' . self::EMAIL_DOMAIN;
    }

    public function host(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        return $this->hostLabel($trimmed) . '.' . self::EMAIL_DOMAIN;
    }

    private function hostLabel(string $value): string
    {
        $key = mb_strtolower($value, 'UTF-8');

        return $this->unique('host', $key, fn (int $attempt): string => 'h' . self::alnumFrom($this->hmac('host', $key, $attempt), 8));
    }

    public function costCenter(string $value): string
    {
        if ($value === '' || preg_match('/^MZ-(?:EX|SR)-(?:[0-9]+|[0-9A-F]{16})$/Di', $value) === 1 || $this->isIssuedPseudonym('cost_center', $value)) return $value;
        $key = mb_strtoupper(trim($value), 'UTF-8');
        return $this->unique('cost_center', $key, fn (int $attempt): string => 'CC' . strtoupper(bin2hex(substr($this->hmac('cost_center', $key, $attempt), 0, 24))));
    }

    public function login(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }
        if (str_contains($trimmed, '@')) {
            return $this->email($trimmed);
        }

        return $this->unique('login', $trimmed, fn (int $attempt): string => 'uzivatel-' . self::alnumFrom($this->hmac('login', $trimmed, $attempt), 6));
    }

    /**
     * Obecný identifikátor bez známé struktury: číslice za číslice, písmena za
     * písmena (se zachovanou velikostí), oddělovače zůstanou. Číselné hodnoty od
     * šesti cifer jdou do slovníku, aby je šlo dohledat i ve variabilních symbolech.
     */
    public function shape(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/[\p{L}\p{N}]/u', $trimmed) !== 1) {
            return $trimmed;
        }
        $pseudo = $this->unique('shape', $trimmed, fn (int $attempt): string => $this->shapeWith('shape', $trimmed, $attempt));
        $this->dictionary->add($trimmed, $pseudo, 6);

        return $pseudo;
    }

    /** Jako {@see shape()}, ale mění jen číslice (spisové značky, rejstříky). */
    public function shapeDigits(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/\d/', $trimmed) !== 1) {
            return $trimmed;
        }
        $pseudo = $this->unique('shape_digits', $trimmed, fn (int $attempt): string => $this->shapeWith('shape_digits', $trimmed, $attempt, true));
        $this->dictionary->add($trimmed, $pseudo, 6);

        return $pseudo;
    }

    /**
     * Symbol platby (VS/SS). Mění se jen tehdy, když CELÝ symbol je identifikátor,
     * který už prošel pseudonymizací (IČO, rodné číslo, číslo účtu, variabilní
     * symbol zaměstnavatele). Čísla faktur zůstávají — jinak by se rozpadlo párování.
     */
    public function symbol(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }
        $hit = $this->dictionary->lookup($trimmed);
        if ($hit === null && ltrim($trimmed, '0') !== $trimmed && ltrim($trimmed, '0') !== '') {
            $hit = $this->dictionary->lookup(ltrim($trimmed, '0'));
        }
        if ($hit === null || preg_match('/^\d+$/D', $hit) !== 1) {
            return $value;
        }

        return strlen($hit) < strlen($trimmed) ? str_pad($hit, strlen($trimmed), '0', STR_PAD_LEFT) : $hit;
    }

    /**
     * Uživatelský název souboru — pseudonym se zachovanou příponou.
     */
    public function fileName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }
        $extension = pathinfo($trimmed, PATHINFO_EXTENSION);
        $extension = preg_match('/^[A-Za-z0-9]{1,6}$/D', $extension) === 1 ? '.' . $extension : '';

        return 'soubor-' . self::alnumFrom($this->hmac('file', $trimmed, 0), 10) . $extension;
    }

    /**
     * Uložená cesta k souboru. Adresáře a strojově generované názvy (hash, datum)
     * zůstávají, lidsky pojmenovaný soubor se nahradí — stejnou funkcí se
     * přejmenují i zástupné soubory v zrcadle úložiště, takže cesta v databázi
     * sedí na disk.
     */
    public function filePath(string $value): string
    {
        if (trim($value) === '') {
            return $value;
        }
        // Adresáře úložiště pojmenovává aplikace (documents/sup-3/ab), lidský název
        // nese jen poslední úsek — soubor.
        $cut = max((int) strrpos($value, '/'), (int) strrpos($value, '\\'));
        $dir = $cut > 0 || str_starts_with($value, '/') || str_starts_with($value, '\\') ? substr($value, 0, $cut + 1) : '';
        $name = substr($value, strlen($dir));
        if ($name === '' || $name === '.' || $name === '..' || self::isMachineName($name)) {
            return $value;
        }

        return $dir . $this->fileName($name);
    }

    public static function isMachineName(string $segment): bool
    {
        $stem = preg_replace('/\.[A-Za-z0-9]{1,6}$/D', '', $segment) ?? $segment;

        return preg_match('/^(?:sup-\d+|_thumbs|[0-9a-f]{2}|[0-9a-f_\-.]*\d[0-9a-f_\-.]*|[a-z]+[-_]\d+[0-9a-z_\-]*|soubor-[a-z0-9]{10}|thumb[-_][0-9a-f\-]+|[a-z0-9_\-]*[0-9a-f]{16,}[a-z0-9_\-]*)$/iD', $stem) === 1;
    }

    // ── Validace (veřejné kvůli testům a kontrolám klonu) ────────────────

    public static function icoCheckDigit(string $sevenDigits): string
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $sevenDigits[$i] * (8 - $i);
        }

        return (string) ((11 - ($sum % 11)) % 10);
    }

    public static function isValidIco(string $ico): bool
    {
        return preg_match('/^\d{8}$/D', $ico) === 1 && self::icoCheckDigit(substr($ico, 0, 7)) === $ico[7];
    }

    public static function isValidBirthNumber(string $value): bool
    {
        $digits = str_replace('/', '', trim($value));
        if (preg_match('/^(\d{2})(\d{2})(\d{2})(\d{3,4})$/D', $digits, $m) !== 1) {
            return false;
        }
        $month = (int) $m[2];
        foreach ([70, 50, 20, 0] as $offset) {
            if ($month > $offset && $month - $offset <= 12) {
                $month -= $offset;
                break;
            }
        }
        if ($month < 1 || $month > 12 || (int) $m[3] < 1 || (int) $m[3] > 31) {
            return false;
        }
        if (strlen($digits) === 9) {
            return true;
        }
        if ((int) $digits % 11 === 0) {
            return true;
        }

        return ((int) substr($digits, 0, 9)) % 11 === 10 && $digits[9] === '0';
    }

    public static function isValidAccountPart(string $digits): bool
    {
        if (preg_match('/^\d{1,10}$/D', $digits) !== 1) {
            return false;
        }
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $padded = str_pad($digits, 10, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $padded[$i] * $weights[$i];
        }

        return $sum % 11 === 0;
    }

    public static function ibanCheckDigits(string $country, string $bban): string
    {
        $rearranged = $bban . $country . '00';
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $mod = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $mod = (int) (($mod . $chunk)) % 97;
        }

        return str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT);
    }

    public static function isValidIban(string $iban): bool
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/D', $compact, $m) !== 1) {
            return false;
        }

        return self::ibanCheckDigits($m[1], $m[3]) === $m[2];
    }

    /** @return array{prefix:string,base:string}|null */
    public static function parseNationalAccount(string $account): ?array
    {
        $compact = strtoupper(preg_replace('/\s+/', '', trim($account)) ?? '');
        if (preg_match('/^(?:CZ|SK)\d{2}\d{4}(\d{6})(\d{10})$/D', $compact, $m) === 1) {
            return ['prefix' => ltrim($m[1], '0'), 'base' => ltrim($m[2], '0')];
        }
        $compact = preg_replace('#/\d{4}$#', '', $compact) ?? $compact;
        if (preg_match('/^(?:(\d{1,6})-)?(\d{1,10})$/D', $compact, $m) === 1) {
            return ['prefix' => ltrim($m[1], '0'), 'base' => ltrim($m[2], '0')];
        }
        if (preg_match('/^(\d{6})(\d{10})$/D', $compact, $m) === 1) {
            return ['prefix' => ltrim($m[1], '0'), 'base' => ltrim($m[2], '0')];
        }

        return null;
    }

    /** Je hodnota pseudonym, který tenhle běh už vydal? (proti dvojímu přepisu) */
    public function isIssuedPseudonym(string $kind, string $value): bool
    {
        return isset($this->taken[$kind][$value]);
    }

    // ── Vnitřní pomocníci ────────────────────────────────────────────────

    private function hmac(string $kind, string $value, int $attempt): string
    {
        return hash_hmac('sha256', $kind . "\0" . $attempt . "\0" . $value, $this->secret, true);
    }

    private function number(string $kind, string $value, int $attempt, int $modulo): int
    {
        $bytes = $this->hmac($kind, $value, $attempt);
        $int = unpack('J', substr($bytes, 0, 8));
        $n = is_array($int) ? (int) $int[1] : 0;

        return (int) (($n & PHP_INT_MAX) % max(1, $modulo));
    }

    /** @param callable():string $generate */
    private function mapped(string $kind, string $key, callable $generate): string
    {
        return $this->maps[$kind][$key] ??= $generate();
    }

    /** @param callable(int):string $generate */
    private function unique(string $kind, string $key, callable $generate): string
    {
        if (isset($this->maps[$kind][$key])) {
            return $this->maps[$kind][$key];
        }
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $candidate = $generate($attempt);
            if ($candidate === $key || isset($this->taken[$kind][$candidate])) {
                continue;
            }
            $this->maps[$kind][$key] = $candidate;
            $this->taken[$kind][$candidate] = $key;

            return $candidate;
        }
        // Malý prostor hodnot (jednociferný kód): prostost se vzdá, jen ne za cenu originálu.
        for ($attempt = 1000; $attempt < 1100; $attempt++) {
            $candidate = $generate($attempt);
            if ($candidate !== $key) {
                return $this->maps[$kind][$key] = $candidate;
            }
        }
        throw new \RuntimeException("Pro druh {$kind} se nepodařilo najít pseudonym.");
    }

    private function shapeWith(string $kind, string $value, int $attempt, bool $digitsOnly = false): string
    {
        $bytes = $this->hmac($kind, $value, $attempt);
        $chars = mb_str_split($value, 1, 'UTF-8');
        $out = '';
        foreach ($chars as $i => $char) {
            $byte = ord($bytes[$i % 32]) ^ ($i >> 5);
            if (ctype_digit($char)) {
                $out .= (string) ($byte % 10);
            } elseif (!$digitsOnly && preg_match('/^\p{Lu}$/u', $char) === 1) {
                $out .= chr(65 + $byte % 26);
            } elseif (!$digitsOnly && preg_match('/^\p{Ll}$/u', $char) === 1) {
                $out .= chr(97 + $byte % 26);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    private static function digitsFrom(string $bytes, int $length, bool $nonZeroFirst): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $digit = ord($bytes[$i % 32]) % 10;
            if ($i === 0 && $nonZeroFirst && $digit === 0) {
                $digit = 1 + ord($bytes[($i + 7) % 32]) % 9;
            }
            $out .= (string) $digit;
        }

        return $out;
    }

    private static function alnumFrom(string $bytes, int $length): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i % 32]) % strlen($alphabet)];
        }

        return $out;
    }

    private static function accountCheckDigit(string $prefixDigits): ?string
    {
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $padded = str_pad($prefixDigits . '0', 10, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $padded[$i] * $weights[$i];
        }
        $check = (11 - ($sum % 11)) % 11;

        return $check === 10 ? null : (string) $check;
    }

    /** @return array{0:string,1:string,2:string} jádro, oddělovač, právní forma */
    private static function splitLegalForm(string $value): array
    {
        $tokens = preg_split('/(\s+|,)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$value];
        $words = [];
        foreach ($tokens as $i => $token) {
            if (trim($token) !== '' && $token !== ',') {
                $words[] = $i;
            }
        }
        for ($take = min(4, count($words) - 1); $take >= 1; $take--) {
            $startToken = $words[count($words) - $take];
            $suffix = implode('', array_slice($tokens, $startToken));
            $normalized = strtolower(preg_replace('/[^a-z]/i', '', ReplacementDictionary::fold($suffix)) ?? '');
            if (in_array($normalized, PseudonymPools::LEGAL_FORMS, true)) {
                $before = implode('', array_slice($tokens, 0, $startToken));
                $core = rtrim($before, " ,\t");
                $separator = substr($before, strlen($core));

                return [$core, $separator === '' ? ' ' : $separator, $suffix];
            }
        }

        return [$value, '', ''];
    }

    private static function looksLikePersonName(string $value): bool
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($value)) ?: [], static fn (string $t): bool => !self::isTitle($t)));
        if (count($tokens) < 2 || count($tokens) > 3) {
            return false;
        }
        foreach ($tokens as $token) {
            if (preg_match('/^\p{Lu}[\p{L}\'\-]*[.,]?$/u', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function looksLikeSurname(string $token): bool
    {
        return preg_match('/(ová|ý|á|í)[,.]?$/u', mb_strtolower($token, 'UTF-8')) === 1;
    }

    private static function isTitle(string $token): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z]/i', '', $token) ?? '');

        return $normalized !== '' && (in_array($normalized, self::TITLES, true) || str_ends_with($token, '.') && mb_strlen($token, 'UTF-8') <= 5);
    }

    private static function hasLetters(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    private static function isUpper(string $value): bool
    {
        return mb_strtoupper($value, 'UTF-8') === $value && mb_strtolower($value, 'UTF-8') !== $value && mb_strlen($value, 'UTF-8') > 3;
    }

    private static function startsUpper(string $value): bool
    {
        return preg_match('/^\p{Lu}/u', $value) === 1;
    }

    /** @return array{0:string,1:string} */
    private static function splitTrailingPunctuation(string $value): array
    {
        if (preg_match('/^(.*?)([.,;:]*)$/uD', $value, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [$value, ''];
    }
}
