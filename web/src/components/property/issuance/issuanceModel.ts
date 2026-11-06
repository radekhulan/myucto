import type { CaseCandidate, CaseDistinction, CaseFamily, CaseIdentity, IssueContext, IssueRequest, IssueSnapshot, IssueUnit, LiabilityGroup } from '@/api/propertyChargeIssuance'
import type { UnitState } from '@/api/propertyRegistry'

export function issueMoney(cents: string, locale = 'cs'): string {
  const amount = BigInt(cents); const value = amount < 0n ? -amount : amount
  return `${amount < 0n ? '-' : ''}${(value / 100n).toLocaleString(locale)}${locale.startsWith('cs') ? ',' : '.'}${String(value % 100n).padStart(2, '0')}`
}
export function emptyIssue(context: IssueContext): IssueRequest {
  return { version_id: context.version.id, source_key: crypto.randomUUID(), title: '', issue_mode: 'initial', period_from: '', period_to: '',
    claim_origin_at: context.as_of, issued_at: '', accounting_date: '', due_date: '', reason: '', units: [],
    accounts: context.version.preview.calculation.rates.map(rate => ({ component_id: rate.component.id, receivable_account: '', counter_account: '', basis_ref: '', settlement_due_on: null, dimensions: {} })) }
}
export function emptyIssueUnit(context: IssueContext, id: number): IssueUnit {
  const unit = context.units.find(v => v.id === id)
  const services = (unit?.revision?.state as UnitState | undefined)?.services ?? []
  return { unit_id: id, expected_revision: 0, previous_charge_id: null, liability_groups: [],
    notices: context.version.preview.calculation.rates.filter(r => r.component.kind === 'service_advance' && services.includes(r.component.service_id ?? 0))
      .map(r => ({ component_id: r.component.id, notice: { mode: '', title_ref: '', written_notice_ref: '', reason: '', deliveries: [] } })) }
}
export function emptyLiability(at: string): LiabilityGroup {
  return { group_key: crypto.randomUUID(), role: '', mode: '', basis: '', members: [], extent: { num: '', den: '' }, evidence_ref: '', legal_reason: '', valid_from: at, valid_to: null }
}

export const familyKey = (family: Pick<CaseFamily, 'unit_id' | 'source_key'>) => `${family.unit_id}|${family.source_key}`
const clean = (value: string) => value.normalize('NFC').trim()
const within = (value: unknown, max: number) => typeof value === 'string' && clean(value).length > 0 && Array.from(clean(value)).length <= max
export function emptyCaseIdentity(): CaseIdentity {
  return { schema: 1, case_ref: '', occurrence_ref: '', title_ref: '', decision_ref: '', decided_on: '', evidence_ref: '' }
}
export function emptyCaseDistinction(): CaseDistinction { return { against_source_keys: [], legal_reason: '', evidence_ref: '' } }
export function caseIdentityComplete(identity: CaseIdentity | undefined): boolean {
  return !!identity && within(identity.case_ref, 160) && within(identity.occurrence_ref, 160) && within(identity.title_ref, 500)
    && within(identity.decision_ref, 500) && /^\d{4}-\d{2}-\d{2}$/.test(identity.decided_on) && within(identity.evidence_ref, 4000)
}
export function caseHeadline(identity: CaseIdentity | null | undefined): string {
  return identity ? [identity.case_ref, identity.occurrence_ref, identity.title_ref].join(' · ') : ''
}
const sortedKeys = (keys: string[]) => [...new Set(keys)].sort()
export function distinctionComplete(distinction: CaseDistinction, candidates: CaseCandidate[] | undefined): boolean {
  const keys = sortedKeys(distinction.against_source_keys)
  if (!within(distinction.legal_reason, 4000) || !within(distinction.evidence_ref, 4000) || !keys.length) return false
  if (!candidates?.length) return true
  const expected = sortedKeys(candidates.map(c => c.source_key))
  return keys.length === expected.length && keys.every((key, index) => key === expected[index])
}
export function caseRequestReady(request: IssueRequest, candidates: Record<number, CaseCandidate[] | undefined>): boolean {
  if (request.issue_mode === 'initial' && !caseIdentityComplete(request.case_identity)) return false
  return request.units.every(unit => unit.case_distinction ? distinctionComplete(unit.case_distinction, candidates[unit.unit_id]) : !candidates[unit.unit_id]?.length)
}
export function normalizeCaseRequest(request: IssueRequest): IssueRequest {
  const copy = JSON.parse(JSON.stringify(request)) as IssueRequest
  if (request.issue_mode !== 'initial') return copy
  if (copy.case_identity) for (const field of ['case_ref', 'occurrence_ref', 'title_ref', 'decision_ref', 'evidence_ref'] as const) copy.case_identity[field] = clean(copy.case_identity[field])
  for (const unit of copy.units) if (unit.case_distinction) {
    unit.case_distinction = { against_source_keys: sortedKeys(unit.case_distinction.against_source_keys), legal_reason: clean(unit.case_distinction.legal_reason), evidence_ref: clean(unit.case_distinction.evidence_ref) }
  }
  return copy
}
export interface CaseErrorInfo { code: string; status: number; details: Record<string, unknown> }
export function caseErrorOf(error: unknown): CaseErrorInfo {
  const response = (error as { response?: { status?: number; data?: { error?: { code?: unknown; details?: unknown } } } } | null)?.response
  const details = response?.data?.error?.details
  return { code: typeof response?.data?.error?.code === 'string' ? response.data.error.code : '', status: response?.status ?? 0,
    details: details && typeof details === 'object' && !Array.isArray(details) ? details as Record<string, unknown> : {} }
}
export function candidatesOf(details: Record<string, unknown>): { unit_id: number; candidates: CaseCandidate[] } | null {
  const list = details.case_candidates
  if (!Number.isSafeInteger(details.unit_id) || !Array.isArray(list)) return null
  const valid = list.filter(c => c && typeof c === 'object' && typeof (c as CaseCandidate).source_key === 'string' && Number.isSafeInteger((c as CaseCandidate).unit_id)) as CaseCandidate[]
  return valid.length === list.length ? { unit_id: details.unit_id as number, candidates: JSON.parse(JSON.stringify(valid)) } : null
}
const canon = (value: unknown): string => Array.isArray(value) ? `[${value.map(canon).join(',')}]` : value && typeof value === 'object'
  ? `{${Object.keys(value).sort().map(k => `${JSON.stringify(k)}:${canon((value as Record<string, unknown>)[k])}`).join(',')}}` : JSON.stringify(value)
export function continuationCase(family: CaseFamily | undefined, snapshot: Pick<IssueSnapshot, 'case_identity' | 'case_distinction'>): { identity?: CaseIdentity; distinction?: CaseDistinction } | null {
  const identity = family ? family.case_identity : snapshot.case_identity ?? null
  const distinction = family ? family.case_distinction : snapshot.case_distinction ?? null
  if (family && ((snapshot.case_identity && canon(snapshot.case_identity) !== canon(identity)) || (snapshot.case_distinction && canon(snapshot.case_distinction) !== canon(distinction)))) return null
  if (!identity && distinction) return null
  const copy = <T>(value: T) => JSON.parse(JSON.stringify(value)) as T
  return { ...(identity ? { identity: copy(identity) } : {}), ...(distinction ? { distinction: copy(distinction) } : {}) }
}
