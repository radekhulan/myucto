<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\BankEmailAttachmentIngestRepository;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use MyInvoice\Service\Mail\Imap\ImapAuthSettings;
use MyInvoice\Service\Mail\Imap\ImapPasswordRequiredException;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankEmailNoticeAction
{
    public function __construct(
        private readonly BankEmailNoticeRepository $repo,
        private readonly BankEmailAttachmentIngestRepository $attachments,
        private readonly BankEmailNoticeParserRepository $parsers,
        private readonly BankEmailNoticeScanner $scanner,
        private readonly ImapMailboxClientInterface $imap,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CrossSupplierAuthorization $crossSupplier,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        try {
            return Json::ok($response, [
                'imap' => $this->repo->imapSettings($sid),
                'imap_accounts' => $this->withDistributionReach($this->repo->imapAccounts($sid)),
                // Kolik firem by rozdělování zapnuté tímhle uživatelem pokrylo. Pod dvě
                // firmy UI přepínač vůbec nenabízí.
                'distribution_supplier_count' => count($this->distributionSuppliers($request)),
                'providers' => array_map(
                    fn (BankEmailNoticeProvider $provider): array => $this->providerPayload($provider),
                    $this->parsers->providers(null, $sid, false),
                ),
                'mappings' => $this->repo->accountMappings($sid),
                'messages' => $this->withVisibleTargets($request, $this->repo->processedMessages($sid, 50, 0)),
                'messages_total' => $this->repo->countProcessedMessages($sid),
                'attachments' => $this->withVisibleTargets($request, $this->attachments->recent($sid, 50)),
            ]);
        } catch (\PDOException $e) {
            if (str_starts_with((string) $e->getCode(), '42S02')) {
                return Json::error(
                    $response,
                    'bank_email_schema_missing',
                    'Schéma bankovních avíz není nainstalované. Spusť migrace databáze.',
                    503,
                );
            }
            throw $e;
        }
    }

    public function updateImap(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->repo->imapSettings($sid);
        if ($denied = $this->distributionDenied($request, $response, $body, $current)) return $denied;
        try {
            $settings = $this->repo->saveImapAccount(
                $sid,
                $body,
                isset($current['id']) ? (int) $current['id'] : null,
                $this->userId($request),
            );
        } catch (ImapPasswordRequiredException $e) {
            return Json::error($response, ImapPasswordRequiredException::CODE, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->audit($request, 'bank_email.imap_updated', ['supplier_id' => $sid] + $this->distributionAudit($current, $settings));
        return Json::ok($response, $this->withDistributionReach([$settings])[0]);
    }

    public function createImapAccount(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($denied = $this->distributionDenied($request, $response, $body, null)) return $denied;
        try {
            $settings = $this->repo->saveImapAccount($sid, $body, null, $this->userId($request));
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->audit($request, 'bank_email.imap_account_created', [
            'supplier_id' => $sid,
            'imap_account_id' => $settings['id'] ?? null,
        ] + $this->distributionAudit(null, $settings));
        return Json::ok($response, $this->withDistributionReach([$settings])[0], 201);
    }

    public function updateImapAccount(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->repo->imapAccount($sid, $id);
        if ($denied = $this->distributionDenied($request, $response, $body, $current)) return $denied;
        try {
            $settings = $this->repo->saveImapAccount($sid, $body, $id, $this->userId($request));
        } catch (ImapPasswordRequiredException $e) {
            return Json::error($response, ImapPasswordRequiredException::CODE, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->audit($request, 'bank_email.imap_account_updated', [
            'supplier_id' => $sid,
            'imap_account_id' => $id,
        ] + $this->distributionAudit($current, $settings));
        return Json::ok($response, $this->withDistributionReach([$settings])[0]);
    }

    /**
     * Zapnout rozdělování smí jen uživatel, který sám zapisuje aspoň do dvou firem;
     * rozsah se pak počítá z jeho oprávnění. Úprava účtu, který už rozděluje, se
     * nekontroluje: vlastník rozdělování se jí nemění (viz repozitář).
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $current
     */
    private function distributionDenied(Request $request, Response $response, array $body, ?array $current): ?Response
    {
        $turningOn = !empty($body['distribute_to_suppliers']) && empty($current['distribute_to_suppliers']);
        if (!$turningOn || count($this->distributionSuppliers($request)) >= 2) {
            return null;
        }
        return Json::error(
            $response,
            'distribution_unavailable',
            'Rozdělování do více firem jde zapnout jen s přístupem k importu banky nebo příchozím dokladům aspoň ve dvou firmách.',
            422,
        );
    }

    /** @return list<int> firmy, do kterých smí aktuální uživatel z e-mailu cokoli zapsat */
    private function distributionSuppliers(Request $request): array
    {
        return array_values(array_unique([
            ...$this->crossSupplier->suppliersAllowing($request, ['bank.import']),
            ...$this->crossSupplier->suppliersAllowing($request, ['documents.inbox']),
        ]));
    }

    /**
     * Počet firem, do kterých účet právě rozděluje. Jen počet: správce schránky
     * nemusí mít přístup do firem vlastníka rozdělování a jejich jména mu nepatří.
     *
     * @param list<array<string,mixed>> $accounts
     * @return list<array<string,mixed>>
     */
    private function withDistributionReach(array $accounts): array
    {
        foreach ($accounts as &$account) {
            $userId = (int) ($account['distribute_user_id'] ?? 0);
            $account['distribute_supplier_count'] = !empty($account['distribute_to_suppliers']) && $userId > 0
                ? count(array_unique([
                    ...$this->crossSupplier->suppliersAllowingForUser($userId, ['bank.import']),
                    ...$this->crossSupplier->suppliersAllowingForUser($userId, ['documents.inbox']),
                ]))
                : 0;
        }
        return $accounts;
    }

    /**
     * Název firmy, do které položka ze schránky odešla, vidí jen ten, kdo do té
     * firmy sám má přístup. Ostatní správci schránky vidí jen to, že odešla jinam.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withVisibleTargets(Request $request, array $rows): array
    {
        $targets = array_filter(array_map(static fn (array $row): int => (int) ($row['target_supplier_id'] ?? 0), $rows));
        if ($targets === []) {
            return $rows;
        }
        $visible = array_flip($this->crossSupplier->suppliersAllowing($request, [], AccessLevel::READ));
        foreach ($rows as &$row) {
            $target = (int) ($row['target_supplier_id'] ?? 0);
            if ($target > 0 && !isset($visible[$target])) {
                $row['target_supplier_name'] = null;
                $row['target_hidden'] = true;
            }
        }
        return $rows;
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private function distributionAudit(?array $before, array $after): array
    {
        $was = !empty($before['distribute_to_suppliers']);
        $is = !empty($after['distribute_to_suppliers']);
        if ($was === $is) {
            return [];
        }
        return ['distribute_to_suppliers' => $is, 'distribute_user_id' => $after['distribute_user_id'] ?? null];
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    public function deleteImapAccount(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $deleted = $this->repo->deleteImapAccount($this->supplierId($request), $id);
        if (!$deleted) {
            return Json::error($response, 'not_found', 'IMAP účet nebyl nalezen.', 404);
        }
        $this->audit($request, 'bank_email.imap_account_deleted', ['imap_account_id' => $id]);
        return Json::ok($response, ['deleted' => true]);
    }

    public function testImap(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $settings = $this->repo->imapSettings($sid, true);
        $result = $this->imap->test($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    public function testImapAccount(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $settings = $this->repo->imapAccount($sid, (int) ($args['id'] ?? 0), true);
        if ($settings === null) {
            return Json::error($response, 'not_found', 'IMAP účet nebyl nalezen.', 404);
        }
        $result = $this->imap->test($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    public function browseImapFolders(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $saved = [];
        if ($id > 0) {
            $saved = $this->repo->imapAccount($sid, $id, true);
            if ($saved === null) {
                return Json::error($response, 'not_found', 'IMAP účet nebyl nalezen.', 404);
            }
        }

        try {
            $settings = $this->imapProbeSettings($saved, $body);
        } catch (ImapPasswordRequiredException $e) {
            return Json::error($response, ImapPasswordRequiredException::CODE, $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $settings['supplier_id'] = $sid;
        $settings['id'] = $id > 0 ? $id : null;
        $result = $this->imap->test($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    public function createProvider(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        try {
            $provider = $this->repo->saveProvider($this->supplierId($request), (array) ($request->getParsedBody() ?? []));
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->audit($request, 'bank_email.provider_created', ['provider_id' => $provider['id']]);
        return Json::ok($response, $provider, 201);
    }

    public function updateProvider(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        try {
            $provider = $this->repo->saveProvider(
                $this->supplierId($request),
                (array) ($request->getParsedBody() ?? []),
                (int) ($args['id'] ?? 0),
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->audit($request, 'bank_email.provider_updated', ['provider_id' => $provider['id']]);
        return Json::ok($response, $provider);
    }

    public function deleteProvider(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $deleted = $this->repo->deleteProvider($this->supplierId($request), (int) ($args['id'] ?? 0));
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Provider nenalezen nebo nejde smazat.', 404);
        }
        $this->audit($request, 'bank_email.provider_deleted', ['provider_id' => (int) ($args['id'] ?? 0)]);
        return Json::ok($response, ['deleted' => true]);
    }

    public function updateMappings(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = isset($body['mappings']) && is_array($body['mappings']) ? $body['mappings'] : [];
        $this->repo->saveAccountMappings($sid, $rows, $this->parsers->systemProviderCodes());
        $this->audit($request, 'bank_email.mappings_updated', ['supplier_id' => $sid, 'count' => count($rows)]);
        return Json::ok($response, $this->repo->accountMappings($sid));
    }

    public function testParser(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        // Banky, které datum platby v těle avíza neuvádějí (Česká spořitelna, Fio),
        // berou posted_at z data doručení e-mailu. V testovacím nástroji žádný
        // skutečný e-mail není, proto ho simulujeme zadaným, jinak aktuálním časem —
        // bez toho by test hlásil chybějící posted_at, i když by avízo v ostrém
        // provozu prošlo (#147).
        $messageDate = null;
        $dateRaw = trim((string) ($body['date'] ?? ''));
        if ($dateRaw !== '') {
            try {
                $messageDate = new \DateTimeImmutable($dateRaw);
            } catch (\Throwable) {
                $messageDate = null;
            }
        }
        $messageDate ??= new \DateTimeImmutable();
        $message = new BankEmailNoticeMessage(
            uid: null,
            messageId: trim((string) ($body['message_id'] ?? '')) ?: null,
            date: $messageDate,
            sender: trim((string) ($body['sender'] ?? 'info@rb.cz')),
            subject: trim((string) ($body['subject'] ?? 'Pohyb na účtě')),
            text: (string) ($body['text'] ?? ''),
            raw: (string) ($body['text'] ?? ''),
            allowForwarded: !empty($body['allow_forwarded']),
            forwardedFrom: trim((string) ($body['forwarded_from'] ?? '')),
        );
        $preferredRef = !empty($body['provider_ref']) ? (string) $body['provider_ref'] : null;
        try {
            $parsed = $this->parsers->parse(
                $message,
                $preferredRef,
                $this->supplierId($request),
                // Explicitně vybraný provider jde otestovat i vypnutý (admin ladí
                // konfiguraci před zapnutím); auto-detekce kopíruje scan = jen enabled.
                enabledOnly: $preferredRef === null,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', $e->getMessage(), 400);
        }
        return Json::ok($response, [
            'provider' => $this->providerPayload($parsed['provider']),
            'parsed' => $parsed['parsed']->toArray(),
        ]);
    }

    public function scan(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $summary = $this->scanner->scanSupplier(
            $this->supplierId($request),
            isset($body['limit']) ? (int) $body['limit'] : null,
        );
        $this->audit($request, 'bank_email.scan_requested', $summary);
        return Json::ok($response, $summary);
    }

    public function messages(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $q = $request->getQueryParams();
        $limit = 50;
        $page = max(1, (int) ($q['page'] ?? 1));
        return Json::ok($response, [
            'items' => $this->withVisibleTargets($request, $this->repo->processedMessages($sid, $limit, ($page - 1) * $limit)),
            'total' => $this->repo->countProcessedMessages($sid),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function deleteMessage(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $deleted = $this->repo->deleteProcessedMessage($this->supplierId($request), $id);
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Záznam zpracování nebyl nalezen.', 404);
        }
        $this->audit($request, 'bank_email.processed_message_deleted', ['id' => $id]);
        return Json::ok($response, ['deleted' => true]);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function admin(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, 'bank.import', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * @param array<string,mixed> $saved
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function imapProbeSettings(array $saved, array $body): array
    {
        $settings = array_merge($saved, $body);
        $formPassword = trim((string) ($body['password'] ?? ''));
        $usesSavedPassword = false;
        if ($formPassword !== '') {
            $settings['password'] = (string) $body['password'];
        } elseif (isset($saved['password'])) {
            $settings['password'] = (string) $saved['password'];
            $usesSavedPassword = true;
        } else {
            $settings['password'] = '';
        }

        $settings = array_replace($settings, ImapAuthSettings::normalize($settings, $saved));
        if ($usesSavedPassword && $settings['auth_mode'] === ImapAuthSettings::PASSWORD) {
            ImapAuthSettings::assertPasswordTargetUnchanged($saved, $settings);
        }
        return $settings;
    }

    /**
     * @return array{
     *   id:?int,
     *   supplier_id:?int,
     *   provider_ref:string,
     *   code:string,
     *   name:string,
     *   parser_type:string,
     *   enabled:bool,
     *   sender_whitelist:?string,
     *   subject_pattern:?string,
     *   body_pattern:?string,
     *   field_patterns:array<string,mixed>,
     *   normalizer_config:array<string,mixed>,
     *   system:bool
     * }
     */
    private function providerPayload(BankEmailNoticeProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'supplier_id' => $provider->supplierId,
            'provider_ref' => $provider->providerRef,
            'code' => $provider->code,
            'name' => $provider->name,
            'parser_type' => $provider->parserType,
            'enabled' => $provider->enabled,
            'sender_whitelist' => $provider->senderWhitelist,
            'subject_pattern' => $provider->subjectPattern,
            'body_pattern' => $provider->bodyPattern,
            'field_patterns' => $provider->fieldPatterns,
            'normalizer_config' => $provider->normalizerConfig,
            'system' => $provider->system,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function audit(Request $request, string $action, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'supplier', $this->supplierId($request), $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
