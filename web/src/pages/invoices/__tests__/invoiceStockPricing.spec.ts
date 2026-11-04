import { describe, expect, it } from 'vitest'
import {
  availabilityQuantity,
  availabilityUnit,
  quotedUnitPrice,
  shouldRequoteOnUnitChange,
  usesStockPricingFeatures,
  canApplyQuote,
  clientHasPriceLevel,
  convertUnitPrice,
  legacyRestorePrice,
  planContextRepricing,
  usesStockQuote,
  priceForMissingQuote,
  initialStockUnit,
  isPriceStillAuto,
  legacyStockSelection,
  packagingRatio,
  quoteFailureFallback,
  quoteQuantity,
  rowUnitOptions,
  rowsToConfirmRepricing,
  rowsToRequote,
  stockUnitChoices,
  toBaseQuantity,
  unitAfterQuote,
  unitPriceForUnit,
  type PendingQuote,
  type StockQuoteState,
} from '@/pages/invoices/invoiceStockPricing'

const units = [
  { unit_code: 'KT', numerator: 8, denominator: 1 },
  { unit_code: 'PAL', numerator: 80, denominator: 1 },
  { unit_code: 'BAL', numerator: 1, denominator: 3 },
]

const auto = (price: number | null): StockQuoteState => ({ seq: 1, autoPrice: price, source: 'standard', discountPct: null })

describe('invoice stock pricing', () => {
  it('nabídne základní jednotku, balení karty a zachová uloženou jinou jednotku', () => {
    expect(stockUnitChoices('ks', units)).toEqual(['ks', 'KT', 'PAL', 'BAL'])
    expect(stockUnitChoices('ks', units, 'kt')).toEqual(['ks', 'KT', 'PAL', 'BAL'])
    expect(stockUnitChoices('ks', units, 'hod')).toEqual(['ks', 'KT', 'PAL', 'BAL', 'hod'])
    expect(stockUnitChoices('ks', [{ unit_code: 'KS', numerator: 1, denominator: 1 }])).toEqual(['ks'])
  })

  it('po výběru karty zvolí shodu EAN balení, pak výchozí prodejní jednotku, jinak základní', () => {
    expect(initialStockUnit({ unit: 'ks', units, matched_unit: 'pal', default_sale_unit: 'KT' })).toBe('PAL')
    expect(initialStockUnit({ unit: 'ks', units, matched_unit: null, default_sale_unit: 'KT' })).toBe('KT')
    expect(initialStockUnit({ unit: 'ks', units, default_sale_unit: 'XX' })).toBe('ks')
    expect(initialStockUnit({ unit: 'ks' })).toBe('ks')
  })

  it('přepočte množství na základní jednotku, neznámou jednotku bere 1:1', () => {
    expect(toBaseQuantity(10, 'KT', 'ks', units)).toBe(80)
    expect(toBaseQuantity('2', 'kt', 'ks', units)).toBe(16)
    expect(toBaseQuantity(1, 'BAL', 'ks', units)).toBe(0.333)
    expect(toBaseQuantity(5, 'ks', 'ks', units)).toBe(5)
    expect(toBaseQuantity(5, 'hod', 'ks', units)).toBe(5)
    expect(toBaseQuantity(-3, 'KT', 'ks', units)).toBe(-24)
    expect(packagingRatio('ks', 'ks', units)).toBeNull()
    expect(packagingRatio('hod', 'ks', units)).toBeNull()
    expect(packagingRatio('pal', 'ks', units)).toEqual({ numerator: 80, denominator: 1 })
  })

  it('záložní cena balení vynásobí cenu za základní jednotku poměrem', () => {
    expect(unitPriceForUnit(154.32, 'KT', 'ks', units)).toBe(1234.56)
    expect(unitPriceForUnit(10, 'BAL', 'ks', units)).toBe(3.333333)
    expect(unitPriceForUnit(10, 'ks', 'ks', units)).toBe(10)
    expect(unitPriceForUnit(0.0108, 'ks', 'ks', units)).toBe(0.0108)
    expect(unitPriceForUnit(0.004, 'KT', 'ks', units)).toBe(0.032)
  })

  it('nacení kladným množstvím, prázdné jako jeden kus', () => {
    expect(quoteQuantity(-2)).toBe('2')
    expect(quoteQuantity(0)).toBe('1')
    expect(quoteQuantity('1.5')).toBe('1.5')
    expect(quoteQuantity(null)).toBe('1')
  })

  it('pozná ručně přepsanou cenu a znovu nacení jen automatické řádky karet', () => {
    expect(isPriceStillAuto(auto(1234.56), 1234.56)).toBe(true)
    expect(isPriceStillAuto(auto(1234.56), '1234.560')).toBe(true)
    expect(isPriceStillAuto(auto(1234.56), 1200)).toBe(false)
    expect(isPriceStillAuto(auto(null), 0)).toBe(false)
    expect(isPriceStillAuto(undefined, 10)).toBe(false)

    const rows = [
      { id: 'auto', stock_item_id: 1, unit: 'ks', unit_price_without_vat: 100 },
      { id: 'manual', stock_item_id: 2, unit: 'ks', unit_price_without_vat: 90 },
      { id: 'hydrated', stock_item_id: 3, unit: 'ks', unit_price_without_vat: 50 },
      { id: 'free-text', stock_item_id: null, unit: 'ks', unit_price_without_vat: 100 },
    ]
    const states: Record<string, StockQuoteState> = {
      auto: auto(100),
      manual: auto(100),
      'free-text': auto(100),
    }
    expect(rowsToRequote(rows, row => states[row.id], { stockEnabled: true, loaded: true }).map(row => row.id)).toEqual(['auto'])
  })
})

