import { describe, expect, it } from 'vitest'
import type { PayrollTimeEntry } from '@/api/payroll'
import {
  buildPayrollGridBatch,
  formatPayrollGridHours,
  isWorkedCategory,
  parsePayrollGridHours,
  payrollDayPlans,
  payrollGridCellKey,
  payrollGridCellState,
  payrollGridFlags,
  payrollGridNextPosition,
  payrollGridWorkedMinutes,
  payrollMonthDays,
} from '@/pages/payroll/payrollTimeGrid'

function entry(overrides: Partial<PayrollTimeEntry> & { starts_at: string }): PayrollTimeEntry {
  return {
    id: 1,
    employment_id: 12,
    series_key: 'a',
    revision_no: 1,
    category: 'regular',
    ends_at: overrides.starts_at,
    timezone_name: 'Europe/Prague',
    break_minutes: 0,
    net_minutes: 480,
    source_kind: 'manual',
    status: 'draft',
    row_version: 1,
    ...overrides,
  }
}

describe('payrollMonthDays', () => {
  it('zná délku měsíce i den v týdnu nezávisle na pásmu prohlížeče', () => {
    const days = payrollMonthDays('2026-02')
    expect(days).toHaveLength(28)
    expect(days[0]).toEqual({ date: '2026-02-01', day: 1, weekday: 7, weekend: true })
    expect(days[1].weekday).toBe(1)
    expect(days[1].weekend).toBe(false)
  })

  it('přestupný únor má devětadvacet dnů', () => {
    expect(payrollMonthDays('2028-02')).toHaveLength(29)
  })

  it('nesmyslné období nedá žádný den místo výjimky', () => {
    expect(payrollMonthDays('2026-13')).toEqual([])
    expect(payrollMonthDays('nesmysl')).toEqual([])
  })
})

describe('parsePayrollGridHours', () => {
  it('bere celé hodiny, dvojtečku i desetinnou čárku', () => {
    expect(parsePayrollGridHours('8')).toBe(480)
    expect(parsePayrollGridHours('8:30')).toBe(510)
    expect(parsePayrollGridHours('7,5')).toBe(450)
    expect(parsePayrollGridHours('7.5')).toBe(450)
  })

  it('prázdno je prázdno, ne nula', () => {
    expect(parsePayrollGridHours('   ')).toBeNull()
  })

  it('nečitelný a přestřelený zápis se rozliší od prázdna', () => {
    expect(parsePayrollGridHours('osm')).toBe(false)
    expect(parsePayrollGridHours('25')).toBe(false)
    expect(parsePayrollGridHours('8:75')).toBe(false)
  })
})

describe('formatPayrollGridHours', () => {
  it('celé hodiny bez dvojtečky, zbytek s ní, nula prázdná', () => {
    expect(formatPayrollGridHours(480)).toBe('8')
    expect(formatPayrollGridHours(510)).toBe('8:30')
    expect(formatPayrollGridHours(0)).toBe('')
  })
})

describe('kategorie', () => {
  it('do odpracované doby patří jen běžná práce a přesčas', () => {
    expect(isWorkedCategory('regular')).toBe(true)
    expect(isWorkedCategory('overtime')).toBe(true)
    for (const flag of ['night', 'weekend', 'holiday', 'difficult_environment'] as const) {
      expect(isWorkedCategory(flag)).toBe(false)
    }
  })

  it('příznaky se nepřičítají k odpracované době', () => {
    const entries = [
      entry({ id: 1, starts_at: '2026-08-03T08:00:00+02:00', net_minutes: 480 }),
      entry({ id: 2, starts_at: '2026-08-03T22:00:00+02:00', category: 'night', net_minutes: 480 }),
    ]
    expect(payrollGridWorkedMinutes(entries, '2026-08-03')).toBe(480)
    expect(payrollGridFlags(entries, '2026-08-03')).toEqual(['night'])
  })
})

