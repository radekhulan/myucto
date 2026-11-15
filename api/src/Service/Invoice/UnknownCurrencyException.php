<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Kód měny z těla dokladu (`currency`) firma v číselníku měn nemá.
 *
 * Dřív se doklad tiše založil ve výchozí měně odběratele nebo firmy, takže překlep
 * („EURO") nebo chybějící měna vyrobily korunovou fakturu místo eurové. Akce ji vrací
 * jako 400 `unknown_currency`.
 */
final class UnknownCurrencyException extends \InvalidArgumentException
{
    public const ERROR_CODE = 'unknown_currency';

    public static function forCode(string $code): self
    {
        return new self('Měna „' . $code . '" ve firmě není. Založte ji v Nastavení, Měny, nebo zadejte jiný kód měny (seznam vrátí číselník měn).');
    }
}
