import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import Page from '@/pages/accounting/NonprofitTaxAssessment.vue'

const mocks = vi.hoisted(() => ({
  currentSource: vi.fn(), openDraft: vi.fn(), getDraft: vi.fn(), resetDraft: vi.fn(), draftSource: vi.fn(),
  decisions: vi.fn(), activities: vi.fn(), fundingPools: vi.fn(), fundingLinks: vi.fn(), fundingMembers: vi.fn(),
  patchDecisions: vi.fn(), patchActivities: vi.fn(), patchFundingPools: vi.fn(), patchFundingLinks: vi.fn(),
  patchFundingMembers: vi.fn(), preview: vi.fn(), previewPage: vi.fn(), confirm: vi.fn(), history: vi.fn(),
  assessment: vi.fn(), frozenSource: vi.fn(), frozenPage: vi.fn(), export: vi.fn(), can: vi.fn(),
  periodSummary: vi.fn(), currentDraft: vi.fn(), withdraw: vi.fn(), convert: vi.fn(), document: vi.fn(),
}))

vi.mock('@/api/nonprofitTaxJ6', () => ({ nonprofitTaxJ6Api: mocks }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '7' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ can: mocks.can }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 22, domainLocked: true }) }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => '22' }))

const header = {
  header: { schema: 'nonprofit-j6-source.v2', scope: 'current-period-source', supplier_id: 22, period_id: 7,
    period: { id: 7, fiscal_year: 2027, starts_on: '2027-01-01', ends_on: '2027-12-31' }, frameworks: [],
    ruleset: 'j6-2027', funding_history_mode: 'paged_assessment_graph_v1', source_fact_schemas: {} },
  input_hash: 'a'.repeat(64), root_hash: 'b'.repeat(64), row_count: 100000, finding_count: 0, chunk_count: 200,
}
const draft = { id: 11, supplier_id: 22, period_id: 7, revision_no: 1, source_manifest_id: 13,
  source_input_hash: 'a'.repeat(64), header: { profile: { kind: 'narrow_vpp', evidence: 'Synthetic statutes' } },
  created_by: 3, created_at: '2027-01-02T00:00:00Z', updated_at: '2027-01-02T00:00:00Z' }
const envelope = { draft, capabilities: { can_edit: true, can_preview: true, can_confirm: true, source_complete: true, reason_code: null } }
const sourcePage = { draft, rows: [{ id: 'line:1', line_id: 1, entry_id: 2, line_no: 1, account_id: 3,
  account_code: '644', date: '2027-01-01', kind: 'revenue', amount_cents: 10000, activity: null,
  activity_kind_id: null, tax_regime: 'subject', mandatory_non_deductible: false, neutral: false, axes: [],
  provenance: {}, origin: { line_id: 1, type: 'journal', id: 2 }, source_type: 'journal', source_id: 2,
  allocation: null, module_facts: [] }], findings: [], after: 'opaque-next-source' }
const emptyHead = { assessment_id: null, revision_no: 0, status: null }
const pagedRevision = {
  id: 9, supplier_id: 22, period_id: 7, revision_no: 1, previous_id: null, status: 'confirmed',
  reason: 'Prior review', ruleset: 'j6-2027', storage_version: 'paged_v2', created_by: 3,
  created_at: '2027-01-01T00:00:00Z', summary: { accounting_result_cents: 100, excluded_revenue_cents: 0,
    cost_addbacks_cents: 0, assessed_result_cents: 100, source_row_count: 1 },
  source_input_hash: 'a'.repeat(64), snapshot_input_hash: 'b'.repeat(64), receipt_hash: 'c'.repeat(64),
}

const ActionBar = defineComponent({ props: ['actions'], template: '<div><button v-for="action in actions" :key="action.key" :data-action="action.key" :disabled="action.disabled" @click="action.run">{{ action.label }}</button></div>' })
function render() { return mount(Page, { attachTo: document.body, global: { stubs: { ActionBar } } }) }

