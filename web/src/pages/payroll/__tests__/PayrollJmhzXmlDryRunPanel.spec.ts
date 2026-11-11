import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  freeze: vi.fn(),
  dryRun: vi.fn(),
  offices: vi.fn(),
  context: vi.fn(),
  components: vi.fn(() => Promise.resolve([])),
  canWrite: vi.fn(() => true),
  workplaceBulkPreview: vi.fn(() => Promise.resolve({
    period_start: '2026-08-01',
    summary: { employments: 1, missing: 1, verified: 0, invalid: 0, excluded: 0 },
    suggestions: [],
    missing_employment_ids: [12],
    items: [],
  })),
  workplaceBulkApply: vi.fn(),
  employmentJmhzEvidenceOptions: vi.fn(() => Promise.resolve({ countries: [] })),
  searchJmhzMunicipalities: vi.fn(() => Promise.resolve([])),
  deferrals: vi.fn(() => Promise.resolve({
    environment: 'production',
    run_id: 8,
    period_start: '2026-08-01',
    due_on: '2026-09-21',
    overdue: false,
    open_count: 0,
    deferrals: [],
  })),
  defer: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    freezeJmhzPreparation: m.freeze,
    jmhzXmlDryRun: m.dryRun,
    jmhzPvpojOffices: m.offices,
    components: m.components,
    workplaceBulkPreview: m.workplaceBulkPreview,
    workplaceBulkApply: m.workplaceBulkApply,
    employmentJmhzEvidenceOptions: m.employmentJmhzEvidenceOptions,
    searchJmhzMunicipalities: m.searchJmhzMunicipalities,
    jmhzDeferrals: m.deferrals,
    deferJmhzEmployment: m.defer,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { context: m.context },
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: (key: string) => !key.includes('some_internal_code_not_translated_yet'),
    locale: { value: 'cs' },
  }),
}))

import PayrollJmhzXmlDryRunPanel from '@/pages/payroll/PayrollJmhzXmlDryRunPanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

const xml = '<?xml version="1.0" encoding="UTF-8"?>\n<jmhz verze="1.4.3"/>'

