<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Souhlas České spořitelny sdílený mezi firmami instalace (`bank_connectors.csas.shared_consent`).
 *
 * Skupina firem nebo účetní často spravuje účty více firem v jednom prostoru George Business
 * a souhlas udělený jedné firmě pak banka vydává i pro účty ostatních. Účet ČS jiné firmy
 * instalace, který takový souhlas pokrývá a který nemá vlastní napojení, se napojí přes něj
 * ({@see CsasSiblingAccountLinker::linkShared()}). Nosnou se stává firma, jejíž souhlas účet
 * napojil jako první; banka se pak volá jedním tokenem a obnova přístupu i seznam účtů
 * proběhnou jednou pro všechny firmy souhlasu.
 *
 * Napojení převzaté ze souhlasu jiné firmy umí jen číst. Platby vyžadují vlastní souhlas firmy.
 * Účet, který má firma připojený vlastním souhlasem, nebo který uživatel odpojil, se nepřebírá.
 *
 * Nastavení: výchozí (nevyplněno) = sdílí každý souhlas; `false` = nesdílí se; `['supplier_id' => N]`
 * = sdílí jen souhlas firmy N.
 */
final class CsasSharedConsent
{
    public function __construct(private readonly Config $config) {}

    /** Smí souhlas firmy napojovat účty ostatních firem? Chybná konfigurace sdílení vypne. */
    public function allows(int $ownerSupplierId): bool
    {
        $value = $this->config->get('bank_connectors.csas.shared_consent', true);
        if ($value === true || $value === null) return true;
        if (!is_array($value)) return false;
        $pinned = $value['supplier_id'] ?? null;
        if ($pinned === null) return true;
        $id = filter_var($pinned, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id !== false && $id === $ownerSupplierId;
    }

    /** Je sdílení v instalaci vypnuté úplně? */
    public function disabled(): bool
    {
        $value = $this->config->get('bank_connectors.csas.shared_consent', true);
        return !($value === true || $value === null || is_array($value));
    }
}
