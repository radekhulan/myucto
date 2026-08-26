<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * ⚠️ JEDINÉ MÍSTO V CELÉM MODULU, KTERÉ SE DOTÝKÁ SÍTĚ ⚠️
 *
 * Port k datové schránce, popsaný jazykem ISDS (dmID, dbID, dmSenderIdent),
 * ale bez jediné zmínky o tom, ČÍM se ta komunikace dělá. {@see IsdsChannel},
 * fronta, ledger, cron i UI stojí nad tímhle rozhraním a o žádné knihovně nevědí.
 *
 * Důvod je provozní: volba knihovny ještě není uzavřená (verdikt auditu je
 * „go s výhradami", ale verze je čerstvá totální přestavba a bus factor je 1).
 * Až rozhodnutí padne, přibude JEDEN soubor s implementací a v `Bootstrap.php`
 * jeden bind. Nic jiného se nemění.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POVINNOSTI IMPLEMENTACE (vyplývají z bezpečnostního auditu knihovny)
 * ═══════════════════════════════════════════════════════════════════════════
 * Tyhle body nejsou doporučení. Každý z nich je nález, který v knihovně
 * reálně je, a rozhraní je postavené tak, aby na něj adaptér musel odpovědět.
 *
 * 1. **Úspěch se musí prokázat, ne jen nespadnout.** Knihovna při chybovém
 *    stavu ISDS nehází výjimku — vrátí objekt s `isOk() === false`. A `isOk()`
 *    bere jako úspěch každý kód začínající `00`. Implementace proto musí
 *    kontrolovat kód **přesně `0000`**; typ {@see IsdsSendReceipt} to vynucuje
 *    tím, že jinak instanci nevytvoří.
 *
 * 2. **Chytat `\Throwable`, ne typ knihovny.** Neúplná odpověď (chybějící
 *    `dmStatus` a spol.) shodí přístup k neinicializované typované property,
 *    což je `\Error` MIMO hierarchii výjimek knihovny — a stane se to až
 *    POTÉ, co zpráva mohla odejít. Takový stav se hlásí jako
 *    {@see IsdsTransportTimeout}, nikdy jako selhání.
 *
 * 3. **Timeout je běžný stav, ne výjimečný.** Knihovna nemá nastavené žádné
 *    timeouty (výchozí Guzzle klient je čeká donekonečna), takže si je
 *    implementace musí nastavit sama. Zavěšené spojení jinak drží PHP worker.
 *
 * 4. **Žádná perzistence běžného hesla.** Systémový certifikát lze držet v
 *    šifrovaném firemním trezoru. Interaktivní metody smějí nést osobní tajemství
 *    v {@see ChannelContext} jen jako {@see SensitiveValue} po dobu právě
 *    spuštěného načtení; do credential tabulky ani logů se nesmí zapsat.
 *
 * 5. **Žádný plaintext na disku.** Knihovna odkládá stažené přílohy do
 *    systémového TEMP nešifrované a uklízí je až v destruktoru — po fatální
 *    chybě tam zůstanou. Implementace musí obsah okamžitě přesunout do našeho
 *    úložiště a dočasný soubor smazat, i když mezitím něco spadne.
 *
 * 6. **Přihlašovací objekt knihovny se nikdy neserializuje.** Nemá
 *    `__serialize()`, takže by `serialize()` i `var_export()` vypsaly heslo
 *    i certifikát. Do fronty úloh patří `supplier_id`, ne pověření.
 *
 * 7. **Podpisy neověřuje nikdo.** Knihovna neověřuje ani CMS podpis doručenky,
 *    ani časové razítko. Dokud si to neuděláme sami, zůstává doručenka vedená
 *    jako `unverified` a nesmí se prezentovat jako ověřený důkaz.
 *
 * A jedna povinnost, která z auditu neplyne, ale je nejdůležitější:
 * implementace se **nesmí pokoušet odvozovat, jestli úřad podání přijal**.
 * Datová schránka to neví — § 73 odst. 3 DŘ váže automatické potvrzení
 * s podacím číslem výhradně na podání na technické zařízení správce daně,
 * kdežto datovkou dostaneme jen doručenku. Chyby přijdou až po dnech jako
 * výzva k odstranění vad podle § 74 DŘ, a to jako běžná zpráva pro člověka.
 */
interface IsdsTransport
{
    /**
     * Ověří schránku příjemce (ISDS `checkDataBox` / `findDataBox2`).
     *
     * Povinný krok PŘED každým odesláním. Náš číselník stárne (seznam
     * Finanční správy je z roku 2023), ISDS je autoritativní a odchytí
     * zrušenou nebo znepřístupněnou schránku dřív, než do ní pošleme přiznání.
     *
     * @throws SubmissionChannelException když se ověřit nepodařilo. Nevědomost
     *         se nesmí vydávat ani za „schránka je v pořádku", ani za opak.
     */
    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck;

    /**
     * Vytvoří a odešle zprávu (ISDS `CreateMessage`).
     *
     * `$senderIdent` MUSÍ skončit v poli `dmSenderIdent` odchozí zprávy
     * (limit 50 znaků). ISDS nemá žádný idempotency token, takže je to jediná
     * stopa, podle které se dá po přerušeném volání dohledat, co se stalo.
     *
     * @param list<array{filename:string,mime:string,bytes:string}> $files
     *        První soubor je hlavní příloha (`dmFileMetaType = main`).
     * @throws IsdsTransportTimeout kdykoli není výsledek jistý — včetně `\Error`
     *         z neúplné odpovědi, protože ten přijde až po možném odeslání
     * @throws SubmissionChannelException při PROKAZATELNÉM odmítnutí
     */
    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt;

    /**
     * Dohledá v ODESLANÝCH zprávách tu s daným `dmSenderIdent`
     * (ISDS `GetListOfSentMessages` s rozsahem `listFrom`/`listTo`).
     *
     * Po timeoutu se NIKDY neodesílá znovu — nejdřív tohle.
     *
     * @return string|null dmID, nebo null když taková zpráva neexistuje
     * @throws SubmissionChannelException když se dohledat nepodařilo; `null`
     *         znamená výhradně „zpráva tam není", nikdy „nevím"
     */
    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string;

    /**
     * Stav odeslané zprávy.
     *
     * @return array{state:string,delivered_at:?string,accepted_at:?string}
     *         `accepted_at` = `dmAcceptanceTime`, tedy okamžik DORUČENÍ
     *         (přihlášením nebo fikcí) — NE přijetí úřadem. Jméno je v ISDS
     *         matoucí a je to nejčastější zdroj té záměny.
     * @throws SubmissionChannelException
     */
    public function messageState(ChannelContext $context, string $messageId): array;

    /**
     * Seznam nových přijatých zpráv (ISDS `GetListOfReceivedMessages`).
     *
     * ⚠️ **PRÁVNĚ VÝZNAMNÝ ÚKON.** Tohle volání je přihlášení do schránky,
     * a tím DORUČENÍ všech dodaných zpráv podle § 17 odst. 3 zák. 300/2008 Sb.
     * Rozjíždí zákonné lhůty. Není to neutrální čtení: volá se jen tehdy, když
     * si to uživatel vědomě zapnul, a každé volání patří do auditní stopy.
     *
     * @return list<array{
     *   message_id:string, sender_box_id:?string, sender_name:?string,
     *   subject:?string, sender_ident:?string,
     *   delivered_at:?string, accepted_at:?string
     * }>
     * @throws SubmissionChannelException při jakémkoli selhání dotazu.
     *         Prázdné pole znamená výhradně „nic nového".
     */
    public function listReceived(ChannelContext $context): array;

    /**
     * Stáhne kompletní zprávu jako ZFO (PKCS#7/CMS obálku s ISDS XML)
     * a vrátí ji v paměti. Rozbalí ji {@see \MyInvoice\Service\Document\ZfoExtractor}.
     *
     * Implementace nesmí nechat obsah ležet v systémovém TEMP — viz povinnost 5.
     *
     * @throws SubmissionChannelException
     */
    public function downloadMessage(ChannelContext $context, string $messageId): string;

    /**
     * Stáhne PODEPSANOU doručenku k naší odeslané zprávě
     * (ISDS `GetSignedDeliveryInfo`).
     *
     * Doručenka je náš jediný důkaz o dni podání podle § 73 odst. 1 DŘ, takže
     * se archivuje i s `dmID` a otiskem odeslaného XML. Podpis ani časové
     * razítko se tu NEOVĚŘUJÍ — dokud to neuděláme sami, vede se jako
     * `unverified`.
     *
     * `null` = doručenka zatím neexistuje (zpráva ještě nebyla doručena).
     *
     * @throws SubmissionChannelException
     */
    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string;
}
