<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;

/**
 * Vzorky pro transportní testy. Všechny identifikátory jsou smyšlené, jen mají
 * doložený tvar: variabilní symbol deset číslic, GUID formuláře RFC 9562.
 */
final class JmhzTransportSample
{
    public const VARIABLE_SYMBOL = '9990000001';
    public const FORM_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEE';
    public const OTHER_FORM_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEF';

    /**
     * Záměrně JINÝ tvar než doložený — testy, které staví obálku, tak nemůžou
     * nechtěně projít jen proto, že se trefily do výchozích hodnot.
     */
    public static function shape(): JmhzGovTalkRequestShape
    {
        return new JmhzGovTalkRequestShape(
            'request',
            'poll',
            'submit',
            'delete',
            'VS',
            '1.2',
            'request',
            'test-only: záměrně jiný tvar než doložený',
        );
    }

    public static function payload(
        string $variableSymbol = self::VARIABLE_SYMBOL,
        string $productName = 'MyUcto',
        string $productVersion = '1.0',
    ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3.4">'
            . '<VENDOR productName="' . $productName . '" productVersion="'
            . $productVersion . '"/>'
            . '<hlavicka><variabilniSymbol>' . $variableSymbol
            . '</variabilniSymbol></hlavicka>'
            . '</jmhz>';
    }

    /**
     * `identifier` lze u formuláře přebít, aby šlo popsat i protokol, který
     * vrací JINÉ OIČ / ID PPV, než jsme odeslali.
     *
     * @param list<array{
     *   guid:string,result:string,errMsg?:string,errNum?:string,
     *   identifier?:string
     * }> $forms
     */
    public static function partialProtocol(
        string $result = 'OK',
        array $forms = [],
        string $qualifier = 'response',
        string $errMsg = '',
        string $errNumber = '0',
        string $generalResult = 'OK',
        int $warnings = 0,
        ?string $correlationId = null,
    ): string {
        $items = '<Item sqnr="" identifier="" subtype="" period="" result="'
            . $generalResult . '" errMsg="" errNum="" />'
            . '<Item sqnr="" identifier="" subtype="SOUHRN" period="" result="OK" errMsg="" errNum="" />'
            . '<Item sqnr="" identifier="" subtype="PVPOJ" period="" result="OK" errMsg="" errNum="" />';
        foreach ($forms as $form) {
            $items .= '<Item sqnr="' . $form['guid']
                . '" identifier="'
                . ($form['identifier'] ?? '1632728141;4002787754995')
                . '" subtype="FORM" period="" result="'
                . $form['result'] . '" errMsg="'
                . htmlspecialchars($form['errMsg'] ?? '', ENT_QUOTES | ENT_XML1)
                . '" errNum="' . ($form['errNum'] ?? '') . '" />';
        }
        $correlation = $correlationId === null
            ? ''
            : '<CorrelationID>' . $correlationId . '</CorrelationID>';

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails><Class>CSSZ_JMHZ</Class>'
            . '<Qualifier>' . $qualifier . '</Qualifier><Function>submit</Function>'
            . $correlation
            . '</MessageDetails></Header>'
            . '<GovTalkDetails></GovTalkDetails>'
            . '<Body><Message version="1.2" eType="response" xmlns="http://www.cssz.cz/XMLSchema/envelope">'
            . '<Header /><Body>'
            . '<ProcessingResult type="CSSZ_JMHZ" version="1,0" result="' . $result
            . '" errMsg="' . htmlspecialchars($errMsg, ENT_QUOTES | ENT_XML1)
            . '" errNumber="' . $errNumber . '" count="' . (3 + count($forms))
            . '" countErr="0" countWar="' . $warnings . '">'
            . '<Error><RaisedBy></RaisedBy><Number>' . $errNumber
            . '</Number><Type>CSSZ_JMHZ</Type><Text></Text></Error>'
            . '<Details>' . $items . '</Details>'
            . '</ProcessingResult>'
            . '</Body></Message></Body></GovTalkMessage>';
    }

    /** @param list<array{kod:string,popis:string,typChyby:string,castPodani:string,idFormulare:string}> $failures */
    public static function completenessProtocol(
        string $statusCode,
        string $statusLabel,
        string $protocolCode = '4',
        string $correlationId = 'CID0000000001',
        array $failures = [],
    ): string {
        $errors = '';
        foreach ($failures as $failure) {
            $errors .= '<chyba><id>1</id><typChyby>' . $failure['typChyby']
                . '</typChyby><castPodani>' . $failure['castPodani']
                . '</castPodani><idFormulare>' . $failure['idFormulare']
                . '</idFormulare><kod>' . $failure['kod']
                . '</kod><popis>' . $failure['popis'] . '</popis></chyba>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<DZMHOdpoved xmlns="http://schemas.cssz.cz/JMHZ/dotazNaStav/2025" verze="2025.0">'
            . '<variabilniSymbol>' . self::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<mesic>4</mesic><rok>2026</rok>'
            . '<stavMH><kod>' . $statusCode . '</kod><nazev>' . $statusLabel . '</nazev></stavMH>'
            . '<protokoly><protokol>'
            . '<idKonkretnihoPodani>' . $correlationId . '</idKonkretnihoPodani>'
            . '<kod>' . $protocolCode . '</kod><nazev>Podání je částečně přijato</nazev>'
            . '<chybySeznam>' . $errors . '</chybySeznam>'
            . '</protokol></protokoly>'
            . '</DZMHOdpoved>';
    }
}
