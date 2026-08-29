import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollAgendaSummaryItem, PayrollEmploymentAgendaSummary } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employmentAgendaSummary: vi.fn(),
  canRead: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { employmentAgendaSummary: m.employmentAgendaSummary },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: () => true }),
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

import EmploymentAgendaPanel from '@/pages/payroll/EmploymentAgendaPanel.vue'
import { payrollCardAgendas } from '@/pages/payroll/payrollAgendaLinks'

function agenda(overrides: Partial<PayrollAgendaSummaryItem> = {}): PayrollAgendaSummaryItem {
  return {
    key: 'time',
    count: 0,
    last_on: null,
    amount_minor: null,
    ...overrides,
  }
}

function summary(agendas: PayrollAgendaSummaryItem[]): PayrollEmploymentAgendaSummary {
  return { employment_id: 12, employee_id: 5, agendas }
}

function mountPanel() {
  return mount(EmploymentAgendaPanel, {
    props: { employmentId: 12, employeeId: 5 },
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

function tile(wrapper: ReturnType<typeof mountPanel>, key: string) {
  return wrapper.get(`[data-test="employment-agenda-${key}"]`)
}

function count(wrapper: ReturnType<typeof mountPanel>, key: string) {
  return wrapper.get(`[data-test="employment-agenda-count-${key}"]`).text()
}

describe('EmploymentAgendaPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.employmentAgendaSummary.mockResolvedValue(summary(
      payrollCardAgendas.map(item => agenda({ key: item.key })),
    ))
  })

  it('vypíše VŠECHNY agendy katalogu v jednom seznamu, ne jen ty naplněné', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    for (const item of payrollCardAgendas) {
      const link = tile(wrapper, item.key).get('a')
      expect(link.attributes('data-to')).toBeTruthy()
    }
    expect(wrapper.findAll('[data-test="employment-agenda-summary"] > li'))
      .toHaveLength(payrollCardAgendas.length)
  })

  it('prázdná agenda zůstane v seznamu, ukáže nulu a dál se dá otevřít', async () => {
    m.employmentAgendaSummary.mockResolvedValue(summary(
      payrollCardAgendas.map(item => agenda({
        key: item.key,
        count: item.key === 'absences' ? 3 : 0,
      })),
    ))

    const wrapper = mountPanel()
    await flushPromises()

    expect(count(wrapper, 'travel')).toBe('0')
    expect(count(wrapper, 'absences')).toBe('3')
    expect(tile(wrapper, 'travel').get('a').attributes('data-to')).toContain('payroll-travel')
  })

  it('u agendy se záznamy ukáže počet, datum i částku', async () => {
    m.employmentAgendaSummary.mockResolvedValue(summary([
      agenda({ key: 'travel', count: 2, last_on: '2026-08-03', amount_minor: 123_400 }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const row = tile(wrapper, 'travel')
    expect(count(wrapper, 'travel')).toBe('2')
    expect(row.text()).toContain('payroll.agendas.last_on')
    // Částka jde přes sdílené `formatMoneyMinor`, tedy s nezlomitelnými mezerami —
    // porovnává se proto normalizovaně, ne na přesný řetězec locale.
    expect(row.text().replace(/\s/gu, '')).toContain('1234,00Kč')
  })

  it('agenda bez oprávnění zůstane vidět, ale nevede nikam a nemá počet', async () => {
    m.canRead.mockImplementation((permission: string) => permission !== 'payroll.enforcement')
    const wrapper = mountPanel()
    await flushPromises()

    const row = tile(wrapper, 'enforcement')
    expect(row.find('a').exists()).toBe(false)
    expect(row.get('[aria-disabled="true"]').attributes('title'))
      .toBe('payroll.agendas.no_permission')
    expect(count(wrapper, 'enforcement')).toBe('–')
    // Tooltip na dotyku neexistuje → věta musí být vidět i na obrazovce.
    expect(wrapper.get('[data-test="employment-agendas-denied"]').text())
      .toBe('payroll.agendas.no_permission')
    expect(tile(wrapper, 'absences').find('a').exists()).toBe(true)
  })

  it('výpadek souhrnu nesmí shodit kartu — odkazy zůstanou, počty jsou pomlčka', async () => {
    m.employmentAgendaSummary.mockRejectedValue(new Error('403'))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="employment-agendas-failed"]').exists()).toBe(true)
    expect(tile(wrapper, 'time').get('a').attributes('data-to')).toContain('payroll-time')
    expect(count(wrapper, 'time')).toBe('–')
  })

  it('souhrn se načte jedním požadavkem, ne jedním na agendu', async () => {
    mountPanel()
    await flushPromises()

    expect(m.employmentAgendaSummary).toHaveBeenCalledTimes(1)
    expect(m.employmentAgendaSummary).toHaveBeenCalledWith(12)
  })
})