describe('invoice stock pricing: žádná regrese editoru faktury', () => {
  const si = { unit: 'ks', effective_price: '99.00', sale_price_without_vat: '120.00', promo_price: '99.00' }
  const on = { stockEnabled: true, loaded: true }
  const pending = (over: Partial<PendingQuote> = {}): PendingQuote => ({
    seq: 7, stockItemId: 1, requestUnit: 'KT', unitAtRequest: 'ks', priceAtRequest: 99, ...over,
  })

  it('záporné ceny se nenaceňují automaticky ani při změně balení', () => {
    const row = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: -100 }
    expect(rowsToRequote([row], () => auto(-100), on)).toEqual([])
    expect(shouldRequoteOnUnitChange(auto(-100), -100, 'ks', 'KT', 'ks', units)).toBe(false)
    expect(convertUnitPrice(-12.5, 'ks', 'KT', 'ks', units)).toBe(-100)
  })

  it('sklad vypnutý: jednotky z číselníku a nic se nepřeceňuje', () => {
    const row = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 100 }
    expect(rowUnitOptions(row, { stockEnabled: false, features: true }, 'ks', units)).toBeNull()
    expect(rowsToRequote([row], () => auto(100), { stockEnabled: false, loaded: true })).toEqual([])
  })

  it('řádek bez skladové karty: jednotky z číselníku, bez přecenění i přepočtu dostupnosti', () => {
    const row = { stock_item_id: null, unit: 'hod', unit_price_without_vat: 100 }
    expect(rowUnitOptions(row, { stockEnabled: true, features: true }, 'ks', units)).toBeNull()
    expect(rowsToRequote([row], () => auto(100), on)).toEqual([])
    expect(availabilityQuantity(-2.5, 'hod', 'hod', [])).toBe(2.5)
  })

  it('hydratace dokladu: před dokončením načtení ani u řádků bez stavu z této relace se nepřeceňuje', () => {
    const autoRow = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 100 }
    const loadedRow = { stock_item_id: 2, unit: 'KT', unit_price_without_vat: 800 }
    expect(rowsToRequote([autoRow], () => auto(100), { stockEnabled: true, loaded: false })).toEqual([])
    expect(rowsToRequote([loadedRow], () => undefined, on)).toEqual([])
  })

  it('uložená jiná jednotka skladového řádku zůstane v nabídce a nepřepíše se', () => {
    const row = { stock_item_id: 1, unit: 'bal.', unit_price_without_vat: 100 }
    expect(rowUnitOptions(row, { stockEnabled: true, features: true }, 'ks', units)).toEqual(['ks', 'KT', 'PAL', 'BAL', 'bal.'])
    expect(rowUnitOptions(row, { stockEnabled: true, features: true }, 'ks', units, ['ks', 'hod', 'kg'])).toEqual(['ks', 'KT', 'PAL', 'BAL', 'bal.', 'hod', 'kg'])
    expect(unitAfterQuote('unit_change', 'bal.', { unit: null, base_unit: 'ks' })).toBeNull()
    expect(unitAfterQuote('context', 'KT', { unit: 'KT', base_unit: 'ks' })).toBeNull()
  })

  it('ruční přepis ceny: řádek se po změně zákazníka nepřecení a rozběhnutou odpověď zahodí', () => {
    const row = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 85 }
    expect(rowsToRequote([row], () => auto(99), on)).toEqual([])
    expect(canApplyQuote(pending(), row, { ...auto(99), seq: 7 })).toBe(false)
  })

  it('změna zákazníka: automatickou cenu přecení, starou nebo cizí odpověď ne', () => {
    const row = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 99 }
    expect(rowsToRequote([row], () => auto(99), on)).toEqual([row])
    expect(canApplyQuote(pending(), row, { ...auto(99), seq: 7 })).toBe(true)
    expect(canApplyQuote(pending(), row, { ...auto(99), seq: 8 })).toBe(false)
    expect(canApplyQuote(pending(), { ...row, stock_item_id: 2 }, { ...auto(99), seq: 7 })).toBe(false)
    expect(canApplyQuote(pending(), { ...row, unit: 'PAL' }, { ...auto(99), seq: 7 })).toBe(false)
  })

  it('po výběru karty nastaví balení jen z úspěšného nacenění', () => {
    expect(unitAfterQuote('select', 'KT', { unit: 'KT', base_unit: 'ks' })).toBe('KT')
    expect(unitAfterQuote('select', 'KT', { unit: null, base_unit: 'ks' })).toBe('ks')
  })

  it('selhání nacenění: po výběru karty dosavadní jednotka, cena i toast, jinak řádek beze změny', () => {
    expect(legacyStockSelection(si)).toEqual({ unit: 'ks', price: 99, promoToast: true })
    expect(quoteFailureFallback('select', si)).toEqual({ unit: 'ks', price: 99, promoToast: true })
    expect(quoteFailureFallback('select', { unit: 'ks', sale_price_without_vat: '120.00' })).toEqual({ unit: 'ks', price: 120, promoToast: false })
    expect(quoteFailureFallback('select', { unit: 'ks', sale_price_without_vat: null })).toEqual({ unit: 'ks', price: null, promoToast: false })
    expect(quoteFailureFallback('unit_change', si)).toBeNull()
    expect(quoteFailureFallback('context', si)).toBeNull()
  })

  it('dostupnost: základní množství jen u balení se známým poměrem, jinak původní množství', () => {
    expect(availabilityQuantity(10, 'KT', 'ks', units)).toBe(80)
    expect(availabilityQuantity(-3, 'kt', 'ks', units)).toBe(24)
    expect(availabilityQuantity(1.23456, 'ks', 'ks', units)).toBe(1.23456)
    expect(availabilityQuantity(1.23456, 'hod', 'ks', units)).toBe(1.23456)
    expect(availabilityQuantity('4', 'KT', 'ks', [])).toBe(4)
  })
})

