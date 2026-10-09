import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  eldpStatement: vi.fn(),
  prepareEldp: vi.fn(),
  completeEldp: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  searchDocuments: vi.fn(),
  pensionRequests: vi.fn(),
  downloadEldpCopy: vi.fn(),
  canWrite: true,
  canRead: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    eldpStatement: m.eldpStatement,
    prepareEldp: m.prepareEldp,
    completeEldp: m.completeEldp,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
    pensionRequests: m.pensionRequests,
    downloadEldpCopy: m.downloadEldpCopy,
  },
}))

vi.mock('@/api/documents', () => ({
  documentsApi: { search: m.searchDocuments },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: () => m.canWrite,
    canRead: () => m.canRead,
  }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

vi.mock('@/components/ui/SearchableSelect.vue', () => ({
  default: {
    name: 'SearchableSelect',
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template: '<select role="combobox" />',
  },
}))

vi.mock('@/components/payroll/PayrollPersonSearchSelect.vue', () => ({
  default: {
    name: 'PayrollPersonSearchSelect',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<select data-test="person-search" role="combobox" />',
  },
}))

const routeQuery = vi.hoisted(() => ({ value: {} as Record<string, string> }))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
  useRoute: () => ({ query: routeQuery.value }),
}))

import PayrollEldpPanel from '../PayrollEldpPanel.vue'

