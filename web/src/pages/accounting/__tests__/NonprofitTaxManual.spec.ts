import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import Page from '@/pages/accounting/NonprofitTaxAssessment.vue'

const mocks = vi.hoisted(() => ({
  currentSource: vi.fn(), history: vi.fn(), openDraft: vi.fn(), draftSource: vi.fn(), getDraft: vi.fn(),
  resetDraft: vi.fn(), decisions: vi.fn(), activities: vi.fn(), fundingPools: vi.fn(), fundingLinks: vi.fn(),
  fundingMembers: vi.fn(), patchDecisions: vi.fn(), patchActivities: vi.fn(), patchFundingPools: vi.fn(),
  patchFundingLinks: vi.fn(), patchFundingMembers: vi.fn(), preview: vi.fn(), previewPage: vi.fn(),
  confirm: vi.fn(), assessment: vi.fn(), frozenSource: vi.fn(), frozenPage: vi.fn(), export: vi.fn(), can: vi.fn(),
  periodSummary: vi.fn(), currentDraft: vi.fn(), withdraw: vi.fn(), convert: vi.fn(), document: vi.fn(),
}))
vi.mock('@/api/nonprofitTaxJ6', () => ({ nonprofitTaxJ6Api: mocks }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '7' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ can: mocks.can }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 22, domainLocked: true }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: Error) => error.message }))

const header = {
  header: { schema: 'nonprofit-j6-source.v2', scope: 'current-period-source', supplier_id: 22, period_id: 7,
    period: { id: 7, fiscal_year: 2027, starts_on: '2027-01-01', ends_on: '2027-12-31' }, frameworks: [],
    ruleset: 'j6-2027', funding_history_mode: 'paged_assessment_graph_v1', source_fact_schemas: {} },
  input_hash: 'a'.repeat(64), root_hash: 'b'.repeat(64), row_count: 1, finding_count: 0, chunk_count: 1,
}
const draft = { id: 11, supplier_id: 22, period_id: 7, revision_no: 1, source_manifest_id: 13,
  source_input_hash: 'a'.repeat(64), header: { profile: { kind: 'documented', evidence: 'Evidence' } },
  created_by: 3, created_at: '2027-01-02T00:00:00Z', updated_at: '2027-01-02T00:00:00Z' }
const envelope = { draft, capabilities: { can_edit: true, can_preview: true, can_confirm: true, source_complete: true, reason_code: null } }
const ActionBar = defineComponent({ props: ['actions'], template: '<div><button v-for="action in actions" :key="action.key" :data-action="action.key" :disabled="action.disabled" @click="action.run">{{ action.label }}</button></div>' })
function render() { return mount(Page, { global: { stubs: { ActionBar } } }) }

beforeEach(() => {
  vi.clearAllMocks()
  mocks.can.mockReturnValue(true)
  mocks.periodSummary.mockResolvedValue({ source: header, latest: null })
  mocks.currentDraft.mockResolvedValue({ draft: null, capabilities: null })
  mocks.history.mockResolvedValue({ head: { assessment_id: null, revision_no: 0, status: null }, rows: [], after: null })
  mocks.openDraft.mockResolvedValue(envelope)
  mocks.draftSource.mockResolvedValue({ draft, rows: [], findings: [], after: null })
})

describe('documented manual J6 draft', () => {
  it('binds the method to the exact accounting period without sending a client source hash', async () => {
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-test="profile-kind"]').setValue('documented')
    await wrapper.get('[data-test="profile-evidence"]').setValue('Documented organization facts')
    await wrapper.get('input[type="checkbox"]').setValue(true)
    const textareas = wrapper.findAll('textarea')
    await textareas[1]!.setValue('Applicable provisions')
    await textareas[2]!.setValue('Period-specific assessment')
    await wrapper.get('[data-action="open"]').trigger('click')
    await flushPromises()
    expect(mocks.openDraft).toHaveBeenCalledWith(22, 7, {
      profile: { kind: 'documented', evidence: 'Documented organization facts' },
      method: { mode: 'documented_manual', valid_from: '2027-01-01', valid_to: '2027-12-31',
        legal_basis: 'Applicable provisions', evidence: 'Period-specific assessment' },
    })
    expect(mocks.openDraft.mock.calls[0]![2]).not.toHaveProperty('source_input_hash')
    wrapper.unmount()
  })

  it('fails closed when an opaque cursor belongs to an older revision', async () => {
    mocks.openDraft.mockResolvedValue(envelope)
    mocks.draftSource
      .mockResolvedValueOnce({ draft, rows: [], findings: [], after: 'opaque-next' })
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_tax_cursor_stale' } } } })
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-test="profile-kind"]').setValue('documented')
    await wrapper.get('[data-test="profile-evidence"]').setValue('Evidence')
    await wrapper.get('[data-action="open"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="source-next"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('nonprofitTaxJ6.errors.nonprofit_tax_cursor_stale')
    expect(wrapper.text()).toContain('nonprofitTaxJ6.stale')
    wrapper.unmount()
  })
})
