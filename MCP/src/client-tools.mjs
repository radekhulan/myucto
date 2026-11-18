/**
 * Karta obchodního partnera, složky a soubory dokumentů, požadavky na doklady
 * a obecné číselníky (země, daňové konstanty).
 *
 * Záměrně tu není: přidání bankovního účtu partnerovi (cíl budoucích plateb mění
 * jen člověk), smazání klienta, odkazy na výkaz posílané e-mailem, koš a hromadné
 * operace dokumentů, mazání složek a požadavků.
 */
import { seg } from './tool-shared.mjs';
import {
  bool, custom, date, id, int, num, pick, read, str,
} from './tool-schema.mjs';

const LINK_TYPES = ['invoice', 'purchase_invoice', 'client', 'project', 'cash_document', 'other_item', 'internal_document'];

export const CLIENT_TOOLS = [
  read('get_client_bank_accounts', 'Bankovní účty obchodního partnera',
    'Aktivní bankovní účty partnera s nezávislými příznaky původu: `source_vat_registry` (zveřejněný v registru plátců '
    + 'DPH), `source_bank_statement` (viděný na bankovním výpisu) a `source_manual` (zadaný ručně). Použij před platbou '
    + 'dodavateli nebo při kontrole účtu na faktuře (viz také verify_purchase_payment_account). Nic nemění.',
    { id: id('ID klienta.') }, (a) => `/clients/${seg(a.id)}/bank-accounts`, { required: ['id'] }),
  read('check_client_vat_status', 'Ověřit plátcovství DPH partnera',
    'Online ověření, zda je partner plátce DPH: podle IČO v ARES, u zahraničního DIČ ve VIES (`source` ares, vies '
    + 'nebo unknown). Zjištěný příznak plátce DPH uloží do karty; když registr nerozhodne, vrátí dosud uložený. '
    + 'Registry se dotazují nejvýš jednou za 24 hodin. Ostatní údaje karty stáhne update_client s refresh_from_ares.',
    { id: id('ID klienta.') }, (a) => `/clients/${seg(a.id)}/vat-status`, { required: ['id'] }),
  read('list_client_projects', 'Zakázky klienta',
    'Všechny zakázky jednoho klienta včetně archivovaných (stav, sazba, rozpočet). Nic nemění.',
    { id: id('ID klienta.') }, (a) => `/clients/${seg(a.id)}/projects`, { required: ['id'] }),
  read('list_client_duplicates', 'Duplicitní karty partnerů',
    'Skupiny karet odběratelů a dodavatelů se shodným IČO nebo DIČ, kandidáti na sloučení. Karty neslučuje ani nemaže.',
    {}, '/clients/duplicates'),
  custom('set_client_archived', 'Archivovat kartu partnera',
    '`archived: true` kartu archivuje (zmizí z nabídek pro nové doklady, doklady i historie zůstávají), '
    + '`archived: false` ji vrátí mezi aktivní. Kartu nemaže.', {
      id: id('ID klienta.'), archived: bool('true = archivovat, false = obnovit.'),
    }, ['id', 'archived'], true,
    (c, a, tool) => c.post(`/clients/${seg(a.id)}/${a.archived ? 'archive' : 'unarchive'}`, {}, tool)),

  read('list_document_folders', 'Složky dokumentů',
    'Strom složek dokumentového úložiště (ID, název, rodič). Bez `parent_id` vrátí celý plochý seznam, s ním jen přímé '
    + 'podsložky. ID složky použij v upload_document, update_document nebo create_document_folder.',
    { parent_id: id('Jen přímé podsložky této složky.') }, '/document-folders', { query: ['parent_id'] }),
  custom('create_document_folder', 'Založit složku dokumentů',
    'Založí složku dokumentů, volitelně jako podsložku `parent_id`. Vrátí její ID.', {
      name: str('Název složky.', { minLength: 1, maxLength: 190 }),
      parent_id: id('Nadřazená složka, jinak kořen.'),
    }, ['name'], true, (c, a, tool) => c.post('/document-folders', pick(a, ['name', 'parent_id']), tool)),
  read('list_document_tags', 'Tagy dokumentů',
    'Tagy používané v dokumentech firmy s počtem použití. Hodí se před update_document, aby nevznikaly duplicitní '
    + 'tagy s jiným zápisem. Nic nemění.', {}, '/documents/tags'),
  custom('document_link_search', 'Najít záznam k připojení dokumentu',
    'Našeptávač cílů pro link_document: podle textu (číslo dokladu, VS, název klienta či zakázky) najde vystavené '
    + 'a přijaté faktury, klienty, zakázky, pokladní doklady, ostatní položky a interní doklady. Nic nemění.', {
      query: str('Hledaný text.', { minLength: 1, maxLength: 100 }),
      types: { type: 'array', description: 'Jen tyto typy záznamů, jinak všechny.', items: str('Typ záznamu.', { enum: LINK_TYPES }), uniqueItems: true },
    }, ['query'], false, (c, a, tool) => c.get('/documents/link-search', {
      q: a.query, ...(a.types ? { types: a.types.join(',') } : {}),
    }, tool)),
  read('list_document_files', 'Soubory dokumentu',
    'Soubory jednoho dokumentu: hlavní soubor a přílohy (název, typ, velikost). Obsah hlavního souboru stáhne '
    + 'download_document. Nic nemění.',
    { id: id('ID dokumentu.') }, (a) => `/documents/${seg(a.id)}/files`, { required: ['id'] }),

  custom('create_document_request', 'Vyžádat doklad od klienta',
    'Založí požadavek na chybějící doklad, který klient uvidí v portálu a může k němu nahrát soubor. '
    + 'S `bank_transaction_id` se požadavek váže k bankovnímu pohybu (platba bez dokladu) a popis, částka a datum '
    + 'se předvyplní z pohybu, pokud je nezadáš. Bez pohybu je `description` povinný. E-mail se tím neodesílá.', {
      description: str('Co klient má dodat, nejvýš 500 znaků.', { maxLength: 500 }),
      bank_transaction_id: id('Bankovní pohyb, ke kterému doklad chybí.'),
      amount: num('Jen bez pohybu: částka dokladu.', { minimum: 0 }),
      context_date: date('Jen bez pohybu: datum, ke kterému se doklad vztahuje.'),
      deadline: date('Termín dodání.'),
    }, [], true, (c, a, tool) => {
      if (a.bank_transaction_id !== undefined) {
        return c.post(`/bank-transactions/${seg(a.bank_transaction_id)}/document-request`, pick(a, ['description', 'deadline']), tool);
      }
      if (!a.description?.trim()) throw new Error('Bez bank_transaction_id zadej description.');
      return c.post('/document-requests', pick(a, ['description', 'amount', 'context_date', 'deadline']), tool);
    }),
  custom('set_document_request_status', 'Uzavřít nebo znovu otevřít požadavek na doklad',
    '`status: "resolved"` požadavek uzavře (vyřešeno i bez nahrání, např. doklad přišel jinou cestou), '
    + '`status: "requested"` ho vrátí klientovi k doplnění (chybný nebo neúplný soubor). Požadavek nemaže.', {
      id: id('ID požadavku.'),
      status: str('resolved = uzavřít, requested = znovu otevřít.', { enum: ['resolved', 'requested'] }),
    }, ['id', 'status'], true,
    (c, a, tool) => c.post(`/document-requests/${seg(a.id)}/${a.status === 'resolved' ? 'resolve' : 'reopen'}`, {}, tool)),

  custom('get_tax_constants', 'Daňové konstanty podle roku',
    'Roční daňové sazby a limity, jak s nimi aplikace počítá (včetně úprav správce): sazby daně z příjmů, slevy, '
    + 'paušální výdaje, minimální a průměrná mzda, vyměřovací základy pojistného, limity pro DPH a další. Používej je '
    + 'místo odhadů z paměti. Nic nemění.',
    { year: int('Jen tento rok, jinak všechny dostupné roky.', { minimum: 2000, maximum: 2100 }) }, [], false,
    async (c, a, tool) => {
      const result = await c.get('/codebooks/tax-constants', null, tool);
      if (a.year === undefined || !Array.isArray(result?.years)) return result;
      const found = result.years.find((row) => Number(row?.year) === a.year);
      if (!found) {
        const known = result.years.map((row) => row?.year).filter(Boolean).join(', ');
        throw new Error(`Daňové konstanty pro rok ${a.year} nejsou k dispozici. Dostupné roky: ${known || 'žádné'}.`);
      }
      return found;
    }),
  read('list_countries', 'Seznam zemí',
    'Číselník zemí (ID, ISO kód, název, členství v EU) pro adresy klientů a DPH v EU. Nic nemění.',
    {}, '/codebooks/countries'),
];
