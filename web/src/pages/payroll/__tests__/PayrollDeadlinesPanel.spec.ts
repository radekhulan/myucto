import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollDeadlineChecklistCompleteResult,
  PayrollDeadlineGroup,
  PayrollDeadlineGroupedOverview,
  PayrollDeadlineGroupItemsPage,
  PayrollDeadlineItem,
  PayrollDeadlinePersonItem,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  deadlineGroups: vi.fn(),
  deadlineGroupItems: vi.fn(),
  completeDeadlineChecklist: vi.fn(),
  canRead: vi.fn(() => true),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    deadlineGroups: m.deadlineGroups,
    deadlineGroupItems: m.deadlineGroupItems,
    completeDeadlineChecklist: m.completeDeadlineChecklist,
  },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => `date:${value}`,
  formatMoneyMinor: (value: number) => `money:${value}`,
  formatPeriod: (value: string) => `period:${value}`,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, params?: unknown) => {
      if (typeof params === 'number') return `${key}:${params}`
      if (params && typeof params === 'object') {
        return `${key}:${Object.values(params as Record<string, unknown>).join(',')}`
      }
      return key
    },
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

function item(overrides: Partial<PayrollDeadlinePersonItem> = {}): PayrollDeadlinePersonItem {
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

function group(overrides: Partial<PayrollDeadlineGroup> = {}): PayrollDeadlineGroup {
  const items = overrides.items ?? [item()]
  const first: PayrollDeadlineItem = items[0] ?? item()
  return {
    key: `${first.phase}:${first.source}:${first.title}`,
    phase: first.phase,
    source: first.source,
    title: first.title,
    per_person: false,
    count: items.length,
    oldest_due_on: first.due_on,
    newest_due_on: first.due_on,
    min_days_to_due: first.days_to_due,
    max_days_to_due: first.days_to_due,
    is_overdue: first.phase === 'overdue',
    items,
    ...overrides,
  }
}

/** 225 lidí se stejnou nástupní povinností — přesně stav po importu docházky. */
function contractGroup(overrides: Partial<PayrollDeadlineGroup> = {}): PayrollDeadlineGroup {
  return group({
    key: 'overdue:checklist:employment_contract',
    phase: 'overdue',
    source: 'checklist',
    title: 'employment_contract',
    per_person: true,
    count: 225,
    oldest_due_on: '2026-06-01',
    newest_due_on: '2026-08-01',
    min_days_to_due: -104,
    max_days_to_due: -43,
    is_overdue: true,
    items: [],
    ...overrides,
  })
}

function overview(groups: PayrollDeadlineGroup[]): PayrollDeadlineGroupedOverview {
  return {
    as_of: '2026-09-13',
    horizon_days: 45,
    window: { from: '2025-08-09', to: '2026-10-28' },
    summary: { total: groups.reduce((sum, g) => sum + g.count, 0) },
    groups,
  }
}

function person(index: number): PayrollDeadlinePersonItem {
  return item({
    source: 'checklist',
    reference: `payroll_checklist_item:${index}`,
    item_id: index,
    title: 'employment_contract',
    subject: `Osoba ${index}`,
    personal_number: `OS-${index}`,
    period: null,
    due_on: '2026-06-01',
    phase: 'overdue',
    days_to_due: -104,
    is_overdue: true,
    employee_id: 1000 + index,
  })
}

function page(items: PayrollDeadlinePersonItem[], total = 225, offset = 0): PayrollDeadlineGroupItemsPage {
  return { total, offset, limit: 25, items }
}

function result(overrides: Partial<PayrollDeadlineChecklistCompleteResult> = {}): PayrollDeadlineChecklistCompleteResult {
  return {
    completed: [],
    skipped: [],
    failed: [],
    remaining: 0,
    complete: true,
    next_after_id: 0,
    ...overrides,
  }
}

async function expandContractGroup(wrapper: ReturnType<typeof mountPanel>) {
  await wrapper.get('[data-test="payroll-deadline-group-toggle-overdue:checklist:employment_contract"]')
    .trigger('click')
  await flushPromises()
}

describe('PayrollDeadlinesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.canWrite.mockReturnValue(true)
    m.deadlineGroups.mockResolvedValue(overview([group()]))
    m.deadlineGroupItems.mockResolvedValue(page([person(1), person(2), person(3)]))
    m.completeDeadlineChecklist.mockResolvedValue(result())
  })

  it('shows 225 people with the same duty as one row with a count and the oldest delay', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))

    const wrapper = mountPanel()
    await flushPromises()

    const rows = wrapper.findAll('[data-test^="payroll-deadline-group-overdue"]')
    expect(rows).toHaveLength(1)
    const key = 'overdue:checklist:employment_contract'
    expect(wrapper.get(`[data-test="payroll-deadline-group-count-${key}"]`).text())
      .toBe('payroll.dashboard.deadlines.people_count:225')
    expect(wrapper.get(`[data-test="payroll-deadline-group-due-${key}"]`).text())
      .toBe('payroll.dashboard.deadlines.oldest:payroll.dashboard.deadlines.overdue_by:104')
    expect(wrapper.get('[data-test="payroll-deadlines-chip-overdue"]').text()).toContain('225')
    // Seznam lidí se nenačítá, dokud ho nikdo nerozbalí.
    expect(m.deadlineGroupItems).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="payroll-deadlines-group-overdue"]').attributes('role')).toBe('alert')
  })

  it('keeps a genuinely new start visible next to the collapsed backlog', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      contractGroup(),
      group({
        items: [person(900)].map(p => ({ ...p, phase: 'due_soon' as const, days_to_due: 3, reference: 'new' })),
        per_person: true,
      }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines-group-due_soon"]').exists()).toBe(true)
    // Jednočlenná skupina se prokliká rovnou na kartu člověka.
    expect(JSON.parse(wrapper.get('[data-test="payroll-deadline-link-new"]').attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-people', query: { person: '1900' } })
  })

  it('loads the people of an expanded group page by page', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)

    expect(m.deadlineGroupItems).toHaveBeenCalledWith({
      phase: 'overdue',
      source: 'checklist',
      title: 'employment_contract',
      offset: 0,
      limit: 25,
      horizon_days: 45,
    })
    const row = wrapper.get('[data-test="payroll-deadline-person-payroll_checklist_item:2"]')
    expect(row.text()).toContain('Osoba 2')
    expect(row.text()).toContain('OS-2')
    expect(JSON.parse(wrapper.get('[data-test="payroll-deadline-person-link-payroll_checklist_item:2"]')
      .attributes('data-to') ?? '{}')).toEqual({ name: 'payroll-people', query: { person: '1002' } })
    // Mobilní karty i tabulka nesou tentýž obsah.
    expect(wrapper.find('[data-test="payroll-deadline-people-cards"]').exists()).toBe(true)

    const next = wrapper.findAll('button').find(button => button.text().includes('common.next'))
    await next?.trigger('click')
    await flushPromises()
    expect(m.deadlineGroupItems).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 25 }))
  })

  it('searches the group on the server', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)
    await wrapper.get('[data-test="payroll-deadline-people-search"]').setValue('novak')
    await flushPromises()

    expect(m.deadlineGroupItems).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'novak', offset: 0 }))
  })

  it('marks the selected people as done with the prefilled note and reloads the overview', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))
    m.completeDeadlineChecklist.mockResolvedValue(result({ completed: [1, 3] }))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)

    const selectedButton = wrapper.get('[data-test="payroll-deadline-complete-selected"]')
    expect(selectedButton.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="payroll-deadline-select-payroll_checklist_item:1"]').trigger('change')
    await wrapper.get('[data-test="payroll-deadline-select-payroll_checklist_item:3"]').trigger('change')
    expect(selectedButton.attributes('disabled')).toBeUndefined()
    await selectedButton.trigger('click')

    const note = wrapper.get('[data-test="payroll-deadline-complete-note"]').element as HTMLTextAreaElement
    expect(note.value).toBe('payroll.dashboard.deadlines.group.note_default')
    await wrapper.get('[data-test="payroll-deadline-complete-run"]').trigger('click')
    await flushPromises()

    expect(m.completeDeadlineChecklist).toHaveBeenCalledWith({
      item_ids: [1, 3],
      note: 'payroll.dashboard.deadlines.group.note_default',
    })
    expect(m.deadlineGroups).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-test="payroll-deadlines-bulk-notice"]').text())
      .toBe('payroll.dashboard.deadlines.bulk_done:2')
  })

  it('walks a whole group in batches and lists what could not be marked', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))
    m.completeDeadlineChecklist
      .mockResolvedValueOnce(result({ completed: [1, 2], complete: false, remaining: 1, next_after_id: 100 }))
      .mockResolvedValueOnce(result({
        failed: [{ item_id: 101, employment_id: 7, subject: 'Osoba 101', code: 'prerequisite_failed', message: 'Nejdřív doplňte datum nástupu.' }],
      }))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)
    await wrapper.get('[data-test="payroll-deadline-complete-all"]').trigger('click')
    await wrapper.get('[data-test="payroll-deadline-complete-run"]').trigger('click')
    await flushPromises()

    expect(m.completeDeadlineChecklist).toHaveBeenCalledTimes(2)
    expect(m.completeDeadlineChecklist).toHaveBeenNthCalledWith(1, {
      phase: 'overdue',
      item_key: 'employment_contract',
      horizon_days: 45,
      note: 'payroll.dashboard.deadlines.group.note_default',
    })
    expect(m.completeDeadlineChecklist).toHaveBeenNthCalledWith(2, expect.objectContaining({ after_id: 100 }))
    expect(wrapper.get('[data-test="payroll-deadline-complete-failures"]').text())
      .toContain('Osoba 101: Nejdřív doplňte datum nástupu.')
  })

  it('refuses to run without a note', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)
    await wrapper.get('[data-test="payroll-deadline-complete-all"]').trigger('click')
    await wrapper.get('[data-test="payroll-deadline-complete-note"]').setValue('   ')

    expect(wrapper.get('[data-test="payroll-deadline-complete-run"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="payroll-deadline-complete-note-required"]').exists()).toBe(true)
    expect(m.completeDeadlineChecklist).not.toHaveBeenCalled()
  })

  it('offers no bulk action without the employment write permission or outside the checklist', async () => {
    m.canWrite.mockReturnValue(false)
    m.deadlineGroups.mockResolvedValue(overview([contractGroup()]))

    const wrapper = mountPanel()
    await flushPromises()
    await expandContractGroup(wrapper)
    expect(wrapper.find('[data-test="payroll-deadline-complete-all"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payroll-deadline-select-page"]').exists()).toBe(false)

    m.canWrite.mockReturnValue(true)
    m.deadlineGroups.mockResolvedValue(overview([contractGroup({
      key: 'overdue:registration_change:regzec_change',
      source: 'registration_change',
      title: 'regzec_change',
      count: 3,
    })]))
    const other = mountPanel()
    await flushPromises()
    await other.get('[data-test="payroll-deadline-group-toggle-overdue:registration_change:regzec_change"]').trigger('click')
    await flushPromises()
    expect(other.find('[data-test="payroll-deadline-complete-all"]').exists()).toBe(false)
  })

  it('expands company-wide duties inline without asking the server', async () => {
    m.deadlineGroups.mockResolvedValue(overview([group({
      items: [
        item({ reference: 'l1', source: 'levy', title: 'health_insurance', subject: 'VZP', phase: 'overdue', days_to_due: -3, remaining_minor: 123_400 }),
        item({ reference: 'l2', source: 'levy', title: 'health_insurance', subject: 'ZPMV', phase: 'overdue', days_to_due: -3 }),
      ],
    })]))

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="payroll-deadline-group-toggle-overdue:levy:health_insurance"]').trigger('click')

    expect(m.deadlineGroupItems).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="payroll-deadline-l1"]').text()).toContain('money:123400')
    expect(JSON.parse(wrapper.get('[data-test="payroll-deadline-link-l2"]').attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-payments' })
  })

  it('links single duties to the screen where they are resolved', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      group({ items: [item({ reference: 'sub', phase: 'due_soon', days_to_due: 2 })] }),
      group({
        items: [item({
          reference: 'tax_statement:dpzvd6:2025',
          source: 'tax_statement',
          title: 'dpzvd6',
          subject: '586/1992 Sb. § 38j',
          period: null,
          phase: 'overdue',
          days_to_due: -2,
          statement_year: 2025,
        })],
      }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const link = (reference: string) => JSON.parse(
      wrapper.get(`[data-test="payroll-deadline-link-${reference}"]`).attributes('data-to') ?? '{}',
    )
    expect(link('sub')).toEqual({ name: 'payroll-submissions' })
    expect(link('tax_statement:dpzvd6:2025')).toEqual({
      name: 'payroll-dashboard',
      query: { taxStatementYear: '2025' },
      hash: '#payroll-tax-statement',
    })
    expect(wrapper.get('[data-test="payroll-deadline-tax_statement:dpzvd6:2025"]').text())
      .toContain('payroll.dashboard.deadlines.tax_statement.subject:2025')
    expect(wrapper.get('[data-test="payroll-deadline-due-sub"]').text())
      .toBe('payroll.dashboard.deadlines.due_in:2')
  })

  /*
   * § 38ch ZDP a konec povolení cizince: termíny dřív žily jen na obrazovce
   * ročního zúčtování a na kartě osoby. Proklik musí vést tam, kde se řeší.
   */
  it('links annual settlement and foreign permit duties to their screens', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      group({
        items: [item({
          reference: 'annual_settlement_request:2025',
          source: 'annual_settlement',
          title: 'annual_settlement_request',
          subject: '4',
          period: null,
          due_on: '2026-02-15',
          phase: 'due_soon',
          days_to_due: 3,
          statement_year: 2025,
          undecided_count: 4,
        })],
      }),
      group({
        items: [item({
          reference: 'annual_settlement_perform:2025:41',
          source: 'annual_settlement',
          title: 'annual_settlement_perform',
          subject: 'Syntetický Žadatel',
          period: null,
          due_on: '2026-03-31',
          statement_year: 2025,
          employee_id: 41,
        })],
      }),
      group({
        items: [item({
          reference: 'payroll_foreign_permit:7',
          source: 'foreign_permit',
          title: 'foreign_permit_work',
          subject: 'Syntetický Cizinec',
          period: null,
          due_on: '2026-03-31',
          employee_id: 52,
          permit_label: 'Syntetická karta',
        })],
      }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const link = (reference: string) => JSON.parse(
      wrapper.get(`[data-test="payroll-deadline-link-${reference}"]`).attributes('data-to') ?? '{}',
    )
    expect(link('annual_settlement_request:2025')).toEqual({
      name: 'payroll-annual-settlement',
      query: { year: '2025' },
    })
    expect(link('annual_settlement_perform:2025:41')).toEqual({
      name: 'payroll-annual-settlement',
      query: { year: '2025', person: '41' },
    })
    expect(link('payroll_foreign_permit:7')).toEqual({
      name: 'payroll-people',
      query: { person: '52' },
    })
    expect(wrapper.get('[data-test="payroll-deadline-annual_settlement_request:2025"]').text())
      .toContain('payroll.dashboard.deadlines.annual_settlement_undecided')
    expect(wrapper.get('[data-test="payroll-deadline-payroll_foreign_permit:7"]').text())
      .toContain('Syntetický Cizinec · Syntetická karta')
  })

  /*
   * § 38j odst. 3 ZDP a § 183 odst. 1 ZP: žádost o potvrzení u trvajícího
   * vztahu vede na panel žádostí u osoby, lhůta pracovní cesty rovnou do
   * editoru cesty ve správném měsíci.
   */
  it('links taxable income requests and business trips to their screens', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      group({
        items: [item({
          reference: 'payroll_taxable_income_request:5',
          source: 'taxable_income_request',
          title: 'taxable_income_request',
          subject: 'Syntetická Žadatelka',
          period: '2025',
          due_on: '2026-08-22',
          employee_id: 61,
          employment_id: 62,
          request_id: 5,
        })],
      }),
      group({
        items: [item({
          reference: 'payroll_business_trip:9',
          source: 'business_trip',
          title: 'business_trip_documents',
          subject: 'Syntetický Cestovatel',
          period: null,
          due_on: '2026-08-28',
          employee_id: 71,
          employment_id: 72,
          trip_id: 9,
          trip_label: 'Brno',
          trip_period: '2026-08',
        })],
      }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const link = (reference: string) => JSON.parse(
      wrapper.get(`[data-test="payroll-deadline-link-${reference}"]`).attributes('data-to') ?? '{}',
    )
    expect(link('payroll_taxable_income_request:5')).toEqual({
      name: 'payroll-people',
      query: { person: '61', panel: 'taxable_income_requests', employment: '62' },
    })
    expect(link('payroll_business_trip:9')).toEqual({
      name: 'payroll-travel',
      query: { period: '2026-08', trip: '9' },
    })
    expect(wrapper.get('[data-test="payroll-deadline-payroll_business_trip:9"]').text())
      .toContain('Syntetický Cestovatel · Brno')
    expect(wrapper.get('[data-test="payroll-deadline-payroll_taxable_income_request:5"]').text())
      .toContain('payroll.dashboard.deadlines.kind.taxable_income_request')
  })

  it('falls back to the raw code when a source code has no translation', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      group({ items: [item({ reference: 'x', source: 'levy', title: 'risky_savings' })] }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadline-x"]').text()).toContain('risky_savings')
  })

  it('stays quiet when nothing is due', async () => {
    m.deadlineGroups.mockResolvedValue(overview([]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadlines-empty"]').text()).toBe('payroll.dashboard.deadlines.empty')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payroll-deadlines"]').classes())
      .toEqual(expect.arrayContaining(['border-neutral-200']))
  })

  it('states the failure in place and recovers through retry', async () => {
    m.deadlineGroups.mockRejectedValueOnce(new Error('boom'))

    const wrapper = mountPanel()
    await flushPromises()

    const error = wrapper.get('[data-test="payroll-deadlines-error"]')
    expect(error.attributes('role')).toBe('alert')

    m.deadlineGroups.mockResolvedValue(overview([group({ items: [item({ reference: 'again' })] })]))
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
    expect(m.deadlineGroups).not.toHaveBeenCalled()
  })

  it('hoists the source above the phase when every group in it shares it', async () => {
    m.deadlineGroups.mockResolvedValue(overview([
      contractGroup(),
      contractGroup({ key: 'overdue:checklist:tax_declaration', title: 'tax_declaration' }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadlines-phase-source-overdue"]').text())
      .toBe('payroll.dashboard.deadlines.source.checklist')
    expect(wrapper.get('[data-test="payroll-deadlines-group-overdue"]').text()).toContain('(450)')
  })

  /** Přihláška ČSSZ u nástupu před 1. 7. 2026 — termín se neodvozuje, povinnost trvá. */
  function undatedPerson(index: number): PayrollDeadlinePersonItem {
    return {
      ...person(index),
      title: 'social_jmhz_registration',
      due_on: null as unknown as string,
      phase: 'undated' as PayrollDeadlinePersonItem['phase'],
      days_to_due: null as unknown as number,
      is_overdue: false,
    }
  }

  function undatedGroup(): PayrollDeadlineGroup {
    return contractGroup({
      key: 'undated:checklist:social_jmhz_registration',
      phase: 'undated' as PayrollDeadlineGroup['phase'],
      title: 'social_jmhz_registration',
      count: 225,
      oldest_due_on: null as unknown as string,
      newest_due_on: null as unknown as string,
      min_days_to_due: null as unknown as number,
      max_days_to_due: null as unknown as number,
      is_overdue: false,
    })
  }

  it('lists open items without a deadline as their own last section, outside the overdue count', async () => {
    m.deadlineGroups.mockResolvedValue(overview([contractGroup({ count: 3 }), undatedGroup()]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.findAll('[data-test^="payroll-deadlines-group-"]').map(s => s.attributes('data-test')))
      .toEqual(['payroll-deadlines-group-overdue', 'payroll-deadlines-group-undated'])
    expect(wrapper.get('[data-test="payroll-deadlines-chip-overdue"]').text())
      .toBe('payroll.dashboard.deadlines.phase.overdue: 3')
    expect(wrapper.get('[data-test="payroll-deadlines-chip-undated"]').text())
      .toBe('payroll.dashboard.deadlines.phase.undated: 225')
    const section = wrapper.get('[data-test="payroll-deadlines-group-undated"]')
    expect(section.attributes('role')).toBeUndefined()
    expect(section.find('[data-test="payroll-deadlines-undated-hint"]').exists()).toBe(true)
    const key = 'undated:checklist:social_jmhz_registration'
    expect(wrapper.get(`[data-test="payroll-deadline-group-due-${key}"]`).text())
      .toBe('payroll.dashboard.deadlines.undated_label')
    expect(wrapper.get(`[data-test="payroll-deadline-group-count-${key}"]`).text())
      .toBe('payroll.dashboard.deadlines.people_count:225')
    expect(section.text()).not.toContain('date:')
  })

  it('marks a whole group without a deadline as done in one go', async () => {
    m.deadlineGroups.mockResolvedValue(overview([undatedGroup()]))
    m.deadlineGroupItems.mockResolvedValue(page([undatedPerson(1), undatedPerson(2)]))
    m.completeDeadlineChecklist.mockResolvedValue(result({ completed: [1, 2] }))

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="payroll-deadline-group-toggle-undated:checklist:social_jmhz_registration"]')
      .trigger('click')
    await flushPromises()

    expect(m.deadlineGroupItems).toHaveBeenCalledWith(expect.objectContaining({
      phase: 'undated',
      source: 'checklist',
      title: 'social_jmhz_registration',
    }))
    const row = wrapper.get('[data-test="payroll-deadline-person-payroll_checklist_item:1"]')
    expect(row.text()).toContain('–')
    expect(row.text()).toContain('payroll.dashboard.deadlines.undated_label')
    expect(row.text()).not.toContain('date:')

    await wrapper.get('[data-test="payroll-deadline-complete-all"]').trigger('click')
    await wrapper.get('[data-test="payroll-deadline-complete-run"]').trigger('click')
    await flushPromises()

    expect(m.completeDeadlineChecklist).toHaveBeenCalledWith({
      phase: 'undated',
      item_key: 'social_jmhz_registration',
      horizon_days: 45,
      note: 'payroll.dashboard.deadlines.group.note_default',
    })
    expect(wrapper.get('[data-test="payroll-deadlines-bulk-notice"]').text())
      .toBe('payroll.dashboard.deadlines.bulk_done:2')
  })

  it('shows a submission sent after the deadline as awaiting result and points to the data box', async () => {
    const sent = item({
      reference: 'payroll_obligation:7',
      title: 'PPZ_2026',
      phase: 'awaiting_result',
      days_to_due: -13,
      submitted_late: true,
      dispatched_via_isds: true,
    })
    m.deadlineGroups.mockResolvedValue(overview([group({ items: [sent] })]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-deadlines-group-overdue"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="payroll-deadline-due-payroll_obligation:7"]').text())
      .toBe('payroll.dashboard.deadlines.submitted_late_label')
    const hint = wrapper.get('[data-test="payroll-deadline-isds-reply-payroll_obligation:7"]')
    expect(hint.text()).toContain('payroll.dashboard.deadlines.isds_reply_hint')
    expect(hint.get('a').attributes('data-to')).toBe('"/admin/databox?tab=inbox"')
  })

  it('offers the data box link only to whoever may open the data box', async () => {
    m.canWrite.mockImplementation((key: string) => key !== 'settings.signing')
    const sent = item({
      reference: 'payroll_obligation:8',
      phase: 'awaiting_result',
      days_to_due: 3,
      submitted_late: false,
      dispatched_via_isds: true,
    })
    m.deadlineGroups.mockResolvedValue(overview([group({ items: [sent] })]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-deadline-due-payroll_obligation:8"]').text())
      .toBe('payroll.dashboard.deadlines.awaiting_label')
    const hint = wrapper.get('[data-test="payroll-deadline-isds-reply-payroll_obligation:8"]')
    expect(hint.find('a').exists()).toBe(false)
  })
})
