<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\Shared\PartnerCountry;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use PDO;

/**
 * Adresář partnerů PREMIER (`PARTNERY`).
 *
 * Partner se stejným IČO, který už ve firmě je, se použije - převod nezakládá druhého
 * a u existující karty jen doplní chybějící údaje. Vlastní firma se přeskakuje. Doklad
 * s partnerem mimo adresář (PREMIER drží na dokladu kopii adresy) partnera založí
 * z údajů dokladu ({@see resolvePartner()}).
 *
 * Normalizace IČO/DIČ a párování na existující kontakt jsou společné všem převodům
 * ({@see PartnerIdentityMatcher}).
 *
 * Stát: ISO kód z karty (`KOD_ZEME`), jinak předpona DIČ, jinak název státu (PREMIER
 * ho na dokladu drží textem - „Česká republika", „United States").
 */
final class PartnerImporter
{
    public const STEP = 'partners';

    /** @var array<int,array{currency_id:int,country_id:int}> firma → výchozí měna a stát */
    private array $defaults = [];

    /** @var array<string,string>|null název státu (malými) → ISO2 */
    private ?array $countryNames = null;

    /** @var array<string,int|null> */
    private array $countryIds = [];

    /** @var array<string,array<string,mixed>> číslo partnera PREMIER → snapshot z karty */
    private array $cards = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly PartnerIdentityMatcher $identity,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $this->loadClientIndex($ctx);
        $ownIco = $ctx->backup->ico;
        foreach ($ctx->backup->rows('PARTNERY') as $r) {
            $s = $this->cardSnapshot($r);
            $number = $s['premier_no'];
            if ($number !== '') {
                $this->cards[$number] = $s;
            }
            if ($s['name'] === '' || ($number === '' && $s['premier_id'] === '') || ($s['ico'] !== '' && $s['ico'] === $ownIco)) {
                $p->count(self::STEP, 'skipped');
                continue;
            }
            $key = 'card:' . ($s['premier_id'] !== '' ? $s['premier_id'] : $number);
            $mapped = $this->map->get($ctx->supplierId, PremierImportRepository::KIND_CLIENT, $key)
                ?? $this->map->get($ctx->supplierId, PremierImportRepository::KIND_CLIENT_MATCH, $key);
            if ($mapped !== null) {
                $this->remember($ctx, $s, $mapped);
                $p->count(self::STEP, 'existing');
                continue;
            }
            if ($s['ico'] !== '' && isset($ctx->clientsByIco[$s['ico']])) {
                $clientId = $ctx->clientsByIco[$s['ico']];
                $this->identity->fillMissing($ctx->supplierId, $clientId, $s);
                $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CLIENT_MATCH, $key, $clientId, $ctx->runId);
                $p->count(self::STEP, 'matched');
            } else {
                $clientId = $this->insertClient($ctx, $s);
                $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
                $p->count(self::STEP, 'created');
            }
            $this->remember($ctx, $s, $clientId);
        }
        $p->finish(self::STEP);
    }

    /**
     * Partner dokladu: podle vazby na adresář (ID nebo číslo partnera), IČO, názvu, jinak
     * se založí z údajů na dokladu.
     *
     * @param array{premier_id:string,premier_no:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string} $s
     */
    public function resolvePartner(PremierContext $ctx, array $s): int
    {
        if ($s['premier_id'] !== '' && isset($ctx->clientsById[$s['premier_id']])) {
            return $ctx->clientsById[$s['premier_id']];
        }
        if ($s['premier_no'] !== '' && isset($ctx->clientsByNumber[$s['premier_no']])) {
            return $ctx->clientsByNumber[$s['premier_no']];
        }
        if ($s['ico'] !== '' && isset($ctx->clientsByIco[$s['ico']])) {
            return $ctx->clientsByIco[$s['ico']];
        }
        $name = $s['name'] !== '' ? $s['name'] : 'Neznámý partner z PREMIER';
        $key = PartnerIdentityMatcher::documentKey($s['ico'], $name);
        $mapped = $this->map->get($ctx->supplierId, PremierImportRepository::KIND_CLIENT, $key)
            ?? $this->map->get($ctx->supplierId, PremierImportRepository::KIND_CLIENT_MATCH, $key);
        if ($mapped !== null) {
            return $mapped;
        }
        if ($s['ico'] === '') {
            $found = $this->identity->clientByName($ctx->supplierId, mb_substr($name, 0, 190));
            if ($found !== null) {
                $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CLIENT_MATCH, $key, $found, $ctx->runId);
                return $found;
            }
        }
        $clientId = $this->insertClient($ctx, $s + ['name' => $name, 'email' => '', 'phone' => '', 'note' => 'Převzato z PREMIER (podle dokladu)']);
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
        if ($s['ico'] !== '') {
            $ctx->clientsByIco[$s['ico']] = $clientId;
        }
        $ctx->protocol->count(self::STEP, 'created_from_documents');
        return $clientId;
    }

    /**
     * Partner z hlavičky faktury (`FA_OUT` / `FA_IN`), doplněný ze karty adresáře.
     *
     * @param array<string,mixed> $h
     * @return array{premier_id:string,premier_no:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string}
     */
    public function documentSnapshot(array $h): array
    {
        $number = trim((string) ($h['CISLO_ODB'] ?? ''));
        $card = $number !== '' ? ($this->cards[$number] ?? null) : null;
        $name = trim(trim((string) ($h['NAZEV_ODB'] ?? '')) . ' ' . trim((string) ($h['NAZEV_OD2'] ?? '')));
        $dic = strtoupper(str_replace(' ', '', trim((string) ($h['DIC_ODB'] ?? ''))));
        $euDic = strtoupper(str_replace(' ', '', trim((string) ($h['DIC_EU'] ?? ''))));
        $s = [
            'premier_id' => trim((string) ($h['ID_PAR'] ?? '')),
            'premier_no' => $number,
            'name' => $name !== '' ? $name : ($card['name'] ?? ''),
            'ico' => PartnerIdentityMatcher::ico((string) ($h['ICO_ODB'] ?? '')),
            'dic' => $dic !== '' ? $dic : ($euDic !== '' ? $euDic : ''),
            'street' => trim((string) ($h['ULICE_ODB'] ?? '')),
            'city' => trim((string) ($h['MESTO_ODB'] ?? '')),
            'zip' => str_replace(' ', '', trim((string) ($h['PSC_ODB'] ?? ''))),
            'country' => '',
        ];
        $s['country'] = $this->countryCode((string) ($h['STAT_ODB'] ?? ''), $s['dic'], $card['country'] ?? '');
        if ($card !== null) {
            // Doklad nese stav adresy v době vystavení; chybějící údaje doplní karta.
            foreach (['ico', 'street', 'city', 'zip'] as $k) {
                if ($s[$k] === '') {
                    $s[$k] = $card[$k];
                }
            }
        }
        return $s;
    }

    /**
     * ISO2 státu: kód z karty adresáře, jinak předpona DIČ, jinak název státu z dokladu.
     */
    public function countryCode(string $name, string $dic, string $cardIso = ''): string
    {
        if (preg_match('/^[A-Z]{2}$/', $cardIso) === 1) {
            return $cardIso;
        }
        $fromVat = PartnerCountry::fromVatId(PartnerIdentityMatcher::vatId($dic));
        if ($fromVat !== null) {
            return $fromVat;
        }
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        if ($this->countryNames === null) {
            $this->countryNames = [];
            foreach ($this->db->pdo()->query('SELECT iso2, iso3, name_cs, name_en FROM countries')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                foreach (['iso2', 'iso3', 'name_cs', 'name_en'] as $k) {
                    $this->countryNames[mb_strtolower((string) $c[$k])] = (string) $c['iso2'];
                }
            }
            $this->countryNames['čr'] = 'CZ';
            $this->countryNames['česko'] = 'CZ';
            $this->countryNames['usa'] = 'US';
        }
        return $this->countryNames[$name] ?? '';
    }

    /**
     * @param array<string,mixed> $r
     * @return array{premier_id:string,premier_no:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string,email:string,phone:string,note:string}
     */
    private function cardSnapshot(array $r): array
    {
        $dic = strtoupper(str_replace(' ', '', trim((string) ($r['DIC'] ?? ''))));
        $number = trim((string) ($r['CISLO'] ?? ''));
        return [
            'premier_id' => trim((string) ($r['ID'] ?? '')),
            'premier_no' => $number,
            'name' => trim((string) ($r['NAZEV'] ?? '')),
            'ico' => PartnerIdentityMatcher::ico((string) ($r['ICO'] ?? '')),
            'dic' => $dic,
            'street' => trim((string) ($r['ULICE'] ?? '')),
            'city' => trim((string) ($r['MESTO'] ?? '')),
            'zip' => str_replace(' ', '', trim((string) ($r['PSC'] ?? ''))),
            'country' => $this->countryCode((string) ($r['STAT'] ?? ''), $dic, strtoupper(trim((string) ($r['KOD_ZEME'] ?? '')))),
            'email' => trim((string) strtok((string) ($r['E_MAIL'] ?? ''), ',;')),
            'phone' => trim((string) ($r['MOBIL'] ?? '')) ?: trim((string) ($r['TEL'] ?? '')),
            'note' => 'Převzato z PREMIER (adresář č. ' . $number . ')',
        ];
    }

    /** @param array{premier_id:string,premier_no:string,ico:string} $s */
    private function remember(PremierContext $ctx, array $s, int $clientId): void
    {
        if ($s['premier_id'] !== '') {
            $ctx->clientsById[$s['premier_id']] = $clientId;
        }
        if ($s['premier_no'] !== '') {
            $ctx->clientsByNumber[$s['premier_no']] = $clientId;
        }
        if ($s['ico'] !== '') {
            $ctx->clientsByIco[$s['ico']] ??= $clientId;
        }
    }

    private function loadClientIndex(PremierContext $ctx): void
    {
        foreach ($this->identity->clientsByIco($ctx->supplierId) as $ico => $clientId) {
            $ctx->clientsByIco[$ico] ??= $clientId;
        }
    }

    /** @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string,email?:string,phone?:string,note?:string} $s */
    private function insertClient(PremierContext $ctx, array $s): int
    {
        $defaults = $this->defaults($ctx->supplierId);
        $dic = PartnerIdentityMatcher::vatId($s['dic']);
        $countryId = $this->countryId($s['country']) ?? $defaults['country_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email, phone,
                 currency_default_id, is_customer, is_vendor, is_vat_payer, note, auto_send_reminders)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, 0)'
        )->execute([
            $ctx->supplierId,
            mb_substr($s['name'], 0, 190),
            $s['ico'] !== '' ? $s['ico'] : null,
            $dic !== '' ? mb_substr($dic, 0, 20) : null,
            mb_substr($s['street'] !== '' ? $s['street'] : '-', 0, 190),
            mb_substr($s['city'] !== '' ? $s['city'] : '-', 0, 120),
            mb_substr($s['zip'] !== '' ? $s['zip'] : '-', 0, 10),
            $countryId,
            ($s['email'] ?? '') !== '' ? mb_substr((string) $s['email'], 0, 190) : null,
            ($s['phone'] ?? '') !== '' ? mb_substr((string) $s['phone'], 0, 40) : null,
            $defaults['currency_id'],
            $dic !== '' ? 1 : 0,
            (string) ($s['note'] ?? 'Převzato z PREMIER'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function countryId(string $iso2): ?int
    {
        $iso2 = strtoupper(trim($iso2));
        if (preg_match('/^[A-Z]{2}$/', $iso2) !== 1) {
            return null;
        }
        if (!array_key_exists($iso2, $this->countryIds)) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
            $stmt->execute([$iso2]);
            $id = $stmt->fetchColumn();
            $this->countryIds[$iso2] = $id === false ? null : (int) $id;
        }
        return $this->countryIds[$iso2];
    }

    /** @return array{currency_id:int,country_id:int} */
    private function defaults(int $supplierId): array
    {
        if (isset($this->defaults[$supplierId])) {
            return $this->defaults[$supplierId];
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT default_currency_id, country_id FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currencyId = (int) ($row['default_currency_id'] ?? 0);
        if ($currencyId === 0) {
            $c = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY id LIMIT 1");
            $c->execute([$supplierId]);
            $currencyId = (int) $c->fetchColumn();
        }
        return $this->defaults[$supplierId] = ['currency_id' => $currencyId, 'country_id' => $this->countryId('CZ') ?? (int) ($row['country_id'] ?? 0)];
    }
}