describe('invoice stock pricing: karta bez balení a individuálních cen', () => {
  const plain = { unit: 'ks', units: [], has_customer_prices: false, effective_price: '99.00', sale_price_without_vat: '120.00', promo_price: '99.00' }

  it('karta bez funkcí jde původní cestou: jednotka karty, effective_price, toast, žádné nacenění ani vlastní nabídka jednotek', () => {
    expect(usesStockPricingFeatures(plain)).toBe(false)
    expect(usesStockPricingFeatures({ units: null })).toBe(false)
    expect(usesStockPricingFeatures({ units, has_customer_prices: false })).toBe(true)
    expect(usesStockPricingFeatures({ units: [], has_customer_prices: true })).toBe(true)
    expect(legacyStockSelection(plain)).toEqual({ unit: 'ks', price: 99, promoToast: true })
    expect(rowUnitOptions({ stock_item_id: 1, unit: 'ks' }, { stockEnabled: true, features: false }, 'ks', [], ['ks', 'hod'])).toBeNull()
    expect(rowsToRequote([{ stock_item_id: 1, unit: 'ks', unit_price_without_vat: 99 }], () => undefined, { stockEnabled: true, loaded: true })).toEqual([])
    expect(availabilityUnit('ks', 'ks', [])).toBe('ks')
  })

  it('backend bez ceny v měně (unit_price null) řádek nepřepíše nulou', () => {
    expect(quotedUnitPrice({ unit_price: null })).toBeNull()
    expect(quotedUnitPrice({})).toBeNull()
    expect(quotedUnitPrice({ unit_price: '' })).toBeNull()
    expect(quotedUnitPrice({ unit_price: 'abc' })).toBeNull()
    expect(quotedUnitPrice({ unit_price: '0.00' })).toBe(0)
    expect(quotedUnitPrice({ unit_price: '1234.56' })).toBe(1234.56)
  })

  it('bez ceny z nacenění přepočte dosavadní cenu mezi jednotkami podle balení', () => {
    expect(convertUnitPrice(12.5, 'ks', 'KT', 'ks', units)).toBe(100)
    expect(convertUnitPrice(100, 'KT', 'PAL', 'ks', units)).toBe(1000)
    expect(convertUnitPrice(100, 'KT', 'ks', 'ks', units)).toBe(12.5)
    expect(convertUnitPrice(0, 'ks', 'PAL', 'ks', units)).toBe(0)
    expect(convertUnitPrice(90, 'ks', 'hod', 'ks', units)).toBe(90)
  })

  it('bez ceny z nacenění přepočte jen automatickou cenu, sazbu klienta ani ruční cenu nechá', () => {
    expect(priceForMissingQuote(auto(12.5), 12.5, 'ks', 'KT', 'ks', units)).toBe(100)
    expect(priceForMissingQuote(undefined, 1500, 'ks', 'PAL', 'ks', units)).toBeNull()
    expect(priceForMissingQuote(auto(12.5), 99, 'ks', 'KT', 'ks', units)).toBeNull()
    expect(priceForMissingQuote(auto(0), 0, 'ks', 'PAL', 'ks', units)).toBeNull()
  })

  it('změna jednotky: ruční cenu mezi jednotkami 1:1 nepřepíše, u balení nebo automatické ceny přecení', () => {
    const manual: StockQuoteState = { seq: 1, autoPrice: 100, source: 'standard', discountPct: null }
    expect(shouldRequoteOnUnitChange(manual, 90, 'ks', 'hod', 'ks', units)).toBe(false)
    expect(shouldRequoteOnUnitChange(undefined, 90, 'ks', 'hod', 'ks', units)).toBe(false)
    expect(shouldRequoteOnUnitChange(manual, 90, 'ks', 'KT', 'ks', units)).toBe(true)
    expect(shouldRequoteOnUnitChange(manual, 90, 'KT', 'PAL', 'ks', units)).toBe(true)
    expect(shouldRequoteOnUnitChange(manual, 100, 'ks', 'hod', 'ks', units)).toBe(true)
    expect(shouldRequoteOnUnitChange(manual, 100, 'KT', 'kt', 'ks', units)).toBe(false)
  })

  it('text dostupnosti: základní jednotka jen u balení se známým poměrem', () => {
    expect(availabilityUnit('KT', 'ks', units)).toBe('ks')
    expect(availabilityUnit('hod', 'ks', units)).toBe('hod')
    expect(availabilityUnit('ks', 'ks', units)).toBe('ks')
  })
})

