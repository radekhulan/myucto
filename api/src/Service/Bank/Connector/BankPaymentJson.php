<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Jediné místo, kde se tělo platebního požadavku pro JSON API banky převádí na text.
 * Částky ({@see BankPaymentAmount}) jdou do JSON jako čísla zapsaná z haléřů, nezávisle
 * na `serialize_precision`. Float v těle se odmítne: platba z něj vzniknout nesmí.
 */
final class BankPaymentJson
{
    /** @param array<mixed> $data */
    public static function encode(array $data, int $flags = 0, int $depth = 512): string
    {
        $nonce = bin2hex(random_bytes(8));
        $amounts = [];
        $prepared = self::prepare($data, $nonce, $amounts);
        $json = json_encode($prepared, $flags | JSON_THROW_ON_ERROR, $depth);
        if ($amounts === []) {
            return $json;
        }
        $zeroFraction = ($flags & JSON_PRESERVE_ZERO_FRACTION) !== 0;
        $replacements = [];
        foreach ($amounts as $token => $amount) {
            $replacements['"' . $token . '"'] = $amount->decimal($zeroFraction);
        }
        $result = strtr($json, $replacements);
        if (str_contains($result, $nonce)) {
            throw new \LogicException('Částku platby nelze bezpečně zapsat do JSON.');
        }
        return $result;
    }

    /**
     * @param array<string,BankPaymentAmount> $amounts
     */
    private static function prepare(mixed $value, string $nonce, array &$amounts): mixed
    {
        if ($value instanceof BankPaymentAmount) {
            $token = 'mu-amount-' . $nonce . '-' . count($amounts);
            $amounts[$token] = $value;
            return $token;
        }
        if (is_float($value)) {
            throw new \LogicException('Tělo platebního požadavku nesmí obsahovat float; částka patří do BankPaymentAmount.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::prepare($item, $nonce, $amounts);
            }
        }
        return $value;
    }
}
