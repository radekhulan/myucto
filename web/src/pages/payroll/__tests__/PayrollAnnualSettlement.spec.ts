import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  listAnnualSettlements: vi.fn(),
  previewAnnualSettlement: vi.fn(),
  saveAnnualSettlementRequest: vi.fn(),
  bulkSetAnnualSettlementRequestStatus: vi.fn(),
  settleAnnualSettlement: vi.fn(),
  saveAnnualSettlementCertificates: vi.fn(),
  saveAnnualSettlementAnnualClaims: vi.fn(),
  downloadDocument: vi.fn(),
  enqueueAnnualDocumentBatch: vi.fn(),
  annualDocumentBatch: vi.fn(),
  annualDocumentBatchItems: vi.fn(),
  retryAnnualDocumentBatchItem: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
  canWrite: true,
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    listAnnualSettlements: m.listAnnualSettlements,
    previewAnnualSettlement: m.previewAnnualSettlement,
    saveAnnualSettlementRequest: m.saveAnnualSettlementRequest,
    bulkSetAnnualSettlementRequestStatus: m.bulkSetAnnualSettlementRequestStatus,
    settleAnnualSettlement: m.settleAnnualSettlement,
    saveAnnualSettlementCertificates: m.saveAnnualSettlementCertificates,
    saveAnnualSettlementAnnualClaims: m.saveAnnualSettlementAnnualClaims,
    downloadDocument: m.downloadDocument,
    enqueueAnnualDocumentBatch: m.enqueueAnnualDocumentBatch,
    annualDocumentBatch: m.annualDocumentBatch,
    annualDocumentBatchItems: m.annualDocumentBatchItems,
    retryAnnualDocumentBatchItem: m.retryAnnualDocumentBatchItem,
  },
}))

// Uložené pohledy jdou přes Pinii a API; v testu stačí prázdná sada bez
// výchozího pohledu, ať se obrazovka nezastaví na načítání předvoleb.
vi.mock('@/composables/useSavedFilters', async () => {
  const { computed, ref } = await import('vue')
  return {
    useSavedFilters: (_key: string, opts: {
      getQuery: () => Record<string, string>
      applyQuery: (q: Record<string, string>) => void
    }) => ({
      filters: ref([]),
      activeId: computed(() => null),
      ready: ref(true),
      getQuery: opts.getQuery,
      saveCurrent: vi.fn(),
      overwrite: vi.fn(),
      apply: vi.fn(),
      clearActive: () => opts.applyQuery({}),
      rename: vi.fn(),
      setDefault: vi.fn(),
      remove: vi.fn(),
      applyDefaultIfAny: () => Promise.resolve(false),
    }),
  }
})

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    error: m.error,
    success: m.success,
    warning: m.warning,
  }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollAnnualSettlement from '@/pages/payroll/PayrollAnnualSettlement.vue'

const actionBarStub = {
  props: ['actions'],
  template:
    '<div data-test="action-bar">'
    + '<button v-for="a in actions" :key="a.key" :data-action="a.key" '
    + ':disabled="a.disabled" :data-reason="a.disabledReason" @click="a.run && a.run()">'
    + '{{ a.label }}</button></div>',
}

const emptyStateStub = {
  props: ['variant', 'title', 'message', 'cta'],
  template: '<div data-test="empty-state" :data-variant="variant">{{ title }}</div>',
}

function listResponse(items: unknown[], overrides: Record<string, unknown> = {}) {
  return {
    tax_year: 2026,
    request_deadline: '2027-02-15',
    settlement_deadline: '2027-03-31',
    payout_period: '2027-03',
    payout_threshold_minor: 5000,
    items,
    total: items.length,
    limit: 25,
    offset: 0,
    search: '',
    state: 'all',
    ...overrides,
  }
}

function person(overrides: Record<string, unknown> = {}) {
  return {
    employee_id: 7,
    employee_name: 'Syntetická osoba',
    request_status: 'requested',
    requested_on: '2027-02-05',
    prior_employers: 'none',
    filing_obligation: 'none',
    annual_claims: 'none',
    row_version: 1,
    outcome_id: null,
    outcome: null,
    tax_difference_minor: null,
    bonus_difference_minor: null,
    settlement_difference_minor: null,
    payable_minor: null,
    settled_on: null,
    payout_run_id: null,
    payout_revision_id: null,
    payout_period_start: null,
    annual_revision_id: null,
    ...overrides,
  }
}

function result(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: 'payroll-annual-settlement.v1',
    tax_year: 2026,
    performed: true,
    blockers: [],
    outcome: 'overpayment',
    rounded_tax_base_minor_units: 50_000_000,
    tax_before_credits_minor_units: 7_500_000,
    annual_credits_minor_units: 3_084_000,
    applied_credits_minor_units: 3_084_000,
    child_entitlement_minor_units: 1_520_400,
    child_credit_minor_units: 0,
    annual_tax_bonus_minor_units: 1_520_400,
    tax_after_all_credits_minor_units: 4_416_000,
    tax_difference_minor_units: 120_000,
    bonus_difference_minor_units: 253_400,
    settlement_difference_minor_units: 373_400,
    payable_minor_units: 373_400,
    annual_bonus_threshold_met: true,
    annual_bonus_candidate_minor_units: 1_520_400,
    annual_bonus_income_threshold_met: true,
    annual_bonus_amount_threshold_met: true,
    annual_bonus_eligible: true,
    annual_bonus_eligibility_reason: 'eligible',
    bonus_qualifying_income_minor_units: 14_000_000,
    bonus_minimum_income_minor_units: 13_440_000,
    bonus_minimum_amount_minor_units: 10_000,
    monthly_tax_bonus_minor_units: 1_267_000,
    ...overrides,
  }
}

