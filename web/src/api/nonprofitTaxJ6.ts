import { api } from './client'
import { useSupplierStore } from '@/stores/supplier'
import { requestSupplierId } from '@/workspace/supplierContext'
import type {
  J6ActivityRow, J6AssessmentCompletion, J6AssessmentHistoryPage, J6AssessmentSummary, J6ConfirmRequest,
  J6ConvertRequest, J6CurrentDraft, J6CurrentSourceHeader, J6DecisionRow, J6DocumentFormat, J6PeriodSummary, J6WithdrawRequest, J6DraftCollectionPage, J6DraftEnvelope, J6DraftOpenRequest,
  J6DraftResetRequest, J6DraftSourcePage, J6FrozenCalculationPage, J6FrozenKind, J6FrozenSourcePage,
  J6LinkRow, J6MemberRow, J6PatchRequest, J6PoolKind, J6PoolRow, J6PreviewKind, J6PreviewPage,
  J6PreviewReceipt, J6TaxActivity, J6TaxDecision, J6TaxFundingLink, J6TaxPool,
} from './nonprofitTaxJ6Types'

const PAGE_LIMIT = 100
const MAX_BATCH = 500
const collectionNames = ['decisions', 'activities', 'funding-pools', 'funding-links'] as const
type CollectionName = typeof collectionNames[number]

function options(supplier: number) {
  const requested = requestSupplierId()
  const store = useSupplierStore()
  if (!Number.isSafeInteger(supplier) || supplier <= 0 || (requested !== null ? Number(requested) !== supplier : !store.domainLocked || store.currentSupplierId !== supplier)) {
    throw new Error('nonprofitTaxJ6.supplierChanged')
  }
  return { headers: { 'X-Supplier-Id': String(supplier) } }
}
function bounded(limit = PAGE_LIMIT) {
  if (!Number.isSafeInteger(limit) || limit < 1 || limit > PAGE_LIMIT) throw new Error('nonprofitTaxJ6.pageLimit')
  return limit
}
function batch<T, D>(body: J6PatchRequest<T, D>) {
  if (!Number.isSafeInteger(body.expected_revision) || body.expected_revision <= 0 || body.upserts.length + body.deletes.length > MAX_BATCH) {
    throw new Error('nonprofitTaxJ6.batchLimit')
  }
  return body
}
function base(period: number) { return `/accounting/periods/${period}/nonprofit-tax` }
function draft(period: number, id: number) { return `${base(period)}/drafts/${id}` }
function params(after: string | null | undefined, limit: number) { return { ...(after ? { after } : {}), limit: bounded(limit) } }
function getCollection<T>(supplier: number, period: number, id: number, name: CollectionName, after?: string | null, limit = PAGE_LIMIT) {
  return api.get<J6DraftCollectionPage<T>>(`${draft(period, id)}/${name}`, { ...options(supplier), params: params(after, limit) }).then(r => r.data)
}
function patchCollection<T, D>(supplier: number, period: number, id: number, name: CollectionName, body: J6PatchRequest<T, D>) {
  return api.patch<J6DraftEnvelope>(`${draft(period, id)}/${name}`, batch(body), options(supplier)).then(r => r.data)
}
function segment(value: string) { return encodeURIComponent(value) }

