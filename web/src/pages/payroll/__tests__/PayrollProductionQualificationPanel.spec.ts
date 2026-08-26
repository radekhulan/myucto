import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  runsPage: vi.fn(),
  qualifyProduction: vi.fn(),
  searchDocuments: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    runsPage: m.runsPage,
    qualifyProduction: m.qualifyProduction,
  },
}))
vi.mock('@/api/documents', () => ({
  documentsApi: { search: m.searchDocuments },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollProductionQualificationPanel from '@/pages/payroll/PayrollProductionQualificationPanel.vue'

const state = {
  supplier_id: 7,
  status: 'qualification_required' as const,
  start_period: '2026-01',
  row_version: 4,
  activated_at: null,
  suspended_at: null,
  created_at: null,
  updated_at: null,
}

describe('PayrollProductionQualificationPanel', () => {
  beforeEach(() => {
    m.runsPage.mockResolvedValue({
      runs: [
        { id: 11, period_start: '2026-04-01', revision_status: 'approved', revision_kind: 'regular' },
        { id: 12, period_start: '2026-05-01', revision_status: 'approved', revision_kind: 'correction' },
      ],
      total: 2,
      limit: 100,
      offset: 0,
    })
    m.searchDocuments.mockResolvedValue([
      { id: 91, title: 'Protokol mezd', original_name: 'protokol.pdf', scope: 'company' },
      { id: 92, title: 'Osobní poznámka', original_name: 'private.pdf', scope: 'user' },
    ])
    m.qualifyProduction.mockResolvedValue({ state: { ...state, status: 'active', row_version: 5 } })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('odesílá jen ID skutečného firemního dokumentu a žádný klientský hash', async () => {
    const wrapper = mount(PayrollProductionQualificationPanel, {
      props: { state, matrixVersion: '2026-08' },
    })
    await flushPromises()

    await wrapper.get('[data-test="first-run"]').setValue('11')
    await wrapper.get('[data-test="second-run"]').setValue('12')
    await wrapper.get('[data-test="correction-run"]').setValue('12')
    await wrapper.get('[data-test="document-query"]').setValue('protokol')
    await wrapper.get('[data-test="document-search"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-test="document-option"]')).toHaveLength(1)
    await wrapper.get('[data-test="document-option"]').trigger('click')
    await wrapper.get('[data-test="approver-name"]').setValue('Účetní Testová')
    await wrapper.get('[data-test="approver-role"]').setValue('mzdová účetní')
    await wrapper.get('[data-test="qualification-submit"]').trigger('click')
    await flushPromises()

    expect(m.qualifyProduction).toHaveBeenCalledOnce()
    const payload = m.qualifyProduction.mock.calls[0][0]
    expect(payload.row_version).toBe(4)
    expect(payload.support_matrix_version).toBe('2026-08')
    expect(payload.evidence.parallel_runs).toEqual([
      { payroll_run_id: 11, document_id: 91 },
      { payroll_run_id: 12, document_id: 91 },
    ])
    expect(JSON.stringify(payload)).not.toContain('sha256')
    expect(wrapper.emitted('qualified')).toHaveLength(1)
  })
})
