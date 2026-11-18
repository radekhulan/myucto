<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai\Assistant;

/**
 * Čtecí nástroje nad daty aktuální firmy pro AI asistenta, vázané na přihlášeného
 * uživatele. Produkční implementace je {@see McpAssistantDataSource} (katalog MCP),
 * testy podstrkují vlastní.
 */
interface AssistantDataSource
{
    /**
     * Nástroje, které smí asistent nabídnout modelu (jen čtecí a jen ty, které role
     * uživatele v aktuální firmě může použít).
     *
     * @return list<array{name:string, title:string, description:string, inputSchema:array<string,mixed>}>
     */
    public function catalog(): array;

    /**
     * Zavolá nástroj z katalogu. Chyba (neznámý nástroj, neplatné argumenty, odmítnutí
     * API) se vrací jako `ok: false` s textem pro model, ne jako výjimka.
     *
     * @param array<string,mixed> $arguments
     * @return array{ok:bool, text:string}
     */
    public function call(string $name, array $arguments): array;
}
