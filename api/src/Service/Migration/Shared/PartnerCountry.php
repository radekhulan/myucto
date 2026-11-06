<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Report\KontrolniHlaseniBuilder;

/**
 * Země partnera převáděného z cizího programu podle jeho DIČ. Jediné pravidlo pro
 * všechny převody (POHODA, PREMIER, Money S3).
 *
 * Zemi určí jen předpona členského státu u tvarově platného DIČ, tedy stát, který DIČ
 * přidělil ({@see KontrolniHlaseniBuilder::euVatIdPrefix()}, stejná tabulka jako KH
 * a souhrnné hlášení). Identifikační číslo zvláštního režimu OSS pro osoby mimo EU
 * („EU372041333"), DIČ třetích zemí ani volný text zemi neurčují - rozhodne stát
 * z adresy. Jinak by americký dodavatel s OSS číslem spadl na výchozí zemi firmy
 * a jeho plnění se v KH vykázalo s `k_stat=CZ`.
 */
final class PartnerCountry
{
    /** ISO 3166-1 alpha-2 státu, který DIČ přidělil (Řecko GR), jinak null. */
    public static function fromVatId(?string $vatId): ?string
    {
        $prefix = KontrolniHlaseniBuilder::euVatIdPrefix($vatId);
        if ($prefix === '') {
            return null;
        }
        return $prefix === 'EL' ? 'GR' : $prefix;
    }
}