export const nonprofitTaxJ6Api = {
  currentSource: (supplier: number, period: number) => api.get<J6CurrentSourceHeader>(`${base(period)}/source`, options(supplier)).then(r => r.data),
  openDraft: (supplier: number, period: number, body: J6DraftOpenRequest) => api.post<J6DraftEnvelope>(`${base(period)}/draft`, body, options(supplier)).then(r => r.data),
  getDraft: (supplier: number, period: number, id: number) => api.get<J6DraftEnvelope>(draft(period, id), options(supplier)).then(r => r.data),
  resetDraft: (supplier: number, period: number, id: number, body: J6DraftResetRequest) => api.post<J6DraftEnvelope>(`${draft(period, id)}/reset`, body, options(supplier)).then(r => r.data),
  draftSource: (supplier: number, period: number, id: number, after?: string | null, limit = PAGE_LIMIT) => api.get<J6DraftSourcePage>(`${draft(period, id)}/source`, { ...options(supplier), params: params(after, limit) }).then(r => r.data),
  decisions: (supplier: number, period: number, id: number, after?: string | null, limit = PAGE_LIMIT) => getCollection<J6DecisionRow>(supplier, period, id, 'decisions', after, limit),
  activities: (supplier: number, period: number, id: number, after?: string | null, limit = PAGE_LIMIT) => getCollection<J6ActivityRow>(supplier, period, id, 'activities', after, limit),
  fundingPools: (supplier: number, period: number, id: number, after?: string | null, limit = PAGE_LIMIT) => getCollection<J6PoolRow>(supplier, period, id, 'funding-pools', after, limit),
  fundingLinks: (supplier: number, period: number, id: number, after?: string | null, limit = PAGE_LIMIT) => getCollection<J6LinkRow>(supplier, period, id, 'funding-links', after, limit),
  fundingMembers: (supplier: number, period: number, id: number, kind: J6PoolKind, anchor: string, after?: string | null, limit = PAGE_LIMIT) => api.get<J6DraftCollectionPage<J6MemberRow>>(`${draft(period, id)}/funding-pools/${kind}/${segment(anchor)}/members`, { ...options(supplier), params: params(after, limit) }).then(r => r.data),
  patchDecisions: (supplier: number, period: number, id: number, body: J6PatchRequest<{ source_id: string; payload: J6TaxDecision }, { source_id: string }>) => patchCollection(supplier, period, id, 'decisions', body),
  patchActivities: (supplier: number, period: number, id: number, body: J6PatchRequest<{ activity_kind_id: string; payload: J6TaxActivity }, { activity_kind_id: string }>) => patchCollection(supplier, period, id, 'activities', body),
  patchFundingPools: (supplier: number, period: number, id: number, body: J6PatchRequest<{ pool_kind: J6PoolKind; anchor: string; payload: J6TaxPool }, { pool_kind: J6PoolKind; anchor: string }>) => patchCollection(supplier, period, id, 'funding-pools', body),
  patchFundingLinks: (supplier: number, period: number, id: number, body: J6PatchRequest<{ source_id: string; cost_id: string; payload: J6TaxFundingLink }, { source_id: string; cost_id: string }>) => patchCollection(supplier, period, id, 'funding-links', body),
  patchFundingMembers: (supplier: number, period: number, id: number, kind: J6PoolKind, anchor: string, body: J6PatchRequest<string, string>) => api.patch<J6DraftEnvelope>(`${draft(period, id)}/funding-pools/${kind}/${segment(anchor)}/members`, batch(body), options(supplier)).then(r => r.data),
  preview: (supplier: number, period: number, id: number, expectedRevision: number) => api.post<J6PreviewReceipt>(`${draft(period, id)}/preview`, { expected_revision: expectedRevision }, options(supplier)).then(r => r.data),
  previewPage: (supplier: number, period: number, id: number, token: string, kind: J6PreviewKind, page = 0) => api.get<J6PreviewPage>(`${draft(period, id)}/previews/${token}/${kind}`, { ...options(supplier), params: { page } }).then(r => r.data),
  confirm: (supplier: number, period: number, id: number, body: J6ConfirmRequest) => api.post<J6AssessmentCompletion>(`${draft(period, id)}/confirm`, body, options(supplier)).then(r => r.data),
  history: (supplier: number, period: number, after?: string | null, limit = PAGE_LIMIT) => api.get<J6AssessmentHistoryPage>(`${base(period)}/history`, { ...options(supplier), params: params(after, limit) }).then(r => r.data),
  assessment: (supplier: number, id: number) => api.get<J6AssessmentSummary>(`/accounting/nonprofit-tax-assessments/${id}`, options(supplier)).then(r => r.data),
  frozenSource: (supplier: number, id: number, after?: string | null, limit = PAGE_LIMIT) => api.get<J6FrozenSourcePage>(`/accounting/nonprofit-tax-assessments/${id}/source`, { ...options(supplier), params: params(after, limit) }).then(r => r.data),
  frozenPage: (supplier: number, id: number, kind: J6FrozenKind, after?: string | null, limit = PAGE_LIMIT) => api.get<J6FrozenCalculationPage>(`/accounting/nonprofit-tax-assessments/${id}/${kind}`, { ...options(supplier), params: params(after, limit) }).then(r => r.data),
  export: (supplier: number, id: number) => api.get(`/accounting/nonprofit-tax-assessments/${id}/export`, { ...options(supplier), responseType: 'blob' }),
  document: (supplier: number, id: number, format: J6DocumentFormat) => api.get(`/accounting/nonprofit-tax-assessments/${id}/summary.${format}`, { ...options(supplier), responseType: 'blob' }),
  periodSummary: (supplier: number, period: number) => api.get<J6PeriodSummary>(`${base(period)}/summary`, options(supplier)).then(r => r.data),
  currentDraft: (supplier: number, period: number) => api.get<J6CurrentDraft>(`${base(period)}/draft`, options(supplier)).then(r => r.data),
  withdraw: (supplier: number, period: number, id: number, body: J6WithdrawRequest) => api.post<J6AssessmentCompletion>(`${base(period)}/assessments/${id}/withdraw`, body, options(supplier)).then(r => r.data),
  convert: (supplier: number, period: number, id: number, body: J6ConvertRequest) => api.post<J6AssessmentCompletion>(`${base(period)}/assessments/${id}/convert`, body, options(supplier)).then(r => r.data),
}
