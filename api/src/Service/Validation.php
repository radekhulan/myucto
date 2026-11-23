<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Service\Client\ClientFieldLimits;
use MyInvoice\Service\Mail\ClientEmailFormat;
use MyInvoice\Service\Mail\ClientIsdocPreference;

/**
 * Lehké validační helpery — vrací array chyb (prázdné = OK).
 */
final class Validation
{
    /**
     * @return array<string, string[]>
     */
    public static function client(array $data): array
    {
        $err = [];

        if (empty($data['company_name']) || !is_string($data['company_name']) || trim($data['company_name']) === '') {
            $err['company_name'][] = 'Firma / jméno je povinné';
        }
        // Drobný zákazník (#310): karta bez adresy smí odebírat jen zjednodušené daňové
        // doklady (§ 30 ZDPH), hlídá to InvoiceIssueTaxGate::simplifiedOnlyClientRejection().
        // Místo adresy musí mít kontakt, kam doklad poslat.
        $simplifiedOnly = !empty($data['simplified_only']) && filter_var($data['simplified_only'], FILTER_VALIDATE_BOOLEAN);
        if (!$simplifiedOnly) {
            if (empty($data['street']) || trim((string) $data['street']) === '') {
                $err['street'][] = 'Ulice je povinná';
            }
            if (empty($data['city']) || trim((string) $data['city']) === '') {
                $err['city'][] = 'Město je povinné';
            }
            if (empty($data['zip']) || trim((string) $data['zip']) === '') {
                $err['zip'][] = 'PSČ je povinné';
            }
        } else {
            if (trim((string) ($data['main_email'] ?? '')) === '' && trim((string) ($data['phone'] ?? '')) === '') {
                $err['main_email'][] = 'Drobný zákazník musí mít e-mail nebo telefon';
            }
            if (!empty($data['reverse_charge']) && filter_var($data['reverse_charge'], FILTER_VALIDATE_BOOLEAN)) {
                $err['reverse_charge'][] = 'Drobnému zákazníkovi nelze fakturovat v režimu přenesené daňové povinnosti (§ 30 odst. 2 ZDPH)';
            }
        }
        // Délky sloupců clients: ručně zadaná delší hodnota je chyba vstupu, ne pád DB.
        foreach (ClientFieldLimits::errors($data) as $field => $messages) {
            $err[$field] = array_merge($err[$field] ?? [], $messages);
        }
        // Hlavní e-mail je nepovinný (#221) — historické doklady ho často nemají.
        // Když ale vyplněný je, musí být platný.
        $mainEmail = trim((string) ($data['main_email'] ?? ''));
        if ($mainEmail !== '' && !filter_var($mainEmail, FILTER_VALIDATE_EMAIL)) {
            $err['main_email'][] = 'Hlavní email musí být platný';
        }
        if (!empty($data['ic']) && !preg_match('/^\d{8}$/', (string) $data['ic'])) {
            $err['ic'][] = 'IČO musí mít 8 číslic';
        }
        $lang = $data['language'] ?? 'cs';
        if (!in_array($lang, ['cs', 'en'], true)) {
            $err['language'][] = 'Jazyk musí být cs nebo en';
        }
        $curId = $data['currency_default_id'] ?? null;
        if ($curId !== null && (!is_numeric($curId) || (int) $curId <= 0)) {
            $err['currency_default_id'][] = 'Neplatné currency_default_id';
        }
        if (isset($data['hourly_rate']) && (float) $data['hourly_rate'] < 0) {
            $err['hourly_rate'][] = 'Hodinová sazba nesmí být záporná';
        }
        if (!ClientIsdocPreference::isValid($data['email_attach_isdoc'] ?? null)) {
            $err['email_attach_isdoc'][] = ClientIsdocPreference::INVALID_MESSAGE;
        }
        foreach (ClientEmailFormat::errors($data['email_subject_format'] ?? null, ClientEmailFormat::SUBJECT_MAX_LENGTH, false) as $message) {
            $err['email_subject_format'][] = $message;
        }
        foreach (ClientEmailFormat::errors($data['email_attachment_name_format'] ?? null, ClientEmailFormat::ATTACHMENT_NAME_MAX_LENGTH, true) as $message) {
            $err['email_attachment_name_format'][] = $message;
        }
        return $err;
    }

