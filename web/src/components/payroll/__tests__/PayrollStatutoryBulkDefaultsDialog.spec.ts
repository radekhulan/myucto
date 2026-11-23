import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { statutoryBulkDefaultsPreview: m.preview, statutoryBulkDefaultsApply: vi.fn() },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import PayrollStatutoryBulkDefaultsDialog from '../PayrollStatutoryBulkDefaultsDialog.vue'

function preview(frozenThrough: string | null, monthStart: string) {
  return {
    effective_on: monthStart,
    month_start: monthStart,
    frozen_through: frozenThrough,
    summary: {
      people: 0,
      ready: 0,
      nothing_to_add: 0,
      excluded: 0,
      sections: { tax_residences: 0, social_jurisdictions: 0, social_discount_claims: 0 },
      declaration_missing: 0,
      unsigned_declaration_withholding_risk: 0,
      health_insurer_missing: 0,
    },
    ready_employee_ids: [],
    declaration_missing_employee_ids: [],
    excluded: [],
    health_insurer_missing: [],
    unsigned_declaration_withholding_risk: [],
    people: [],
  }
}

function mountDialog(effectiveOn: string) {
  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
  return mount(PayrollStatutoryBulkDefaultsDialog, {
    props: { effectiveOn, periodEditable: true },
    global: {
      plugins: [i18n],
      stubs: { Modal: { props: ['title'], template: '<div><slot /><slot name="footer" /></div>' } },
    },
  })
}

describe('PayrollStatutoryBulkDefaultsDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // Náhled za říjen psal zároveň „schváleno do února, platí od dalšího měsíce".
  it('u měsíce po schváleném období nemíchá schválené období do textu', async () => {
    m.preview.mockResolvedValue(preview('2026-02-28', '2026-10-01'))
    const wrapper = mountDialog('2026-10-01')
    await flushPromises()

    expect(wrapper.find('[data-test="statutory-bulk-frozen"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('mzdový běh za říjen 2026. Údaje budou platit od 01. 10. 2026.')
  })

  it('u schváleného měsíce řekne, od kdy údaje skutečně platí', async () => {
    m.preview.mockResolvedValue(preview('2026-02-28', '2026-02-01'))
    const wrapper = mountDialog('2026-02-01')
    await flushPromises()

    expect(wrapper.text()).toContain('Údaje budou platit od 01. 03. 2026.')
    expect(wrapper.get('[data-test="statutory-bulk-frozen"]').text())
      .toBe('Mzdy jsou schválené do 28. 02. 2026. Do schváleného období se nezapisuje, proto údaje začnou platit až od 01. 03. 2026.')
  })
})
