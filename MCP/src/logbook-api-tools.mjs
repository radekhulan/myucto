import { endpoint, previewRecord } from './endpoint-tool.mjs';
import { pick } from './tool-schema.mjs';
import { seg } from './tool-shared.mjs';

async function previewOdometerRecalculation(c, a, tool) {
  const anchor = a.after_trip_id ? await c.get(`/logbook/trips/${seg(a.after_trip_id)}`, null, tool) : null;
  const carId = anchor?.car_id ?? a.car_id;
  if (!carId) throw new Error('Vyberte vozidlo nebo zadejte jízdu, po které má přepočet začít.');
  const filter = { car_id: carId };
  if (anchor) filter.date_from = anchor.trip_date;
  const items = [];
  let count = 0;
  let page = 1;
  let pages;
  do {
    const result = await c.get('/logbook/trips', { ...filter, page, per_page: 200 }, tool);
    pages = result.meta.pages;
    for (const trip of result.data) {
      if (anchor && !isAfterTrip(trip, anchor)) continue;
      count += 1;
      if (items.length < 50) {
        items.push(pick(trip, ['id', 'trip_date', 'time_start', 'origin', 'destination', 'odometer_start', 'odometer_end']));
      }
    }
    page += 1;
  } while (page <= pages);
  return { car_id: carId, count, items, shown: items.length };
}

function isAfterTrip(trip, anchor) {
  if (trip.trip_date !== anchor.trip_date) return trip.trip_date > anchor.trip_date;
  const tripTime = trip.time_start ?? '00:00';
  const anchorTime = anchor.time_start ?? '00:00';
  if (tripTime !== anchorTime) return tripTime > anchorTime;
  return Number(trip.id) > Number(anchor.id);
}

