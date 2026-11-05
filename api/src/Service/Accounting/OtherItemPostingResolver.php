<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemKindRepository;
use MyInvoice\Repository\OtherItemPostingProfileRepository;

/**
 * Účinná kontace ostatní položky pro účetní událost (migrace 2238). Jediné místo, kde se
 * rozhoduje, odkud kontace pochází, ať ji zaúčtování, náhled v detailu i párování
 * z banky čtou stejně.
 *
 * Přednost: vlastní kontace položky > účetní profil druhu > výchozí kontace aplikace.
 *
 *   * Vznik (`origin`) se skládá po částech, protože položka svou kontaci vzniku nese
 *     ve vlastních sloupcích odjakživa: účet položky (account_code) a protiúčet
 *     (posting_lines). Prázdnou část doplní profil druhu, účet položky bez profilu
 *     výchozí 315/325. Protiúčet bez položky i profilu chybí (zaúčtování ho vyžádá).
 *     Položka bez profilu druhu proto účtuje přesně jako dřív.
 *   * Úhrady (`payment_bank`, `payment_cash`) se přebírají celé: vlastní kontace úhrady
 *     položky, jinak aktivní profil druhu, jinak výchozí 221 / 211. Strana položky je
 *     u úhrady vždy účet položky, jinak by úhrada minula saldokonto (hlídá to už
 *     uložení profilu, {@see OtherItemPostingProfileService}).
 *
 * Daňová evidence nic z toho nepoužívá: položku jen potvrdí a úhradu neúčtuje.
 */
final class OtherItemPostingResolver
{
    public const SOURCE_ITEM = 'item';
    public const SOURCE_KIND = 'kind';
    public const SOURCE_DEFAULT = 'default';

    /** Výchozí peněžní účet úhrady: 221 doplní analytika podle účtu výpisu, 211 pokladna. */
    public const DEFAULT_MONEY_ACCOUNT = ['payment_bank' => '221', 'payment_cash' => '211'];

