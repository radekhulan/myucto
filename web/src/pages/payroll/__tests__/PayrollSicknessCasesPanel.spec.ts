import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  preview: vi.fn(),
  prepare: vi.fn(),
  recordReceipt: vi.fn(),
  person: vi.fn(),
}))

vi.mock('@/api/payrollSicknessCases', () => ({
  payrollSicknessCasesApi: {
    list: m.list,
    create: m.create,
    update: m.update,
    preview: m.preview,
    prepare: m.prepare,
    recordReceipt: m.recordReceipt,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { person: m.person },
}))

vi.mock('@/components/payroll/PayrollPersonSearchSelect.vue', () => ({
  default: {
    name: 'PayrollPersonSearchSelect',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<select data-test="person-search" role="combobox" />',
  },
}))

vi.mock('@/components/ui/SearchableSelect.vue', () => ({
  default: {
    name: 'SearchableSelect',
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template: '<select data-test="searchable-select" />',
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
  }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PayrollSicknessCasesPanel from '../PayrollSicknessCasesPanel.vue'

/**
 * ActionBar drží inline jen první tři akce (AGENTS.md: max 3 tlačítka
 * v hlavičce) a zbytek schová do „…". Test se proto ptá na PROPS lišty, ne na
 * vykreslené `<button>` — jinak by kontroloval rozvržení ActionBaru, který má
 * vlastní spec, místo pravidel téhle obrazovky.
 */
function actionsOf(wrapper: ReturnType<typeof mount>, key: string): ActionItem | undefined {
  for (const bar of wrapper.findAllComponents(ActionBar)) {
    const found = (bar.props('actions') as ActionItem[])
      .find(action => action.key === key)
    if (found) return found
  }
  return undefined
}

function sicknessCase(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    employee_id: 3,
    employment_id: 5,
    full_name: 'Jan Novák',
    benefit_kind: 'NEM',
    ossz_code: 115,
    decision_number: 'A1234567',
    foreign_case: 0,
    correction: 0,
    incapacity_from: '2026-08-01',
    incapacity_to: null,
    issued_on: null,
    payroll_payment_date: null,
    worked_on_decisive_day: 1,
    hours_worked: '4.00',
    daily_working_hours: '8.00',
    small_scope_income_minor: null,
    receives_pension: 0,
    pension_kind: null,
    is_student: 0,
    within_school_holidays: null,
    first_employment_free_time: 0,
    unpaid_leave: 0,
    unpaid_leave_from: null,
    unpaid_leave_to: null,
    starts_maternity: null,
    child_birth_date: null,
    transferred_other_work: 0,
    transferred_on: null,
    enforcement: 0,
    insolvency: 0,
    returned_to_work: null,
    return_reason: null,
    returned_on: null,
    hours_worked_last_day: null,
    shift_hours_last_day: null,
    additional_note: null,
    status: 'draft',
    accepted_on: null,
    rejection_reason: null,
    nempri_submission_id: null,
    hzupn_submission_id: null,
    row_version: 1,
    work_days: [],
    ...overrides,
  }
}

async function mountPanel() {
  const wrapper = mount(PayrollSicknessCasesPanel, {
    global: { stubs: { RouterLink: true } },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollSicknessCasesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.list.mockResolvedValue([sicknessCase()])
    m.person.mockResolvedValue({ employments: [] })
  })

  it('vypíše evidované případy dávek', async () => {
    const wrapper = await mountPanel()

    expect(m.list).toHaveBeenCalledWith('production')
    expect(wrapper.find('[data-test="sickness-case-7"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Jan Novák')
  })

  /**
   * HZUPN se podává až po skončení neschopnosti (§ 97 odst. 3). Akce se ale
   * NESKRÝVÁ — skrytá by vypadala jako neexistující povinnost.
   */
  it('nechá HZUPN zašedlé, dokud neschopnost trvá', async () => {
    const wrapper = await mountPanel()
    const prepareHzupn = actionsOf(wrapper, 'prepare-hzupn')

    expect(prepareHzupn).toBeDefined()
    expect(prepareHzupn?.show).not.toBe(false)
    expect(prepareHzupn?.disabled).toBe(true)
    expect(prepareHzupn?.disabledReason).toContain('hints.incapacityEndRequired')
  })

  /**
   * Datovou větu NEMPRI umí aplikace sestavit jen u NEM a VPM. U ošetřovného
   * musí zůstat zavřená s vlastní větou proč — žádost o dávku podává pojištěnec.
   */
  it('nedovolí připravit NEMPRI u dávky, kterou zaměstnavatel nedrží', async () => {
    m.list.mockResolvedValue([sicknessCase({ benefit_kind: 'OSE', id: 9 })])
    const wrapper = await mountPanel()
    const prepareNempri = wrapper.findAll('button').find(
      button => button.text().includes('actions.prepareNempri'),
    )

    expect(prepareNempri?.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('hints.benefitKindNotSerializable')
  })

  /**
   * Vícesekční editor má JEDNO společné Uložit ve spodní liště; sekce nemají
   * vlastní tlačítka, aby nešlo uložit půlku případu.
   */
  it('má v editoru jediné společné Uložit', async () => {
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-editor-7"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sickness-case-save-bar"]').exists()).toBe(true)
    const saveButtons = wrapper.findAll('button').filter(
      button => button.text().includes('actions.save'),
    )
    expect(saveButtons).toHaveLength(1)
  })

  it('uloží celý editor jedním voláním včetně dnů práce', async () => {
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="sickness-case-incapacity-to"]')
      .setValue('2026-08-22')
    await wrapper.find('[data-test="sickness-case-work-day-add"]').trigger('click')
    await wrapper.find('[data-test="sickness-case-work-day-0"] input')
      .setValue('2026-08-10')
    await wrapper.findAll('[data-test="sickness-case-work-day-0"] input')[1]
      .setValue('2026-08-11')
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()

    expect(m.update).toHaveBeenCalledTimes(1)
    const [environment, caseId, rowVersion, payload] = m.update.mock.calls[0]
    expect(environment).toBe('production')
    expect(caseId).toBe(7)
    expect(rowVersion).toBe(1)
    expect(payload.incapacity_to).toBe('2026-08-22')
    expect(payload.work_days).toEqual([{ from: '2026-08-10', to: '2026-08-11' }])
  })

  /**
   * Přijetí se nesmí zapsat bez dne DORUČENÍ z protokolu — povinnost je
   * splněná předáním ČSSZ, ne kliknutím.
   */
  it('nedovolí zapsat přijetí bez dne doručení', async () => {
    const wrapper = await mountPanel()

    expect(actionsOf(wrapper, 'accept')?.disabled).toBe(true)

    await wrapper.find('[data-test="sickness-case-accepted-on-7"]')
      .setValue('2026-08-18')
    await flushPromises()

    expect(actionsOf(wrapper, 'accept')?.disabled).toBe(false)
  })

  it('zobrazí náhled zmrazené datové věty', async () => {
    m.preview.mockResolvedValue({
      case_id: 7,
      agenda_code: 'NEMPRI',
      document_kind: 'nempri',
      document_type: 'NEMPRI25',
      xml: '<NEMPRI version="1.0"/>',
      xml_sha256: 'abc',
      channel: 'isds',
      window: {
        earliest_notification_on: '2026-08-15',
        due_on: '2026-08-17',
        legal_reference: '§ 97 odst. 2 věta druhá zákona č. 187/2006 Sb.',
        deadline_source_status: 'derived_immediacy',
      },
      official_submission: { supported: false, reason: 'test' },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.previewNempri'))!
      .trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith('production', 7, 'nempri')
    expect(wrapper.find('[data-test="sickness-case-preview-7"]').text())
      .toContain('<NEMPRI version="1.0"/>')
  })

  it('ukáže chybu ze serveru místo obecné hlášky', async () => {
    m.prepare.mockRejectedValue({
      isAxiosError: true,
      response: { data: { message: 'Firma nemá vyplněný variabilní symbol ČSSZ.' } },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.prepareNempri'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-error"]').text())
      .toContain('Firma nemá vyplněný variabilní symbol ČSSZ.')
  })
})
