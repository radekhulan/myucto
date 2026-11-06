<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Pseudonymizace volného textu, JSON a XML.
 *
 * 1. Slovník ze strukturovaných sloupců ({@see ReplacementDictionary}) nahradí
 *    všechna známá jména, IČO, účty a rodná čísla jejich pseudonymy.
 * 2. Vzory zachytí identifikátory, které ve strukturovaných sloupcích nebyly:
 *    e-maily, IBAN, česká čísla účtů s kódem banky (jen platná podle mod 11 —
 *    číslo dokladu „2024/0100" zůstane), rodná čísla s lomítkem (jen platná),
 *    DIČ, IČO za popiskem a telefonní čísla s předvolbou nebo popiskem.
 *
 * Hodnoty, které už jsou pseudonymem z tohoto běhu, se podruhé nepřepisují.
 */
final class TextScrubber
{
    private const PATTERN = '/'
        . '(?<email>[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})'
        . '|(?<iban>(?<![\p{L}\p{N}])(?:CZ|SK)\d{2}(?:\s?\d{4}){5}(?![\p{L}\p{N}]))'
        . '|(?<acct>(?<![\p{L}\p{N}\-\/])(?:\d{1,6}-)?\d{2,10}\/\d{4}(?![\p{L}\p{N}]))'
        . '|(?<rc>(?<![\p{L}\p{N}\/])\d{6}\/\d{3,4}(?![\p{L}\p{N}\/]))'
        . '|(?<dic>(?<![\p{L}\p{N}])CZ\d{8,10}(?![\p{L}\p{N}]))'
        . '|(?<ico>(?<![\p{L}\p{N}])(?:IČO?|ICO?|IČ)\s*[:.]?\s*)(?<icodigits>\d{3}\s?\d{2}\s?\d{3}|\d{8})(?![\p{L}\p{N}])'
        . '|(?<phonelabel>(?<![\p{L}\p{N}])(?:tel|telefon|mobil|mob|phone|GSM)\.?\s*:?\s*)(?<phonedigits>(?:\+|00)?(?:\d[\s\-]?){9,12}\d)(?![\p{L}\p{N}])'
        . '|(?<phone>(?<![\p{L}\p{N}+])(?:\+|00)420[\s\-]?\d{3}[\s\-]?\d{3}[\s\-]?\d{3}(?![\p{L}\p{N}]))'
        . '/u';

    /**
     * Klíče JSON, jejichž hodnotu určuje klíč, ne obsah (porovnává se bez
     * velikosti písmen, podtržítek a pomlček).
     *
     * @var array<string,string>
     */
    private const JSON_KEYS = [
        'costcenter' => 'cost_center',
        'companyname' => 'party_name', 'displayname' => 'party_name', 'partnername' => 'party_name',
        'vendorname' => 'party_name', 'buyername' => 'party_name', 'customername' => 'party_name',
        'counterpartyname' => 'party_name', 'payername' => 'party_name', 'payeename' => 'party_name',
        'holdername' => 'party_name', 'accountname' => 'party_name', 'suppliername' => 'party_name',
        'clientname' => 'party_name', 'recipientname' => 'party_name', 'sendername' => 'party_name',
        'nazev' => 'party_name', 'obchjmeno' => 'party_name', 'firma' => 'party_name',
        'firstname' => 'first_name', 'givenname' => 'first_name', 'jmeno' => 'first_name',
        'lastname' => 'last_name', 'familyname' => 'last_name', 'surname' => 'last_name', 'prijmeni' => 'last_name',
        'fullname' => 'person_name', 'personname' => 'person_name', 'contactname' => 'person_name',
        'employeename' => 'person_name',
        'street' => 'street', 'ulice' => 'street', 'streetline' => 'street',
        'city' => 'city', 'obec' => 'city', 'mesto' => 'city',
        'zip' => 'zip', 'postalcode' => 'zip', 'psc' => 'zip',
        'ic' => 'ico', 'ico' => 'ico', 'ičo' => 'ico', 'companyid' => 'ico', 'vendorico' => 'ico', 'buyerico' => 'ico',
        'dic' => 'dic', 'dič' => 'dic', 'vatid' => 'dic', 'taxid' => 'dic', 'vatnumber' => 'dic',
        'vendordic' => 'dic', 'buyerdic' => 'dic',
        'birthnumber' => 'birth_number', 'rodnecislo' => 'birth_number', 'rc' => 'birth_number',
        'email' => 'email', 'mainemail' => 'email', 'mail' => 'email', 'replyto' => 'email',
        'phone' => 'phone', 'telefon' => 'phone', 'tel' => 'phone', 'mobile' => 'phone',
        'accountnumber' => 'bank_account', 'bankaccount' => 'bank_account', 'counterpartyaccount' => 'bank_account',
        'cislouctu' => 'bank_account', 'payeeaccountnumber' => 'bank_account', 'payeraccountnumber' => 'bank_account',
        'iban' => 'iban', 'payeeiban' => 'iban', 'payeriban' => 'iban',
        'databox' => 'data_box', 'databoxid' => 'data_box', 'isdsbox' => 'data_box',
        'address' => 'address', 'adresa' => 'address',
    ];

    public function __construct(private readonly Pseudonymizer $pseudonymizer) {}

    public function scrub(string $text): string
    {
        if ($text === '' || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return $text;
        }
        $text = $this->pseudonymizer->dictionary()->apply($text);

        $result = preg_replace_callback(self::PATTERN, function (array $m): string {
            if (($m['email'] ?? '') !== '') {
                return $this->pseudonymizer->isIssuedPseudonym('email', mb_strtolower($m['email'], 'UTF-8'))
                    ? $m['email'] : $this->pseudonymizer->email($m['email']);
            }
            if (($m['iban'] ?? '') !== '') {
                return Pseudonymizer::isValidIban($m['iban']) ? $this->pseudonymizer->iban($m['iban']) : $m['iban'];
            }
            if (($m['acct'] ?? '') !== '') {
                return $this->account($m['acct']);
            }
            if (($m['rc'] ?? '') !== '') {
                $digits = str_replace('/', '', $m['rc']);

                return Pseudonymizer::isValidBirthNumber($m['rc']) && !$this->pseudonymizer->isIssuedPseudonym('rc', $digits)
                    ? $this->pseudonymizer->birthNumber($m['rc']) : $m['rc'];
            }
            if (($m['dic'] ?? '') !== '') {
                $rest = substr($m['dic'], 2);
                $issued = $this->pseudonymizer->isIssuedPseudonym('ico', $rest) || $this->pseudonymizer->isIssuedPseudonym('rc', $rest);

                return $issued ? $m['dic'] : $this->pseudonymizer->dic($m['dic']);
            }
            if (($m['icodigits'] ?? '') !== '') {
                $digits = preg_replace('/\s+/', '', $m['icodigits']) ?? '';
                if ($this->pseudonymizer->isIssuedPseudonym('ico', $digits)) {
                    return $m[0];
                }

                return $m['ico'] . $this->pseudonymizer->ico($digits);
            }
            if (($m['phonedigits'] ?? '') !== '') {
                return $m['phonelabel'] . $this->phone($m['phonedigits']);
            }
            if (($m['phone'] ?? '') !== '') {
                return $this->phone($m['phone']);
            }

            return $m[0];
        }, $text);

        return is_string($result) ? $result : $text;
    }

    /**
     * JSON se prochází po hodnotách: u známých klíčů rozhoduje klíč (jméno,
     * IČO, účet…), ostatní řetězce projdou {@see scrub()}. Čísla, struktura a
     * rozlišení `{}` × `[]` zůstávají. Když se nic nezměnilo, vrátí se originální
     * text beze změny formátování.
     */
    public function scrubJson(string $json): string
    {
        $trimmed = ltrim($json);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[' && $trimmed[0] !== '"')) {
            return $this->scrub($json);
        }
        try {
            $decoded = json_decode($json, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->scrub($json);
        }
        $changed = false;
        $walked = $this->walk($decoded, null, $changed);
        if (!$changed) {
            return $json;
        }
        $encoded = json_encode($walked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded) ? $encoded : $this->scrub($json);
    }

    public function byStrategy(string $strategy, string $value): string
    {
        return match ($strategy) {
            'party_name' => $this->pseudonymizer->partyName($value),
            'person_name' => $this->pseudonymizer->personName($value),
            'first_name' => $this->pseudonymizer->firstName($value),
            'last_name' => $this->pseudonymizer->lastName($value),
            'street' => $this->pseudonymizer->street($value),
            'city' => $this->pseudonymizer->city($value),
            'zip' => $this->pseudonymizer->zip($value),
            'address' => $this->pseudonymizer->address($value),
            'ico' => $this->pseudonymizer->ico($value),
            'dic' => $this->pseudonymizer->dic($value),
            'birth_number' => $this->pseudonymizer->birthNumber($value),
            'email' => $this->pseudonymizer->email($value),
            'phone' => $this->pseudonymizer->phone($value),
            'bank_account' => $this->pseudonymizer->bankAccount($value),
            'account_key' => $this->pseudonymizer->accountKey($value),
            'iban' => $this->pseudonymizer->iban($value),
            'card_last4' => $this->pseudonymizer->cardLast4($value),
            'data_box' => $this->pseudonymizer->dataBox($value),
            'web' => $this->pseudonymizer->web($value),
            'host' => $this->pseudonymizer->host($value),
            'login' => $this->pseudonymizer->login($value),
            'cost_center' => $this->pseudonymizer->costCenter($value),
            'shape' => $this->pseudonymizer->shape($value),
            'shape_digits' => $this->pseudonymizer->shapeDigits($value),
            'symbol' => $this->pseudonymizer->symbol($value),
            'file_name' => $this->pseudonymizer->fileName($value),
            'file_path' => $this->pseudonymizer->filePath($value),
            'text' => $this->scrub($value),
            'json' => $this->scrubJson($value),
            default => throw new \InvalidArgumentException("Neznámá strategie pseudonymizace: {$strategy}"),
        };
    }

    private function walk(mixed $node, ?string $key, bool &$changed): mixed
    {
        if ($node instanceof \stdClass) {
            if (($node->type ?? null) === 'cost_center' && isset($node->code) && is_string($node->code)) {
                $code = $this->byStrategy('cost_center', $node->code);
                if ($code !== $node->code) {
                    $node->code = $code;
                    $changed = true;
                }
            }
            foreach (get_object_vars($node) as $k => $v) {
                $node->{$k} = $this->walk($v, (string) $k, $changed);
            }

            return $node;
        }
        if (is_array($node)) {
            foreach ($node as $i => $v) {
                $node[$i] = $this->walk($v, $key, $changed);
            }

            return $node;
        }
        $strategy = $key === null ? null : (self::JSON_KEYS[self::normalizeKey($key)] ?? null);
        if (is_int($node) && $strategy !== null && in_array($strategy, ['ico', 'zip', 'phone', 'bank_account', 'birth_number'], true)) {
            $new = $this->byStrategy($strategy, (string) $node);
            if ($new !== (string) $node) {
                $changed = true;

                return ctype_digit($new) ? (int) $new : $new;
            }

            return $node;
        }
        if (!is_string($node) || $node === '') {
            return $node;
        }
        $new = $strategy !== null ? $this->byStrategy($strategy, $node) : $this->scrub($node);
        if ($new !== $node) {
            $changed = true;
        }

        return $new;
    }

    private static function normalizeKey(string $key): string
    {
        return mb_strtolower(preg_replace('/[\s_\-]+/u', '', $key) ?? $key, 'UTF-8');
    }

    private function account(string $value): string
    {
        $parsed = Pseudonymizer::parseNationalAccount($value);
        $bank = substr($value, -4);
        $validAccount = $parsed !== null
            && in_array($bank, PseudonymPools::CZECH_BANK_CODES, true)
            && Pseudonymizer::isValidAccountPart($parsed['base'])
            && ($parsed['prefix'] === '' || Pseudonymizer::isValidAccountPart($parsed['prefix']));
        if (!$validAccount) {
            // Rodné číslo s lomítkem má týž tvar jako účet „základ/banka".
            if (preg_match('/^\d{6}\/\d{3,4}$/D', $value) === 1
                && Pseudonymizer::isValidBirthNumber($value)
                && !$this->pseudonymizer->isIssuedPseudonym('rc', str_replace('/', '', $value))) {
                return $this->pseudonymizer->birthNumber($value);
            }

            return $value;
        }
        if ($this->pseudonymizer->isIssuedPseudonym('account', $parsed['base'])) {
            return $value;
        }

        return $this->pseudonymizer->bankAccount($value);
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($this->pseudonymizer->isIssuedPseudonym('phone', substr($digits, -9))) {
            return $value;
        }

        return $this->pseudonymizer->phone($value);
    }
}