describe('PayrollJmhzXmlDryRunPanel', () => {
  it('vede z kontroly do správného měsíce docházky a platné záložky účtáren', async () => {
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'Lokální test.' }, blockers: [
      { code: 'jmhz_work_month_not_approved', entity_type: 'employment', entity_id: 12, attribute_ids: [] },
      { code: 'social_security_variable_symbol_missing', entity_type: 'office', entity_id: 4, attribute_ids: [] },
    ] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()
    const targets = wrapper.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to')!))
    expect(targets).toContainEqual({ name: 'payroll-time', query: { employment: '12', period: '2026-08' } })
    expect(targets).toContainEqual({ name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-offices' })
  })

  /*
   * Haléřová částka ve výsledku běhu je věc mzdového běhu, ne identity osoby —
   * dřív chyběl i18n klíč, takže nález padal na `/admin/support`.
   */
  it('nález o haléřích v hlášení pošle do Mzdových běhů, ne na podporu', async () => {
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'Lokální test.' }, blockers: [
      { code: 'jmhz_scenario1_whole_czk_required', entity_type: 'person', entity_id: 5, attribute_ids: [10228] },
    ] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()
    const targets = wrapper.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to')!))
    expect(targets).toContainEqual({ name: 'payroll-runs', query: { period: '2026-08' } })
    expect(targets.some(target => target === '/admin/support')).toBe(false)
  })

  /*
   * Hromadné doplnění pracoviště se otevírá dialogem, ne odkazem — jinak by
   * účetní musela projít vztah po vztahu.
   */
  it('nález o neověřeném pracovišti otevře hromadný dialog místo odkazu', async () => {
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'Lokální test.' }, blockers: [
      { code: 'jmhz_workplace_codebooks_unverified', entity_type: 'employment', entity_id: 12, attribute_ids: ['10229'] },
    ] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: { stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        teleport: true,
      } },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="workplace-bulk-dialog"]').exists()).toBe(false)
    await wrapper.get('[data-test="jmhz-dry-run-workplace-bulk"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="workplace-bulk-dialog"]').exists()).toBe(true)
  })
  /*
   * Za období od 01/2027 chybí číselník obcí a států úplně. Hromadné doplnění
   * by vrátilo zavádějící „obec neplatí" i pro správný kód, takže ho panel
   * nenabízí a místo obecného kroku „podejte ručně" řekne, že se čeká na
   * vyhlášení číselníku.
   */
  it('nevyhlášený číselník obcí nenabízí hromadné doplnění ani odkaz', async () => {
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'Lokální test.' }, blockers: [
      {
        code: 'jmhz_workplace_codebooks_not_published',
        entity_type: 'employment',
        entity_id: 12,
        attribute_ids: ['10229', '10230', '10231'],
        remediation: { kind: 'manual', field: null },
      },
    ] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [{ ...run, period_start: '2027-01-01' }] as never[] },
      global: { stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        teleport: true,
      } },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-dry-run-workplace-bulk"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="jmhz-dry-run-blocker-label"]').text())
      .toBe('payroll.jmhz_gate.codes.jmhz_workplace_codebooks_not_published')
    expect(wrapper.get('[data-test="jmhz-dry-run-blocker-step"]').text())
      .toBe('payroll.jmhz_gate.code_steps.jmhz_workplace_codebooks_not_published')
  })
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.freeze.mockResolvedValue({ id: 77, readiness_status: 'source_ready' })
    m.context.mockResolvedValue([])
    m.offices.mockResolvedValue([{
      office_id: 4,
      code: 'UC4',
      name: 'Mzdová účtárna 4',
      social_security_variable_symbol: '1234567890',
      submittable: true,
    }])
  })

  /**
   * Měsíční hlášení se podává ZA REGISTRACI u OSSZ. Revize přes víc mzdových
   * účtáren proto nesmí nacvičovat naslepo: dokud si uživatel účtárnu nezvolí,
   * test se nespustí — vykázat lidi jedné účtárny pod variabilním symbolem
   * druhé je horší než nic nespustit.
   */
  it('u dvou registrací žádá volbu účtárny a předá ji do testu', async () => {
    m.offices.mockResolvedValue([
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
    ])
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      office_id: 5,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.6',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'x',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    const start = wrapper.get('[data-test="jmhz-dry-run-start-18"]')
    expect(start.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="jmhz-dry-run-office-18"] select')
      .setValue('5')
    await start.trigger('click')
    await flushPromises()

    expect(m.dryRun).toHaveBeenCalledWith(77, 'test', 5)
  })

  it('zmrazí přípravu a ukáže XML ověřené proti připnutému schématu', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.6',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(m.freeze).toHaveBeenCalledWith(18, expect.any(String), 'test')
    expect(m.dryRun).toHaveBeenCalledWith(77, 'test', 4)
    expect(wrapper.text()).toContain('jmhz_dry_run_valid')
    expect(wrapper.find('pre').exists()).toBe(false)

    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.get('pre').text()).toContain('jmhz')
  })

  it('blokovaný dokument vypíše důvody a nenabídne XML', async () => {
    m.context.mockResolvedValue([
      {
        id: 12,
        employee_id: 13,
        code: 'DPP-13',
        relation_type: 'dpp',
        status: 'active',
        full_name: 'Dana Testovací',
      },
      {
        id: 14,
        employee_id: 11,
        code: 'HPP-11',
        relation_type: 'employment',
        status: 'active',
        full_name: 'Adam Testovací',
      },
    ])
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [
        {
          code: 'jmhz_taxpayer_declaration_unresolved',
          entity_type: 'person',
          entity_id: 11,
          attribute_ids: ['10419'],
        },
        {
          code: 'jmhz_taxpayer_declaration_unresolved',
          entity_type: 'person',
          entity_id: 13,
          attribute_ids: ['10419'],
        },
        {
          code: 'some_internal_code_not_translated_yet',
          entity_type: 'employment',
          entity_id: 12,
          attribute_ids: [],
        },
      ],
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('jmhz_dry_run_blocked')
    // Kód bez překladu i bez věty serveru: neutrální „nález bez popisu",
    // ne „neznámá blokace" s odkazem na podporu.
    expect(wrapper.text()).toContain('payroll.jmhz_gate.unlabelled')
    expect(wrapper.findAll('a[data-to]').some(link => link.attributes('data-to') === '"/admin/support"')).toBe(false)
    expect(wrapper.text()).toContain('jmhz_dry_run_blocker_occurrences')
    expect(wrapper.findAll('[data-test="jmhz-dry-run-blocker"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="jmhz-dry-run-technical-detail"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation-list"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="router-link"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Adam Testovací')
    expect(wrapper.text()).toContain('Dana Testovací')
    expect(wrapper.text()).not.toContain('jmhz_dry_run_actions.record')
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="router-link"]').attributes('data-to'))
      .toContain('payroll-people')
    expect(wrapper.text()).not.toContain('some_internal_code_not_translated_yet')
    expect(wrapper.find('pre').exists()).toBe(false)
  })

  it('u velké firmy nevyrenderuje stovky odkazů na jednotlivé zaměstnance', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: Array.from({ length: 50 }, (_, index) => ({
        code: 'jmhz_taxpayer_declaration_unresolved',
        entity_type: 'person',
        entity_id: 10_001 + index,
        attribute_ids: ['10419'],
      })),
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a data-test="router-link" :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="jmhz-dry-run-remediation"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="jmhz-dry-run-remediation-list"]').exists())
      .toBe(false)
    expect(wrapper.get('[data-test="jmhz-dry-run-remediation"]').attributes('data-to'))
      .toBe('{"name":"payroll-people"}')
    expect(wrapper.text()).not.toContain('10050')
  })

  it('vypíše nepropustné vady z katalogu kontrol i s kódem chyby', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_incomplete',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.6',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      controls: {
        schema_reference: 'payroll-jmhz-control-evaluation.v1',
        catalog_key: 'jmhz-controls-1.4.2.7-source-v3',
        catalog_manifest_sha256: 'e'.repeat(64),
        submittable: false,
        counts: {
          passed: 70,
          failed: 1,
          not_applicable: 90,
          not_evaluable: 33,
          unimplemented: 5,
        },
        blocking: [
          {
            control_id: 8,
            name: 'Pojistné za zaměstnavatele',
            outcome: 'failed',
            scope: 'pvpoj',
            passability: 'blocking',
            technical: false,
            part: 'pvpoj',
            form_ordinal: null,
            message: 'Pojistné 10024 neodpovídá sazbě.',
            attribute_ids: ['10024', '10023'],
            error_code: 20008,
          },
        ],
        warnings: [],
        coverage_gaps: [
          {
            control_id: 59,
            name: 'Vyměřovací základ s podmínkami',
            outcome: 'unimplemented',
            scope: 'employee_form',
            passability: 'blocking',
            technical: false,
            part: 'submission',
            form_ordinal: 0,
            message: 'Kontrola dopadá na podání, ale implementaci nemá.',
            attribute_ids: ['10245'],
            error_code: 20059,
          },
        ],
        evaluated: [],
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_dry_run_is_not_a_submission',
        reason: 'Jde o lokální test, ne o podání.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    // Nepropustná vada: nadpis mluví o kontrolách, které neprošly, ne
    // o „nevykonaném katalogu" (ten platí jen pro nevykonané kontroly).
    expect(wrapper.text()).toContain('jmhz_dry_run_controls_failed')
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain(
      'Pojistné 10024 neodpovídá sazbě.',
    )
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain('20008')
    expect(wrapper.get('[data-test="jmhz-controls-gaps"]').text()).toContain('20059')
    expect(wrapper.find('[data-test="jmhz-controls-warnings"]').exists()).toBe(false)
  })

  it('v režimu jen pro čtení nespustí test', async () => {
    m.canWrite.mockReturnValue(false)

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })

    expect(
      wrapper.get('[data-test="jmhz-dry-run-start-18"]').attributes('disabled'),
    ).toBeDefined()
    // Zhasnuté tlačítko bez věty vypadá jako porucha aplikace.
    expect(wrapper.get('[data-test="jmhz-dry-run-blocked-18"]').text())
      .toContain('payroll.submissions.overview.jmhz_dry_run_read_only')
    expect(m.freeze).not.toHaveBeenCalled()
  })

  /** Čeká se na volbu účtárny — a musí to být napsané, ne jen zhasnuté. */
  it('u víc účtáren řekne, na co se čeká', async () => {
    m.offices.mockResolvedValue([
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
        social_security_variable_symbol: '2234567890',
        submittable: true,
      },
    ])

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-dry-run-blocked-18"]').text())
      .toContain('payroll.submissions.overview.jmhz_social_multiple_offices')

    await wrapper.get(`[data-test="jmhz-dry-run-office-18"] select`).setValue('4')
    expect(wrapper.find('[data-test="jmhz-dry-run-blocked-18"]').exists()).toBe(false)
  })

  /*
   * Jeden blokovaný zaměstnanec nesmí zastavit hlášení za ostatní: u nálezu
   * na vztahu jde vztah odložit s povinným důvodem a test se spustí znovu.
   */
  it('blokovaný vztah odloží s důvodem a test spustí znovu', async () => {
    m.context.mockResolvedValue([
      { id: 12, employee_id: 13, code: 'DPP-13', relation_type: 'dpp', status: 'active', full_name: 'Dana Testovací' },
    ])
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [{
        code: 'jmhz_average_hourly_earning_missing',
        entity_type: 'employment',
        entity_id: 12,
        attribute_ids: ['10345'],
        reason: 'Chybí ověřený průměrný hodinový výdělek.',
        action: 'Otevřete Mzdy → Absence a průměry a doplňte výdělek.',
        remediation: { kind: 'averages', field: null },
        deferrable: true,
      }],
      official_submission: { supported: false, reason_code: 'x', reason: 'x' },
    })
    m.defer.mockResolvedValue({ created: true, deferral_ids: [5], employee_id: 13, employment_ids: [12], office_id: 4, source_revision_id: 18, blocker_codes: ['jmhz_average_hourly_earning_missing'] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[], environment: 'production' },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    // Proklik vede na průměr konkrétního vztahu a čtvrtletí.
    const targets = wrapper.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to')!))
    expect(targets).toContain('/payroll/absences?employment=12&tab=averages&year=2026&quarter=3')
    expect(wrapper.get('[data-test="jmhz-dry-run-blocker-step"]').text()).toContain('Absence a průměry')

    await wrapper.get('[data-test="jmhz-dry-run-defer-12"]').trigger('click')
    expect(wrapper.get('[data-test="jmhz-defer-form"]').text()).toContain('Dana Testovací')
    await wrapper.get('[data-test="jmhz-defer-confirm"]').trigger('click')
    expect(m.defer).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.jmhz_gate.deferral.reason_required')

    await wrapper.get('[data-test="jmhz-defer-reason"]').setValue('Průměr se doplní do konce měsíce.')
    await wrapper.get('[data-test="jmhz-defer-confirm"]').trigger('click')
    await flushPromises()

    // Odklad jde do prostředí panelu (výchozí produkce), ne natvrdo do testu.
    expect(m.defer).toHaveBeenCalledWith({
      environment: 'production',
      preparationId: 77,
      employmentId: 12,
      officeId: 4,
      reason: 'Průměr se doplní do konce měsíce.',
    })
    expect(m.freeze).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="jmhz-defer-form"]').exists()).toBe(false)
  })

  it('firemní nález odložit nenabídne', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [{
        code: 'jmhz_office_variable_symbol_missing',
        entity_type: 'office',
        entity_id: 4,
        attribute_ids: ['10221'],
        remediation: { kind: 'office', field: null },
        deferrable: false,
      }],
      official_submission: { supported: false, reason_code: 'x', reason: 'x' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test^="jmhz-dry-run-defer-"]').exists()).toBe(false)
  })

  it('technickou vadu přípravy nabídne spustit znovu a ukáže větu serveru', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [{
        code: 'some_internal_code_not_translated_yet',
        entity_type: 'preparation',
        entity_id: 77,
        attribute_ids: [],
        reason: 'Otisk přípravy nesouhlasí.',
        remediation: { kind: 'retry', field: null },
        deferrable: false,
      }],
      official_submission: { supported: false, reason_code: 'x', reason: 'x' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-dry-run-blocker-label"]').text()).toBe('Otisk přípravy nesouhlasí.')
    await wrapper.get('[data-test="jmhz-dry-run-retry"]').trigger('click')
    await flushPromises()
    expect(m.freeze).toHaveBeenCalledTimes(2)
  })

  it('u hlášení s odloženým vztahem vysvětlí očekávaná varování kontrol', async () => {
    m.context.mockResolvedValue([
      { id: 12, employee_id: 13, code: 'DPP-13', relation_type: 'dpp', status: 'active', full_name: 'Dana Testovací' },
    ])
    const warning = {
      control_id: 12,
      name: 'Pojistné za zaměstnance',
      outcome: 'failed',
      scope: 'pvpoj',
      passability: 'passable',
      technical: false,
      part: 'pvpoj',
      form_ordinal: null,
      message: 'Pojistné za zaměstnance neodpovídá součtu součástí.',
      attribute_ids: ['10028'],
      error_code: 40012,
    }
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      blockers: [],
      deferred: {
        purpose: 'deferral',
        deferral_ids: [5],
        employment_ids: [12],
        employee_ids: [13],
        summary_excluded_employee_ids: [],
        blockers: [],
        expected_warning_control_ids: [12],
      },
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: { package_key: 'p', data_version: '1.4.3', bundle_sha256: 'c', document_sha256: 'd' },
      controls: {
        schema_reference: 's', catalog_key: 'k', catalog_manifest_sha256: 'e', submittable: true,
        counts: { passed: 1, failed: 1, not_applicable: 0, not_evaluable: 0, unimplemented: 0, unverifiable: 0 },
        deviations: [], blocking: [], warnings: [warning], coverage_gaps: [], evaluated: [],
      },
      official_submission: { supported: false, reason_code: 'x', reason: 'x' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-dry-run-deferred"]').text()).toContain('Dana Testovací')
    expect(wrapper.get('[data-test="jmhz-dry-run-deferred-expected"]').text()).toContain('12')
    expect(wrapper.find('[data-test="jmhz-controls-expected-12"]').exists()).toBe(true)
  })

  /*
   * Test nad celou firmou před koncem měsíce vrátí stovky nálezů (u každého
   * vztahu neschválená docházka i chybějící pracovní souhrn). Stránka je musí
   * vykreslit seskupené a rychle, ne zamrznout.
   */
  it('stovky nálezů seskupí po kódu a vykreslí bez zamrznutí', async () => {
    const employments = Array.from({ length: 200 }, (_, index) => ({
      id: 100 + index, employee_id: 500 + index, code: `ZAM-${index}`,
      relation_type: 'employment', status: 'active', full_name: `Osoba ${index}`,
    }))
    m.context.mockResolvedValue(employments)
    const blockers = employments.flatMap(item => [
      { code: 'jmhz_work_month_not_approved', entity_type: 'employment', entity_id: item.id, attribute_ids: ['10259', '10260'], remediation: { kind: 'time', field: null }, deferrable: true },
      { code: 'jmhz_eldp_work_summary_missing', entity_type: 'employment', entity_id: item.id, attribute_ids: ['10240', '10241'], remediation: { kind: 'time', field: null }, deferrable: true },
    ])
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'x' }, blockers })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
    })
    const started = performance.now()
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()
    expect(performance.now() - started).toBeLessThan(2000)
    expect(wrapper.findAll('[data-test="jmhz-dry-run-blocker"]')).toHaveLength(2)
  })

  /** Období karty v lidském tvaru, ne strojové „2026-08". */
  it('období karty ukáže česky', async () => {
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('srpen 2026')
    expect(wrapper.text()).not.toContain('2026-08')
  })

  const routerStub = { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } }

  function finding(controlId: number, message: string) {
    return {
      control_id: controlId,
      name: 'Kontrola',
      outcome: 'failed',
      scope: 'submission',
      passability: 'blocking',
      technical: false,
      part: 'submission',
      form_ordinal: null,
      message,
      attribute_ids: [],
      error_code: null,
    }
  }

  /**
   * M6: test před koncem měsíce. Kontroly 43, 44 a 90 selžou jen kvůli
   * dnešnímu datu; panel to řekne jednou větou s datem a nevypíše je jako
   * „nepropustné vady, podání by bylo neúčinné".
   */
  it('před koncem období řekne jednou větou, od kdy půjde hlášení připravit', async () => {
    m.canWrite.mockReturnValue(true)
    m.dryRun.mockResolvedValue({
      status: 'dry_run_incomplete',
      preparation_id: 77,
      preparation_gate: {
        code: 'jmhz_period_not_finished',
        message: 'Období 08/2026 ještě neskončilo. Připravit ho půjde od 1. 9. 2026.',
        available_from: '2026-09-01',
      },
      waiting_for_period_end: true,
      date_only_control_ids: [43, 44, 90],
      blockers: [],
      xml,
      xml_sha256: 'c'.repeat(64),
      controls: {
        submittable: false,
        counts: { passed: 88, failed: 3, not_evaluable: 30, not_applicable: 0 },
        blocking: [
          finding(43, 'Pojištění do 2026-08-31 je po datu vyplnění podání 2026-08-12.'),
          finding(44, 'Pojištění do 2026-08-31 je po datu vyplnění podání 2026-08-12.'),
          finding(90, 'Hlášené období 2026-08 ještě neskončilo.'),
        ],
        warnings: [],
        coverage_gaps: [],
        evaluated: [],
      },
      official_submission: { supported: false, reason_code: 'x', reason: 'Lokální test.' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] }, global: { stubs: routerStub } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-dry-run-gate-18"]').text()).toContain('od 1. 9. 2026')
    expect(wrapper.get('[data-test="jmhz-dry-run-status"]').text()).toContain('jmhz_dry_run_waiting')
    expect(wrapper.find('[data-test="jmhz-controls-blocking"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('jmhz_dry_run_incomplete')
  })

  it('před koncem období nechá vypsanou vadu, která s datem nesouvisí', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_incomplete',
      preparation_id: 77,
      preparation_gate: { code: 'jmhz_period_not_finished', message: 'Období ještě neskončilo.', available_from: '2026-09-01' },
      waiting_for_period_end: false,
      date_only_control_ids: [43, 44, 90],
      blockers: [],
      xml,
      xml_sha256: 'c'.repeat(64),
      controls: {
        submittable: false,
        counts: { passed: 88, failed: 2, not_evaluable: 30, not_applicable: 0 },
        blocking: [finding(90, 'Hlášené období 2026-08 ještě neskončilo.'), finding(8, 'Pojistné 10024 neodpovídá sazbě.')],
        warnings: [],
        coverage_gaps: [],
        evaluated: [],
      },
      official_submission: { supported: false, reason_code: 'x', reason: 'Lokální test.' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] }, global: { stubs: routerStub } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    const blocking = wrapper.get('[data-test="jmhz-controls-blocking"]').text()
    expect(blocking).toContain('Pojistné 10024 neodpovídá sazbě.')
    expect(blocking).not.toContain('ještě neskončilo')
    expect(wrapper.get('[data-test="jmhz-dry-run-status"]').text()).toContain('jmhz_dry_run_controls_failed')
  })

  /**
   * B1: nález čtený ze schváleného běhu. Test řekne, že samotná oprava údaje
   * nestačí, a vede na mzdový běh měsíce; přesný důvod ze serveru má přednost
   * před obecným popiskem kódu.
   */
  it('u nálezu čteného ze schváleného běhu řekne o opravné revizi a ukáže přesný důvod', async () => {
    const reason = 'Variabilní symbol zaměstnavatele u ČSSZ „1100000012" u mzdové účtárny je vyplněný, ale neplatný: nesouhlasí kontrolní číslice.'
    m.dryRun.mockResolvedValue({ status: 'blocked', preparation_id: 77, official_submission: { supported: false, reason: 'Lokální test.' }, blockers: [
      {
        code: 'jmhz_office_variable_symbol_invalid',
        entity_type: 'office',
        entity_id: 4,
        attribute_ids: ['10221'],
        reason,
        reason_specific: true,
        message: reason,
        remediation: { kind: 'office', field: null },
        needs_revision: true,
      },
      {
        code: 'component_jmhz_mapping_missing',
        entity_type: 'component',
        entity_id: 3,
        attribute_ids: [],
        reason: 'Mzdové složky nemají určené zařazení do JMHZ.',
        remediation: { kind: 'components', field: null },
        needs_revision: false,
      },
    ] })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] }, global: { stubs: routerStub } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    const groups = wrapper.findAll('[data-test="jmhz-dry-run-blocker"]')
    expect(groups).toHaveLength(2)
    expect(groups[0]!.get('[data-test="jmhz-dry-run-blocker-label"]').text()).toBe(reason)
    expect(groups[0]!.get('[data-test="jmhz-dry-run-revision-hint"]').text())
      .toContain('payroll.jmhz_gate.freeze_blockers.revision_hint')
    expect(JSON.parse(groups[0]!.get('[data-test="jmhz-dry-run-revision-link"]').attributes('data-to')!))
      .toEqual({ name: 'payroll-runs', query: { period: '2026-08' } })
    // Zařazení složky se čte až při přípravě: o opravné revizi se nic neříká.
    expect(groups[1]!.find('[data-test="jmhz-dry-run-revision-hint"]').exists()).toBe(false)
    expect(groups[1]!.get('[data-test="jmhz-dry-run-blocker-label"]').text())
      .toBe('payroll.jmhz_gate.codes.component_jmhz_mapping_missing')
  })

  /**
   * B3: ročník, který aplikace ještě neumí sestavit. Důvod, co dělat a termín
   * stojí nahoře; když na straně účetní nic nechybí, panel neslibuje „kroky
   * k doplnění" a nenabízí odložení ani hromadné doplnění pracoviště.
   */
  it('u období, které nejde sestavit, ukáže důvod s termínem a žádné kroky navíc', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      preparation_gate: {
        code: 'jmhz_spec_version_unavailable',
        message: 'Číselník obcí a států ještě nebyl vyhlášen. Na vaší straně nic nechybí. Termín podání za 01/2027 je 22. 2. 2027.',
        available_from: null,
      },
      // Nález číselníků u vztahu server dál vrací; důvod ale nese závora,
      // takže se jako krok k doplnění neukáže.
      blockers: [{
        code: 'jmhz_workplace_codebooks_not_published',
        entity_type: 'employment',
        entity_id: 12,
        attribute_ids: ['10229', '10230', '10231'],
        remediation: { kind: 'manual', field: null },
        deferrable: false,
      }],
      official_submission: { supported: false, reason: 'Lokální test.' },
    })
    const wrapper = mount(PayrollJmhzXmlDryRunPanel, { props: { runs: [run] as never[] }, global: { stubs: routerStub } })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-dry-run-gate-18"]').text()).toContain('22. 2. 2027')
    expect(wrapper.get('[data-test="jmhz-dry-run-blocked-title"]').text())
      .toContain('jmhz_dry_run_blocked_nothing_to_fix')
    expect(wrapper.findAll('[data-test="jmhz-dry-run-blocker"]')).toHaveLength(0)
    expect(wrapper.find('[data-test="jmhz-dry-run-workplace-bulk"]').exists()).toBe(false)
    expect(wrapper.find('[data-test^="jmhz-dry-run-defer-"]').exists()).toBe(false)
  })
})
