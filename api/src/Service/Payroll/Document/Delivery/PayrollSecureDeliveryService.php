<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document\Delivery;

use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use Psr\Log\LoggerInterface;

/**
 * Odchozí strana zabezpečeného doručení: zařazení do fronty, odeslání workerem,
 * zneplatnění.
 *
 * Rozesílka NIKDY neběží v requestu. Sestavení zprávy dešifruje adresu, sahá na
 * SMTP a může trvat sekundy; kdyby to viselo na kliknutí účetní, každý timeout by
 * skončil buď nedoručenou páskou, nebo — hůř — dvojím odesláním. Fronta to řeší
 * idempotentním klíčem a leasem, takže „odesláno právě jednou" drží i přes pád
 * workeru.
 *
 * PLAINTEXT TOKENU ŽIJE PŘESNĚ JEDNU FUNKCI. Vygeneruje se ve
 * {@see self::dispatchOne()}, vloží se do e-mailu a zahodí. V DB je jen sha256,
 * v logu není vůbec — logují se identifikátory řádků, nikdy tajemství.
 */
final class PayrollSecureDeliveryService
{
    /** Kód šablony e-mailu; DB override i soubor v templates/email/. */
    public const TEMPLATE_CODE = 'payroll_document_secure_link';

    public function __construct(
        private readonly PayrollDocumentAccessLinkRepository $links,
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollDocumentDeliveryLedgerService $ledger,
        private readonly PayrollSecureDeliveryPolicy $policy,
        private readonly PayrollDeliveryRecipientResolver $recipients,
        private readonly Mailer $mailer,
        private readonly TenantUrlResolver $tenantUrls,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Zařadí odeslání do fronty. Nic neodesílá.
     *
     * @return array{link_id:int,created:bool,recipient_masked:string,expires_at:string}
     * @throws PayrollSecureDeliveryBlockedException
     * @throws PayrollProductionGateException
     */
    public function enqueue(
        int $supplierId,
        int $documentId,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $documentId <= 0) {
            throw new \InvalidArgumentException('Identita mzdového dokumentu není platná.');
        }

        $document = $this->documents->find($supplierId, $documentId);
        if ($document === null) {
            throw new \DomainException('Mzdový dokument nebyl nalezen.');
        }
        $employeeId = $document['employee_id'] ?? null;
        if (!is_int($employeeId) || $employeeId <= 0) {
            // Firemní sestavy (mzdový list firmy, rekapitulace, přehledy) nemají
            // adresáta a nikdy ho mít nebudou. Trigger v DB to hlídá taky, ale
            // odmítnout to má aplikace, ne až constraint.
            throw new PayrollSecureDeliveryBlockedException(
                'document_not_personal',
                'Zabezpečený odkaz lze vydat jen k osobnímu dokumentu zaměstnance.',
            );
        }

        $this->policy->assertDispatchAllowed($supplierId, $this->effectiveOn($document));

        $recipient = $this->recipients->resolve($supplierId, $employeeId);
        $this->policy->assertEmployeeOptedIn($recipient['secure_delivery_channel']);

        // Idempotence je vázaná na dokument a jeho obsah: nový odkaz na TÝŽ obsah
        // se nezaloží dvakrát, ale po opravné revizi (jiný `file_sha256`) ano,
        // protože to už je jiná páska. Dvojklik účetní tak nepošle dva odkazy.
        $idempotencyKey = 'payroll-document-secure-link:' . $documentId
            . ':' . substr((string) ($document['file_sha256'] ?? ''), 0, 32);

        $created = $this->links->create(
            $supplierId,
            $documentId,
            $employeeId,
            $recipient['email_hash'],
            $recipient['masked'],
            $idempotencyKey,
            $this->policy->linkTtlDays(),
            $actorUserId,
        );

        $link = $this->links->find($supplierId, $created['id']);
        if ($link === null) {
            throw new \RuntimeException('Zabezpečený odkaz se nepodařilo načíst.');
        }

        return [
            'link_id' => (int) $link['id'],
            'created' => $created['created'],
            'recipient_masked' => (string) $link['recipient_masked'],
            'expires_at' => (string) $link['expires_at'],
        ];
    }

    /**
     * Zneplatní odkaz. Používá se i jako „poslat znovu": nejdřív zneplatnit starý,
     * teprve pak zařadit nový, aby po firmě nekolovaly dva platné odkazy na jednu
     * pásku.
     */
    public function revoke(int $supplierId, int $linkId, ?int $actorUserId): bool
    {
        $link = $this->links->find($supplierId, $linkId);
        if ($link === null) {
            throw new \DomainException('Zabezpečený odkaz nebyl nalezen.');
        }
        if (!$this->links->revoke($supplierId, $linkId)) {
            return false;
        }
        $this->ledger->recordChannelEvent(
            $supplierId,
            (int) $link['payroll_document_id'],
            'secure_link_revoked',
        );
        $this->logger->info('payroll.secure_delivery.revoked', [
            'link_id' => $linkId,
            'document_id' => (int) $link['payroll_document_id'],
            'actor_user_id' => $actorUserId,
        ]);
        return true;
    }

