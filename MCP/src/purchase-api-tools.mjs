import { endpoint } from './endpoint-tool.mjs';

export const API_PURCHASE_TOOLS = [
  endpoint('list_new_purchase_invoice_advance_candidates', 'Nespárované zálohy dodavatele pro konečnou fakturu, která ještě není uložená',
    'Nespárované zálohy dodavatele pro konečnou fakturu, která ještě není uložená. Stejný výběr a řazení jako `/purchase-invoices/{id}/advance-candidates`.',
    '/purchase-invoices/advance-candidates', {
    query: {
      vendor_id: { type: 'integer', description: 'ID dodavatele.', minimum: 1 },
      currency_id: { type: 'integer', description: 'ID měny.', minimum: 1 },
      total: { type: 'number', description: 'Částka konečné faktury s DPH pro řazení podle nejbližší zálohy.' },
    },
    required: ['vendor_id', 'currency_id'],
  }),
  endpoint('list_purchase_advance_deduction_lines', 'Řádky odpočtu poskytnuté zálohy podle jejích daňových dokladů k platbě',
    'Řádky odpočtu poskytnuté zálohy podle jejích daňových dokladů k platbě. Za každý živý DDKP zálohy a sazbu DPH jeden řádek se základem a daní DDKP se znaménkem minus (v měně dokladu).',
    '/purchase-invoices/advance-deduction-lines', {
    query: { advance_id: { type: 'integer', description: 'ID zálohy.', minimum: 1 } },
    required: ['advance_id'],
  }),
  endpoint('dismiss_purchase_invoice_advance_suggestion', 'Zamítne automatický návrh na spárování zálohy',
    'Zamítne automatický návrh na spárování zálohy. Pro provedení je potřeba confirm: true.',
    '/purchase-invoices/{id}/advance-suggestion', {
    method: 'DELETE',
    confirm: true,
    preview: '/purchase-invoices/{id}',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_purchase_invoice_documents', 'Seznam DMS dokumentů provázaných s přijatou fakturou',
    'Vrátí dokumenty připojené k přijaté faktuře v agendě Dokumenty.',
    '/purchase-invoices/{id}/documents', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('download_purchase_invoice_isdoc', 'ISDOC 6.0 XML export přijaté faktury',
    'Stáhne přijatou fakturu ve formátu ISDOC. Export uvádí protistranu jako dodavatele a aktuální firmu jako odběratele.',
    '/purchase-invoices/{id}/isdoc', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_purchase_invoice_items', 'Položky přijaté faktury bez načtení celého detailu',
    'Položky přijaté faktury bez načtení celého detailu.',
    '/purchase-invoices/{id}/items', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('get_purchase_invoice_opening_balance', 'Je přijatý doklad převzatý z počátečních stavů?',
    'Je přijatý doklad převzatý z počátečních stavů? Vazba dokladu na živý otevírací zápis (`journal_entries.source_type = opening`).',
    '/purchase-invoices/{id}/opening-balance', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('download_purchase_invoice_rendered_pdf', 'Naše PDF rekonstrukce přijaté faktury (mPDF z dat)',
    'Naše PDF rekonstrukce přijaté faktury (mPDF z dat). Vygeneruje naši vlastní PDF kopii přijaté faktury.',
    '/purchase-invoices/{id}/our-pdf', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('download_purchase_invoice_source', 'Stáhne archivovaný strojově čitelný zdrojový doklad (ISDOC/ISDOCX/Pohoda XML/…)',
    'Stáhne archivovaný strojově čitelný zdrojový doklad (ISDOC/ISDOCX/Pohoda XML/…). Vrací bajty AS-IS (`.isdocx` se nerozbaluje).',
    '/purchase-invoices/{id}/source', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('preview_purchase_invoice_stock_receipt', 'Návrh příjemky ke skladovým položkám přijaté faktury',
    'Návrh příjemky ke skladovým položkám přijaté faktury. Vrací, co zbývá přijmout na sklad, navrhované pořizovací ceny a kandidáty vedlejších nákladů.',
    '/purchase-invoices/{id}/stock-receipt', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    query: {
      stock_item_ids: {
        type: 'object',
        description: 'Karta vybraná pro řádek faktury, `stock_item_ids[<purchase_invoice_item_id>]=<stock_item_id>`. Řádek se pak vrátí v `lines` se zbytkem a cenou přepočtenými podle balení této karty.',
        additionalProperties: { type: 'integer', description: 'ID skladové karty pro řádek přijaté faktury určený klíčem.' },
      },
    },
  }),
  endpoint('create_purchase_invoice_stock_receipt', 'Založí skladovou příjemku (DRAFT) z přijaté faktury',
    'Založí skladovou příjemku (DRAFT) z přijaté faktury. Vytvoří skladový doklad typu příjemka navázaný na fakturu.',
    '/purchase-invoices/{id}/stock-receipt', {
    method: 'POST',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: {
      warehouse_id: { type: 'integer', description: 'ID skladu.', minimum: 1 },
      doc_date: { type: 'string', description: 'Datum skladové příjemky.', format: 'date' },
      description: { type: 'string', description: 'Popis skladové příjemky.', maxLength: 255 },
      lines: {
        type: 'array',
        description: 'Vybrané řádky přijaté faktury, které se mají naskladnit.',
        minItems: 1,
        items: {
          type: 'object',
          description: 'Jeden přijímaný řádek faktury včetně skladové karty a množství.',
          properties: {
            purchase_invoice_item_id: { type: 'integer', description: 'ID řádku přijaté faktury.', minimum: 1 },
            stock_item_id: { type: 'integer', description: 'ID skladové karty.', minimum: 1 },
            quantity: { type: ['string', 'number'], description: 'Množství k naskladnění v měrné jednotce položky.' },
            unit_cost: {
              type: ['string', 'number', 'null'],
              description: 'Pořizovací cena za základní jednotku zvolené karty. Bez hodnoty ji server spočítá z řádku faktury podle balení zvolené karty.',
            },
            fill_intrastat: {
              type: 'boolean',
              description: 'Jen `true` doplní po zaúčtování příjemky kód KN8 a zemi původu z řádku faktury na kartu, pokud tam chybí; existující hodnotu nepřepíše. Volbu smí poslat jen token s právem `stock.items.write`, jinak se požadavek odmítne se stavem 403 a příjemka nevznikne. Stejné právo musí mít i ten, kdo příjemku zaúčtuje: bez něj se příjemka zaúčtuje, karta se nezmění a odpověď zaúčtování to uvede ve `warnings`. Zápis je v logu aktivit.',
              default: false,
            },
            remember_seller_code: {
              type: 'boolean',
              description: 'Jen `true`: po zaúčtování příjemky uloží kód z řádku faktury ke kartě pro tohoto dodavatele a odebere ho z jeho ostatních karet. Nastavit vyžaduje právo `stock.items.write`, bez něj se požadavek odmítne se stavem 403 a příjemka nevznikne. Zaúčtuje-li koncept někdo bez tohoto práva, příjemka se zaúčtuje, karta se nezmění a odpověď nese varování. Zápis je v logu aktivit.',
              default: false,
            },
          },
          additionalProperties: false,
          required: ['purchase_invoice_item_id', 'quantity'],
        },
      },
      landed_costs: {
        type: 'array',
        description: 'Vedlejší pořizovací náklady, které se rozpočítají do ocenění příjemky.',
        items: {
          type: 'object',
          description: 'Jeden vedlejší pořizovací náklad, například doprava nebo clo.',
          properties: {
            purchase_invoice_id: { type: 'integer', description: 'ID přijaté faktury.', minimum: 1 },
            purchase_invoice_item_id: { type: 'integer', description: 'ID řádku přijaté faktury.', minimum: 1 },
            description: { type: 'string', description: 'Popis vedlejšího nákladu.' },
            amount: { type: 'number', description: 'Částka v měně dokladu.' },
            allocation: { type: 'string', description: 'Rozdělení vedlejšího nákladu podle hodnoty (`by_value`) nebo množství (`by_qty`).', enum: ['by_value', 'by_qty'], default: 'by_value' },
          },
          additionalProperties: false,
          required: ['amount'],
        },
      },
    },
    required: ['warehouse_id', 'doc_date', 'lines'],
  }),
  endpoint('list_purchase_invoice_stock_receipts', 'Přehled skladových příjmů vytvořených z této přijaté faktury',
    'Přehled skladových příjmů vytvořených z této přijaté faktury.',
    '/purchase-invoices/{id}/stock-receipts', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
];
