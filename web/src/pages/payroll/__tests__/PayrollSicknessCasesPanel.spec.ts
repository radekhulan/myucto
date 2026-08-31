import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  preview: vi.fn(),
  prepare: vi.fn(),
  dispatch: vi.fn(),
  recordReceipt: vi.fn(),
  person: vi.fn(),
  gatewayStartPayroll: vi.fn(),
}))

vi.mock('@/api/payrollSicknessCases', () => ({
  payrollSicknessCasesApi: {
    list: m.list,
    create: m.create,
    update: m.update,
    preview: m.preview,
    prepare: m.prepare,
    dispatch: m.dispatch,
    recordReceipt: m.recordReceipt,
  },
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartPayroll: m.gatewayStartPayroll },
}))

vi.mock('@/components/submission/MobileKeySendButton.vue', () => ({
  default: {
    name: 'MobileKeySendButton',
    props: ['outboxId', 'environment'],
    emits: ['sent'],
    template: '<button data-test="mobile-key-send" />',
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

/**
 * Seznam případů nese od serveru i stav odesílací cesty a frontu — panel se
 * podle toho rozhoduje, jestli nabídne „Odeslat datovou schránkou". Výchozí
 * hodnota je nejhorší případ („ručně"), aby test musel dostupnost přiznat
 * výslovně.
 */
function listResponse(
  items: ReturnType<typeof sicknessCase>[],
  overrides: Record<string, unknown> = {},
) {
  return {
    items,
    transport: { automatic: false, channel: 'manual_upload', reason: 'isds_transport_unavailable' },
    ready_submissions: [],
    ...overrides,
  }
}

function readySubmission(overrides: Record<string, unknown> = {}) {
  return {
    submission_id: 44,
    agenda_code: 'NEMPRI',
    submission_kind: 'regular',
    submission_status: 'ready',
    corrects_submission_id: null,
    period_start: '2026-08-01',
    period_end: '2026-08-31',
    created_at: '2026-08-16 10:00:00',
    outbox_id: null,
    outbox_dispatch_state: null,
    outbox_acceptance_state: null,
    outbox_external_message_id: null,
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
    m.list.mockResolvedValue(listResponse([sicknessCase()]))
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
    m.list.mockResolvedValue(listResponse([sicknessCase({ benefit_kind: 'OSE', id: 9 })]))
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
  /**
   * Jádro celé opravy: připravené NEMPRI se DÁ odeslat rovnou tady.
   *
   * Panel dřív psal „Odešlete ho ve Stavu odeslání" a odkazoval tak na
   * obrazovku kanálu VREP/APEP, kde tahle podání nikdy nebyla — účetní neměla
   * hlášení kde odeslat.
   */
  it('nabídne u připraveného NEMPRI odeslání datovou schránkou', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({ status: 'prepared', nempri_submission_id: 44 })],
      { ready_submissions: [readySubmission()] },
    ))
    const wrapper = await mountPanel()

    const dispatch = actionsOf(wrapper, 'dispatch-nempri')
    expect(dispatch).toBeDefined()
    expect(dispatch?.show).toBe(true)
    expect(dispatch?.disabled).toBe(false)
  })

  it('nenabídne odeslání u případu, ze kterého se ještě nepřipravilo podání', async () => {
    const wrapper = await mountPanel()

    expect(actionsOf(wrapper, 'dispatch-nempri')?.show).toBe(false)
    expect(actionsOf(wrapper, 'dispatch-hzupn')?.show).toBe(false)
  })

  /** Kliknutí musí opravdu volat API, ne jen překreslit lištu. */
  it('zařadí podání do fronty voláním serveru', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({ status: 'prepared', nempri_submission_id: 44 })],
      { ready_submissions: [readySubmission()] },
    ))
    m.dispatch.mockResolvedValue({
      case_id: 7,
      document_kind: 'nempri',
      agenda_code: 'NEMPRI',
      outbox_id: 91,
      created: true,
      recipient: { box_id: '9tsaf6s', name: 'ČSSZ — e-Podání TEST', note: '' },
      subject: 'NEMPRI - Oznámení zaměstnavatele o žádosti zaměstnance o dávku za 08/2026',
      sender_ident: 'NEMPRI-000091',
      attachment: { filename: 'NEMPRI_1234567890_08-2026.xml', mime: 'application/xml', sha256: 'abc', bytes: 120 },
      transport: { automatic: false, channel: 'manual_upload', reason: 'isds_transport_unavailable' },
    })
    const wrapper = await mountPanel()

    // ActionBar drží inline jen první tři akce, zbytek schová do „…" — akce
    // se proto spouští přes vlastní handler, ne přes hledání `<button>`.
    // Nabídnutá být ale MUSÍ: skrytá akce, kterou test přesto zavolá, by
    // prošla i tehdy, kdyby se k ní uživatel nikdy nedostal.
    const dispatch = actionsOf(wrapper, 'dispatch-nempri')
    expect(dispatch?.show).toBe(true)
    await dispatch!.run!()
    await flushPromises()

    expect(m.dispatch).toHaveBeenCalledWith('production', 7, 'nempri')
    expect(wrapper.find('[data-test="sickness-case-success"]').text())
      .toContain('dispatch.queued')
  })

  /**
   * Právě jedna ze tří vět o tom, co se s podáním stane. Bez brány a bez
   * doložené schránky se nesmí tvrdit, že appka odešle sama.
   */
  it('řekne u připraveného podání konkrétní cestu ven', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({ status: 'prepared', nempri_submission_id: 44 })],
      { ready_submissions: [readySubmission()] },
    ))
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="sickness-case-dispatch-7-nempri"]').text())
      .toContain('dispatch.transportManual')
  })

  it('u firmy s Mobilním klíčem slíbí odeslání po potvrzení v mobilu', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({ status: 'prepared', nempri_submission_id: 44 })],
      {
        ready_submissions: [readySubmission()],
        transport: { automatic: false, channel: 'mobile_key', reason: null },
      },
    ))
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="sickness-case-dispatch-7-nempri"]').text())
      .toContain('dispatch.transportMobileKey')
  })

  /**
   * Už zařazené podání se nenabízí k zařazení podruhé, ale musí být vidět,
   * že ve frontě JE — jinak se to čte jako „neodešlo to".
   */
  it('u zařazeného podání ukáže frontu místo dalšího tlačítka', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({ status: 'prepared', nempri_submission_id: 44 })],
      { ready_submissions: [readySubmission({ outbox_id: 91, outbox_dispatch_state: 'ready' })] },
    ))
    const wrapper = await mountPanel()

    expect(actionsOf(wrapper, 'dispatch-nempri')?.show).toBe(false)
    expect(wrapper.find('[data-test="sickness-case-outbox-7-nempri"]').text())
      .toContain('dispatch.inOutbox')
  })
})

