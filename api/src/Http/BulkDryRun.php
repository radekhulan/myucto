<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Běh nanečisto hromadné akce nad akcí jednoho záznamu (smazání faktury, přijaté
 * faktury). Akce projde všechny své kontroly i storno zápisu v deníku ve vlastní
 * transakci a těsně před prvním krokem, který nejde vrátit (mazání souborů, PDF),
 * transakci odvolá a vrátí `{ok, dry_run}`. Náhled tak rozhoduje týmž kódem jako
 * ostré smazání.
 *
 * Atribut nastavuje jen kód serveru ({@see \MyInvoice\Service\Accounting\Bulk\DeleteOperation}),
 * z HTTP požadavku ho podvrhnout nejde. Akce volaná uvnitř cizí transakce ji
 * neodvolává; to pak musí volající.
 */
final class BulkDryRun
{
    public const ATTR = 'bulk.dry_run';

    public static function requested(Request $request): bool
    {
        return $request->getAttribute(self::ATTR) === true;
    }
}
