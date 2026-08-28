import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  PayrollEmployeeCardMonth,
  PayrollQuickInputRow,
  PayrollQuickSurchargeKind,
  PayrollQuickSurchargeState,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  employeeCards: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { employeeCards: m.employeeCards },
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: ref('cs-CZ'),
  }),
}))

import PayrollEmployeeCards from '@/pages/payroll/PayrollEmployeeCards.vue'

/**
 * Výchozí stav zákonných příplatků: druh je dostupný, ale nic zadaného nemá.
 * Server ho posílá u každého řádku, takže fixtura ho posílat musí taky —
 * jinak by testy běžely nad tvarem, který v odpovědi nikdy nenastane.
 */
function surchargeStates(
  overrides: Partial<Record<PayrollQuickSurchargeKind, Partial<PayrollQuickSurchargeState>>> = {},
): Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState> {
  const sections: Record<PayrollQuickSurchargeKind, string> = {
    night: '§ 116',
    weekend: '§ 118',
    holiday: '§ 115',
    difficult_environment: '§ 117',
  }
  const kinds: PayrollQuickSurchargeKind[] = [
    'night', 'weekend', 'holiday', 'difficult_environment',
  ]
  return Object.fromEntries(kinds.map(kind => [kind, {
    kind,
    label: kind,
    section: sections[kind],
    component_code: `PRIPLATEK_${kind.toUpperCase()}`,
    basis: kind === 'difficult_environment' ? 'minimum_wage_hourly' : 'average_earning',
    basis_hourly_minor: 20_000,
    average_hourly_minor: 20_000,
    average_snapshot_id: 41,
    average_snapshot_version: 1,
    rate_basis_points: kind === 'holiday' ? 10_000 : 1_000,
    rate_is_agreed: false,
    requires_factors: kind === 'difficult_environment',
    default_factors: null,
    hours_milli: null,
    factors: null,
    amount_minor: 0,
    managed_amount_minor: 0,
    row_version: null,
    status: null,
    managed_elsewhere: false,
    from_attendance: false,
    conflict: false,
    available: true,
    entry_available: true,
    clear_only: false,
    unavailable_reason: null,
    ...overrides[kind],
  }])) as Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState>
}

function row(overrides: Partial<PayrollQuickInputRow> = {}): PayrollQuickInputRow {
  return {
    employee_id: 5,
    employment_id: 12,
    employment_row_version: 1,
    full_name: 'Alfa Aktivní',
    birth_number_masked: null,
    employment_code: 'SYNTH-HPP',
    relation_type: 'employment',
    effective_status: 'active',
    suspended_in_month: false,
    base_amount_minor: 4_500_000,
    base_managed_elsewhere: false,
    base_conflict: false,
    partial_month: false,
    base_requires_entry: false,
    overtime_mode: 'amount',
    overtime_hours_milli: null,
    overtime_amount_minor: 0,
    overtime_hourly_rate_minor: null,
    overtime_average_snapshot_id: null,
    overtime_average_snapshot_version: null,
    overtime_hours_available: true,
    overtime_hours_relation_supported: true,
    overtime_managed_elsewhere: false,
    overtime_conflict: false,
    bonus_amount_minor: 0,
    bonus_managed_elsewhere: false,
    bonus_conflict: false,
    other_amount_minor: 0,
    non_monetary_amount_minor: 0,
    excluded_from_gross_amount_minor: 0,
    gross_preview_minor: 4_500_000,
    inputs: { base: null, overtime: null, bonus: null },
    surcharges: surchargeStates(),
    surcharge_amount_minor: 0,
    blockers: [],
    ...overrides,
  }
}

function cardMonth(
  items: PayrollQuickInputRow[],
  overrides: Partial<PayrollEmployeeCardMonth> = {},
): PayrollEmployeeCardMonth {
  return {
    period: '2026-08',
    items: items.map(item => ({ ...item, absences: [] })),
    total: items.length,
    company_headcount: items.length,
    summary: {
      people: new Set(items.map(item => item.employee_id)).size,
      gross_preview_minor: items.reduce((sum, item) => sum + item.gross_preview_minor, 0),
      away: 0,
      attention: 0,
    },
    ...overrides,
  }
}