export const API_LOGBOOK_TOOLS = [
  endpoint('list_logbook_drivers', 'Zaměstnanci k výběru řidiče vozidla',
    'Zaměstnanci k výběru řidiče vozidla. Jen id, jméno a příznak aktivity. žádné mzdové údaje.',
    '/logbook/drivers'),
  endpoint('list_logbook_fuel_cash_documents', 'Pokladní doklady za pohonné hmoty',
    'Pokladní doklady za pohonné hmoty. Zaúčtované výdajové pokladní doklady (nákup / jiný výdaj, ne úhrada přijaté faktury), jejichž partner je čerpací stanice (podle IČO) nebo popis zní na palivo.',
    '/logbook/fuel-cash-documents', {
    query: {
      year: { type: 'integer', description: 'Rok.' },
      only_unscanned: { type: 'boolean', description: 'Při `true` vrátí pouze doklady, ze kterých dosud nebylo vytěženo tankování.' },
    },
  }),
  endpoint('create_fueling_from_cash_document', 'Vytěží pokladní doklad do tankování',
    'Vytěží pokladní doklad do tankování. Z popisu dokladu vytáhne litry, cenu za litr, druh paliva, SPZ a tachometr; vozidlo určí explicitní `car_id`, jinak SPZ, jinak výchozí vůz.',
    '/logbook/fuel-cash-documents/{id}/assign', {
    method: 'POST',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: { car_id: { type: ['integer', 'null'], description: 'ID vozidla.', minimum: 1 } },
  }),
  endpoint('list_logbook_fuel_invoices', 'Přehled faktur od benzínek',
    'Najde přijaté faktury použitelné jako podklad pro tankování. Vyžaduje oprávnění číst přijaté faktury.',
    '/logbook/fuel-invoices', {
    query: {
      year: { type: 'integer', description: 'Rok.' },
      only_unscanned: { type: 'boolean', description: 'Při `true` vrátí pouze doklady, ze kterých dosud nebylo vytěženo tankování.' },
      scanned_limit: { type: 'integer', description: 'Nejvyšší počet již vytěžených faktur ve výsledku.', default: 10 },
    },
  }),
  endpoint('create_fueling_from_purchase_invoice', 'Vytěží fakturu a přiřadí ji autu',
    'Založí tankování z vybrané přijaté faktury a přiřadí je vozidlu. Vyžaduje oprávnění číst přijaté faktury.',
    '/logbook/fuel-invoices/{id}/assign', {
    method: 'POST',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: { car_id: { type: ['integer', 'null'], description: 'ID vozidla.', minimum: 1 } },
  }),
  endpoint('list_fuel_purchase_invoice_items', 'Položky faktury od benzínky (detail pro vytěžení)',
    'Vrátí položky přijaté faktury za pohonné hmoty. Vyžaduje oprávnění číst přijaté faktury.',
    '/logbook/fuel-invoices/{id}/items', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('estimate_logbook_fueling_odometers', 'Odhad tachometrů tankování',
    'Odhad tachometrů tankování. dry_run pouze nabídne odhady. Pro provedení je potřeba confirm: true.',
    '/logbook/fuelings/estimate-odometers', {
    method: 'POST',
    confirm: true,
    preview: (c, a, tool) => c.post('/logbook/fuelings/estimate-odometers', {
      ...pick(a, ['car_id', 'year']), dry_run: true,
    }, tool),
    body: {
      car_id: { type: ['integer', 'null'], description: 'ID vozidla.', minimum: 1 },
      year: { type: ['integer', 'null'], description: 'Rok.', minimum: 1900, maximum: 2999 },
      dry_run: { type: 'boolean', description: 'Při `true` pouze spočítá návrh doplnění stavů tachometru.' },
    },
  }),
  endpoint('list_logbook_fuelings_link_candidates', 'Doklady k navázání tankování',
    'Doklady k navázání tankování. Pokladní doklady, bankovní pohyby (výdaje) nebo účetní zápisy firmy kolem data tankování (±10 dní), seřazené podle shody částky a vzdálenosti data.',
    '/logbook/fuelings/link-candidates', {
    query: {
      type: { type: 'string', description: 'Typ hledaného podkladu: pokladní doklad, bankovní pohyb nebo účetní zápis.', enum: ['cash_document', 'bank_transaction', 'journal_entry'] },
      date: { type: 'string', description: 'Datum tankování, kolem kterého se mají hledat doklady.', format: 'date' },
      amount: { type: 'number', description: 'Částka tankování pro seřazení výsledků podle shody.' },
      q: { type: 'string', description: 'Hledaný text.' },
    },
    required: ['type', 'date'],
  }),
  endpoint('list_logbook_fuelings_warnings', 'Upozornění k tankování per vozidlo',
    'Upozornění k tankování per vozidlo. Chybějící stav tachometru, nesouvislá řada stavů (nižší než u předchozího tankování nebo pod počátečním stavem vozidla) a nesoulad odpočtu DPH navázaného dokladu s nastavením vozidla.',
    '/logbook/fuelings/warnings', {
    query: { car_id: { type: 'integer', description: 'ID vozidla.', minimum: 1 }, year: { type: 'integer', description: 'Rok.' } },
  }),
  endpoint('list_logbook_odometer_gaps', 'Km k doplnění podle stavů tachometru',
    'Km k doplnění podle stavů tachometru. Pro každý stav tachometru, který převyšuje konec zapsaných jízd (a předchozí stav), vrátí mezeru s rozsahem dat a stavů.',
    '/logbook/odometer-gaps', {
    query: { car_id: { type: 'integer', description: 'ID vozidla.', minimum: 1 } },
  }),
  endpoint('fill_logbook_odometer_gap', 'Doplní km k doplnění jízdami',
    'Doplní km k doplnění jízdami. Vytvoří navazující jízdy od začátku mezery. Pro provedení je potřeba confirm: true.',
    '/logbook/odometer-gaps/fill', {
    method: 'POST',
    confirm: true,
    preview: async (c, a, tool) => {
      const gaps = await c.get('/logbook/odometer-gaps', null, tool);
      return gaps.find((gap) => Number(gap.reading_id) === a.reading_id) ?? null;
    },
    body: {
      reading_id: { type: 'integer', description: 'ID odečtu tachometru, ke kterému se doplňují kilometry.', minimum: 1 },
      trips: {
        type: 'array',
        description: 'Seznam jízd, kterými se má mezera v kilometrech vyplnit.',
        items: {
          type: 'object',
          description: 'Jedna doplňovaná jízda s údaji o cestě nebo odkazem na její šablonu.',
          properties: {
            template_id: { type: ['integer', 'null'], description: 'ID šablony.', minimum: 1 },
            trip_date: { type: 'string', description: 'Datum jízdy.', format: 'date' },
            time_start: { type: ['string', 'null'], description: 'Čas odjezdu.' },
            time_end: { type: ['string', 'null'], description: 'Čas příjezdu.' },
            distance_km: { type: 'number', description: 'Ujetá vzdálenost v kilometrech.' },
            category_id: { type: ['integer', 'null'], description: 'ID kategorie.', minimum: 1 },
            purpose: { type: ['string', 'null'], description: 'Účel jízdy.' },
            origin: { type: ['string', 'null'], description: 'Místo odjezdu.' },
            destination: { type: ['string', 'null'], description: 'Cíl jízdy.' },
          },
          additionalProperties: false,
        },
      },
      remainder_private: { type: 'boolean', description: 'Při `true` doplní zbývající kilometry jako soukromou jízdu.', default: false },
      remainder_purpose: { type: ['string', 'null'], description: 'Účel soukromé jízdy, výchozí „Soukromá jízda"' },
    },
    required: ['reading_id'],
  }),
  endpoint('list_logbook_odometer_readings', 'Stavy tachometru (od nejnovějšího, max 200)',
    'Stavy tachometru (od nejnovějšího, max 200).',
    '/logbook/odometer-readings', {
    query: { car_id: { type: 'integer', description: 'ID vozidla.', minimum: 1 } },
  }),
  endpoint('save_logbook_odometer_reading', 'Zapíše stav tachometru',
    'Zapíše ruční odečet tachometru. Opakovaný zápis pro stejné vozidlo a den přepíše dřívější ruční odečet.',
    '/logbook/odometer-readings', {
    method: 'POST',
    confirm: true,
    preview: async (c, a, tool) => {
      const readings = await c.get('/logbook/odometer-readings', { car_id: a.car_id }, tool);
      const reading = readings.find((row) => row.reading_date === a.reading_date && row.source === 'manual');
      if (reading) return { reading };
      return { reading: null, message: readings.length < 200
        ? 'Pro toto datum zatím není uložený ruční odečet.'
        : 'Ruční odečet nebyl nalezen mezi 200 posledními odečty vozidla.' };
    },
    body: {
      car_id: { type: 'integer', description: 'ID vozidla.', minimum: 1 },
      reading_date: { type: 'string', description: 'Den ručního odečtu tachometru.', format: 'date' },
      reading_time: { type: ['string', 'null'], description: 'HH:MM' },
      odometer: { type: 'number', description: 'Zaokrouhlí se na celé km', minimum: 0 },
      note: { type: ['string', 'null'], description: 'Volitelná poznámka.' },
    },
    required: ['car_id', 'reading_date', 'odometer'],
  }),
  endpoint('delete_logbook_odometer_reading', 'Smaže stav tachometru',
    'Smaže stav tachometru. Pro provedení je potřeba confirm: true.',
    '/logbook/odometer-readings/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: previewRecord('/logbook/odometer-readings', 'id'),
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('create_logbook_trip_category', 'Vytvoří kategorii cesty',
    'Vytvoří kategorii cesty.',
    '/logbook/trip-categories', {
    method: 'POST',
    body: {
      code: { type: 'string', description: 'A-Z, a-z, 0-9, _, - (max 30 znaků)' },
      label: { type: 'string', description: 'Max 100 znaků' },
    },
    required: ['code', 'label'],
  }),
  endpoint('update_logbook_trip_category', 'Upraví kategorii cesty',
    'Upraví zadané údaje kategorie jízdy. Vynechané hodnoty zůstanou uložené.',
    '/logbook/trip-categories/{id}', {
    method: 'PUT',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: { code: { type: 'string', description: 'Kód v číselníku.' }, label: { type: 'string', description: 'Krátký název akce.' } },
  }),
  endpoint('delete_logbook_trip_category', 'Smaže kategorii cesty',
    'Smaže kategorii cesty. Selže s 409, pokud jsou na kategorii navázané jízdy. v takovém případě ji lze místo smazání archivovat přes PUT. Pro provedení je potřeba confirm: true.',
    '/logbook/trip-categories/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: previewRecord('/logbook/trip-categories', 'id', { include_archived: 1 }),
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_logbook_trip_templates', 'Pravidelné cesty (šablony jízd)',
    'Pravidelné cesty (šablony jízd).',
    '/logbook/trip-templates', {
    query: { include_archived: { type: 'boolean', description: 'Při `true` zahrne také archivované pravidelné cesty.', default: false } },
  }),
  endpoint('create_logbook_trip_template', 'Vytvoří pravidelnou cestu',
    'Vytvoří pravidelnou cestu.',
    '/logbook/trip-templates', {
    method: 'POST',
    body: {
      car_id: { type: ['integer', 'null'], description: 'ID vozidla.', minimum: 1 },
      label: { type: 'string', description: 'Název pravidelné cesty.', maxLength: 120 },
      origin: { type: ['string', 'null'], description: 'Místo odjezdu.' },
      destination: { type: ['string', 'null'], description: 'Cíl jízdy.' },
      purpose: { type: ['string', 'null'], description: 'Účel jízdy.' },
      distance_km: { type: 'number', description: 'Ujetá vzdálenost v kilometrech.', exclusiveMinimum: 0 },
      category_id: { type: ['integer', 'null'], description: 'ID kategorie.', minimum: 1 },
      time_start: { type: ['string', 'null'], description: 'HH:MM' },
      time_end: { type: ['string', 'null'], description: 'HH:MM' },
      display_order: { type: 'integer', description: 'Pořadí zobrazení. Menší čísla se zobrazí dříve.' },
      is_archived: { type: 'boolean', description: 'Při `true` se šablona archivuje a skryje z běžné nabídky.' },
    },
    required: ['label', 'distance_km'],
  }),
  endpoint('update_logbook_trip_template', 'Upraví pravidelnou cestu',
    'Upraví zadané údaje pravidelné cesty. Volitelnou textovou hodnotu lze vymazat prázdným textem nebo hodnotou `null`.',
    '/logbook/trip-templates/{id}', {
    method: 'PUT',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: {
      car_id: { type: ['integer', 'null'], description: 'ID vozidla.', minimum: 1 },
      label: { type: 'string', description: 'Název pravidelné cesty.', maxLength: 120 },
      origin: { type: ['string', 'null'], description: 'Místo odjezdu.' },
      destination: { type: ['string', 'null'], description: 'Cíl jízdy.' },
      purpose: { type: ['string', 'null'], description: 'Účel jízdy.' },
      distance_km: { type: 'number', description: 'Ujetá vzdálenost v kilometrech.', exclusiveMinimum: 0 },
      category_id: { type: ['integer', 'null'], description: 'ID kategorie.', minimum: 1 },
      time_start: { type: ['string', 'null'], description: 'HH:MM' },
      time_end: { type: ['string', 'null'], description: 'HH:MM' },
      display_order: { type: 'integer', description: 'Pořadí zobrazení. Menší čísla se zobrazí dříve.' },
      is_archived: { type: 'boolean', description: 'Při `true` se šablona archivuje a skryje z běžné nabídky.' },
    },
    required: ['label', 'distance_km'],
  }),
  endpoint('delete_logbook_trip_template', 'Smaže pravidelnou cestu',
    'Smaže pravidelnou cestu. Jízdy si šablonu nepamatují, smazání je nezmění. Pro provedení je potřeba confirm: true.',
    '/logbook/trip-templates/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: previewRecord('/logbook/trip-templates', 'id', { include_archived: 1 }),
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('suggest_logbook_trip_places', 'Našeptávač míst cest',
    'Vrátí místa odjezdu a příjezdu z dřívějších cest firmy.',
    '/logbook/trips/places'),
  endpoint('suggest_logbook_trip_purposes', 'Našeptávač účelů cest',
    'Vrátí dříve používané účely jízd firmy.',
    '/logbook/trips/purposes'),
  endpoint('recalculate_logbook_trip_odometers', 'Přepočet stavu tachometru',
    'Přepočet stavu tachometru. Naváže stavy tachometru jízd jednoho auta v chronologickém pořadí: začátek jízdy = konec předchozí, konec = začátek + ujeté km. Pro provedení je potřeba confirm: true.',
    '/logbook/trips/recalculate-odometer', {
    method: 'POST',
    confirm: true,
    preview: previewOdometerRecalculation,
    body: {
      after_trip_id: { type: 'integer', description: 'ID jízdy, po které má přepočet začít.', minimum: 1 },
      car_id: { type: 'integer', description: 'ID vozidla.', minimum: 1 },
    },
  }),
];
