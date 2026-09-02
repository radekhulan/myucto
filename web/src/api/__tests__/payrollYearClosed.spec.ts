import { describe, expect, it } from 'vitest'
import { yearClosedTarget } from '@/api/errors'

/**
 * Uzavřený mzdový rok blokuje zápis v pěti agendách, které o uzávěrce nic
 * nevědí (nepřítomnosti, exekuce, závazky, podání, běhy). Hláška o tom uměla
 * jen říct rok a poradit „rok nejprve znovu otevřete" — nikam nevedla a
 * uzávěrka se dělá jednou za rok, takže tu cestu nikdo nezná zpaměti.
 */
describe('yearClosedTarget', () => {
  function error(code: string, extra: Record<string, unknown> = {}) {
    return { response: { data: { error: { code, message: 'x', ...extra } } } }
  }

  it('vede na roční uzávěrku s rokem, o který šlo', () => {
    expect(yearClosedTarget(error('payroll_year_closed', { year: 2025 }))).toEqual({
      name: 'payroll-dashboard',
      query: { yearCloseYear: '2025' },
      hash: '#payroll-year-close',
    })
  })

  /*
   * Starší odpověď rok neposílala. Proklik se kvůli tomu nesmí ztratit —
   * uzávěrka se otevře na svém výchozím roce, což je pořád lepší než věta
   * bez cesty.
   */
  it('bez roku vede aspoň na uzávěrku', () => {
    expect(yearClosedTarget(error('payroll_year_closed'))).toEqual({
      name: 'payroll-dashboard',
      hash: '#payroll-year-close',
    })
  })

  it('jinou chybu nikam nesměruje', () => {
    expect(yearClosedTarget(error('validation_failed', { year: 2025 }))).toBeNull()
    expect(yearClosedTarget(new Error('síť'))).toBeNull()
  })
})
