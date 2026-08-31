<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\MailDeliveredArchiveException;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SentMailImapAppender;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EmailProfilesAction
{
    public function __construct(
        private readonly EmailProfileRepository $profiles,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly Mailer $mailer,
        private readonly SentMailImapAppender $imap,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        // H-02: ve spravované instalaci píše SMTP účet hosting a obálku určuje
        // jeho MTA — na ní stojí SPF. Vlastní transport by prošel jen napůl.
        private readonly ManagedModeGuard $managed,
    ) {}

    /**
     * Vlastní odesílací transport (`smtp`, `sendmail`) je ve spravované instalaci
     * zamčený; `global` = odesílá hosting, což je jediná varianta, u které sedí SPF.
     *
     * Kontroluje se hodnota z těla požadavku, ne jen to, co ukáže UI: zamčené
     * pole ve formuláři není zámek.
     *
     * @param array<string,mixed> $body
     */
    private function denyCustomTransport(Response $response, array $body): ?Response
    {
        $transport = strtolower(trim((string) ($body['transport_type'] ?? 'global')));
        if ($transport === '' || $transport === 'global') {
            return null;
        }

        return $this->managed->deny($response, ManagedModeGuard::CAPABILITY_MAIL_TRANSPORT);
    }

    public function list(Request $request, Response $response): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $profiles = array_map(
            fn (array $profile): array => $this->profileForResponse($request, $profile),
            $this->profiles->listProfiles($this->supplierId($request)),
        );
        return Json::ok($response, $profiles);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (($restricted = $this->denyClientRestrictedFields($request, $response, $body)) !== null) {
            return $restricted;
        }
        if (($locked = $this->denyCustomTransport($response, $body)) !== null) {
            return $locked;
        }

        try {
            $id = $this->profiles->createProfile($supplierId, $body, $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'profile_conflict', 'E-mailový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'create_failed', 'E-mailový profil se nepodařilo vytvořit.', 500);
        } catch (\Throwable) {
            return Json::error($response, 'create_failed', 'E-mailový profil se nepodařilo vytvořit.', 500);
        }

        $profile = $this->profiles->findProfile($supplierId, $id);
        $this->log($request, 'email_profile.created', $id, [
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
            'reply_to_enabled' => $profile['reply_to_enabled'] ?? false,
            'dkim_enabled' => $profile['dkim_enabled'] ?? false,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'imap_sent_enabled' => $profile['imap_sent_enabled'] ?? false,
            'imap_folder' => $profile['imap_folder'] ?? null,
            'imap_on_failure' => $profile['imap_on_failure'] ?? 'log_only',
            'is_default' => $profile['is_default'] ?? false,
            'signing_profile_id' => $profile['signing_profile_id'] ?? null,
        ]);

        return Json::ok($response, $this->profileForResponse($request, $profile), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $current = $this->profiles->findProfile($supplierId, $profileId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }
        if (($restricted = $this->denyClientRestrictedProfile($request, $response, $current)) !== null) {
            return $restricted;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (($restricted = $this->denyClientRestrictedFields($request, $response, $body)) !== null) {
            return $restricted;
        }
        if (($locked = $this->denyCustomTransport($response, $body)) !== null) {
            return $locked;
        }
        try {
            $this->profiles->updateProfile($supplierId, $profileId, $body, RequestAuthorization::isClientType($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'profile_conflict', 'E-mailový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'update_failed', 'E-mailový profil se nepodařilo uložit.', 500);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'E-mailový profil se nepodařilo uložit.', 500);
        }

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        $this->log($request, 'email_profile.updated', $profileId, [
            'changed_fields' => array_values(array_filter(
                array_keys($body),
                static fn (string $field): bool => !in_array($field, ['smtp_password', 'imap_password'], true),
            )),
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
            'reply_to_enabled' => $profile['reply_to_enabled'] ?? false,
            'dkim_enabled' => $profile['dkim_enabled'] ?? false,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'imap_sent_enabled' => $profile['imap_sent_enabled'] ?? false,
            'imap_folder' => $profile['imap_folder'] ?? null,
            'imap_on_failure' => $profile['imap_on_failure'] ?? 'log_only',
            'is_default' => $profile['is_default'] ?? false,
            'signing_profile_id' => $profile['signing_profile_id'] ?? null,
        ]);

        return Json::ok($response, $this->profileForResponse($request, $profile));
    }

    public function test(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId, false, true);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }
        if (($restricted = $this->denyClientRestrictedProfile($request, $response, $profile)) !== null) {
            return $restricted;
        }

        return $this->sendProfileTest($request, $response, $profile, $profileId, false);
    }

    public function testDraft(Request $request, Response $response): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $profileId = isset($body['id']) && (int) $body['id'] > 0 ? (int) $body['id'] : null;
        if ($profileId === null && isset($body['profile_id']) && (int) $body['profile_id'] > 0) {
            $profileId = (int) $body['profile_id'];
        }

        $profileData = isset($body['profile']) && is_array($body['profile'])
            ? (array) $body['profile']
            : $body;
        unset($profileData['id'], $profileData['profile_id'], $profileData['profile']);
        if (($restricted = $this->denyClientRestrictedFields($request, $response, $profileData)) !== null) {
            return $restricted;
        }
        // Zámek vlastního transportu musí platit i tady. Bez něj by šlo ve
        // spravované instalaci protlačit vlastní SMTP testem, který `create`
        // a `update` odmítají.
        if (($locked = $this->denyCustomTransport($response, $profileData)) !== null) {
            return $locked;
        }

        try {
            $profile = $this->profiles->profileForDraftTest(
                $supplierId,
                $profileData,
                $profileId,
                RequestAuthorization::isClientType($request),
            );
        } catch (\InvalidArgumentException $e) {
            if ($profileId !== null && $this->profiles->findProfile($supplierId, $profileId) === null) {
                return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
            }
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'Testovací e-mailový profil se nepodařilo připravit.', 400);
        }
        if (($restricted = $this->denyClientRestrictedProfile($request, $response, $profile)) !== null) {
            return $restricted;
        }

        return $this->sendProfileTest($request, $response, $profile, $profileId, true);
    }

    public function browseImapFolders(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }
        if (($restricted = $this->denyClientRestrictedImapProfile($request, $response, $args)) !== null) {
            return $restricted;
        }

        try {
            $settings = $this->imapProbeSettings($request, $args);
        } catch (\InvalidArgumentException $e) {
            return $this->imapProbeError($request, $response, $args, $e);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'IMAP nastavení se nepodařilo připravit.', 400);
        }

        $result = $this->imap->folders($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    public function testImapSettings(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }
        if (($restricted = $this->denyClientRestrictedImapProfile($request, $response, $args)) !== null) {
            return $restricted;
        }

        try {
            $settings = $this->imapProbeSettings($request, $args);
        } catch (\InvalidArgumentException $e) {
            return $this->imapProbeError($request, $response, $args, $e);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'IMAP nastavení se nepodařilo připravit.', 400);
        }

        $result = $this->imap->test($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    /**
     * @return array<string,mixed>
     */
    private function imapProbeSettings(Request $request, array $args = []): array
    {
        $supplierId = $this->supplierId($request);
        $profileId = $this->imapProfileId($request, $args);
        $body = (array) ($request->getParsedBody() ?? []);

        $profileData = isset($body['profile']) && is_array($body['profile'])
            ? (array) $body['profile']
            : $body;
        unset($profileData['id'], $profileData['profile_id'], $profileData['profile']);

        return $this->profiles->imapProbeSettingsForDraft(
            $supplierId,
            $profileData,
            $profileId,
            RequestAuthorization::isClientType($request),
        );
    }

    private function imapProbeError(Request $request, Response $response, array $args, \InvalidArgumentException $e): Response
    {
        $profileId = $this->imapProfileId($request, $args);
        if ($profileId !== null && $this->profiles->findProfile($this->supplierId($request), $profileId) === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }

        return Json::error($response, 'validation_failed', $e->getMessage(), 400);
    }

    private function denyClientRestrictedImapProfile(
        Request $request,
        Response $response,
        array $args,
    ): ?Response {
        if (!RequestAuthorization::isClientType($request)) return null;

        $profileId = $this->imapProfileId($request, $args);
        if ($profileId === null) return null;
        $profile = $this->profiles->findProfile($this->supplierId($request), $profileId);
        if ($profile === null) return null;

        return $this->denyClientRestrictedProfile($request, $response, $profile);
    }

    private function imapProfileId(Request $request, array $args): ?int
    {
        if (isset($args['id']) && (int) $args['id'] > 0) return (int) $args['id'];

        $body = (array) ($request->getParsedBody() ?? []);
        if (isset($body['id']) && (int) $body['id'] > 0) return (int) $body['id'];
        if (isset($body['profile_id']) && (int) $body['profile_id'] > 0) return (int) $body['profile_id'];
        return null;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function sendProfileTest(Request $request, Response $response, array $profile, ?int $profileId, bool $draft): Response
    {
        $supplierId = $this->supplierId($request);
        $supplier = $this->supplierForEmail($supplierId);
        $recipient = $this->testRecipient($request, $supplier);
        if ($recipient === null) {
            return Json::error($response, 'no_test_recipient', 'Nelze určit testovací e-mail příjemce.', 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $locale = in_array(($user['locale'] ?? 'cs'), ['cs', 'en'], true) ? (string) $user['locale'] : 'cs';
        $smtpResponse = '';
        $imapAppend = ['status' => 'skipped', 'folder' => null, 'error' => null];

        try {
            $sendResult = $this->mailer->sendTemplateDetailed(
                'email_profile_test',
                $locale,
                [$recipient],
                [
                    'supplier' => $supplier,
                    'profile' => $this->profileTestVars($profile),
                ],
                null,
                [],
                [],
                [],
                $this->userId($request),
                $profile,
            );
            $smtpResponse = (string) ($sendResult['smtp_response'] ?? '');
            $imapAppend = is_array($sendResult['imap_append'] ?? null)
                ? $sendResult['imap_append']
                : $imapAppend;
        } catch (MailDeliveredArchiveException $e) {
            $smtpResponse = $e->smtpResponse();
            $imapAppend = $e->imapAppend();
        } catch (\Throwable $e) {
            $this->log($request, 'email.profile_test_failed', $profileId, [
                'code' => $profile['code'] ?? null,
                'to' => $recipient,
                'draft' => $draft,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return Json::error($response, 'send_failed', 'Testovací e-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $sentAt = date('Y-m-d H:i:s');
        $this->log($request, 'email.sent_profile_test', $profileId, [
            'code' => $profile['code'] ?? null,
            'to' => $recipient,
            'draft' => $draft,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'smtp_response' => $smtpResponse,
            'imap_append_status' => $imapAppend['status'] ?? 'skipped',
            'imap_append_folder' => $imapAppend['folder'] ?? null,
            'imap_append_error' => $imapAppend['error'] ?? null,
        ]);

        return Json::ok($response, [
            'sent_to' => [$recipient],
            'sent_at' => $sentAt,
            'smtp_response' => $smtpResponse,
            'imap_append' => $imapAppend,
            'is_test' => true,
            'is_draft' => $draft,
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat e-mailové profily.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }
        if (($restricted = $this->denyClientRestrictedProfile($request, $response, $profile)) !== null) {
            return $restricted;
        }

        $brandingNames = $this->profiles->brandingProfileUsages($supplierId, $profileId);
        if ($brandingNames !== []) {
            return Json::error($response, 'profile_in_use', 'E-mailový profil používají brandingové profily: ' . implode(', ', $brandingNames) . '. Nejprve jim nastav jiného odesílatele.', 409);
        }

        $this->profiles->softDeleteProfile($supplierId, $profileId);
        $this->log($request, 'email_profile.deleted', $profileId, [
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
        ]);

        return Json::ok($response, ['deleted' => true]);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function canManage(Request $request): bool
    {
        return OperationalSettingsAccess::emailProfiles($request);
    }

    /**
     * Elektronické podpisy a lokální sendmail nejsou součástí klientsky
     * delegovaného nastavení. Kontrola je v akci, ne jen ve formuláři.
     *
     * @param array<string,mixed> $body
     */
    private function denyClientRestrictedFields(Request $request, Response $response, array $body): ?Response
    {
        if (!RequestAuthorization::isClientType($request)) return null;

        if (array_key_exists('signing_profile_id', $body)) {
            return Json::error(
                $response,
                'field_not_delegable',
                'Elektronické podepisování není součástí delegovaného nastavení firmy.',
                403,
            );
        }
        if (array_key_exists('sendmail_command', $body)
            || strtolower(trim((string) ($body['transport_type'] ?? ''))) === 'sendmail'
        ) {
            return Json::error(
                $response,
                'field_not_delegable',
                'Lokální sendmail není součástí delegovaného nastavení firmy.',
                403,
            );
        }
        return null;
    }

    /**
     * Profily napojené na systémový sendmail nebo kryptografické S/MIME identity
     * zůstávají ve správě staff role. Klient je vidí v seznamu, ale nemůže je
     * testovat, měnit ani smazat.
     *
     * @param array<string,mixed> $profile
     */
    private function denyClientRestrictedProfile(Request $request, Response $response, array $profile): ?Response
    {
        if (!RequestAuthorization::isClientType($request)) return null;

        if (($profile['signing_profile_id'] ?? null) !== null
            || strtolower((string) ($profile['transport_type'] ?? 'global')) === 'sendmail'
        ) {
            return Json::error(
                $response,
                'profile_not_delegable',
                'Tento pokročilý e-mailový profil může spravovat pouze správce instalace.',
                403,
            );
        }
        return null;
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array<string,mixed>|null
     */
    private function profileForResponse(Request $request, ?array $profile): ?array
    {
        if ($profile === null || !RequestAuthorization::isClientType($request)) return $profile;

        $profile['client_manageable'] = ($profile['signing_profile_id'] ?? null) === null
            && strtolower((string) ($profile['transport_type'] ?? 'global')) !== 'sendmail';
        unset(
            $profile['signing_profile_id'],
            $profile['signing_profile_name'],
            $profile['signing_profile_code'],
            $profile['sendmail_command'],
        );
        return $profile;
    }

    /**
     * @return array<string,mixed>
     */
    private function supplierForEmail(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, COALESCE(bp.display_name, s.display_name) AS display_name,
                    COALESCE(bp.tagline, s.tagline) AS tagline, s.street, s.city, s.zip,
                    COALESCE(bp.email, s.email) AS email, COALESCE(bp.phone, s.phone) AS phone,
                    COALESCE(bp.web, s.web) AS web,
                    COALESCE(bp.branding_enabled, s.email_branding_enabled) AS email_branding_enabled,
                    COALESCE(bp.accent_color, s.email_accent_color) AS email_accent_color,
                    COALESCE(bp.logo_path, s.logo_path) AS logo_path, bp.id AS branding_profile_id,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN branding_profiles bp ON s.branding_profiles_enabled = 1 AND bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id AND bp.is_active = 1
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['id' => $supplierId];
        }

        $row['id'] = (int) $row['id'];
        $row['email_branding_enabled'] = (int) ($row['email_branding_enabled'] ?? 0) === 1;
        $row['email_accent_color'] = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['accent_soft'] = AccentColor::emailBackground(
            (bool) $row['email_branding_enabled'],
            $row['email_accent_color'],
        );

        return $row;
    }

    /**
     * @param array<string,mixed> $supplier
     */
    private function testRecipient(Request $request, array $supplier): ?string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        foreach ([
            (string) ($user['email'] ?? ''),
            (string) ($supplier['email'] ?? ''),
            (string) $this->config->get('smtp.from_email', ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,string>
     */
    private function profileTestVars(array $profile): array
    {
        $from = trim((string) ($profile['from_email'] ?? ''));
        $fromName = trim((string) ($profile['from_name'] ?? ''));
        if ($fromName !== '') {
            $from = $fromName . ' <' . $from . '>';
        }

        $replyTo = 'From';
        if (($profile['reply_to_enabled'] ?? false) && !empty($profile['reply_to_email'])) {
            $replyTo = trim((string) ($profile['reply_to_email'] ?? ''));
            $replyToName = trim((string) ($profile['reply_to_name'] ?? ''));
            if ($replyToName !== '') {
                $replyTo = $replyToName . ' <' . $replyTo . '>';
            }
        }

        return [
            'name' => (string) ($profile['name'] ?? ''),
            'code' => (string) ($profile['code'] ?? ''),
            'from' => $from,
            'reply_to' => $replyTo,
            'transport' => $this->transportLabel($profile),
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function transportLabel(array $profile): string
    {
        return match ((string) ($profile['transport_type'] ?? 'global')) {
            'smtp' => trim((string) ($profile['smtp_host'] ?? '')) !== ''
                ? sprintf('SMTP %s:%d', (string) $profile['smtp_host'], (int) ($profile['smtp_port'] ?? 587))
                : 'SMTP',
            'sendmail' => trim((string) ($profile['sendmail_command'] ?? '')) !== ''
                ? 'sendmail: ' . trim((string) $profile['sendmail_command'])
                : 'sendmail',
            default => 'cfg.php',
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, ?int $profileId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'email_profile',
            $profileId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->supplierId($request),
        );
    }

    private function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
