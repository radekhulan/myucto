import { beforeEach, expect, it, vi } from 'vitest'
const calls = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const scope = vi.hoisted(() => ({ requested: '5' as string | null, domainLocked: false, currentSupplierId: 5 }))
vi.mock('@/api/client', () => ({ api: calls }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => scope.requested }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => scope }))
import { membershipApi as api } from '../nonprofitMembership'

beforeEach(() => {
  vi.clearAllMocks()
  scope.requested = '5'; scope.domainLocked = false; scope.currentSupplierId = 5
  calls.get.mockResolvedValue({ data: {} })
  calls.post.mockResolvedValue({ data: {} })
})

it('reads members, detail, claims and reconciliation pinned to the captured supplier with cursor paging', async () => {
  await api.members(5, 12, 50)
  await api.member(5, 9, 31, 25)
  await api.claim(5, 31)
  await api.reconciliation(5, 11)
  expect(calls.get.mock.calls.map(c => c[0])).toEqual([
    '/accounting/nonprofit-membership/members',
    '/accounting/nonprofit-membership/members/9',
    '/accounting/nonprofit-membership/claims/31',
    '/accounting/periods/11/nonprofit-membership/reconciliation',
  ])
  expect(calls.get.mock.calls[0]![1].params).toEqual({ after_id: 12, limit: 50 })
  expect(calls.get.mock.calls[1]![1].params).toEqual({ after_claim_id: 31, limit: 25 })
  for (const [, options] of calls.get.mock.calls) expect(options.headers).toEqual({ 'X-Supplier-Id': '5' })
  expect(calls.post).not.toHaveBeenCalled()
})

it('posts exactly the documented bodies and never supplies an actor', async () => {
  const revision = {
    status: 'active' as const, effective_from: '2026-01-01',
    legal_basis: { organisation_form: 'association' as const, relationship: 'nonreciprocal_membership' as const, reference: 'Statutes', evidence: 'Application' },
    expected_revision: 0, idempotency_key: 'synthetic-revision-token',
  }
  const reverse = { operation: 'reverse' as const, request: { case_id: 4, accounting_date: '2026-10-19', reason: 'Synthetic reason' } }
  const confirm = { ...reverse, expected_case_version: 2, preview_hash: 'a'.repeat(64), idempotency_key: 'synthetic-confirm-token' }
  await api.createMember(5, { client_id: 3, member_number: 'M-1' })
  await api.appendRevision(5, 9, revision)
  await api.preview(5, reverse)
  await api.confirm(5, confirm)
  expect(calls.post.mock.calls.map(c => [c[0], c[1]])).toEqual([
    ['/accounting/nonprofit-membership/members', { client_id: 3, member_number: 'M-1' }],
    ['/accounting/nonprofit-membership/members/9/revisions', revision],
    ['/accounting/nonprofit-membership/charges/preview', reverse],
    ['/accounting/nonprofit-membership/charges/confirm', confirm],
  ])
  for (const [, body, options] of calls.post.mock.calls) {
    expect(body).not.toHaveProperty('actor')
    expect(options.headers).toEqual({ 'X-Supplier-Id': '5' })
  }
})

it('refuses requests captured before a supplier switch', () => {
  scope.requested = '6'
  expect(() => api.members(5)).toThrow('nonprofit_membership_supplier_changed')
  expect(() => api.confirm(5, {} as never)).toThrow('nonprofit_membership_supplier_changed')
  expect(calls.get).not.toHaveBeenCalled()
  expect(calls.post).not.toHaveBeenCalled()
})
