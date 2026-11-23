/**
 * Bankovní pohyby: přehled, detail, kandidáti párování, ruční párování a jeho zrušení,
 * ignorování pohybu a vyřízení návrhů párování.
 *
 * ÚČETNÍ DENÍK ZŮSTÁVÁ JEN KE ČTENÍ. Párování tokenem server nezaúčtuje: odpověď nese
 * `posting: {action: "deferred", reason: "api_token"}` a pohyb zaúčtuje uživatel
 * v aplikaci nebo plánovaný přepočet fronty automatiky podle politiky firmy
 * (`BankPostingService::deferMatchedPosting`). Pohyb, který už má zápis v deníku,
 * token nepřepáruje ani neodpáruje (403 `token_write_forbidden`). Zaúčtování
 * (`post`), jeho zrušení (`unpost`), smazání výpisu a AI kontace v katalogu nejsou.
 */
import { CONFIRM, confirmed, seg } from './tool-shared.mjs';
import {
  bool, custom, id, ids, int, PAGING, pick, read, str,
} from './tool-schema.mjs';

const MATCH_STATUS = str('Stav párování: unmatched = nespárovaný, auto_exact / auto_partial = spárovaný automaticky, '
  + 'manual = spárovaný ručně, ignored = ignorovaný.', { enum: ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] });

const DEFERRED_POSTING = 'Spárování přes MCP do účetního deníku NEZAPISUJE: odpověď vrátí '
  + '`posting.action = "deferred"` a pohyb zaúčtuje uživatel v aplikaci (Automat, K zaúčtování) nebo ranní '
  + 'přepočet fronty podle politiky automatického účtování firmy. Pohyb, který už má zápis v deníku, '
  + 'nástroj nepřepáruje ani neodpáruje (chyba token_write_forbidden, změnu udělá účetní v aplikaci).';

const describeTx = (row) => {
  const amount = row?.amount !== undefined ? `${row.amount} ${row.currency ?? ''}`.trim() : 'neznámá částka';
  const who = row?.counterparty_name || row?.counterparty_account || 'bez protistrany';
  return `pohyb #${row?.id ?? '?'} z ${row?.posted_at ?? '?'}, ${amount}, ${who}, stav ${row?.match_status ?? '?'}`;
};

