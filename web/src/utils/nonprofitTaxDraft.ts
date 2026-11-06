import type { TaxAssessment, TaxDecision, TaxPool, TaxSnapshot, TaxSource } from '@/api/nonprofitTax'

export function activityName(source: TaxSource | undefined, fallback = ''): string {
  return source && !Array.isArray(source.axes) ? source.axes.activity_kind?.name ?? fallback : fallback
}

export function centsText(value: number | undefined): string {
  if (value === undefined) return ''
  if (!Number.isSafeInteger(value)) throw new Error('nonprofitTax.invalidAmount')
  const cents = BigInt(value)
  const absolute = cents < 0n ? -cents : cents
  return `${cents < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`
}
export function parseCents(value: string): number {
  const normalized = value.trim().replace(',', '.')
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(normalized)) throw new Error('nonprofitTax.invalidAmount')
  const [whole, fraction = ''] = normalized.replace('-', '').split('.')
  const cents = (BigInt(whole!) * 100n + BigInt(fraction.padEnd(2, '0'))) * (normalized.startsWith('-') ? -1n : 1n)
  const result = Number(cents)
  if (!Number.isSafeInteger(result)) throw new Error('nonprofitTax.invalidAmount')
  return result
}
export interface DecisionDraft extends Omit<TaxDecision, 'deductible_cents' | 'facts'> {
  deductible: string
  facts: Record<string, string | boolean | undefined>
}
export interface PoolDraft { anchor: string; members: string[]; evidence: string; review: boolean; reviewEvidence: string; reviewBasis: string; reviewAmount: string }
export interface AssessmentDraft {
  method?: TaxAssessment['method']
  profile: TaxAssessment['profile']
  activities: Record<string, { evidence: string; cohorts: { code: string; evidence: string }[] }>
  decisions: Record<string, DecisionDraft>
  funding: { source_id: string; cost_id: string; amount: string; evidence: string; overlap: string }[]
  funding_sources: PoolDraft[]
  funding_costs: PoolDraft[]
}
const poolDraft = (pool: TaxPool): PoolDraft => ({ anchor: pool.anchor, members: [...pool.members], evidence: pool.evidence,
  review: !!pool.prior_period_review, reviewEvidence: pool.prior_period_review?.evidence ?? '', reviewBasis: pool.prior_period_review?.legal_basis ?? '', reviewAmount: centsText(pool.prior_period_review?.adjustment_cents) })
export function legalProfileChoices(snapshot: TaxSnapshot | null): string[] {
  if (snapshot?.legal_profile) return [snapshot.legal_profile.profile.kind]
  return snapshot?.svj_module_proof?.length ? ['svj'] : ['narrow_vpp', 'broad_vpp', 'non_vpp', 'svj']
}
export function assessmentDraft(snapshot: TaxSnapshot, previous?: TaxAssessment): AssessmentDraft {
  const manual = snapshot.assessment_support?.automatic === false && snapshot.assessment_support.documented_manual
  const draft: AssessmentDraft = { ...(manual ? { method: previous?.method ? { ...previous.method } : { mode: 'documented_manual' as const, valid_from: snapshot.period.starts_on, valid_to: snapshot.period.ends_on, legal_basis: '', evidence: '' } } : {}), profile: previous ? { ...previous.profile } : { kind: '', evidence: '' }, activities: {}, decisions: {},
    funding: previous?.funding.map(link => ({ source_id: link.source_id, cost_id: link.cost_id, amount: centsText(link.amount_cents), evidence: link.evidence, overlap: centsText(link.deductible_funded_cents) })) ?? [],
    funding_sources: previous?.funding_sources?.map(poolDraft) ?? [], funding_costs: previous?.funding_costs?.map(poolDraft) ?? [] }
  if (manual) draft.profile = { kind: 'documented', evidence: previous?.profile.evidence ?? '', ...(previous?.profile.entity_kind ? { entity_kind: previous.profile.entity_kind } : {}) }
  for (const source of snapshot.sources) {
    if (source.activity_kind_id !== null) {
      const activity = previous?.activities[source.activity_kind_id]
      draft.activities[source.activity_kind_id] ??= { evidence: activity?.evidence ?? '', cohorts: Object.entries(activity?.cohorts ?? {}).map(([code, evidence]) => ({ code, evidence })) }
    }
    const old = previous?.decisions[source.id]
    const facts: DecisionDraft['facts'] = {}
    for (const [key, value] of Object.entries(old?.facts ?? {})) facts[key] = key.endsWith('_cents') ? centsText(value as number) : value as string | boolean
    draft.decisions[source.id] = { ...(manual ? { final_regime: old?.final_regime ?? '', ...(source.kind === 'revenue' ? { final_method: old?.final_method ?? '' } : {}) } : {}), title: old?.title ?? '', evidence: old?.evidence ?? '', legal_basis: old?.legal_basis ?? '', cohort: old?.cohort ?? '', deductible: centsText(old?.deductible_cents), facts }
  }
  return draft
}
export function assessmentPayload(draft: AssessmentDraft): TaxAssessment {
  const activities: TaxAssessment['activities'] = {}
  for (const [id, activity] of Object.entries(draft.activities)) {
    const cohorts: Record<string, string> = {}
    for (const cohort of activity.cohorts) {
      if (!cohort.code.trim() || Object.hasOwn(cohorts, cohort.code.trim())) throw new Error('nonprofitTax.invalidCohort')
      cohorts[cohort.code.trim()] = cohort.evidence
    }
    activities[id] = { evidence: activity.evidence, ...(activity.cohorts.length ? { cohorts } : {}) }
  }
  const decisions: TaxAssessment['decisions'] = {}
  for (const [id, row] of Object.entries(draft.decisions)) {
    const facts: NonNullable<TaxDecision['facts']> = {}
    for (const [key, value] of Object.entries(row.facts)) {
      if (value !== undefined && value !== '') facts[key] = key.endsWith('_cents') ? parseCents(String(value)) : value
    }
    decisions[id] = { ...(draft.method ? { final_regime: row.final_regime, ...(row.final_method !== undefined ? { final_method: row.final_method } : {}) } : {}), title: row.title, evidence: row.evidence, legal_basis: row.legal_basis, cohort: row.cohort, facts,
      ...(row.deductible.trim() ? { deductible_cents: parseCents(row.deductible) } : {}) }
  }
  const pool = (value: PoolDraft): TaxPool => ({ anchor: value.anchor, members: [...value.members], evidence: value.evidence,
    ...(value.review ? { prior_period_review: { evidence: value.reviewEvidence, legal_basis: value.reviewBasis, adjustment_cents: parseCents(value.reviewAmount) } } : {}) })
  return { ...(draft.method ? { method: { ...draft.method } } : {}), profile: { ...draft.profile }, activities, decisions,
    funding: draft.funding.map(link => ({ source_id: link.source_id, cost_id: link.cost_id, amount_cents: parseCents(link.amount), evidence: link.evidence,
      ...(link.overlap.trim() ? { deductible_funded_cents: parseCents(link.overlap) } : {}) })),
    funding_sources: draft.funding_sources.map(pool), funding_costs: draft.funding_costs.map(pool) }
}

