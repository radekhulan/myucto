import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  termsBulkPreview: vi.fn(),
  termsBulkApply: vi.fn(),
  employmentJmhzEvidenceOptions: vi.fn(() => Promise.resolve({ countries: [{ code: 'CZ', label: 'Česko' }] })),
  searchJmhzMunicipalities: vi.fn(() => Promise.resolve([])),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    termsBulkPreview: m.termsBulkPreview,
    termsBulkApply: m.termsBulkApply,
    employmentJmhzEvidenceOptions: m.employmentJmhzEvidenceOptions,
    searchJmhzMunicipalities: m.searchJmhzMunicipalities,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      (params ? `${key} ${Object.values(params).join(' ')}` : key),
    locale: ref('cs-CZ'),
  }),
}))

import PayrollTermsBulkEditDialog from '@/components/payroll/PayrollTermsBulkEditDialog.vue'

function item(id: number, name: string, overrides: Record<string, unknown> = {}) {
  return {
    employment_id: id,
    employee_id: id + 100,
    full_name: name,
    employment_code: `DPP-${id}`,
    relation_type: 'dpp',
    state: 'editable',
    reason: null,
    locked_period: null,
    new_version_from: null,
    effective_from: '2026-06-01',
    remuneration_type: null,
    hourly_rate_minor: null,
    probable_hourly_earning_minor: null,
    cz_isco_code: null,
    activity_code: 'T',
    work_place: null,
    jmhz_workplace_municipality_code: null,
    ...overrides,
  }
}

function preview() {
  return {
    period_start: '2026-08-01',
    relation_type: 'dpp',
    summary: { employments: 3, editable: 2, excluded: 1 },
    editable_employment_ids: [12, 13],
    activity_codes: [{ code: 'T', label: 'DPP' }, { code: 'U', label: 'Druhá DPP' }],
    items: [
      item(12, 'Syntetická osoba'),
      item(13, 'Druhá syntetická osoba'),
      item(14, 'Zamčená syntetická osoba', { state: 'excluded', reason: 'run_locked', locked_period: '2026-08-01' }),
    ],
  }
}

const MOUNT = { global: { stubs: { teleport: true, RouterLink: { template: '<a><slot /></a>' }, CzIscoPicker: true } } }

describe('PayrollTermsBulkEditDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('načte DPP vztahy měsíce, pošle jen vybrané vztahy a vyplněné údaje v režimu doplnit', async () => {
    m.termsBulkPreview.mockResolvedValue(preview())
    m.termsBulkApply.mockResolvedValue({
      period_start: '2026-08-01',
      mode: 'fill',
      counts: { applied: 1, skipped: 0, failed: 0 },
      applied: [{ employment_id: 12, employee_id: 112, full_name: 'Syntetická osoba', fields: ['hourly_rate'], kind: 'correction', effective_from: '2026-06-01' }],
      skipped: [],
      failed: [],
    })

    const wrapper = mount(PayrollTermsBulkEditDialog, { ...MOUNT, props: { periodStart: '2026-08-01' } })
    await flushPromises()

    expect(m.termsBulkPreview).toHaveBeenCalledWith({ period_start: '2026-08-01', relation_type: 'dpp', employment_ids: null })
    expect(wrapper.get('[data-test="terms-bulk-excluded"]').text()).toContain('Zamčená syntetická osoba')
    expect(wrapper.get('[data-test="terms-bulk-apply"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="terms-bulk-item-13"]').setValue(false)
    await wrapper.get('[data-test="terms-bulk-hourly-rate"]').setValue('250,50')
    await wrapper.get('[data-test="terms-bulk-activity"]').setValue('U')
    await wrapper.get('[data-test="terms-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.termsBulkApply).toHaveBeenCalledWith({
      period_start: '2026-08-01',
      mode: 'fill',
      changes: { hourly_rate_minor: 25050, activity_code: 'U' },
      employment_ids: [12],
    })
    expect(wrapper.get('[data-test="terms-bulk-applied"]').text()).toBe('1')
  })

  it('u zúčtované verze ohlásí novou verzi od měsíce a výsledek ji odliší od opravy', async () => {
    const data = preview()
    data.items[0] = item(12, 'Syntetická osoba', { new_version_from: '2026-09-01' })
    m.termsBulkPreview.mockResolvedValue(data)
    m.termsBulkApply.mockResolvedValue({
      period_start: '2026-08-01',
      mode: 'fill',
      counts: { applied: 2, skipped: 0, failed: 0 },
      applied: [
        { employment_id: 12, employee_id: 112, full_name: 'Syntetická osoba', fields: ['hourly_rate'], kind: 'new_version', effective_from: '2026-09-01' },
        { employment_id: 13, employee_id: 113, full_name: 'Druhá syntetická osoba', fields: ['hourly_rate'], kind: 'correction', effective_from: '2026-06-01' },
      ],
      skipped: [],
      failed: [],
    })

    const wrapper = mount(PayrollTermsBulkEditDialog, { ...MOUNT, props: { periodStart: '2026-08-01' } })
    await flushPromises()
    expect(wrapper.get('[data-test="terms-bulk-new-version-12"]').text()).toContain('09/2026')
    expect(wrapper.find('[data-test="terms-bulk-new-version-13"]').exists()).toBe(false)

    await wrapper.get('[data-test="terms-bulk-hourly-rate"]').setValue('250')
    await wrapper.get('[data-test="terms-bulk-apply"]').trigger('click')
    await flushPromises()

    const rows = wrapper.get('[data-test="terms-bulk-rows"]').text()
    expect(rows).toContain('payroll.terms_bulk.row_new_version 09/2026')
    expect(rows).toContain('payroll.terms_bulk.row_correction')
  })

  it('pravděpodobný výdělek bez odůvodnění neodešle', async () => {
    m.termsBulkPreview.mockResolvedValue(preview())

    const wrapper = mount(PayrollTermsBulkEditDialog, { ...MOUNT, props: { periodStart: '2026-08-01' } })
    await flushPromises()
    await wrapper.get('[data-test="terms-bulk-probable"]').setValue('260')

    expect(wrapper.get('[data-test="terms-bulk-apply"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.terms_bulk.blocked.rationale_required')

    await wrapper.get('[data-test="terms-bulk-rationale"]').setValue('Syntetické odůvodnění')
    expect(wrapper.get('[data-test="terms-bulk-apply"]').attributes('disabled')).toBeUndefined()
  })

  it('výsledek ukáže vynechané a chybné vztahy s důvodem', async () => {
    m.termsBulkPreview.mockResolvedValue(preview())
    m.termsBulkApply.mockResolvedValue({
      period_start: '2026-08-01',
      mode: 'overwrite',
      counts: { applied: 0, skipped: 1, failed: 1 },
      applied: [],
      skipped: [{ employment_id: 12, employee_id: 112, full_name: 'Syntetická osoba', reason: 'period_settled' }],
      failed: [{ employment_id: 13, employee_id: 113, full_name: 'Druhá syntetická osoba', message: 'Syntetická chyba' }],
    })

    const wrapper = mount(PayrollTermsBulkEditDialog, { ...MOUNT, props: { periodStart: '2026-08-01' } })
    await flushPromises()
    await wrapper.get('[data-test="terms-bulk-mode-overwrite"]').setValue(true)
    await wrapper.get('[data-test="terms-bulk-hourly-rate"]').setValue('250')
    await wrapper.get('[data-test="terms-bulk-apply"]').trigger('click')
    await flushPromises()

    expect(m.termsBulkApply.mock.calls[0]?.[0]).toMatchObject({ mode: 'overwrite', employment_ids: [12, 13] })
    const rows = wrapper.get('[data-test="terms-bulk-rows"]').text()
    expect(rows).toContain('payroll.terms_bulk.reason.period_settled')
    expect(rows).toContain('Syntetická chyba')
  })
})
