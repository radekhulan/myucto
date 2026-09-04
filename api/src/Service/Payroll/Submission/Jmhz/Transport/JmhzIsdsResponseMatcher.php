<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Která došlá zpráva ve schránce je odpovědí na naše podání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se páruje podle věci
 * ═══════════════════════════════════════════════════════════════════════════
 * Podávací a dotazovací protokol ČSSZ v1.47, kapitola „ISDS → Komunikační vzor“
 * (strana 24 z 51), přiznává omezení rozhraní ISDS: „aktuální verze rozhraní
 * ISDS neumožňuje vyhledávat došlé zprávy podle pole věc či podle spisové
 * značky […], takže je nutné stažený seznam projít a vyhledat dle pole ‚věc‘
 * odpovědi na podání“.
 *
 * Párování podle věci tedy není improvizace — je to postup, který ČSSZ předepisuje
 * a k němuž se v témže odstavci ZAVAZUJE:
 *
 * > „Pro usnadnění párování zpráv obsahujících podání a odpověď garantuje systém
 * > pro zpracování e-podání ČSSZ uvedení ID datové zprávy ISDS s podáním v poli
 * > ‚věc‘ v datové zprávě s odpovědí, a to ve formátu
 * > "ČSSZ - Odpověď na e-Podání. [{0}-{1}-{2}]" (kde prvkem {0} je
 * > transakce/classname, prvkem {1} je unikátní identifikátor podání a prvkem
 * > {2} je identifikátor původní zprávy s podáním).“
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Rozhoduje `dmId`, ne classname a ne pořadí
 * ═══════════════════════════════════════════════════════════════════════════
 * Ze tří prvků je pro nás závazný jen třetí — `dmId` NAŠÍ odeslané zprávy. Ten
 * jsme dostali při odeslání (nebo dohledali přes `probe()`), je jedinečný v celém
 * ISDS a nedá se splést s cizím podáním. `classname` se kontroluje jen jako
 * pojistka proti odpovědi na jinou agendu; `correlationId` se čte a předá dál,
 * ale sám o sobě nic neprokazuje.
 *
 * Matcher schválně NEPOUŽÍVÁ „nejnovější zpráva od ČSSZ“ ani shodu podle období.
 * Zaměstnavatel odesílá každý měsíc a opravná podání se překrývají — vzít
 * odpověď podle času by k podání přiřadilo protokol jiného měsíce a tím uzavřelo
 * povinnost, která uzavřená není.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co matcher NEDĚLÁ
 * ═══════════════════════════════════════════════════════════════════════════
 * Neprohlašuje nic za ověřené. Vrátí jen „tahle zpráva se tváří jako odpověď na
 * tohle podání“; obsah přílohy pak musí projít
 * {@see JmhzProtocolSignatureVerifier} a {@see JmhzProtocolParser} přesně jako
 * u VREP. Věc datové zprávy je nepodepsaný text, který si může nastavit kdokoliv
 * — kdyby na něm stálo přijetí podání, stačilo by poslat zprávu se správně
 * složeným předmětem.
 */
