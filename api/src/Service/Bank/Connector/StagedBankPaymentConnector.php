<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Konektor, který příkaz zakládá po jednotlivých platbách a pak je seskupí do dávky
 * k podpisu v bance. Průběh zapisuje do {@see BankPaymentSubmissionJournal}.
 */
interface StagedBankPaymentConnector extends BankConnector
{
    /**
     * Kontrola příkazu bez volání banky, ještě před založením pokusu o odeslání.
     *
     * @throws BankConnectorException s jistým (ne nejasným) odmítnutím
     */
    public function assertPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): void;

    /**
     * @return array{accepted:true,reference:string,status:'awaiting_signature',sign_hash:string}
     */
    public function submitStagedPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
        BankPaymentSubmissionJournal $journal,
    ): array;
}