beforeEach(() => {
  vi.clearAllMocks()
  mocks.can.mockReturnValue(true)
  mocks.periodSummary.mockResolvedValue({ source: header, latest: null })
  mocks.currentDraft.mockResolvedValue({ draft: null, capabilities: null })
  mocks.history.mockResolvedValue({ head: emptyHead, rows: [], after: null })
  mocks.openDraft.mockResolvedValue(envelope)
  mocks.draftSource.mockResolvedValue(sourcePage)
  mocks.decisions.mockResolvedValue({ draft, rows: [], after: null })
  mocks.preview.mockResolvedValue({
    header: {}, root_hashes: { decision: 'a', result: 'b', coverage: 'c', usage: 'd' },
    counts: { decision: 1, result: 1, coverage: 1, usage: 1 },
    evidence_root_hashes: { funding_reference: 'e', funding_history: 'f' },
    evidence_counts: { funding_reference: 1, funding_history: 1 },
    summary: { assessed_result_cents: 0 }, preview_token: 'f'.repeat(64), expires_in: 900,
  })
  mocks.previewPage.mockResolvedValue({ kind: 'result', rows: [], next_page: null })
  mocks.confirm.mockResolvedValue({ assessment_id: 10, supplier_id: 22, period_id: 7, revision: 2,
    status: 'confirmed', request_hash: 'c'.repeat(64), receipt: {} })
  mocks.withdraw.mockResolvedValue({ assessment_id: 12, supplier_id: 22, period_id: 7, revision: 2,
    status: 'withdrawn', request_hash: 'd'.repeat(64), receipt: {} })
  mocks.convert.mockResolvedValue({ assessment_id: 9, supplier_id: 22, period_id: 7, revision: 1,
    status: 'confirmed', request_hash: 'd'.repeat(64), receipt: {} })
})

async function openNewDraft(wrapper: ReturnType<typeof render>) {
  await wrapper.get('[data-test="profile-kind"]').setValue('narrow_vpp')
  await wrapper.get('[data-test="profile-evidence"]').setValue('Synthetic statutes')
  await wrapper.get('[data-action="open"]').trigger('click')
  await flushPromises()
}

function button(wrapper: ReturnType<typeof render>, key: string) {
  return wrapper.findAll('button').find(element => element.text() === `nonprofitTaxJ6.${key}`)!
}

function decisionField(wrapper: ReturnType<typeof render>, key: string, selector = 'input') {
  return wrapper.findAll('label').find(label => label.text().startsWith(`nonprofitTaxJ6.${key}`))!.get(selector)
}

async function assessFirstSource(wrapper: ReturnType<typeof render>, title: string) {
  await button(wrapper, 'assess').trigger('click')
  await flushPromises()
  await decisionField(wrapper, 'titleField').setValue(title)
  await decisionField(wrapper, 'evidence', 'textarea').setValue('Synthetic evidence')
}

