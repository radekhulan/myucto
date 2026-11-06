<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Dávka, kterou uživatel podepisuje po přesměrování do banky; výsledek podpisu
 * i provedení plateb se zjišťuje dotazem (banka nevolá zpět).
 */
interface SignableBankPaymentConnector extends StagedBankPaymentConnector
{
    /** @return array{signing_url:string,valid_to:?string,poll_id:string,poll_interval_ms:int} */
    public function startSignature(#[\SensitiveParameter] string $token, string $batchSignId, string $batchHash, string $callbackUri): array;

    /** @return array{state:string,poll_interval_ms:int} stav z {@see CsasApiClient::SIGN_STATES} */
    public function signatureState(#[\SensitiveParameter] string $token, string $pollId): array;

    /** @return array{instruction_status:string,status_change_info:?string} */
    public function paymentState(#[\SensitiveParameter] string $token, string $signId): array;
}
