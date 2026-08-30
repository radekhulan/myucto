<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document\Delivery;

use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use Psr\Log\LoggerInterface;

/**
 * Příchozí strana: co se stane, když zaměstnanec klikne na odkaz.
 *
 * ZAMĚSTNANEC NENÍ UŽIVATEL APLIKACE. Nemá heslo, roli ani tenanta a mít je
 * nebude — kvůli dvanácti výplatnicím ročně se nezakládá účet. Cesta proto vede
 * mimo přihlášení a musí si bezpečnost obstarat sama:
 *
 *  - LOKÁTOR JE JEN ADRESA. `state()` je čtení bez vedlejších účinků a neukáže
 *    ani jméno, ani období, dokud není relace ověřená — jinak by z uniklé URL
 *    šlo vyčíst, kdo u koho pracuje. Před ověřením vrací pouze maskovanou adresu,
 *    aby zaměstnanec poznal, do které schránky se má podívat.
 *  - PROČ VŮBEC KÓD, KDYŽ ODKAZ PŘIŠEL DO TÉŽE SCHRÁNKY. Protože URL cestuje
 *    a schránka ne. Odkaz se přeposílá, zůstává v mailovém archivu firmy,
 *    v historii prohlížeče na sdíleném počítači, v logu proxy. Kód z uniklé URL
 *    dělá bezcenný řetězec: k zobrazení je navíc potřeba ŽIVÝ přístup do schránky
 *    v okamžiku čtení, ne jen kdysi doručená zpráva.
 *  - ADRESU NELZE ZADAT. Na rozdíl od veřejného výkazu práce tu není žádné pole
 *    na e-mail — příjemce je pevně dán odkazem. Není tedy co enumerovat a není
 *    kam kód přesměrovat.
 *  - NEEXISTENCE SE NEROZLIŠUJE. Neznámý, prošlý, zneplatněný i dosud neodeslaný
 *    odkaz vrací jednu a tutéž odpověď, takže se z chování nedá nic dovodit.
 */
final class PayrollDocumentAccessService
{
    public const COOKIE_NAME = 'pdl_session';
    public const TEMPLATE_CODE = 'payroll_document_access_code';

    public function __construct(
        private readonly PayrollDocumentAccessLinkRepository $links,
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollDocumentStorage $storage,
        private readonly PayrollDocumentDeliveryLedgerService $ledger,
        private readonly PayrollSecureDeliveryPolicy $policy,
        private readonly PayrollDeliveryRecipientResolver $recipients,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Najde živý odkaz podle lokátoru z URL.
     *
     * Vrací null pro cokoli, co není použitelné — a volající z toho MUSÍ udělat
     * jedinou, nerozlišitelnou odpověď.
     *
     * @return array<string,mixed>|null
     */
    public function resolveLive(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $link = $this->links->findByTokenHash(hash('sha256', $token));
        if ($link === null) {
            return null;
        }
        // Odkaz smí fungovat teprve po odeslání: dokud visí ve frontě, jeho
        // lokátor se k nikomu nedostal a případný zásah je pokus o uhodnutí.
        if ($link['dispatch_state'] !== 'sent'
            || $link['revoked_at'] !== null
            || !$link['is_live']
        ) {
            return null;
        }
        return $link;
    }

    /**
     * Stav stránky. Před ověřením záměrně skoupý.
     *
     * @param array<string,mixed> $link
     * @return array<string,mixed>
     */
    public function state(array $link, ?string $sessionToken): array
    {
        $verified = $this->hasValidSession($link, $sessionToken);
        $state = [
            'verified' => $verified,
            'recipient_masked' => (string) $link['recipient_masked'],
            'code_ttl_seconds' => $this->policy->codeTtlSeconds(),
            'resend_cooldown_seconds' => $this->policy->codeResendCooldownSeconds(),
        ];
        if (!$verified) {
            return $state;
        }

        $document = $this->documents->find(
            (int) $link['supplier_id'],
            (int) $link['payroll_document_id'],
        );
        if ($document === null) {
            return $state;
        }
        // Až tady, po ověření, smí odejít cokoli o dokumentu. A pořád jen to, co
        // zaměstnanec stejně uvidí uvnitř PDF — žádné rodné číslo, žádné částky.
        $state['document'] = [
            'kind' => (string) ($document['document_kind'] ?? ''),
            'period_start' => $document['period_start'] ?? null,
            'created_at' => $document['created_at'] ?? null,
            'size_bytes' => (int) ($document['size_bytes'] ?? 0),
            'suggested_filename' => (string) ($document['suggested_filename'] ?? 'dokument.pdf'),
        ];
        return $state;
    }

    /**
     * Pošle jednorázový kód na adresu, kterou nese odkaz.
     *
     * Odpověď je vždy stejná bez ohledu na to, jestli e-mail odešel. Volající
     * nemá jak zjistit, jestli adresa existuje — a nemá to ani proč vědět.
     *
     * @param array<string,mixed> $link
     * @return array{sent:bool,cooldown_remaining:int}
     */
    public function issueCode(array $link, ?string $ip): array
    {
        $supplierId = (int) $link['supplier_id'];
        $linkId = (int) $link['id'];
        $cooldown = $this->policy->codeResendCooldownSeconds();

        $elapsed = $this->links->secondsSinceLastCode($supplierId, $linkId);
        if ($elapsed !== null && $elapsed < $cooldown) {
            return ['sent' => false, 'cooldown_remaining' => $cooldown - $elapsed];
        }

        // Předchozí kódy padají i tehdy, když nový neodejde. Ve schránce tak nikdy
        // neleží dva použitelné kódy naráz.
        $this->links->invalidateCodes($supplierId, $linkId);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->links->insertCode(
            $supplierId,
            $linkId,
            hash('sha256', $code),
            $this->policy->codeTtlSeconds(),
            $ip === null ? null : (@inet_pton($ip) ?: null),
        );

        try {
            if (!$this->policy->isChannelEnabled()) {
                throw new PayrollSecureDeliveryBlockedException(
                    'secure_delivery_disabled',
                    'Zabezpečený kanál není zapnutý.',
                );
            }
            $email = $this->recipients->plaintextEmail(
                $supplierId,
                (int) $link['employee_id'],
                (string) $link['recipient_email_hash'],
            );
            $this->mailer->sendTemplate(
                self::TEMPLATE_CODE,
                'cs',
                [$email],
                [
                    'code' => $code,
                    'expiresIn' => (string) intdiv($this->policy->codeTtlSeconds(), 60) . ' min',
                ],
            );
            unset($email);
        } catch (\Throwable $exception) {
            // Ani tady se navenek nic nemění: odpověď je pořád „sent". Rozdíl by
            // prozradil stav cizí schránky.
            $this->logger->warning('payroll.secure_delivery.code_mail_failed', [
                'link_id' => $linkId,
                'reason' => $exception instanceof PayrollSecureDeliveryBlockedException
                    ? $exception->reasonCode()
                    : 'mail_error',
            ]);
        }
        unset($code);

        return ['sent' => true, 'cooldown_remaining' => $cooldown];
    }

    /**
     * Ověří kód a při úspěchu vrátí plaintext session tokenu do cookie.
     * V DB je z něj jen sha256.
     *
     * @param array<string,mixed> $link
     */
    public function verifyCode(array $link, string $code, ?string $ip): ?string
    {
        $code = trim($code);
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return null;
        }
        $supplierId = (int) $link['supplier_id'];
        $linkId = (int) $link['id'];

        $active = $this->links->activeCode($supplierId, $linkId);
        if ($active === null) {
            return null;
        }
        $codeId = (int) $active['id'];
        if ((int) $active['attempts'] >= $this->policy->maxCodeAttempts()) {
            $this->links->markCodeUsed($supplierId, $codeId);
            return null;
        }

        if (!hash_equals((string) $active['code_hash'], hash('sha256', $code))) {
            $attempts = $this->links->bumpCodeAttempts($supplierId, $codeId);
            if ($attempts >= $this->policy->maxCodeAttempts()) {
                // Vyčerpané pokusy kód spálí. Další se dá vyžádat až po cooldownu,
                // takže hádání šestimístného čísla není průchozí cesta.
                $this->links->markCodeUsed($supplierId, $codeId);
            }
            return null;
        }

        $this->links->markCodeUsed($supplierId, $codeId);
        $sessionToken = bin2hex(random_bytes(32));
        $this->links->createSession(
            $supplierId,
            $linkId,
            hash('sha256', $sessionToken),
            $this->policy->sessionTtlSeconds(),
            $ip === null ? null : (@inet_pton($ip) ?: null),
        );
        return $sessionToken;
    }