    /**
     * Non-blocking varování pro klienta/dodavatele (audit 2026-07, nález "IČO bez
     * kontroly mod 11, DIČ se nevaliduje vůbec"). Na rozdíl od client() NIKDY
     * neblokuje uložení — cizí subjekty (zahraniční VAT ID, subjekty bez IČO)
     * musí projít beze zbytku. Volající vrátí kódy ve _warnings, FE je zobrazí
     * jako non-blocking toast/banner (mapováno přes client.warning.<code>).
     *
     * @return string[]
     */
    public static function clientWarnings(array $data): array
    {
        $warn = [];

        // IČO má formát ověřený už v client() (přesně 8 číslic); mod 11 kontrolujeme
        // jen když regex prošel — jinak by šlo o duplicitní hlášku ke stejné chybě.
        $ic = isset($data['ic']) ? trim((string) $data['ic']) : '';
        if ($ic !== '' && preg_match('/^\d{8}$/', $ic) === 1 && !self::icChecksumValid($ic)) {
            $warn[] = 'ic_checksum_invalid';
        }

        // DIČ: tuzemské CZ + 8-10 číslic (fyzické osoby mívají DIČ odvozené z rodného
        // čísla, proto 8-10, ne jen 8 jako IČO). Zahraniční EU VAT ID je alfanumerické
        // (IE 1234567X, AT U12345678, NL 123456789B01…) — kontrolujeme jen hrubý tvar
        // (dvoupísmenný kód země + alfanumerické tělo), přesný formát ověří VIES lookup.
        $dic = isset($data['dic']) ? strtoupper(trim((string) $data['dic'])) : '';
        $dicDomesticDigits = null;
        if ($dic !== '') {
            if (str_starts_with($dic, 'CZ')) {
                // Tuzemský prefix zavazuje k tuzemskému tvaru — nesmí propadnout do
                // volnějšího zahraničního regexu (ten by "CZ123" mylně pustil jako
                // platné EU VAT ID).
                if (preg_match('/^CZ(\d{8,10})$/', $dic, $m) === 1) {
                    $dicDomesticDigits = $m[1];
                } else {
                    $warn[] = 'dic_format_invalid';
                }
            } elseif (preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $dic) !== 1) {
                $warn[] = 'dic_format_invalid';
            }
        }

        // Vazba DIČ↔IČO: jen když DIČ nese přesně 8 číslic (typický případ právnické
        // osoby, kde CZ-DIČ = CZ + IČO) a IČO má platný formát — u DIČ fyzických osob
        // (9-10 číslic, odvozeno z rodného čísla) shoda s IČO neplatí, tam nekontrolujeme.
        if ($dicDomesticDigits !== null && strlen($dicDomesticDigits) === 8
            && $ic !== '' && preg_match('/^\d{8}$/', $ic) === 1 && $dicDomesticDigits !== $ic) {
            $warn[] = 'dic_ic_mismatch';
        }

        return $warn;
    }

    /**
     * Modulo 11 kontrolní číslice IČO (algoritmus ČSÚ/ARES): vážený součet prvních
     * 7 číslic s váhami 8..2, zbytek po 11 (a); kontrolní číslice: a=0 → 1, a=1 → 0,
     * jinak 11-a. Volá se až po ověření, že $ic je přesně 8 číslic.
     */
    public static function icChecksumValid(string $ic): bool
    {
        $digits = array_map('intval', str_split($ic));
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += $digits[$i] * (8 - $i);
        }
        $a = $sum % 11;
        $check = match (true) {
            $a === 0 => 1,
            $a === 1 => 0,
            default => 11 - $a,
        };
        return $check === $digits[7];
    }

    /**
     * @return array<string, string[]>
     */
    public static function project(array $data): array
    {
        $err = [];

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }
        if (empty($data['name']) || trim((string) $data['name']) === '') {
            $err['name'][] = 'Název zakázky je povinný';
        }
        $due = (int) ($data['payment_due_days'] ?? 0);
        if ($due < 1 || $due > 365) {
            $err['payment_due_days'][] = 'Splatnost musí být 1–365 dní';
        }
        if (isset($data['hourly_rate']) && (float) $data['hourly_rate'] < 0) {
            $err['hourly_rate'][] = 'Hodinová sazba nesmí být záporná';
        }
        // Akceptujeme buď currency_id (preferováno) nebo legacy currency code (resolveCurrencyId si poradí)
        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'paused', 'closed'], true)) {
            $err['status'][] = 'Status musí být active, paused nebo closed';
        }

        // Billing emails (0..3)
        $emails = $data['billing_emails'] ?? [];
        if (!is_array($emails)) {
            $err['billing_emails'][] = 'billing_emails musí být pole';
        } elseif (count($emails) > 3) {
            $err['billing_emails'][] = 'Maximálně 3 fakturační emaily';
        } else {
            foreach ($emails as $i => $entry) {
                if (!is_array($entry)) continue;
                $em = (string) ($entry['email'] ?? '');
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $err["billing_emails.{$i}"][] = 'Neplatný email';
                }
            }
        }

        return $err;
    }
}
