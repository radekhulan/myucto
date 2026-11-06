import { describe, expect, it, vi } from 'vitest'

const calls = vi.hoisted(() => ({
  get: vi.fn().mockResolvedValue({ data: {} }),
  post: vi.fn().mockResolvedValue({ data: {} }),
  patch: vi.fn().mockResolvedValue({ data: {} }),
}))

vi.mock('@/api/client', () => ({ api: calls }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => '4' }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ domainLocked: true, currentSupplierId: 4 }) }))

import { nonprofitTaxJ6Api } from '@/api/nonprofitTaxJ6'

describe('bounded nonprofit J6 HTTP v2 client', () => {
  it('uses the frozen draft, opaque cursor, pool context, preview and confirmed-head CAS wire', async () => {
    await nonprofitTaxJ6Api.currentSource(4, 7)
    await nonprofitTaxJ6Api.openDraft(4, 7, { profile: { kind: 'narrow_vpp', evidence: 'Synthetic statutes' } })
    await nonprofitTaxJ6Api.draftSource(4, 7, 11, 'opaque-source', 100)
    await nonprofitTaxJ6Api.decisions(4, 7, 11, 'opaque-decision', 100)
    await nonprofitTaxJ6Api.fundingMembers(4, 7, 11, 'revenue', 'line:41', 'opaque-member', 100)
    await nonprofitTaxJ6Api.patchFundingMembers(4, 7, 11, 'revenue', 'line:41', { expected_revision: 3, upserts: ['line:41'], deletes: [] })
    await nonprofitTaxJ6Api.preview(4, 7, 11, 4)
    await nonprofitTaxJ6Api.previewPage(4, 7, 11, 'a'.repeat(64), 'funding_history', 2)
    await nonprofitTaxJ6Api.confirm(4, 7, 11, { expected_revision: 4, expected_assessment_id: null, reason: 'Synthetic review', idempotency_key: 'synthetic-confirm' })
    await nonprofitTaxJ6Api.history(4, 7, 'opaque-history', 100)
    await nonprofitTaxJ6Api.frozenSource(4, 13, 'opaque-frozen')

    expect(calls.get.mock.calls.map(call => [call[0], call[1]?.params])).toEqual([
      ['/accounting/periods/7/nonprofit-tax/source', undefined],
      ['/accounting/periods/7/nonprofit-tax/drafts/11/source', { after: 'opaque-source', limit: 100 }],
      ['/accounting/periods/7/nonprofit-tax/drafts/11/decisions', { after: 'opaque-decision', limit: 100 }],
      ['/accounting/periods/7/nonprofit-tax/drafts/11/funding-pools/revenue/line%3A41/members', { after: 'opaque-member', limit: 100 }],
      [`/accounting/periods/7/nonprofit-tax/drafts/11/previews/${'a'.repeat(64)}/funding_history`, { page: 2 }],
      ['/accounting/periods/7/nonprofit-tax/history', { after: 'opaque-history', limit: 100 }],
      ['/accounting/nonprofit-tax-assessments/13/source', { after: 'opaque-frozen', limit: 100 }],
    ])
    expect(calls.post.mock.calls.map(call => [call[0], call[1]])).toEqual([
      ['/accounting/periods/7/nonprofit-tax/draft', { profile: { kind: 'narrow_vpp', evidence: 'Synthetic statutes' } }],
      ['/accounting/periods/7/nonprofit-tax/drafts/11/preview', { expected_revision: 4 }],
      ['/accounting/periods/7/nonprofit-tax/drafts/11/confirm', { expected_revision: 4, expected_assessment_id: null, reason: 'Synthetic review', idempotency_key: 'synthetic-confirm' }],
    ])
    expect(calls.patch.mock.calls[0]?.slice(0, 2)).toEqual([
      '/accounting/periods/7/nonprofit-tax/drafts/11/funding-pools/revenue/line%3A41/members',
      { expected_revision: 3, upserts: ['line:41'], deletes: [] },
    ])
    for (const method of [calls.get, calls.post, calls.patch]) {
      for (const call of method.mock.calls) expect(call.at(-1)?.headers).toEqual({ 'X-Supplier-Id': '4' })
    }
  })

  it('uses the lifecycle wire for summary, own draft, documents, withdrawal and explicit conversion', async () => {
    calls.get.mockClear()
    calls.post.mockClear()
    await nonprofitTaxJ6Api.periodSummary(4, 7)
    await nonprofitTaxJ6Api.currentDraft(4, 7)
    await nonprofitTaxJ6Api.document(4, 13, 'pdf')
    await nonprofitTaxJ6Api.document(4, 13, 'xlsx')
    await nonprofitTaxJ6Api.withdraw(4, 7, 13, { reason: 'Synthetic withdrawal', idempotency_key: 'synthetic-withdraw' })
    await nonprofitTaxJ6Api.convert(4, 7, 13, { expected_storage_version: 'legacy_json_v1', expected_input_hash: 'b'.repeat(64), reason: 'Synthetic conversion' })
    expect(calls.get.mock.calls.map(call => [call[0], call[1]?.responseType])).toEqual([
      ['/accounting/periods/7/nonprofit-tax/summary', undefined],
      ['/accounting/periods/7/nonprofit-tax/draft', undefined],
      ['/accounting/nonprofit-tax-assessments/13/summary.pdf', 'blob'],
      ['/accounting/nonprofit-tax-assessments/13/summary.xlsx', 'blob'],
    ])
    expect(calls.post.mock.calls.map(call => [call[0], call[1]])).toEqual([
      ['/accounting/periods/7/nonprofit-tax/assessments/13/withdraw', { reason: 'Synthetic withdrawal', idempotency_key: 'synthetic-withdraw' }],
      ['/accounting/periods/7/nonprofit-tax/assessments/13/convert', { expected_storage_version: 'legacy_json_v1', expected_input_hash: 'b'.repeat(64), reason: 'Synthetic conversion' }],
    ])
  })

  it('rejects an edit batch over 500 before HTTP', async () => {
    const before = calls.patch.mock.calls.length
    expect(() => nonprofitTaxJ6Api.patchDecisions(4, 7, 11, {
      expected_revision: 2,
      upserts: Array.from({ length: 501 }, (_, index) => ({ source_id: `line:${index + 1}`, payload: { title: 'rent', evidence: 'Synthetic' } })),
      deletes: [],
    })).toThrow('nonprofitTaxJ6.batchLimit')
    expect(calls.patch).toHaveBeenCalledTimes(before)
  })

  it('rejects a read page over the HTTP v2.1 maximum before HTTP', () => {
    const before = calls.get.mock.calls.length
    expect(() => nonprofitTaxJ6Api.draftSource(4, 7, 11, null, 101)).toThrow('nonprofitTaxJ6.pageLimit')
    expect(calls.get).toHaveBeenCalledTimes(before)
  })
})
