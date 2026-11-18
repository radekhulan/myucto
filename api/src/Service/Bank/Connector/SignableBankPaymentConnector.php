<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Dávka (nebo jediná platba), kterou uživatel podepisuje po přesměrování do banky;
 * výsledek podpisu i provedení plateb se zjišťuje dotazem (banka nevolá zpět).
 * `$scope` je rozsah podpisu z odeslání: {@see CsasApiClient::SIGN_SCOPE_BATCH} nebo
 * {@see CsasApiClient::SIGN_SCOPE_PAYMENT}.
 */
interface SignableBankPaymentConnector extends StagedBankPaymentConnector
{
    /** @return array{signing_url:string,valid_to:?string,poll_id:string,poll_interval_ms:int} */
    public function startSignature(#[\SensitiveParameter] string $token, string $signId, string $hash, string $callbackUri,
        string $scope = CsasApiClient::SIGN_SCOPE_BATCH): array;

    /** @return array{state:string,poll_interval_ms:int} stav z {@see CsasApiClient::SIGN_STATES} */
    public function signatureState(#[\SensitiveParameter] string $token, string $pollId, string $scope = CsasApiClient::SIGN_SCOPE_BATCH): array;

    /** @return array{instruction_status:string,status_change_info:?string} */
    public function paymentState(#[\SensitiveParameter] string $token, string $signId): array;
}