    /** Zástupné znaky v popisu zápisu. */
    public const PLACEHOLDERS = ['title', 'document_no', 'partner', 'variable_symbol'];

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemKindRepository $kinds,
        private readonly OtherItemPostingProfileRepository $profiles,
    ) {}

    public function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== 'tax_evidence';
    }

    /** Výchozí účet položky, když ho nenese položka ani profil druhu. */
    public static function defaultItemAccount(string $side): string
    {
        return $side === 'receivable' ? '315' : '325';
    }

    /**
     * Sloupec profilu, na kterém stojí účet položky. Vznik pohledávky jde na MD, úhrada
     * pohledávky na D; u závazku obráceně.
     */
    public static function itemColumn(OtherItemPostingEvent $event, string $side): string
    {
        $debit = $event === OtherItemPostingEvent::Origin ? $side === 'receivable' : $side !== 'receivable';
        return $debit ? 'debit_account_code' : 'credit_account_code';
    }

    public static function otherColumn(OtherItemPostingEvent $event, string $side): string
    {
        return self::itemColumn($event, $side) === 'debit_account_code' ? 'credit_account_code' : 'debit_account_code';
    }

    /**
     * Řádek druhu položky: vlastní druh podle kind_id, vestavěný podle kódu a strany
     * (jen když má řádek nastavení). Výchozí druh „ostatní" profil nemá.
     *
     * @param array<string,mixed> $item
     */
    public function kindIdOf(int $supplierId, array $item): ?int
    {
        if (($item['kind_id'] ?? null) !== null) {
            return (int) $item['kind_id'];
        }
        $code = (string) ($item['kind'] ?? 'other');
        if (!in_array($code, OtherItemKindService::BUILTIN_CODES, true)) {
            return null;
        }
        $kind = $this->kinds->findBuiltin($supplierId, (string) $item['side'], $code);
        return $kind === null ? null : (int) $kind['id'];
    }

    /**
     * Kontace vzniku po částech (účet položky, protiřádky) s jejich původem.
     *
     * @param array<string,mixed> $item řádek položky včetně posting_lines a amount_czk
     * @return array{event:string,source:string,account_code:string,account_source:string,
     *   posting_lines:list<array{account_code:string,amount:float}>,counter_source:?string,
     *   description:string,description_template:?string,dimensions:list<array{type_id:int,value_id:int}>,
     *   kind_id:?int,profile_id:?int}
     */
    public function origin(int $supplierId, array $item): array
    {
        $side = (string) $item['side'];
        $kindId = $this->kindIdOf($supplierId, $item);
        $profile = $kindId === null ? null
            : $this->profiles->activeProfile($supplierId, $kindId, OtherItemPostingEvent::Origin->value);
        $itemColumn = self::itemColumn(OtherItemPostingEvent::Origin, $side);
        $counterColumn = self::otherColumn(OtherItemPostingEvent::Origin, $side);

        $ownAccount = trim((string) ($item['account_code'] ?? ''));
        if ($ownAccount !== '') {
            [$account, $accountSource] = [$ownAccount, self::SOURCE_ITEM];
        } elseif (($profile[$itemColumn] ?? null) !== null) {
            [$account, $accountSource] = [(string) $profile[$itemColumn], self::SOURCE_KIND];
        } else {
            [$account, $accountSource] = [self::defaultItemAccount($side), self::SOURCE_DEFAULT];
        }

        $lines = array_values((array) ($item['posting_lines'] ?? []));
        $counterSource = $lines !== [] ? self::SOURCE_ITEM : null;
        if ($lines === [] && ($profile[$counterColumn] ?? null) !== null) {
            $lines = [['account_code' => (string) $profile[$counterColumn], 'amount' => round((float) $item['amount_czk'], 2)]];
            $counterSource = self::SOURCE_KIND;
        }

        $usesKind = $accountSource === self::SOURCE_KIND || $counterSource === self::SOURCE_KIND
            || ($profile !== null && $profile['description_template'] !== null);
        $source = match (true) {
            $usesKind => self::SOURCE_KIND,
            $accountSource === self::SOURCE_ITEM || $counterSource === self::SOURCE_ITEM => self::SOURCE_ITEM,
            default => self::SOURCE_DEFAULT,
        };
        $template = $profile['description_template'] ?? null;
        return [
            'event' => OtherItemPostingEvent::Origin->value,
            'source' => $source,
            'account_code' => $account,
            'account_source' => $accountSource,
            'posting_lines' => $lines,
            'counter_source' => $counterSource,
            'description' => self::render($template, $item) ?? (string) $item['title'],
            'description_template' => $template,
            'dimensions' => $profile['dimensions'] ?? [],
            'kind_id' => $profile !== null ? $kindId : null,
            'profile_id' => $profile['id'] ?? null,
        ];
    }

    /**
     * Kontace úhrady: MD/D pro zaúčtování pohybu nebo pokladního dokladu.
     *
     * @param array<string,mixed> $item
     * @param string $itemAccount účet položky, proti kterému úhrada jde (saldokonto)
     * @return array{event:string,source:string,debit_account_code:string,credit_account_code:string,
     *   money_account_code:string,description:?string,description_template:?string,kind_id:?int,profile_id:?int}
     */
    public function payment(int $supplierId, array $item, OtherItemPostingEvent $event, string $itemAccount): array
    {
        if (!$event->isPayment()) {
            throw new \LogicException('OtherItemPostingResolver::payment čeká událost úhrady.');
        }
        $side = (string) $item['side'];
        $moneyColumn = self::otherColumn($event, $side);
        $rule = null;
        $source = self::SOURCE_DEFAULT;
        $kindId = null;
        if (isset($item['id'])) {
            $rule = $this->profiles->overridesForItem($supplierId, (int) $item['id'])[$event->value] ?? null;
            if ($rule !== null) $source = self::SOURCE_ITEM;
        }
        if ($rule === null) {
            $kindId = $this->kindIdOf($supplierId, $item);
            $rule = $kindId === null ? null : $this->profiles->activeProfile($supplierId, $kindId, $event->value);
            if ($rule !== null) $source = self::SOURCE_KIND;
        }
        $money = (string) ($rule[$moneyColumn] ?? self::DEFAULT_MONEY_ACCOUNT[$event->value]);
        $template = $rule['description_template'] ?? null;
        return [
            'event' => $event->value,
            'source' => $source,
            'debit_account_code' => $moneyColumn === 'debit_account_code' ? $money : $itemAccount,
            'credit_account_code' => $moneyColumn === 'credit_account_code' ? $money : $itemAccount,
            'money_account_code' => $money,
            'description' => self::render($template, $item),
            'description_template' => $template,
            'kind_id' => $source === self::SOURCE_KIND ? $kindId : null,
            'profile_id' => $source === self::SOURCE_KIND ? ($rule['id'] ?? null) : null,
        ];
    }

    /**
     * Účinná kontace všech událostí pro detail a editor položky; daňová evidence nic.
     *
     * @param array<string,mixed> $item
     * @return array<string,array<string,mixed>>
     */
    public function forItem(int $supplierId, array $item): array
    {
        if (!$this->isDoubleEntry($supplierId)) {
            return [];
        }
        $origin = $this->origin($supplierId, $item);
        $receivable = $item['side'] === 'receivable';
        $amount = round((float) $item['amount_czk'], 2);
        $origin['lines'] = [['account_code' => $origin['account_code'], 'side' => $receivable ? 'debit' : 'credit', 'amount' => $amount]];
        foreach ($origin['posting_lines'] as $line) {
            $origin['lines'][] = ['account_code' => $line['account_code'], 'side' => $receivable ? 'credit' : 'debit',
                'amount' => (float) $line['amount']];
        }
        $out = [OtherItemPostingEvent::Origin->value => $origin];
        foreach ([OtherItemPostingEvent::PaymentBank, OtherItemPostingEvent::PaymentCash] as $event) {
            $out[$event->value] = $this->payment($supplierId, $item, $event, $origin['account_code']);
        }
        return $out;
    }

    /**
     * Popis zápisu ze šablony; null = šablona není (volající použije svůj výchozí popis).
     *
     * @param array<string,mixed> $item
     */
    public static function render(?string $template, array $item): ?string
    {
        if ($template === null || trim($template) === '') {
            return null;
        }
        $text = strtr($template, [
            '{title}' => (string) ($item['title'] ?? ''),
            '{document_no}' => (string) ($item['document_no'] ?? ''),
            '{partner}' => (string) ($item['partner_name'] ?? ''),
            '{variable_symbol}' => (string) ($item['variable_symbol'] ?? ''),
        ]);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text === '' ? null : mb_substr($text, 0, 255);
    }
}
