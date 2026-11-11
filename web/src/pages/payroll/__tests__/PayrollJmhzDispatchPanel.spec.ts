import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollJmhzPvpojPreview,
  PayrollSubmissionOverviewItem,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  freezePreparation: vi.fn(),
  freezeSubmission: vi.fn(),
  sendTransport: vi.fn(),
  enqueueIsds: vi.fn(),
  gatewayStart: vi.fn(),
  employerSettings: vi.fn(),
  deferMany: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    freezeJmhzPreparation: m.freezePreparation,
    freezeJmhzSubmission: m.freezeSubmission,
    sendJmhzTransport: m.sendTransport,
    enqueueJmhzIsds: m.enqueueIsds,
    employerSettings: m.employerSettings,
    deferJmhzEmployments: m.deferMany,
  },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartPayroll: m.gatewayStart },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, params?: Record<string, unknown>) =>
    params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/api/errors', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/errors')>()),
  apiErrorMessage: (error: unknown, fallback = '') => {
    const message = (error as { response?: { data?: { error?: { message?: string } } } })
      ?.response?.data?.error?.message
    if (typeof message === 'string') return message
    return error instanceof Error ? error.message : fallback
  },
  apiErrorCode: (error: unknown) => {
    const code = (error as { response?: { data?: { error?: { code?: string } } } })
      ?.response?.data?.error?.code
    return typeof code === 'string' ? code : ''
  },
}))

import PayrollJmhzDispatchPanel from '../PayrollJmhzDispatchPanel.vue'

const preview = {
  run_id: 42,
  revision_id: 7,
  revision_no: 1,
  period: '2026-07',
  office: {
    office_id: 3,
    code: 'PRAHA',
    name: 'Praha',
    variable_symbol: '12345678',
  },
} as unknown as PayrollJmhzPvpojPreview

function obligation(overrides: Partial<PayrollSubmissionOverviewItem> = {}) {
  return {
    id: 99,
    environment: 'production',
    agenda_code: 'JMHZ25',
    agenda_group: 'jmhz',
    subject_type: 'payroll_run',
    subject_reference: 'payroll_run:42:office:3',
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    obligation_kind: 'regular',
    preferred_channel: 'vrep_apep',
    status: 'open',
    row_version: 1,
    earliest_submission_on: '2026-08-01',
    due_on: '2026-08-20',
    calendar_basis: 'cssz',
    deadline: {
      phase: 'open',
      days_to_due: 10,
      is_action_required: false,
      is_overdue: false,
    },
    latest_submission: null,
    ...overrides,
  } as PayrollSubmissionOverviewItem
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('crypto', { randomUUID: vi.fn(() => '00000000-0000-4000-8000-000000000001') })
  m.freezePreparation.mockResolvedValue({ id: 55 })
  m.freezeSubmission.mockResolvedValue({ submission_id: 66, status: 'ready' })
  m.employerSettings.mockResolvedValue({ offices: [] })
  m.enqueueIsds.mockResolvedValue({
    outbox_id: 77,
    created: true,
    environment: 'production',
    recipient: { environment: 'production', box_id: '5ffu6xk', name: 'ČSSZ', note: '' },
    subject: 'JMHZ 2026-07',
    sender_ident: 'MU-JMHZ',
    attachment: { filename: 'jmhz.xml', mime: 'application/xml', sha256: 'a', bytes: 10 },
    transport: { automatic: true, channel: 'gateway', reason: null },
    response_hint: { subject_prefix: 'JMHZ', attachment_prefix: 'JMHZ', note: '' },
  })
  m.gatewayStart.mockResolvedValue({
    session_id: 1,
    app_token: 'token',
    redirect_url: 'https://www.datovka.gov.cz/as/login',
    login_guidance: 'Přihlaste se metodou, kterou nabízí ISDS.',
    login_policy_documented: false,
    expires_at: '2026-08-25 15:00:00',
    resumed: false,
  })
})

