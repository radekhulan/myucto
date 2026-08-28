import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  listAnnualSettlements: vi.fn(),
  previewAnnualSettlement: vi.fn(),
  saveAnnualSettlementRequest: vi.fn(),
  settleAnnualSettlement: vi.fn(),
  saveAnnualSettlementCertificates: vi.fn(),
  downloadDocument: vi.fn(),
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
    settleAnnualSettlement: m.settleAnnualSettlement,
    saveAnnualSettlementCertificates: m.saveAnnualSettlementCertificates,
    downloadDocument: m.downloadDocument,
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
})
