<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;

/**
 * Načtení protokolu ČSSZ, který přišel do datové schránky.
 *
 * PROČ TOHLE VŮBEC EXISTUJE: přehled „co jsem podal a jak to dopadlo" umí
 * ukázat jen podání, která odešla naší cestou. Firma, která poslala hlášení
 * cizím softwarem — a takových je většina těch, co k nám teprve přecházejí —
 * má obrazovku prázdnou, i když podala a protokol drží v ruce. Prázdná
 * obrazovka u firmy, která podala dvakrát, je horší než žádná obrazovka:
 * čte se jako „nic neodešlo".
 *
 * Bere všechny druhy, které umí `JmhzProtocolParser` (obálku GovTalk,
 * odpověď DZMH i protokol o zpracování) — hádat druh podle jména souboru by
 * znamenalo odmítnout doklad kvůli tomu, jak si ho uživatel pojmenoval.
 *
 * **Ověření příslušnosti je tvrdé a nejde obejít.** Do jedné datové schránky
 * chodí protokoly ke všem podáním a soubor si uživatel může splést. Uložit
 * protokol cizí firmy pod tenhle tenant je jediný výsledek, který musí být
 * nemožný — proto se ukládá jen protokol, jehož variabilní symbol se shoduje
 * s registračním číslem zaměstnavatele nebo s VS některého pracoviště. Když
 * protokol variabilní symbol vůbec nenese (obálka GovTalk ho nemá), NEUKLÁDÁ
 * se: neověřitelný doklad se nesmí tvářit jako ověřený.
 */
final readonly class JmhzProtocolImportService
{
    /** Doručený protokol má jednotky kilobajtů; strop je proti nesmyslu, ne škrcení. */
    public const MAX_BYTES = 262_144;

    public function __construct(
        private PayrollImportedJmhzProtocolRepository $protocols,
        private JmhzProtocolExplainer $explainer,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
    ) {
    }

    /**
     * @return array{
     *   protocol:array<string,mixed>,
     *   created:bool,
     *   errors:list<array<string,mixed>>
     * }
     */
    public function import(
        int $supplierId,
        string $environment,
        string $xml,
        ?string $filename,
        ?int $actorUserId,
    ): array {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new JmhzTransportException(
                'jmhz_protocol_too_large',
                'Soubor je na protokol ČSSZ příliš velký.',
            );
        }
        // Nečitelný protokol se neukládá. Uložit, co se nepodařilo přečíst,
        // by znamenalo mít v evidenci doklad, o kterém nevíme, co říká.
        $report = $this->parser->parse($xml);
        $variableSymbol = $report->variableSymbol;
        if ($variableSymbol === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_variable_symbol_missing',
                'Protokol neobsahuje variabilní symbol, takže nelze ověřit, že'
                    . ' patří této firmě. Načtěte protokol o zpracování,'
                    . ' který ČSSZ doručuje do datové schránky.',
            );
        }
        JmhzProtocolOwnership::assert(
            $variableSymbol,
            $this->protocols->employerVariableSymbols($supplierId),
        );

        $errors = $this->explainer->explain($report);
        $stored = $this->protocols->store(
            $supplierId,
            $environment,
            [
                'protocol_kind' => self::kindColumn($report),
                'variable_symbol' => $variableSymbol,
                'period_month' => $report->periodMonth,
                'period_year' => $report->periodYear,
                'submission_guid' => $report->submissionGuid === null
                    ? null
                    : strtoupper($report->submissionGuid),
                'correlation_reference' => $report->correlationReference,
                'status_code' => $report->status->value,
                'status_name' => $report->status->name,
                'error_count' => count($errors),
                'protocol_dated_at' => self::clip($report->protocolDate, 40),
                'submitted_at' => self::clip($report->submittedDate, 40),
                'source_filename' => self::clip($filename, 255),
                'payload_sha256' => hash('sha256', $xml),
                'payload_xml' => $xml,
                'dedupe_key' => self::dedupeKey($xml),
            ],
            $actorUserId,
        );

        return [
            'protocol' => $stored['row'],
            'created' => $stored['created'],
            'errors' => $errors,
        ];
    }

    /**
     * Stránka načtených protokolů.
     *
     * PROČ SEZNAM UŽ NENESE VYSVĚTLENÉ CHYBY: dřív si pro každý ze sta řádků
     * dotáhl uložené XML jen proto, aby z něj vysvětlení znovu vyparsoval —
     * tedy sto originálních dokladů přenesených z databáze a stokrát spuštěný
     * parser na jedno otevření obrazovky. Kolik chyb protokol má, je přitom
     * uložený sloupec `error_count`, a JESTLI se dá detail zobrazit, se pozná
     * z toho, že je originál uložený; ani jedno XML nepotřebuje.
     *
     * Vysvětlení se proto dotahuje až na vyžádání pro JEDEN protokol
     * ({@see self::explain()}). Zachovává to zásadu, že se chyby počítají
     * z ULOŽENÉHO ORIGINÁLU, ne ze zamrazené interpretace — náš katalog
     * kontrol se doplňuje, takže dnes nevysvětlená hláška může být zítra
     * dohledaná. Zamrazit výklad při importu by tomu bránilo.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function history(
        int $supplierId,
        string $environment,
        int $limit = PayrollImportedJmhzProtocolRepository::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        return $this->protocols->listRecentPage(
            $supplierId,
            $environment,
            $limit,
            $offset,
        );
    }

    /**
     * Vysvětlené chyby jednoho načteného protokolu.
     *
     * Když se originál z jakéhokoli důvodu nepodaří přečíst nebo rozebrat,
     * vrací se prázdný seznam s `detail_available = false`: řádek v evidenci
     * zmizet nesmí, protože právě on je dokladem o podání.
     *
     * @return array{errors:list<array<string,mixed>>,detail_available:bool}
     */
    public function explain(
        int $supplierId,
        string $environment,
        int $protocolId,
    ): array {
        $payload = $this->protocols->payload($supplierId, $environment, $protocolId);
        if ($payload === null) {
            return ['errors' => [], 'detail_available' => false];
        }
        try {
            return [
                'errors' => $this->explainer->explain($this->parser->parse($payload)),
                'detail_available' => true,
            ];
        } catch (\Throwable) {
            return ['errors' => [], 'detail_available' => false];
        }
    }

    /**
     * Otisk jednoho konkrétního doručeného dokladu. `idPodani` označuje celý
     * řetězec řádného, opravného a stornovacího podání, takže podle něj nelze
     * protokoly slučovat. Dvakrát načtený stejný originál zůstává jedním
     * dokladem, ale každá odlišná odpověď ČSSZ dostane vlastní řádek.
     */
    private static function dedupeKey(string $xml): string
    {
        return hash('sha256', $xml);
    }

    private static function kindColumn(JmhzProtocolReport $report): string
    {
        if ($report->kind === JmhzProtocolKind::PartialSubmission) {
            return 'partial_submission';
        }

        // Protokol o zpracování a odpověď DZMH sdílí `Completeness`; rozezná je
        // `idPodani`, které nese jen doručený protokol o zpracování.
        return $report->submissionGuid === null ? 'completeness' : 'processing';
    }

    private static function clip(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}