describe('PayrollJmhzDispatchPanel', () => {
  it('zmrazí JMHZ, připraví ISDS zprávu a přesměruje až po potvrzení', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { assign },
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [obligation()],
      },
    })

    await wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    expect(m.freezePreparation).toHaveBeenCalledWith(7, expect.any(String), 'production')
    expect(m.freezeSubmission).toHaveBeenCalledWith(55, 99, 'production', 3, false, false)
    expect(m.enqueueIsds).toHaveBeenCalledWith(66, 'production')
    expect(m.gatewayStart).toHaveBeenCalledWith(77)
    expect(assign).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="jmhz-dispatch-gateway"]').text()).toContain(
      'Přihlaste se metodou, kterou nabízí ISDS.',
    )

    const continueButton = wrapper.findAll('button').find(button =>
      button.text().includes('jmhz_gateway_continue'),
    )
    await continueButton!.trigger('click')
    expect(assign).toHaveBeenCalledWith('https://www.datovka.gov.cz/as/login')
  })

  /*
   * Měsíc, za který řádné hlášení podal předchozí program: zmrazení skončí
   * chybou s kódem a panel k hlášce nabídne odkaz na převzatá podání.
   */
  it('u měsíce podaného předchozím programem nabídne odkaz na převzatá podání', async () => {
    m.freezeSubmission.mockRejectedValue({
      response: { data: { error: { code: 'jmhz_period_submitted_externally', message: 'Podal předchozí program.' } } },
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'test', previews: [preview], obligations: [] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    const link = wrapper.get('[data-test="jmhz-dispatch-external-link"]')
    expect(link.text()).toContain('payroll.external_jmhz.open_history')
    expect(link.attributes('data-to')).toContain('external-submissions')
    expect(m.sendTransport).not.toHaveBeenCalled()
  })

  it('před otevřením ostré lhůty odeslání nenabídne', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [obligation({ deadline: {
          phase: 'not_open',
          days_to_due: 25,
          is_action_required: false,
          is_overdue: false,
        } })],
      },
    })

    const button = wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.text()).toContain('jmhz_dispatch_not_open')
    expect(m.freezePreparation).not.toHaveBeenCalled()
  })

  it('při prvním podání založí povinnost automaticky', async () => {
    m.sendTransport.mockResolvedValue({
      attempt: { id: 77, status: 'submitted' },
      acknowledgement: null,
      settled: false,
      report: null,
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'test',
        previews: [preview],
        obligations: [],
      },
    })

    const button = wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(false)
    await button.trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    expect(m.freezePreparation).toHaveBeenCalledWith(7, expect.any(String), 'test')
    expect(m.freezeSubmission).toHaveBeenCalledWith(55, null, 'test', 3, false, false)
    expect(m.sendTransport).toHaveBeenCalledWith(
      66,
      '12345678',
      'test',
      expect.any(String),
    )
  })

  /**
   * Kontrola 290: hlášení po splatnosti se slevou. Zmrazení se neodmítne
   * natvrdo, ale čeká na vědomé potvrzení účetní. Potvrzení pošle totéž
   * zmrazení znovu s `confirmLateDiscount` a pokračuje zvoleným kanálem.
   */
  it('u slevy po lhůtě se zeptá na potvrzení a po něm zmrazí a odešle', async () => {
    m.freezeSubmission.mockRejectedValueOnce({
      response: { data: { error: {
        code: 'jmhz_submission_late_discount_confirmation_required',
        message: 'Hlášení se podává po lhůtě (2026-08-20) a uplatňuje slevu.',
      } } },
    })
    m.sendTransport.mockResolvedValue({
      attempt: { id: 77, status: 'awaiting_protocol' },
      acknowledgement: null,
      settled: false,
      report: null,
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'production', previews: [preview], obligations: [obligation()] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    const confirm = wrapper.get('[data-test="jmhz-late-discount-7:3"]')
    expect(confirm.text()).toContain('Hlášení se podává po lhůtě (2026-08-20) a uplatňuje slevu.')
    expect(m.sendTransport).not.toHaveBeenCalled()

    await wrapper.get('[data-test="jmhz-late-discount-7:3-yes"]').trigger('click')
    await flushPromises()

    expect(m.freezeSubmission).toHaveBeenLastCalledWith(55, 99, 'production', 3, true, false)
    expect(m.sendTransport).toHaveBeenCalledWith(66, '12345678', 'production', expect.any(String))
    expect(wrapper.find('[data-test="jmhz-late-discount-7:3"]').exists()).toBe(false)
  })

  /** Audit #16: 48 h po přijaté změně A3 nabídne odeslat i tak, nebo odložit dotčené. */
  it('do 48 hodin po změně údajů nabídne odeslat i tak a odložit dotčené', async () => {
    m.freezeSubmission.mockRejectedValueOnce({
      response: { data: { error: {
        code: 'jmhz_registration_change_settling',
        message: 'U 1 pracovních vztahů ČSSZ přijala změnu údajů.',
        details: { deferral: { preparation_id: 55, environment: 'production', office_id: 3, employment_ids: [11], count: 1 } },
      } } },
    })
    m.sendTransport.mockResolvedValue({
      attempt: { id: 77, status: 'awaiting_protocol' },
      acknowledgement: null,
      settled: false,
      report: null,
    })
    m.deferMany.mockResolvedValue({ created: true, deferral_ids: [1], employment_ids: [11], people: [{ employee_id: 5 }], firm_blocker_count: 0, source_revision_id: 7 })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'production', previews: [preview], obligations: [obligation()] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    const confirm = wrapper.get('[data-test="jmhz-settling-7:3"]')
    expect(confirm.text()).toContain('U 1 pracovních vztahů ČSSZ přijala změnu údajů.')
    await confirm.get('[data-test="jmhz-bulk-defer-open"]').trigger('click')
    await confirm.get('[data-test="jmhz-bulk-defer-reason"]').setValue('Změna údajů, doplníme opravou.')
    await confirm.get('[data-test="jmhz-bulk-defer-confirm"]').trigger('click')
    await flushPromises()
    expect(m.deferMany).toHaveBeenCalledWith({
      environment: 'production',
      preparationId: 55,
      officeId: 3,
      employmentIds: [11],
      reason: 'Změna údajů, doplníme opravou.',
    })

    await wrapper.get('[data-test="jmhz-settling-7:3-yes"]').trigger('click')
    await flushPromises()
    expect(m.freezeSubmission).toHaveBeenLastCalledWith(55, 99, 'production', 3, false, true)
    expect(m.sendTransport).toHaveBeenCalledWith(66, '12345678', 'production', expect.any(String))
  })

  /** Audit #2: blokace zmrazení nabídne odložit všechny dotčené jedním důvodem. */
  it('u blokace zmrazení nabídne odložit všechny dotčené', async () => {
    m.freezeSubmission.mockRejectedValueOnce({
      response: { data: { error: {
        code: 'conflict',
        message: 'Příprava JMHZ není úplná.',
        details: {
          blockers: [{ code: 'jmhz_identity_missing', entity_type: 'employment', entity_id: 11, attribute_ids: [], label: 'Eva', remediation_path: null, deferrable: true }],
          deferral: { preparation_id: 55, environment: 'production', office_id: 3, employment_ids: null, count: 14 },
        },
      } } },
    })
    m.deferMany.mockResolvedValue({ created: true, deferral_ids: [1], employment_ids: [11], people: [{ employee_id: 5 }], firm_blocker_count: 0, source_revision_id: 7 })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'production', previews: [preview], obligations: [obligation()] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    const bulk = wrapper.get('[data-test="jmhz-freeze-blockers"] [data-test="jmhz-bulk-defer"]')
    expect(bulk.get('[data-test="jmhz-bulk-defer-open"]').text()).toContain('"count":14')
    await bulk.get('[data-test="jmhz-bulk-defer-open"]').trigger('click')
    await bulk.get('[data-test="jmhz-bulk-defer-reason"]').setValue('Chybí adresy brigádníků.')
    await bulk.get('[data-test="jmhz-bulk-defer-confirm"]').trigger('click')
    await flushPromises()
    expect(m.deferMany).toHaveBeenCalledWith({
      environment: 'production',
      preparationId: 55,
      officeId: 3,
      employmentIds: null,
      reason: 'Chybí adresy brigádníků.',
    })
    expect(wrapper.find('[data-test="jmhz-bulk-defer-done"]').exists()).toBe(true)
  })

  it('v testovacím prostředí pošle do obálky testovací VS účtárny', async () => {
    m.employerSettings.mockResolvedValue({
      offices: [{ id: 3, test_social_security_variable_symbol: ' 1112223334 ' }],
    })
    m.sendTransport.mockResolvedValue({
      attempt: { id: 77, status: 'submitted' },
      acknowledgement: null,
      settled: false,
      report: null,
    })
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'test', previews: [preview], obligations: [] },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    // Účetní vidí před odesláním týž VS, jaký odejde v obálce — ne VS registrace.
    const confirm = wrapper.get('[data-test="jmhz-dispatch-confirm-7:3"]').text()
    expect(confirm).toContain('1112223334')
    expect(confirm).not.toContain('12345678')
    expect(wrapper.text()).not.toContain('12345678')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    expect(m.sendTransport).toHaveBeenCalledWith(66, '1112223334', 'test', expect.any(String))
  })

  it('znovu použije existující zmrazené podání ve stavu ready', async () => {
    m.enqueueIsds.mockResolvedValue({
      ...(await m.enqueueIsds()),
      transport: { automatic: false, channel: 'manual_upload', reason: 'gateway_unavailable' },
    })
    m.enqueueIsds.mockClear()
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'test',
        previews: [preview],
        obligations: [obligation({
          environment: 'test',
          latest_submission: {
            id: 88,
            status: 'ready',
            row_version: 1,
            submission_kind: 'regular',
            channel: 'vrep_apep',
            submitted_at: null,
            decided_at: null,
          },
        })],
      },
    })

    await wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-confirm-yes-7:3"]').trigger('click')
    await flushPromises()

    expect(m.freezePreparation).not.toHaveBeenCalled()
    expect(m.freezeSubmission).not.toHaveBeenCalled()
    expect(m.enqueueIsds).toHaveBeenCalledWith(88, 'test')
  })

  it('bez potvrzení neodešle nic a zrušení potvrzení odeslání zahodí', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [obligation()],
      },
    })

    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    await flushPromises()

    // Samotné kliknutí na „Odeslat" nesmí nic poslat: odeslání na VREP je
    // nevratné a dřív odletělo bez jediného dotazu, protože se podepisuje
    // certifikátem, ne heslem.
    expect(m.sendTransport).not.toHaveBeenCalled()
    expect(m.freezePreparation).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="jmhz-dispatch-confirm-7:3"]').exists()).toBe(true)

    await wrapper.get('[data-test="jmhz-dispatch-confirm-no-7:3"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-dispatch-confirm-7:3"]').exists()).toBe(false)
    expect(m.sendTransport).not.toHaveBeenCalled()
  })

  /**
   * B3: období, které nejde sestavit (číselníky 2027) nebo ještě neskončilo.
   * Dřív se tlačítka zhasínala jen podle evidované povinnosti, takže u období
   * bez povinnosti zůstala aktivní a klik skončil chybou.
   */
  it('podle závory přípravy zneaktivní obě tlačítka i bez evidované povinnosti', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'production',
        previews: [preview],
        obligations: [],
        preparationGate: {
          code: 'jmhz_spec_version_unavailable',
          message: 'Číselník obcí a států ještě nebyl vyhlášen. Termín podání je 22. 2. 2027.',
          available_from: null,
        },
      },
    })

    expect((wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.text()).toContain('22. 2. 2027')
    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    expect(wrapper.find('[data-test="jmhz-dispatch-confirm-7:3"]').exists()).toBe(false)
    expect(m.freezePreparation).not.toHaveBeenCalled()
  })

  it('už zmrazené podání závora přípravy neblokuje', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: {
        environment: 'test',
        previews: [preview],
        obligations: [obligation({ latest_submission: { id: 88, status: 'ready', row_version: 1 } as never })],
        preparationGate: { code: 'jmhz_period_not_finished', message: 'Období ještě neskončilo.', available_from: '2026-08-01' },
      },
    })

    expect((wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').element as HTMLButtonElement).disabled).toBe(false)
  })

  /**
   * M13: zpráva do datové schránky se potvrzením neodešle, jen se zmrazí
   * a zařadí do fronty. Potvrzení to musí říct, ne se ptát „Opravdu odeslat?".
   */
  it('u datové schránky se ptá na přípravu zprávy, u VREP na odeslání', async () => {
    const wrapper = mount(PayrollJmhzDispatchPanel, {
      props: { environment: 'production', previews: [preview], obligations: [obligation()] },
    })

    await wrapper.get('[data-test="jmhz-dispatch-isds-7:3"]').trigger('click')
    let confirm = wrapper.get('[data-test="jmhz-dispatch-confirm-7:3"]').text()
    expect(confirm).toContain('jmhz_dispatch_confirm_title_isds')
    expect(confirm).toContain('jmhz_dispatch_confirm_prepare')
    expect(confirm).not.toContain('jmhz_dispatch_confirm_irreversible')

    await wrapper.get('[data-test="jmhz-dispatch-confirm-no-7:3"]').trigger('click')
    await wrapper.get('[data-test="jmhz-dispatch-vrep-7:3"]').trigger('click')
    confirm = wrapper.get('[data-test="jmhz-dispatch-confirm-7:3"]').text()
    expect(confirm).toContain('jmhz_dispatch_confirm_send')
    expect(confirm).toContain('jmhz_dispatch_confirm_irreversible')
  })
})