describe('payrollGridCellState', () => {
  it('jeden zápis dne se dá z mřížky přepsat', () => {
    const entries = [entry({ id: 7, starts_at: '2026-08-03T08:00:00+02:00', net_minutes: 450 })]
    const state = payrollGridCellState(entries, '2026-08-03', 'regular')
    expect(state.minutes).toBe(450)
    expect(state.entry?.id).toBe(7)
    expect(state.locked).toBe(false)
  })

  it('den s víc zápisy téže kategorie se zamkne, ale součet ukáže', () => {
    const entries = [
      entry({ id: 1, starts_at: '2026-08-03T08:00:00+02:00', net_minutes: 240 }),
      entry({ id: 2, starts_at: '2026-08-03T13:00:00+02:00', net_minutes: 180 }),
    ]
    const state = payrollGridCellState(entries, '2026-08-03', 'regular')
    expect(state.minutes).toBe(420)
    expect(state.entry).toBeNull()
    expect(state.locked).toBe(true)
  })
})

describe('payrollDayPlans', () => {
  it('kalendář má přednost před odhadem pondělí až pátek', () => {
    const days = payrollMonthDays('2026-07')
    const plans = payrollDayPlans({
      calendar: {
        id: 1,
        employment_id: 12,
        name: 'x',
        timezone_name: 'Europe/Prague',
        schedule_type: 'regular',
        week_pattern: {},
        weekly_minutes: 2400,
        valid_from: '2026-07-01',
        valid_to: null,
        row_version: 1,
        fund_minutes: 0,
        days: [{
          date: '2026-07-06',
          weekday: 1,
          is_weekend: false,
          is_holiday: true,
          day_kind: 'holiday',
          planned_minutes: 0,
          holiday_code: 'cyril',
          holiday_name: 'Cyril a Metoděj',
        }],
      },
    }, days, 480)
    expect(plans.get('2026-07-06')).toEqual({
      kind: 'holiday',
      plannedMinutes: 0,
      holidayName: 'Cyril a Metoděj',
    })
    // Den, který kalendář nezná, spadne na odhad — a je to pracovní pondělí.
    expect(plans.get('2026-07-13')?.kind).toBe('workday')
    expect(plans.get('2026-07-11')?.kind).toBe('non_working')
  })
})

