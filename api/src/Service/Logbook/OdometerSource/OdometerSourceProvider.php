<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Zdroj automatického stavu tachometru (automobilka, agregátor, GPS jednotka).
 *
 * Zdroj umí jen přihlášení, seznam aut a načtení stavu. Uložení a šifrování přístupů,
 * zámek, kontrolu návaznosti na zapsané stavy a jízdy, zápis do odometer_readings
 * a denní běh dělá pro všechny zdroje stejně {@see OdometerSourceService}.
 *
 * Napojení je účet u zdroje (jedny přístupy); auta účtu se k autům v knize jízd přiřazují
 * podle `vehicleRef`, obvykle VIN.
 *
 * Nový zdroj = třída implementující toto rozhraní a registrace v {@see OdometerSourceRegistry}.
 * Kód zdroje se ukládá jako VARCHAR; platné kódy hlídá registr.
 */
interface OdometerSourceProvider
{
    /** Kód zdroje = hodnota odometer_readings.source i odometer_source_connections.provider. */
    public function code(): string;

    /** Popis pro UI, viz {@see OdometerSourceDefinition}. */
    public function definition(): OdometerSourceDefinition;

    /**
     * Zahájí připojení z hodnot formuláře (klíče dle {@see OdometerSourceDefinition::$fields}).
     * Zdroj s OAuth předá službě `$context->state` a PKCE z kontextu; vlastní state ani
     * verifier negeneruje.
     *
     * @param array<string,string> $input
     */
    public function startConnect(#[\SensitiveParameter] array $input, ConnectContext $context): ConnectStep;

    /**
     * Pokračuje v rozpracovaném připojení (polling device code, návrat z OAuth, …).
     * Jádro do vstupu doplní `code_verifier` a po návratu přesměrováním `code`.
     *
     * @param array<string,string|int> $pending data z předchozího {@see ConnectStep::$pending}
     * @param array<string,string>     $input
     */
    public function continueConnect(#[\SensitiveParameter] array $pending, #[\SensitiveParameter] array $input): ConnectStep;

    /**
     * Auta dostupná pod přístupem.
     *
     * @return list<OdometerSourceVehicle>
     */
    public function vehicles(OdometerSourceSession $session): array;

    /** Poslední stav tachometru auta v jednotce služby; null, když ho auto zatím neodeslalo. */
    public function readOdometer(OdometerSourceSession $session, string $vehicleRef): ?OdometerSample;
}
