import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'

// H28 — rodná čísla dětí a manžela/manželky: formulář dostane jen masku, nové číslo
// jde zadat dál, prázdné pole uložené číslo ponechá, odebrání je výslovné a plnou
// hodnotu ukáže jen auditované odhalení pro uživatele s právem zápisu do daní.
const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  canWrite: true,
  get: vi.fn(),
  saveInputs: vi.fn(),
  saveProfile: vi.fn(),
  revealFamily: vi.fn(),
}))

vi.mock('@/api/taxReturn', () => ({
  taxReturnApi: {
    get: m.get,
    saveInputs: m.saveInputs,
    reportPdfUrl: () => '/api/tax-return/fo/2025/pdf',
    xmlUrl: () => '/api/tax-return/fo/2025/xml',
    previewXmlUrl: () => '/api/tax-return/fo/2025/xml/preview',
    advanceOverrides: vi.fn().mockResolvedValue({ overrides: [], schedules: [] }),
    advances: vi.fn().mockResolvedValue({ schedules: [] }),
    insurance: vi.fn().mockResolvedValue(null),
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listPeriods: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/tax', () => ({ taxApi: { saveProfile: m.saveProfile, revealFamily: m.revealFamily } }))
vi.mock('@/api/taxEvidence', () => ({ taxEvidenceApi: { closing: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: vi.fn() }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { taxpayer_type: 'fo' } }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => m.canWrite }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: (_source: string, year: { value: number }) => computed(() => [year.value]) }
})
vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return {
    ...actual,
    useI18n: () => ({
      t: (key: string) => key,
      tm: () => [],
      rt: (v: unknown) => String(v),
      te: () => false,
      locale: { value: 'cs' },
    }),
  }
})

import IncomeTaxReport from '@/pages/reports/IncomeTaxReport.vue'

const months = Array.from({ length: 12 }, (_, i) => ({ month: i + 1, order: 1, ztpp: false, claimed: true }))

function makeState() {
  return {
    return: {
      year: 2025, type: 'fo', variant: 'radne', variant_seq: 1, status: 'draft', row_version: 1, inputs: {},
      last_submission_id: null, final_snapshot_id: null, finalized_at: null, finalized_by: null, updated_at: null,
    },
    form_code: 'dpfdp7',
    variant: 'radne',
    variant_seq: 1,
    available_variants: [],
    last_known_tax_suggested: null,
    computed: { lines: [], tax: 0, advances_paid: 0, balance_due: 0, projection: null, summary: {}, warnings: [] },
    podklady: {
      accounting_mode: 'double_entry',
      profile: {
        activities: [],
        osvc_months: [],
        children: [
          { id: 11, first_name: 'Anna', last_name: 'Rodinná', birth_number: null, birth_number_masked: '••••••••03',
            has_birth_number: true, birth_date: null, shared_household_proved: true, other_parent_not_claimed_proved: true, months },
          { id: 12, first_name: 'Cyril', last_name: 'Rodinný', birth_number: null, birth_number_masked: null,
            has_birth_number: false, birth_date: '2019-05-01', shared_household_proved: true, other_parent_not_claimed_proved: true, months },
        ],
        spouse_claim: { id: 5, first_name: 'Eva', last_name: 'Rodinná', birth_number: null, birth_number_masked: '••••••••36',
          has_birth_number: true, birth_date: null, eligible_months: 12, ztpp: false, own_income: 0,
          income_proved: true, shared_household_proved: true, child_under_three_proved: true },
      },
    },
    warnings: [],
    tax_losses: { losses: [], available_total: 0, suggested: 0, carry_years: 5 },
    prefinalize_check: null,
    snapshot: null,
    constants_year: 2025,
  }
}

let wrapper: VueWrapper | null = null

async function mountPage() {
  m.get.mockResolvedValue(makeState())
  wrapper = mount(IncomeTaxReport, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('IncomeTaxReport.vue: rodná čísla rodiny', () => {
  beforeEach(() => {
    m.route = reactive({ query: {} })
    m.canWrite = true
    m.get.mockReset()
    m.saveInputs.mockReset().mockImplementation(async () => makeState())
    m.saveProfile.mockReset().mockImplementation(async (payload: unknown) => payload)
    m.revealFamily.mockReset().mockResolvedValue({
      year: 2025, children: [{ id: 11, birth_number: '1504110003' }], spouse: { id: 5, birth_number: '8555101236' },
    })
  })
  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
  })

  it('ukáže jen masku a pole pro nové číslo nechá prázdné', async () => {
    const page = await mountPage()
    const inputs = page.findAll('[data-test="tax-child-birth-number"]')
    expect(inputs).toHaveLength(2)
    expect((inputs[0].element as HTMLInputElement).value).toBe('')
    expect(inputs[0].attributes('placeholder')).toBe('••••••••03')
    expect(inputs[1].attributes('placeholder')).toBe('taxReturn.birth_number')
    expect(page.get('[data-test="tax-spouse-birth-number"]').attributes('placeholder')).toBe('••••••••36')
    expect(page.findAll('[data-test="tax-child-birth-number-status"]')).toHaveLength(1)
    expect(page.html()).not.toContain('1504110003')
  })

  it('uložení pošle id a prázdné číslo (ponechat), nové číslo i výslovné odebrání', async () => {
    const page = await mountPage()
    const inputs = page.findAll('[data-test="tax-child-birth-number"]')
    await inputs[1].setValue('190501/0001')
    await page.get('[data-test="tax-spouse-birth-number-clear"]').trigger('click')
    await (page.vm as unknown as { save: () => Promise<void> }).save()
    await flushPromises()

    expect(m.saveProfile).toHaveBeenCalledTimes(1)
    const payload = m.saveProfile.mock.calls[0][0]
    expect(payload.year).toBe(2025)
    expect(payload.children[0]).toMatchObject({ id: 11, birth_number: null })
    expect(payload.children[0].birth_number_clear).toBeFalsy()
    expect(payload.children[1]).toMatchObject({ id: 12, birth_number: '190501/0001' })
    expect(payload.spouse_claim).toMatchObject({ id: 5, birth_number_clear: true, birth_number: '' })
  })

  it('odhalení zavolá auditovaný endpoint a ukáže plná čísla jen u odpovídajících řádků', async () => {
    const page = await mountPage()
    await page.get('[data-test="tax-family-reveal"]').trigger('click')
    await flushPromises()

    expect(m.revealFamily).toHaveBeenCalledWith(2025)
    expect(page.get('[data-test="tax-child-birth-number-status"]').text()).toContain('1504110003')
    expect(page.get('[data-test="tax-spouse-birth-number-status"]').text()).toContain('8555101236')

    await page.get('[data-test="tax-family-reveal"]').trigger('click')
    await flushPromises()
    expect(page.html()).not.toContain('1504110003')
  })

  it('bez práva zápisu do daní se odhalit ani odebrat nedá', async () => {
    m.canWrite = false
    const page = await mountPage()
    expect(page.find('[data-test="tax-family-reveal"]').exists()).toBe(false)
    expect(page.find('[data-test="tax-child-birth-number-clear"]').exists()).toBe(false)
    expect(page.get('[data-test="tax-child-birth-number-status"]').text()).toContain('••••••••03')
  })
})