describe('invoice stock pricing: cenové hladiny odběratelů', () => {
  const on = { stockEnabled: true, loaded: true }
  const plainCard = { unit: 'ks', effective_price: '99.00', sale_price_without_vat: '120.00', promo_price: '99.00' }
  const noFeatures = () => false

  it('odběratel bez hladiny je „Default", s hladinou zapíná novou logiku', () => {
    expect(clientHasPriceLevel({ price_level_id: 3 })).toBe(true)
    expect(clientHasPriceLevel({ price_level_id: null })).toBe(false)
    expect(clientHasPriceLevel({})).toBe(false)
    expect(clientHasPriceLevel(undefined)).toBe(false)
  })

  it('nacenění jen se zapnutým skladem, u řádku s kartou a když to zapíná karta nebo hladina odběratele', () => {
    const row = { stock_item_id: 1 }
    expect(usesStockQuote(row, { stockEnabled: true, cardFeatures: false, clientHasLevel: true })).toBe(true)
    expect(usesStockQuote(row, { stockEnabled: true, cardFeatures: true, clientHasLevel: false })).toBe(true)
    expect(usesStockQuote(row, { stockEnabled: true, cardFeatures: false, clientHasLevel: false })).toBe(false)
    expect(usesStockQuote(row, { stockEnabled: false, cardFeatures: true, clientHasLevel: true })).toBe(false)
    expect(usesStockQuote({ stock_item_id: null }, { stockEnabled: true, cardFeatures: true, clientHasLevel: true })).toBe(false)
  })

  it('karta bez funkcí + odběratel s hladinou: automatický řádek jde do nacenění', () => {
    const row = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 99 }
    const plan = planContextRepricing([row], () => auto(99), { ...on, clientHasLevel: true }, noFeatures)
    expect(plan.quote).toEqual([row])
    expect(plan.legacy).toEqual([])
  })

  it('přepnutí na odběratele Default: karta bez funkcí dostane zpět původní cenu, karta s funkcemi se nacení', () => {
    const plain = { id: 'plain', stock_item_id: 1, unit: 'ks', unit_price_without_vat: 89.1 }
    const packed = { id: 'packed', stock_item_id: 2, unit: 'KT', unit_price_without_vat: 800 }
    const states: Record<string, StockQuoteState> = { plain: auto(89.1), packed: auto(800) }
    const plan = planContextRepricing([plain, packed], row => states[row.id], { ...on, clientHasLevel: false }, row => row.id === 'packed')
    expect(plan.legacy.map(row => row.id)).toEqual(['plain'])
    expect(plan.quote.map(row => row.id)).toEqual(['packed'])
    expect(legacyRestorePrice(plainCard)).toBe(99)
    expect(legacyRestorePrice({ unit: 'ks', sale_price_without_vat: '120.00' })).toBe(120)
  })

  it('ruční cena, načtený doklad, řádek bez karty i vypnutý sklad zůstanou beze změny', () => {
    const manual = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 85 }
    const hydrated = { stock_item_id: 2, unit: 'ks', unit_price_without_vat: 50 }
    const freeText = { stock_item_id: null, unit: 'hod', unit_price_without_vat: 100 }
    const stateOf = (row: { stock_item_id: number | null }) => row.stock_item_id === 2 ? undefined : auto(row.stock_item_id === 1 ? 99 : 100)
    for (const clientHasLevel of [true, false]) {
      expect(planContextRepricing([manual, hydrated, freeText], stateOf, { ...on, clientHasLevel }, noFeatures)).toEqual({ quote: [], legacy: [] })
      expect(planContextRepricing([{ ...manual, unit_price_without_vat: 99 }], () => auto(99), { stockEnabled: false, loaded: true, clientHasLevel }, noFeatures)).toEqual({ quote: [], legacy: [] })
      expect(planContextRepricing([{ ...manual, unit_price_without_vat: 99 }], () => auto(99), { stockEnabled: true, loaded: false, clientHasLevel }, noFeatures)).toEqual({ quote: [], legacy: [] })
    }
  })

  it('karta bez ceny: návrat původní ceny nic nepřepíše nulou', () => {
    expect(legacyRestorePrice({ unit: 'ks', sale_price_without_vat: null })).toBeNull()
    expect(legacyRestorePrice({ unit: 'ks', effective_price: null, sale_price_without_vat: 'abc' })).toBeNull()
    expect(legacyRestorePrice(undefined)).toBeNull()
  })
})

