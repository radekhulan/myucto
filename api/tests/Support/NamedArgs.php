<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Převede poziční argumenty zachycené v mock callbacku na mapu podle JMEN parametrů.
 *
 * ⚠️ Proč to existuje: testy licenčního klienta si braly argument indexem
 * (`$args[8]`). Jakmile do `LicenseClient::renew()` přibyl parametr uprostřed
 * (počty mzdových zaměstnanců a uživatelů), posunuly se všechny indexy za ním
 * a testy začaly porovnávat úplně jiné hodnoty — místo domény verzi aplikace.
 * Selhání navíc nevypadalo jako změna kontraktu, ale jako rozbitá doména.
 *
 * Pojmenovaný přístup je proti vkládání parametrů imunní: dokud se parametr
 * jmenuje stejně, test čte pořád totéž. Když ho někdo přejmenuje nebo odstraní,
 * spadne to na chybějícím klíči — tedy tam, kde to opravdu je.
 */
final class NamedArgs
{
    /**
     * @param list<mixed> $args poziční argumenty z `willReturnCallback(fn(...$args) => …)`
     * @return array<string,mixed> jméno parametru => hodnota (nepředané se vynechají)
     */
    public static function of(string $class, string $method, array $args): array
    {
        $params = (new \ReflectionMethod($class, $method))->getParameters();

        $out = [];
        foreach ($params as $i => $param) {
            if (array_key_exists($i, $args)) {
                $out[$param->getName()] = $args[$i];
            }
        }

        return $out;
    }
}