    /**
     * Jeden krok workeru.
     *
     * @return array{processed:bool,succeeded:?bool,link_id:?int}
     */
    public function dispatchOne(): array
    {
        // Přepínač instance se čte ZNOVU, ne jen při zařazení. Fronta přežije
        // restart i změnu konfigurace; vypnutý kanál musí zastavit i to, co v ní
        // už leží.
        if (!$this->policy->isChannelEnabled()) {
            return ['processed' => false, 'succeeded' => null, 'link_id' => null];
        }

        $maxAttempts = $this->policy->maxDispatchAttempts();
        $claim = $this->links->claimNext($maxAttempts);
        if ($claim === null) {
            return ['processed' => false, 'succeeded' => null, 'link_id' => null];
        }

        $supplierId = (int) $claim['supplier_id'];
        $linkId = (int) $claim['id'];
        $documentId = (int) $claim['payroll_document_id'];
        $lease = (string) $claim['lease_token'];

        try {
            $document = $this->documents->find($supplierId, $documentId);
            if ($document === null) {
                throw new PayrollSecureDeliveryBlockedException(
                    'document_missing',
                    'Mzdový dokument už neexistuje.',
                );
            }

            // Brána znovu, těsně před odesláním — viz PayrollSecureDeliveryPolicy.
            $this->policy->assertDispatchAllowed($supplierId, $this->effectiveOn($document));

            $recipient = $this->recipients->resolve($supplierId, (int) $claim['employee_id']);
            $this->policy->assertEmployeeOptedIn($recipient['secure_delivery_channel']);

            $email = $this->recipients->plaintextEmail(
                $supplierId,
                (int) $claim['employee_id'],
                (string) $claim['recipient_email_hash'],
            );

            // Plaintext lokátoru vzniká TEĎ a nikde se neukládá. Odeslaný e-mail je
            // jediné místo, kde existuje; v DB je jen jeho sha256, takže ani dump
            // databáze nedá funkční URL.
            $token = bin2hex(random_bytes(32));
            if (!$this->links->attachToken($supplierId, $linkId, hash('sha256', $token), $lease)) {
                throw new \RuntimeException('Lokátor odkazu se nepodařilo přiřadit.');
            }

            $this->mailer->sendTemplate(
                self::TEMPLATE_CODE,
                'cs',
                [$email],
                [
                    'url' => $this->publicUrl($supplierId, $token),
                    'documentKind' => (string) ($document['document_kind'] ?? ''),
                    'validUntil' => (string) $claim['expires_at'],
                ],
            );
            unset($token, $email);

            if (!$this->links->markSent($supplierId, $linkId, $lease)) {
                throw new \RuntimeException('Odeslaný odkaz se nepodařilo označit.');
            }
            $this->ledger->recordChannelEvent($supplierId, $documentId, 'secure_link_sent');
            $this->logger->info('payroll.secure_delivery.sent', [
                'link_id' => $linkId,
                'document_id' => $documentId,
            ]);

            return ['processed' => true, 'succeeded' => true, 'link_id' => $linkId];
        } catch (\Throwable $exception) {
            // Blokace politikou je trvalá — opakováním nezmizí, tak ať se fronta
            // nezacyklí. Selhání SMTP naopak stojí za další pokus.
            $permanent = $exception instanceof PayrollSecureDeliveryBlockedException
                || $exception instanceof PayrollProductionGateException;
            $code = $exception instanceof PayrollSecureDeliveryBlockedException
                ? $exception->reasonCode()
                : self::errorCode($exception);

            $this->links->markAttemptFailed(
                $supplierId,
                $linkId,
                $lease,
                $code,
                $maxAttempts,
                $permanent,
            );
            $exhausted = $permanent || (int) $claim['attempt_count'] >= $maxAttempts;
            if ($exhausted) {
                try {
                    $this->ledger->recordChannelEvent(
                        $supplierId,
                        $documentId,
                        'secure_link_failed',
                    );
                } catch (\Throwable) {
                    // Evidence nesmí přebít původní příčinu selhání.
                }
            }
            // Do logu jde jen kód a identifikátory. Text výjimky může nést adresu
            // příjemce, a ta v logu nemá co dělat.
            $this->logger->warning('payroll.secure_delivery.failed', [
                'link_id' => $linkId,
                'document_id' => $documentId,
                'reason' => $code,
                'permanent' => $permanent,
            ]);

            return ['processed' => true, 'succeeded' => false, 'link_id' => $linkId];
        }
    }

    /** @return array{processed:int,succeeded:int,failed:int} */
    public function dispatchAvailable(int $limit = 25): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $step = $this->dispatchOne();
            if (!$step['processed']) {
                break;
            }
            $result['processed']++;
            $step['succeeded'] === true ? $result['succeeded']++ : $result['failed']++;
        }
        return $result;
    }

    private function publicUrl(int $supplierId, string $token): string
    {
        return $this->tenantUrls->urlFor(
            $supplierId,
            'public_links',
            '/payroll-document/' . $token,
        );
    }

    /** @param array<string,mixed> $document */
    private function effectiveOn(array $document): string
    {
        $period = (string) ($document['period_start'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $period) === 1
            ? $period
            : (new \DateTimeImmutable())->format('Y-m-d');
    }

    private static function errorCode(\Throwable $exception): string
    {
        $short = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        return substr('dispatch_' . $normalized, 0, 64);
    }
}
