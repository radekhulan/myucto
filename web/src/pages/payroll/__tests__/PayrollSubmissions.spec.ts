import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  employerSettings: vi.fn(),
  profile: vi.fn(),
  snapshots: vi.fn(),
  prepare: vi.fn(),
  download: vi.fn(),
  overview: vi.fn(),
  monthlyChecklist: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  runs: vi.fn(),
  jmhzPreview: vi.fn(),
  jmhzOffices: vi.fn(),
  jmhzOrdinaryEvidence: vi.fn(),
  confirmJmhzOrdinaryEvidence: vi.fn(),
  downloadJmhzPreview: vi.fn(),
  healthOverviews: vi.fn(),
  downloadHealthOverview: vi.fn(),
  prepareHealthOverview: vi.fn(),
  submissionInbox: vi.fn(),
  acknowledgeInboxItem: vi.fn(),
  snoozeInboxItem: vi.fn(),
  signingProfile: vi.fn(),
  jmhzTransportHistory: vi.fn(),
  pollJmhzTransportAttempt: vi.fn(),
  closeJmhzTransportAttempt: vi.fn(),
  jmhzExternalSubmissions: vi.fn(() => Promise.resolve({ environment: 'production', items: [] })),
  routeParams: {} as Record<string, string>,
  routeQuery: {} as Record<string, string>,
  testAllowed: true,
  routerReplace: vi.fn(),
}))

// Aktivní záložka je součást adresy (`/payroll/submissions/:tab`), takže
// stránka potřebuje router. Originál se rozprostře, ať zůstanou i ostatní
// exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ name: 'payroll-submissions', params: m.routeParams, query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerSettings: m.employerSettings,
    regzelProfile: m.profile,
    regzelSnapshots: m.snapshots,
    prepareRegzel: m.prepare,
    downloadRegzelSnapshot: m.download,
    submissionOverview: m.overview,
    monthlyChecklist: m.monthlyChecklist,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
    runs: m.runs,
    jmhzPvpojPreview: m.jmhzPreview,
    jmhzPvpojOffices: m.jmhzOffices,
    jmhzOrdinaryEvidence: m.jmhzOrdinaryEvidence,
    confirmJmhzOrdinaryEvidence: m.confirmJmhzOrdinaryEvidence,
    downloadJmhzPvpojPreview: m.downloadJmhzPreview,
    healthPaymentOverviews: m.healthOverviews,
    downloadHealthPaymentOverview: m.downloadHealthOverview,
    submissionInbox: m.submissionInbox,
    acknowledgeSubmissionInboxItem: m.acknowledgeInboxItem,
    snoozeSubmissionInboxItem: m.snoozeInboxItem,
    signingProfile: m.signingProfile,
    jmhzTransportHistory: m.jmhzTransportHistory,
    pollJmhzTransportAttempt: m.pollJmhzTransportAttempt,
    closeJmhzTransportAttempt: m.closeJmhzTransportAttempt,
    jmhzExternalSubmissions: m.jmhzExternalSubmissions,
  },
}))

// Panel oznamovací povinnosti si data obstarává sám; tady jde jen o to,
// že se na něj dá proklikat — vlastní chování má samostatný spec.
vi.mock('@/api/payrollHealthNotifications', () => ({
  payrollHealthNotificationApi: {
    capability: () => Promise.resolve({
      channels: {},
      change_codes: {
        total: 25,
        narrowing_effective_from: '2026-01-01',
        mapping_from_duty_documented: [],
      },
      documents: {},
      duties: [],
      automated_dispatch: { supported: false, reason_code: 'x' },
      schema_reference: 'payroll-health-submission-capability.v1',
      shared_data_message_since: '2026-01-01',
      verification_reference: 'private/Mzdy/21-ZP-PODANI-RESERSE.md',
    }),
    duties: () => Promise.resolve({
      period: '2026-08',
      environment: 'production',
      items: [],
      total: 0,
      limit: 50,
      offset: 0,
      summary: {
        total: 0,
        reported_by_employer: 0,
        reported_by_insured: 0,
        code_documented: 0,
        code_undocumented: 0,
        overdue: 0,
      },
      unresolved_employments: [],
    }),
    preparePaymentOverview: m.prepareHealthOverview,
  },
}))

vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
    canRead: (permission: string) => permission === 'payroll.submissions' || permission === 'reports',
    get submissionTestEnvironmentAllowed() { return m.testAllowed },
  }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters
        ? `${key} ${Object.values(parameters).join(' ')}`
        : key,
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

import PayrollSubmissions from '@/pages/payroll/PayrollSubmissions.vue'

