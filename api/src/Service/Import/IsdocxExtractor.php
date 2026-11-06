<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Xml\SafeXmlLoader;
use MyInvoice\Service\Xml\UnsafeXmlException;

/**
 * Rozbalí ISDOCX balíček (ISDOC Package) — ZIP archiv, do kterého řada českých
 * účetních systémů balí strukturovaný ISDOC i čitelné PDF faktury najednou.
 *
 * Layout (dle node-isdoc-pdf / národního standardu ISDOC 6.x):
 *
 *     <name>.isdocx (ZIP, DEFLATE)
 *     ├── manifest.xml     <manifest xmlns="…/2013/manifest"><maindocument filename="<name>.isdoc"/></manifest>
 *     ├── <name>.isdoc     strukturované ISDOC XML (http://isdoc.cz/namespace/2013) — to chceme
 *     └── <name>.pdf       čitelná faktura (pro archivaci/náhled)
 *
 * Hlavní ISDOC se určuje v pořadí:
 *   1) manifest.xml → `<maindocument filename="…">`,
 *   2) fallback (backward-compat dle spec, manifest mohl chybět ve verzích ≤5.x):
 *      první `.isdoc` v rootu archivu, jinak první `.isdoc` kdekoliv.
 *
 * Použití:
 *   - {@see AiPdfExtractor} — samostatně nahraný `.isdocx` (deterministický import, 0 AI cost),
 *   - {@see PdfIsdocExtractor} — `.isdocx` jako příloha uvnitř PDF/A-3 (issue #136),
 *   - {@see InvoiceImportService} a {@see PurchaseInvoiceInboxScanner} — dávkový/inbox import.
 *
 * Pure helper bez závislostí (jako BankAccountParser) — lze instancovat inline.
 * ZipArchive čte jen ze souboru, takže obsah zapisujeme do dočasného souboru
 * (stejný vzor jako InvoiceImportService::unzip()).
 */
final class IsdocxExtractor
{
    private const ZIP_MAGIC = "PK\x03\x04";
    /**
     * Zip-bomb guard — žádný vnitřní člen nečteme nad tento limit. Hlídá jen
     * bajty; cenu parsování XML (DOM bývá mnohonásobně větší) hlídá
     * {@see SafeXmlLoader} počtem uzlů.
     */
    private const MAX_MEMBER_BYTES = 20 * 1024 * 1024; // 20 MiB
    /** Skutečný manifest má pár set bajtů a jeden element `maindocument`. */
    private const MAX_MANIFEST_BYTES = 64 * 1024;
    private const MAX_MANIFEST_NODES = 1_000;

    /** Rychlý test ZIP magic bytes (bez otevírání archivu). */
    public static function isZip(string $bytes): bool
    {
        return str_starts_with($bytes, self::ZIP_MAGIC);
    }

