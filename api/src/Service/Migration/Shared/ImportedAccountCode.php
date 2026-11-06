<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/** Cílový zápis číselné analytiky; původní klíč a otisk zdroje zůstávají beze změny. */
final class ImportedAccountCode
{
    public static function normalize(string $sourceCode): string
    {
        $code = trim($sourceCode);
        return preg_match('/^([0-9]{3})([0-9]+)$/D', $code, $parts) === 1
            ? $parts[1] . '.' . $parts[2]
            : $code;
    }

    /**
     * Dříve převzaté účty mohou mít původní zápis. Jejich doložená importní vazba má
     * přednost před normalizací; samotný opakovaný převod historický deník nepřejmenuje.
     *
     * @param array<string,string> $sourceToTarget
     */
    public static function target(string $sourceCode, array $sourceToTarget): string
    {
        return $sourceToTarget[$sourceCode] ?? self::normalize($sourceCode);
    }
}
