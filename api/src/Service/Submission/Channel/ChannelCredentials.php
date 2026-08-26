<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Přístup ke kanálu, jak ho vidí adaptér.
 *
 * Veřejné jsou jen údaje, které veřejné jsou (ID naší schránky, režim
 * přihlášení). Cokoliv tajného je {@see SensitiveValue}, takže se to nedostane
 * do výpisu, do JSONu ani do stack trace.
 *
 * Běžné heslo a SMS kód se nikdy neukládají. Osobní komunikační kód Mobilního
 * klíče lze na výslovnou žádost uložit jen šifrovaně v odděleném trezoru
 * firma + uživatel + prostředí. V tomto objektu jsou tajemství vždy jen jako
 * {@see SensitiveValue}; nesmějí do logu, session, cache ani fronty.
 */
final readonly class ChannelCredentials
{
    public function __construct(
        public string $boxId,
        public string $authMode,
        public ?SensitiveValue $certificate = null,
        public ?SensitiveValue $certificatePassphrase = null,
        public ?SensitiveValue $username = null,
        public ?SensitiveValue $password = null,
        public ?SensitiveValue $sessionCookie = null,
    ) {}

    /**
     * Ani přihlašovací objekt nesmí do session, cache nebo fronty úloh —
     * odtud by šel vytáhnout certifikát. Do fronty patří `supplier_id`
     * a pověření se odemyká až těsně před voláním.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Přístup ke kanálu nelze serializovat.');
    }

    /** @return array{box_id:string,auth_mode:string} Bezpečný tvar pro log. */
    public function toLogContext(): array
    {
        return ['box_id' => $this->boxId, 'auth_mode' => $this->authMode];
    }
}
