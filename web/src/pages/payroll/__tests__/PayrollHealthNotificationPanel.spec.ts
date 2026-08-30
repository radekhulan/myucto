import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  capability: vi.fn(),
  duties: vi.fn(),
  registerPeriod: vi.fn(),
  prepare: vi.fn(),
  enqueueIsds: vi.fn(),
  gatewayStart: vi.fn(),
  runs: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  prepareBulk: vi.fn(),
  downloadBulk: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartPayroll: m.gatewayStart },
}))

vi.mock('@/api/payrollHealthNotifications', () => ({
  payrollHealthNotificationApi: {
    capability: m.capability,
    duties: m.duties,
    registerPeriodObligations: m.registerPeriod,
    preparePaymentOverview: m.prepare,
    enqueuePaymentOverviewIsds: m.enqueueIsds,
    prepareBulkNotification: m.prepareBulk,
    downloadBulkNotification: m.downloadBulk,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    runs: m.runs,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
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
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters
        ? `${key} ${Object.values(parameters).join(' ')}`
        : key,
    locale: { value: 'cs' },
  }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollHealthNotificationPanel
  from '@/pages/payroll/PayrollHealthNotificationPanel.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'

/**
 * Vybere hodnotu v `SearchableSelect` přes jeho model. Rozbalovací seznam se
 * tu neklikáme — testuje se panel, ne komponenta výběru, která má vlastní spec.
 */
function pick(
  wrapper: ReturnType<typeof mount>,
  testId: string,
  value: unknown,
): void {
  const target = wrapper.findAllComponents(SearchableSelect)
    .find(component => component.attributes('data-test') === testId) as
      { vm: { $emit: (event: string, payload: unknown) => void } } | undefined
  if (!target) {
    throw new Error(`SearchableSelect [data-test="${testId}"] nenalezen.`)
  }
  target.vm.$emit('update:modelValue', value)
}

const CHANNEL_111 = {
  insurer_code: '111',
  insurer_name: 'VZP ČR',
  kind: 'own_portal',
  data_box_id: 'i48ae3q',
  portal_url: null,
  isds_attachment_format: 'none',
  isds_attachment_rules: [],
  accepts_shared_data_message: false,
  automated_dispatch_documented: false,
  undocumented_reason_code: 'zp_transport_envelope_undocumented',
  note: 'Formát přílohy PPZ pro ISDS není doložený.',
}

const CHANNEL_205 = {
  insurer_code: '205',
  insurer_name: 'ČPZP',
  kind: 'shared_portal',
  data_box_id: 'mk5ab8i',
  portal_url: 'https://portal.cpzp.cz',
  isds_attachment_format: 'xml',
  isds_attachment_rules: [{ from: '2026-01-01', to: null, format: 'xml' }],
  accepts_shared_data_message: true,
  automated_dispatch_documented: false,
  undocumented_reason_code: 'zp_portal_gateway_description_on_request',
  note: 'XML lze podat E-přepážkou nebo datovou schránkou.',
}

const CHANNEL_209 = {
  insurer_code: '209',
  insurer_name: 'ZP Škoda',
  kind: 'shared_portal',
  data_box_id: '5kpadkp',
  portal_url: 'https://portal.zpskoda.cz',
  isds_attachment_format: 'text_pdf',
  isds_attachment_rules: [{
    from: '2026-01-01',
    to: null,
    format: 'text_pdf',
  }],
  accepts_shared_data_message: false,
  automated_dispatch_documented: false,
  undocumented_reason_code: 'zp_transport_envelope_undocumented',
  note: 'Datová schránka přijímá vytěžitelné PDF.',
}

const RULE = {
  kind: 'employment_start',
  label: 'Nástup zaměstnance do zaměstnání',
  employer_reports: true,
  effective_from: '1997-04-01',
  effective_to: null,
  act: 'zákon č. 48/1997 Sb.',
  section: '§ 10 zákona č. 48/1997 Sb.',
  source: '§ 10 zákona č. 48/1997 Sb.',
  source_status: 'statute_verified',
  verified_on: '2026-08-15',
  note: '',
}

function dutyItem(overrides: Record<string, unknown> = {}) {
  return {
    id: 'payroll_health_notification:9:employment_start:2026-06-03',
    obligation_id: null,
    employment_id: 9,
    employee_id: 4,
    full_name: 'Syntetická osoba',
    kind: 'employment_start',
    label: RULE.label,
    insurer_code: '111',
    occurred_on: '2026-06-03',
    reported_by_employer: true,
    rule: RULE,
    deadline: {
      earliest_submission_on: '2026-06-03',
      due_on: '2026-06-11',
      calendar_basis: 'calendar_days',
      ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
      ruleset_hash: 'a'.repeat(64),
      source: '§ 10 zákona č. 48/1997 Sb.',
      source_status: 'statute_verified',
    },
    change_code: { documented: true, code: 'P', reason: null },
    channel: CHANNEL_111,
    dispatch: {
      supported: false,
      reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
      reason: 'Automatické odeslání pojišťovně 111 není doložené.',
      channel: CHANNEL_111,
    },
    ...overrides,
  }
}

function dutyPage(overrides: Record<string, unknown> = {}) {
  return {
    period: '2026-06',
    environment: 'production',
    items: [dutyItem()],
    total: 1,
    limit: 50,
    offset: 0,
    summary: {
      total: 1,
      reported_by_employer: 1,
      reported_by_insured: 0,
      code_documented: 1,
      code_undocumented: 0,
      overdue: 0,
    },
    unresolved_employments: [],
    ...overrides,
  }
}

function setup() {
  m.capability.mockResolvedValue({
    schema_reference: 'payroll-health-submission-capability.v1',
    shared_data_message_since: '2026-01-01',
    documents: {},
    channels: { 111: CHANNEL_111, 205: CHANNEL_205, 209: CHANNEL_209 },
    automated_dispatch: {
      supported: false,
      reason_code: 'zp_transport_envelope_undocumented',
    },
    isds_dispatch: {
      supported: true,
      requires_user_confirmation: true,
      automatic_inbox: false,
    },
    change_codes: {
      total: 25,
      narrowing_effective_from: '2026-01-01',
      mapping_from_duty_documented: [
        'employment_start',
        'employment_end',
        'maternity_leave_start',
        'parental_leave_start',
        'maternity_or_parental_leave_end',
      ],
    },
    duties: [RULE],
    verification_reference: 'private/Mzdy/21-ZP-PODANI-RESERSE.md',
  })
  m.duties.mockResolvedValue(dutyPage())
  m.registerPeriod.mockResolvedValue({
    items: [{
      duty_id: 'employment_start:9:2026-06-03',
      obligation_id: 71,
      created: true,
    }],
    total: 1,
    created: 1,
  })
  m.runs.mockResolvedValue([{
    id: 3,
    period_start: '2026-06-01',
    revision_id: 12,
    revision_no: 1,
    revision_status: 'approved',
  }])
  m.gatewayStart.mockResolvedValue({
    session_id: 1,
    app_token: 'token',
    redirect_url: 'https://www.datovka.gov.cz/as/login',
    login_guidance: 'Přihlaste se metodou, kterou nabízí ISDS.',
    login_policy_documented: true,
    expires_at: '2026-08-25 15:00:00',
    resumed: false,
  })
}

describe('PayrollHealthNotificationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setup()
  })

  it('vypíše povinnost i s lhůtou a doloženým kódem změny', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const rows = wrapper.findAll('[data-test="health-notification-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Syntetická osoba')
    expect(rows[0].text()).toContain('P')
  })

  /**
   * Jádro zadání: omezení musí být vidět DŘÍV, než na ně uživatel narazí.
   * Panel s omezeními se proto vykresluje vždy, ne až po chybě.
   */
  it('řekne, co modul neumí, ještě než se na cokoli klikne', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const limits = wrapper.find('[data-test="health-notifications-limits"]')
    expect(limits.exists()).toBe(true)
    expect(limits.text()).toContain('payroll.health_notifications.limits.no_transport')
    expect(limits.text()).toContain('payroll.health_notifications.limits.manual_delivery')
    // Tři nedoložené druhy povinnosti se vyjmenují, ne shrnou do „některé".
    expect(limits.text()).toContain('payroll.health_notifications.kind.insurer_change')
    expect(limits.text()).toContain('payroll.health_notifications.kind.employee_data_change')
    expect(limits.text()).toContain('payroll.health_notifications.kind.state_category_other')
  })

  it('u nedoloženého kódu ukáže konkrétní důvod, ne obecnou hlášku', async () => {
    m.duties.mockResolvedValue(dutyPage({
      items: [dutyItem({
        kind: 'insurer_change',
        change_code: {
          documented: false,
          code: null,
          reason: 'Přestup se hlásí každé pojišťovně jinak, bez směru kód neplyne.',
        },
      })],
    }))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const badge = wrapper.find('[data-test="health-notification-code-undocumented"]')
    expect(badge.exists()).toBe(true)
    expect(badge.attributes('title')).toContain('bez směru kód neplyne')
  })

  /**
   * Selhání načtení nesmí vypadat jako prázdná agenda — u osmidenní lhůty je
   * to nejdražší možná lež.
   */
  it('na selhání načtení ukáže failed stav, ne prázdný', async () => {
    m.duties.mockRejectedValue(new Error('boom'))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="health-notifications-failed"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="health-notifications-empty"]').exists()).toBe(false)
  })

  it('při selhání dalšího načtení nevynuluje už zobrazená data', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    expect(wrapper.findAll('[data-test="health-notification-row"]')).toHaveLength(1)

    m.duties.mockRejectedValue(new Error('boom'))
    await wrapper.find('[data-test="health-notifications-period"]').setValue('2026-07')
    await flushPromises()

    expect(wrapper.findAll('[data-test="health-notification-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="health-notifications-stale"]').exists()).toBe(true)
  })

  it('filtr posílá na server a stránkuje se tam taky', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    await wrapper.find('[data-test="health-notifications-undocumented"]').setValue(true)
    await flushPromises()

    const lastCall = m.duties.mock.calls.at(-1)
    expect(lastCall?.[0]).toBe(localPayrollPeriod())
    expect(lastCall?.[1]).toMatchObject({
      undocumented_code_only: true,
      limit: 50,
      offset: 0,
    })
  })

  /**
   * Dlaždice počítají celý měsíc, tabulka pod nimi jen filtrovaný výběr.
   * Bez popisku by to vypadalo, že souhrn filtr ignoruje omylem.
   */
  it('při zapnutém filtru řekne, že dlaždice počítají celý měsíc', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    expect(wrapper.find('[data-test="health-notifications-summary-scope"]').exists())
      .toBe(false)

    await wrapper.find('[data-test="health-notifications-undocumented"]').setValue(true)
    await flushPromises()

    expect(wrapper.find('[data-test="health-notifications-summary-scope"]').exists())
      .toBe(true)
  })

  it('vztah bez pojišťovny pojmenuje místo vypuštění', async () => {
    m.duties.mockResolvedValue(dutyPage({
      unresolved_employments: [{
        employment_id: 11,
        full_name: 'Osoba bez pojišťovny',
        reason_code: 'zp_insurer_code_missing',
        reason: 'Zaměstnanec nemá evidovanou zdravotní pojišťovnu.',
      }],
    }))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const box = wrapper.find('[data-test="health-notifications-unresolved"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('Osoba bez pojišťovny')
  })

  /**
   * Soubor vzniká i u zablokovaného podání — a právě tam ho účetní potřebuje
   * vidět. Stahování se proto za `schema_validated` neschovává.
   */
  it('nabídne stažení XML i u blokující výhrady a ukáže její důvod', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 55,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 9,
      status: 'draft',
      row_version: 3,
      insurer_code: '111',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: false,
      dispatch: {
        supported: false,
        reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
        reason: 'Odeslání pojišťovně 111 není doložené.',
        channel: CHANNEL_111,
      },
    })
    m.submissionDetail.mockResolvedValue({
      submission: { id: 55 },
      parts: [],
      artifacts: [{ id: 9, mime_type: 'application/xml', byte_size: 512 }],
      issues: [],
      receipts: [],
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    // Revizi a pojišťovnu vybere uživatel; SearchableSelect je v testu
    // adresovaný přes svůj model, ne přes rozbalovací seznam.
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    expect(prepareButton).toBeDefined()
    await prepareButton!.trigger('click')
    await flushPromises()

    expect(m.prepare).toHaveBeenCalledWith(12, '111')

    const result = wrapper.find('[data-test="health-prepare-result"]')
    expect(result.exists()).toBe(true)
    expect(result.text()).toContain('payroll.health_notifications.prepare.blocked')
    // Důvod nedostupnosti odeslání se ukazuje i u výsledku, ne jen nahoře.
    expect(result.text()).toContain('Odeslání pojišťovně 111 není doložené.')

    // Stažení JE k dispozici, přestože podání zůstalo v konceptu.
    const download = wrapper.find('[data-test="health-prepare-download"]')
    expect(download.exists()).toBe(true)
    await download.trigger('click')
    await flushPromises()

    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      55,
      expect.objectContaining({ id: 9 }),
    )
  })

  it('u platné věty ohlásí platnost a lhůtu', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 56,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 10,
      status: 'ready',
      row_version: 4,
      insurer_code: '111',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'c'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
        reason: 'Odeslání pojišťovně 111 není doložené.',
        channel: CHANNEL_111,
      },
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()

    const result = wrapper.find('[data-test="health-prepare-result"]')
    expect(result.text()).toContain('payroll.health_notifications.prepare.valid')
    // Platnost NEZNAMENÁ, že se odešle — přiznání zůstává i u zelené věty.
    expect(result.text()).toContain('Odeslání pojišťovně 111 není doložené.')
  })

  it('HOZ pouze výslovně synchronizuje do inboxu a netvrdí sestavení ani odeslání', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    expect(m.registerPeriod).not.toHaveBeenCalled()
    m.duties.mockResolvedValue(dutyPage({
      items: [dutyItem({ obligation_id: 71 })],
    }))
    const syncButton = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.health_notifications.hoz_sync.action'),
    )
    expect(syncButton).toBeDefined()
    await syncButton!.trigger('click')
    await flushPromises()

    expect(m.registerPeriod).toHaveBeenCalledTimes(1)
    expect(m.registerPeriod).toHaveBeenCalledWith(localPayrollPeriod())
    const result = wrapper.get('[data-test="health-hoz-sync-result"]')
    expect(result.text()).toContain('payroll.health_notifications.hoz_sync.done')
    const row = wrapper.get('[data-test="health-notification-row"]')
    expect(row.text()).toContain(
      'payroll.health_notifications.state.obligation_registered',
    )
    expect(wrapper.get('[data-test="health-hoz-sync"]').text())
      .not.toContain('payroll.health_notifications.prepare.isds_ready')
  })

  it('po obnovení stránky načte evidovaný stav HOZ ze serveru', async () => {
    m.duties.mockResolvedValue(dutyPage({
      items: [dutyItem({ obligation_id: 71 })],
    }))

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    expect(m.registerPeriod).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="health-notification-row"]').text()).toContain(
      'payroll.health_notifications.state.obligation_registered',
    )
  })

  it('platnou větu připraví do ISDS, ale netvrdí, že byla odeslána', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 57,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 11,
      status: 'ready',
      row_version: 4,
      insurer_code: '205',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_portal_gateway_description_on_request',
        reason: 'Automatické portálové API není doložené.',
        channel: CHANNEL_205,
      },
    })
  m.enqueueIsds.mockResolvedValue({
      outbox_id: 91,
      created: true,
      recipient: { box_id: 'mk5ab8i', name: 'ČPZP (205)' },
      subject: 'Přehled pojistného 2026-06 — zdravotní pojišťovna 205',
      attachment: {
        filename: 'mzdove-podani-57-11.xml',
        mime: 'application/xml',
        sha256: 'd'.repeat(64),
        bytes: 512,
        format: 'xml',
      },
      transport: {
        automatic: false,
        channel: 'manual_upload',
        reason: 'isds_gateway_unavailable',
      },
      outbox_url: '/admin/databox?tab=outbox',
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '205')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="health-prepare-result"]').text())
      .toContain('payroll.submissions.overview.status.ready')

    const isdsButton = wrapper.get('[data-test="health-prepare-isds"]')
    expect(isdsButton.attributes('disabled')).toBeUndefined()
    await isdsButton.trigger('click')
    await flushPromises()

    expect(m.enqueueIsds).toHaveBeenCalledWith(57, '205')
    const result = wrapper.get('[data-test="health-prepare-isds-result"]')
    expect(result.text()).toContain('payroll.health_notifications.prepare.isds_ready')
    expect(result.text()).toContain('mk5ab8i')
    expect(result.text()).not.toContain('odesláno')
    expect(wrapper.get('[data-test="health-prepare-isds"]').attributes('disabled'))
      .toBeDefined()
    await wrapper.get('[data-test="health-prepare-isds"]').trigger('click')
    expect(m.enqueueIsds).toHaveBeenCalledTimes(1)
  })

  it('po zařazení PPZ zahájí dostupnou bránu a přesměruje až po potvrzení', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { assign },
    })
    m.prepare.mockResolvedValue({
      submission_id: 57,
      obligation_id: 7,
      artifact_id: 11,
      status: 'ready',
      row_version: 4,
      insurer_code: '205',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_portal_gateway_description_on_request',
        reason: 'Automatické portálové API není doložené.',
        channel: CHANNEL_205,
      },
    })
    m.enqueueIsds.mockResolvedValue({
      outbox_id: 91,
      created: true,
      recipient: { box_id: 'mk5ab8i', name: 'ČPZP (205)' },
      subject: 'PPPZ 2026-06 — zdravotní pojišťovna 205',
      attachment: {
        filename: 'mzdove-podani-57-11.xml',
        mime: 'application/xml',
        sha256: 'd'.repeat(64),
        bytes: 512,
        format: 'xml',
      },
      transport: { automatic: true, channel: 'gateway', reason: null },
      outbox_url: '/admin/databox?tab=outbox',
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '205')
    await flushPromises()
    const prepareButton = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.health_notifications.prepare.action'),
    )
    await prepareButton!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="health-prepare-isds"]').trigger('click')
    await flushPromises()

    expect(m.gatewayStart).toHaveBeenCalledWith(91)
    expect(assign).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="health-prepare-isds-gateway"]').text())
      .toContain('Přihlaste se metodou, kterou nabízí ISDS.')
    await wrapper.get('[data-test="health-prepare-isds-continue"]').trigger('click')
    expect(assign).toHaveBeenCalledWith('https://www.datovka.gov.cz/as/login')
  })

  it('pro ZP Škoda stáhne a připraví do ISDS vytěžitelné PDF', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 58,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 12,
      pdf_artifact_id: 13,
      status: 'ready',
      row_version: 5,
      insurer_code: '209',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'e'.repeat(64),
      pdf_artifact_sha256: 'f'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_transport_envelope_undocumented',
        reason: 'Automatické portálové API není doložené.',
        channel: CHANNEL_209,
      },
    })
    m.submissionDetail.mockResolvedValue({
      submission: { id: 58 },
      parts: [],
      artifacts: [
        { id: 12, mime_type: 'application/xml', byte_size: 512 },
        { id: 13, mime_type: 'application/pdf', byte_size: 1024 },
      ],
      issues: [],
      receipts: [],
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '209')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="health-prepare-download"]').trigger('click')
    await flushPromises()

    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      58,
      expect.objectContaining({ id: 13, mime_type: 'application/pdf' }),
    )
    expect(wrapper.get('[data-test="health-prepare-isds"]')
      .attributes('disabled')).toBeUndefined()
  })

  it('konkrétní důvod selhání sestavení se propíše na obrazovku', async () => {
    m.prepare.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Součet pojistného obsahuje haléře, ale datová věta má celé koruny.',
          },
        },
      },
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()

    const box = wrapper.find('[data-test="health-prepare-error"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('haléře')
    expect(box.text()).not.toContain('payroll.health_notifications.prepare.failed')
  })

  /**
   * HOZ se sestavuje za období (z filtru nahoře) a pojišťovnu — na rozdíl od
   * PPZ nemá revizi. Stažení jde přímo přes `downloadBulkNotification`, ne
   * přes `submissionDetail` + `download-grant` jako u PPZ.
   */
  it('sestaví HOZ za období a pojišťovnu a nabídne stažení i u blokující výhrady', async () => {
    m.prepareBulk.mockResolvedValue({
      submission_id: 61,
      obligation_id: 9,
      part_id: 3,
      artifact_id: 14,
      status: 'draft',
      row_version: 2,
      insurer_code: '111',
      period: localPayrollPeriod(),
      agenda_code: 'HOZ_2026',
      artifact_sha256: 'a1'.repeat(32),
      changes_count: 3,
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-03',
        due_on: '2026-06-11',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 10 zákona č. 48/1997 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: false,
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-bulk-insurer', '111')
    await flushPromises()

    await wrapper.get('[data-test="health-prepare-bulk-action"]').trigger('click')
    await flushPromises()

    expect(m.prepareBulk).toHaveBeenCalledWith(localPayrollPeriod(), '111')

    const result = wrapper.find('[data-test="health-prepare-bulk-result"]')
    expect(result.exists()).toBe(true)
    expect(result.text()).toContain('payroll.health_notifications.prepare_bulk.blocked')

    const download = wrapper.get('[data-test="health-prepare-bulk-download"]')
    await download.trigger('click')
    await flushPromises()

    expect(m.downloadBulk).toHaveBeenCalledWith(localPayrollPeriod(), '111')
  })

  it('u platného HOZ ohlásí platnost a počet vět', async () => {
    m.prepareBulk.mockResolvedValue({
      submission_id: 62,
      obligation_id: 9,
      part_id: 3,
      artifact_id: 15,
      status: 'ready',
      row_version: 3,
      insurer_code: '205',
      period: localPayrollPeriod(),
      agenda_code: 'HOZ_2026',
      artifact_sha256: 'b2'.repeat(32),
      changes_count: 5,
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-03',
        due_on: '2026-06-11',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 10 zákona č. 48/1997 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-bulk-insurer', '205')
    await flushPromises()
    await wrapper.get('[data-test="health-prepare-bulk-action"]').trigger('click')
    await flushPromises()

    const result = wrapper.get('[data-test="health-prepare-bulk-result"]')
    expect(result.text()).toContain('payroll.health_notifications.prepare_bulk.valid')
    expect(result.text()).toContain('5')
  })

  it('konkrétní důvod selhání sestavení HOZ se propíše na obrazovku', async () => {
    m.prepareBulk.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Zaměstnanec (id 4) nemá evidované rodné číslo ani EČP.',
          },
        },
      },
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-bulk-insurer', '111')
    await flushPromises()
    await wrapper.get('[data-test="health-prepare-bulk-action"]').trigger('click')
    await flushPromises()

    const box = wrapper.find('[data-test="health-prepare-bulk-error"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('rodné číslo ani EČP')
    expect(box.text()).not.toContain('payroll.health_notifications.prepare_bulk.failed')
  })
})
