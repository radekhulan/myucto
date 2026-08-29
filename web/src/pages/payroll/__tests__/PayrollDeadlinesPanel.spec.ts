import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollDeadlineItem, PayrollDeadlineOverview } from '@/api/payroll'

const m = vi.hoisted(() => ({
  deadlines: vi.fn(),
  canRead: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { deadlines: m.deadlines },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => `date:${value}`,
  formatMoneyMinor: (value: number) => `money:${value}`,
  formatPeriod: (value: string) => `period:${value}`,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: unknown) => {
      if (typeof params === 'number') return `${key}:${params}`
      if (params && typeof params === 'object') {
        return `${key}:${Object.values(params as Record<string, unknown>).join(',')}`
      }
      return key
    },
    // Podání i odvody mají v i18n jen část číselníku; `te` to rozhoduje.
    te: (key: string) => key !== 'payroll.payments.kind.risky_savings',
  }),
}))

import PayrollDeadlinesPanel from '@/pages/payroll/PayrollDeadlinesPanel.vue'

const RouterLinkStub = {
  props: ['to'],
  template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
}

function mountPanel() {
  return mount(PayrollDeadlinesPanel, {
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

function item(overrides: Partial<PayrollDeadlineItem> = {}): PayrollDeadlineItem {
  return {
    source: 'submission',
    reference: 'payroll_obligation:1',
    title: 'ELDP',
    subject: 'Jan Novák',
    period: '2026-07',
    due_on: '2026-08-20',
    phase: 'open',
    days_to_due: 12,
    is_overdue: false,
    path: '/payroll/submissions',
    ...overrides,
  }
}

function overview(items: PayrollDeadlineItem[]): PayrollDeadlineOverview {
  return {
    as_of: '2026-08-08',
    horizon_days: 45,
    window: { from: '2025-07-04', to: '2026-09-22' },
    summary: { total: items.length },
    items,
  }
}

describe('PayrollDeadlinesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.deadlines.mockResolvedValue(overview([item()]))
  })

  it('groups items by phase with the overdue group first and announced as an alert', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'a', phase: 'open', days_to_due: 30 }),
      item({
        reference: 'b',
        source: 'levy',
        title: 'social_insurance',
        phase: 'overdue',
        days_to_due: -3,
        is_overdue: true,
        due_on: '2026-08-05',
        remaining_minor: 123_400,
      }),
      item({ reference: 'c', phase: 'due_today', days_to_due: 0, due_on: '2026-08-08' }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const groups = wrapper.findAll('[data-test^="payroll-deadlines-group-"]')
    expect(groups.map(node => node.attributes('data-test'))).toEqual([
      'payroll-deadlines-group-overdue',
      'payroll-deadlines-group-due_today',
      'payroll-deadlines-group-open',
    ])
    expect(groups[0].attributes('role')).toBe('alert')
    expect(groups[0].text()).toContain('payroll.dashboard.deadlines.overdue_hint')
    // Zmeskany termin nese pocet dnu i zbyvajici castku odvodu.
    expect(wrapper.get('[data-test="payroll-deadline-due-b"]').text())
      .toBe('payroll.dashboard.deadlines.overdue_by:3')
    expect(wrapper.get('[data-test="payroll-deadline-b"]').text()).toContain('money:123400')
    // Ram panelu se zbarvi, jakmile je cokoli po terminu.
    expect(wrapper.get('[data-test="payroll-deadlines"]').classes())
      .toEqual(expect.arrayContaining(['border-danger-500/40']))
  })

  it('links each source to the screen where it is resolved, not to the raw server path', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'sub', source: 'submission', phase: 'due_soon', days_to_due: 2 }),
      item({
        reference: 'lev',
        source: 'levy',
        title: 'advance_tax',
        phase: 'due_soon',
        days_to_due: 2,
      }),
      item({
        reference: 'chk',
        source: 'checklist',
        title: 'eldp_submission',
        phase: 'due_soon',
        days_to_due: 2,
        employee_id: 42,
        // Server posila /payroll/employees/42, coz v routeru neexistuje.
        path: '/payroll/employees/42',
      }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const link = (reference: string) => JSON.parse(
      wrapper.get(`[data-test="payroll-deadline-link-${reference}"]`).attributes('data-to') ?? '{}',
    )
    expect(link('sub')).toEqual({ name: 'payroll-submissions' })
    expect(link('lev')).toEqual({ name: 'payroll-payments' })
    expect(link('chk')).toEqual({ name: 'payroll-people', query: { person: '42' } })
  })

  it('falls back to the raw code when a source code has no translation', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'x', source: 'levy', title: 'risky_savings', phase: 'open' }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadline-x"]').text()).toContain('risky_savings')
    expect(wrapper.get('[data-test="payroll-deadline-x"]').text())
      .not.toContain('payroll.payments.kind.risky_savings')
  })

  it('stays quiet when nothing is due', async () => {
    m.deadlines.mockResolvedValue(overview([]))

    const wrapper = mountPanel()
    await flushPromises()

    const empty = wrapper.get('[data-test="payroll-deadlines-empty"]')
    expect(empty.text()).toBe('payroll.dashboard.deadlines.empty')
    // Zadny alert, zadna vystrazna barva.
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payroll-deadlines"]').classes())
      .toEqual(expect.arrayContaining(['border-neutral-200']))
    expect(wrapper.find('[data-test^="payroll-deadlines-group-"]').exists()).toBe(false)
  })

  it('collapses the open group to two full grid rows and expands it on demand', async () => {
    m.deadlines.mockResolvedValue(overview(
      Array.from({ length: 12 }, (_, index) => item({ reference: `o${index}`, phase: 'open' })),
    ))

    const wrapper = mountPanel()
    await flushPromises()

    // Osm = dve plne rady ctyrsloupcove mrizky; tri by nechavaly v rade diru.
    expect(wrapper.findAll('[data-test^="payroll-deadline-o"]')).toHaveLength(8)
    await wrapper.get('[data-test="payroll-deadlines-toggle-open"]').trigger('click')
    expect(wrapper.findAll('[data-test^="payroll-deadline-o"]')).toHaveLength(12)
    expect(wrapper.get('[data-test="payroll-deadlines-toggle-open"]').text())
      .toBe('payroll.dashboard.deadlines.collapse')
  })

  it('states the failure in place and recovers through retry instead of a vanishing toast', async () => {
    m.deadlines.mockRejectedValueOnce(new Error('boom'))

    const wrapper = mountPanel()
    await flushPromises()

    const error = wrapper.get('[data-test="payroll-deadlines-error"]')
    expect(error.attributes('role')).toBe('alert')
    expect(error.text()).toContain('payroll.dashboard.deadlines.load_failed')

    m.deadlines.mockResolvedValue(overview([item({ reference: 'again' })]))
    await wrapper.get('[data-test="payroll-deadlines-retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payroll-deadline-again"]').exists()).toBe(true)
  })

  it('renders nothing and calls no endpoint without the submissions read permission', async () => {
    m.canRead.mockReturnValue(false)

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines"]').exists()).toBe(false)
    expect(m.deadlines).not.toHaveBeenCalled()
  })

  it('lays the items out as a grid that grows to four columns without horizontal scroll', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'a', phase: 'due_soon', days_to_due: 2 }),
      item({ reference: 'b', phase: 'due_soon', days_to_due: 2 }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const grid = wrapper.get('[data-test="payroll-deadlines-group-due_soon"]').get('ul')
    // Na mobilu jeden sloupec, na velke obrazovce ctyri — a zadny overflow-x.
    expect(grid.classes()).toEqual(expect.arrayContaining([
      'grid', 'grid-cols-1', 'sm:grid-cols-2', 'xl:grid-cols-3', '2xl:grid-cols-4',
    ]))
    expect(wrapper.html()).not.toContain('overflow-x')
  })

  it('makes the whole tile a real link so middle click and keyboard work', async () => {
    m.deadlines.mockResolvedValue(overview([item({ reference: 'a', phase: 'due_soon' })]))

    const wrapper = mountPanel()
    await flushPromises()

    const tile = wrapper.get('[data-test="payroll-deadline-link-a"]')
    expect(tile.element.tagName).toBe('A')
    // Cil odkazu drzi dlazdice sama, uz ne samostatny radek "Vyresit".
    expect(JSON.parse(tile.attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-submissions' })
    // Ctecka se ale musi dozvedet, co odkaz udela.
    expect(tile.get('.sr-only').text()).toBe('payroll.dashboard.deadlines.resolve')
  })

  it('hoists the source above the group when every item in the phase shares it', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'a', source: 'checklist', title: 'eldp_submission', phase: 'overdue', days_to_due: -28, is_overdue: true, employee_id: 1 }),
      item({ reference: 'b', source: 'checklist', title: 'eldp_submission', phase: 'overdue', days_to_due: -12, is_overdue: true, employee_id: 2 }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadlines-phase-source-overdue"]').text())
      .toBe('payroll.dashboard.deadlines.source.checklist')
    // Stejna hodnota u kazde dlazdice je jen sum.
    expect(wrapper.find('[data-test="payroll-deadline-source-a"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payroll-deadline-source-b"]').exists()).toBe(false)
  })

  it('keeps the source on the tiles when the phase mixes several sources', async () => {
    m.deadlines.mockResolvedValue(overview([
      item({ reference: 'a', source: 'checklist', phase: 'overdue', days_to_due: -3, is_overdue: true, employee_id: 1 }),
      item({ reference: 'b', source: 'levy', title: 'advance_tax', phase: 'overdue', days_to_due: -1, is_overdue: true }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines-phase-source-overdue"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payroll-deadline-source-a"]').text())
      .toBe('payroll.dashboard.deadlines.source.checklist')
    expect(wrapper.get('[data-test="payroll-deadline-source-b"]').text())
      .toBe('payroll.dashboard.deadlines.source.levy')
  })

  it('judges source uniformity over the whole group, not over the collapsed excerpt', async () => {
    m.deadlines.mockResolvedValue(overview([
      ...Array.from({ length: 8 }, (_, index) => item({
        reference: `o${index}`,
        phase: 'open',
        source: 'checklist',
        employee_id: index + 1,
      })),
      // Devata polozka je za sbalenim, presto rozhoduje o nadpisu skupiny.
      item({ reference: 'o8', phase: 'open', source: 'levy', title: 'advance_tax' }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines-phase-source-open"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payroll-deadline-source-o0"]').text())
      .toBe('payroll.dashboard.deadlines.source.checklist')
  })

})
