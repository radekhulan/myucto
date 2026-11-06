<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Certificate;

/**
 * Připnuté certifikační autority EET podle prostředí.
 *
 * Důvěra se NIKDY nebere z uživatelova `.p12` (řetězec v souboru slouží jen
 * jako pomůcka): pokladní certifikát musí vést k autoritě z `api/resources/eet/ca`
 * (zdroj a otisky v README tamtéž). Prostředí certifikátu se tak určí podle
 * autority, která ho vydala — Playground CA není produkční CA a naopak.
 */
final class EetTrustStore
{
    /** @var array<string, list<string>> prostředí → PEM certifikáty autorit */
    private array $anchors;

    /** @param array<string, list<string>>|null $anchors pro testy; null = přibalené autority */
    public function __construct(?array $anchors = null)
    {
        $this->anchors = $anchors ?? self::bundled();
    }

    /** @return array<string, list<string>> */
    public function anchors(): array
    {
        return $this->anchors;
    }

    /** @return array<string, list<string>> */
    private static function bundled(): array
    {
        $dir = dirname(__DIR__, 4) . '/resources/eet/ca';
        $anchors = [];
        foreach (['playground', 'production'] as $environment) {
            $files = glob($dir . '/' . $environment . '-*.pem') ?: [];
            sort($files);
            foreach ($files as $file) {
                $pem = file_get_contents($file);
                if (is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')) {
                    $anchors[$environment][] = $pem;
                }
            }
        }
        return $anchors;
    }
}
