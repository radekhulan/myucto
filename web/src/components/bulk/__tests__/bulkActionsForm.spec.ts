import { describe, expect, it } from 'vitest'
import {
  applyItemCount,
  buildApplyRequest,
  buildDryRunRequest,
  bulkReasonText,
  deleteConfirmMatches,
  dimensionSnapshotChanges,
  emptyBulkForm,
  parseAccountPrefixes,
  type BulkActionForm,
} from '../bulkActionsForm'
import { bulkDialogState, canOpenBulkActions } from '../useListBulkActions'

const t = (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key)

function dimsForm(patch: Partial<BulkActionForm> = {}): BulkActionForm {
  return {
    ...emptyBulkForm(),
    operation: 'set_dimensions',
    dimensions: {
      1: { mode: 'set', valueId: 11 },
      2: { mode: 'fill', valueId: 22 },
      3: { mode: 'clear', valueId: 33 },
      4: { mode: 'keep', valueId: 44 },
    },
    ...patch,
  }
}

describe('hromadné akce: sestavení požadavku', () => {
  it('dimenze více typů najednou; beze změny se neposílá, vymazat bez hodnoty, u faktur rozsah', () => {
    expect(buildDryRunRequest('invoice', { ids: [5, 6] }, dimsForm({ scope: 'items', accountPrefixes: '5' }))).toEqual({
      dry_run: true,
      operation: 'set_dimensions',
      target: 'invoice',
      selection: { ids: [5, 6] },
      params: {
        dimensions: [
          { type_id: 1, mode: 'set', value_id: 11 },
          { type_id: 2, mode: 'fill', value_id: 22 },
          { type_id: 3, mode: 'clear' },
        ],
        scope: 'items',
      },
    })
  })

  it('u deníku jde místo rozsahu filtr účtů a jen když je vyplněný', () => {
    const query = { list_query: { account_from: '5' } }
    expect(buildDryRunRequest('journal_entry', query, dimsForm({ accountPrefixes: '5, 6,,6' }))?.params).toEqual({
      dimensions: expect.any(Array),
      account_prefixes: ['5', '6'],
    })
    const params = buildDryRunRequest('journal_entry', query, dimsForm({ accountPrefixes: '  ' }))?.params
    expect(params).not.toHaveProperty('account_prefixes')
    expect(params).not.toHaveProperty('scope')
  })

  it('neúplný formulář nevrací požadavek', () => {
    expect(buildDryRunRequest('invoice', { ids: [1] }, dimsForm({ dimensions: { 1: { mode: 'set', valueId: null } } }))).toBeNull()
    expect(buildDryRunRequest('invoice', { ids: [1] }, dimsForm({ dimensions: { 1: { mode: 'keep', valueId: 3 } } }))).toBeNull()
    expect(buildDryRunRequest('invoice', { ids: [1] }, { ...emptyBulkForm(), operation: 'rebook_account', rebook: { from_account: '518', to_account: '518', side: 'both', mode: 'rewrite' } })).toBeNull()
    expect(buildDryRunRequest('invoice', { ids: [1] }, { ...emptyBulkForm(), operation: 'set_posting_rule' })).toBeNull()
  })

  it('smazání: náhradní storno jen u deníku, ostrý běh nese potvrzený počet', () => {
    const del = { ...emptyBulkForm(), operation: 'delete' as const, journalFallback: 'reverse' as const }
    expect(buildDryRunRequest('journal_entry', { ids: [1] }, del)?.params).toEqual({ journal_fallback: 'reverse' })
    expect(buildDryRunRequest('purchase_invoice', { ids: [1] }, del)?.params).toEqual({})
    expect(buildApplyRequest('delete', 9, new Set([3]), applyItemCount(5, 1))).toEqual({
      dry_run: false, dry_run_job_id: 9, excluded_item_ids: [3], confirm_count: 4,
    })
    expect(buildApplyRequest('set_dimensions', 9, [3], 4)).toEqual({ dry_run: false, dry_run_job_id: 9, excluded_item_ids: [3] })
  })

  it('opsaný počet musí přesně sedět', () => {
    expect(deleteConfirmMatches(' 4 ', 4)).toBe(true)
    expect(deleteConfirmMatches('3', 4)).toBe(false)
    expect(deleteConfirmMatches('4x', 4)).toBe(false)
    expect(deleteConfirmMatches('0', 0)).toBe(false)
    expect(parseAccountPrefixes('5; 6 7')).toEqual(['5', '6', '7'])
  })

  it('přeloží známý důvod, neznámý ukáže zprávou serveru', () => {
    expect(bulkReasonText({ reason_code: 'retention_period', message: 'x' }, t)).toBe('bulk_actions.reason.retention_period')
    expect(bulkReasonText({ reason_code: 'something_new', message: 'Text serveru' }, t)).toBe('Text serveru')
  })

  it('dimenze před a po: jen změněné typy s popisky', () => {
    const row = { kind: 'header' as const, no: null, line_id: null, account_code: null, side: null, amount: null, splits: {} }
    const changes = dimensionSnapshotChanges(
      [{ ...row, dimensions: { 1: 11, 2: 22 } }],
      [{ ...row, dimensions: { 1: 12, 2: 22 } }],
      { types: { 1: 'Středisko', 2: 'Zakázka' }, values: { 11: 'Praha', 12: 'Brno', 22: 'Z1' } },
      t,
    )
    expect(changes).toEqual([{ label: 'bulk_actions.snapshot.header:{"no":""} · Středisko', from: 'Praha', to: 'Brno' }])
  })

  it('dimenze před a po: rozpad jako mapa hodnota → podíl a prázdná mapa z PHP jako []', () => {
    const line = { kind: 'line' as const, no: 2, line_id: 40, account_code: '501', side: 'debit' as const, amount: 100 }
    const changes = dimensionSnapshotChanges(
      [{ ...line, dimensions: [], splits: [] }],
      [{ ...line, dimensions: [], splits: { 5: { 12: 0.5, 13: 0.5 } } }],
      { types: { 5: 'Zakázka' }, values: { 12: 'A', 13: 'B' } },
      t,
    )
    expect(changes).toEqual([{ label: 'bulk_actions.snapshot.line:{"no":2} 501 · Zakázka', from: '—', to: 'A 50 %, B 50 %' }])
  })
})

