<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/rebuild-snapshots (jen admin)
 *
 * „Obnovit údaje stran" — lehčí alternativa k odemčení celého formuláře:
 * přepíše POUZE snapshoty (dodavatel/odběratel/banka) z aktuálních živých dat,
 * a to i u uzamčeného dokladu. Částky, stav, variabilní symbol ani data
 * zůstávají nedotčené, nic se nepřepočítává.
 *
 * K čemu to je: odběratel změní adresu nebo přejde do skupinové registrace DPH
 * a je potřeba, aby nově generované PDF a exporty nesly aktuální údaje. Force-edit
 * celého dokladu je na to zbytečně hrubý nástroj — otevře i částky.
 *
 * NA VÝKAZY DPH TO VLIV NEMÁ. VatLedgerService čte DIČ i název protistrany
 * z živé tabulky `clients`, ne ze snapshotů, takže kontrolní hlášení i přiznání
 * jsou správně i bez téhle akce. Projeví se jen v PDF a v exportech, které
 * snapshoty čtou (ISDOC, Pohoda).
 *
 * Zároveň se ZNEPLATNÍ vygenerované PDF (staré se archivuje). U dokladu
 * v už podaném období se tedy nově vygenerované PDF může lišit od toho, které
 * odběratel dostal — proto jen admin a proto plná auditní stopa.
 *
 * Stornovaný doklad zůstává nedotknutelný (auditní stopa). Koncept snapshoty
 * nepoužívá — renderer u něj bere živá data a čerstvé snapshoty vzniknou až
 * vystavením, takže by akce byla matoucí no-op.
 */
final class RebuildInvoiceSnapshotsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!RequestAuthorization::isCompanyAdmin($request)) {
            return Json::error($response, 'forbidden', 'Obnovit údaje stran na dokladu smí jen administrátor.', 403);
        }

        $status = (string) ($existing['status'] ?? '');
        if ($status === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze měnit (auditní stopa).', 409);
        }
        if ($status === 'draft') {
            return Json::error($response, 'not_applicable',
                'Koncept snapshoty nepoužívá — údaje stran se doplní živé při vystavení.', 409);
        }

        $before = self::snapshotTriplet($existing);

        $this->pdf->rebuildSnapshots($id);
        // Vygenerované PDF nese původní údaje → zneplatnit, ať se vyrobí znovu.
        $this->pdf->invalidate($id, 'invalidate_update');

        $invoice = $this->repo->find($id);
        $after = self::snapshotTriplet($invoice ?? []);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            'invoice.rebuild_snapshots',
            $user['id'] ?? null,
            'invoice',
            $id,
            ['old_snapshot' => $before, 'new_snapshot' => $after, 'changed' => self::changedParts($before, $after)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, $invoice);
    }

    /**
     * Všechny tři snapshoty dokladu v dekódované podobě. Banka je součástí —
     * writeSnapshots() ji přepisuje taky a u změny bankovního spojení je to
     * ta jediná viditelná změna.
     *
     * @param array<string,mixed> $invoice
     * @return array{supplier:?array<string,mixed>, client:?array<string,mixed>, bank:?array<string,mixed>}
     */
    private static function snapshotTriplet(array $invoice): array
    {
        return [
            'supplier' => self::decodeSnapshot($invoice['supplier_snapshot'] ?? null),
            'client'   => self::decodeSnapshot($invoice['client_snapshot'] ?? null),
            'bank'     => self::decodeSnapshot($invoice['bank_snapshot'] ?? null),
        ];
    }

    /**
     * Které části se opravdu změnily — auditní payload jinak nese dva velké
     * objekty a z historie není poznat, jestli akce něco udělala.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return list<string>
     */
    private static function changedParts(array $before, array $after): array
    {
        $changed = [];
        foreach (['supplier', 'client', 'bank'] as $part) {
            if (($before[$part] ?? null) !== ($after[$part] ?? null)) {
                $changed[] = $part;
            }
        }
        return $changed;
    }

    /**
     * JSON snapshot z DB → pole pro auditní payload; neparsovatelný → null.
     *
     * @return array<string,mixed>|null
     */
    private static function decodeSnapshot(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