    /**
     * Rozbalí ISDOCX a vrátí vnitřní ISDOC XML + (volitelně) čitelné PDF.
     * Vrátí null, pokud to není ZIP, nebo uvnitř není použitelný ISDOC.
     *
     * @return array{isdoc:string, isdoc_name:string, pdf:?string, pdf_name:?string}|null
     */
    public function unwrap(string $bytes): ?array
    {
        // Magic check PŘED zápisem temp souboru — běžné PDF/obrázky tak nezdrží.
        if (!self::isZip($bytes) || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'isdocx-');
        if ($tmp === false) {
            return null;
        }
        try {
            if (@file_put_contents($tmp, $bytes) === false) {
                return null;
            }
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            try {
                $isdocName = $this->resolveMainIsdocName($zip);
                if ($isdocName === null) {
                    return null;
                }
                $isdocXml = $this->readMember($zip, $isdocName);
                if ($isdocXml === null || !str_contains($isdocXml, 'isdoc.cz/namespace')) {
                    return null;
                }
                [$pdfName, $pdfBytes] = $this->firstPdfMember($zip);
                return [
                    'isdoc'      => $isdocXml,
                    'isdoc_name' => basename($isdocName),
                    'pdf'        => $pdfBytes,
                    'pdf_name'   => $pdfName !== null ? basename($pdfName) : null,
                ];
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Název členu archivu, který je hlavní ISDOC dokument.
     * Priorita: manifest.xml `<maindocument filename>` → root `.isdoc` → jakýkoliv `.isdoc`.
     */
    private function resolveMainIsdocName(\ZipArchive $zip): ?string
    {
        $manifestName = $this->findMemberCi($zip, 'manifest.xml');
        if ($manifestName !== null) {
            $manifest = $this->readMember($zip, $manifestName, self::MAX_MANIFEST_BYTES);
            if ($manifest !== null) {
                $declared = $this->mainDocumentFromManifest($manifest);
                if ($declared !== null) {
                    $resolved = $this->findMemberCi($zip, $declared);
                    if ($resolved !== null && str_ends_with(strtolower($resolved), '.isdoc')) {
                        return $resolved;
                    }
                }
            }
        }

        // Fallback dle spec: `.isdoc` v rootu archivu (preferovaně), jinak kdekoliv.
        $rootIsdoc = null;
        $anyIsdoc = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with(strtolower($name), '.isdoc')) {
                continue;
            }
            $anyIsdoc ??= $name;
            if (!str_contains($name, '/')) {
                $rootIsdoc = $name;
                break;
            }
        }
        return $rootIsdoc ?? $anyIsdoc;
    }

    /**
     * Vytáhne `filename` z `<maindocument>` v manifestu. Namespace-agnostic
     * (čteme přes local-name), aby to přežilo drobné odchylky napříč verzemi.
     */
    private function mainDocumentFromManifest(string $xml): ?string
    {
        try {
            $dom = SafeXmlLoader::load($xml, self::MAX_MANIFEST_BYTES, 0, self::MAX_MANIFEST_NODES);
        } catch (UnsafeXmlException) {
            // Nepoužitelný manifest = fallback na `.isdoc` podle názvu, jako dřív.
            return null;
        }
        $nodes = (new \DOMXPath($dom))->query('//*[local-name()="maindocument"]');
        foreach ($nodes ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $fn = trim($node->getAttribute('filename'));
            if ($fn !== '') {
                return $fn;
            }
        }
        return null;
    }

    /** První `.pdf` člen (root preferovaně) + jeho obsah; null pokud žádné použitelné PDF. */
    private function firstPdfMember(\ZipArchive $zip): array
    {
        $rootPdf = null;
        $anyPdf = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with(strtolower($name), '.pdf')) {
                continue;
            }
            $anyPdf ??= $name;
            if (!str_contains($name, '/')) {
                $rootPdf = $name;
                break;
            }
        }
        $name = $rootPdf ?? $anyPdf;
        if ($name === null) {
            return [null, null];
        }
        $bytes = $this->readMember($zip, $name);
        if ($bytes === null || !str_starts_with($bytes, '%PDF')) {
            return [null, null]; // ne-PDF obsah nearchivujeme jako PDF
        }
        return [$name, $bytes];
    }

    /**
     * Najde člen archivu case-insensitive (a tolerantně k adresářovému prefixu
     * v deklaraci manifestu) — vrátí skutečný název členu, nebo null.
     */
    private function findMemberCi(\ZipArchive $zip, string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if ($zip->locateName($name) !== false) {
            return $name;
        }
        $needle = strtolower(basename($name));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $candidate = (string) $zip->getNameIndex($i);
            if (strtolower(basename($candidate)) === $needle) {
                return $candidate;
            }
        }
        return null;
    }

    /** Přečte člen archivu se zip-bomb guardem; null při chybě/přetečení limitu. */
    private function readMember(\ZipArchive $zip, string $name, int $maxBytes = self::MAX_MEMBER_BYTES): ?string
    {
        $idx = $zip->locateName($name);
        if ($idx === false) {
            return null;
        }
        $stat = $zip->statIndex($idx);
        if (is_array($stat) && (int) ($stat['size'] ?? 0) > $maxBytes) {
            return null;
        }
        $data = $zip->getFromIndex($idx, $maxBytes);
        return $data === false ? null : $data;
    }
}
