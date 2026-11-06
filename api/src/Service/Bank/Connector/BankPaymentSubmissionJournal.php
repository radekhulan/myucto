<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Evidence odeslání po položkách pro banky, jejichž API nemá idempotenční klíč. Každý
 * krok se zapíše dřív, než se pošle požadavek, a pokračování po přerušení vychází jen
 * z evidence. Selhání zápisu je vždy nejasný výsledek: konektor nesmí poslat další požadavek.
 */
interface BankPaymentSubmissionJournal
{
    /**
     * @param list<array{line_no:int,instruction_identification:string,amount_minor:int,creditor_iban:string,variable_symbol?:string}> $items
     * @return array<int,array{status:string,provider_sign_id:?string,provider_hash:?string}> evidence podle čísla řádku
     */
    public function plan(array $items): array;

    public function sending(int $lineNo): void;

    public function created(int $lineNo, string $signId, string $hash, string $instructionStatus): void;

    public function rejected(int $lineNo, string $errorCode): void;

    public function unknown(int $lineNo, string $errorCode): void;

    /** Dávka se zakládá; pokud se o ni už pokus jednou začal, vyhodí nejasný výsledek. */
    public function batchSending(): void;
}
