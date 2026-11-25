import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

import { INVOICE_ITEM_FIELDS } from '../src/tools.mjs';
import { PURCHASE_ITEM_FIELDS } from '../src/purchase-tools.mjs';
import { RECURRING_ITEM_FIELDS } from '../src/invoice-tools.mjs';

/**
 * Úprava konceptu přes MCP posílá položky zpět celé, protože server je nahrazuje
 * (DELETE + INSERT). Sloupec položky, který MCP nezná, se tak při každé úpravě tiše
 * smaže; přesně tak se ztrácely účty a předkontace řádků.
 *
 * Test porovná seznamy polí MCP se sloupci tabulek položek v otisku struktury
 * (`db/schema.snapshot.json`, přegeneruje ho každá migrace). Nový sloupec ho shodí,
 * dokud ho někdo nepřidá do seznamu MCP, nebo vědomě sem mezi sloupce, které MCP
 * posílat nemá (dopočítá je server, nebo je replaceItems neukládá).
 */
const SNAPSHOT = new URL('../../db/schema.snapshot.json', import.meta.url);

const TABLES = [
  {
    table: 'invoice_items',
    fields: INVOICE_ITEM_FIELDS,
    // Sloupec => pole API, pod kterým ho server čte a vrací.
    aliases: { tracking_json: 'tracking_allocations' },
    serverOwned: {
      invoice_id: 'vazba na doklad z cesty',
      vat_rate_snapshot: 'sazbu dosadí server podle vat_rate_id',
      total_without_vat: 'součty řádku počítá server',
      total_vat: 'součty řádku počítá server',
      total_with_vat: 'součty řádku počítá server',
      line_discount_pct: 'procento slevy dopočítá server z list_unit_price',
      import_tax_base_czk: 'otisk importu, replaceItems neukládá',
      import_tax_vat_czk: 'otisk importu, replaceItems neukládá',
      sales_quote_item_key: 'vazbu na nabídku obnovuje server z uložené položky podle jejího id',
      import_tax_base_czk: 'otisk importu, replaceItems neukládá',
      import_tax_vat_czk: 'otisk importu, replaceItems neukládá',
    },
  },
  {
    table: 'purchase_invoice_items',
    fields: PURCHASE_ITEM_FIELDS,
    aliases: {},
    serverOwned: {
      purchase_invoice_id: 'vazba na doklad z cesty',
      vat_rate_snapshot: 'sazbu dosadí server podle vat_rate_id',
      total_without_vat: 'součty řádku počítá server',
      total_vat: 'součty řádku počítá server',
      total_with_vat: 'součty řádku počítá server',
      expense_category_id: 'replaceItems neukládá (kategorie je v hlavičce)',
      expense_classification_source: 'provenience automatické klasifikace, ruční úprava ji maže',
      expense_rule_id: 'provenience automatické klasifikace, ruční úprava ji maže',
      import_projection_vat_czk: 'otisk importu, replaceItems neukládá',
      import_tax_base_czk: 'otisk importu, replaceItems neukládá',
      import_tax_excluded: 'otisk importu, replaceItems neukládá',
      import_tax_vat_czk: 'otisk importu, replaceItems neukládá',
      purchase_order_line_id: 'vazbu na objednávku replaceItems neukládá',
    },
  },
  {
    table: 'recurring_invoice_template_items',
    fields: RECURRING_ITEM_FIELDS,
    aliases: {},
    serverOwned: {
      id: 'řádky šablony se nepárují podle ID',
      template_id: 'vazba na šablonu z cesty',
    },
  },
];

const snapshotAvailable = existsSync(SNAPSHOT);

for (const { table, fields, aliases, serverOwned } of TABLES) {
  test(`${table}: MCP posílá zpět každý sloupec, který server při náhradě položek ukládá`, { skip: !snapshotAvailable && 'bez db/schema.snapshot.json (MCP mimo repozitář)' }, () => {
    const columns = Object.keys(JSON.parse(readFileSync(SNAPSHOT, 'utf8')).tables[table].columns);
    const sent = new Set(fields);
    const missing = columns.filter((column) => !(column in serverOwned) && !sent.has(aliases[column] ?? column));
    assert.deepEqual(missing, [], `${table}: MCP nezná sloupce ${missing.join(', ')}; úprava konceptu by je smazala.`);

    // Opačný směr: pole MCP odpovídá sloupci (překlep by tiše nic neposlal).
    const known = new Set([...columns.map((column) => aliases[column] ?? column), 'id']);
    const unknown = fields.filter((field) => !known.has(field));
    assert.deepEqual(unknown, [], `${table}: pole ${unknown.join(', ')} tabulka nemá.`);

    const stale = Object.keys(serverOwned).filter((column) => !columns.includes(column));
    assert.deepEqual(stale, [], `${table}: výjimky pro neexistující sloupce ${stale.join(', ')}.`);
  });
}