function setup() {
  m.employerSettings.mockResolvedValue({
    offices: [{
      id: 42,
      code: 'MAIN',
      name: 'Hlavní účtárna',
      is_active: true,
    }],
  })
  m.profile.mockResolvedValue({
    suggested_tax_office_workplace_code: null,
    profile: {
      supplier_id: 1,
      social_enterprise: false,
      employment_agency: false,
      protected_labor_market: false,
      tax_office_code: '3000',
      tax_office_workplace_code: '3001',
      payer_reference_number: null,
      is_complete: true,
      evidence_confirmed_at: '2026-08-04 12:00:00',
      row_version: 1,
      updated_at: '2026-08-04 12:00:00',
    },
  })
  m.snapshots.mockResolvedValue({
    environment: 'production',
    items: [],
    total: 0,
    limit: 25,
    offset: 0,
  })
  // Měsíční přehled je výchozí záložka, takže se načítá při každém mountu.
  m.monthlyChecklist.mockResolvedValue({
    environment: 'production',
    period: '2026-08',
    window: { from: '2026-08-01', to: '2026-08-31' },
    summary: { total: 0, send: 0, generate: 0, manual: 0, done: 0 },
    items: [],
  })
  m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
  m.submissionInbox.mockResolvedValue({
    environment: 'production',
    status: 'unresolved',
    summary: { total: 0, open: 0, acknowledged: 0, snoozed: 0 },
    items: [],
    total: 0,
    limit: 25,
    offset: 0,
  })
  m.overview.mockResolvedValue({
    environment: 'production',
    period: '2026-08',
    agenda_group: 'jmhz',
    summary: {
      total: 1,
      open: 1,
      prepared: 0,
      submitted: 0,
      fulfilled: 0,
      overdue: 0,
      manual_review: 0,
      other: 0,
    },
    deadline_summary: {
      not_open: 1,
      open: 0,
      due_soon: 0,
      due_today: 0,
      overdue: 0,
      awaiting_result: 0,
      fulfilled: 0,
      action_required: 0,
      cancelled: 0,
    },
    items: [{
      id: 7,
      environment: 'production',
      agenda_code: 'JMHZ25',
      agenda_group: 'jmhz',
      subject_type: 'office',
      subject_reference: 'office:synthetic',
      period_start: '2026-08-01',
      period_end: '2026-08-31',
      obligation_kind: 'regular',
      preferred_channel: 'manual_upload',
      status: 'open',
      row_version: 1,
      earliest_submission_on: '2026-09-01',
      due_on: '2026-09-20',
      calendar_basis: 'calendar_days',
      deadline: {
        phase: 'not_open',
        days_to_due: 36,
        is_action_required: false,
        is_overdue: false,
      },
      latest_submission: null,
    }],
    total: 1,
    limit: 50,
    offset: 0,
  })
  m.runs.mockResolvedValue([{
    id: 8,
    status: 'approved',
    period_start: '2026-08-01',
    revision_id: 18,
    revision_no: 1,
    revision_status: 'approved',
  }, {
    id: 9,
    status: 'posted',
    period_start: '2026-08-01',
    revision_id: 19,
    revision_no: 2,
    revision_status: 'approved',
  }])
  m.healthOverviews.mockImplementation(async (revisionId: number) => ({
    items: [{
      schema_reference: 'payroll-health-payment-overview.v1',
      document_kind: 'internal_health_payment_overview',
      official_submission: {
        supported: false,
        reason_code: 'health_insurance_official_format_unavailable',
      },
      supplier_id: 1,
      run_id: revisionId === 18 ? 8 : 9,
      revision_id: revisionId,
      revision_no: 1,
      period: '2026-08',
      currency_code: 'CZK',
      insurer: { code: '111' },
      source: {
        statutory_result_id: 90,
        statutory_result_hash: 'a'.repeat(64),
        ruleset_id: 'cz-health-2026',
        ruleset_hash: 'b'.repeat(64),
      },
      totals: {
        person_count: 2,
        assessment_base_minor_units: 10_000_000,
        employee_contribution_minor_units: 450_000,
        employer_contribution_minor_units: 900_000,
        total_contribution_minor_units: 1_350_000,
      },
      people: [],
      sha256: 'c'.repeat(64),
      filename: `zp-prehled-2026-08-111-revize-${revisionId}.json`,
    }],
    electronic_submission: {
      direct_portal: {
        supported: false,
        reason_code: 'health_insurance_portal_transport_undocumented',
      },
      isds: {
        supported: true,
        requires_ready: true,
        requires_production_gate: true,
        requires_user_confirmation: true,
      },
    },
  }))
  m.prepareHealthOverview.mockResolvedValue({
    submission_id: 31,
    artifact_id: 51,
    pdf_artifact_id: 52,
    insurer_code: '111',
    status: 'ready',
    schema_validated: true,
    dispatch: { channel: { isds_attachment_format: 'text_pdf' } },
  })
  // Přehled se podává za REGISTRACI u OSSZ, takže panel se nejdřív ptá,
  // za které účtárny se z revize podává.
  m.jmhzOffices.mockImplementation(async () => [{
    office_id: 4,
    code: 'UC4',
    name: 'Mzdová účtárna 4',
    social_security_variable_symbol: '1234567890',
    submittable: true,
  }])
  m.jmhzPreview.mockImplementation(async (
    revisionId: number,
    officeId?: number | null,
  ) => ({
    schema_reference: 'payroll-jmhz-pvpoj-preview.v1',
    document_kind: 'internal_jmhz_pvpoj_preview',
    workflow_status: 'preview_only',
    official_submission: {
      supported: false,
      reason_code: 'pvpoj_only_identity_snapshot_incomplete',
    },
    xsd: {
      bundle_version: '1.4.3.4',
      schema_version: '1.4.3',
      entry_point: 'jmhz-1.4.3.6/PVPOJ.xsd',
      namespace: 'http://schemas.cssz.cz/JMHZ/PVPOJ/1.0',
    },
    supplier_id: 1,
    run_id: revisionId === 18 ? 8 : 9,
    revision_id: revisionId,
    revision_no: 1,
    period: '2026-08',
    currency_code: 'CZK',
    office: {
      office_id: officeId ?? 4,
      code: `UC${officeId ?? 4}`,
      name: `Mzdová účtárna ${officeId ?? 4}`,
      variable_symbol: officeId === 5 ? '9990001234' : '1234567890',
    },
    office_allocation: {
      method: 'largest_remainder_by_capped_assessment_base',
      root_result_is_single_source_of_truth: true,
      offices: [],
    },
    source: {
      revision_input_hash: 'a'.repeat(64),
      statutory_result_id: 90,
      statutory_result_hash: 'b'.repeat(64),
      ruleset_id: 'cz-social-2026',
      ruleset_hash: 'c'.repeat(64),
    },
    pvpoj: {
      pojistne: {
        zakladZamestnavateleA: 100_000,
        pojistneZamestnavateleA: 24_800,
        pojistneZamestnavateleCelkem: 24_800,
        pojistneZamestnance: 7_100,
        pojistneCelkem: 31_900,
      },
      pojistneUhrada: 31_900,
    },
    reconciliation: [{
      employee_reference: 'employee:1',
      relationship_references: ['employment:1'],
      capped_assessment_base_minor_units: 10_000_000,
      employee_contribution_before_discount_minor_units: 710_000,
      employee_discount_minor_units: 0,
      employee_contribution_minor_units: 710_000,
    }],
    sha256: 'e'.repeat(64),
    filename: `jmhz-pvpoj-preview-2026-08-revize-${revisionId}.json`,
  }))
  m.jmhzOrdinaryEvidence.mockResolvedValue(null)
  m.prepare.mockResolvedValue({
    id: 9,
    environment: 'production',
    office_id: 42,
    document_type: 'REGZELDOPL25',
    interaction_code: 'supplemental_information',
    mapping_version: 'regzeldopl25-map-1',
    xsd_version: '1.2',
    source_snapshot_hash: 'a'.repeat(64),
    xml_sha256: 'b'.repeat(64),
    xml_byte_size: 123,
    request_fingerprint: 'c'.repeat(64),
    created: true,
  })
  m.submissionDetail.mockResolvedValue({
    submission: {
      id: 31,
      environment: 'production',
      obligation_id: 7,
      agenda_code: 'JMHZ25',
      subject_type: 'office',
      subject_reference: 'office:synthetic',
      period_start: '2026-08-01',
      period_end: '2026-08-31',
      submission_kind: 'regular',
      channel: 'manual_upload',
      status: 'validated',
      row_version: 4,
      source_revision_id: 18,
      corrects_submission_id: null,
      correlation_reference: null,
      submitted_at: null,
      decided_at: null,
      created_at: '2026-09-01 08:00:00',
      updated_at: '2026-09-01 08:05:00',
    },
    parts: [{
      id: 41,
      part_reference: 'jmhz-summary',
      agenda_code: 'JMHZ25',
      subject_reference: 'office:synthetic',
      status: 'validated',
      source_entity_type: 'run_revision',
      source_entity_reference: 'revision:18',
      row_version: 1,
      created_at: '2026-09-01 08:00:00',
      updated_at: '2026-09-01 08:00:00',
    }],
    artifacts: [{
      id: 51,
      part_id: 41,
      artifact_kind: 'outbound_xml',
      direction: 'outbound',
      mime_type: 'application/xml',
      byte_size: 2048,
      xsd_version: '1.4.3.4',
      catalog_version: null,
      channel: 'manual_upload',
      created_at: '2026-09-01 08:01:00',
    }, {
      id: 52,
      part_id: 41,
      artifact_kind: 'outbound_pdf',
      direction: 'outbound',
      mime_type: 'application/pdf',
      byte_size: 4096,
      xsd_version: null,
      catalog_version: null,
      channel: 'manual_upload',
      created_at: '2026-09-01 08:01:00',
    }],
    receipts: [],
    issues: [{
      id: 61,
      part_id: 41,
      severity: 'warning',
      validation_stage: 'catalog',
      issue_code: 'MANUAL_REVIEW',
      entity_type: null,
      entity_reference: null,
      is_resolved: false,
      row_version: 1,
      resolved_at: null,
      created_at: '2026-09-01 08:02:00',
      updated_at: '2026-09-01 08:02:00',
    }],
  })
}