function mountCards(period = '2026-08') {
  return mount(PayrollEmployeeCards, {
    props: { period },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollEmployeeCards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.employeeCards.mockResolvedValue(cardMonth([row()]))
  })

  it('u 501 vztahů vykreslí nejvýš stránku 25 a použije celofiremní souhrn', async () => {
    const page = Array.from({ length: 25 }, (_, index) => row({
      employee_id: index + 1,
      employment_id: index + 1,
      full_name: `Zaměstnanec ${String(index + 1).padStart(3, '0')}`,
    }))
    m.employeeCards.mockResolvedValue(cardMonth(page, {
      total: 501,
      company_headcount: 501,
      summary: {
        people: 501,
        gross_preview_minor: 2_254_500_000,
        away: 17,
        attention: 9,
      },
    }))

    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.findAll('[data-test^="employee-card-"]')).toHaveLength(25)
    expect(wrapper.get('[data-test="employee-count"]').text()).toBe('501')
    expect(wrapper.text()).toContain('1 / 21')
    expect(m.employeeCards).toHaveBeenCalledWith(
      '2026-08',
      { limit: 25, offset: 0 },
      { search: '', status: 'active' },
    )

    const next = wrapper.findAll('button').find(button => button.text().includes('common.next'))
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()
    expect(m.employeeCards).toHaveBeenLastCalledWith(
      '2026-08',
      { limit: 25, offset: 25 },
      { search: '', status: 'active' },
    )
  })

  it('shows name, employment type, status and pay on one scannable card', async () => {
    const wrapper = mountCards()
    await flushPromises()

    const card = wrapper.get('[data-test="employee-card-12"]')
    expect(card.text()).toContain('Alfa Aktivní')
    expect(card.text()).toContain('payroll.people.relations.employment')
    expect(card.text()).toContain('SYNTH-HPP')
    expect(card.text()).toContain('payroll.people.employment_status.active')
    expect(wrapper.get('[data-test="employee-gross-12"]').text()).toContain('45')
  })

  it('žádá jen serverovou stránku 25 karet, ne celý měsíc ani osobní volání', async () => {
    mountCards('2026-02')
    await flushPromises()

    expect(m.employeeCards).toHaveBeenCalledTimes(1)
    expect(m.employeeCards).toHaveBeenCalledWith(
      '2026-02',
      { limit: 25, offset: 0 },
      { search: '', status: 'active' },
    )
  })

  it('links the quick actions to the existing absence flow with the person preselected', async () => {
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-vacation-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-absences","query":{"employment":"12","type":"vacation"}}')
    expect(wrapper.get('[data-test="employee-absence-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-absences","query":{"employment":"12"}}')
    expect(wrapper.get('[data-test="employee-detail-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-people","query":{"person":"5"}}')
  })

  it('marks who is away this month from approved and requested absences', async () => {
    m.employeeCards.mockResolvedValue(cardMonth([row()], {
      items: [{
        ...row(),
        absences: [
          { id: 1, employment_id: 12, absence_type: 'vacation', date_from: '2026-08-05', date_to: '2026-08-09', status: 'approved' },
        ],
      }],
      summary: { people: 1, gross_preview_minor: 4_500_000, away: 1, attention: 0 },
    }))
    const wrapper = mountCards()
    await flushPromises()

    const card = wrapper.get('[data-test="employee-card-12"]')
    expect(card.text()).toContain('payroll_absence.types.vacation 5. 8. – 9. 8.')
    expect(card.text()).not.toContain('payroll_absence.types.dpn')
  })

  it('filters to people who need attention and renders their blockers', async () => {
    const attention = row({
          employee_id: 6,
          employment_id: 13,
          full_name: 'Beta Rozpracovaná',
          employment_code: 'SYNTH-DPC',
          base_requires_entry: true,
          blockers: ['partial_month_base_required'],
        })
    m.employeeCards
      .mockResolvedValueOnce(cardMonth([row(), attention], {
        summary: { people: 2, gross_preview_minor: 9_000_000, away: 0, attention: 1 },
      }))
      .mockResolvedValueOnce(cardMonth([attention], {
        summary: { people: 2, gross_preview_minor: 9_000_000, away: 0, attention: 1 },
      }))
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-card-13"]').text())
      .toContain('payroll.quick_inputs.blockers.partial_month_base_required')
    expect(wrapper.get('[data-test="employee-gross-13"]').text())
      .toBe('payroll.employee_cards.base_missing')

    await wrapper.get('[data-test="employee-filter-attention"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="employee-card-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employee-card-12"]').exists()).toBe(false)
    expect(m.employeeCards).toHaveBeenLastCalledWith(
      '2026-08',
      { limit: 25, offset: 0 },
      { search: '', status: 'attention' },
    )
  })

  it('hledá jméno a kód na serveru, ne jen v aktuální stránce', async () => {
    vi.useFakeTimers()
    m.employeeCards.mockResolvedValue(cardMonth([row()]))
    const wrapper = mountCards()
    await flushPromises()

    await wrapper.get('[data-test="employee-search"]').setValue('dpc')
    await vi.advanceTimersByTimeAsync(250)
    await flushPromises()
    expect(m.employeeCards).toHaveBeenLastCalledWith(
      '2026-08',
      { limit: 25, offset: 0 },
      { search: 'dpc', status: 'active' },
    )
    vi.useRealTimers()
  })

  /**
   * Přehled tvrdil „Zatím žádný zaměstnanec" i firmě, která zaměstnance má —
   * jen jim vztah nikdo nezahájil nebo už skončil. Prázdný stav proto musí
   * rozlišit „nikoho nemáte" od „nikdo tenhle měsíc neběží".
   */
  it('rozliší firmu bez lidí od firmy, které nikdo v tomhle měsíci neběží', async () => {
    m.employeeCards.mockResolvedValue(cardMonth([], { company_headcount: 0 }))
    const nobody = mountCards()
    await flushPromises()
    expect(nobody.get('[data-test="employee-cards-empty"]').text())
      .toContain('payroll.employee_cards.empty_title')

    m.employeeCards.mockResolvedValue(cardMonth([], { company_headcount: 1 }))
    const idle = mountCards()
    await flushPromises()
    const text = idle.get('[data-test="employee-cards-empty"]').text()
    expect(text).toContain('payroll.employee_cards.none_active_title')
    expect(text).not.toContain('payroll.employee_cards.empty_title')
  })

  it('explains the failure instead of showing an empty list', async () => {
    m.employeeCards.mockRejectedValue(new Error('500'))
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-cards-failed"]').text())
      .toBe('payroll.employee_cards.load_failed')
  })
})