final readonly class JmhzIsdsResponseMatcher
{
    /**
     * Předpona věci odpovědi podle protokolu. Porovnává se bez ohledu na
     * velikost písmen a s tolerancí k mezerám — je to text pro člověka a
     * protokol jeho přesný tvar nezaručuje nad rámec uvedeného vzoru.
     */
    public const SUBJECT_PREFIX = 'ČSSZ - Odpověď na e-Podání.';

    /**
     * Název přílohy s protokolem, tvar podle téže strany protokolu:
     * `ČSSZ_Protokol_o_zpracování_e-Podání_{0}-{1}-{2}.xml`.
     */
    public const ATTACHMENT_PREFIX = 'ČSSZ_Protokol_o_zpracování_e-Podání_';

    /**
     * Rozebere věc odpovědi na tři prvky, nebo vrátí `null`, když to odpověď
     * ČSSZ na e-Podání není.
     */
    public function parseSubject(?string $subject): ?JmhzIsdsResponseReference
    {
        if ($subject === null) {
            return null;
        }
        // Hranatá závorka je jediná strukturní kotva, kterou protokol slibuje;
        // text před ní se může lišit diakritikou i mezerami podle toho, čím
        // prošel. Vytahuje se proto obsah závorky, ne shoda celé věty.
        if (preg_match('/\[([^\[\]]{3,255})\]/u', $subject, $match) !== 1) {
            return null;
        }
        $parts = explode('-', $match[1]);
        // Formát je {classname}-{correlationId}-{dmId}. Míň než tři prvky není
        // ten formát; víc být může, protože correlationId samo pomlčku obsahovat
        // smí — proto se krajní prvky berou zvenčí a zbytek je correlationId.
        if (count($parts) < 3) {
            return null;
        }
        $className = trim(array_shift($parts));
        $dmId = trim(array_pop($parts));
        $correlationId = trim(implode('-', $parts));

        if ($className === '' || $dmId === '' || $correlationId === '') {
            return null;
        }

        return new JmhzIsdsResponseReference($className, $correlationId, $dmId);
    }

    /**
     * `dmId` naší zprávy tak, jak ho ČSSZ píše mimo dokumentovaný tvar:
     * „… z DZ 1761891234 …“. Ověřeno na odpovědi doručené 4. 9. 2026.
     */
    private const SENT_MESSAGE_PATTERN = '/\bz\s+DZ\s+(\d{1,20})\b/iu';

    /** Variabilní symbol zaměstnavatele: `VS4442070407` i `VS 4442070407`. */
    private const VARIABLE_SYMBOL_PATTERN = '/\bVS\s?(\d{10})\b/iu';

    /** Rozhodné období: `08/2026`, ve starším tvaru i `VS…-05/2026-…`. */
    private const PERIOD_PATTERN = '#\b(0?[1-9]|1[0-2])/((?:19|20)\d{2})\b#';

    /**
     * Vodítka z věci došlé zprávy — dokumentovaný tvar i skutečné věty, které
     * ČSSZ pro JMHZ posílá. Nic z toho nic neprokazuje; slouží jen k tomu, ke
     * kterému podání se má protokol zkusit přiřadit.
     */
    public function parseCsszSubject(?string $subject): ?JmhzIsdsProtocolSubject
    {
        if ($subject === null || trim($subject) === '') {
            return null;
        }
        $documented = $this->parseSubject($subject);
        $sentMessageId = $documented?->originalMessageId;
        if ($sentMessageId === null
            && preg_match(self::SENT_MESSAGE_PATTERN, $subject, $match) === 1
        ) {
            $sentMessageId = $match[1];
        }
        $variableSymbol = null;
        if (preg_match(self::VARIABLE_SYMBOL_PATTERN, $subject, $match) === 1) {
            $variableSymbol = $match[1];
        }
        $month = null;
        $year = null;
        if (preg_match(self::PERIOD_PATTERN, $subject, $match) === 1) {
            $month = (int) $match[1];
            $year = (int) $match[2];
        }
        $parsed = new JmhzIsdsProtocolSubject(
            $sentMessageId,
            $variableSymbol,
            $month,
            $year,
        );

        return $parsed->isEmpty() ? null : $parsed;
    }

    /**
     * Je tahle došlá zpráva odpovědí na zprávu, kterou jsme odeslali?
     *
     * @param string $sentMessageId `dmId` naší odeslané zprávy
     */
    public function matches(
        ?string $subject,
        string $sentMessageId,
        ?string $expectedClassName = null,
    ): bool {
        $reference = $this->parseSubject($subject);
        if ($reference === null) {
            return false;
        }
        $sentMessageId = trim($sentMessageId);
        if ($sentMessageId === '') {
            // Bez ID odeslané zprávy nemáme s čím porovnávat. Vrátit „ano“ by
            // k podání přiřadilo první odpověď, která se namane.
            return false;
        }
        if (!hash_equals($sentMessageId, $reference->originalMessageId)) {
            return false;
        }

        return $expectedClassName === null
            || strcasecmp(trim($expectedClassName), $reference->className) === 0;
    }

    /**
     * Táž otázka, ale i pro věty, které dokumentovaný tvar nedodržují.
     *
     * Rozhoduje pořád jen `dmId` NAŠÍ odeslané zprávy. Když ho věc neuvádí
     * (protokol o kompletnosti ho nenese), vrací se `false` — vodítkem je pak
     * variabilní symbol a období, ale ta sama o sobě k vazbě nestačí a musí je
     * potvrdit obsah přílohy.
     */
    public function matchesSentMessage(?string $subject, string $sentMessageId): bool
    {
        $sentMessageId = trim($sentMessageId);
        if ($sentMessageId === '') {
            return false;
        }
        $parsed = $this->parseCsszSubject($subject);
        if ($parsed?->originalMessageId === null) {
            return false;
        }

        return hash_equals($sentMessageId, $parsed->originalMessageId);
    }
}