export function validateManualAssessment(draft: AssessmentDraft, snapshot: TaxSnapshot): void {
  const method = draft.method
  const identity = method ? draft.profile.entity_kind : draft.profile.kind
  if ((snapshot.legal_profile || snapshot.svj_module_proof?.length) && identity && !legalProfileChoices(snapshot).includes(identity)) throw new Error('nonprofitTaxNative.profileConflict')
  if (method && (snapshot.legal_profile || snapshot.svj_module_proof?.length) && !identity) throw new Error('nonprofitTaxNative.profileRequired')
  if (!method) return
  const fail = (key: string): never => { throw new Error(`nonprofitTax.manual.${key}`) }
  if (snapshot.assessment_support?.automatic !== false || !snapshot.assessment_support.documented_manual) fail('unsupported')
  const date = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value
  if (!date(method.valid_from) || !date(method.valid_to) || method.valid_from > snapshot.period.starts_on || method.valid_to < snapshot.period.ends_on) fail('intervalError')
  if (!method.evidence.trim() || !method.legal_basis.trim() || draft.profile.kind !== 'documented' || !draft.profile.evidence.trim()) fail('evidenceError')
  for (const activity of Object.values(draft.activities)) {
    if (!activity.evidence.trim() || activity.cohorts.some(cohort => !cohort.code.trim() || !cohort.evidence.trim())) fail('evidenceError')
  }
  for (const source of snapshot.sources) {
    const row = draft.decisions[source.id]
    if (!row || !row.title.trim() || !row.evidence.trim() || !row.legal_basis?.trim()) fail('evidenceError')
    if (!['subject', 'exempt', 'out_of_scope'].includes(row.final_regime ?? '')) fail('classificationError')
    if (source.kind === 'revenue') {
      if (!['return', 'none', 'final_withholding'].includes(row.final_method ?? '') || (row.final_regime !== 'subject') !== (row.final_method === 'none')) fail('classificationError')
      if (row.title === 'gift') {
        const election = row.facts.claim_exemption
        if (typeof election !== 'boolean' || (election && (row.final_regime !== 'exempt' || !String(row.facts.election_evidence ?? '').trim())) || (!election && row.final_regime === 'exempt')) fail('giftError')
      }
      if (row.final_method === 'final_withholding') {
        const gross = parseCents(String(row.facts.gross_interest_cents ?? ''))
        const withheld = parseCents(String(row.facts.withholding_cents ?? ''))
        if (BigInt(gross) - BigInt(withheld) !== BigInt(source.amount_cents)) fail('withholdingError')
      }
    } else {
      const deductible = parseCents(row.deductible)
      if ((row.final_regime !== 'subject' || source.mandatory_non_deductible) && deductible !== 0) fail('deductibleError')
      if (source.amount_cents >= 0 ? deductible < 0 || deductible > source.amount_cents : deductible > 0 || deductible < source.amount_cents) fail('deductibleError')
    }
  }
}
