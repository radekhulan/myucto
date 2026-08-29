<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Bootstrap;

/**
 * XSD validation pro EPO XML výkazy MFČR.
 *
 * Schémata jsou součástí `api/xsd/`, takže validace funguje offline. Pokud pro
 * některý form_code schéma chybí, vrátí se `status=skipped`; XML se i tak
 * archivuje a stáhne.
 */
final class XmlSchemaValidator
{
    /**
     * @return array{status: 'passed'|'failed'|'skipped', errors: list<string>}
     */
    public function validate(string $xml, string $formCode): array
    {
        $schemaPath = $this->resolveSchemaPath($formCode);
        if ($schemaPath === null || !is_file($schemaPath)) {
            return ['status' => 'skipped', 'errors' => []];
        }

        $errors = [];

        // PHP libxml errors collector
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        if (!$loaded) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ')';
            }
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return ['status' => 'failed', 'errors' => $errors];
        }

        $valid = @$dom->schemaValidate($schemaPath);
        if (!$valid) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ', column ' . $err->column . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return [
            'status' => $valid ? 'passed' : 'failed',
            'errors' => array_slice($errors, 0, 50), // cap pro DB JSON column size
        ];
    }

    /**
     * Zda je schema dostupné pro daný form_code (pro UI hint).
     */
    public function hasSchema(string $formCode): bool
    {
        $path = $this->resolveSchemaPath($formCode);
        return $path !== null && is_file($path);
    }

    /**
     * Whitelist form_code → XSD filename. Zároveň brání path injection (klíče
     * jsou fixní). EPO výkazy MFČR + ISDOC (formát faktur) — soubory commitnuté
     * v `api/xsd/` (public, ~400 KB celkem). Dřív byly v `storage/xsd/`
     * (gitignored), což si vynucovalo `cmd/download-xsd.sh` setup krok.
     */
    private const SCHEMA_FILES = [
        'dphdp3' => 'dphdp3.xsd',
        'dphkh1' => 'dphkh1.xsd',
        'dphshv' => 'dphshv.xsd',
        'ossei1' => 'ossei1.xsd',
        'dpfdp5' => 'dpfdp5.xsd',
        // Epic DP (issue #18): reálné EPO2 formuláře daně z příjmů. Nové buildery
        // (Service/Tax/Return/*XmlBuilder) mapují skutečné věty formulářů a validují
        // proti těmto schématům. `dppdp9` je remapován ze staršího dppdp9.xsd na
        // EPO2 verzi (starý MVP IncomeTaxBuilder generuje jen kostru — jeho XML
        // se archivuje se statusem validation, download i tak funguje).
        'dppdp9' => 'dppdp9_epo2.xsd',
        'dpfdp7' => 'dpfdp7_epo2.xsd',
        // Vyúčtování daně ze závislé činnosti (§ 38j odst. 4 ZDP) a vyúčtování
        // daně vybírané srážkou. Pozor: srážková daň NENÍ „DPZ", ale vlastní
        // písemnost DPSVD2 s vlastním schématem — přílohy 25 5466/A jsou uvnitř
        // jako věty, ne druhé podání.
        'dpzvd6' => 'dpzvd6.xsd',
        'dpsvd2' => 'dpsvd2.xsd',
        // Žádosti o poukázání chybějící částky na daňovém bonusu
        // (§ 35d odst. 5 a 9 ZDP). Bez záznamu tady by validace skončila
        // `skipped` a `validatedSubmission()` by podání zablokoval.
        'dpzmb1' => 'dpzmb1.xsd',
        'dpzdb1' => 'dpzdb1.xsd',
        // Písemnosti k příjmům daňových nerezidentů: oznámení o příjmech
        // plynoucích do zahraničí (§ 38da ZDP) a hlášení o srážce zajištění
        // daně (§ 38e ZDP). Obě jsou událostní, ne za zdaňovací období.
        'dpshl1' => 'dpshl1.xsd',
        'dpszd1' => 'dpszd1.xsd',
        // Epic DP v2 (issue #19): ČSSZ přehled OSVČ (sociální pojištění, roční e-podání).
        // Vlastní schéma ČSSZ (ns http://schemas.cssz.cz/OSVC2025), importuje baseTypes2.xsd
        // (oba v api/xsd/). Jiný kanál i formát než EPO MFČR.
        'osvc25' => 'osvc25.xsd',
        'isdoc'  => 'isdoc-invoice-6.0.2.xsd',
        // SEPA Credit Transfer (platební příkazy pro EUR dávky) — ISO 20022, ne EPO/MFČR.
        'pain001' => 'pain.001.001.03.xsd',
    ];

    private function resolveSchemaPath(string $formCode): ?string
    {
        $file = self::SCHEMA_FILES[$formCode] ?? null;
        if ($file === null) {
            return null;
        }
        return Bootstrap::rootDir() . '/api/xsd/' . $file;
    }
}