/*
 * Záložky se vybírají podle popisku, ne podle pořadí. Indexy tady dřív byly a
 * rozsypala je každá nová agenda — přidání evidenčního listu shodilo čtyři
 * testy, které se samotného evidenčního listu vůbec netýkaly.
 */
async function clickTab(
  wrapper: ReturnType<typeof mount>,
  key: string,
): Promise<void> {
  const tab = wrapper.findAll('[role="tab"]')
    .find(candidate => candidate.text().includes(`payroll.submissions.tabs.${key}`))
  if (!tab) {
    throw new Error(`Záložka ${key} na stránce podání chybí.`)
  }
  await tab.trigger('click')
  await flushPromises()
}

describe('PayrollSubmissions', () => {
  /* Q8-49: přepínač se po každém načtení vracel na ostrý provoz. */
  it('pamatuje si zvolené testovací prostředí přes nové načtení stránky', async () => {
    sessionStorage.setItem('myinvoice.payroll.submissionEnvironment', 'test')
    const wrapper = mount(PayrollSubmissions, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(m.monthlyChecklist.mock.calls[0]?.[0]).toBe('test')
    expect(sessionStorage.getItem('myinvoice.payroll.submissionEnvironment')).toBe('test')
    wrapper.unmount()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    // Volbu prostředí si stránka pamatuje v sessionStorage — test ji nesmí
    // předat dalšímu testu.
    sessionStorage.clear()
    m.routeParams = {}
    m.routeQuery = {}
    m.testAllowed = true
    setup()
  })

  /*
   * Produkce 6.28.0: mimo vývojovou instalaci byla v hlavičce volba „Prostředí
   * evidence" se štítkem Ostrý provoz a uložený `test` poslal první dotaz do
   * testu, který server odmítne. Jediné prostředí není volba.
   */
  it('mimo vývoj nevykreslí volbu prostředí a uložený test nahradí produkcí', async () => {
    m.testAllowed = false
    sessionStorage.setItem('myinvoice.payroll.submissionEnvironment', 'test')
    const wrapper = mount(PayrollSubmissions, { global: { stubs: { RouterLink: true } } })
    await flushPromises()

    expect(m.monthlyChecklist.mock.calls.length).toBeGreaterThan(0)
    for (const call of m.monthlyChecklist.mock.calls) expect(call[0]).toBe('production')
    expect(sessionStorage.getItem('myinvoice.payroll.submissionEnvironment')).toBeNull()
    expect(wrapper.find('[data-test="submissions-environment"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="monthly-checklist-environment"]').exists()).toBe(false)
    expect(wrapper.find('[data-environment]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.submissions.overview.environment')
    expect(wrapper.text()).not.toContain('common.environmentSwitch')
    expect(wrapper.text()).toContain('payroll.submissions.subtitle_production_only')
    wrapper.unmount()
  })

  /*
   * Měsíční rutina (JMHZ, pojišťovny, K odeslání) byla schovaná pod „Další"
   * a účetní ji nenašla. Viditelné jsou záložky každého měsíce, mimořádná
   * podání mají vlastní nabídku a „Další" drží jen správu.
   */
  it('ukáže měsíční rutinu jako hlavní záložky, mimořádná podání a správu v nabídkách', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    const nav = wrapper.get('nav[role="tablist"]')
    expect(nav.classes()).toContain('flex-wrap')
    const primary = nav.findAll(':scope > [role="tab"]').map(tab => tab.text())
    expect(primary).toEqual([
      'payroll.submissions.tabs.monthly',
      'payroll.submissions.tabs.jmhz',
      'payroll.submissions.tabs.health',
      'payroll.submissions.tabs.tax_statements',
      'payroll.submissions.tabs.queue',
      'payroll.submissions.tabs.transport',
    ])
    const groups = nav.findAll(':scope > div > button[aria-haspopup]').map(button => button.text())
    expect(groups[0]).toContain('payroll.submissions.tabs_extraordinary')
    expect(groups[1]).toContain('payroll.submissions.tabs_more')
    const extraordinary = wrapper.get('[data-test="submissions-extraordinary-menu"]')
      .findAll('[role="tab"]').map(tab => tab.text())
    expect(extraordinary).toEqual([
      'payroll.submissions.tabs.statutory',
      'payroll.submissions.tabs.regzel',
      'payroll.submissions.tabs.registration_a1',
      'payroll.submissions.tabs.registration_completion',
      'payroll.submissions.tabs.discount_intents',
      'payroll.submissions.tabs.sickness',
      'payroll.submissions.tabs.eldp',
      'payroll.submissions.tabs.other',
    ])
    const more = wrapper.get('[data-test="submissions-more-menu"]')
      .findAll('[role="tab"]').map(tab => tab.text())
    expect(more).toEqual([
      'payroll.submissions.tabs.inbox',
      'payroll.submissions.tabs.certificate',
    ])
  })

  /* Jedno období pro celou stránku: návrh serveru, v adrese, sdílené záložkami. */
  it('drží jedno období stránky — navržené serverem a zapsané do adresy', async () => {
    m.monthlyChecklist.mockResolvedValue({
      environment: 'production',
      period: '2026-05',
      suggested_period: '2026-05',
      window: { from: '2026-05-01', to: '2026-05-31' },
      summary: { total: 0, send: 0, generate: 0, manual: 0, await: 0, done: 0 },
      items: [],
    })
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    expect((wrapper.get('[data-test="submissions-period"]').element as HTMLInputElement).value).toBe('2026-05')
    expect(m.routerReplace).toHaveBeenCalledWith({ query: { period: '2026-05', submission: undefined } })
    await clickTab(wrapper, 'health')
    expect(m.monthlyChecklist).toHaveBeenLastCalledWith('production', '2026-05')
  })

  it('na záložkách nemocenské a ELDP je akční karta před podrobnostmi', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    for (const tab of ['sickness', 'eldp']) {
      await clickTab(wrapper, tab)
      const card = wrapper.get('[data-test="monthly-checklist-panel"]')
      const details = wrapper.get(`[data-test="submissions-details-${tab}"]`)
      expect(card.element.compareDocumentPosition(details.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    }
  })

  /* „Co odesílám, mám vidět hned": akční karta je na záložce agendy první. */
  it('na záložce JMHZ je akční karta první a zbytek je pod Podrobnostmi', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'jmhz')

    const card = wrapper.get('[data-test="submissions-action-card"]')
    const details = wrapper.get('[data-test="submissions-details-jmhz"]')
    expect(card.element.compareDocumentPosition(details.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(details.find('[data-test="monthly-checklist-panel"]').exists()).toBe(false)
    expect(card.find('[data-test="monthly-checklist-panel"]').exists()).toBe(true)
  })

  it('odkaz na zmrazené JMHZ rozbalí podrobnosti a předá ID podání', async () => {
    m.routeParams = { tab: 'jmhz' }
    m.routeQuery = { period: '2026-04', environment: 'test', submission: '15' }
    const wrapper = mount(PayrollSubmissions, {
      global: {
        directives: { 'column-labels': {} },
        stubs: { PayrollSubmissionOverviewPanel: { name: 'PayrollSubmissionOverviewPanel', props: ['submissionId', 'period', 'environment'], template: '<div />' } },
      },
    })
    await flushPromises()
    expect(wrapper.get('[data-test="submissions-details-jmhz"]').attributes('open')).toBeDefined()
    const panel = wrapper.findComponent({ name: 'PayrollSubmissionOverviewPanel' })
    expect(panel.props('submissionId')).toBe(15)
    expect(panel.props('period')).toBe('2026-04')
    expect(panel.props('environment')).toBe('test')
  })

  it('oddělí test a produkci, používá standardní záložky a SearchableSelect', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    // Třináct: dvanáct dosavadních plus „K odeslání" — jedno místo, kde jsou
    // vidět všechna připravená a neodeslaná podání napříč agendami a odkud se
    // dají odeslat hromadně. Vlastní záložku má i záměr uplatňovat slevu
    // (OZUSPOJ) — je to podmínka nároku, ne součást měsíčního hlášení.
    // „Další povinnosti" vede explicitní NEMPRI/HZUPN/ELDP/úrazovou matici,
    // zatímco „Ostatní" zůstává záchytná skupina pro neznámé kódy.
    // Čtrnáctá je Dohlášení údajů (REGZEC A3) za zaměstnance přihlášené přes ONZ.
    // Patnáctá je roční vyúčtování daně (DPZVD6/DPSVD2), které se podává do EPO
    // a mezi daňová přiznání nepatří.
    // Šestnáctá jsou přihlášky k podání (REGZEC A1) připravované hromadně.
    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs).toHaveLength(16)
    expect(tabs.some(tab => tab.text().includes('payroll.submissions.tabs.registration_a1'))).toBe(true)
    expect(tabs.some(tab => tab.text().includes('payroll.submissions.tabs.statutory'))).toBe(true)
    expect(tabs.some(tab => tab.text().includes('payroll.submissions.tabs.registration_completion'))).toBe(true)
    await clickTab(wrapper, 'regzel')
    await flushPromises()
    expect(wrapper.findAll('input[role="combobox"]').length).toBeGreaterThanOrEqual(1)
    expect(wrapper.text()).toContain('payroll.regzel.environment.production_warning')

    // Prostředí se přepíná viditelným segmentovým přepínačem, ne rozbalovacím
    // seznamem — testovací volba musí být vidět bez rozkliknutí.
    const environment = wrapper.get('[data-test="regzel-environment"]')
    await environment.get('[data-test="environment-switch-test"]').trigger('click')
    await flushPromises()

    expect(m.snapshots).toHaveBeenLastCalledWith('test', { limit: 25, offset: 0 })
    expect(wrapper.text()).toContain('payroll.regzel.environment.test_warning')

    // Odznak Inboxu musí následovat zvolené prostředí. Dřív se ptal natvrdo
    // produkce, takže číslo u záložky tvrdilo něco jiného, než co se pod ní
    // otevřelo.
    expect(m.submissionInbox).toHaveBeenLastCalledWith('test')

    // Skupinu agend filtruje server — panel ji nesmí dofiltrovávat z přijaté
    // stránky, jinak by pager počítal řádky, které tabulka neukazuje.
    await clickTab(wrapper, 'jmhz')
    expect(m.overview).toHaveBeenCalledWith(
      'test',
      expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
      { agenda_group: 'jmhz', limit: 50, offset: 0 },
    )
    expect(wrapper.text()).toContain('JMHZ')
    expect(wrapper.text()).toContain('payroll.submissions.jmhz_fail_closed')
  })

  /**
   * Měsíční přehled je teď výchozí záložka — účetní nemá hledat „co mám
   * tenhle měsíc udělat" po deseti dalších záložkách.
   */
  it('otevře se rovnou na měsíčním přehledu a nabídne akci podle druhu', async () => {
    m.monthlyChecklist.mockResolvedValue({
      environment: 'production',
      period: '2026-08',
      window: { from: '2026-08-01', to: '2026-08-31' },
      summary: { total: 2, send: 1, generate: 0, manual: 1, done: 0 },
      items: [
        {
          key: 'submission:7',
          source: 'submission',
          agenda_code: 'JMHZ25',
          agenda_label: 'JMHZ25',
          subject: 'office:synthetic',
          period: '2026-08',
          due_on: '2026-09-20',
          phase: 'open',
          days_to_due: 30,
          is_overdue: false,
          status: 'ready',
          document: { format: 'XML (JMHZ)', note: '' },
          recipient: { label: 'ČSSZ', note: '', applicable: true },
          channel: { label: 'datová schránka — odesílací brána', note: '', applicable: true },
          done: false,
          action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null },
        },
        {
          key: 'levy:1',
          source: 'levy',
          agenda_code: 'statutory_insurance',
          agenda_label: 'Zákonné pojištění odpovědnosti zaměstnavatele (úrazové)',
          subject: 'institution:statutory_insurance:123',
          period: '2026-Q2',
          due_on: '2026-08-31',
          phase: 'open',
          days_to_due: 5,
          is_overdue: false,
          status: 'open',
          document: { format: null, note: 'Bez dokumentu — jde o platbu, ne o podání.' },
          recipient: { label: 'institution:statutory_insurance:123', note: '', applicable: true },
          channel: { label: 'bankovní převod', note: 'ABO/SEPA export v modulu Platby.', applicable: true },
          done: false,
          action: { kind: 'generate', label: 'Otevřít platby', path: '/payroll/payments', reason: null },
        },
      ],
    })

    const wrapper = mount(PayrollSubmissions, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' } } },
    })
    await flushPromises()

    expect(wrapper.get('[role="tab"][aria-selected="true"]').text())
      .toContain('payroll.submissions.tabs.monthly')
    expect(m.monthlyChecklist).toHaveBeenCalledWith(
      'production',
      expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
    )
    // JSDOM nevyhodnocuje `md:block`/`md:hidden`, takže by se stejný
    // `data-test` napočítal dvakrát (desktopová tabulka i mobilní karty) —
    // dotaz se proto omezí na řádky desktopové tabulky.
    const rows = wrapper.findAll('tbody tr[data-test="monthly-checklist-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Odeslat')
    expect(rows[1]!.text()).toContain('Otevřít platby')
    expect(rows[1]!.text()).toContain('jde o platbu, ne o podání')
  })

  /*
   * Povinnost s neznámou agendou spadne na serveru do skupiny `other`.
   * Dokud pro ni nebyla záložka, nezobrazil ji ŽÁDNÝ panel — oba filtrovaly
   * skupinu na serveru, takže se taková povinnost tiše ztratila.
   */
  it('má záložku pro skupinu other, aby nezařazená povinnost nezmizela', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await clickTab(wrapper, 'other')
    await flushPromises()

    expect(m.overview).toHaveBeenCalledWith(
      'production',
      expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
      { agenda_group: 'other', limit: 50, offset: 0 },
    )
    expect(wrapper.text()).toContain('payroll.submissions.other_title')
  })

  it('nabídne volbu podpisového certifikátu jako vlastní záložku', async () => {
    m.signingProfile.mockResolvedValue({
      environment: 'production',
      environments: ['production', 'test'],
      storage_available: true,
      profile: null,
      certificates: [],
      warnings: [],
    })

    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await clickTab(wrapper, 'certificate')
    await flushPromises()

    expect(m.signingProfile).toHaveBeenCalledWith('production')
    expect(wrapper.get('[data-test="payroll-signing-certificate"]').text())
      .toContain('payroll.submissions.signing.title')
  })

  /**
   * Potvrzení evidence se stvrzuje JEDNOU — při uložení profilu. Příprava XML
   * čte tentýž profil a nic nového nepotvrzuje, takže tady žádný zaškrtávací
   * box být nesmí: stránka místo něj ukazuje pasivní větu s datem potvrzení.
   */
  it('XML připraví bez druhého potvrzení a API chybu zobrazí trvale inline', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'regzel')
    await flushPromises()

    expect(wrapper.find('[data-test="regzel-prepare-confirmation"]').exists())
      .toBe(false)
    expect(wrapper.text()).toContain('payroll.regzel.prepare.profile_confirmed')

    m.prepare.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Produkční VS nesmí být testovací.',
          },
        },
      },
    })
    await wrapper.get('[data-test="regzel-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepare).toHaveBeenCalledWith(expect.objectContaining({
      office_id: 42,
      environment: 'production',
    }))
    expect(m.prepare.mock.calls[0][0]).not.toHaveProperty('evidence_confirmed')
    expect(wrapper.get('[data-test="regzel-error"]').text()).toContain(
      'Produkční VS nesmí být testovací.',
    )
  })

  /**
   * Tohle je důkaz, o který se opírá `health_insurer_export: available = true`
   * v {@see \MyInvoice\Service\Payroll\SupportMatrix}: účetní se k oznamovací
   * povinnosti PROKLIKÁ. Hotové jádro bez cesty k němu je z pohledu uživatele
   * nedostupná funkce, takže kdyby záložka zmizela, musí zhasnout i vlajka.
   */
  it('proklikne se na oznamovací povinnost vůči zdravotní pojišťovně', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await clickTab(wrapper, 'health')
    await flushPromises()

    expect(wrapper.find('[data-test="health-notifications"]').exists()).toBe(true)
    // Přiznání, co modul neumí, je vidět hned po prokliku — ne až po akci.
    expect(wrapper.find('[data-test="health-notifications-limits"]').exists())
      .toBe(true)
  })

  it('ve společné zdravotní záložce zachová historii měsíčních přehledů ke stažení', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await clickTab(wrapper, 'health')
    await flushPromises()

    expect(m.runs).toHaveBeenCalledWith(expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/))
    expect(m.healthOverviews).toHaveBeenCalledWith(18)
    expect(m.healthOverviews).toHaveBeenCalledWith(19)
    expect(m.healthOverviews).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-test="health-payment-overviews"] article')).toHaveLength(2)
    expect(wrapper.get('[data-test="health-payment-overviews"]').text()).toContain('111')
    expect(wrapper.get('[data-test="health-payment-overviews"]').text())
      .toContain('payroll.submissions.overview.health_description')

    const download = wrapper.get('[data-test="health-overview-download"]')
    await download.trigger('click')
    await flushPromises()
    expect(m.prepareHealthOverview).toHaveBeenCalledWith(18, '111', 'production')
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      31,
      expect.objectContaining({ id: 52, mime_type: 'application/pdf' }),
    )
    expect(m.downloadHealthOverview).not.toHaveBeenCalled()
  })

  it('nabídne bezpečně označený PVPOJ kontrolní náhled ke stažení', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await clickTab(wrapper, 'jmhz')
    await flushPromises()

    expect(m.jmhzOffices).toHaveBeenCalledWith(18)
    expect(m.jmhzOffices).toHaveBeenCalledWith(19)
    expect(m.jmhzPreview).toHaveBeenCalledWith(18, 4)
    expect(m.jmhzPreview).toHaveBeenCalledWith(19, 4)
    expect(m.jmhzOrdinaryEvidence).toHaveBeenCalledWith(18)
    expect(m.jmhzOrdinaryEvidence).toHaveBeenCalledWith(19)
    expect(wrapper.findAll('[data-test="jmhz-pvpoj-previews"] article')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence"] article')).toHaveLength(2)
    expect(wrapper.get('[data-test="jmhz-pvpoj-previews"]').text())
      .toContain('payroll.submissions.overview.jmhz_preview_only')

    await wrapper.get('[data-test="jmhz-pvpoj-previews"] button').trigger('click')
    await flushPromises()
    expect(m.downloadJmhzPreview).toHaveBeenCalledWith(
      expect.objectContaining({ revision_id: 18, workflow_status: 'preview_only' }),
    )
  })

  it('stará immutable ordinary evidence vede účetní k nové revizi, ne k editaci vztahu', async () => {
    m.jmhzOrdinaryEvidence.mockResolvedValue({
      scopes: [{
        employee_id: 3,
        employment_id: 4,
        employee_name: 'Cyril Syntetický',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_scope_mismatch',
        attention_message: 'Zmrazené potvrzení už neodpovídá aktuální specifikaci.',
      }],
      evidences: [],
    })

    const wrapper = mount(PayrollSubmissions, {
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a :data-to="to"><slot /></a>',
          },
        },
      },
    })
    await flushPromises()
    await clickTab(wrapper, 'jmhz')
    await flushPromises()

    const evidence = wrapper.get('[data-test="jmhz-ordinary-evidence"]')
    expect(evidence.text()).toContain('Zmrazené potvrzení už neodpovídá aktuální specifikaci.')
    expect(evidence.text())
      .toContain('payroll.submissions.overview.jmhz_guidance.actions.revision')
    expect(evidence.text())
      .not.toContain('payroll.submissions.overview.jmhz_evidence_attention_employment_action')
  })

  /**
   * Přehled se podává za registraci u OSSZ, takže revize přes dvě mzdové
   * účtárny dá dva náhledy — každý pod svým variabilním symbolem. Účtárna bez
   * variabilního symbolu se přitom musí ukázat ADRESNĚ, ne jako jeden slitý
   * banner, jinak uživatel netuší, kterou registraci má doplnit.
   */
  it('rozpadne PVPOJ náhled na registrace a chybějící VS pojmenuje', async () => {
    m.runs.mockImplementation(async () => [{
      id: 8,
      period_start: '2026-08-01',
      revision_id: 18,
      revision_status: 'approved',
    }])
    m.jmhzOffices.mockImplementation(async () => [
      {
        office_id: 4,
        code: 'UC4',
        name: 'Mzdová účtárna 4',
        social_security_variable_symbol: '1234567890',
        submittable: true,
      },
      {
        office_id: 5,
        code: 'UC5',
        name: 'Mzdová účtárna 5',
        social_security_variable_symbol: '9990001234',
        submittable: true,
      },
      {
        office_id: 6,
        code: 'UC6',
        name: 'Mzdová účtárna 6',
        social_security_variable_symbol: null,
        submittable: false,
      },
    ])

    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'jmhz')
    await flushPromises()

    expect(m.jmhzPreview).toHaveBeenCalledWith(18, 4)
    expect(m.jmhzPreview).toHaveBeenCalledWith(18, 5)
    expect(m.jmhzPreview).not.toHaveBeenCalledWith(18, 6)
    expect(wrapper.findAll('[data-test="jmhz-pvpoj-previews"] article'))
      .toHaveLength(2)
    const offices = wrapper.findAll('[data-test="jmhz-preview-office"]')
    expect(offices).toHaveLength(2)

    const blocked = wrapper.get('[data-test="jmhz-blocked-offices"]')
    expect(blocked.text()).toContain('UC6')
    expect(blocked.text())
      .toContain('payroll.submissions.overview.jmhz_office_variable_symbol_missing')
    expect(blocked.text()).not.toContain('UC4')
  })

  it('zpřístupní bezpečný detail částí, artefaktů a problémů posledního podání', async () => {
    const overview = await m.overview()
    overview.items[0].latest_submission = {
      id: 31,
      status: 'validated',
      submission_kind: 'regular',
      channel: 'manual_upload',
      submitted_at: null,
      decided_at: null,
    }
    m.overview.mockResolvedValue(overview)

    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'jmhz')
    await flushPromises()

    await wrapper.get('[data-test="submission-detail-open"]').trigger('click')
    await flushPromises()

    expect(m.submissionDetail).toHaveBeenCalledWith(31)
    expect(wrapper.get('[data-test="submission-detail"]').text()).toContain('outbound_xml')
    expect(wrapper.get('[data-test="submission-detail"]').text()).toContain('MANUAL_REVIEW')
    expect(wrapper.get('[data-test="submission-detail"]').text()).toContain('2.0 kB')

    await wrapper.get('[data-test="submission-artifact-download"]').trigger('click')
    await flushPromises()
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      31,
      expect.objectContaining({ id: 51, mime_type: 'application/xml' }),
    )
  })

  it('po otevření detailu znovu odstraní starou chybu stažení artefaktu', async () => {
    const overview = await m.overview()
    overview.items[0].latest_submission = {
      id: 31,
      status: 'validated',
      submission_kind: 'regular',
      channel: 'manual_upload',
      submitted_at: null,
      decided_at: null,
    }
    m.overview.mockResolvedValue(overview)
    m.downloadSubmissionArtifact.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            message: 'Artefakt již není dostupný.',
          },
        },
      },
    })

    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'jmhz')
    await flushPromises()
    await wrapper.get('[data-test="submission-detail-open"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="submission-artifact-download"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="submission-artifact-download-error"]').text())
      .toContain('Artefakt již není dostupný.')

    await wrapper.get('[data-test="submission-detail-open"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="submission-artifact-download-error"]').exists())
      .toBe(false)
  })

  it('zobrazuje účinný stav lhůty odděleně od stavu podání', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()
    await clickTab(wrapper, 'jmhz')
    await flushPromises()

    expect(wrapper.get('[data-test="submission-deadline-phase"]').text())
      .toContain('payroll.submissions.overview.deadline_phase.not_open')
  })

  it('zobrazí odznak inboxu a umožní položku potvrdit i odložit s důvodem', async () => {
    const inboxItem = {
      id: 101,
      obligation_id: 7,
      submission_id: null,
      agenda_code: 'JMHZ25',
      subject_type: 'office',
      subject_reference: 'office:synthetic',
      period_start: '2026-09-01',
      period_end: '2026-09-30',
      due_on: '2026-09-13',
      problem_kind: 'due_soon',
      escalation_level: 'due_soon',
      status: 'open',
      snoozed_until: null,
      snooze_reason: null,
      acknowledged_at: null,
      resolved_at: null,
      row_version: 1,
      created_at: '2026-09-01 08:00:00',
      updated_at: '2026-09-01 08:00:00',
    }
    m.submissionInbox.mockResolvedValue({
      environment: 'production',
      status: 'unresolved',
      summary: { total: 1, open: 1, acknowledged: 0, snoozed: 0 },
      items: [inboxItem],
      total: 1,
      limit: 25,
      offset: 0,
    })
    m.acknowledgeInboxItem.mockResolvedValue({ id: 101, status: 'acknowledged', row_version: 2 })
    m.snoozeInboxItem.mockResolvedValue({
      id: 101,
      status: 'snoozed',
      row_version: 3,
      snoozed_until: '2026-09-05T10:00:00Z',
    })

    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    expect(wrapper.get('[data-test="submissions-inbox-badge"]').text()).toBe('1')

    await clickTab(wrapper, 'inbox')
    await flushPromises()

    // Stránkuje SERVER: panel musí posílat rozsah stránky, ne si řádky
    // filtrovat až z odpovědi (vyřešené vyřazuje serverový výchozí filtr).
    expect(m.submissionInbox).toHaveBeenCalledWith('production', {
      limit: 25,
      offset: 0,
    })
    expect(wrapper.get('[data-test="inbox-row"]').text()).toContain('JMHZ')

    await wrapper.get('[data-test="inbox-acknowledge"]').trigger('click')
    await flushPromises()
    expect(m.acknowledgeInboxItem).toHaveBeenCalledWith(101, 1)

    // Modal se teleportuje mimo strom wrapperu, hledá se proto v document.body.
    await wrapper.get('[data-test="inbox-snooze"]').trigger('click')
    const confirmButton = () =>
      document.body.querySelector<HTMLButtonElement>('[data-test="snooze-confirm"]')
    expect(confirmButton()).not.toBeNull()
    confirmButton()!.click()
    await flushPromises()
    expect(m.snoozeInboxItem).not.toHaveBeenCalled()

    const reasonInput = document.body
      .querySelector<HTMLTextAreaElement>('[data-test="snooze-reason-input"]')
    expect(reasonInput).not.toBeNull()
    reasonInput!.value = 'Čekáme na doklad od klienta.'
    reasonInput!.dispatchEvent(new Event('input'))
    await flushPromises()
    confirmButton()!.click()
    await flushPromises()
    expect(m.snoozeInboxItem).toHaveBeenCalledWith(
      101,
      1,
      expect.any(String),
      'Čekáme na doklad od klienta.',
    )
  })
})
