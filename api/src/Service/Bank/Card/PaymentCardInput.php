<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Repository\PaymentCardRepository;

/**
 * Validace a sanitizace vstupu platební karty z formuláře / API.
 *
 * Koncovka se bere přes {@see CardNumberMask::normalizeLast4()}: vloží-li někdo
 * celé číslo karty, uloží se jen poslední čtyři číslice a odpověď to oznámí
 * příznakem `last4_truncated`. Volná textová pole (název, držitel, poznámka) celé
 * číslo karty odmítnou — ani omylem nesmí skončit v databázi.
 *
 * Vazby na cizí tabulky (měnový účet, zaměstnanec, uživatel) tady jen typově
 * kontroluje; příslušnost k firmě ověřuje akce.
 */
final class PaymentCardInput
{
    /**
     * @param array<string,mixed> $body
     * @return array{data: array<string,mixed>, errors: array<string,string>, last4_truncated: bool}
     */
    public static function normalize(array $body): array
    {
        $errors = [];
        $data = [];

        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            $errors['label'] = 'Název karty je povinný.';
        } elseif (mb_strlen($label) > 120) {
            $errors['label'] = 'Název karty smí mít nejvýše 120 znaků.';
        }
        $data['label'] = $label;

        if (CardNumberMask::containsFullPan($label)) {
            $errors['label'] = 'Pole nesmí obsahovat číslo karty. Evidujeme jen poslední čtyři číslice.';
        }
        $data['holder_name'] = self::optionalText($body, 'holder_name', 191, 'Jméno držitele', $errors);
        $data['note'] = self::optionalText($body, 'note', 500, 'Poznámka', $errors);

        $rawLast4 = $body['last4'] ?? null;
        $last4 = CardNumberMask::normalizeLast4($rawLast4);
        $truncated = false;
        if ($last4 === null) {
            $errors['last4'] = 'Zadejte poslední čtyři číslice karty.';
        } else {
            $digits = (string) preg_replace('/[^0-9]/', '', (string) $rawLast4);
            $truncated = strlen($digits) > 4;
        }
        $data['last4'] = $last4;

        $type = (string) ($body['card_type'] ?? 'debit');
        if (!in_array($type, PaymentCardRepository::CARD_TYPES, true)) {
            $errors['card_type'] = 'Neplatný typ karty.';
        }
        $data['card_type'] = $type;

        $network = $body['card_network'] ?? null;
        if ($network === '' || $network === null) {
            $network = null;
        } elseif (!in_array($network, PaymentCardRepository::CARD_NETWORKS, true)) {
            $errors['card_network'] = 'Neplatná karetní asociace.';
            $network = null;
        }
        $data['card_network'] = $network;

        foreach (['currency_id', 'employee_id', 'user_id'] as $fk) {
            $data[$fk] = self::optionalId($body, $fk, $errors);
        }

        $data['valid_from'] = self::optionalDate($body, 'valid_from', $errors);
        $data['valid_to'] = self::optionalDate($body, 'valid_to', $errors);
        if ($data['valid_from'] !== null && $data['valid_to'] !== null && $data['valid_to'] < $data['valid_from']) {
            $errors['valid_to'] = 'Platnost do nesmí být dřív než platnost od.';
        }

        $data['is_active'] = !array_key_exists('is_active', $body) || (bool) $body['is_active'];

        return ['data' => $data, 'errors' => $errors, 'last4_truncated' => $truncated];
    }

    /** @param array<string,string> $errors */
    private static function optionalText(array $body, string $key, int $max, string $label, array &$errors): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$key] = $label . ' smí mít nejvýše ' . $max . ' znaků.';
        }
        if (CardNumberMask::containsFullPan($value)) {
            $errors[$key] = 'Pole nesmí obsahovat číslo karty. Evidujeme jen poslední čtyři číslice.';
        }
        return $value;
    }

    /** @param array<string,string> $errors */
    private static function optionalId(array $body, string $key, array &$errors): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (!(is_int($value) || is_string($value)) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value) !== 1) {
            $errors[$key] = 'Neplatná hodnota.';
            return null;
        }
        return (int) $value;
    }

    /** @param array<string,string> $errors */
    private static function optionalDate(array $body, string $key, array &$errors): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[$key] = 'Datum musí být ve tvaru RRRR-MM-DD.';
            return null;
        }
        return $value;
    }
}