function previewResponse(overrides: Record<string, unknown> = {}) {
  return {
    tax_year: 2026,
    employee_id: 7,
    request: {
      tax_year: 2026,
      request_status: 'requested',
      requested_on: '2027-02-05',
      request_evidence_reference: 'synthetic',
      prior_employers: 'none',
      prior_documents_received_on: null,
      filing_obligation: 'none',
      filing_obligation_reason: null,
      annual_claims: 'none',
      annual_claims_note: null,
      note: null,
      row_version: 1,
    },
    result: result(),
    credit_rows: [{ label: 'Základní sleva na poplatníka', amount_minor_units: 3_084_000 }],
    child_rows: [],
    certificates: [],
    already_settled: null,
    ...overrides,
  }
}

/**
 * Výchozí rok stránky je UPLYNULÉ zdaňovací období — zúčtovává se to, co
 * skončilo. Test si ho odvozuje stejně, aby nezčernal 1. ledna.
 */
const defaultYear = new Date().getFullYear() - 1

function mountPage() {
  return mount(PayrollAnnualSettlement, {
    global: {
      stubs: {
        ActionBar: actionBarStub,
        EmptyState: emptyStateStub,
        SavedFiltersMenu: true,
        RouterLink: true,
      },
    },
  })
}

describe('Roční zúčtování', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite = true
    m.listAnnualSettlements.mockResolvedValue(listResponse([person()]))
    m.previewAnnualSettlement.mockResolvedValue(previewResponse())
  })

  /**
   * Prázdný rok NENÍ selhání. Musí se odlišit od nenačtených dat, jinak by
   * uživatel z prázdné obrazovky usoudil, že za rok nikdo nepožádal.
   */
  it('u firmy bez zaměstnanců ukáže prázdný stav, ne chybu', async () => {
    m.listAnnualSettlements.mockResolvedValue(listResponse([]))
    const wrapper = mountPage()
    await flushPromises()

    const empty = wrapper.find('[data-test="empty-state"]')
    expect(empty.exists()).toBe(true)
    expect(empty.attributes('data-variant')).toBe('empty')
    expect(m.error).not.toHaveBeenCalled()
  })

  /* Hlídač zákonných termínů odkazuje rovnou na zúčtování konkrétního roku. */
  it('rok převezme z odkazu', async () => {
    m.routeQuery = { year: '2024' }
    const wrapper = mountPage()
    await flushPromises()

    expect(m.listAnnualSettlements.mock.calls[0]?.[0]).toBe(2024)
    m.routeQuery = {}
    wrapper.unmount()
  })

  /* Q8-43: firma převedená z jiného programu viděla rok, který zúčtoval on. */
  it('výchozí rok nepředchází začátku vedení mezd v MyÚčtu', async () => {
    const startYear = new Date().getFullYear() + 1
    m.listAnnualSettlements.mockResolvedValue(listResponse([person()], { payroll_start_period: `${startYear}-01` }))
    const wrapper = mountPage()
    await flushPromises()

    expect(m.listAnnualSettlements.mock.calls[0]?.[0]).toBe(new Date().getFullYear() - 1)
    expect(m.listAnnualSettlements.mock.calls.at(-1)?.[0]).toBe(startYear)
    wrapper.unmount()
  })

  it('rok z odkazu se začátkem vedení mezd nepřepíše', async () => {
    m.routeQuery = { year: '2024' }
    m.listAnnualSettlements.mockResolvedValue(listResponse([person()], { payroll_start_period: '2030-01' }))
    const wrapper = mountPage()
    await flushPromises()

    expect(m.listAnnualSettlements.mock.calls.at(-1)?.[0]).toBe(2024)
    m.routeQuery = {}
    wrapper.unmount()
  })

  it('při selhání načtení ukáže stav „nepovedlo se", ne prázdno', async () => {
    m.listAnnualSettlements.mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="empty-state"]').attributes('data-variant'))
      .toBe('failed')
    expect(m.error).toHaveBeenCalled()
  })

  it('vypíše všechny překážky větami a hlavní akci nechá zašedlou s důvodem', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        performed: false,
        outcome: null,
        blockers: ['declaration_not_signed', 'filing_obligation_unknown'],
        payable_minor_units: 0,
      }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const blockers = wrapper.find('[data-test="annual-settlement-blockers"]')
    expect(blockers.exists()).toBe(true)
    expect(blockers.text()).toContain('payroll.annual_settlement.blocker.declaration_not_signed')
    expect(blockers.text()).toContain('payroll.annual_settlement.blocker.filing_obligation_unknown')
    // Výsledková tabulka se nesmí objevit — nedopočítalo se „aspoň částečně".
    expect(wrapper.find('[data-test="annual-settlement-result"]').exists()).toBe(false)

    // Tlačítko zůstává vidět, jen zašedlé — a nese větu, proč.
    const settle = wrapper.find('[data-action="settle"]')
    expect(settle.exists()).toBe(true)
    expect(settle.attributes('disabled')).toBeDefined()
    expect(settle.attributes('data-reason'))
      .toBe('payroll.annual_settlement.blocker.declaration_not_signed')
  })

  /**
   * Regrese: seznam překážek končil větou „Doplňte odpověď" nad formulářem
   * o dvanácti polích a neřekl, které z nich to je. Překážka, jejíž pole je
   * na téhle obrazovce, je proto klikací a pole se otevře a vysvítí.
   * Překážka mířící jinam (karta zaměstnance) zůstává větou.
   */
  /**
   * Převzaté zvýhodnění na dítě bez nároku v evidenci se opravuje v kartě
   * zaměstnance — překážka tam musí rovnou vést.
   */
  it('u převzatého zvýhodnění bez nároku na dítě nabídne proklik na kartu zaměstnance', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        performed: false,
        outcome: null,
        blockers: ['takeover_child_claim_missing'],
        payable_minor_units: 0,
      }),
    }))
    const wrapper = mount(PayrollAnnualSettlement, {
      global: {
        stubs: {
          ActionBar: actionBarStub,
          EmptyState: emptyStateStub,
          SavedFiltersMenu: true,
          RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const link = wrapper.get('[data-test="annual-settlement-blocker-card-takeover_child_claim_missing"]')
    expect(link.attributes('data-to')).toContain('"payroll-person"')
    expect(link.attributes('data-to')).toContain('7')
    expect(wrapper.get('[data-test="annual-settlement-blockers"]').text())
      .toContain('payroll.annual_settlement.blocker.takeover_child_claim_missing')
  })

  it('u překážky se zdejším polem nabídne proklik, u cizí ne', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        performed: false,
        outcome: null,
        blockers: ['filing_obligation_unknown', 'declaration_unverified'],
        payable_minor_units: 0,
      }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const link = wrapper.find(
      '[data-test="annual-settlement-blocker-link-filing_obligation_unknown"]',
    )
    expect(link.exists()).toBe(true)
    expect(
      wrapper.find('[data-test="annual-settlement-blocker-link-declaration_unverified"]').exists(),
    ).toBe(false)

    const field = wrapper.find('[data-test="annual-settlement-filing-obligation"]')
    expect(field.exists()).toBe(true)
    const element = field.element as HTMLSelectElement
    element.scrollIntoView = vi.fn()
    await link.trigger('click')

    expect(element.scrollIntoView).toHaveBeenCalled()
    expect(element.classList.contains('ring-2')).toBe(true)
  })

  /*
   * Dvě překážky, které do enumu přibyly později. Kód, pro který obrazovka nemá
   * větu, se nevykreslí jako chyba — vykreslí se jako holý klíč přesně tam, kde
   * má stát důvod, proč roční zúčtování nejde provést. Kontraktová brána
   * (PayrollEnumContractTest) hlídá existenci vět v cs.json i en.json; tenhle
   * test hlídá, že jimi obrazovka opravdu projde.
   */
  it.each(['tax_year_not_finished', 'taxpayer_credit_evidence_missing'] as const)(
    'vypíše překážku %s větou, ne holým kódem',
    async (code) => {
      m.previewAnnualSettlement.mockResolvedValue(previewResponse({
        result: result({
          performed: false,
          outcome: null,
          blockers: [code],
          payable_minor_units: 0,
        }),
      }))
      const wrapper = mountPage()
      await flushPromises()
      await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
      await flushPromises()

      const blockers = wrapper.find('[data-test="annual-settlement-blockers"]')
      expect(blockers.text()).toContain(`payroll.annual_settlement.blocker.${code}`)
      expect(wrapper.find('[data-action="settle"]').attributes('data-reason'))
        .toBe(`payroll.annual_settlement.blocker.${code}`)
    },
  )

  it('u splněných podmínek ukáže výsledek a nechá zúčtování provést', async () => {
    m.settleAnnualSettlement.mockResolvedValue({
      tax_year: 2026,
      employee_id: 7,
      performed: true,
      created: true,
      result: result(),
      outcome: null,
      document: { id: 42, document_kind: 'annual_settlement_result' },
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="annual-settlement-result"]').exists()).toBe(true)
    const bonus = wrapper.get('[data-test="annual-tax-bonus-eligibility"]')
    expect(bonus.text()).toContain('payroll.annual_settlement.bonus_eligibility.eligible')
    expect(wrapper.get('[data-test="annual-tax-bonus-income"]').text()).toContain('140 000')
    expect(wrapper.get('[data-test="annual-tax-bonus-income-threshold"]').text())
      .toContain('134 400')
    expect(wrapper.get('[data-test="annual-tax-bonus-entitlement"]').text())
      .toContain('payroll.annual_settlement.row_annual_tax_bonus')
    expect(wrapper.get('[data-test="annual-tax-bonus-entitlement"]').text()).toContain('15 204')
    expect(wrapper.get('[data-test="annual-tax-bonus-paid-monthly"]').text())
      .toContain('payroll.annual_settlement.row_monthly_tax_bonus')
    expect(wrapper.get('[data-test="annual-tax-bonus-paid-monthly"]').text()).toContain('12 670')
    expect(wrapper.get('[data-test="annual-settlement-result"]').text()).toContain('2 534')
    const settle = wrapper.find('[data-action="settle"]')
    expect(settle.attributes('disabled')).toBeUndefined()

    await settle.trigger('click')
    await flushPromises()

    expect(m.settleAnnualSettlement).toHaveBeenCalledWith(defaultYear, 7)
    expect(m.success).toHaveBeenCalledWith('payroll.annual_settlement.settled')
    expect(wrapper.find('[data-test="annual-settlement-download"]').exists()).toBe(true)
  })

  it('vysvětlí nulový roční bonus příjmem pod roční hranicí', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        annual_bonus_threshold_met: false,
        annual_bonus_income_threshold_met: false,
        annual_bonus_amount_threshold_met: true,
        annual_bonus_eligible: false,
        annual_bonus_eligibility_reason: 'income_below_threshold',
        bonus_qualifying_income_minor_units: 10_000_000,
        annual_tax_bonus_minor_units: 0,
        bonus_difference_minor_units: 0,
      }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const bonus = wrapper.get('[data-test="annual-tax-bonus-eligibility"]')
    expect(bonus.text()).toContain(
      'payroll.annual_settlement.bonus_eligibility.income_below_threshold',
    )
    expect(wrapper.get('[data-test="annual-tax-bonus-income"]').text()).toContain('100 000')
    expect(wrapper.get('[data-test="annual-tax-bonus-entitlement"]').text()).toContain('0,00')
  })

  it('vysvětlí nulový bonus kandidátem pod ročním minimem', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        annual_bonus_candidate_minor_units: 9_900,
        annual_bonus_income_threshold_met: true,
        annual_bonus_amount_threshold_met: false,
        annual_bonus_eligible: false,
        annual_bonus_eligibility_reason: 'amount_below_threshold',
        annual_tax_bonus_minor_units: 0,
        bonus_difference_minor_units: 0,
      }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const bonus = wrapper.get('[data-test="annual-tax-bonus-eligibility"]')
    expect(bonus.text()).toContain(
      'payroll.annual_settlement.bonus_eligibility.amount_below_threshold',
    )
    expect(wrapper.get('[data-test="annual-tax-bonus-candidate"]').text()).toContain('99,00')
    expect(wrapper.get('[data-test="annual-tax-bonus-entitlement"]').text()).toContain('0,00')
  })

  /**
   * Odmítnutí přijde z API jako úspěšná odpověď s `performed: false`. Nesmí se
   * zobrazit jako chyba serveru — je to řádný závěr posouzení podmínek.
   */
  it('odmítnuté zúčtování nehlásí jako chybu, ale vypíše překážky', async () => {
    m.settleAnnualSettlement.mockResolvedValue({
      tax_year: 2026,
      employee_id: 7,
      performed: false,
      result: result({
        performed: false,
        outcome: null,
        blockers: ['already_settled'],
        payable_minor_units: 0,
      }),
      already_settled: null,
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-action="settle"]').trigger('click')
    await flushPromises()

    expect(m.error).not.toHaveBeenCalled()
    expect(m.warning).toHaveBeenCalledWith('payroll.annual_settlement.settle_refused')
    expect(wrapper.find('[data-test="annual-settlement-blockers"]').text())
      .toContain('payroll.annual_settlement.blocker.already_settled')
  })

  it('u už provedeného zúčtování řekne kdy proběhlo', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        performed: false,
        outcome: null,
        blockers: ['already_settled'],
        payable_minor_units: 0,
      }),
      already_settled: {
        id: 3,
        employee_id: 7,
        tax_year: 2026,
        annual_revision_id: 9,
        outcome: 'overpayment',
        tax_difference_minor: 120_000,
        bonus_difference_minor: 0,
        settlement_difference_minor: 120_000,
        payable_minor: 120_000,
        settled_on: '2027-03-10',
        payout_run_id: null,
        payout_revision_id: null,
        payout_period_start: null,
      },
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="annual-settlement-already"]').exists()).toBe(true)
  })

  /**
   * Nejnebezpečnější místo celé obrazovky: prázdné pole se NESMÍ odeslat jako
   * nula. Nula je podle § 38ch odst. 3 doložený údaj a počítalo by se s ní —
   * z toho by vyšel přeplatek, na který zaměstnanec nemá nárok.
   */
  it('prázdnou částku na potvrzení posílá jako null, ne jako nulu', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      certificates: [{
        certificate_reference: 'POT-1',
        payer_name: 'Předchozí plátce',
        payer_tax_identification: null,
        received_on: '2027-02-10',
        gross_income_minor_units: 3_000_000,
        advance_base_minor_units: 3_000_000,
        advance_tax_minor_units: 450_000,
        non_refundable_credit_minor_units: 257_000,
        child_credit_minor_units: 0,
        tax_bonus_minor_units: null,
        evidence_status: 'verified',
        evidence_reference: 'doklad',
        missing_statutory_fields: ['tax_bonus'],
      }],
    }))
    m.saveAnnualSettlementCertificates.mockResolvedValue([])
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    // Chybějící údaj je vidět dřív, než se na něj někdo zeptá. (Mock `t`
    // interpolaci nedělá, takže se ověřuje jen že varování je vypsané.)
    expect(wrapper.find('[data-test="annual-settlement-certificate-missing"]').text())
      .toContain('payroll.annual_settlement.certificate_missing')
    // Nula se do formuláře načte jako „0.00", ne jako prázdno.
    expect(
      (wrapper.find('[data-test="annual-settlement-certificate-credit_35c"]')
        .element as HTMLInputElement).value,
    ).toBe('0.00')
    expect(
      (wrapper.find('[data-test="annual-settlement-certificate-tax_bonus"]')
        .element as HTMLInputElement).value,
    ).toBe('')

    await wrapper.find('[data-test="annual-settlement-save-certificates"]').trigger('click')
    await flushPromises()

    const [, , payload] = m.saveAnnualSettlementCertificates.mock.calls[0]
    expect(payload[0].tax_bonus_minor_units).toBeNull()
    expect(payload[0].child_credit_minor_units).toBe(0)
    expect(payload[0].advance_tax_minor_units).toBe(450_000)
  })

  /**
   * Období „za období od–do" z tiskopisu rozhoduje o souběhu plátců
   * (§ 38g odst. 2). Prázdné období znamená „nevíme", ne „souběh nebyl", takže
   * se posílá jako `null` a pole NESMÍ být povinné — historická potvrzení ho
   * nenesou a fail-closed by je shodilo.
   */
  it('období u potvrzení načte, uloží a prázdné pošle jako null', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      certificates: [{
        certificate_reference: 'POT-1',
        payer_name: 'Předchozí plátce',
        payer_tax_identification: null,
        received_on: '2027-02-10',
        employment_from: '2026-01-01',
        employment_to: '2026-06-30',
        gross_income_minor_units: 3_000_000,
        advance_base_minor_units: 3_000_000,
        advance_tax_minor_units: 450_000,
        non_refundable_credit_minor_units: 257_000,
        child_credit_minor_units: 0,
        tax_bonus_minor_units: 0,
        evidence_status: 'verified',
        evidence_reference: 'doklad',
        missing_statutory_fields: [],
      }],
    }))
    m.saveAnnualSettlementCertificates.mockResolvedValue([])
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const from = wrapper.find('[data-test="certificate-employment-from"]')
    const to = wrapper.find('[data-test="certificate-employment-to"]')
    // DateInput zobrazuje datum v jazyce aplikace (ISO drží model a payload níže).
    expect((from.element as HTMLInputElement).value).toBe('01. 01. 2026')
    expect((to.element as HTMLInputElement).value).toBe('30. 06. 2026')
    // Nepovinné: prázdné pole nesmí formulář blokovat.
    expect(from.attributes('required')).toBeUndefined()
    expect(to.attributes('required')).toBeUndefined()

    // Konec se vymaže — „nevíme", ne „trvá dodnes".
    await to.setValue('')
    await wrapper.find('[data-test="annual-settlement-save-certificates"]').trigger('click')
    await flushPromises()

    const [, , payload] = m.saveAnnualSettlementCertificates.mock.calls[0]
    expect(payload[0].employment_from).toBe('2026-01-01')
    expect(payload[0].employment_to).toBeNull()
  })

  it('uloží podklady s row_version, aby souběžná úprava nepřepsala odpovědi', async () => {
    m.saveAnnualSettlementRequest.mockResolvedValue({})
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-save-request"]').trigger('click')
    await flushPromises()

    expect(m.saveAnnualSettlementRequest).toHaveBeenCalledWith(
      defaultYear,
      7,
      expect.objectContaining({
        row_version: 1,
        request_status: 'requested',
        request_evidence_reference: 'synthetic',
      }),
    )
  })

  it('hromadně nastaví stav žádosti vybraným lidem a seznam znovu načte', async () => {
    m.listAnnualSettlements.mockResolvedValue(
      listResponse([person(), person({ employee_id: 8, employee_name: 'Druhá osoba' })]),
    )
    m.bulkSetAnnualSettlementRequestStatus.mockResolvedValue({ created: 1, updated: 1 })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="annual-settlement-select-all"]').setValue(true)
    await wrapper.get('[data-test="annual-settlement-bulk-not-requested"]').trigger('click')
    await flushPromises()

    expect(m.bulkSetAnnualSettlementRequestStatus).toHaveBeenCalledWith(
      defaultYear,
      [7, 8],
      'not_requested',
    )
    expect(m.success).toHaveBeenCalled()
    expect(m.listAnnualSettlements).toHaveBeenCalledTimes(2)
  })

  /**
   * Zašedlé „Provést" musí říct TÝŽ důvod, který ho vypnul. Chybějící
   * oprávnění hlásilo „zaměstnanec o zúčtování nepožádal" — podklad, který
   * účetní marně hledala.
   */
  it('bez práva schvalovat nehlásí chybějící žádost, ale chybějící oprávnění', async () => {
    m.canWrite = false
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const settle = wrapper.get('[data-action="settle"]')
    expect(settle.attributes('disabled')).toBeDefined()
    expect(settle.attributes('data-reason'))
      .toBe('payroll.annual_settlement.settle_read_only')

    expect(wrapper.get('[data-test="annual-settlement-save-request-blocked"]').text())
      .toBe('payroll.annual_settlement.save_request_read_only')
  })

  /**
   * `performed === false` bez jediné překážky nechávalo tlačítko zašedlé úplně
   * mlčky: `:disabled` na `performed` koukalo, věta pod ním ne.
   */
  it('náhled bez provedení a bez překážek dostane vlastní důvod, ne ticho', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({ performed: false, blockers: [] }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const settle = wrapper.get('[data-action="settle"]')
    expect(settle.attributes('disabled')).toBeDefined()
    expect(settle.attributes('data-reason'))
      .toBe('payroll.annual_settlement.settle_unavailable')
  })

  it('read-only uživateli uzamkne celou evidenci jiné osoby uplatňující dítě', async () => {
    m.canWrite = false
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      request: {
        ...previewResponse().request,
        other_household_caregiver_status: 'present',
        other_household_caregivers: [{
          given_name: 'Jana',
          family_name: 'Syntetická',
          birth_date: '1990-01-01',
          months_mask: 'ANNNNNNNNNNN',
        }],
      },
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="annual-settlement-caregiver-fields"]')
      .attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="annual-settlement-save-request"]')
      .attributes('disabled')).toBeDefined()
  })

  it('uloží žádost i potvrzení bez volitelných odkazů na podklady', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      request: {
        ...previewResponse().request,
        request_evidence_reference: null,
      },
      certificates: [{
        certificate_reference: 'POT-BEZ-ODKAZU',
        payer_name: 'Předchozí plátce',
        payer_tax_identification: null,
        received_on: '2027-02-10',
        gross_income_minor_units: 0,
        advance_base_minor_units: 0,
        advance_tax_minor_units: 0,
        non_refundable_credit_minor_units: 0,
        child_credit_minor_units: 0,
        tax_bonus_minor_units: 0,
        evidence_status: 'verified',
        evidence_reference: null,
        missing_statutory_fields: [],
      }],
    }))
    m.saveAnnualSettlementRequest.mockResolvedValue({})
    m.saveAnnualSettlementCertificates.mockResolvedValue([])
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="annual-settlement-save-request"]').trigger('click')
    await flushPromises()
    expect(m.saveAnnualSettlementRequest).toHaveBeenCalledWith(
      defaultYear,
      7,
      expect.objectContaining({ request_evidence_reference: null }),
    )

    await wrapper.find('[data-test="annual-settlement-save-certificates"]').trigger('click')
    await flushPromises()
    expect(m.saveAnnualSettlementCertificates).toHaveBeenCalledWith(
      defaultYear,
      7,
      [expect.objectContaining({ evidence_reference: null })],
    )
  })
  /**
   * Seznam lidí nestránkoval vůbec — server posílal všechny zaměstnance firmy
   * a obrazovka je všechny vykreslila. Test hlídá, že stránka dotazuje
   * ohraničený výsek a že další stránku umí objednat.
   */
  it('žádá server o ohraničenou stránku a umí přejít na další', async () => {
    m.listAnnualSettlements.mockResolvedValue(
      listResponse([person()], { total: 60 }),
    )
    const wrapper = mountPage()
    await flushPromises()

    expect(m.listAnnualSettlements).toHaveBeenCalledWith(
      defaultYear,
      { limit: 25, offset: 0 },
      { search: '', state: 'all' },
    )

    m.listAnnualSettlements.mockResolvedValue(
      listResponse([person({ employee_id: 9, employee_name: 'Syntetická osoba B' })], {
        total: 60,
        offset: 25,
      }),
    )
    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()

    expect(m.listAnnualSettlements).toHaveBeenLastCalledWith(
      defaultYear,
      { limit: 25, offset: 25 },
      { search: '', state: 'all' },
    )
    expect(wrapper.text()).toContain('Syntetická osoba B')
  })

  /** Zúžení hledá na serveru a vrací stránku na začátek. */
  it('posílá zúžení na server a vrací se na první stránku', async () => {
    m.listAnnualSettlements.mockResolvedValue(
      listResponse([person()], { total: 60 }),
    )
    const wrapper = mountPage()
    await flushPromises()

    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    await next!.trigger('click')
    await flushPromises()

    const search = wrapper.get('[data-test="annual-settlement-search"]')
    await search.setValue('Novak')
    await search.trigger('change')
    await flushPromises()

    expect(m.listAnnualSettlements).toHaveBeenLastCalledWith(
      defaultYear,
      { limit: 25, offset: 0 },
      { search: 'Novak', state: 'all' },
    )

    const state = wrapper.get('[data-test="annual-settlement-state"]')
    await state.setValue('requested')
    await flushPromises()

    expect(m.listAnnualSettlements).toHaveBeenLastCalledWith(
      defaultYear,
      { limit: 25, offset: 0 },
      { search: 'Novak', state: 'requested' },
    )
  })

  it('hromadné zúčtování jde serverovou frontou a vypíše přeskočené s důvody', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const batch = {
      id: 91,
      tax_year: defaultYear,
      document_kind: 'annual_settlement_result',
      scope: 'all',
      status: 'completed',
      item_count: 3,
      succeeded_count: 1,
      failed_count: 0,
      skipped_count: 2,
      created_at: '2027-03-01 08:00:00',
      started_at: '2027-03-01 08:00:01',
      completed_at: '2027-03-01 08:00:05',
      updated_at: '2027-03-01 08:00:05',
    }
    const item = (id: number, status: string, code: string | null, message: string | null) => ({
      id,
      batch_id: 91,
      employee_id: id,
      employee_name: `Syntetická osoba ${id}`,
      status,
      attempt_count: 1,
      available_at: '2027-03-01 08:00:00',
      document_id: status === 'succeeded' ? 500 + id : null,
      last_error_code: code,
      last_error_message: message,
      completed_at: '2027-03-01 08:00:05',
      updated_at: '2027-03-01 08:00:05',
    })
    m.enqueueAnnualDocumentBatch.mockResolvedValue({ ...batch, status: 'queued', succeeded_count: 0, skipped_count: 0 })
    m.annualDocumentBatch.mockResolvedValue(batch)
    m.annualDocumentBatchItems.mockResolvedValue({
      items: [
        item(1, 'succeeded', null, null),
        item(2, 'skipped', 'annual_settlement_blocked',
          'Roční zúčtování nelze provést, překážky: declaration_not_signed, settlement_deadline_passed'),
        item(3, 'skipped', 'annual_settlement_exists', 'Osoba už je za rok zúčtovaná.'),
      ],
      total: 3,
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-action="settle-all"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(m.enqueueAnnualDocumentBatch).toHaveBeenCalledWith(
      'annual_settlement_result',
      defaultYear,
      'all',
      null,
    )
    const blocked = wrapper.get('[data-test="annual-settlement-bulk-blocked"]').text()
    expect(blocked).toContain('Syntetická osoba 2')
    expect(blocked).toContain('payroll.annual_settlement.blocker.declaration_not_signed')
    expect(blocked).toContain('payroll.annual_settlement.blocker.settlement_deadline_passed')
    expect(wrapper.get('[data-test="annual-settlement-bulk-settled"]').text())
      .toContain('Syntetická osoba 3')
    expect(wrapper.find('[data-test="annual-settlement-bulk-failures"]').exists()).toBe(false)
    confirm.mockRestore()
  })

  /**
   * Ročně uplatňované položky: načtou se z náhledu, odběry se oceňují pevnou
   * sazbou a PUT nenese id, částku odběrů ani měsíce mimo úroky.
   */
  it('ročně uplatňované položky vykreslí a uloží ve správném tvaru', async () => {
    const claims = {
      deductions: [
        {
          id: 12, kind: 'housing_interest', amount_minor_units: 15_000_000, quantity: null,
          months_mask: 7, housing_acquired_before_2021: true, counterparty_name: 'Banka',
          evidence_status: 'verified', evidence_reference: 'potvrzení', note: null,
        },
        {
          id: 13, kind: 'blood_donation', amount_minor_units: null, quantity: 2,
          months_mask: null, housing_acquired_before_2021: false, counterparty_name: null,
          evidence_status: 'unverified', evidence_reference: null, note: null,
        },
      ],
      spouse: {
        spouse_dependant_id: 5, child_dependant_id: 6, months_mask: 4095, ztp_p_months_mask: 3,
        spouse_income_minor_units: 4_000_000, evidence_status: 'verified',
        evidence_reference: 'doklad', note: null,
      },
      dependants: [
        { id: 5, relation: 'spouse', full_name: 'Manželka', birth_date: '1990-01-01', ztp_p: true },
        { id: 6, relation: 'child_own', full_name: 'Dítě', birth_date: '2025-05-05', ztp_p: false },
      ],
      statute: {
        counted_donation_values: { blood_donation: 300_000, organ_donation: 2_000_000, stem_cell_donation: 2_000_000 },
      },
    }
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({ annual_claims: claims }))
    m.saveAnnualSettlementAnnualClaims.mockResolvedValue({
      deductions: claims.deductions,
      spouse: claims.spouse,
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="annual-settlement-deduction"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="annual-settlement-deduction-evaluation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="annual-settlement-spouse-form"]').exists()).toBe(true)
    // Manželka má ZTP/P, takže se nabízí druhá řada měsíců.
    expect(wrapper.find('[data-test="annual-settlement-spouse-ztp-p"]').exists()).toBe(true)
    const housingMonths = wrapper.findAll('[data-test="annual-settlement-deduction-month"]')
    expect(housingMonths.filter(box => (box.element as HTMLInputElement).checked)).toHaveLength(3)

    await wrapper.find('[data-test="annual-settlement-save-claims"]').trigger('click')
    await flushPromises()

    const [, , payload] = m.saveAnnualSettlementAnnualClaims.mock.calls[0]
    expect(payload.deductions[0]).toEqual({
      kind: 'housing_interest',
      amount_minor_units: 15_000_000,
      quantity: null,
      months_mask: 7,
      housing_acquired_before_2021: true,
      counterparty_name: 'Banka',
      evidence_status: 'verified',
      evidence_reference: 'potvrzení',
      note: null,
    })
    expect(payload.deductions[1].amount_minor_units).toBeNull()
    expect(payload.deductions[1].quantity).toBe(2)
    expect(payload.deductions[1].months_mask).toBeNull()
    expect(payload.deductions[1]).not.toHaveProperty('id')
    expect(payload.spouse).toMatchObject({
      spouse_dependant_id: 5,
      child_dependant_id: 6,
      months_mask: 4095,
      ztp_p_months_mask: 3,
      spouse_income_minor_units: 4_000_000,
    })
  })

  it('bez manžela v evidenci ukáže nápovědu s odkazem na kartu zaměstnance', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      annual_claims: { deductions: [], spouse: null, dependants: [], statute: { counted_donation_values: {} } },
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="annual-settlement-spouse-missing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="annual-settlement-spouse-form"]').exists()).toBe(false)
  })

  it('sleva za umístění dítěte: sekce jen od roku 2027 a uložení posílá řádek na dítě', async () => {
    const dependants = [
      { id: 6, relation: 'child_own', full_name: 'Dítě', birth_date: '2023-05-05', ztp_p: false },
    ]
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      annual_claims: {
        deductions: [], spouse: null, preschool: [], preschool_available: false, dependants,
        statute: { counted_donation_values: {} },
      },
    }))
    const before = mountPage()
    await flushPromises()
    await before.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()
    expect(before.find('[data-test="annual-settlement-preschool"]').exists()).toBe(false)

    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      annual_claims: {
        deductions: [], spouse: null, preschool: [], preschool_available: true, dependants,
        statute: { counted_donation_values: {} },
      },
    }))
    m.saveAnnualSettlementAnnualClaims.mockResolvedValue({ deductions: [], spouse: null, preschool: [] })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="annual-settlement-preschool-add"]').trigger('click')
    await wrapper.find('[data-test="annual-settlement-preschool-expenses"]').setValue('18000,50')
    await wrapper.find('[data-test="annual-settlement-save-claims"]').trigger('click')
    await flushPromises()

    const [, , payload] = m.saveAnnualSettlementAnnualClaims.mock.calls[0]
    expect(payload.preschool).toEqual([{
      child_dependant_id: 6,
      facility_kind: 'kindergarten',
      facility_name: null,
      expenses_minor_units: 1_800_050,
      households_count: 1,
      agreed_cap_minor_units: null,
      claimed_by_other_taxpayer: false,
      claimed_as_business_expense: false,
      grandchild_care_replaces_parents: false,
      other_supporting_taxpayer: false,
      other_taxpayer_name: null,
      other_taxpayer_claimed_minor_units: null,
      other_taxpayer_confirmation_reference: null,
      agreement_reference: null,
      evidence_status: 'unverified',
      evidence_reference: null,
      note: null,
    }])
  })

  it('u střídavé péče nabídne potvrzení druhého poplatníka a odešle ho', async () => {
    const dependants = [
      { id: 6, relation: 'child_own', full_name: 'Dítě', birth_date: '2023-05-05', ztp_p: false },
    ]
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      annual_claims: {
        deductions: [], spouse: null, preschool: [], preschool_available: true, dependants,
        statute: { counted_donation_values: {} },
      },
    }))
    m.saveAnnualSettlementAnnualClaims.mockResolvedValue({ deductions: [], spouse: null, preschool: [] })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="annual-settlement-preschool-add"]').trigger('click')
    expect(wrapper.find('[data-test="annual-settlement-preschool-other-confirmation"]').exists()).toBe(false)
    await wrapper.find('[data-test="annual-settlement-preschool-expenses"]').setValue('30000')
    await wrapper.find('[data-test="annual-settlement-preschool-households"]').setValue(2)
    const block = wrapper.find('[data-test="annual-settlement-preschool-other-confirmation"]')
    expect(block.exists()).toBe(true)
    const inputs = block.findAll('input')
    await inputs[0].setValue('Druhý Rodič')
    await inputs[1].setValue('12450')
    await inputs[2].setValue('Potvrzení 2027')
    await wrapper.find('[data-test="annual-settlement-save-claims"]').trigger('click')
    await flushPromises()

    const [, , payload] = m.saveAnnualSettlementAnnualClaims.mock.calls[0]
    expect(payload.preschool[0]).toMatchObject({
      households_count: 2,
      other_taxpayer_name: 'Druhý Rodič',
      other_taxpayer_claimed_minor_units: 1_245_000,
      other_taxpayer_confirmation_reference: 'Potvrzení 2027',
      agreement_reference: null,
    })
  })

  it('rozpis nezdanitelných částí a důvody blokátoru slevy na manžela vypíše', async () => {
    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        tax_base_before_deductions_minor_units: 60_000_000,
        non_taxable_deductions_minor_units: 1_000_000,
        enforcement_credit_minor_units: 50_000,
        trace: {
          non_taxable: {
            donation: {
              claimed_minor_units: 500_000, threshold_met: true,
              cap_minor_units: 9_000_000, deducted_minor_units: 500_000,
            },
          },
          credits: { spouse: { months: 12, ztp_p_months: 2, amount_minor_units: 2_494_800 } },
        },
      }),
    }))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="annual-settlement-base-before-deductions"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="annual-settlement-trace-donation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="annual-settlement-trace-enforcement"]').exists()).toBe(true)

    m.previewAnnualSettlement.mockResolvedValue(previewResponse({
      result: result({
        performed: false,
        blockers: ['spouse_credit_conditions_not_met'],
        trace: { spouse_credit_failures: ['spouse_income_over_limit', 'neznamy_kod'] },
      }),
    }))
    const blocked = mountPage()
    await flushPromises()
    await blocked.find('[data-test="annual-settlement-person"]').trigger('click')
    await flushPromises()

    const failures = blocked.find('[data-test="annual-settlement-spouse-failures"]')
    expect(failures.text()).toContain('payroll.annual_settlement.spouse_failure.spouse_income_over_limit')
    expect(failures.text()).not.toContain('neznamy_kod')
  })
})
