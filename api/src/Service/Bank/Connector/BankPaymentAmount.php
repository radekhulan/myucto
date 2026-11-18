<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Částka platby pro JSON API banky, držená v haléřích. Do JSON ji zapisuje jen
 * {@see BankPaymentJson::encode()} jako číslo s nejvýš dvěma desetinnými místy,
 * odvozené celočíselně z haléřů. Float `haléře / 100` by json_encode zapsal podle
 * `serialize_precision`: při hodnotě 17 (některé hostingy) z 1234.56 vznikne
 * 1234.5599999999999 a banka platbu odmítne (Česká spořitelna WRONG_DECIMAL_PLACES).
 */
final class BankPaymentAmount implements \JsonSerializable
{
    private function __construct(public readonly int $minor) {}

    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * Desetinný zápis bez exponentu a bez nadbytečných nul: 1000, 2.5, 2.35. S
     * `$zeroFraction` zůstane u celých částek jedna nula za tečkou (1000.0), stejně
     * jako json_encode s JSON_PRESERVE_ZERO_FRACTION.
     */
    public function decimal(bool $zeroFraction = false): string
    {
        $absolute = abs($this->minor);
        $whole = intdiv($absolute, 100);
        $fraction = rtrim(sprintf('%02d', $absolute % 100), '0');
        if ($fraction === '' && $zeroFraction) {
            $fraction = '0';
        }
        return ($this->minor < 0 ? '-' : '') . $whole . ($fraction !== '' ? '.' . $fraction : '');
    }

    /** Jinou cestou než přes {@see BankPaymentJson::encode()} se částka do JSON dostat nesmí. */
    public function jsonSerialize(): never
    {
        throw new \LogicException('Částku platby kóduje jen BankPaymentJson::encode().');
    }
}
