<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Připnutý balíček XSD měsíčního hlášení. Na rozdíl od registračních vět
 * netvoří `jmhzPodani.xsd` dvojici se svou jedinou závislostí — táhne za sebou
 * `souhrn`, `PVPOJ`, `form` a přes něj všech osm formulářových schémat.
 * Ověřuje se proto CELÝ balík, ne jen vstupní bod: `schemaValidate()` neumí
 * vypnout síť a jediné, co drží běh offline, je fakt, že každý
 * `schemaLocation` míří na relativní soubor uvnitř balíčku s ověřeným otiskem.
 * Kdyby se kterýkoli z nich dal vyměnit, dala by se validace obejít.
 */
final class JmhzSchemaCatalog
{
    public const PACKAGE_KEY = 'jmhz-1.4.3.6';
    public const ENTRY_POINT = 'jmhzPodani.xsd';

    /**
     * Verze datové věty (atribut `jmhz/@verze`) se drží tvaru `XX.YY.ZZ` podle
     * `xs:schema/@version` vstupního schématu, ne podle názvu adresáře balíčku.
     */
    public const DATA_VERSION = '1.4.3';

    public const NS_PODANI = 'http://schemas.cssz.cz/JMHZ/podani/1.0';
    public const NS_SOUHRN = 'http://schemas.cssz.cz/JMHZ/souhrn/1.0';
    public const NS_PVPOJ = 'http://schemas.cssz.cz/JMHZ/PVPOJ/1.0';
    public const NS_FORM = 'http://schemas.cssz.cz/JMHZ/form/1.0';

    private const BUNDLE = [
        'PVPOJ.xsd' =>
            '5bf8357aab60d8a27cac35625faaea2a52b72f1f4d9b3bf8c9d3e4c5163af6f8',
        'baseTypes2.xsd' =>
            '839973458fa82559dfb56114ef2e555523db64d5af9b4f203ece6e941f946660',
        'form.xsd' =>
            '5597b5ad9f01bd4a56a0eee7fdb8f1d2d30dd5a8bede6cac320c68fde08c7480',
        'formBezPriznaku.xsd' =>
            '242b08344fdcb9c41fb563168699437766b54fd2ee30d12b84c87d7caf6b2262',
        'formCinnostKS.xsd' =>
            '713ec54e94dd9be40523e1d3357e0b9a12de9a37d0bd420c3560400094140f3d',
        'formCommonTypes.xsd' =>
            '072d2e3002be26c602ccb23326647c1489fd734d1fb976bc8a50165148b5d881',
        'formJinyPrijem.xsd' =>
            '2daf24a0388f692912147a17f9234210bdbed921d5f0ffcebc0c7267b7755f76',
        'formMezinarodniPronajemSily.xsd' =>
            'd7c9fa004ca9a1f638eca632d565da0775ef7e45b5d145e59c6dfbdc88fc6d7a',
        'formOdlozenyPrijem.xsd' =>
            'b9fccabee047d69d5f9e8a633e8520f92af38c328cec172ca9ee1ae5045d7f16',
        'formOzpTpp.xsd' =>
            '213edd67e3e7b846b69b13405e6ad709c8a52088ee290434c08410897781a487',
        'formPestoun.xsd' =>
            'f2c4ad2e73b616e9dfbad4d9d23b6793644bf4918dcf08489b6049fd2137869f',
        'formVezen.xsd' =>
            '54f3d6eda6602ad958201320aa6ec2fb4a839239b0a7a6dd6d98b9e9e96a8cdd',
        'jmhzPodani.xsd' =>
            '228db1ee19d9e897d4f1f5f541d6c1c5e7e6c02d07ac7bbd33d987a35cd110f7',
        'souhrn.xsd' =>
            '4d5be0d5fd0a0fa2e6f7c0ba66a19ff2e9bb122a1c2c03e8d2858a6958a747f7',
    ];

    /** @return array{path:string,package_key:string,data_version:string,bundle_sha256:string} */
    public function entryPoint(): array
    {
        $root = dirname(__DIR__, 5) . '/xsd/jmhz/' . self::PACKAGE_KEY;
        $verified = [];
        foreach (self::BUNDLE as $name => $expectedHash) {
            $path = $root . '/' . $name;
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                throw new JmhzXmlException(
                    'jmhz_schema_integrity_failed',
                    'Připnutý balíček XSD měsíčního hlášení chybí nebo má jiný otisk.',
                );
            }
            $verified[] = $name . ':' . $expectedHash;
        }

        return [
            'path' => $root . '/' . self::ENTRY_POINT,
            'package_key' => self::PACKAGE_KEY,
            'data_version' => self::DATA_VERSION,
            'bundle_sha256' => hash('sha256', implode("\n", $verified)),
        ];
    }
}
