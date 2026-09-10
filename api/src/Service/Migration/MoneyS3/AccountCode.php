<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Money vede účty šestimístně (`042000`, `221001`). MyÚčto drží syntetiku třímístně
 * a analytiku jako potomka s tečkou, takže `042000` → `042.000` pod syntetikou `042`.
 * Nedosazený účet v předkontaci (`xxxxxx`) a prázdno = žádný účet.
 */
final class AccountCode
{
    public static function fromMoney(string $moneyCode): ?string
    {
        $code = trim($moneyCode);
        if ($code === '' || !ctype_digit($code) || strlen($code) < 3) {
            return null;
        }
        $analytic = substr($code, 3);
        return substr($code, 0, 3) . '.' . ($analytic === '' ? '000' : $analytic);
    }

    public static function synthetic(string $moneyCode): ?string
    {
        $code = trim($moneyCode);
        return ctype_digit($code) && strlen($code) >= 3 ? substr($code, 0, 3) : null;
    }
}
