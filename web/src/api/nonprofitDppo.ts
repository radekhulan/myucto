import { api } from './client'
import { useSupplierStore } from '@/stores/supplier'
import { requestSupplierId } from '@/workspace/supplierContext'
import type { AccountingPeriod } from './accounting'
import type { AcceptanceChange, AcceptanceState, LegalConfirm, LegalOverview, LegalReadiness, LegalRevision, NonprofitDppoState, SavingConfirm, SavingEvent, SavingOverview, SavingPlan, SavingReverseConfirm, SavingUseInput } from './nonprofitDppoTypes'
function options(supplier: number) {
 const requested = requestSupplierId(), store = useSupplierStore()
 if (!Number.isSafeInteger(supplier) || supplier <= 0 || (requested !== null ? Number(requested) !== supplier : !store.domainLocked || store.currentSupplierId !== supplier)) throw new Error('nonprofit_dppo_supplier_changed')
 return { headers: { 'X-Supplier-Id': String(supplier) } }
}
const profile = (period: number) => `/accounting/periods/${period}/nonprofit-legal-profile`
const source = (id: number) => `/accounting/nonprofit-tax-savings/sources/${id}`
export const nonprofitDppoApi = {
 periods: (supplier: number) => api.get<AccountingPeriod[]>('/accounting/periods', options(supplier)).then(r => r.data),
 profile: (supplier: number, period: number) => api.get<LegalOverview>(profile(period), options(supplier)).then(r => r.data),
 confirmProfile: (supplier: number, period: number, body: LegalConfirm) => api.post<LegalRevision>(`${profile(period)}/confirm`, body, options(supplier)).then(r => r.data),
 readiness: (supplier: number, period: number) => api.post<LegalReadiness>(`${profile(period)}/readiness`, {}, options(supplier)).then(r => r.data),
 acceptance: (supplier: number) => api.get<AcceptanceState>('/accounting/nonprofit-dppo/acceptance', options(supplier)).then(r => r.data),
 changeAcceptance: (supplier: number, operation: 'accept' | 'revoke', body: AcceptanceChange) => api.post<AcceptanceState>(`/accounting/nonprofit-dppo/acceptance/${operation}`, body, options(supplier)).then(r => r.data),
 savings: (supplier: number) => api.get<SavingOverview>('/accounting/nonprofit-tax-savings', options(supplier)).then(r => r.data),
 previewUse: (supplier: number, id: number, input: SavingUseInput) => api.post<SavingPlan>(`${source(id)}/preview`, input, options(supplier)).then(r => r.data),
 confirmUse: (supplier: number, id: number, body: SavingConfirm) => api.post<SavingEvent>(`${source(id)}/confirm`, body, options(supplier)).then(r => r.data),
 previewReverse: (supplier: number, id: number, event: number, reason: string) => api.post<SavingPlan>(`${source(id)}/events/${event}/reverse-preview`, { reason }, options(supplier)).then(r => r.data),
 confirmReverse: (supplier: number, id: number, event: number, body: SavingReverseConfirm) => api.post<SavingEvent>(`${source(id)}/events/${event}/reverse-confirm`, body, options(supplier)).then(r => r.data),
 saveReduction: (supplier: number, year: number, rowVersion: number, nonprofit: Record<string, unknown>) => api.put<NonprofitDppoState>(`/tax-return/po/${year}/inputs`, { row_version: rowVersion, inputs: { nonprofit } }, { ...options(supplier), params: { variant: 'radne' } }).then(r => r.data),
 native: (supplier: number, year: number) => api.get<NonprofitDppoState>(`/tax-return/po/${year}`, { ...options(supplier), params: { variant: 'radne' } }).then(r => r.data),
}