export const BANK_TOOLS = [
  read('list_bank_transactions', 'Bankovní pohyby napříč účty',
    'Stránkovaný přehled bankovních pohybů firmy ze všech výpisů a účtů: částka, protistrana, VS, stav párování, '
    + 'navázaný doklad a v podvojném účetnictví i stav zaúčtování (`posting`). Typicky: nespárované příchozí platby '
    + '(`status: "unmatched"`), nezaúčtované pohyby (`posting_status: "unposted"`) nebo dohledání platby podle VS či částky '
    + '(`query`). Nic nemění.', {
      status: MATCH_STATUS,
      posting_status: str('Jen podvojné účetnictví: unposted = bez zápisu v deníku, posted = zaúčtované.', { enum: ['unposted', 'posted'] }),
      year: int('Kalendářní rok pohybu.', { minimum: 2000, maximum: 2100 }),
      account: str('Náš bankovní účet (číslo účtu v libovolném formátu), jinak všechny účty.'),
      query: str('Hledaný text: protistrana, popis, číslo protiúčtu, VS nebo přesná částka (např. 1 234,50).', { maxLength: 100 }),
      sort: str('Řazení.', { enum: ['posted_at', 'amount', 'account', 'variable_symbol', 'counterparty', 'invoice', 'status', 'posting'] }),
      direction: str('Směr řazení, výchozí desc (nejnovější nahoře).', { enum: ['asc', 'desc'] }),
      ...PAGING,
    }, '/bank-transactions', {
      query: ['status', 'posting_status', 'year', 'account', 'query', 'sort', 'direction', 'page', 'per_page'],
      aliases: { query: 'q' },
    }),
  read('get_bank_transaction', 'Detail bankovního pohybu',
    'Jeden bankovní pohyb: částka, měna a korunový přepočet, protistrana, VS, popis, stav párování, navázaný doklad '
    + 'a stav zaúčtování. Nic nemění.',
    { id: id('ID bankovního pohybu (bank_transaction_id).') }, (a) => `/bank-transactions/${seg(a.id)}`, { required: ['id'] }),
  custom('bank_transaction_match_candidates', 'Kandidáti na spárování pohybu',
    'Navrhne doklady ke spárování s pohybem. `mode: "documents"` (výchozí) vrátí vystavené i přijaté faktury podle '
    + 'částky a data (i dobropisy a už zaplacené doklady kvůli doplatkům), volitelně zúžené textem `search`. '
    + '`mode: "split"` hledá sloučenou úhradu, kdy jedna platba hradí víc faktur (kombinace se součtem rovným částce). '
    + 'Výsledek předej do match_bank_transaction. Nic nemění.', {
      id: id('ID bankovního pohybu.'),
      mode: str('documents = jednotlivé doklady, split = kombinace více faktur.', { enum: ['documents', 'split'] }),
      search: str('Jen mode documents: hledaný text (číslo dokladu, VS, protistrana).', { maxLength: 100 }),
      invoice_id: id('Jen mode split: vystavená faktura, která musí být v kombinaci.'),
      purchase_invoice_id: id('Jen mode split: přijatá faktura, která musí být v kombinaci.'),
      window: int('Jen mode split: okno ± dní kolem data platby (0 = bez omezení, nejvýš 60).', { minimum: 0, maximum: 60 }),
      max: int('Jen mode split: nejvýš faktur v kombinaci.', { minimum: 2, maximum: 6 }),
    }, ['id'], false, (c, a, tool) => (a.mode === 'split'
      ? c.get(`/bank-transactions/${seg(a.id)}/split-suggestions`, pick(a, ['invoice_id', 'purchase_invoice_id', 'window', 'max']), tool)
      : c.get(`/bank-transactions/${seg(a.id)}/match-candidates`, pick(a, ['search']), tool))),
  custom('find_bank_payments_for_document', 'Volné platby k dokladu',
    'Opačný směr než bank_transaction_match_candidates: k vystavené (`invoice_id`) nebo přijaté (`purchase_invoice_id`) '
    + 'faktuře najde volné bankovní pohyby správného směru, které ještě nejsou spárované. Zadej právě jeden doklad. '
    + 'Nic nemění.', {
      invoice_id: id('ID vystavené faktury.'),
      purchase_invoice_id: id('ID přijaté faktury.'),
      search: str('Hledaný text: protistrana, VS, popis nebo přesná částka.', { maxLength: 100 }),
      page: PAGING.page,
    }, [], false, (c, a, tool) => {
      if ((a.invoice_id === undefined) === (a.purchase_invoice_id === undefined)) {
        throw new Error('Zadej právě jedno z invoice_id a purchase_invoice_id.');
      }
      return c.get('/bank-transactions/payment-candidates', pick(a, ['invoice_id', 'purchase_invoice_id', 'search', 'page']), tool);
    }),
  read('bank_transaction_posting_preview', 'Náhled zaúčtování pohybu',
    'Jen podvojné účetnictví: ukáže, jak by se pohyb zaúčtoval (řádky MD/D, zdroj kontace), bez zápisu do deníku. '
    + 'Slouží ke kontrole před tím, než účetní pohyb zaúčtuje v aplikaci. Vyžaduje oprávnění k zaúčtování banky.',
    { id: id('ID bankovního pohybu.') }, (a) => `/bank-transactions/${seg(a.id)}/posting-preview`, { required: ['id'] }),
  custom('match_bank_transaction', 'Spárovat bankovní pohyb s doklady',
    'Ručně spáruje bankovní pohyb a zaeviduje úhradu. Doklad zadej právě jedním způsobem: `invoice_ids` (vystavené '
    + 'faktury; víc ID = sloučená úhrada, součet musí sedět na částku), `purchase_invoice_ids` (přijaté faktury, i od '
    + 'různých dodavatelů) nebo `varsymbol` (dohledá doklad podle VS). Plná úhrada označí doklad jako zaplacený, nižší '
    + `platba zůstane částečnou úhradou (odpověď vrátí zbývající částku). ${DEFERRED_POSTING} `
    + 'Podle nastavení firmy může odejít poděkování za platbu, jako při párování v aplikaci.', {
      id: id('ID bankovního pohybu.'),
      invoice_ids: ids('Vystavené faktury (1 = běžné párování, 2 a více = sloučená úhrada).', { maxItems: 50 }),
      purchase_invoice_ids: ids('Přijaté faktury (1 = běžné párování, 2 a více = sloučená úhrada).', { maxItems: 50 }),
      varsymbol: str('Variabilní symbol dokladu, když neznáš jeho ID.', { maxLength: 20 }),
    }, ['id'], true, (c, a, tool) => {
      const given = ['invoice_ids', 'purchase_invoice_ids', 'varsymbol'].filter((key) => a[key] !== undefined);
      if (given.length !== 1) throw new Error('Zadej právě jedno z invoice_ids, purchase_invoice_ids a varsymbol.');
      const body = a.invoice_ids ? (a.invoice_ids.length === 1 ? { invoice_id: a.invoice_ids[0] } : { invoice_ids: a.invoice_ids })
        : a.purchase_invoice_ids ? (a.purchase_invoice_ids.length === 1
          ? { purchase_invoice_id: a.purchase_invoice_ids[0] } : { purchase_invoice_ids: a.purchase_invoice_ids })
          : { varsymbol: a.varsymbol };
      return c.post(`/bank-transactions/${seg(a.id)}/match`, body, tool);
    }),
  custom('unmatch_bank_transaction', 'Zrušit spárování bankovního pohybu',
    'Vrátí pohyb mezi nespárované a odebere úhradu, která párováním vznikla; stav dokladu se přepočte ze zbývajících '
    + 'plateb. Ruší i ruční ignorování. Funguje jen u pohybu BEZ zápisu v účetním deníku, který nehradí zálohu; '
    + 'zaúčtovaný pohyb odpáruje účetní v aplikaci (chyba token_write_forbidden). Bez `confirm: true` jen ukáže, '
    + 'kterého pohybu se změna týká.', {
      id: id('ID bankovního pohybu.'), confirm: CONFIRM,
    }, ['id'], true, async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/bank-transactions/${seg(a.id)}`, action: 'Zrušit se má spárování', label: describeTx,
      });
      return { transaction: was, result: await c.post(`/bank-transactions/${seg(a.id)}/unmatch`, {}, tool) };
    }, { destructive: true }),
  custom('set_bank_transaction_ignored', 'Ignorovat bankovní pohyb',
    '`ignored: true` označí pohyb jako ignorovaný (nepatří k žádnému dokladu, např. převod mezi vlastními účty) '
    + 's volitelnou poznámkou; `ignored: false` ruční ignorování zruší. Zaúčtovaný spárovaný pohyb ani pohyb přiřazený '
    + 'ostatní položce ignorovat nejde. Do deníku nezapisuje.', {
      id: id('ID bankovního pohybu.'),
      ignored: bool('true = ignorovat, false = zrušit ruční ignorování.'),
      note: str('Jen při ignorování: důvod, nejvýš 1000 znaků.', { maxLength: 1000 }),
    }, ['id', 'ignored'], true, (c, a, tool) => (a.ignored
      ? c.post(`/bank-transactions/${seg(a.id)}/ignore`, pick(a, ['note']), tool)
      : c.post(`/bank-transactions/${seg(a.id)}/unignore`, {}, tool))),
  custom('resolve_bank_match_suggestion', 'Přijmout nebo zamítnout návrh párování',
    'Vyřídí návrh párování z list_bank_match_suggestions. `decision: "accept"` spáruje pohyb s kandidátem '
    + `\`candidate_index\` (výchozí 0, první kandidát). ${DEFERRED_POSTING} `
    + '`decision: "reject"` návrh zamítne s volitelným důvodem a pohyb nechá nespárovaný.', {
      id: id('ID návrhu párování (ne ID pohybu).'),
      decision: str('accept = přijmout, reject = zamítnout.', { enum: ['accept', 'reject'] }),
      candidate_index: int('Jen accept: pořadí kandidáta v návrhu od 0.', { minimum: 0 }),
      reason: str('Jen reject: důvod zamítnutí.', { maxLength: 190 }),
    }, ['id', 'decision'], true, (c, a, tool) => (a.decision === 'accept'
      ? c.post(`/bank-match-suggestions/${seg(a.id)}/accept`, pick(a, ['candidate_index']), tool)
      : c.post(`/bank-match-suggestions/${seg(a.id)}/reject`, pick(a, ['reason']), tool))),
  custom('create_purchase_invoice_from_bank_transaction', 'Koncept přijaté faktury z odchozí platby',
    'Z ODCHOZÍ platby bez dokladu založí koncept přijaté faktury (částka, datum, VS, měna a popis z pohybu, jedna '
    + 'položka v plné částce se sazbou 0 %) a pohyb s ním spáruje. Dodavatel musí existovat (search_clients). Koncept '
    + 'se nezaúčtuje; rozpad DPH, číslo dokladu a PDF doplní účetní. Opakované volání pro stejný pohyb vrátí chybu '
    + 'already_exists s ID existujícího konceptu.', {
      id: id('ID odchozího bankovního pohybu.'),
      vendor_id: id('ID dodavatele (karta klienta).'),
    }, ['id', 'vendor_id'], true, (c, a, tool) => c.post(
      `/bank-transactions/${seg(a.id)}/create-purchase-invoice`, { vendor_id: a.vendor_id }, tool,
    )),
];
