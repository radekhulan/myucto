import source from '@/pages/bank/StatementDetail.vue?raw'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { computed, ref } from 'vue'
import { describe, expect, it } from 'vitest'

// Akce „Smazat výpis" se vyhodnocuje stejně jako na stránce: vyřízne se computed
// statementActions (a podmínka blokace) a spustí nad skutečnými reaktivními refy.
function slice(from: string, to: string): string {
  const start = source.indexOf(from)
  const end = source.indexOf(to, start)
  if (start < 0 || end < 0) throw new Error(`Úsek ${from} nenalezen`)
  return source.slice(start, end)
}

function deleteAction(statement: Record<string, unknown>, auth: Record<string, unknown>) {
  const code = ts.transpileModule(
    slice('const noticeDeleteBlocked', '\n') + '\n' + slice('const statementActions', '\nuseWindowRefresh('),
    { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
  ).outputText
  const stmt = ref(statement)
  const context = {
    computed, statement: stmt, auth,
    isVirtual: computed(() => stmt.value.source === 'email_notice' || stmt.value.source === 'idoklad'),
    t: (key: string) => key, rematching: ref(false), sendingUnmatched: ref(false), uploadingPdf: ref(false),
    deletingStatement: ref(false), bankDownloads: ref([]), bankDownloadsOpen: ref(false), pdfInput: ref(null), bankApi: { unmatchedExportUrl: () => '', pdfUrl: () => '' },
    statementHasGpc: () => false, statementGpcUrl: () => undefined, statementGpcTitle: () => 'gpc', router: {},
    downloadStatementFile: () => undefined, rematchStatement: () => undefined, sendUnmatchedToClient: () => undefined,
    onDeletePdf: () => undefined, onDeleteStatement: () => undefined,
  }
  const actions = runInNewContext(`${code}\nstatementActions.value`, context) as Array<Record<string, unknown>>
  return actions.find(a => a.key === 'statement_delete')!
}

const writer = { isClientRole: false, isCompanyAdminRole: false, canWrite: (p: string) => p === 'bank' }
const reader = { isClientRole: false, isCompanyAdminRole: false, canWrite: () => false }
const client = { isClientRole: true, isCompanyAdminRole: false, canWrite: () => true }
const admin = { isClientRole: false, isCompanyAdminRole: true, canWrite: () => true }

describe('StatementDetail — mazání avízového výpisu podle pravidla backendu', () => {
  it('nabídne smazání avíza každému s bankou k zápisu, ne klientovi ani čtenáři', () => {
    const notice = { source: 'email_notice', matched_count: 0 }
    expect(deleteAction(notice, writer).show).toBe(true)
    expect(deleteAction(notice, writer).disabled).toBe(false)
    expect(deleteAction({ ...notice, source: 'idoklad' }, writer).show).toBe(true)
    expect(deleteAction(notice, reader).show).toBe(false)
    expect(deleteAction(notice, client).show).toBe(false)
  })

  it('avízo se spárovanou položkou ukáže neaktivní tlačítko s vysvětlením', () => {
    const action = deleteAction({ source: 'email_notice', matched_count: 2 }, writer)
    expect(action.show).toBe(true)
    expect(action.disabled).toBe(true)
    expect(action.disabledReason).toBe('bank.statement_delete_has_matches')
  })

  it('ostatní výpisy drží dosavadní pravidlo (admin a zdroj měsíčního API)', () => {
    expect(deleteAction({ source: 'gpc', matched_count: 0 }, writer).show).toBe(false)
    expect(deleteAction({ source: 'gpc', matched_count: 0, api_evidence: true }, writer).show).toBe(false)
    expect(deleteAction({ source: 'gpc', matched_count: 0, api_evidence: true }, admin).show).toBe(true)
    expect(deleteAction({ source: 'gpc', matched_count: 0 }, admin).show).toBe(false)
  })
})
