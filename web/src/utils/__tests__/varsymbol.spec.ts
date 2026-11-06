import { describe, expect, it } from 'vitest'
import { digitSkeleton, hasCounterPlaceholder, renderVarsymbolTemplate, templatesCollide, varsymbolSeriesType } from '../varsymbol'

// Zrcadlo api/tests/Unit/Service/Invoice/InvoiceNumberFormatTest.php — stejné případy
// musí dát stejný výsledek v obou jazycích. Rozejít se smí jen jedno: číslo dokladu,
// protože živý náhled ve formuláři by pak sliboval jinou řadu, než jakou server přidělí.
describe('renderVarsymbolTemplate — posun v datumových tokenech', () => {
  const cases: Array<[string, string, string]> = [
    ['{YY}{MM}{CCC}', '2026-08-11', '2608007'],
    ['{YY+30}{MM}{CCC}', '2026-08-11', '5608007'],
    ['{YY+30}{MM}{CCC}', '2026-12-31', '5612007'],
    ['{YYYY+1}{CCCCCC}', '2026-08-11', '2027000007'],
    ['{YY-1}{CCC}', '2026-01-01', '25007'],
    ['{MM+8}/{YY}', '2026-05-15', '01/26'],
    ['{MM-1}{CCC}', '2026-01-15', '12007'],
    ['FA{YYYY}-{CCCC}', '2026-08-11', 'FA2026-0007'],
  ]

  it.each(cases)('%s @ %s → %s', (template, date, expected) => {
    const [y, m, d] = date.split('-').map(Number)
    expect(renderVarsymbolTemplate(template, new Date(y, m - 1, d), 7)).toBe(expected)
  })

  it('posun po měsících je kotvený na 1. den — 31. 1. + 1 měsíc je únor, ne březen', () => {
    expect(renderVarsymbolTemplate('{MM+1}{CCC}', new Date(2026, 0, 31), 7)).toBe('02007')
  })

  it('neznámý token zůstává beze změny', () => {
    expect(renderVarsymbolTemplate('{FOO}{CCC}', new Date(2026, 7, 11), 7)).toBe('{FOO}007')
  })

  it('counter placeholder se pozná i vedle posunutého tokenu', () => {
    expect(hasCounterPlaceholder('{YY+30}{CCC}')).toBe(true)
    expect(hasCounterPlaceholder('{YY+30}{MM}')).toBe(false)
  })
})

describe('digitSkeleton — posun je součástí identity tokenu', () => {
  it('stejná šířka, jiná hodnota → NENÍ kolize', () => {
    expect(digitSkeleton('{YY+30}{CCC}')).toBe('Y2+30|C3')
    expect(digitSkeleton('{YY}{CCC}')).toBe('Y2|C3')
    expect(templatesCollide('{YY+30}{CCC}', '{YY}{CCC}')).toBe(false)
  })

  it('záporný posun u měsíce', () => {
    expect(digitSkeleton('{MM-1}{CCC}')).toBe('M2-1|C3')
    expect(templatesCollide('{MM-1}{CCC}', '{MM}{CCC}')).toBe(false)
  })

  it('shodné šablony s posunem kolidují dál', () => {
    expect(templatesCollide('{YY+30}{CCC}', '{YY+30}{CCC}')).toBe(true)
  })

  it('posun 0 je totéž co bez posunu', () => {
    expect(digitSkeleton('{YY+0}{CCC}')).toBe(digitSkeleton('{YY}{CCC}'))
  })
})

// Zrcadlo VarsymbolGenerator::seriesTypeFor() (PHP), editor podle ní žádá náhled
// čísla a nastavení podle ní hlídá kolize řad.
describe('varsymbolSeriesType, řada zjednodušeného dokladu', () => {
  it('zjednodušená faktura jde do řady zjednodušených dokladů', () => {
    expect(varsymbolSeriesType('invoice', true)).toBe('simplified')
  })

  it('běžná faktura zůstává v řadě faktur', () => {
    expect(varsymbolSeriesType('invoice', false)).toBe('invoice')
    expect(varsymbolSeriesType('invoice', undefined)).toBe('invoice')
  })

  it('příznak u jiného typu dokladu řadu nemění', () => {
    expect(varsymbolSeriesType('proforma', true)).toBe('proforma')
    expect(varsymbolSeriesType('credit_note', true)).toBe('credit_note')
    expect(varsymbolSeriesType('payment_calendar', true)).toBe('payment_calendar')
    expect(varsymbolSeriesType('rent_calendar', true)).toBe('rent_calendar')
    expect(varsymbolSeriesType('advance_calendar', true)).toBe('advance_calendar')
  })
})