describe('invoice stock pricing: přecenění uloženého dokladu po změně hladiny', () => {
  const on = { stockEnabled: true, loaded: true, storedDocument: true }

  it('zápornou jednotkovou cenu nepřecení, dobropis se záporným množstvím ponechá ve výběru', () => {
    const returned = { stock_item_id: 1, unit: 'ks', quantity: 1, unit_price_without_vat: -100 }
    const credit = { stock_item_id: 2, unit: 'ks', quantity: -1, unit_price_without_vat: 100 }
    expect(rowsToConfirmRepricing([returned, credit], () => undefined, on)).toEqual([credit])
  })

  it('nabídne skladové řádky s uloženou i ručně přepsanou cenou, automatické nechá automatice', () => {
    const stored = { id: 'stored', stock_item_id: 1, unit: 'ks', unit_price_without_vat: 100 }
    const manual = { id: 'manual', stock_item_id: 2, unit: 'ks', unit_price_without_vat: 85 }
    const autoRow = { id: 'auto', stock_item_id: 3, unit: 'ks', unit_price_without_vat: 99 }
    const freeText = { id: 'text', stock_item_id: null, unit: 'hod', unit_price_without_vat: 100 }
    const states: Record<string, StockQuoteState | undefined> = { manual: auto(99), auto: auto(99) }
    const rows = rowsToConfirmRepricing([stored, manual, autoRow, freeText], row => states[row.id], on)
    expect(rows.map(row => row.id)).toEqual(['stored', 'manual'])
  })

  it('nový doklad, vypnutý sklad a doklad před dokončením načtení se neptají', () => {
    const stored = { stock_item_id: 1, unit: 'ks', unit_price_without_vat: 100 }
    expect(rowsToConfirmRepricing([stored], () => undefined, { ...on, storedDocument: false })).toEqual([])
    expect(rowsToConfirmRepricing([stored], () => undefined, { ...on, stockEnabled: false })).toEqual([])
    expect(rowsToConfirmRepricing([stored], () => undefined, { ...on, loaded: false })).toEqual([])
  })
})
