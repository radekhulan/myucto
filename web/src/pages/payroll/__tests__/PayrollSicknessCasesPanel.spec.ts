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
  locale: { value: 'cs' },
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
    te: (key: string) => key.startsWith('payroll.server_codes.'),
    locale: m.locale,
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
    slovak_case: 0,
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
    nempri_status: 'pending',
    nempri_accepted_on: null,
    nempri_rejection_reason: null,
    hzupn_status: 'pending',
    hzupn_accepted_on: null,
    hzupn_rejection_reason: null,
    cancelled: 0,
    source: 'myucto',
    external_reference: null,
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
  it('posune chybu náhledu NEMPRI do zobrazení', async () => {
    m.preview.mockRejectedValue({
      isAxiosError: true,
      response: { data: { error: {
        code: 'sickness_variable_symbol_missing',
        message: 'Firma nemá vyplněný variabilní symbol ČSSZ.',
      } } },
    })
    const wrapper = await mountPanel()
    const original = HTMLElement.prototype.scrollIntoView
    const scrollIntoView = vi.fn()
    HTMLElement.prototype.scrollIntoView = scrollIntoView
    try {
      await wrapper.findAll('button')
        .find(button => button.text().includes('actions.previewNempri'))!.trigger('click')
      await flushPromises()
      const error = wrapper.get('[data-test="sickness-case-error-box"]')
      expect(error.text()).toContain('Firma nemá vyplněný variabilní symbol ČSSZ.')
      expect(scrollIntoView).toHaveBeenCalledTimes(1)
      expect(scrollIntoView.mock.contexts[0]).toBe(error.element)
      expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
    } finally {
      HTMLElement.prototype.scrollIntoView = original
      wrapper.unmount()
    }
  })

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

  /* Q8-26/27/28: návrh příjmu předvyplněný, stará zelená hláška zmizí, přepínač prostředí. */
  it('editor předvyplní pravděpodobný příjem a smaže starou hlášku o uložení', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({ probable_income_suggestion_minor: 3_800_000 })]))
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()
    expect(wrapper.find('[data-test="sickness-case-environment"]').exists()).toBe(false)

    const openEditor = async () => {
      await wrapper.findAll('button')
        .find(button => button.text().includes('actions.edit'))!
        .trigger('click')
      await flushPromises()
    }
    await openEditor()
    expect((wrapper.get('[data-test="sickness-case-probable-income"]').element as HTMLInputElement).value).toBe('38000')

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="sickness-case-success"]').exists()).toBe(true)
    await openEditor()
    expect(wrapper.find('[data-test="sickness-case-success"]').exists()).toBe(false)
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
   * Ošetřovné bylo zablokované jako „údaje, které zaměstnavatel nedrží“.
   * Zaměstnavatel žádost přijímá a předává, takže NEMPRI jde připravit;
   * HZUPN se u ošetřovného nenabízí — hlášení při ukončení neschopnosti nemá.
   */
  it('dovolí připravit NEMPRI u ošetřovného a HZUPN nenabízí', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({ benefit_kind: 'OSE', id: 9 })]))
    const wrapper = await mountPanel()

    expect(actionsOf(wrapper, 'prepare-nempri')?.disabled).toBe(false)
    expect(actionsOf(wrapper, 'prepare-hzupn')?.show).toBe(false)
    expect(wrapper.text()).not.toContain('hints.benefitKindNotSerializable')
  })

  /**
   * Převedená zaměstnankyně (§ 19 odst. 6, Všeobecné zásady NEMPRI): případ
   * nese druhé oznámení ke dni převedení s vlastním náhledem, přípravou,
   * odesláním a výsledkem. Bez převedení se nenabízí vůbec.
   */
  it('nabídne druhé oznámení NEMPRI ke dni převedení jen u převedené zaměstnankyně', async () => {
    m.list.mockResolvedValue(listResponse(
      [sicknessCase({
        transferred_other_work: 1,
        transferred_on: '2026-05-10',
        transfer_reason: 'pregnancy',
        nempri_transfer_status: 'pending',
        nempri_transfer_submission_id: 45,
        status: 'prepared',
      })],
      { ready_submissions: [readySubmission({ submission_id: 45 })] },
    ))
    m.prepare.mockResolvedValue({})
    const wrapper = await mountPanel()

    expect(actionsOf(wrapper, 'preview-nempri_transfer')?.show).toBe(true)
    expect(actionsOf(wrapper, 'prepare-nempri_transfer')?.disabled).toBe(true)
    expect(actionsOf(wrapper, 'dispatch-nempri_transfer')?.show).toBe(true)
    expect(actionsOf(wrapper, 'accept-nempri_transfer')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.sicknessCases.documents.nempri_transfer')
    expect(wrapper.find('[data-test="sickness-case-dispatch-7-nempri_transfer"]').exists()).toBe(true)

    m.list.mockResolvedValue(listResponse([sicknessCase({ nempri_transfer_status: null })]))
    const plain = await mountPanel()
    expect(actionsOf(plain, 'preview-nempri_transfer')?.show).toBe(false)
    expect(actionsOf(plain, 'accept-nempri_transfer')).toBeUndefined()
  })

  /**
   * Celá žádost o ošetřovné jde zadat v editoru a uloží se jedním voláním:
   * akce, dny péče, ruční měsíc rozhodného období (Kč → haléře)
   * i příjem z malého rozsahu.
   */
  it('uloží žádost o ošetřovné i rozhodné období z editoru', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({
      benefit_kind: 'OSE',
      incapacity_to: '2026-09-11',
      probable_income_suggestion_minor: 4_200_000,
    })]))
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-application"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sickness-case-actions"]').exists()).toBe(true)
    // HZUPN sekce (ukončení neschopnosti) u ošetřovného není.
    expect(wrapper.find('[data-test="sickness-case-returned-to-work"]').exists()).toBe(false)

    await wrapper.find('[data-test="sickness-case-action-end"]').setValue(true)
    await wrapper.find('[data-test="sickness-case-care-reason"]').setValue('ill')
    await wrapper.find('[data-test="sickness-case-relationship-code"]').setValue('PL')
    await wrapper.find('[data-test="sickness-case-cared-first-name"]').setValue('Dítě')
    await wrapper.find('[data-test="sickness-case-cared-last-name"]').setValue('Testovací')
    await wrapper.find('[data-test="sickness-case-small-scope-income"]').setValue('3500')
    actionsOf(wrapper, 'care-add')!.run!()
    actionsOf(wrapper, 'decisive-add')!.run!()
    await flushPromises()
    const careFields = wrapper.findAll('[data-test="sickness-case-care-days"] input[type="text"]')
    await careFields[0].setValue('2026-09-07')
    await careFields[1].setValue('2026-09-11')
    const month = wrapper.find('[data-test="sickness-case-decisive-month-0"]')
    await month.find('input[type="month"]').setValue('2025-12')
    await month.find('input[inputmode="decimal"]').setValue('31000,50')
    await month.find('input[type="number"]').setValue('4')
    actionsOf(wrapper, 'suggest-probable-income')!.run!()
    await flushPromises()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()

    const payload = m.update.mock.calls[0][3]
    expect(payload.action_start).toBe(1)
    expect(payload.action_end).toBe(1)
    expect(payload.care_reason).toBe('ill')
    expect(payload.relationship_code).toBe('PL')
    expect(payload.cared_first_name).toBe('Dítě')
    expect(payload.small_scope_income_minor).toBe(350_000)
    expect(payload.probable_income_czk).toBe(42_000)
    expect(payload.care_days).toEqual([{ from: '2026-09-07', to: '2026-09-11' }])
    expect(payload.decisive_months).toEqual([
      { period: '2025-12', income_minor: 3_100_050, excluded_days: 4 },
    ])
  })

  /**
   * Kódované prvky NEMPRI se vybírají z číselníků ČSSZ. Ošetřovné a DLO
   * sdílejí pole, ale ne číselník: u ošetřovného CIS_RODVZTAH, u DLO CIS_VZTAH.
   * Starý ručně zapsaný kód mimo číselník zůstane v nabídce označený.
   */
  it('nabídne vztah z číselníku podle druhu dávky', async () => {
    m.list.mockResolvedValue(listResponse([
      sicknessCase({ benefit_kind: 'OSE', relationship_code: 'AB' }),
      sicknessCase({ benefit_kind: 'DLO', id: 8 }),
    ]))
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .filter(button => button.text().includes('actions.edit'))[0]
      .trigger('click')
    await flushPromises()

    const oseOptions = wrapper.findAll('[data-test="sickness-case-relationship-code"] option')
      .map(option => option.attributes('value') ?? '')
    expect(oseOptions).toContain('PL')
    expect(oseOptions).toContain('JIN')
    expect(oseOptions).not.toContain('3')
    expect(wrapper.find('[data-test="sickness-case-relationship-code"]').text())
      .toContain('codebooks.invalidValue')

    actionsOf(wrapper, 'cancel')!.run!()
    await flushPromises()
    await wrapper.find('[data-test="sickness-case-8"]').findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()
    const dloOptions = wrapper.findAll('[data-test="sickness-case-relationship-code"] option')
      .map(option => option.attributes('value') ?? '')
    expect(dloOptions).toContain('3')
    expect(dloOptions).toContain('29')
    expect(dloOptions).not.toContain('PL')
  })

  /**
   * § 191a ZP: u DLO jde zapsat rozhodnutí zaměstnavatele, u odmítnutí
   * s důvodem. Důvod se po přepnutí na souhlas neposílá.
   */
  it('zapíše odmítnutí dlouhodobé péče s dnem a důvodem', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({ benefit_kind: 'DLO' })]))
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-ltc-refusal-reason"]').exists()).toBe(false)
    await wrapper.find('[data-test="sickness-case-ltc-consent"]').setValue('refused')
    await flushPromises()
    await wrapper.find('[data-test="sickness-case-ltc-consent-on"]').setValue('2026-08-03')
    await wrapper.find('[data-test="sickness-case-ltc-refusal-reason"]').setValue('Vážné provozní důvody.')
    await wrapper.find('[data-test="sickness-case-relationship-code"]').setValue('2')
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()

    const payload = m.update.mock.calls[0][3]
    expect(payload.long_term_care_consent).toBe('refused')
    expect(payload.long_term_care_consent_on).toBe('2026-08-03')
    expect(payload.long_term_care_refusal_reason).toBe('Vážné provozní důvody.')
    expect(payload.relationship_code).toBe('2')
  })

  it('ukáže ochrannou lhůtu, odmítnutou péči a původ z nepřítomnosti', async () => {
    m.list.mockResolvedValue(listResponse([
      sicknessCase({
        absence_id: 31,
        protection_period: {
          status: 'protection_period',
          employment_end: '2026-07-31',
          protection_until: '2026-08-07',
        },
      }),
      sicknessCase({
        id: 8,
        protection_period: {
          status: 'outside',
          reason_code: 'sickness_event_outside_protection_period',
          message: 'Ochranná lhůta skončila 7. 8.',
        },
      }),
      sicknessCase({
        id: 9,
        benefit_kind: 'DLO',
        long_term_care_consent: 'refused',
        long_term_care_consent_on: '2026-08-03',
      }),
    ]))
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="sickness-case-protection-7"]').text())
      .toContain('protectionPeriod.within')
    expect(wrapper.find('[data-test="sickness-case-origin-7"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sickness-case-protection-outside-8"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sickness-case-ltc-refused-9"]').text())
      .toContain('longTermCare.refusedBadge')
  })

  it('u odmítnuté dlouhodobé péče nabídne opravu v editoru', async () => {
    m.preview.mockRejectedValue({
      isAxiosError: true,
      response: { data: { error: {
        code: 'dlo_employer_refused',
        message: 'Zaměstnavatel dlouhodobou péči odmítl.',
      } } },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.previewNempri'))!
      .trigger('click')
    await flushPromises()

    expect(actionsOf(wrapper, 'error-fix-edit')?.show).toBe(true)
  })

  /**
   * HZUPN „nevrátil se do práce“ nese důvod i den, ke kterému nastal —
   * ČSSZ takové hlášení přijímá. Dřív šel zaškrtnout jen návrat.
   */
  it('umí zadat HZUPN bez návratu do práce s důvodem', async () => {
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-return-reason"]').exists()).toBe(false)
    await wrapper.find('[data-test="sickness-case-returned-to-work"]').setValue('0')
    await flushPromises()
    await wrapper.find('[data-test="sickness-case-return-reason"]').setValue('skončení zaměstnání')
    await wrapper.find('[data-test="sickness-case-correction"]').setValue(true)
    await wrapper.find('[data-test="sickness-case-contact-name"]').setValue('Mzdová Účetní')
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()

    const payload = m.update.mock.calls[0][3]
    expect(payload.returned_to_work).toBe(0)
    expect(payload.return_reason).toBe('skončení zaměstnání')
    expect(payload.correction).toBe(1)
    expect(payload.contact_worker_name).toBe('Mzdová Účetní')
  })

  /**
   * Blokátor ze serveru musí říct, KDE se opraví: chybějící výplatní účet
   * vede na kartu osoby, chybějící převzatý měsíc na kontrolu převodu.
   */
  it('u chyby výplatního účtu nabídne proklik na kartu osoby', async () => {
    m.preview.mockRejectedValue({
      isAxiosError: true,
      response: { data: { error: {
        code: 'nempri_payment_connection_missing',
        message: 'Zaměstnanec nemá ve výplatním profilu účet.',
      } } },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.previewNempri'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-error"]').text())
      .toContain('Zaměstnanec nemá ve výplatním profilu účet.')
    expect(wrapper.find('[data-test="sickness-case-error-fix-person"]').exists()).toBe(true)
  })

  it('u chybějícího měsíce rozhodného období odkáže na kontrolu převodu a editor', async () => {
    m.preview.mockRejectedValue({
      isAxiosError: true,
      response: { data: { error: {
        code: 'nempri_decisive_month_missing',
        message: 'Chybí převzatá mzda: 2025-11.',
      } } },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.previewNempri'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-error-fix-reconciliation"]').exists()).toBe(true)
    actionsOf(wrapper, 'error-fix-edit')!.run!()
    await flushPromises()
    expect(wrapper.find('[data-test="sickness-case-decisive-period"]').exists()).toBe(true)
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
    // Každé datumové pole je dnes DateInput: viditelný text + skryté date pole
    // pro kalendář. Bez omezení na `type="text"` by druhý index trefil skryté
    // pole prvního data místo druhého data.
    const workDayFields = wrapper.findAll('[data-test="sickness-case-work-day-0"] input[type="text"]')
    await workDayFields[0].setValue('2026-08-10')
    await workDayFields[1].setValue('2026-08-11')
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

    expect(actionsOf(wrapper, 'accept-nempri')?.disabled).toBe(true)

    await wrapper.find('[data-test="sickness-case-accepted-on-7-nempri"]')
      .setValue('2026-08-18')
    await flushPromises()

    expect(actionsOf(wrapper, 'accept-nempri')?.disabled).toBe(false)
    // Den doručení NEMPRI neodemkne zápis HZUPN — každé podání má svůj.
    expect(actionsOf(wrapper, 'accept-hzupn')?.disabled).toBe(true)
  })

  /**
   * PRE-01: po přijetí NEMPRI zůstává případ upravitelný a HZUPN má vlastní
   * zápis výsledku; přijetí se posílá s tiskopisem.
   */
  it('po přijetí NEMPRI nabídne úpravu a výsledek HZUPN', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({
      status: 'submitted',
      nempri_status: 'accepted',
      nempri_accepted_on: '2026-08-16',
      incapacity_to: '2026-08-20',
    })]))
    m.recordReceipt.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-test="sickness-case-documents-7"]').text()).toContain('documentStatuses.accepted')
    expect(wrapper.find('[data-test="sickness-case-receipt-7-nempri"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="sickness-case-receipt-7-hzupn"]').exists()).toBe(true)
    expect(actionsOf(wrapper, 'edit')?.show).not.toBe(false)
    expect(actionsOf(wrapper, 'prepare-nempri')?.disabled).toBe(true)

    await wrapper.find('[data-test="sickness-case-accepted-on-7-hzupn"]').setValue('2026-08-24')
    await flushPromises()
    actionsOf(wrapper, 'accept-hzupn')!.run!()
    await flushPromises()

    expect(m.recordReceipt).toHaveBeenCalledWith('production', 7, {
      outcome: 'accepted',
      document: 'hzupn',
      accepted_on: '2026-08-24',
      reason: null,
    })
  })

  /**
   * NEMPRI vedené jako podané předchozím programem jde vrátit k podání
   * z MyÚčta; jinak by se povinnost přestala hlídat a nešla by připravit.
   */
  it('u NEMPRI předchozího programu nabídne vrácení k podání', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({
      source: 'predecessor',
      nempri_status: 'predecessor',
      incapacity_to: '2026-08-20',
    })]))
    m.recordReceipt.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="sickness-case-reopen-hint-7-nempri"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="sickness-case-accepted-on-7-nempri"]').exists()).toBe(false)
    expect(actionsOf(wrapper, 'accept-nempri')?.show).toBe(false)
    expect(actionsOf(wrapper, 'reopen-hzupn')?.show).toBe(false)
    actionsOf(wrapper, 'reopen-nempri')!.run!()
    await flushPromises()

    expect(m.recordReceipt).toHaveBeenCalledWith('production', 7, {
      outcome: 'pending',
      document: 'nempri',
      accepted_on: null,
      reason: null,
    })
  })

  /** NX-03: podklady pro výplatu DLO se ukládají s případem. */
  it('u DLO uloží pracovní volno a rozvrh směn', async () => {
    m.list.mockResolvedValue(listResponse([sicknessCase({
      benefit_kind: 'DLO',
      planned_shifts: 1,
      dlo_has_leave: 1,
      dlo_leave_periods: [{ from: '2026-08-05', to: '2026-08-06' }],
      dlo_shift_schedule: [{ from: '2026-08-03', to: '2026-08-07' }],
    })]))
    m.update.mockResolvedValue(sicknessCase())
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.edit'))!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="sickness-case-dlo-basis"]').exists()).toBe(true)
    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.save'))!
      .trigger('click')
    await flushPromises()

    const payload = m.update.mock.calls[0][3]
    expect(payload.dlo_has_leave).toBe(1)
    expect(payload.dlo_leave_periods).toEqual([{ from: '2026-08-05', to: '2026-08-06' }])
    expect(payload.dlo_shift_schedule).toEqual([{ from: '2026-08-03', to: '2026-08-07' }])
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
    // Skutečný tvar odpovědi serveru (Json::error). Dřív panel četl
    // `data.error` jako text a ukázal „[object Object]".
    m.prepare.mockRejectedValue({
      isAxiosError: true,
      response: { data: { error: {
        code: 'sickness_variable_symbol_missing',
        message: 'Firma nemá vyplněný variabilní symbol ČSSZ.',
      } } },
    })
    const wrapper = await mountPanel()

    await wrapper.findAll('button')
      .find(button => button.text().includes('actions.prepareNempri'))!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="sickness-case-error"]').text())
      .toContain('Firma nemá vyplněný variabilní symbol ČSSZ.')
  })

  it('v angličtině místo české věty serveru ukáže překlad kódu', async () => {
    m.locale.value = 'en'
    try {
      m.prepare.mockRejectedValue({
        isAxiosError: true,
        response: { data: { error: {
          code: 'sickness_variable_symbol_missing',
          message: 'Firma nemá vyplněný variabilní symbol ČSSZ.',
        } } },
      })
      const wrapper = await mountPanel()

      await wrapper.findAll('button')
        .find(button => button.text().includes('actions.prepareNempri'))!
        .trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="sickness-case-error"]').text())
        .toBe('payroll.server_codes.sickness_variable_symbol_missing')
    } finally {
      m.locale.value = 'cs'
    }
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
  // Ostré odeslání se ptá; dialog se teleportuje do <body>, ne do wrapperu.
  async function confirmProductionSend(): Promise<void> {
    const buttons = document.querySelectorAll<HTMLButtonElement>(
      '[data-test="production-send-confirm-yes"]',
    )
    const button = buttons[buttons.length - 1]
    expect(button).toBeDefined()
    button!.click()
    await flushPromises()
  }

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
    const pending = dispatch!.run!()
    await flushPromises()
    expect(m.dispatch).not.toHaveBeenCalled()
    await confirmProductionSend()
    await pending
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

