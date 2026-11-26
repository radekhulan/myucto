<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Podoba OSS dokladu v syntetickém exportu POHODY ({@see SyntheticPohodaExport::write()}).
 * Výchozí stav odpovídá dokladu, jak ho vede POHODA v režimu OSS: stát spotřeby v hlavičce
 * (`MOSS`) a slovenská adresa odběratele.
 */
final readonly class OssVariant
{
    /**
     * @param ?string $moss           stát spotřeby v hlavičce (`inv:MOSS`), `null` = element chybí
     *                                (doklad v POHODĚ není v režimu OSS)
     * @param string  $supplyType     typ plnění položky (`inv:typeServiceMOSS`), `''` = element chybí
     * @param ?string $partnerCountry země v adrese odběratele, `null` = adresa zemi neuvádí
     * @param string  $date           datum vystavení, plnění i zaúčtování (splatnost o 14 dní později);
     *                                datum před rokem agendy dělá z dokladu doklad minulého období
     */
    public function __construct(
        public ?string $moss = SyntheticPohodaExport::OSS_COUNTRY,
        public string $supplyType = '',
        public ?string $partnerCountry = SyntheticPohodaExport::OSS_COUNTRY,
        public string $date = '2026-02-10',
    ) {}
}
