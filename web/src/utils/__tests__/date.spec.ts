import { describe, expect, it } from 'vitest'
import { addDaysIso, addMonthsIso, appIsoDate, appYear, overdueDays } from '../date'

describe('overdueDays', () => {
  it.each([
    ['2026-09-07', '2026-09-07T21:59:59Z', 0],
    ['2026-09-07', '2026-09-07T22:00:00Z', 1],
    ['2026-03-29', '2026-03-29T22:00:00Z', 1],
    ['2026-10-25', '2026-10-25T23:00:00Z', 1],
    ['2026-12-31', '2026-12-31T23:00:00Z', 1],
    ['2028-02-28', '2028-03-01T12:00:00Z', 2],
    ['2026-09-08', '2026-09-07T12:00:00Z', 0],
    ['', '2026-09-07T12:00:00Z', 0],
  ])('splatnost %s v okamžiku %s znamená %i dní', (due, now, expected) => {
    expect(overdueDays(due, new Date(now))).toBe(expected)
  })
})

describe('appIsoDate', () => {
  it('vrací kalendářní datum v účetní zóně, ne v UTC', () => {
    // 00:30 pražského času (letní čas) = 22:30 UTC předchozího dne.
    expect(appIsoDate(new Date('2026-04-01T22:30:00Z'))).toBe('2026-04-02')
  })

  /**
   * Účetní na dovolené v USA je běžná situace. V 19:00 newyorského času je
   * v Praze 01:00 NÁSLEDUJÍCÍHO dne — a doklad se vystavuje do českého
   * kalendáře, ne do toho, který má účetní na hodinkách. Kdyby se datum bralo
   * z prohlížeče, doklad vystavený poslední den v měsíci by spadl do jiného
   * zdaňovacího období než ten, který ve stejnou chvíli vystaví kolega v Praze.
   *
   * Asserce jsou psané přes okamžik v UTC, takže platí bez ohledu na to,
   * v jaké zóně běží test.
   */
  it('drží český kalendář i pro prohlížeč v jiné zóně', () => {
    // 31. 3. 2026, 19:00 New York (EDT, UTC-4) = 1. 4. 2026, 01:00 Praha.
    expect(appIsoDate(new Date('2026-03-31T23:00:00Z'))).toBe('2026-04-01')
    // 1. 4. 2026, 09:00 Tokio (UTC+9) = 1. 4. 2026, 02:00 Praha.
    expect(appIsoDate(new Date('2026-04-01T00:00:00Z'))).toBe('2026-04-01')
  })

  it('doplňuje nuly', () => {
    expect(appIsoDate(new Date('2026-01-05T12:00:00Z'))).toBe('2026-01-05')
  })
})

describe('addMonthsIso', () => {
  it('drží stejný den v měsíci', () => {
    expect(addMonthsIso('2026-03-01', 1)).toBe('2026-04-01')
  })

  it('u kratšího měsíce vrací jeho poslední den', () => {
    expect(addMonthsIso('2026-01-31', 1)).toBe('2026-02-28')
    expect(addMonthsIso('2028-01-31', 1)).toBe('2028-02-29')
  })

  it('přetéká přes konec roku', () => {
    expect(addMonthsIso('2026-11-15', 3)).toBe('2027-02-15')
  })

  /**
   * Regrese platebního kalendáře: splátky se generovaly opakovaným voláním nad
   * vlastním výstupem, takže posun o den se sčítal (1. 3. → 31. 3. → 29. 4. → …).
   * Datum splátky je podle § 31 ZDPH DUZP, takže to přesouvalo splátky do jiného
   * zdaňovacího období.
   */
  it('iterativní volání neposouvá datum', () => {
    let date = '2026-03-01'
    const dates: string[] = []
    for (let i = 0; i < 12; i++) {
      dates.push(date)
      date = addMonthsIso(date, 1)
    }
    expect(dates).toEqual([
      '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01',
      '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01',
      '2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01',
    ])
  })
})

describe('addDaysIso', () => {
  it('počítá kalendářně přes konec měsíce', () => {
    expect(addDaysIso('2026-01-30', 3)).toBe('2026-02-02')
  })

  /** Přechod na letní čas (29. 3. 2026) nesmí datum přetéct na sousední den. */
  it('přežije přechod letního času', () => {
    expect(addDaysIso('2026-03-28', 2)).toBe('2026-03-30')
    expect(addDaysIso('2026-10-24', 2)).toBe('2026-10-26')
  })

  it('umí odečítat', () => {
    expect(addDaysIso('2026-03-01', -1)).toBe('2026-02-28')
  })
})

describe('appYear', () => {
  it('bere rok ze stejného kalendáře jako appIsoDate', () => {
    // 31. 12. 2026, 23:30 UTC = 1. 1. 2027, 00:30 v Praze.
    expect(appYear(new Date('2026-12-31T23:30:00Z'))).toBe('2027')
    expect(appIsoDate(new Date('2026-12-31T23:30:00Z'))).toBe('2027-01-01')
  })
})

describe('odolnost proti nesmyslnému vstupu', () => {
  /**
   * Formulář fakturace přepočítává splatnost při každé změně klienta. S vymazaným
   * datem vystavení dřív vznikl řetězec 'NaN-NaN-NaN', uložil se do `due_date`
   * a odešel na API.
   */
  it('vrací vstup beze změny místo NaN', () => {
    expect(addDaysIso('', 14)).toBe('')
    expect(addDaysIso('2026-13', 14)).toBe('2026-13')
    expect(addMonthsIso('', 1)).toBe('')
    expect(addMonthsIso('nesmysl', 1)).toBe('nesmysl')
  })
})
