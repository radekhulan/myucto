<?php

declare(strict_types=1);

namespace MyInvoice\Service\Qr;

/** Striktní převod databázového DATE; neplatná hodnota se nikdy nenahrazuje dneškem. */
final class PaymentQrDueDate
{
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $raw = trim($value);
        if ($raw === '') return null;

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $raw
        ) {
            return null;
        }
        return $date;
    }
}
