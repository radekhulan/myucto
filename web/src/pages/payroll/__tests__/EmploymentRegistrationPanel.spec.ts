import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  prepare: vi.fn(),
  send: vi.fn(),
  status: vi.fn(),
  poll: vi.fn(),
  close: vi.fn(),
  events: vi.fn(),
  approveEvent: vi.fn(),
  a1Profile: vi.fn(),
  saveA1Profile: vi.fn(),
  writeA1MasterData: vi.fn(),
  checkA1Profile: vi.fn(),
  jmhzOptions: vi.fn(),
  searchMunicipalities: vi.fn(),
  searchCzIsco: vi.fn(),
  detectChanges: vi.fn(),
  fileChange: vi.fn(),
  current: vi.fn(),
  locale: 'cs',
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    detectEmploymentRegistrationChanges: m.detectChanges,
    fileEmploymentRegistrationChange: m.fileChange,
    previewEmploymentRegistration: m.preview,
    prepareEmploymentRegistration: m.prepare,
    currentEmploymentRegistration: m.current,
    sendEmploymentRegistrationTransport: m.send,
    employmentRegistrationTransportStatus: m.status,
    pollEmploymentRegistrationTransportAttempt: m.poll,
    closeEmploymentRegistrationTransportAttempt: m.close,
    employmentRegistrationEvents: m.events,
    approveEmploymentRegistrationEvent: m.approveEvent,
    employmentRegistrationA1Profile: m.a1Profile,
    saveEmploymentRegistrationA1Profile: m.saveA1Profile,
    writeEmploymentRegistrationA1MasterData: m.writeA1MasterData,
    checkEmploymentRegistrationA1Profile: m.checkA1Profile,
    employmentJmhzEvidenceOptions: m.jmhzOptions,
    searchJmhzMunicipalities: m.searchMunicipalities,
    searchCzIsco: m.searchCzIsco,
  },
}))

// Formátování chyby se NEmockuje: panel se podle strojového kódu z odpovědi
// rozhoduje, jestli nabídne odkaz na kartu osoby, a náhrada by tenhle kus
// chování obešla.

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
const routerPush = vi.fn(async () => {})
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: routerPush }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => key.startsWith('payroll.people.registration.a1.problem.'),
    locale: { get value() { return m.locale } },
  }),
}))

import EmploymentRegistrationPanel from '@/pages/payroll/EmploymentRegistrationPanel.vue'
import { resetPayrollJmhzOptions } from '@/composables/usePayrollJmhzOptions'

function jmhzOptions() {
  return {
    package_key: 'synthetic',
    manifest_sha256: 'a'.repeat(64),
    external_codebooks: {
      overlay_key: 'synthetic-overlay',
      manifest_sha256: 'b'.repeat(64),
      snapshot_date: '2026-08-13',
      effective_from: '2026-01-01',
      verified_through: '2026-08-13',
      base_spec_manifest_sha256: 'a'.repeat(64),
    },
    activity_codes: [
      { code: '1', label: 'Pracovní poměr', relationship_detail_mode: 'select' },
      { code: '13', label: 'Člen volební komise', relationship_detail_mode: 'forbidden' },
    ],
    relationship_detail_codes: [{ code: '1', label: 'Žádné' }],
    apz_instruments: [],
    countries: [
      { code: 'CZ', label: 'Česko' },
      { code: 'SK', label: 'Slovensko' },
    ],
    tax_identifier_types: [
      { code: 'D', label: 'DIČ' },
      { code: 'R', label: 'Rodné číslo' },
      { code: 'S', label: 'Sociální pojištění' },
      { code: 'J', label: 'Jiné' },
    ],
    education_levels: [{ code: 'M', label: 'Úplné střední odborné vzdělání s maturitou' }],
    work_mode_codes: [{ code: '1', label: 'Jednosměnný pracovní režim' }],
    employment_status_codes: [
      { code: '1111', label: 'Zaměstnanci v pracovním poměru na dobu neurčitou' },
      { code: '1341', label: 'Ostatní zaměstnanci ve služebním poměru na dobu neurčitou' },
    ],
    workplace_progress_codes: [{ code: '1', label: 'V prostorách zaměstnavatele' }],
    pension_type_codes: [{ code: '1', label: 'starobní' }],
    proof_identity_type_codes: [{ code: 'I', label: 'Průkaz totožnosti' }],
    health_restriction_type_codes: [{ code: '1', label: 'III. stupeň invalidity' }],
    foreign_worker_free_access_reason_codes: [
      { code: '1', label: 'Občan EU/EHP a Švýcarska' },
    ],
    foreign_worker_permit_type_codes: [{ code: '1', label: 'povolení k zaměstnání' }],
    labour_office_codes: [{ code: 'HMP', label: 'Krajská pobočka pro hlavní město Prahu' }],
    from_2027: {
      employment_types: [
        { code: '3', label: 'služební poměr podle zákona č. 361/2003 Sb.' },
        { code: '4', label: 'služební poměr podle zákona č. 221/1999 Sb.' },
      ],
      free_access_reason_codes: [
        { code: '22', label: '§ 98 písm. a) zvláštní dlouhodobý pobyt' },
        { code: '23', label: '§ 98 písm. k) vyslání zaměstnavatelem usazeným v EU' },
      ],
      service_termination_reasons: [
        { code: '5', label: 'Ostatní', employment_types: ['2', '3', '4'] },
        { code: '7', label: '§ 42 odst. 1 písm. h)', employment_types: ['3'] },
        { code: '15', label: '§ 18 písm. d)', employment_types: ['4'] },
      ],
      employment_status_codes: [
        { code: '1111', label: 'Zaměstnanci v pracovním poměru na dobu neurčitou' },
        { code: '2219', label: 'Zaměstnavatelé – fyzické osoby jinde neuvedené' },
      ],
    },
  }
}

const deadline = {
  earliest_registration_on: '2026-08-14',
  due_on: '2026-08-22',
  calendar_basis: 'calendar_days',
  ruleset_id: 'cz-employee-registration-2026-07.v1',
}

const preview = {
  employment_id: 5,
  agenda_code: 'PREZEC26',
  interaction: 'limited_pre_registration',
  action_code: 9,
  xml: '<PREZEC/>',
  xml_sha256: 'a'.repeat(64),
  deadline,
  employer_registration: null,
  official_submission: { supported: false, reason: 'Test.' },
}

function a1Suggested() {
  return {
    effective_on: '2026-08-14',
    row_version: 0,
    permanent_address: {
      street: 'Dlouhá',
      house_number: null,
      orientation_number: null,
      city: 'Praha',
      postal_code: '11000',
      country_code: 'CZ',
      ruian_point: null,
    },
    tax_residency: {
      country_code: 'CZ',
      identifier_type: null,
      identifier: null,
      residence_address: null,
    },
    employment: {
      activity_code: '1',
      relationship_detail_code: '1',
      actual_start_on: '2026-08-14',
      contract_start_on: '2026-08-14',
      small_scale: false,
      employment_status_code: null,
      work_mode_code: null,
      continuous_operation: null,
      prevailing_workplace_code: null,
      expected_workplaces: null,
      contract_workplace: 'Praha',
      workplace_city: null,
      workplace_municipality_code: '554782',
      profession_code: '2411',
      required_education_code: null,
      position_name: null,
      leadership: null,
    },
    pension: {
      type_code: null,
      received_from: null,
      early_retirement: false,
      reduced_retirement_age: false,
    },
    health_insurance_code: '111',
    facts: {
      highest_education_code: null,
      disability_card: false,
      health_restrictions: [],
    },
    foreign_legislation: { applies: false, country_code: null },
    proof_identity: null,
    foreign_worker: null,
    czech_residence_address: null,
    contact_address: null,
    attachments: [],
  }
}

function a1View(overrides: Record<string, unknown> = {}) {
  return {
    profile: null,
    draft: {
      effective_on: '2026-08-14',
      row_version: 0,
      citizenship_country_code: 'CZ',
      foreigner: false,
      variant: 'OST',
      variant_error: null,
      suggested: a1Suggested(),
      sources: {
        'permanent_address.city': 'Adresa trvalého pobytu osoby.',
        'health_insurance_code': 'Ověřená zdravotní pojišťovna osoby.',
      },
      missing: [
        {
          field: 'permanent_address.house_number',
          message: 'Aplikace vede adresu jedním řádkem včetně čísla.',
        },
      ],
      submitted: false,
      writeback: [],
      diverged: [],
      ...overrides,
    },
  }
}

function insurerInput(wrapper: ReturnType<typeof mountPanel>): HTMLInputElement {
  return wrapper
    .get('[data-test="a1-health-insurance-code"]')
    .get('input[role="combobox"]')
    .element as HTMLInputElement
}

function mountPanel(canWrite = true) {
  return mount(EmploymentRegistrationPanel, {
    props: { employmentId: 5, personId: 9, canWrite },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
        Modal: {
          props: ['title', 'widthClass'],
          template: '<div data-test="modal"><slot /><slot name="footer" /></div>',
        },
      },
    },
  })
}

function preparedSubmission(environment: 'test' | 'production') {
  return {
    submission_id: 12,
    obligation_id: 3,
    part_id: 4,
    artifact_id: 6,
    status: 'ready',
    row_version: 3,
    environment,
    agenda_code: 'PREZEC26',
    interaction: 'limited_pre_registration',
    artifact_sha256: 'b'.repeat(64),
    created: true,
    deadline,
  }
}

function rejection(code: string, message: string) {
  return { response: { status: 422, data: { error: { code, message } } } }
}

const A1_DRAFT_KEY = 'myinvoice.payroll.a1-draft.0.5'

afterEach(() => {
  vi.useRealTimers()
})

