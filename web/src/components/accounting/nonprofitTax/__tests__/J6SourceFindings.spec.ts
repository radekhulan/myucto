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
  source_link_id: null, source_statement_id: null, source_doc_number: null, source_register_id: null, source_asset_id: null,
  source_settlement_doc_type: null, source_settlement_doc_id: null,
  document_no: 'SYN-1', description: 'Synthetic operation', ...over })

async function openGroup(w: ReturnType<typeof render>) {
  await w.get('[data-test=source-findings-load]').trigger('click')
  await flushPromises()
  await w.findAll('[data-test=source-finding-show]')[0]!.trigger('click')
  await flushPromises()
}

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
    // Úzká obrazovka má stejné skupiny jako karty.
    expect(w.findAll('[data-test=source-finding-group-card]').map(c => c.text().includes('nonprofitTax.findings.'))).toEqual([true, true])
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
    expect(w.findAll('[data-test=source-finding-line-card]')).toHaveLength(2)
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

  it('links bank, purchase invoice, other item and reversal lines to their documents like the journal', async () => {
    api.sourceFindingLines.mockResolvedValueOnce({ after: null, rows: [
      line({ line_id: 1, source_type: 'bank', source_id: 3, source_link_id: 3, source_statement_id: 9 }),
      line({ line_id: 2, source_type: 'purchase_invoice', source_id: 4, source_link_id: 4 }),
      line({ line_id: 3, source_type: 'other_item', source_id: 5, source_link_id: 5 }),
      line({ line_id: 4, source_type: 'bank', source_id: null, source_link_id: 3, source_statement_id: 9 }),
      line({ line_id: 5, source_type: 'accrual_release', source_id: 6, source_link_id: 6, source_accrual_item_id: 12 }),
    ] })
    const w = render()
    await openGroup(w)
    const targets = w.findAll('[data-test=source-finding-link]').map(link => JSON.parse(link.attributes('data-to')!))
    expect(targets).toEqual([
      { name: 'bank-detail', params: { id: 9 } },
      { name: 'purchase-invoice-detail', params: { id: 4 } },
      { name: 'other-item-detail', params: { id: 5 } },
      { name: 'bank-detail', params: { id: 9 } },
      { name: 'accounting-accrual-detail', params: { id: 12 } },
    ])
  })

  it('keeps the page history when loading a page fails and offers further search on an empty scanned page', async () => {
    api.sourceFindingLines
      .mockResolvedValueOnce({ rows: [line()], after: 'cursor-2' })
      .mockResolvedValueOnce({ rows: [], after: 'cursor-3' })
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_tax_cursor_stale', message: 'Synthetic stale cursor' } } } })
      .mockResolvedValueOnce({ rows: [line()], after: 'cursor-2' })
    const w = render()
    await openGroup(w)
    await w.get('[data-test=source-finding-next]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('nonprofitTaxJ6.sourceFindings.continueSearch')
    expect(w.get('[data-test=source-finding-next]').attributes('disabled')).toBeUndefined()
    const previous = () => w.findAll('button').find(b => b.text().includes('nonprofitTaxJ6.previous'))!
    await previous().trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toBe('Synthetic stale cursor')
    await previous().trigger('click')
    await flushPromises()
    expect(api.sourceFindingLines).toHaveBeenLastCalledWith(1, 7, { account: '518', code: 'missing_activity_kind' }, null)
    expect(api.sourceFindingLines).toHaveBeenCalledTimes(4)
  })

  it('does not keep old counts when computing the summary again fails', async () => {
    api.sourceFindings.mockResolvedValueOnce(summary).mockRejectedValueOnce(new Error('timeout'))
    const w = render()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(w.findAll('[data-test=source-finding-group]')).toHaveLength(2)
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(w.findAll('[data-test=source-finding-group]')).toHaveLength(0)
    expect(w.find('[role=alert]').exists()).toBe(true)
  })

  it('ignores a late summary of the previous period', async () => {
    let release!: (value: unknown) => void
    api.sourceFindings.mockImplementationOnce(() => new Promise(resolve => { release = resolve }))
    const w = render()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await w.setProps({ periodId: 8 })
    release(summary)
    await flushPromises()
    expect(w.findAll('[data-test=source-finding-group]')).toHaveLength(0)
    expect(w.get('[data-test=source-findings-load]').attributes('disabled')).toBeUndefined()
  })

  it('forgets the cursor of the previous group when another group fails to load', async () => {
    api.sourceFindingLines
      .mockResolvedValueOnce({ rows: [line()], after: 'cursor-a' })
      .mockRejectedValueOnce({ response: { status: 500, data: { error: { code: 'x', message: 'Synthetic failure' } } } })
    const w = render()
    await openGroup(w)
    await w.findAll('[data-test=source-finding-show]')[1]!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test=source-finding-next]').attributes('disabled')).toBeDefined()
    expect(w.text()).not.toContain('continueSearch')
    expect(w.findAll('button').find(b => b.text().includes('nonprofitTaxJ6.previous'))!.attributes('disabled')).toBeDefined()
  })

  it('adds a joint allocation link to lines that are fixed in the joint allocation', async () => {
    api.sourceFindingLines.mockResolvedValueOnce({ after: null, rows: [line({ line_id: 1, codes: ['joint_allocation_required'] }), line({ line_id: 2 })] })
    const w = render()
    await openGroup(w)
    const rows = w.findAll('[data-test=source-finding-line]')
    expect(JSON.parse(rows[0]!.get('[data-test=source-finding-joint]').attributes('data-to')!)).toEqual({ name: 'accounting-joint-allocations' })
    expect(rows[1]!.find('[data-test=source-finding-joint]').exists()).toBe(false)
    // Karty na úzké obrazovce nesou stejné odkazy.
    const cards = w.findAll('[data-test=source-finding-line-card]')
    expect(JSON.parse(cards[0]!.get('[data-test=source-finding-card-joint]').attributes('data-to')!)).toEqual({ name: 'accounting-joint-allocations' })
    expect(cards[1]!.find('[data-test=source-finding-card-joint]').exists()).toBe(false)
    expect(cards.map(c => JSON.parse(c.get('[data-test=source-finding-card-link]').attributes('data-to')!))).toEqual(
      w.findAll('[data-test=source-finding-link]').map(l => JSON.parse(l.attributes('data-to')!)))
  })

  it('warns when the summary was computed over other sources than the page overview', async () => {
    const w = mount(J6SourceFindings, { props: { supplierId: 1, periodId: 7, currentHash: 'other' }, global: { stubs: { RouterLink } } })
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test=source-findings-changed]').exists()).toBe(true)
    await w.setProps({ currentHash: 'h' })
    expect(w.find('[data-test=source-findings-changed]').exists()).toBe(false)
  })

  it('shows the server message when the summary fails', async () => {
    api.sourceFindings.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'nonprofit_tax_session_only', message: 'Synthetic session message' } } } })
    const w = render()
    await w.get('[data-test=source-findings-load]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toBe('Synthetic session message')
  })
})
