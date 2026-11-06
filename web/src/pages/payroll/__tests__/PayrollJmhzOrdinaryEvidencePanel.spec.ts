import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  confirm: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzOrdinaryEvidence: m.get,
    confirmJmhzOrdinaryEvidence: m.confirm,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
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

vi.mock('vue-router', () => ({
  RouterLink: {
    props: ['to'],
    template: '<a :href="to"><slot /></a>',
  },
}))

const guidanceCalls = vi.hoisted(() => ({ count: 0 }))
vi.mock('@/pages/payroll/jmhzEvidenceGuidance', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/pages/payroll/jmhzEvidenceGuidance')>()
  return {
    ...original,
    jmhzEvidenceGuidance: (...args: Parameters<typeof original.jmhzEvidenceGuidance>) => {
      guidanceCalls.count++
      return original.jmhzEvidenceGuidance(...args)
    },
  }
})

import PayrollJmhzOrdinaryEvidencePanel from '@/pages/payroll/PayrollJmhzOrdinaryEvidencePanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

describe('PayrollJmhzOrdinaryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
      }],
      evidences: [],
    })
    m.confirm.mockResolvedValue({
      id: 31,
      revision_id: 18,
      confirmed_at: '2026-08-13T12:00:00Z',
      source_manifest_sha256: 'a'.repeat(64),
    })
  })

  it('u běžných vztahů nevyžaduje měsíční checkboxy ani jednotlivé karty', async () => {
    m.get.mockResolvedValue({
      scopes: Array.from({ length: 1000 }, (_, index) => ({
        employee_id: index + 1,
        employment_id: index + 101,
        employee_name: `Osoba ${index + 1}`,
        confirmed: false,
        resolution: 'automatic_on_preparation',
        attention_code: null,
        attention_message: null,
      })),
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('jmhz_evidence_automatic_count')
    expect(m.confirm).not.toHaveBeenCalled()
  })

  /**
   * Regrese: dokud šla evidence potvrdit jen za revizi, firma s víc lidmi
   * neměla kde potvrdit druhého — panel uměl jednu sadu zaškrtávátek na revizi.
   */
  it('ukáže jednotlivě jen vztahy, které skutečně vyžadují pozornost', async () => {
    m.get.mockResolvedValue({
      scopes: [
        {
          employee_id: 11,
          employment_id: 101,
          employee_name: 'Osoba A',
          confirmed: false,
          resolution: 'attention_required',
          attention_code: 'jmhz_ordinary_evidence_monthly_exception_required',
          attention_message: 'Doplňte měsíční údaje výjimky.',
        },
        {
          employee_id: 12,
          employment_id: 102,
          employee_name: 'Osoba B',
          confirmed: true,
          resolution: 'confirmed',
          attention_code: null,
          attention_message: null,
        },
      ],
      evidences: [{
        id: 30,
        employee_id: 12,
        employment_id: 102,
        confirmed_at: '2026-08-13T12:00:00Z',
        source_manifest_sha256: 'b'.repeat(64),
      }],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]')).toHaveLength(1)
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.get('[data-test="jmhz-ordinary-evidence-pending"]').text())
      .toContain('jmhz_evidence_pending')
    expect(wrapper.text()).toContain('Doplňte měsíční údaje výjimky.')
    expect(wrapper.text()).toContain('jmhz_guidance.actions.employment')
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).not.toContain('jmhz_ordinary_evidence_monthly_exception_required')
    expect(m.confirm).not.toHaveBeenCalled()
  })

  it('vede evidovanou srážku přímo do agendy srážek bez interního kódu', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_deduction_conflict',
        attention_message: 'Revize obsahuje evidovanou srážku ze mzdy.',
      }],
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('a').attributes('href')).toBe('/payroll/enforcement?person=11')
    expect(wrapper.text()).toContain('jmhz_guidance.actions.deductions')
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).not.toContain('jmhz_ordinary_evidence_deduction_conflict')
  })

  it('vede starou revizi k novému přepočtu místo na neexistující detail vztahu', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_profile_missing',
        attention_message: 'Tato revize vznikla před doplněním podkladů JMHZ. Mzdu znovu přepočítejte a schvalte.',
      }],
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('a').attributes('href')).toBe('/payroll/runs?period=2026-08')
    expect(wrapper.text()).toContain('jmhz_guidance.actions.run')
    expect(wrapper.get('[data-test="jmhz-evidence-guidance"]').text()).not.toContain('jmhz_ordinary_evidence_profile_missing')
  })

  /**
   * Karta se načítá sama při otevření záložky. Bez tlačítka u chybové hlášky
   * neexistovala žádná cesta zpátky kromě přenačtení celé stránky.
   */
  it('po neúspěšném načtení nabídne opakování a zkusí to znovu', async () => {
    m.get.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    const retry = wrapper.get('[data-test="jmhz-ordinary-evidence-retry-18"]')
    m.get.mockResolvedValue({ scopes: [], evidences: [] })
    await retry.trigger('click')
    await flushPromises()

    expect(m.get).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="jmhz-ordinary-evidence-retry-18"]').exists()).toBe(false)
  })

  /** „2026-07" vedle „01.07.2026" ve zbytku appky vypadá jako useknuté datum. */
  it('období karty ukáže česky, ne jako strojové YYYY-MM', async () => {
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('srpen 2026')
    expect(wrapper.text()).not.toContain('2026-08')
  })

  /**
   * Regrese: firma s 226 vztahy zamrazila prohlížeč. Panel kreslil kartu
   * s návodem, odkazy a technickými údaji ke KAŽDÉMU vztahu naráz a návod
   * počítal v šabloně osmkrát na kartu při každém překreslení. UI mezd musí
   * unést 500 lidí: stejné problémy se seskupí, vztahy se ukážou po stránkách
   * a návod se spočítá jednou na vztah.
   */
  it('u 500 vztahů s výjimkou nevykreslí stovky karet a návod nepočítá opakovaně', async () => {
    const codes = [
      'jmhz_ordinary_evidence_profile_missing',
      'jmhz_ordinary_evidence_monthly_exception_required',
      'jmhz_ordinary_evidence_deduction_conflict',
      'jmhz_ordinary_evidence_catalog_mismatch',
    ]
    const count = 500
    m.get.mockResolvedValue({
      scopes: Array.from({ length: count }, (_, index) => ({
        employee_id: index + 1,
        employment_id: index + 1001,
        employee_name: `Osoba ${index + 1}`,
        confirmed: false,
        resolution: 'attention_required',
        attention_code: codes[index % codes.length],
        attention_message: `Syntetická zpráva ${index + 1}`,
        attention_context: { field: 'deep_mining_work_applies' },
      })),
      evidences: [],
    })
    guidanceCalls.count = 0
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-ordinary-evidence-pending"]').text())
      .toContain('jmhz_evidence_pending')
    expect.soft(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]').length)
      .toBeLessThanOrEqual(codes.length * 8)
    expect.soft(wrapper.findAll('a').length).toBeLessThan(80)
    expect.soft(guidanceCalls.count).toBeLessThanOrEqual(count)
    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-group"]')).toHaveLength(codes.length)

    const callsAfterMount = guidanceCalls.count
    await wrapper.get('[data-test="jmhz-ordinary-evidence-list-18-0-toggle"]').trigger('click')
    expect(wrapper.findAll('[data-test="jmhz-ordinary-evidence-scope"]').length)
      .toBeLessThanOrEqual(25 + (codes.length - 1) * 8)
    await wrapper.get('[data-test="jmhz-ordinary-evidence-list-18-0-search"]').setValue('Osoba 497')
    expect(wrapper.get('[data-test="jmhz-ordinary-evidence-list-18-0"]').text()).toContain('Osoba 497')
    expect(guidanceCalls.count).toBe(callsAfterMount)
  })

  /**
   * Podpora zaměstnávání OZP (IN28): datum úhrady mzdy včetně pojistného
   * (10347) a hotovost (10348) zadává účetní přímo u vztahu; potvrzení nese
   * IN28 = Ano a výdělek OZP. Bez práva zápisu se formulář neukáže.
   */
  it('u vztahu s podporou OZP potvrdí datum úhrady a hotovost', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_ozp_wage_required',
        attention_message: 'Doplňte výdělek OZP.',
      }],
      evidences: [],
    })
    const stubs = {
      DateInput: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
      },
    }
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
      global: { stubs },
    })
    await flushPromises()

    const confirm = wrapper.get('[data-test="jmhz-ozp-confirm-101"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="jmhz-ozp-payment-date-101"]').setValue('2026-09-14')
    await wrapper.get('[data-test="jmhz-ozp-cash-101"]').setValue('no')
    await wrapper.get('[data-test="jmhz-ozp-wage-form-101"]').trigger('submit')
    await flushPromises()

    expect(m.confirm).toHaveBeenCalledWith(18, 101, expect.any(String), {
      payment_date: '2026-09-14',
      cash_paid: false,
    })
    expect(m.get).toHaveBeenCalledTimes(2)

    m.canWrite.mockReturnValue(false)
    const readOnly = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
      global: { stubs },
    })
    await flushPromises()
    expect(readOnly.find('[data-test="jmhz-ozp-wage-form-101"]').exists()).toBe(false)
  })

  /**
   * Od 01/2027 se k výdělku OZP posílají i dny odvodu sociálního (10570)
   * a zdravotního pojištění (10571), ale jen oba vyplněné.
   */
  it('u výdělku OZP pošle vyplněné dny odvodu pojistného', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_ozp_wage_required',
        attention_message: 'Doplňte výdělek OZP.',
      }],
      evidences: [],
    })
    const stubs = {
      DateInput: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
      },
    }
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
      global: { stubs },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-ozp-payment-date-101"]').setValue('2026-09-14')
    await wrapper.get('[data-test="jmhz-ozp-cash-101"]').setValue('no')
    await wrapper.get('[data-test="jmhz-ozp-social-date-101"]').setValue('2026-09-15')
    await wrapper.get('[data-test="jmhz-ozp-health-date-101"]').setValue('2026-09-16')
    await wrapper.get('[data-test="jmhz-ozp-wage-form-101"]').trigger('submit')
    await flushPromises()

    expect(m.confirm).toHaveBeenCalledWith(18, 101, expect.any(String), {
      payment_date: '2026-09-14',
      cash_paid: false,
      social_insurance_paid_on: '2026-09-15',
      health_insurance_paid_on: '2026-09-16',
    })
  })

  /** Práce kategorie 4 od 01/2027: počet směn (10580) zadává účetní přímo u vztahu. */
  it('u rizikové práce potvrdí počet směn', async () => {
    m.get.mockResolvedValue({
      scopes: [{
        employee_id: 11,
        employment_id: 101,
        employee_name: 'Osoba A',
        confirmed: false,
        resolution: 'attention_required',
        attention_code: 'jmhz_ordinary_evidence_risk_shifts_required',
        attention_message: 'Doplňte počet směn.',
      }],
      evidences: [],
    })
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-risk-shifts-confirm-101"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="jmhz-risk-shifts-count-101"]').setValue('20')
    await wrapper.get('[data-test="jmhz-risk-shifts-form-101"]').trigger('submit')
    await flushPromises()

    expect(m.confirm).toHaveBeenCalledWith(18, 101, expect.any(String), undefined, 20)
  })

  it('v režimu jen pro čtení nepovolí potvrzení', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})