describe('EmploymentRegistrationPanel', () => {
  beforeEach(() => {
    // Nabídky JMHZ číselníků se drží v paměti modulu na celý běh aplikace —
    // mezi případy se musí vyprázdnit, jinak druhý test dostane odpověď
    // (nebo `null` po chybě) z toho prvního (viz stejný vzorec u karty
    // pracovního vztahu, EmploymentCard.spec.ts).
    resetPayrollJmhzOptions()
    vi.clearAllMocks()
    localStorage.clear()
    // Vývojová instalace: výběr testovacího prostředí je dostupný.
    setActivePinia(createPinia())
    useAuthStore().submissionTestEnvironmentAllowed = true
    m.current.mockResolvedValue(null)
    m.locale = 'cs'
    vi.stubGlobal('crypto', {
      randomUUID: vi.fn(() => '00000000-0000-4000-8000-000000000001'),
    })
    m.preview.mockResolvedValue(preview)
    m.status.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      attempt: null,
    })
    m.events.mockResolvedValue([])
    m.a1Profile.mockResolvedValue(a1View())
    m.jmhzOptions.mockResolvedValue(jmhzOptions())
  })

  /**
   * Změna pojišťovny na kartě osoby: návrh neřekne jen „podejte jinou
   * cestou", ale vede na hromadné oznámení (HOZ) za měsíc změny, kde vznikne
   * odhláška i přihláška.
   */
  it('u změny zdravotní pojišťovny odkáže na HOZ za měsíc změny', async () => {
    m.detectChanges.mockResolvedValue({
      as_of: '2026-07-02',
      reason_code: null,
      without_baseline: {},
      proposals: [{
        id: 71,
        duty_kind: 'health_insurer_change',
        action_code: null,
        status: 'open',
        detected_on: '2026-07-02',
        due_on: '2026-07-10',
        deadline_source: '§ 10 odst. 1 písm. b) zákona č. 48/1997 Sb.',
        deadline_ruleset_id: 'synthetic',
        findings: [{ path: 'health.insurer_code', group: 'health_insurer' }],
        changes: {},
        unsupported: [],
        fileable: false,
        created: true,
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()

    const hoz = wrapper.get('[data-test="registration-change-health-hoz"]')
    expect(hoz.text()).toContain('payroll.people.registration.changes.health_insurer_hoz')
    const link = wrapper.get('[data-test="registration-change-open-hoz"]')
    expect(JSON.parse(link.attributes('data-to') ?? '{}')).toEqual({
      path: '/payroll/submissions/health',
      query: { period: '2026-07' },
    })
    expect(wrapper.find('[data-test="registration-change-manual"]').exists()).toBe(false)
  })

  it('saves the authoritative A1 profile before preview and prepare', async () => {
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'hire',
      action_code: 1,
    })
    m.prepare.mockResolvedValue({
      submission_id: 14,
      obligation_id: 15,
      part_id: 16,
      artifact_id: 17,
      status: 'ready',
      row_version: 1,
      environment: 'test',
      agenda_code: 'REGZEC25',
      interaction: 'hire',
      artifact_sha256: 'c'.repeat(64),
      created: true,
      deadline,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="a1-permanent-house_number"]').setValue('12')
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      effective_on: '2026-08-14',
      row_version: 0,
      permanent_address: expect.objectContaining({
        house_number: '12',
        city: 'Praha',
      }),
    }))
    // Číslo verze se v hlášce neukazuje — u rozpracovaného profilu žádná
    // historie nevzniká a seznam verzí nikde není.
    const saved = wrapper.get('[data-test="registration-a1-saved"]').text()
    expect(saved).toContain('payroll.people.registration.a1.saved')
    expect(saved).not.toContain('version')

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(5, 'production')
    expect(m.prepare).toHaveBeenCalledWith(5, 'production')
    expect(m.saveA1Profile.mock.invocationCallOrder[0])
      .toBeLessThan(m.preview.mock.invocationCallOrder[0])
    expect(m.preview.mock.invocationCallOrder[0])
      .toBeLessThan(m.prepare.mock.invocationCallOrder[0])
    expect(wrapper.find('[data-test="registration-prepared"]').exists()).toBe(true)
  })

  it('offers the unknown-start deadline for a direct A1 after start and sends the choice', async () => {
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'direct_full_registration',
      action_code: 1,
      before_start_choice: false,
    })
    m.prepare.mockResolvedValue({
      ...preparedSubmission('production'),
      agenda_code: 'REGZEC25',
      interaction: 'direct_full_registration',
    })
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="registration-unknown-start"]').exists()).toBe(false)

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-unknown-start"]').setValue(true)
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenLastCalledWith(5, 'production', null, 'auto', { startNotKnownInAdvance: true })

    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()
    expect(m.prepare).toHaveBeenCalledWith(5, 'production', null, 'auto', { startNotKnownInAdvance: true })
  })

  it('offers full A1 registration before start and sends the chosen mode', async () => {
    m.preview.mockResolvedValueOnce({ ...preview, before_start_choice: true })
    m.preview.mockResolvedValueOnce({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'direct_full_registration',
      action_code: 1,
      before_start_choice: true,
    })
    m.prepare.mockResolvedValue({
      ...preparedSubmission('production'),
      agenda_code: 'REGZEC25',
      interaction: 'direct_full_registration',
      before_start_choice: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="registration-mode"]').exists()).toBe(false)

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenLastCalledWith(5, 'production')
    expect(wrapper.get('[data-test="registration-mode-hint"]').text())
      .toBe('payroll.people.registration.before_start.hint')

    await wrapper.get('[data-test="registration-mode"]').setValue('full')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenLastCalledWith(5, 'production', null, 'full')

    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()
    expect(m.prepare).toHaveBeenCalledWith(5, 'production', null, 'full')
  })

  /**
   * Formulář místo syrového JSONu: hodnoty přijdou předvyplněné ze serveru,
   * u každé je vidět zdroj a co aplikace nevede, se hlásí konkrétně.
   */
  it('prefills the A1 form, names the gaps and shows labelled payload rows', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    expect(wrapper.find('[data-test="registration-a1-json"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="a1-permanent-city"]').element as HTMLInputElement).value)
      .toBe('Praha')
    expect(insurerInput(wrapper).value).toContain('111')
    const missing = wrapper.get('[data-test="registration-a1-missing"]').text()
    // Lidský popisek pole, ne technický klíč (UI-20).
    expect(missing).not.toContain('permanent_address.house_number')
    expect(missing).toContain('payroll.people.registration.a1.section.permanent_address · payroll.people.registration.a1.address.house_number')
    expect(missing).toContain('Aplikace vede adresu jedním řádkem včetně čísla.')
    await wrapper.get('[data-test="registration-a1-payload-toggle"]').trigger('click')

    /** UI-23: „Co odesíláme" je čitelný seznam s popisky, ne syrový JSON. */
    const payload = wrapper.get('[data-test="registration-a1-payload"]')
    expect(payload.element.tagName).toBe('DL')
    expect(payload.text()).not.toContain('"employment"')
    expect(payload.text()).toContain('payroll.people.registration.a1.employment.activity_code')
    expect(payload.text()).toContain('payroll.people.registration.a1.section.permanent_address · payroll.people.registration.a1.address.city')
  })

  function driftedView(submitted: boolean) {
    const drift = [
      {
        field: 'health_insurance_code',
        label: 'Kód zdravotní pojišťovny',
        stored: '201',
        suggested: '111',
        writable: true,
        reason: null,
      },
      {
        field: 'employment.small_scale',
        label: 'Příznak zaměstnání malého rozsahu',
        stored: 'true',
        suggested: 'false',
        writable: false,
        reason: 'Zaměstnání malého rozsahu se v evidenci nevede jako '
          + 'samostatný příznak.',
      },
    ]

    return {
      ...a1View(),
      profile: {
        ...a1Suggested(),
        health_insurance_code: '201',
        row_version: 3,
        reference_hash: 'b'.repeat(64),
        created_at: '2026-08-14 10:00:00',
        created: false,
      },
      draft: {
        ...a1View().draft,
        row_version: 3,
        submitted,
        writeback: drift,
        diverged: submitted ? drift : [],
      },
    }
  }

  it('offers writing the difference back into master data', async () => {
    m.a1Profile.mockResolvedValue(driftedView(false))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    const panel = wrapper.get('[data-test="registration-a1-diverged"]')
    // Lidský název údaje, ne název sloupce.
    expect(panel.text()).toContain('Kód zdravotní pojišťovny')
    expect(panel.text()).not.toContain('health_insurance_code:')
    expect(insurerInput(wrapper).value).toContain('201')
    expect(
      wrapper.find('[data-test="registration-a1-master-data-health_insurance_code"]').exists(),
    ).toBe(true)
    // Údaj, který kmen nevede, tlačítko nedostane — jen větu proč.
    expect(
      wrapper.find('[data-test="registration-a1-master-data-employment.small_scale"]').exists(),
    ).toBe(false)
    expect(panel.text()).toContain('nevede jako samostatný příznak')
    // Rozpracovaný profil nevaruje před rozdílem proti nahlášenému stavu.
    expect(
      wrapper.find('[data-test="registration-a1-submitted-note"]').exists(),
    ).toBe(false)
  })

  it('warns about a change notice only once the registration was filed', async () => {
    m.a1Profile.mockResolvedValue(driftedView(true))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    // `t` je v testu nahrazené klíčem, tak se ověřuje klíč, ne česká věta.
    expect(
      wrapper.get('[data-test="registration-a1-submitted-note"]').text(),
    ).toContain('master_data_submitted_note')
  })

  it('writes the picked value into master data and recomputes the list', async () => {
    m.a1Profile.mockResolvedValue(driftedView(false))
    m.writeA1MasterData.mockResolvedValue({
      written: [
        { field: 'health_insurance_code', label: 'Kód zdravotní pojišťovny', value: '201' },
      ],
      skipped: [
        {
          field: 'employment.small_scale',
          label: 'Příznak zaměstnání malého rozsahu',
          reason: 'Kmenová data tenhle příznak nevedou.',
        },
      ],
      view: a1View(),
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper
      .get('[data-test="registration-a1-master-data-health_insurance_code"]')
      .trigger('click')
    await flushPromises()

    expect(m.writeA1MasterData).toHaveBeenCalledWith(5, ['health_insurance_code'])
    expect(
      wrapper.get('[data-test="registration-a1-master-data-saved"]').text(),
    ).toContain('Kód zdravotní pojišťovny')
    expect(
      wrapper.get('[data-test="registration-a1-master-data-skipped"]').text(),
    ).toContain('Kmenová data tenhle příznak nevedou.')
    // Přepočítaný seznam už zapsaný údaj nenabízí.
    expect(wrapper.find('[data-test="registration-a1-diverged"]').exists()).toBe(false)
  })

  /**
   * Kód pojišťovny se vybírá z číselníku, ne píše rukou — na server ale musí
   * odejít pořád jen ten kód jako řetězec.
   */
  it('sends the insurer code picked from the codebook', async () => {
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      health_insurance_code: '205',
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    const picker = wrapper.get('[data-test="a1-health-insurance-code"]').get('input[role="combobox"]')
    await picker.trigger('focus')
    await picker.setValue('205')
    await picker.trigger('keydown', { key: 'Enter' })

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      health_insurance_code: '205',
    }))
  })

  /**
   * Zaniklá pojišťovna v číselníku není. Našeptávač ji přesto musí ukázat a
   * uložit beze změny — jinak by první otevření karty starý kód tiše smazalo.
   */
  it('keeps a legacy insurer code that is not in the codebook', async () => {
    const legacy = { ...a1Suggested(), health_insurance_code: '999' }
    m.a1Profile.mockResolvedValue({
      profile: null,
      draft: { ...a1View().draft, suggested: legacy },
    })
    m.saveA1Profile.mockResolvedValue({
      ...legacy,
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    expect(insurerInput(wrapper).value).toContain('999')

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      health_insurance_code: '999',
    }))
  })

  it('shows the deadline window and which form will be filed', async () => {
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    const window = wrapper.find('[data-test="registration-deadline"]')
    expect(window.exists()).toBe(true)
    expect(window.text()).toContain('registration.agenda.PREZEC26')
    expect(window.text()).toContain('registration.interaction.limited_pre_registration')
  })

  it('never claims the employee is registered once the filing is prepared', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'ready',
      row_version: 3,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline,
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    const prepared = wrapper.find('[data-test="registration-prepared"]')
    expect(prepared.exists()).toBe(true)
    // „Odesláno != přijato" musí být vidět i v UI.
    expect(prepared.text()).toContain('registration.not_sent_yet')
  })

  it('surfaces the server message naming the missing field', async () => {
    m.preview.mockRejectedValue({
      message: 'Účtárna nemá vyplněný variabilní symbol ČSSZ.',
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-error"]').text())
      .toContain('variabilní symbol')
  })

  it('warns about the employer deadline for the first employee', async () => {
    m.preview.mockResolvedValue({
      ...preview,
      employer_registration: {
        earliest_registration_on: '2026-08-07',
        due_on: '2026-08-20',
        deemed_employer_from: '2026-08-07',
        no_show_notification_due_on: '2026-08-30',
        calendar_basis: 'czech_working_days',
        ruleset_id: 'cz-jmhz-employer-registration-2026-07.v1',
      },
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-employer-deadline"]').exists())
      .toBe(true)
  })

  it('keeps the filing action disabled without write permission', async () => {
    const wrapper = mountPanel(false)
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="registration-prepare"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('sends, fetches the result and closes only after explicit clicks', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'ready',
      row_version: 3,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline,
    })
    m.send.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      payload_sha256: 'b'.repeat(64),
      acknowledgement: { correlation_id: 'CID-1', poll_interval_seconds: 30, gateway_timestamp: null },
      settled: false,
      attempt: { id: 87, status: 'awaiting_protocol', closed_at: null },
    })
    m.poll.mockResolvedValue({
      acknowledgement: null,
      settled: true,
      report: { status: 'ProcessedAndComplete', errors: [] },
      attempt: { id: 87, status: 'completed', closed_at: null },
    })
    m.close.mockResolvedValue({
      closed: true,
      already_closed: false,
      attempt: { id: 87, status: 'completed', closed_at: '2026-08-26 12:00:00' },
    })

    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    const actions = wrapper.get('[data-test="registration-transport-actions"]')
    await actions.get('button').trigger('click')
    await flushPromises()
    expect(m.send).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="production-send-confirm-message"]').text())
      .toContain('payroll.production_send.registration')
    await wrapper.get('[data-test="production-send-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(m.send).toHaveBeenCalledWith(
      12,
      'production',
      '00000000-0000-4000-8000-000000000001',
    )
    expect(m.poll).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="registration-transport-result"]').text())
      .toContain('registration.awaiting_protocol')

    await actions.get('button').trigger('click')
    await flushPromises()
    expect(m.poll).toHaveBeenCalledWith(87, 'production')
    expect(m.close).not.toHaveBeenCalled()

    await actions.get('button').trigger('click')
    await flushPromises()
    expect(m.close).toHaveBeenCalledWith(87, 'production')
    expect(wrapper.get('[data-test="registration-transport-result"]').text())
      .toContain('registration.closed')
  })

  it('does not send to production when the confirmation is cancelled', async () => {
    m.prepare.mockResolvedValue(preparedSubmission('production'))
    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="registration-transport-actions"] button').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="production-send-confirm-no"]').trigger('click')
    await flushPromises()

    expect(m.send).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="production-send-confirm"]').exists()).toBe(false)
  })

  it('sends to the test environment without a production confirmation', async () => {
    m.prepare.mockResolvedValue(preparedSubmission('test'))
    m.send.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      payload_sha256: 'b'.repeat(64),
      acknowledgement: { correlation_id: 'CID-1', poll_interval_seconds: 30, gateway_timestamp: null },
      settled: false,
      attempt: { id: 87, status: 'awaiting_protocol', closed_at: null },
    })
    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-environment"]').setValue('test')
    await flushPromises()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="registration-transport-actions"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="production-send-confirm"]').exists()).toBe(false)
    expect(m.send).toHaveBeenCalledWith(12, 'test', expect.any(String))
  })

  it('defaults to production and uses an explicitly selected test environment', async () => {
    const wrapper = mountPanel()
    const select = wrapper.get('[data-test="registration-environment"]')
    expect((select.element as HTMLSelectElement).value).toBe('production')

    await select.setValue('test')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(5, 'test')
  })

  it('after reload resumes the stored attempt without sending it again', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'submitted',
      row_version: 4,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: false,
      deadline,
    })
    m.status.mockResolvedValue({
      agenda_code: 'PREZEC26',
      submission_class: 'CSSZ_PREZEC',
      attempt: { id: 87, status: 'awaiting_protocol', closed_at: null },
    })
    const wrapper = mountPanel()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    expect(m.status).toHaveBeenCalledWith(12, 'production')
    expect(m.send).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="registration-transport-actions"]').text())
      .toContain('registration.poll')
  })

  it('loads an immutable REGZEC event and uses it for preview and prepare', async () => {
    m.events.mockResolvedValue([{
      id: 91,
      employment_id: 5,
      environment: 'test',
      interaction: 'change',
      action_code: 3,
      effective_on: '2026-08-26',
      source_kind: 'verified_change',
      source_reference: 'personnel-change-18',
      snapshot_fingerprint: 'c'.repeat(64),
      approved_at: '2026-08-26 09:00:00',
      consumed: false,
      created: true,
    }])
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'change',
      action_code: 3,
    })
    m.prepare.mockResolvedValue({
      submission_id: 21,
      obligation_id: 22,
      part_id: 23,
      artifact_id: 24,
      status: 'ready',
      row_version: 1,
      environment: 'test',
      agenda_code: 'REGZEC25',
      interaction: 'change',
      artifact_sha256: 'd'.repeat(64),
      created: true,
      deadline,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-select"]').setValue('91')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenCalledWith(5, 'production', 91)

    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()
    expect(m.prepare).toHaveBeenCalledWith(5, 'production', 91)
  })

  it('offers Prepare again instead of sending a stale REGZEC25 change', async () => {
    m.events.mockResolvedValue([{
      id: 91,
      employment_id: 5,
      environment: 'test',
      interaction: 'change',
      action_code: 3,
      effective_on: '2026-12-01',
      source_kind: 'verified_change',
      source_reference: 'personnel-change-19',
      snapshot_fingerprint: 'c'.repeat(64),
      approved_at: '2026-12-10 09:00:00',
      consumed: true,
      created: false,
    }])
    m.preview.mockResolvedValue({ ...preview, agenda_code: 'REGZEC25', interaction: 'change', action_code: 3 })
    const prepared = {
      obligation_id: 22,
      part_id: 23,
      artifact_id: 24,
      status: 'ready',
      row_version: 1,
      environment: 'production',
      agenda_code: 'REGZEC25',
      interaction: 'change',
      artifact_sha256: 'd'.repeat(64),
      deadline,
    }
    m.prepare
      .mockResolvedValueOnce({ ...prepared, submission_id: 21, created: false, vintage_expired: true })
      .mockResolvedValueOnce({ ...prepared, submission_id: 31, created: true, vintage_expired: false, replaced_submission_id: 21 })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-select"]').setValue('91')
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-vintage-expired"]').exists()).toBe(true)
    const actions = wrapper.get('[data-test="registration-transport-actions"]')
    expect(actions.text()).toContain('registration.reprepare')
    expect(actions.text()).not.toContain('registration.send_production')

    await actions.get('button').trigger('click')
    await flushPromises()

    expect(m.send).not.toHaveBeenCalled()
    expect(m.prepare).toHaveBeenLastCalledWith(5, 'production', 91, 'auto', { replacesSubmissionId: 21 })
    expect(wrapper.find('[data-test="registration-vintage-expired"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="registration-transport-actions"]').text())
      .toContain('registration.send_production')
  })

  it('creates an A5 source, selects it and previews the exact event', async () => {
    const event = {
      id: 92,
      employment_id: 5,
      environment: 'test',
      interaction: 'variable_symbol_transfer',
      action_code: 5,
      effective_on: '2026-08-26',
      source_kind: 'employer_transfer',
      source_reference: 'transfer-decision-4',
      snapshot_fingerprint: 'e'.repeat(64),
      approved_at: '2026-08-26 10:00:00',
      consumed: false,
      created: true,
    }
    m.approveEvent.mockResolvedValue(event)
    m.preview.mockResolvedValue({
      ...preview,
      agenda_code: 'REGZEC25',
      interaction: 'variable_symbol_transfer',
      action_code: 5,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('variable_symbol_transfer')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-08-26')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('transfer-decision-4')
    await wrapper.get('[data-test="registration-event-new-variable-symbol"]').setValue('9990005678')
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      environment: 'production',
      interaction: 'variable_symbol_transfer',
      effective_on: '2026-08-26',
      source_reference: 'transfer-decision-4',
      new_variable_symbol: '9990005678',
    }))
    expect(m.preview).toHaveBeenCalledWith(5, 'production', 92)
  })

  /**
   * Dohlášení údajů (A3) za zaměstnance přihlášeného přes ONZ: jedno kliknutí
   * otevře formulář s dnešním dnem odeslání, rozsah jde přepnout a podání se
   * skládá na serveru z profilu A1 — formulář nic dalšího nevyžaduje.
   */
  it('opens the A3 data supplement with today and sends only the completion scope', async () => {
    m.approveEvent.mockResolvedValue({
      id: 94,
      employment_id: 5,
      environment: 'production',
      interaction: 'change',
      action_code: 3,
      effective_on: '2026-09-26',
      source_kind: 'verified_change',
      source_reference: 'dohlaseni:minimal:2026-09-26',
      snapshot_fingerprint: 'c'.repeat(64),
      approved_at: '2026-09-26 10:00:00',
      consumed: false,
      created: true,
    })
    m.preview.mockResolvedValue({ ...preview, agenda_code: 'REGZEC25', interaction: 'change', action_code: 3 })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-completion-open"]').trigger('click')

    expect(wrapper.find('[data-test="registration-completion-hint"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="registration-event-delta"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="registration-event-source-reference"]').exists()).toBe(false)
    const effective = (wrapper.get('[data-test="registration-event-effective-on"]').element as HTMLInputElement)
    // Den odeslání je předvyplněný (DateInput ukazuje datum česky).
    expect(effective.value).not.toBe('')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-09-26')
    await wrapper.get('[data-test="registration-event-change-scope"]').setValue('minimal')
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'change',
      effective_on: '2026-09-26',
      completion: 'minimal',
      source_reference: 'dohlaseni:minimal:2026-09-26',
    }))
    expect(m.approveEvent.mock.calls[0][1]).not.toHaveProperty('changes')
  })

  /** Překryv se stejným druhem činnosti: varování s proklikem na oba vztahy. */
  it('shows overlap warnings with links to both relationships', async () => {
    m.a1Profile.mockResolvedValue({
      ...a1View(),
      warnings: [{
        code: 'registration_overlap_same_activity',
        field: 'employment.activity_code',
        employment_id: 77,
        message: 'Zaměstnanec má u firmy další pracovní vztah.',
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    const box = wrapper.get('[data-test="registration-warnings"]')
    expect(box.text()).toContain('Zaměstnanec má u firmy další pracovní vztah.')
    expect(JSON.parse(box.get('[data-test="registration-warning-open-other"]').attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-people', query: { employment: '77', panel: 'employment_terms', person: '9' } })
    expect(JSON.parse(box.get('[data-test="registration-warning-open-own"]').attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-people', query: { employment: '5', panel: 'employment_terms', person: '9' } })
  })

  /** Práce probíhá převážně (10258) jen na chráněném trhu práce u zdravotního omezení. */
  it('hides the prevailing workplace field unless the protected labour market applies', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="a1-employment-prevailing-workplace-code"]').exists()).toBe(false)

    const suggested = a1Suggested()
    suggested.facts.health_restrictions = [{ type_code: '1', from: '2025-01-01', to: null }] as never
    m.a1Profile.mockResolvedValue(a1View({ protected_labor_market: true, suggested }))
    const protectedPanel = mountPanel()
    await flushPromises()
    await protectedPanel.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()
    expect(protectedPanel.find('[data-test="a1-employment-prevailing-workplace-code"]').exists()).toBe(true)
  })

  it('requires an explicit no-show confirmation for A8 and binds the source submission', async () => {
    m.approveEvent.mockResolvedValue({
      id: 93,
      employment_id: 5,
      environment: 'test',
      interaction: 'cancellation',
      action_code: 8,
      effective_on: '2026-08-20',
      source_kind: 'verified_cancellation',
      source_reference: 'no-show-record-1',
      snapshot_fingerprint: 'f'.repeat(64),
      approved_at: '2026-08-26 11:00:00',
      consumed: false,
      created: true,
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('cancellation')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-08-20')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('no-show-record-1')
    await wrapper.get('[data-test="registration-event-source-submission-id"]').setValue('44')

    expect(wrapper.get('[data-test="registration-event-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="registration-event-not-started"]').setValue(true)
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'cancellation',
      source_submission_id: 44,
      not_started: true,
    }))
  })

  it('links an A3 proposal blocked by a missing house number to the A1 profile', async () => {
    m.detectChanges.mockResolvedValue({
      as_of: '2026-11-03',
      reason_code: null,
      without_baseline: {},
      proposals: [{
        id: 41,
        duty_kind: 'regzec_change',
        action_code: 3,
        status: 'open',
        detected_on: '2026-11-03',
        due_on: '2026-11-11',
        deadline_source: '§ 19 odst. 5 zákona č. 323/2025 Sb.',
        deadline_ruleset_id: 'cz-regzec-follow-up-2026-04.v1',
        findings: [{ path: 'permanent_address.street', group: 'permanent_address', action_code: 3, sensitive: false, from: 'Dlouhá', to: 'Nová 5' }],
        changes: { health_insurance_code: '211' },
        unsupported: [
          { path: 'permanent_address', reason_code: 'registration_change_permanent_address_incomplete' },
        ],
        fileable: false,
        created: true,
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()

    const gaps = wrapper.get('[data-test="registration-change-profile-gaps"]')
    expect(gaps.text()).toContain(
      'payroll.people.registration.changes.gap.registration_change_permanent_address_incomplete',
    )
    expect(wrapper.find('[data-test="registration-change-manual"]').exists()).toBe(false)
    await wrapper.get(
      '[data-test="registration-change-open-profile-registration_change_permanent_address_incomplete"]',
    ).trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-a1-field="permanent_address.house_number"]').exists()).toBe(true)
  })

  it('files a detected change with the suggested validity date', async () => {
    m.detectChanges.mockResolvedValue({
      as_of: '2026-11-20',
      reason_code: null,
      without_baseline: {},
      proposals: [{
        id: 52,
        duty_kind: 'regzec_change',
        action_code: 3,
        status: 'open',
        detected_on: '2026-11-20',
        effective_on: '2026-11-01',
        due_on: '2026-11-28',
        deadline_source: '§ 19 odst. 5 zákona č. 323/2025 Sb.',
        deadline_ruleset_id: 'cz-regzec-follow-up-2026-04.v1',
        findings: [{ path: 'identity.last_name', group: 'identity', action_code: 3, sensitive: false, from: 'Registrační', to: 'Nová' }],
        changes: { identity: { first_name: 'Petra', last_name: 'Nová' } },
        unsupported: [],
        fileable: true,
        created: true,
      }],
    })
    m.fileChange.mockResolvedValue({ event: { id: 9 }, proposal_id: 52 })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="registration-change-effective"]').exists()).toBe(true)
    const file = wrapper.findAll('button').find(
      button => button.text() === 'payroll.people.registration.changes.file',
    )
    expect(file).toBeDefined()
    await file!.trigger('click')
    await flushPromises()

    expect(m.fileChange).toHaveBeenCalledWith(5, 52, 'production', '2026-11-01')
  })

  it('sends the explicit ONZ identifier verification with the A2 deregistration', async () => {
    m.approveEvent.mockResolvedValue({
      id: 95,
      employment_id: 5,
      environment: 'production',
      interaction: 'termination',
      action_code: 2,
      effective_on: '2026-09-15',
      source_kind: 'employment_exit',
      source_reference: 'termination',
      snapshot_fingerprint: 'f'.repeat(64),
      approved_at: '2026-09-15 11:00:00',
      consumed: false,
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-09-15')
    expect(wrapper.get('[data-test="registration-a2-identifiers-verified-box"]').text())
      .toContain('payroll.people.registration.event.identifiers_verified_hint')
    await wrapper.get('[data-test="registration-a2-identifiers-verified"]').setValue(true)
    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()

    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'termination',
      identifiers_verified_in_cssz_list: true,
    }))
  })

  it('files A8 for another reason only with an explanation attachment', async () => {
    m.approveEvent.mockResolvedValue({
      id: 94,
      employment_id: 5,
      environment: 'production',
      interaction: 'cancellation',
      action_code: 8,
      effective_on: '2026-09-20',
      source_kind: 'verified_cancellation',
      source_reference: 'wrong-vs-1',
      snapshot_fingerprint: 'f'.repeat(64),
      approved_at: '2026-09-20 11:00:00',
      consumed: false,
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('cancellation')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-09-20')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('wrong-vs-1')
    await wrapper.get('[data-test="registration-event-source-submission-id"]').setValue('44')
    await wrapper.get('[data-test="registration-event-a8-reason"]').setValue('other')

    expect(wrapper.find('[data-test="registration-event-not-started"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="registration-event-a8-other-hint"]').text())
      .toBe('payroll.people.registration.event.a8_other_hint')
    expect(wrapper.get('[data-test="registration-event-save"]').attributes('disabled')).toBeDefined()

    const input = wrapper.get('[data-test="registration-event-a8-attachment"]')
    const file = new File(['%PDF'], 'zduvodneni.pdf', { type: 'application/pdf' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flushPromises()
    expect(wrapper.get('[data-test="registration-event-a8-attachment-name"]').text()).toBe('zduvodneni.pdf')

    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()
    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'cancellation',
      source_submission_id: 44,
      not_started: false,
      explanation_attachment: {
        name: 'zduvodneni.pdf',
        description: null,
        data_base64: btoa('%PDF'),
      },
    }))
  })

  /** REGZEC25-PROC.A1.prereg-combos-01 (d): oprava skutečného nástupu A4 jen s průvodním dopisem. */
  it('files A4 correction of the actual start only with a cover letter', async () => {
    m.approveEvent.mockResolvedValue({
      id: 95,
      employment_id: 5,
      environment: 'production',
      interaction: 'correction',
      action_code: 4,
      effective_on: '2026-09-20',
      source_kind: 'verified_correction',
      source_reference: 'start-1',
      snapshot_fingerprint: 'f'.repeat(64),
      approved_at: '2026-09-20 11:00:00',
      consumed: false,
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-event-interaction"]').setValue('correction')
    await wrapper.get('[data-test="registration-event-effective-on"]').setValue('2026-09-20')
    await wrapper.get('[data-test="registration-event-source-reference"]').setValue('start-1')
    await wrapper.get('[data-test="registration-event-source-submission-id"]').setValue('44')
    await wrapper.get('[data-test="registration-event-discovered-on"]').setValue('2026-09-21')
    await wrapper.get('[data-test="registration-event-delta-field"]').setValue('actual_start_on')
    await wrapper.get('[data-test="registration-event-delta-actual-start"]').setValue('2026-09-22')

    expect(wrapper.get('[data-test="registration-event-a4-start-hint"]').text())
      .toBe('payroll.people.registration.event.a4_start_hint')
    expect(wrapper.get('[data-test="registration-event-save"]').attributes('disabled')).toBeDefined()

    const input = wrapper.get('[data-test="registration-event-a4-attachment"]')
    const file = new File(['%PDF'], 'dopis.pdf', { type: 'application/pdf' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await flushPromises()

    await wrapper.get('[data-test="registration-event-save"]').trigger('click')
    await flushPromises()
    expect(m.approveEvent).toHaveBeenCalledWith(5, expect.objectContaining({
      interaction: 'correction',
      corrections: { employment: { actual_start_on: '2026-09-22' } },
      explanation_attachment: { name: 'dopis.pdf', description: null, data_base64: btoa('%PDF') },
    }))
  })

  /**
   * Důvod skončení se zadává jednou na kartě vztahu; odhláška A2 si ho odsud
   * předvyplní i s čistým průměrem a odstupným (DIS přijme odstupné jen
   * u důvodu 4 nebo 5).
   */
  it('prefills the A2 unemployment data from the termination record', async () => {
    const wrapper = mount(EmploymentRegistrationPanel, {
      props: {
        employmentId: 5,
        personId: 9,
        canWrite: true,
        a2Prefill: {
          ended_by_death: false,
          unemployment: {
            mode: 'provided',
            employment_type: '1',
            termination_reason: '4',
            average_net_earnings: '28150',
            entitlement: true,
            settlement_kind: 'golden_handshake',
            settlement_amount: '130440',
          },
        },
      },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')

    await wrapper.get('[data-test="registration-a2-prefill-button"]').trigger('click')
    await flushPromises()

    expect((wrapper.get('[data-test="registration-a2-termination-reason"]').element as HTMLInputElement).value).toBe('4')
    expect((wrapper.get('[data-test="registration-a2-settlement-kind"]').element as HTMLSelectElement).value).toBe('golden_handshake')
    expect((wrapper.get('[data-test="registration-a2-settlement-amount"]').element as HTMLInputElement).value).toBe('130440')
  })

  /*
   * C-19: předvyplnění mlčky vynechalo den skončení i odpověď „ne" na úmrtí
   * (kterou varianta OST vyžaduje) a čistý průměr zůstal prázdný bez vysvětlení.
   */
  it('prefills the A2 end date and death answer and explains a missing net average', async () => {
    const wrapper = mount(EmploymentRegistrationPanel, {
      props: {
        employmentId: 5,
        personId: 9,
        canWrite: true,
        terminationEndDate: '2026-10-31',
        a2Prefill: {
          ended_by_death: false,
          average_net_note: 'Zaměstnanec uplatňuje daňové zvýhodnění na dítě.',
          unemployment: {
            mode: 'provided',
            employment_type: '1',
            termination_reason: '4',
            average_net_earnings: null,
          },
        },
      },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')

    await wrapper.get('[data-test="registration-a2-prefill-button"]').trigger('click')
    await flushPromises()

    expect((wrapper.get('[data-test="registration-event-effective-on"]').element as HTMLInputElement).value)
      .toBe('31. 10. 2026')
    expect((wrapper.get('[data-test="registration-a2-ended-by-death"]').element as HTMLSelectElement).value).toBe('no')
    expect(wrapper.find('[data-test="registration-a2-net-average-note"]').exists()).toBe(true)
  })

  async function openA2Prefilled(endDate: string, employmentType: '1' | '2') {
    const wrapper = mount(EmploymentRegistrationPanel, {
      props: {
        employmentId: 5,
        personId: 9,
        canWrite: true,
        terminationEndDate: endDate,
        a2Prefill: {
          ended_by_death: false,
          unemployment: {
            mode: 'provided',
            employment_type: employmentType as '1',
            termination_reason: '4',
            average_net_earnings: '28150',
          },
        },
      },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    await wrapper.get('[data-test="registration-a2-prefill-button"]').trigger('click')
    await flushPromises()
    return wrapper
  }

  it('offers the 2027 service relations and their termination reasons only from 1 January 2027', async () => {
    const before = await openA2Prefilled('2026-12-31', '2')
    expect(before.get('[data-test="registration-a2-employment-type"]').findAll('option').map(o => o.element.value))
      .toEqual(['omit', '1', '2'])
    expect(before.get('[data-test="registration-a2-termination-reason"]').element.tagName).toBe('INPUT')

    const wrapper = await openA2Prefilled('2027-01-15', '2')
    const type = wrapper.get('[data-test="registration-a2-employment-type"]')
    expect(type.findAll('option').map(o => o.element.value)).toEqual(['omit', '1', '2', '3', '4'])

    await type.setValue('3')
    const reason = wrapper.get('[data-test="registration-a2-termination-reason"]')
    expect(reason.element.tagName).toBe('SELECT')
    expect(reason.findAll('option').map(o => o.element.value)).toEqual(['', '5', '7'])

    await type.setValue('4')
    expect(wrapper.get('[data-test="registration-a2-termination-reason"]').findAll('option').map(o => o.element.value))
      .toEqual(['', '5', '15'])
  })

  it('softly warns about state employees ending in a civil-service relation from 1 November 2026', async () => {
    const early = await openA2Prefilled('2026-10-31', '2')
    expect(early.find('[data-test="registration-a2-state-employee-warning"]').exists()).toBe(false)
    expect(early.find('[data-test="registration-a2-state-employee-hint"]').exists()).toBe(true)

    const later = await openA2Prefilled('2026-11-15', '2')
    expect(later.find('[data-test="registration-a2-state-employee-warning"]').exists()).toBe(true)

    const employment = await openA2Prefilled('2026-11-15', '1')
    expect(employment.find('[data-test="registration-a2-state-employee-warning"]').exists()).toBe(false)

    const firstDay = await openA2Prefilled('2026-11-01', '2')
    expect(firstDay.find('[data-test="registration-a2-state-employee-warning"]').exists()).toBe(true)
    await firstDay.get('[data-test="registration-a2-termination-reason"]').setValue('5')
    expect(firstDay.find('[data-test="registration-a2-state-employee-warning"]').exists()).toBe(false)
  })

  it('shows the election commission hint only for activity type 13', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="a1-employment-activity-code"]').setValue('1')
    expect(wrapper.find('[data-test="a1-election-commission-hint"]').exists()).toBe(false)
    await wrapper.get('[data-test="a1-employment-activity-code"]').setValue('13')
    expect(wrapper.find('[data-test="a1-election-commission-hint"]').exists()).toBe(true)
  })

  async function openForeignA1(startOn: string) {
    const suggested = a1Suggested()
    m.a1Profile.mockResolvedValue(a1View({
      foreigner: true,
      citizenship_country_code: 'UA',
      suggested: {
        ...suggested,
        employment: { ...suggested.employment, actual_start_on: startOn },
        foreign_worker: {
          free_access: true,
          free_access_reason_code: null,
          permit_type_code: null,
          permit_identifier: null,
          permit_from: null,
          permit_to: null,
          issuing_labour_office_code: null,
        },
      },
    }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()
    const reasons = wrapper.get('[data-test="a1-foreign-worker-free-access-reason-code"]')
    await reasons.get('input[role="combobox"]').trigger('focus')
    return reasons
  }

  it('offers free-access reasons 22 and 23 only for a start from 1 January 2027', async () => {
    const before = await openForeignA1('2026-12-31')
    expect(before.text()).toContain('Občan EU/EHP')
    expect(before.text()).not.toContain('zvláštní dlouhodobý pobyt')

    const after = await openForeignA1('2027-01-04')
    expect(after.text()).toContain('zvláštní dlouhodobý pobyt')
    expect(after.text()).toContain('vyslání zaměstnavatelem')
  })

  it('offers employment status codes from the ČSÚ codebook for a start from 1 January 2027', async () => {
    const statusCodes = async (startOn: string) => {
      const suggested = a1Suggested()
      m.a1Profile.mockResolvedValue(a1View({
        suggested: { ...suggested, employment: { ...suggested.employment, actual_start_on: startOn } },
      }))
      const wrapper = mountPanel()
      await flushPromises()
      await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
      await flushPromises()
      return wrapper.get('[data-test="a1-employment-status-code"]').findAll('option')
        .map(option => option.attributes('value'))
    }

    const before = await statusCodes('2026-12-31')
    expect(before).toContain('1341')
    expect(before).not.toContain('2219')

    const after = await statusCodes('2027-01-04')
    expect(after).toContain('2219')
    expect(after).not.toContain('1341')
  })

  it('keeps the A2 prefill disabled without a termination record', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')

    expect((wrapper.get('[data-test="registration-a2-prefill-button"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('exposes guided fields for every REGZEC interaction A2 through A8', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    const interaction = wrapper.get('[data-test="registration-event-interaction"]')

    expect(wrapper.find('[data-test="registration-event-a2"]').exists()).toBe(true)

    await interaction.setValue('change')
    expect(wrapper.find('[data-test="registration-event-delta"]').exists()).toBe(true)

    await interaction.setValue('correction')
    expect(wrapper.find('[data-test="registration-event-source-submission-id"]').exists()).toBe(true)

    await interaction.setValue('variable_symbol_transfer')
    expect(wrapper.find('[data-test="registration-event-new-variable-symbol"]').exists()).toBe(true)

    await interaction.setValue('czech_legislation_start')
    expect(wrapper.find('[data-test="registration-event-foreign-insurance"]').exists()).toBe(true)

    await interaction.setValue('czech_legislation_end')
    expect(wrapper.find('[data-test="registration-event-foreign-insurance"]').exists()).toBe(true)

    await interaction.setValue('cancellation')
    expect(wrapper.find('[data-test="registration-event-a8"]').exists()).toBe(true)
  })

  /**
   * UX: cizinec s uplatněnou slevou potřebuje dvoukrokovou registraci (akce 1
   * neumí atribut 10459) a změna se u ČSSZ projeví se zpožděním (chyba 40243
   * u JMHZ odeslaného dřív). Účetní to musí vidět dopředu u téhle změny, ne až
   * po zamítnutí.
   */
  it('shows the two-step and latency hints when editing the tax residency delta', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-event-new"]').trigger('click')
    const interaction = wrapper.get('[data-test="registration-event-interaction"]')
    await interaction.setValue('change')

    const deltaField = wrapper.get('[data-test="registration-event-delta"] select')
    await deltaField.setValue('tax_residency')

    expect(wrapper.get('[data-test="registration-event-tax-residency-two-step-hint"]').text())
      .toContain('payroll.people.registration.event.tax_residency_two_step_hint')
    expect(wrapper.get('[data-test="registration-event-tax-residency-latency-hint"]').text())
      .toContain('payroll.people.registration.event.tax_residency_latency_hint')
  })

  /**
   * Číselníková pole A1 (druh činnosti, typ daňového identifikátoru, stát)
   * se vybírají z připnutých JMHZ číselníků, ne píší rukou — a odesílá se
   * pořád jen zvolený kód jako řetězec (stejná záruka jako u pojišťovny).
   */
  it('sends codes picked from the JMHZ codebooks for the A1 profile', async () => {
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="a1-employment-activity-code"]').setValue('1')
    await wrapper.get('[data-test="a1-tax-residency-identifier-type"]').setValue('R')

    const country = wrapper.get('[data-test="a1-permanent-country_code"]').get('input[role="combobox"]')
    await country.trigger('focus')
    await country.setValue('Slovensko')
    await country.trigger('keydown', { key: 'Enter' })

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      employment: expect.objectContaining({ activity_code: '1' }),
      tax_residency: expect.objectContaining({ identifier_type: 'R' }),
      permanent_address: expect.objectContaining({ country_code: 'SK' }),
    }))
  })

  /**
   * EDV 1.4.0.6, ID 10092 a 10099: u druhu činnosti „N“ je cizozemský nositel
   * pojištění povinný. Oddíl je ve formuláři varianty OST vždy a posílá se až
   * po vyplnění; prázdný oddíl server bere jako nevyplněný.
   */
  it('lets the accountant fill in the foreign insurance carrier of an A1-OST profile', async () => {
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="a1-foreign-insurance-current"]').setValue('S')
    await wrapper.get('[data-test="a1-foreign-insurance-name"]').setValue('Syntetická pojišťovna')
    const country = wrapper
      .get('[data-test="a1-foreign-insurance-country-code"]')
      .get('input[role="combobox"]')
    await country.trigger('focus')
    await country.setValue('Slovensko')
    await country.trigger('keydown', { key: 'Enter' })
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      foreign_insurance: expect.objectContaining({
        current: 'S',
        name: 'Syntetická pojišťovna',
        country_code: 'SK',
        street: null,
      }),
    }))
  })

  it('does not show the foreign insurance carrier for the limited A1-10 variant', async () => {
    m.a1Profile.mockResolvedValue(a1View({ variant: '10' }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="a1-foreign-insurance-current"]').exists()).toBe(false)
  })

  /**
   * Historický kód státu mimo číselník (starší podklad, změna hranic apod.)
   * se nesmí tiše ztratit hned při prvním otevření karty.
   */
  it('keeps a legacy country code that is not in the codebook', async () => {
    const legacy = { ...a1Suggested() }
    legacy.permanent_address = { ...legacy.permanent_address, country_code: 'XX' }
    m.a1Profile.mockResolvedValue({
      profile: null,
      draft: { ...a1View().draft, suggested: legacy },
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    const country = wrapper.get('[data-test="a1-permanent-country_code"]').get('input[role="combobox"]')
    expect((country.element as HTMLInputElement).value).toContain('XX')
  })

  /**
   * Kód obce pracoviště se hledá stejným našeptávačem jako na kartě vztahu
   * (searchJmhzMunicipalities) — výběr zároveň doplní i název obce.
   */
  it('picks the workplace municipality from the search codebook', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    m.searchMunicipalities.mockResolvedValue([
      { code: '554791', label: 'Neratovice' },
    ])
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    const municipality = wrapper
      .get('[data-test="a1-employment-workplace-municipality-code"]')
      .get('input[role="combobox"]')
    await municipality.trigger('focus')
    await municipality.setValue('Neratovice')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(m.searchMunicipalities).toHaveBeenCalledWith('Neratovice')
    await municipality.trigger('keydown', { key: 'Enter' })

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      employment: expect.objectContaining({
        workplace_municipality_code: '554791',
        workplace_city: 'Neratovice',
      }),
    }))
  })

  /**
   * Postavení v zaměstnání je výběr čtyřmístných kódů NKPZ — ČSSZ kratší
   * kód nepřijme a všechny přijaté přihlášky z cizích programů nesou čtyři
   * číslice. Uložený kód mimo číselník se nesmí tiše ztratit.
   */
  it('offers four-digit employment status codes and keeps an unknown stored code', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await flushPromises()

    const field = wrapper.get('[data-test="a1-employment-status-code"]')
    expect(field.element.tagName).toBe('SELECT')
    const values = field.findAll('option').map(option => option.attributes('value'))
    expect(values).toContain('1111')
    const codes = values.filter((value): value is string => value !== undefined && value !== '')
    expect(codes.length).toBeGreaterThan(0)
    expect(codes.every(value => /^\d{4}$/.test(value))).toBe(true)
    expect(wrapper.text()).toContain('employment_status_code_hint')
  })

  /**
   * Uložení je všechno nebo nic. Odmítnutí proto nesmí vypadat jako drobnost
   * u tlačítka na konci stovky polí — a hlavně nesmí spolknout rozdělanou
   * práci ani zamlčet, kde se chybějící údaj zadává.
   */
  it('keeps the filled form and points at the person card when the save is rejected', async () => {
    m.saveA1Profile.mockRejectedValue(rejection(
      'registration_regzec_a1_required_field_missing',
      'Pro REGZEC A1 chybí státní občanství (citizenship_country_code).',
    ))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="a1-permanent-house_number"]').setValue('12')
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    const panel = wrapper.get('[data-test="registration-a1-error"]')
    expect(panel.attributes('role')).toBe('alert')
    expect(panel.text()).toContain('chybí státní občanství')
    expect(panel.text()).toContain('a1.error_kept')
    expect(
      (wrapper.get('[data-test="a1-permanent-house_number"]').element as HTMLInputElement).value,
    ).toBe('12')

    const link = wrapper.get('[data-test="registration-a1-person-link"]')
    expect(JSON.parse(link.attributes('data-to') ?? '{}')).toEqual({
      name: 'payroll-people',
      query: { employment: '5', panel: 'statutory_evidence', person: '9' },
    })
  })

  /**
   * Konflikt verzí se na kartě osoby doplnit nedá — odkaz by účetní poslal
   * na špatné místo.
   */
  it('omits the person card link for errors that are not about person data', async () => {
    m.saveA1Profile.mockRejectedValue(rejection(
      'registration_regzec_a1_profile_conflict',
      'Profil mezitím uložil někdo jiný.',
    ))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="registration-a1-error"]').text())
      .toContain('Profil mezitím uložil někdo jiný.')
    expect(wrapper.find('[data-test="registration-a1-person-link"]').exists()).toBe(false)
  })

  /**
   * Doplnit chybějící údaj znamená odejít z karty vztahu — ta se odmontuje
   * i s formulářem. Rozdělaná práce proto musí přežít v prohlížeči a po
   * návratu se sama nabídnout zpátky.
   */
  it('offers the unsaved form back after the card is reopened', async () => {
    m.saveA1Profile.mockRejectedValue(rejection(
      'registration_regzec_a1_required_field_missing',
      'Pro REGZEC A1 chybí státní občanství (citizenship_country_code).',
    ))
    const first = mountPanel()
    await flushPromises()
    await first.get('[data-test="registration-a1-toggle"]').trigger('click')
    await first.get('[data-test="a1-permanent-house_number"]').setValue('12')
    await first.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()
    first.unmount()

    expect(localStorage.getItem(A1_DRAFT_KEY)).not.toBeNull()

    const second = mountPanel()
    await flushPromises()
    await second.get('[data-test="registration-a1-toggle"]').trigger('click')

    expect(second.find('[data-test="registration-a1-local-draft"]').exists()).toBe(true)
    expect(
      (second.get('[data-test="a1-permanent-house_number"]').element as HTMLInputElement).value,
    ).toBe('')

    await second.get('[data-test="registration-a1-draft-restore"]').trigger('click')
    await flushPromises()

    expect(
      (second.get('[data-test="a1-permanent-house_number"]').element as HTMLInputElement).value,
    ).toBe('12')
    expect(second.find('[data-test="registration-a1-local-draft"]').exists()).toBe(false)
  })

  it('drops the browser copy once the profile is saved', async () => {
    m.saveA1Profile
      .mockRejectedValueOnce(rejection(
        'registration_regzec_a1_required_field_missing',
        'Pro REGZEC A1 chybí státní občanství (citizenship_country_code).',
      ))
      .mockResolvedValue({
        ...a1Suggested(),
        row_version: 1,
        reference_hash: 'a'.repeat(64),
        created_at: '2026-08-14 10:00:00',
        created: true,
      })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="a1-permanent-house_number"]').setValue('12')
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()
    expect(localStorage.getItem(A1_DRAFT_KEY)).not.toBeNull()

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(localStorage.getItem(A1_DRAFT_KEY)).toBeNull()
    expect(wrapper.find('[data-test="registration-a1-error"]').exists()).toBe(false)
  })

  it('discards the browser copy on request', async () => {
    localStorage.setItem(A1_DRAFT_KEY, JSON.stringify({
      saved_at: '2026-08-14T10:00:00.000Z',
      payload: { ...a1Suggested(), permanent_address: { ...a1Suggested().permanent_address, house_number: '12' } },
    }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-draft-discard"]').trigger('click')

    expect(localStorage.getItem(A1_DRAFT_KEY)).toBeNull()
    expect(wrapper.find('[data-test="registration-a1-local-draft"]').exists()).toBe(false)
  })

  /**
   * V soukromém režimu prohlížeče `localStorage` vyhazuje. Záloha je bonus,
   * takže z toho nesmí spadnout ani samotné hlášení o odmítnutém uložení.
   */
  it('survives localStorage being unavailable', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
      .mockImplementation(() => { throw new Error('QuotaExceededError') })
    const getItem = vi.spyOn(Storage.prototype, 'getItem')
      .mockImplementation(() => { throw new Error('SecurityError') })
    m.saveA1Profile.mockRejectedValue(rejection(
      'registration_regzec_a1_required_field_missing',
      'Pro REGZEC A1 chybí státní občanství (citizenship_country_code).',
    ))
    try {
      const wrapper = mountPanel()
      await flushPromises()
      await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
      await wrapper.get('[data-test="a1-permanent-house_number"]').setValue('12')
      await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="registration-a1-error"]').text())
        .toContain('chybí státní občanství')
      expect(
        (wrapper.get('[data-test="a1-permanent-house_number"]').element as HTMLInputElement).value,
      ).toBe('12')
    } finally {
      setItem.mockRestore()
      getItem.mockRestore()
    }
  })

  /**
   * Jádro celé věci: uložení projde i s prázdným povinným polem. Formulář má
   * přes stovku polí a část se dopisuje na kartě osoby — odmítnutý zápis by
   * hodinu práce nechal jen v prohlížeči.
   */
  it('stores an incomplete profile and marks the offending fields red', async () => {
    m.saveA1Profile.mockResolvedValue({
      ...a1Suggested(),
      row_version: 1,
      reference_hash: 'a'.repeat(64),
      created_at: '2026-08-14 10:00:00',
      created: true,
      status: 'draft',
      problems: [
        {
          field: 'facts.highest_education_code',
          code: 'registration_regzec_a1_required_field_missing',
          message: 'Pro REGZEC A1 chybí nejvyšší dosažené vzdělání.',
        },
      ],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="registration-a1-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="registration-a1-saved"]').text())
      .toContain('saved_draft')
    expect(wrapper.get('[data-test="registration-a1-problems"]').text())
      .toContain('facts.highest_education_code')
    expect(wrapper.get('[data-test="a1-facts-highest-education-code"]').classes())
      .toContain('border-danger-500')
  })

  /** Kontrola označí pole, ale nic neuloží. */
  it('checks the profile without saving it', async () => {
    m.checkA1Profile.mockResolvedValue({
      complete: false,
      problems: [
        {
          field: 'employment.position_name',
          code: 'registration_regzec_a1_required_field_missing',
          message: 'Pro REGZEC A1 chybí název pracovní pozice.',
        },
      ],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-check"]').trigger('click')
    await flushPromises()

    expect(m.saveA1Profile).not.toHaveBeenCalled()
    expect(m.checkA1Profile).toHaveBeenCalledWith(5, expect.objectContaining({
      row_version: 0,
    }))
    expect(wrapper.get('[data-test="registration-a1-problems"]').text())
      .toContain('employment.position_name')
    expect(wrapper.get('[data-test="a1-employment-position-name"]').classes())
      .toContain('border-danger-500')
    expect(wrapper.get('[data-test="a1-permanent-city"]').classes())
      .not.toContain('border-danger-500')

    // Červený rám u pole nestačí, když je pole o dvě obrazovky níž — nález
    // z Kontroly musí umět doskočit stejně jako chybějící kmenový údaj.
    routerPush.mockClear()
    await wrapper
      .get('[data-test="registration-a1-problem-link-employment.position_name"]')
      .trigger('click')
    await flushPromises()
    expect(routerPush).not.toHaveBeenCalled()
    expect(wrapper.find('[data-a1-field="employment.position_name"]').exists()).toBe(true)
  })

  /**
   * Žlutý seznam „Co aplikace o osobě nevede" musí NAVIGOVAT, ne popisovat
   * cestu slovy. Dřív tu byly dvě varianty a jedna z nich („vyplňuje se zde")
   * nebyla klikací — vypadala jako akce a nedělala nic. Teď je tlačítko vždy
   * a rozhoduje se až při kliknutí: co je na tomhle formuláři, se vysvítí
   * tady; co tu není, otevře kartu osoby s povelem na konkrétní pole.
   */
  it('navigates every gap to the field where it is entered', async () => {
    m.a1Profile.mockResolvedValue(a1View({
      missing: [
        {
          field: 'identity.citizenship_country_code',
          message: 'Osoba nemá k rozhodnému dni státní občanství.',
        },
        {
          field: 'employment.position_name',
          message: 'Aplikace nevede název pracovní pozice.',
        },
        {
          field: 'permanent_address.house_number',
          message: 'Aplikace vede adresu jedním řádkem včetně čísla.',
        },
      ],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    const gap = (field: string) =>
      wrapper.get(`[data-test="registration-a1-gap-link-${field}"]`)

    // Každá položka má klikací povel — žádná mrtvá cedulka.
    for (const field of [
      'identity.citizenship_country_code',
      'employment.position_name',
      'permanent_address.house_number',
    ]) {
      expect(gap(field).element.tagName).toBe('BUTTON')
    }

    // Pole, která na formuláři jsou, musí být adresovatelná pro doskok.
    expect(wrapper.find('[data-a1-field="employment.position_name"]').exists()).toBe(true)
    expect(wrapper.find('[data-a1-field="permanent_address.house_number"]').exists()).toBe(true)

    // Občanství se tu nevyplňuje — teprve to otevře kartu osoby, a to rovnou
    // na konkrétní pole, ne jen na sekci.
    routerPush.mockClear()
    await gap('identity.citizenship_country_code').trigger('click')
    await flushPromises()
    expect(routerPush).toHaveBeenCalledWith({
      name: 'payroll-people',
      query: {
        employment: '5',
        panel: 'registration_identity',
        field: 'identity.citizenship_country_code',
        person: '9',
      },
    })

    // Co je na formuláři, nikam neodnavigovává.
    routerPush.mockClear()
    await gap('employment.position_name').trigger('click')
    await flushPromises()
    expect(routerPush).not.toHaveBeenCalled()
  })

  /**
   * Seznam „doplňte ručně" vzniká při sestavení návrhu a mluví o tom, co se
   * NEDÁ předvyplnit z kmenových dat. Vypisoval se ale dál i potom, co
   * uživatel ta pole vyplnil — takže vedle sebe svítilo jedenáct „chybí"
   * a zelené „Kontrola prošla, 0 položek brání podání". Obojí byla pravda
   * o něčem jiném a dohromady to nedávalo smysl.
   */
  it('vypustí ze seznamu položky, které už jsou ve formuláři vyplněné', async () => {
    m.a1Profile.mockResolvedValue(a1View({
      missing: [
        { field: 'employment.position_name', message: 'Aplikace nevede název pozice.' },
        { field: 'employment.workplace_city', message: 'Aplikace nevede obec pracoviště.' },
      ],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    expect(wrapper.get('[data-test="registration-a1-missing"]').text())
      .toContain('employment.position_name')

    await wrapper.get('[data-test="a1-employment-position-name"]').setValue('Jednatel')
    await flushPromises()

    const box = wrapper.get('[data-test="registration-a1-missing"]')
    expect(box.text()).not.toContain('employment.position_name')
    expect(box.text()).toContain('employment.workplace_city')
    expect(wrapper.find(
      '[data-test="registration-a1-gap-link-employment.position_name"]',
    ).exists()).toBe(false)
  })

  /**
   * Zápis do kmenových dat patří k ukládání, ne do jiného panelu. Kdo tady
   * opraví adresu, čeká, že ji tím opravil i v kartě osoby — jinak karta drží
   * starou hodnotu a formulář ji donekonečna hlásí jako rozdíl proti snímku.
   */
  it('při uložení zapíše rozdílné údaje i do kmenových dat', async () => {
    m.a1Profile.mockResolvedValue(a1View({
      writeback: [
        { field: 'permanent_address.city', label: 'obec trvalého pobytu', writable: true, stored: 'Kolín', suggested: 'Havlíčkův Brod', reason: null },
        { field: 'facts.disability_card', label: 'průkaz ZTP', writable: false, stored: null, suggested: null, reason: 'Aplikace tenhle údaj o osobě nevede.' },
      ],
    }))
    m.writeA1MasterData.mockResolvedValue({
      written: [{ field: 'permanent_address.city', label: 'obec trvalého pobytu' }],
      skipped: [],
      view: a1View(),
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    // Výchozí stav je zapnuto — o to uživatel opakovaně žádal.
    const toggle = wrapper.get<HTMLInputElement>(
      '[data-test="registration-a1-write-master-data"]',
    )
    expect(toggle.element.checked).toBe(true)

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    // Zapisuje se jen to, co má v kmenových datech kam jít.
    expect(m.writeA1MasterData)
      .toHaveBeenCalledWith(5, ['permanent_address.city'])
  })

  it('vypnuté zaškrtávátko do kmenových dat nezapíše', async () => {
    m.a1Profile.mockResolvedValue(a1View({
      writeback: [
        { field: 'permanent_address.city', label: 'obec trvalého pobytu', writable: true, stored: 'Kolín', suggested: 'Havlíčkův Brod', reason: null },
      ],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-write-master-data"]').setValue(false)

    await wrapper.get('[data-test="registration-a1-save"]').trigger('click')
    await flushPromises()

    expect(m.writeA1MasterData).not.toHaveBeenCalled()
  })

  /**
   * UI-6/UI-9: příprava vrací VŠECHNY chybějící údaje najednou a každý má
   * proklik přímo na pole — na kartě osoby, nebo v nastavení mezd.
   */
  it('lists every missing item with a link to the exact field', async () => {
    m.preview.mockRejectedValue({
      response: {
        status: 422,
        data: {
          error: {
            code: 'registration_data_incomplete',
            message: 'Registraci zatím nejde sestavit, chybí: rodné příjmení, státní občanství.',
            problems: [
              { field: 'identity.birth_surname', label: 'Rodné příjmení', message: 'Rodné příjmení chybí.', panel: 'registration_identity', target: 'person' },
              { field: 'identity.citizenship_country_code', label: 'Státní občanství', message: 'Státní občanství chybí.', panel: 'registration_identity', target: 'person' },
              { field: 'employer_variable_symbol', label: 'Variabilní symbol', message: 'Chybí.', panel: null, target: 'employer_settings' },
            ],
          },
        },
      },
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    const list = wrapper.get('[data-test="registration-missing-list"]')
    expect(list.findAll('li')).toHaveLength(3)
    expect(list.text()).toContain('payroll.people.registration.missing.fields.birth_surname')
    expect(list.text()).not.toContain('(birth_surname)')
    const personLink = JSON.parse(wrapper
      .get('[data-test="registration-missing-link-identity.citizenship_country_code"]')
      .attributes('data-to')!)
    expect(personLink).toEqual({
      name: 'payroll-people',
      query: {
        employment: '5',
        panel: 'registration_identity',
        field: 'identity.citizenship_country_code',
        person: '9',
      },
    })
    const settingsLink = JSON.parse(wrapper
      .get('[data-test="registration-missing-link-employer_variable_symbol"]')
      .attributes('data-to')!)
    expect(settingsLink).toMatchObject({ name: 'payroll-settings', query: { tab: 'employer' } })
    expect(wrapper.get('[data-test="registration-error"]').text())
      .toContain('payroll.people.registration.missing.title')
  })

  /** UI-6: dokud nic neodešlo, je zřetelně vidět, kam se podává. Výchozí je produkce. */
  it('shows which environment the filing goes to while nothing was sent', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect((wrapper.get('[data-test="registration-environment"]').element as HTMLSelectElement).value)
      .toBe('production')
    expect(wrapper.find('[data-test="registration-environment-notice-production"]').exists()).toBe(true)

    await wrapper.get('[data-test="registration-environment"]').setValue('test')
    await flushPromises()
    expect(wrapper.find('[data-test="registration-environment-notice-test"]').exists()).toBe(true)
  })

  /** UI-11: zástupný variabilní symbol neblokuje, ale náhled na něj upozorní s proklikem. */
  it('warns about a placeholder employer variable symbol in the preview', async () => {
    m.preview.mockResolvedValue({
      ...preview,
      warnings: [{
        code: 'employer_variable_symbol_placeholder',
        field: 'employer_variable_symbol',
        message: 'Variabilní symbol 9876543210 je řada.',
        target: 'employer_settings',
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    const warning = wrapper.get('[data-test="registration-preview-warnings"]')
    expect(warning.text()).toContain('payroll.people.registration.missing.variable_symbol_placeholder')
    expect(JSON.parse(wrapper.get('[data-test="registration-preview-warning-link"]').attributes('data-to')!))
      .toMatchObject({ name: 'payroll-settings', query: { tab: 'employer' } })
  })

  /** UI-21: obec pracoviště se doplní z kódu obce, který přišel ze vztahu. */
  it('fills the workplace city from the municipality code of the relationship', async () => {
    m.searchMunicipalities.mockResolvedValue([
      { code: '554782', label: 'Hlavní město Praha' },
    ])
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')

    expect(m.searchMunicipalities).toHaveBeenCalledWith('554782', 5)
    expect((wrapper.get('[data-test="a1-employment-workplace-city"]').element as HTMLInputElement).value)
      .toBe('Hlavní město Praha')
    // Doplnění z kmenových dat není rozepsaná práce — záloha nevznikne.
    expect(localStorage.getItem(A1_DRAFT_KEY)).toBeNull()
  })

  /** „Adresa pobytu v ČR" má stát předvyplněný, trvalý pobyt naopak ne. */
  it('prefills Czechia only for the Czech residence address', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="a1-czech-residence-toggle"]').setValue(true)
    await wrapper.get('[data-test="a1-contact-toggle"]').setValue(true)
    await flushPromises()

    const country = (test: string) => wrapper
      .get(`[data-test="${test}"]`)
      .get('input[role="combobox"]')
      .element as HTMLInputElement
    expect(country('a1-czech-residence-country_code').value).toContain('Česko')
    expect(country('a1-contact-country_code').value).toBe('')
    expect(wrapper.get('[data-test="a1-czech-residence-hint"]').text())
      .toContain('czech_residence_hint')
    expect(wrapper.get('[data-test="a1-contact-hint"]').text())
      .toContain('contact_address_hint')
  })

  it('UI-23: po načtení ukáže připravenou přihlášku a místo přípravy nabídne frontu', async () => {
    m.current.mockResolvedValue({
      submission_id: 44,
      agenda_code: 'PREZEC26',
      status: 'ready',
      created_at: '2026-09-20 10:00:00',
      submitted_at: null,
      sent: false,
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(m.current).toHaveBeenCalledWith(5, 'production')
    expect(wrapper.get('[data-test="registration-existing-ready"]').text()).toContain('"id":44')
    expect(wrapper.get('[data-test="registration-open-queue"]').attributes('data-to'))
      .toBe(JSON.stringify({ name: 'payroll-submissions-tab', params: { tab: 'queue' } }))
    expect(wrapper.find('[data-test="registration-prepare"]').exists()).toBe(false)
  })

  it('UI-23: u odeslané přihlášky vysvětlí, že druhá se nepodává', async () => {
    m.current.mockResolvedValue({
      submission_id: 45,
      agenda_code: 'REGZEC25',
      status: 'accepted',
      created_at: '2026-09-01 10:00:00',
      submitted_at: '2026-09-01 11:00:00',
      sent: true,
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="registration-existing-sent"]').text()).toContain('existing_sent')
    expect(wrapper.find('[data-test="registration-existing-link"]').exists()).toBe(true)
  })

  it('UI-27: náhled a příprava jsou dvě tlačítka, příprava až po náhledu', async () => {
    m.preview.mockResolvedValue({ ...preview, deadline: { ...deadline, due_on: '2999-01-01' } })
    const wrapper = mountPanel()
    await flushPromises()

    const prepare = wrapper.get('[data-test="registration-prepare"]')
    expect(prepare.attributes('disabled')).toBeDefined()
    expect(prepare.attributes('title')).toContain('prepare_needs_preview')

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(m.prepare).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="registration-prepare"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="registration-deadline-overdue"]').exists()).toBe(false)
  })

  it('UI-25: chybějící profil A1 nabídne jeho doplnění přímo na kartě', async () => {
    m.preview.mockRejectedValue(rejection(
      'registration_regzec_a1_profile_missing',
      'Profil REGZEC A1 pro tento vztah není uložený.',
    ))
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="registration-error"]').text()).toContain('Profil REGZEC A1')
    await wrapper.get('[data-test="registration-error-open-a1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="registration-a1-toggle"]').text()).toContain('a1.hide')
  })

  it('UI-26: po uložení kmenových dat se profil A1 načte znovu', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    const loads = m.a1Profile.mock.calls.length

    await wrapper.setProps({ masterDataVersion: 1 })
    await flushPromises()

    expect(m.a1Profile.mock.calls.length).toBe(loads + 1)
  })

  it('bod 5: v anglickém UI přeloží hlášku problému pole A1 podle klíče', async () => {
    m.locale = 'en'
    m.checkA1Profile.mockResolvedValue({
      complete: false,
      problems: [{
        field: 'employment.position_name',
        code: 'registration_regzec_a1_field_value_invalid',
        message: 'Název pozice je delší, než ČSSZ přijme.',
        message_key: 'too_long',
        params: { max: 255, length: 300 },
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-check"]').trigger('click')
    await flushPromises()

    const text = wrapper.get('[data-test="registration-a1-problem-text-0"]').text()
    expect(text).toContain('payroll.people.registration.a1.problem.too_long')
    expect(text).toContain('"max":255')
    expect(text).not.toContain('delší, než ČSSZ')
  })

  it('bod 5: v češtině zůstane úplná věta serveru', async () => {
    m.checkA1Profile.mockResolvedValue({
      complete: false,
      problems: [{
        field: 'employment.position_name',
        code: 'registration_regzec_a1_field_value_invalid',
        message: 'Název pozice je delší, než ČSSZ přijme.',
        message_key: 'too_long',
        params: { max: 255, length: 300 },
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="registration-a1-toggle"]').trigger('click')
    await wrapper.get('[data-test="registration-a1-check"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="registration-a1-problem-text-0"]').text())
      .toBe('Název pozice je delší, než ČSSZ přijme.')
  })

  it('UI-27: prošlou lhůtu označí červeným upozorněním', async () => {
    m.preview.mockResolvedValue(preview)
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="registration-deadline-overdue"]').text()).toContain('deadline_overdue')
  })
})
