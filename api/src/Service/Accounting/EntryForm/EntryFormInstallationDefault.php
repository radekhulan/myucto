<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\EntryForm;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\ActivityLogger;

/**
 * Výchozí forma účetního zápisu instalace (`accounting.default_entry_form`, ENV
 * `MYINVOICE_ACCOUNTING_DEFAULT_ENTRY_FORM`) pro nově založené nebo nově aktivované firmy.
 *
 * Výchozí `composite` nedělá nic: firma bez řádku historie účtuje složeně jako dosud.
 * `single` zapíše nové firmě v podvojném účetnictví jednoduchou formu od začátku
 * ({@see EntryFormService::seedInstallationDefault()}). Volají ji všechny cesty, kudy firma
 * do podvojného účetnictví vstupuje: dokončení profilu po založení (aplikace, prvotní
 * setup, převody z cizích programů), aktivace účetnictví průvodcem, přepnutí režimu
 * v nastavení a převod účetní jednotky z Money S3.
 */
final class EntryFormInstallationDefault
{
    public const CONFIG_KEY = 'accounting.default_entry_form';

    private ?EntryFormService $entryForms;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        ?EntryFormService $entryForms = null,
    ) {
        $this->entryForms = $entryForms;
    }

    /** Výchozí forma instalace; neznámá hodnota = složený zápis. */
    public function form(): EntryForm
    {
        $raw = strtolower(trim((string) $this->config->get(self::CONFIG_KEY, EntryForm::Composite->value)));
        return EntryForm::tryFrom($raw) ?? EntryForm::Composite;
    }

    /**
     * Zapíše výchozí formu firmě, pokud je instalace nastavená na jednoduchý zápis,
     * firma vede podvojné účetnictví a dosud nemá formu ani zápis v deníku.
     * `$activating` = průvodce aktivací, který podvojné účetnictví teprve zapíná
     * (režim se přepne až po doúčtování historie, ta už ale jde v nové formě).
     *
     * @return int|null id řádku historie, null = beze změny
     */
    public function apply(int $supplierId, ?int $actor = null, bool $activating = false): ?int
    {
        $form = $this->form();
        if ($form !== EntryForm::Single) {
            return null;
        }
        if (!$activating && !(new AccountingModeRepository($this->db))->hasDoubleEntry($supplierId)) {
            return null;
        }

        return $this->entryForms()->seedInstallationDefault($supplierId, $form, $actor);
    }

    private function entryForms(): EntryFormService
    {
        return $this->entryForms ??= new EntryFormService(
            $this->db,
            new ActivityLogger($this->db, new ActivityLogHashChain($this->db)),
            new AccountingModeRepository($this->db),
            new FrameworkScopePolicy($this->db),
        );
    }
}