describe('bounded nonprofit J6 assessment workspace', () => {
  it('loads only the period summary, own draft and one history page until the user opens a draft', async () => {
    const wrapper = render()
    await flushPromises()
    expect(mocks.periodSummary).toHaveBeenCalledWith(22, 7)
    expect(mocks.currentDraft).toHaveBeenCalledWith(22, 7)
    expect(mocks.history).toHaveBeenCalledWith(22, 7, null, 100)
    expect(mocks.openDraft).not.toHaveBeenCalled()
    expect(mocks.draftSource).not.toHaveBeenCalled()
    expect(wrapper.find('[data-action="resume"]').exists()).toBe(false)
    await openNewDraft(wrapper)
    expect(mocks.openDraft).toHaveBeenCalledWith(22, 7, { profile: { kind: 'narrow_vpp', evidence: 'Synthetic statutes' } })
    expect(mocks.draftSource).toHaveBeenCalledWith(22, 7, 11, null, 100)
    wrapper.unmount()
  })

  it('resumes the users own draft without asking for its number', async () => {
    mocks.currentDraft.mockResolvedValueOnce(envelope)
    const prompt = vi.spyOn(window, 'prompt')
    const wrapper = render()
    await flushPromises()
    expect(prompt).not.toHaveBeenCalled()
    expect(mocks.draftSource).toHaveBeenCalledWith(22, 7, 11, null, 100)
    expect((wrapper.get('[data-test="profile-evidence"]').element as HTMLTextAreaElement).value).toBe('Synthetic statutes')
    wrapper.unmount()
  })

  it('offers period source findings only when there are some and translates draft finding codes', async () => {
    const findings = defineComponent({ props: ['supplierId', 'periodId'], template: '<div data-test="findings-stub">{{ supplierId }}/{{ periodId }}</div>' })
    const mountWith = () => mount(Page, { attachTo: document.body, global: { stubs: { ActionBar, J6SourceFindings: findings } } })
    let wrapper = mountWith()
    await flushPromises()
    expect(wrapper.find('[data-test="findings-stub"]').exists()).toBe(false)
    wrapper.unmount()

    mocks.periodSummary.mockResolvedValueOnce({ source: { ...header, finding_count: 3 }, latest: null })
    mocks.currentDraft.mockResolvedValueOnce(envelope)
    mocks.draftSource.mockResolvedValueOnce({ ...sourcePage, findings: [{ line_id: 1, code: 'missing_activity' }] })
    wrapper = mountWith()
    await flushPromises()
    expect(wrapper.get('[data-test="findings-stub"]').text()).toBe('22/7')
    expect(wrapper.text()).toContain('nonprofitTax.findings.missing_activity')
    expect(wrapper.text()).not.toContain(': missing_activity')
    wrapper.unmount()
  })

  it('follows one opaque source cursor per user action and never drains it automatically', async () => {
    mocks.draftSource.mockResolvedValueOnce({ ...sourcePage, rows: [], findings: [{ line_id: 1, code: 'missing_activity' }] })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    expect(mocks.draftSource).toHaveBeenCalledTimes(1)
    await wrapper.get('[data-test="source-next"]').trigger('click')
    await flushPromises()
    expect(mocks.draftSource).toHaveBeenNthCalledWith(2, 22, 7, 11, 'opaque-next-source', 100)
    expect(mocks.draftSource).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it.each([
    [10000, '37,25', 3725],
    [-10000, '-37.25', -3725],
    [10000, '0', 0],
  ])('saves signed deductible cents for expense %s and previews the new revision', async (amount, input, expected) => {
    const expense = { ...sourcePage.rows[0], kind: 'expense', amount_cents: amount }
    mocks.draftSource.mockResolvedValueOnce({ ...sourcePage, rows: [expense] })
    mocks.patchDecisions.mockResolvedValueOnce({ ...envelope, draft: { ...draft, revision_no: 2 } })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await assessFirstSource(wrapper, 'cost')
    await decisionField(wrapper, 'deductibleBeforeFunding').setValue(input)
    await button(wrapper, 'save').trigger('click')
    await flushPromises()
    expect(mocks.patchDecisions).toHaveBeenCalledWith(22, 7, 11, {
      expected_revision: 1,
      upserts: [{ source_id: 'line:1', payload: expect.objectContaining({ deductible_cents: expected }) }],
      deletes: [],
    })
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(22, 7, 11, 2)
    expect(mocks.previewPage).toHaveBeenCalledWith(22, 7, 11, 'f'.repeat(64), 'result', 0)
    wrapper.unmount()
  })

  it('identifies the deductible field for blank, malformed, excessive and wrong-sign amounts', async () => {
    mocks.draftSource.mockResolvedValueOnce({ ...sourcePage, rows: [{ ...sourcePage.rows[0], kind: 'expense' }] })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await assessFirstSource(wrapper, 'cost')
    const input = decisionField(wrapper, 'deductibleBeforeFunding')
    for (const [value, error] of [['', 'deductibleRequired'], ['3,123', 'deductibleInvalid'], ['101', 'deductibleRange'], ['-10', 'deductibleRange']] as const) {
      await input.setValue(value)
      await button(wrapper, 'save').trigger('click')
      expect(wrapper.get('[role="alert"]').text()).toBe(`nonprofitTaxJ6.${error}`)
      expect(input.attributes('aria-describedby')).toBe(wrapper.get('[role="alert"]').attributes('id'))
    }
    expect(mocks.patchDecisions).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it.each([[true, 'cost'], [false, 'excluded_cost']])('saves explicit zero for an expense with mandatory exclusion %s and title %s', async (excluded, title) => {
    mocks.draftSource.mockResolvedValueOnce({ ...sourcePage, rows: [{ ...sourcePage.rows[0], kind: 'expense', mandatory_non_deductible: excluded }] })
    mocks.patchDecisions.mockResolvedValueOnce(envelope)
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await assessFirstSource(wrapper, title)
    const input = decisionField(wrapper, 'deductibleBeforeFunding').element as HTMLInputElement
    expect(input.readOnly).toBe(true)
    expect(input.value).toBe('0.00')
    await button(wrapper, 'save').trigger('click')
    await flushPromises()
    expect(mocks.patchDecisions.mock.calls[0]![3].upserts[0].payload.deductible_cents).toBe(0)
    wrapper.unmount()
  })

  it('restores the stored deductible amount for a decision outside the loaded source page', async () => {
    const row = { source_id: 'line:81', payload: { title: 'cost', evidence: 'Stored evidence', deductible_cents: -150 }, record_hash: 'a'.repeat(64) }
    mocks.decisions.mockResolvedValueOnce({ draft, rows: [row], after: null })
    mocks.patchDecisions.mockResolvedValueOnce(envelope)
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await button(wrapper, 'tabs.decisions').trigger('click')
    await flushPromises()
    await button(wrapper, 'edit').trigger('click')
    expect((decisionField(wrapper, 'deductibleBeforeFunding').element as HTMLInputElement).value).toBe('-1.50')
    await button(wrapper, 'save').trigger('click')
    await flushPromises()
    expect(mocks.patchDecisions.mock.calls[0]![3].upserts[0]).toEqual({ source_id: 'line:81', payload: { ...row.payload, facts: {} } })
    expect(mocks.draftSource).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('saves a revenue decision without an expense field and displays the specific preview error', async () => {
    mocks.patchDecisions.mockResolvedValueOnce(envelope)
    const message = 'Čistý bankovní úrok musí souhlasit s doloženým hrubým úrokem a sraženou daní.'
    mocks.preview.mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_tax_facts_incomplete', message } } } })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await assessFirstSource(wrapper, 'bank_interest')
    expect(wrapper.find('input[inputmode="decimal"]').exists()).toBe(false)
    await button(wrapper, 'save').trigger('click')
    await flushPromises()
    expect(mocks.patchDecisions.mock.calls[0]![3].upserts[0].payload).not.toHaveProperty('deductible_cents')
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe(message)
    wrapper.unmount()
  })

  it('confirms with the current draft and the confirmed history head as CAS without sending the preview token', async () => {
    mocks.history.mockResolvedValueOnce({ head: { assessment_id: 9, revision_no: 1, status: 'confirmed' }, rows: [pagedRevision], after: null })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(22, 7, 11, 1)
    expect(mocks.previewPage).toHaveBeenCalledWith(22, 7, 11, 'f'.repeat(64), 'result', 0)
    await wrapper.get('[data-test="confirm-reason"]').setValue('Reviewed evidence')
    await wrapper.get('[data-test="confirm-ack"]').setValue(true)
    await wrapper.get('[data-test="confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.confirm).toHaveBeenCalledWith(22, 7, 11, expect.objectContaining({
      expected_revision: 1,
      expected_assessment_id: 9,
      reason: 'Reviewed evidence',
      idempotency_key: expect.any(String),
    }))
    expect(mocks.confirm.mock.calls[0]![3]).not.toHaveProperty('preview_token')
    wrapper.unmount()
  })

  it('does not use a withdrawn head as confirmation CAS', async () => {
    mocks.history.mockResolvedValueOnce({ head: { assessment_id: 12, revision_no: 2, status: 'withdrawn' },
      rows: [pagedRevision, { ...pagedRevision, id: 12, revision_no: 2, previous_id: 9, status: 'withdrawn' }], after: null })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="confirm-reason"]').setValue('Reviewed evidence')
    await wrapper.get('[data-test="confirm-ack"]').setValue(true)
    await wrapper.get('[data-test="confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.confirm.mock.calls[0]![3]).toMatchObject({ expected_assessment_id: null })
    wrapper.unmount()
  })

  it('withdraws only the confirmed head with reason and acknowledgement next to the button', async () => {
    mocks.history.mockResolvedValueOnce({ head: { assessment_id: 9, revision_no: 1, status: 'confirmed' }, rows: [pagedRevision], after: null })
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-test="withdraw"]').trigger('click')
    const panel = wrapper.get('[data-test="history-action"]')
    const confirm = panel.get('[data-test="history-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    await panel.get('[data-test="history-reason"]').setValue('Synthetic correction')
    expect(confirm.attributes('disabled')).toBeDefined()
    await panel.get('[data-test="history-ack"]').setValue(true)
    await confirm.trigger('click')
    await flushPromises()
    expect(mocks.withdraw).toHaveBeenCalledWith(22, 7, 9, { reason: 'Synthetic correction', idempotency_key: expect.any(String) })
    expect(mocks.periodSummary).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('offers explicit conversion of an older revision with its stored CAS and never on load', async () => {
    const legacy = { ...pagedRevision, storage_version: 'legacy_json_v1', summary: null, snapshot_input_hash: null,
      receipt_hash: null, source_input_hash: 'e'.repeat(64) }
    mocks.history.mockResolvedValueOnce({ head: { assessment_id: 9, revision_no: 1, status: 'confirmed' }, rows: [legacy], after: null })
    const wrapper = render()
    await flushPromises()
    expect(mocks.convert).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="withdraw"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="download-pdf"]').exists()).toBe(false)
    await wrapper.get('[data-test="convert"]').trigger('click')
    await wrapper.get('[data-test="history-reason"]').setValue('Owner conversion')
    await wrapper.get('[data-test="history-ack"]').setValue(true)
    await wrapper.get('[data-test="history-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.convert).toHaveBeenCalledWith(22, 7, 9, { expected_storage_version: 'legacy_json_v1',
      expected_input_hash: 'e'.repeat(64), reason: 'Owner conversion' })
    wrapper.unmount()
  })

  it('reloads history from the start when its cursor belongs to an older head', async () => {
    mocks.history
      .mockResolvedValueOnce({ head: { assessment_id: 9, revision_no: 1, status: 'confirmed' }, rows: [pagedRevision], after: 'page-2' })
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_tax_cursor_stale' } } } })
      .mockResolvedValueOnce({ head: { assessment_id: 12, revision_no: 2, status: 'withdrawn' }, rows: [pagedRevision], after: null })
    const wrapper = render()
    await flushPromises()
    const next = wrapper.findAll('button').find(button => button.text() === 'nonprofitTaxJ6.next')!
    await next.trigger('click')
    await flushPromises()
    expect(mocks.history).toHaveBeenNthCalledWith(2, 22, 7, 'page-2', 100)
    expect(mocks.history).toHaveBeenNthCalledWith(3, 22, 7, null, 100)
    expect(wrapper.text()).toContain('nonprofitTaxJ6.historyChanged')
    wrapper.unmount()
  })

  it('invalidates an expired preview receipt and requires a fresh preview', async () => {
    mocks.previewPage.mockReset().mockRejectedValueOnce({ response: { status: 404,
      data: { error: { code: 'nonprofit_tax_preview_not_found' } } } })
    const wrapper = render()
    await flushPromises()
    await openNewDraft(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('nonprofitTaxJ6.errors.nonprofit_tax_preview_not_found')
    expect(wrapper.text()).toContain('nonprofitTaxJ6.noPreview')
    wrapper.unmount()
  })
})
