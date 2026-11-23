<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Druh zařízení relace pro limit souběžných přihlášení: prohlížeč počítače,
 * nebo mobilní zařízení (telefon, tablet, nativní mobilní aplikace).
 *
 * Rozhoduje User-Agent při přihlášení. Ten si klient může podvrhnout, ale
 * získá tím nejvýš druhou povolenou relaci (prohlížeč + mobil), víc ne.
 *
 * ⚠️ Stejný výraz používá migrace 2245 pro doplnění stávajících relací.
 */
final class SessionDeviceClass
{
    public const BROWSER = 'browser';
    public const MOBILE = 'mobile';

    private const MOBILE_PATTERN = '/Mobi|Android|iPhone|iPad|iPod|Windows Phone|IEMobile|Opera Mini|BlackBerry|webOS|CFNetwork|Dalvik|okhttp/i';

    public static function fromUserAgent(string $userAgent): string
    {
        return preg_match(self::MOBILE_PATTERN, $userAgent) === 1 ? self::MOBILE : self::BROWSER;
    }
}