describe('buildPayrollGridBatch', () => {
  const context = new Map([[12, { entries: [], monthRowVersion: 3, open: true }]])

  it('pošle jen změněné buňky a odvodí interval od začátku dne', () => {
    const result = buildPayrollGridBatch({
      drafts: [{ employmentId: 12, date: '2026-08-03', raw: '8' }],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 30,
      timezone: 'Europe/Prague',
      context,
    })
    expect(result.cells).toHaveLength(1)
    expect(result.cells[0]).toMatchObject({
      employment_id: 12,
      category: 'regular',
      starts_at: '2026-08-03T08:00:00+02:00',
      // 8 hodin čistého času plus půlhodinová přestávka.
      ends_at: '2026-08-03T16:30:00+02:00',
      break_minutes: 30,
      supersedes_id: null,
      row_version: 0,
      month_row_version: 3,
    })
    expect(result.keys[0]).toBe(payrollGridCellKey(12, '2026-08-03'))
  })

  it('beze změny se nic neposílá — jinak by hromadná akce psala revize naprázdno', () => {
    const entries = [entry({ id: 5, starts_at: '2026-08-03T08:00:00+02:00', net_minutes: 480 })]
    const result = buildPayrollGridBatch({
      drafts: [{ employmentId: 12, date: '2026-08-03', raw: '8' }],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 0,
      timezone: 'Europe/Prague',
      context: new Map([[12, { entries, monthRowVersion: 3, open: true }]]),
    })
    expect(result.cells).toEqual([])
    expect(result.problems.size).toBe(0)
  })

  it('změna existujícího dne nahradí revizi, ne aby vedle ní vznikla druhá', () => {
    const entries = [entry({ id: 5, row_version: 4, starts_at: '2026-08-03T08:00:00+02:00', net_minutes: 480 })]
    const result = buildPayrollGridBatch({
      drafts: [{ employmentId: 12, date: '2026-08-03', raw: '6' }],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 0,
      timezone: 'Europe/Prague',
      context: new Map([[12, { entries, monthRowVersion: 3, open: true }]]),
    })
    expect(result.cells[0]).toMatchObject({ supersedes_id: 5, row_version: 4 })
  })

  it('vadné buňky se pojmenují a nezablokují zbytek dávky', () => {
    const locked = [
      entry({ id: 1, starts_at: '2026-08-04T08:00:00+02:00', net_minutes: 240 }),
      entry({ id: 2, starts_at: '2026-08-04T13:00:00+02:00', net_minutes: 180 }),
    ]
    const existing = [entry({ id: 3, starts_at: '2026-08-05T08:00:00+02:00', net_minutes: 480 })]
    const result = buildPayrollGridBatch({
      drafts: [
        { employmentId: 12, date: '2026-08-03', raw: 'osm' },
        { employmentId: 12, date: '2026-08-04', raw: '5' },
        { employmentId: 12, date: '2026-08-05', raw: '' },
        { employmentId: 12, date: '2026-08-06', raw: '20' },
        { employmentId: 12, date: '2026-08-07', raw: '8' },
      ],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 0,
      timezone: 'Europe/Prague',
      context: new Map([[12, {
        entries: [...locked, ...existing],
        monthRowVersion: 3,
        open: true,
      }]]),
    })
    expect(result.problems.get(payrollGridCellKey(12, '2026-08-03'))).toBe('unparsable')
    expect(result.problems.get(payrollGridCellKey(12, '2026-08-04'))).toBe('locked')
    expect(result.problems.get(payrollGridCellKey(12, '2026-08-05'))).toBe('delete_unsupported')
    expect(result.problems.get(payrollGridCellKey(12, '2026-08-06'))).toBe('crosses_midnight')
    // Poslední buňka je v pořádku a dávka ji pošle navzdory čtyřem vadným.
    expect(result.cells).toHaveLength(1)
    expect(result.cells[0].starts_at).toContain('2026-08-07')
  })

  it('prázdná buňka nad prázdným dnem není chyba, jen se neposílá', () => {
    const result = buildPayrollGridBatch({
      drafts: [{ employmentId: 12, date: '2026-08-03', raw: '' }],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 0,
      timezone: 'Europe/Prague',
      context,
    })
    expect(result.cells).toEqual([])
    expect(result.problems.size).toBe(0)
  })

  it('do schváleného měsíce se nezapisuje', () => {
    const result = buildPayrollGridBatch({
      drafts: [{ employmentId: 12, date: '2026-08-03', raw: '8' }],
      category: 'regular',
      startTime: '08:00',
      breakMinutes: 0,
      timezone: 'Europe/Prague',
      context: new Map([[12, { entries: [], monthRowVersion: 3, open: false }]]),
    })
    expect(result.cells).toEqual([])
  })
})

describe('payrollGridNextPosition', () => {
  it('Enter i šipka dolů jdou o řádek níž', () => {
    expect(payrollGridNextPosition({ row: 0, column: 3 }, 'Enter', 4, 31))
      .toEqual({ row: 1, column: 3 })
    expect(payrollGridNextPosition({ row: 0, column: 3 }, 'ArrowDown', 4, 31))
      .toEqual({ row: 1, column: 3 })
  })

  it('šipky vodorovně mění den, ne zaměstnance', () => {
    expect(payrollGridNextPosition({ row: 2, column: 3 }, 'ArrowRight', 4, 31))
      .toEqual({ row: 2, column: 4 })
    expect(payrollGridNextPosition({ row: 2, column: 3 }, 'ArrowLeft', 4, 31))
      .toEqual({ row: 2, column: 2 })
  })

  it('Home a End skočí na první a poslední den', () => {
    expect(payrollGridNextPosition({ row: 1, column: 10 }, 'Home', 4, 31))
      .toEqual({ row: 1, column: 0 })
    expect(payrollGridNextPosition({ row: 1, column: 10 }, 'End', 4, 31))
      .toEqual({ row: 1, column: 30 })
  })

  it('na kraji mřížky se nikam neskáče', () => {
    expect(payrollGridNextPosition({ row: 0, column: 0 }, 'ArrowUp', 4, 31)).toBeNull()
    expect(payrollGridNextPosition({ row: 0, column: 0 }, 'ArrowLeft', 4, 31)).toBeNull()
    expect(payrollGridNextPosition({ row: 3, column: 30 }, 'Enter', 4, 31)).toBeNull()
    expect(payrollGridNextPosition({ row: 0, column: 0 }, 'ArrowDown', 0, 0)).toBeNull()
  })
})