describe('hromadné akce: výběr ze seznamu a oprávnění', () => {
  it('s výběrem jdou id a jako druhá volba filtr, bez výběru rovnou filtr', () => {
    expect(bulkDialogState([3, 4], { q: 'x' }, 40)).toEqual({
      selection: { ids: [3, 4] }, selectionCount: 2, filterSelection: { list_query: { q: 'x' } }, filterCount: 40,
    })
    expect(bulkDialogState([], { q: 'x' }, 40)).toEqual({
      selection: { list_query: { q: 'x' } }, selectionCount: 40, filterSelection: null, filterCount: 40,
    })
  })

  it('položku vidí nové právo, přeúčtování s právem zaúčtovat, nebo správce; klient nikdy', () => {
    const can = (keys: string[]) => (k: string) => keys.includes(k)
    expect(canOpenBulkActions({ canWrite: can(['accounting.bulk_changes']) })).toBe(true)
    expect(canOpenBulkActions({ canWrite: can(['accounting.journal.bulk_repost']) })).toBe(false)
    expect(canOpenBulkActions({ canWrite: can(['accounting.journal.bulk_repost', 'accounting.journal.post']) })).toBe(true)
    expect(canOpenBulkActions({ canWrite: can([]), isCompanyAdminRole: true })).toBe(true)
    expect(canOpenBulkActions({ canWrite: can(['accounting.bulk_changes']), isClientRole: true })).toBe(false)
  })
})
