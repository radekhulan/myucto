import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import J6SourceFindings from '../J6SourceFindings.vue'

const api = vi.hoisted(() => ({ sourceFindings: vi.fn(), sourceFindingLines: vi.fn() }))
vi.mock('@/api/nonprofitTaxJ6', () => ({ nonprofitTaxJ6Api: api }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key, locale: { value: 'cs' } }) }))

const RouterLink = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const summary = {
  source: { header: {}, input_hash: 'h', root_hash: 'r', row_count: 40, finding_count: 5, chunk_count: 1 },
  line_count: 3,
  groups: [
    { account_id: 1, account_code: '518', account_name: 'Synthetic services', code: 'missing_activity_kind', line_count: 2 },
    { account_id: 2, account_code: '602', account_name: null, code: 'missing_tax_regime', line_count: 1 },
  ],
}
const line = (over: Record<string, unknown> = {}) => ({ line_id: 11, entry_id: 21, line_no: 1, date: '2025-03-01', account_code: '518', kind: 'expense',
  amount_cents: 12345, codes: ['missing_activity_kind', 'unknown_activity_kind_origin'], source_type: 'manual', source_id: null,
  document_no: 'SYN-1', description: 'Synthetic operation', ...over })

function render() {
  return mount(J6SourceFindings, { props: { supplierId: 1, periodId: 7 }, global: { stubs: { RouterLink } } })
}

beforeEach(() => {
  vi.resetAllMocks()
  api.sourceFindings.mockResolvedValue(summary)
})

describe('J6 source findings', () => {
  it('computes the summary only on request and translates finding codes', async () => {
    const w = render()
    await flushPromises()
    expect(api.sourceFindings).not.toHaveBeenCalled()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(api.sourceFindings).toHaveBeenCalledWith(1, 7)
    const groups = w.findAll('[data-test=source-finding-group]')
    expect(groups).toHaveLength(2)
    expect(groups[0]!.text()).toContain('518 Synthetic services')
    expect(groups[0]!.text()).toContain('nonprofitTax.findings.missing_activity_kind')
    expect(groups[1]!.text()).toContain('602')
    expect(w.get('[data-test=source-findings-total]').text()).toContain('"lines":3')
  })

  it('lists lines of the chosen group page by page with a link to the place of fix', async () => {
    api.sourceFindingLines
      .mockResolvedValueOnce({ rows: [line(), line({ line_id: 12, entry_id: 22, source_type: 'invoice', source_id: 5 })], after: 'cursor-2' })
      .mockResolvedValueOnce({ rows: [line({ line_id: 13, entry_id: 23 })], after: null })
      .mockResolvedValueOnce({ rows: [line()], after: 'cursor-2' })
    const w = render()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    await w.findAll('[data-test=source-finding-show]')[0]!.trigger('click')
    await flushPromises()
    expect(api.sourceFindingLines).toHaveBeenLastCalledWith(1, 7, { account: '518', code: 'missing_activity_kind' }, null)
    const rows = w.findAll('[data-test=source-finding-line]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('SYN-1')
    expect(rows[0]!.text()).toContain('nonprofitTax.findings.unknown_activity_kind_origin')
    const links = w.findAll('[data-test=source-finding-link]')
    expect(JSON.parse(links[0]!.attributes('data-to')!)).toEqual({ name: 'accounting-journal', query: { entry_id: '21' } })
    expect(links[0]!.text()).toContain('openEntry')
    expect(JSON.parse(links[1]!.attributes('data-to')!)).toEqual({ name: 'invoice-detail', params: { id: 5 } })
    expect(links[1]!.text()).toContain('openDocument')

    await w.get('[data-test=source-finding-next]').trigger('click')
    await flushPromises()
    expect(api.sourceFindingLines).toHaveBeenLastCalledWith(1, 7, { account: '518', code: 'missing_activity_kind' }, 'cursor-2')
    expect(w.findAll('[data-test=source-finding-line]')).toHaveLength(1)
    expect(w.get('[data-test=source-finding-next]').attributes('disabled')).toBeDefined()
    await w.findAll('button').find(b => b.text().includes('nonprofitTaxJ6.previous'))!.trigger('click')
    await flushPromises()
    expect(api.sourceFindingLines).toHaveBeenLastCalledWith(1, 7, { account: '518', code: 'missing_activity_kind' }, null)
  })

  it('shows the server message when the summary fails', async () => {
    api.sourceFindings.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'nonprofit_tax_session_only', message: 'Synthetic session message' } } } })
    const w = render()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toBe('Synthetic session message')
  })
})