function setup(): void {
  vi.clearAllMocks()
  routeQuery.value = {}
  m.pensionRequests.mockResolvedValue([])
  m.canWrite = true
  m.canRead = true
  m.peoplePage.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
  m.person.mockResolvedValue({
    id: 11,
    employments: [{
      id: 101,
      employee_id: 11,
      code: 'eldp-synthetic',
      start_date: '2025-01-01',
      end_date: null,
    }],
  })
  m.eldpStatement.mockResolvedValue({
    statement: null,
    supported: {},
    // Výchozí je rok, kdy roční evidenční list ještě existoval; scénáře od
    // roku 2026 si přípustnost přepisují samy.
    eligibility: {
      allowed: true,
      routine: true,
      reason: 'Za období před 1. 1. 2026 vede evidenční list zaměstnavatel.',
      rule: 'transitional_before_2026',
      employment_end_date: null,
      authority_request_available: false,
      last_annual_year: 2025,
    },
    manual_completion: null,
  })
  m.prepareEldp.mockResolvedValue({
    statement_id: 5,
    created: true,
    statement_kind: 'annual',
    section_count: 1,
    insurance_days: 365,
    excluded_days_total: 0,
    due_on: '2026-04-30',
    earliest_submission_on: '2026-01-01',
    obligation_id: 7,
    submission_id: 9,
    part_id: 11,
    artifact_id: 13,
    submission_status: 'prepared',
    xml_sha256: 'a'.repeat(64),
    environment: 'production',
  })
  m.submissionDetail.mockResolvedValue({
    submission: { id: 9 },
    parts: [],
    artifacts: [{ id: 13, mime_type: 'application/xml', artifact_kind: 'payload' }],
    receipts: [],
    issues: [],
    events: [],
  })
  m.downloadSubmissionArtifact.mockResolvedValue(undefined)
  m.searchDocuments.mockResolvedValue([])
  m.completeEldp.mockResolvedValue({
    id: 31,
    statement_id: 5,
    obligation_id: 7,
    authority_status: 'accepted',
    confirmation_document_id: 91,
    confirmation_sha256: 'b'.repeat(64),
    confirmation_byte_size: 42,
    confirmation_mime_type: 'application/pdf',
    authority_reference: 'CSSZ-SYNTHETIC-ACCEPTED',
    confirmed_on: '2026-08-25',
    recorded_by: 1,
    recorded_at: '2026-08-25 10:00:00',
    created: true,
    obligation_status: 'fulfilled',
    obligation_row_version: 4,
    local_submission_status: 'prepared',
    submission_id: 9,
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
}

describe('PayrollEldpPanel', () => {
  beforeEach(setup)

  /**
   * ELDP na výzvu z evidence výzev: proklik z karty osoby předvyplní vztah,
   * rok a údaje výzvy a příprava pošle odkaz na výzvu, aby ji server navázal
   * na list a vzal termín z výzvy.
   */
  it('převezme výzvu z evidence výzev a pošle její odkaz', async () => {
    routeQuery.value = { person: '11', employment: '101', year: '2025', pension_request: '44' }
    m.pensionRequests.mockResolvedValue([{
      id: 44,
      employee_id: 11,
      employment_id: 101,
      request_kind: 'eldp',
      legacy_kind: null,
      requester: 'ossz',
      requester_reference: 'SYN-OSSZ-1',
      received_on: '2026-08-20',
      period_year: 2025,
      period_from: null,
      period_to: null,
      death_on: null,
      stated_due_on: null,
      due_on: '2026-08-28',
      deadline_rule: 'cz-eldp-deadlines.authority-request.pre-2026.v1',
      deadline_source: 'syntetický zdroj',
      eldp_statement_id: null,
      eldp_environment: null,
      status: 'open',
      completed_on: null,
      completion_kind: null,
      completion_reference: null,
      copy_delivered_on: null,
      note: null,
      row_version: 1,
    }])
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    expect(m.pensionRequests).toHaveBeenCalledWith(11)
    expect(wrapper.get('[data-test="eldp-pension-request"]').text()).toContain('payroll.eldp.fromRequest.title')
    expect((wrapper.get('[data-test="eldp-authority-request"]').element as HTMLInputElement).checked).toBe(true)

    await wrapper.get('[data-test="eldp-excluded-confirm"]').setValue(true)
    await wrapper.get('[data-test="eldp-pension-confirm"]').setValue(true)
    await flushPromises()
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      employment_id: 101,
      year: 2025,
      requested_by_authority: true,
      authority_request_received_on: '2026-08-20',
      pension_request_id: 44,
    }))
  })
  it('používá dark-mode tokeny místo natvrdo bílých ploch formuláře', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    expect(wrapper.html()).not.toContain('bg-white')
    expect(wrapper.get('[data-test="eldp-note"]').classes()).toContain('bg-surface')
  })

  it('nedovolí přípravu bez obou výslovných potvrzení', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    const button = wrapper.get('[data-test="eldp-prepare"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.eldp.noSendNotice')
  })

  /*
   * Od roku 2026 zaměstnavatel evidenční list nevyhotovuje. Panel to musí
   * říct NAD formulářem a přípravu vůbec nenabídnout — jinak vypadá zrušená
   * roční povinnost pořád jako úkol, který má účetní odbavit.
   */
  /**
   * Zhasnuté tlačítko nad formulářem o šesti polích neřeklo, které z nich
   * zlobí. Poznámka mezi překážkami už NENÍ — ČSSZ ji nepřebírá, byla to jen
   * naše evidence, a zákonná povinnost nesmí stát na interním zápisu.
   */
  it('vyjmenuje, co k přípravě chybí, místo mlčky zhasnutého tlačítka', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()
    wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
      .vm.$emit('update:modelValue', 11)
    await flushPromises()
    wrapper.findAllComponents({ name: 'SearchableSelect' })[0]!
      .vm.$emit('update:modelValue', 101)
    await flushPromises()

    const blockers = wrapper.get('[data-test="eldp-prepare-blockers"]').text()
    expect(blockers).toContain('payroll.eldp.blockers.excluded')
    // Odečtené doby odvozuje server z nepřítomností, potvrzení už nechybí.
    expect(blockers).not.toContain('payroll.eldp.blockers.deducted')
    expect(blockers).toContain('payroll.eldp.blockers.pension')
    expect(blockers).not.toContain('payroll.eldp.blockers.note')

    await fillConfirmation(wrapper)
    expect(wrapper.find('[data-test="eldp-prepare-blockers"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="eldp-prepare"]').attributes('disabled')).toBeUndefined()
  })

  /**
   * Regrese: prázdná poznámka držela tlačítko Připravit zhasnuté, přestože
   * ČSSZ žádnou poznámku nečeká. Potvrzení vyloučených a odečítaných dob
   * samo o sobě musí stačit.
   */
  it('nechá připravit list i s prázdnou poznámkou', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()
    wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
      .vm.$emit('update:modelValue', 11)
    await flushPromises()
    wrapper.findAllComponents({ name: 'SearchableSelect' })[0]!
      .vm.$emit('update:modelValue', 101)
    await flushPromises()

    await wrapper.get('[data-test="eldp-excluded-confirm"]').setValue(true)
    await wrapper.get('[data-test="eldp-pension-confirm"]').setValue(true)
    await wrapper.get('[data-test="eldp-note"]').setValue('')
    await flushPromises()

    expect(wrapper.find('[data-test="eldp-prepare-blockers"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="eldp-prepare"]').attributes('disabled')).toBeUndefined()
  })

  /**
   * Chyba načtení podkladu se dřív zahazovala: obrazovka po výběru vztahu
   * jen zhasla a účetní z toho četla „za tenhle rok nic není".
   */
  it('selhání podkladu řekne nahlas, ne prázdnou obrazovkou', async () => {
    m.eldpStatement.mockRejectedValue(new Error('boom'))
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()
    wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
      .vm.$emit('update:modelValue', 11)
    await flushPromises()
    wrapper.findAllComponents({ name: 'SearchableSelect' })[0]!
      .vm.$emit('update:modelValue', 101)
    await flushPromises()

    expect(wrapper.get('[data-test="eldp-error"]').text())
      .toContain('payroll.eldp.errors.statementLoadFailed')
  })

  it('nenabídne přípravu tam, kde evidenční list sestavuje ČSSZ', async () => {
    m.eldpStatement.mockResolvedValue({
      statement: null,
      supported: {},
      eligibility: {
        allowed: false,
        routine: false,
        reason: 'Zaměstnavatel evidenční list nevyhotovuje ani nepředkládá.',
        rule: 'assembled_by_cssz_from_monthly_report',
        employment_end_date: null,
        authority_request_available: true,
        last_annual_year: 2025,
      },
      manual_completion: null,
    })
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)

    expect(wrapper.get('[data-test="eldp-not-applicable"]').text())
      .toContain('nevyhotovuje')
    expect(wrapper.get('[data-test="eldp-prepare"]').attributes('disabled'))
      .toBeDefined()
    expect(m.prepareEldp).not.toHaveBeenCalled()
  })

  it('výjimku označí jako výjimku, ne jako roční povinnost', async () => {
    m.eldpStatement.mockResolvedValue({
      statement: null,
      supported: {},
      eligibility: {
        allowed: true,
        routine: false,
        reason: 'Zaměstnání skončilo před 1. 4. 2026.',
        rule: 'transitional_participation_ended_before_april_2026',
        employment_end_date: '2026-03-31',
        authority_request_available: false,
        last_annual_year: 2025,
      },
      manual_completion: null,
    })
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)

    expect(wrapper.get('[data-test="eldp-exception"]').text())
      .toContain('payroll.eldp.exceptionOnly.title')
    expect(wrapper.find('[data-test="eldp-not-applicable"]').exists()).toBe(false)
  })

  it('vypíše blokátory pojmenované serverem', async () => {
    m.prepareEldp.mockRejectedValue({
      isAxiosError: true,
      response: {
        data: {
          error: {
            code: 'eldp_source_incomplete',
            message: 'Evidenční list nelze sestavit.',
            blockers: [{
              code: 'eldp_month_source_missing',
              message: 'Chybí schválená mzdová revize za březen 2025.',
              detail: { period_start: '2025-03-01', employment_id: 101 },
            }],
          },
        },
      },
    })
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="eldp-blocker"]').text())
      .toContain('březen 2025')
    expect(wrapper.get('[data-test="eldp-blocker"] a').attributes('href')).toBe('/payroll/runs?period=2025-03')
  })

  it('po přípravě hlásí připravené podání, ne odeslané', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="eldp-success"]').text())
      .toContain('payroll.eldp.preparedCreated')
  })

  /*
   * Kód D a to, zda se list za poživatele plného starobního důchodu vůbec
   * vede, stojí na důchodových údajích. Posílají se vždy všechny, prázdné
   * jako null, aby „nenastalo" nebylo totéž co „nezadáno".
   */
  it('pošle výslovně potvrzené důchodové údaje', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-pension-age"]').setValue('2025-04-01')
    await wrapper.get('[data-test="eldp-pension-full"]').setValue('2025-10')
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      pension_status: {
        pension_age_reached_on: '2025-04-01',
        early_pension_from: null,
        full_pension_paid_from: '2025-10',
        foreign_insurance: false,
      },
    }))
  })

  it('nabídne pouze stažení kontrolního XML a nikdy odeslání', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="eldp-download"]').trigger('click')
    await flushPromises()

    expect(m.submissionDetail).toHaveBeenCalledWith(9)
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      9,
      expect.objectContaining({ id: 13 }),
    )
    expect(wrapper.text()).toContain('payroll.eldp.manualCompletionNotice')
    expect(wrapper.find('[data-test="eldp-send"]').exists()).toBe(false)
  })

  it('u výzvy vyžádá datum doručení a předá je serveru', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-authority-request"]').setValue(true)
    await flushPromises()

    const date = wrapper.get('[data-test="eldp-authority-request-date"]')
    await date.setValue('2026-08-25')
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      requested_by_authority: true,
      authority_request_received_on: '2026-08-25',
    }))
  })

  it('nad zmrazeným listem nabídne opravný list a pošle ho pod vlastním klíčem', async () => {
    m.eldpStatement.mockResolvedValue({
      statement: {
        id: 5,
        statement_sequence: 1,
        corrects_statement_id: null,
        statement_kind: 'termination',
        period_from: '2025-01-01',
        period_to: '2025-08-31',
        section_count: 2,
        insurance_days: 243,
        excluded_days_total: 0,
        deducted_days_total: 0,
        due_on: '2025-09-30',
        earliest_submission_on: '2025-09-30',
        xml_sha256: 'a'.repeat(64),
        payload: {
          form: {
            eldp_type: '02',
            employed_from: '2019-05-01',
            prepared_on: '2025-09-30',
            corrects: null,
          },
          eldp_sections: [
            { code: '1++', valid_from: '2025-01-01', valid_to: '2025-08-31', insurance_days: 212, assessment_base_czk: 80000, months_without_insurance: [6] },
            { code: '1P+', valid_from: null, valid_to: null, insurance_days: 0, assessment_base_czk: 5000, months_without_insurance: [] },
          ],
        },
      },
      eligibility: { allowed: true, routine: true, reason: 'Syntetický důvod.', rule: 'transitional_before_2026', authority_request_available: false },
      manual_completion: null,
    })
    m.prepareEldp.mockResolvedValue({
      statement_id: 6,
      created: true,
      statement_kind: 'termination',
      eldp_type: '52',
      corrects_statement_id: 5,
      section_count: 2,
      insurance_days: 243,
      excluded_days_total: 0,
      due_on: '2025-09-30',
      earliest_submission_on: '2025-09-30',
      obligation_id: 7,
      submission_id: 9,
      part_id: 11,
      artifact_id: 13,
      submission_status: 'prepared',
      xml_sha256: 'b'.repeat(64),
      environment: 'production',
    })
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    expect(wrapper.get('[data-test="eldp-form-type"]').text()).toContain('payroll.eldp.formSheet.types.02')
    const rows = wrapper.findAll('[data-test="eldp-form-section"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.findAll('td')[5]!.text()).toBe('6')
    expect(rows[0]!.get('[data-test="eldp-form-small-scale"]').text()).toBe('N')
    expect(rows[1]!.text()).toContain('payroll.eldp.formSheet.postTermination')

    // Stejnopis pro zaměstnance se tiskne ze zmrazeného listu téhož rozsahu.
    await wrapper.get('[data-test="eldp-copy"]').trigger('click')
    await flushPromises()
    expect(m.downloadEldpCopy).toHaveBeenCalledWith({ employment_id: 101, year: 2025, environment: 'production' })

    await wrapper.get('[data-test="eldp-correction"]').setValue(true)
    await wrapper.get('[data-test="eldp-prepared-on"]').setValue('2025-10-05')
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      correction: true,
      prepared_on: '2025-10-05',
      idempotency_key: 'eldp-correction:production:101:2025:5',
    }))
    expect(wrapper.get('[data-test="eldp-success"]').text())
      .toContain('payroll.eldp.correction.created')
  })

  it('bez zmrazeného listu opravný list nenabídne', async () => {
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    await wrapper.get('[data-test="eldp-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareEldp).toHaveBeenCalledWith(expect.objectContaining({
      correction: false,
      prepared_on: null,
    }))
  })

  it('doloží přijetí jen ID firemního DMS dokumentu a odliší je od odeslání', async () => {
    m.eldpStatement.mockResolvedValue({
      statement: {
        id: 5,
        statement_kind: 'annual',
        period_from: '2025-01-01',
        period_to: '2025-12-31',
        section_count: 1,
        insurance_days: 365,
        excluded_days_total: 0,
        deducted_days_total: 0,
        due_on: '2026-04-30',
        earliest_submission_on: '2026-01-01',
        xml_sha256: 'a'.repeat(64),
        payload: {},
      },
      supported: {},
      manual_completion: {
        statement_id: 5,
        obligation_id: 7,
        obligation_status: 'prepared',
        obligation_row_version: 2,
        submission_id: 9,
        local_submission_status: 'prepared',
        evidence: [],
      },
    })
    m.searchDocuments.mockResolvedValue([
      { id: 91, title: 'Potvrzení ČSSZ', original_name: 'potvrzeni.pdf', scope: 'company', deleted_at: null },
      { id: 92, title: 'Soukromá poznámka', original_name: 'poznamka.pdf', scope: 'user', deleted_at: null },
      { id: 93, title: 'Dokument v koši', original_name: 'kos.pdf', scope: 'company', deleted_at: '2026-08-20 10:00:00' },
    ])
    const wrapper = mount(PayrollEldpPanel)
    await flushPromises()

    await fillConfirmation(wrapper)
    expect(wrapper.get('[data-test="eldp-authority-status-explanation"]').text())
      .toContain('payroll.eldp.manual.statusExplanation.submitted')
    await wrapper.get('[data-test="eldp-authority-status"]').setValue('accepted')
    await wrapper.get('[data-test="eldp-document-query"]').setValue('potvrzení')
    await wrapper.get('[data-test="eldp-document-search"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="eldp-document-option"]')).toHaveLength(1)
    await wrapper.get('[data-test="eldp-document-option"]').trigger('click')
    await wrapper.get('[data-test="eldp-authority-reference"]')
      .setValue('CSSZ-SYNTHETIC-ACCEPTED')
    await wrapper.get('[data-test="eldp-confirmed-on"]').setValue('2026-08-25')
    await wrapper.get('[data-test="eldp-complete"]').trigger('click')
    await flushPromises()

    expect(m.completeEldp).toHaveBeenCalledOnce()
    const [statementId, payload] = m.completeEldp.mock.calls[0]
    expect(statementId).toBe(5)
    expect(payload).toEqual(expect.objectContaining({
      environment: 'production',
      expected_obligation_row_version: 2,
      authority_status: 'accepted',
      confirmation_document_id: 91,
      authority_reference: 'CSSZ-SYNTHETIC-ACCEPTED',
      confirmed_on: '2026-08-25',
    }))
    expect(JSON.stringify(payload)).not.toContain('sha256')
  })
})

async function fillConfirmation(
  wrapper: ReturnType<typeof mount>,
): Promise<void> {
  const selects = wrapper.findAllComponents({ name: 'SearchableSelect' })
  wrapper.findComponent({ name: 'PayrollPersonSearchSelect' })
    .vm.$emit('update:modelValue', 11)
  await flushPromises()
  selects[0]!.vm.$emit('update:modelValue', 101)
  await flushPromises()
  await wrapper.get('[data-test="eldp-excluded-confirm"]').setValue(true)
  await wrapper.get('[data-test="eldp-pension-confirm"]').setValue(true)
  await wrapper.get('[data-test="eldp-note"]')
    .setValue('Syntetické potvrzení evidenčního listu.')
  await flushPromises()
}