    /** @param array<string,mixed> $link */
    public function hasValidSession(array $link, ?string $sessionToken): bool
    {
        if ($sessionToken === null
            || preg_match('/^[a-f0-9]{64}$/D', $sessionToken) !== 1
        ) {
            return false;
        }
        return $this->links->touchValidSession(
            (int) $link['supplier_id'],
            (int) $link['id'],
            hash('sha256', $sessionToken),
        );
    }

    /**
     * Vydá obsah dokumentu. Vyžaduje ověřenou relaci.
     *
     * Do evidence se zapisuje jen PRVNÍ převzetí — to je ta doložitelná událost.
     * Další otevření se počítají v `download_count`, aby evidence nezaplavila
     * historii dvaceti řádky od jednoho člověka.
     *
     * @param array<string,mixed> $link
     * @return array{bytes:string,filename:string,mime:string}
     */
    public function download(array $link, ?string $sessionToken): array
    {
        if (!$this->hasValidSession($link, $sessionToken)) {
            throw new \DomainException('Relace není ověřená.');
        }
        $supplierId = (int) $link['supplier_id'];
        $documentId = (int) $link['payroll_document_id'];

        $document = $this->documents->find($supplierId, $documentId);
        if ($document === null) {
            throw new \DomainException('Dokument nebyl nalezen.');
        }

        $bytes = $this->storage->readVerified(
            $supplierId,
            (string) $document['storage_key'],
            (int) ($document['employee_scope_id']
                ?? $document['employee_id']
                ?? PayrollDocumentKeyRing::COMPANY_SUBJECT_ID),
        );

        $firstTime = (int) $link['download_count'] === 0;
        $this->links->recordDownload($supplierId, (int) $link['id']);
        if ($firstTime) {
            $this->ledger->recordChannelEvent($supplierId, $documentId, 'self_downloaded');
        }
        $this->logger->info('payroll.secure_delivery.self_download', [
            'link_id' => (int) $link['id'],
            'document_id' => $documentId,
            'first' => $firstTime,
        ]);

        return [
            'bytes' => $bytes,
            'filename' => (string) ($document['suggested_filename'] ?? 'dokument.pdf'),
            'mime' => (string) ($document['mime_type'] ?? 'application/pdf'),
        ];
    }
}
