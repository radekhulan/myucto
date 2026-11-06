import { api } from './client'
import type { InternalDocumentKind, InternalDocumentPayload } from './internalDocuments'
import type { DimensionMap } from './dimensions'

export interface AiAssistantStatus {
  available: boolean
  reason: string | null
  provider: string | null
  model: string | null
  supports_files: boolean
  models?: string[]
}

export interface AiJournalLine {
  account_code: string
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  note: string | null
}

export interface AiJournalEntry {
  entry_date: string
  description: string
  document_no: string | null
  lines: AiJournalLine[]
  balanced: boolean
  unknown_accounts: string[]
}

export type AiOtherItemKind = 'other' | 'rent' | 'loan' | 'deposit' | 'insurance' | 'fee' | 'claim'

/** Návrh ostatní pohledávky / závazku — zakládá se jen jako koncept. */
export interface AiOtherItem {
  side: 'receivable' | 'payable'
  kind: AiOtherItemKind
  title: string
  partner_id: number | null
  partner_name: string | null
  partner_ico: string | null
  amount: number
  currency: string
  issued_on: string
  due_on: string
  variable_symbol: string | null
  account_code: string | null
  account_name: string | null
  counter_account_code: string | null
  counter_account_name: string | null
  note: string | null
  installments: { due_on: string; amount: number }[]
  installments_valid: boolean
  unknown_accounts: string[]
}

export interface AiInternalDocumentLine {
  account_code: string
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  /** Dimenze ve tvaru agendy: typ => hodnota. */
  dimensions: Record<string, number> | number[]
  dimension_labels: string[]
}

/**
 * Návrh interního dokladu. Zakládá se existující cestou (POST /accounting/internal-documents),
 * `problems` jsou nálezy serverové kontroly stejnými pravidly jako zaúčtování ručního dokladu.
 */
export interface AiInternalDocument {
  kind: InternalDocumentKind
  title: string
  document_date: string
  entry_date: string
  partner_id: number | null
  partner_name: string | null
  partner_ico: string | null
  note: string | null
  lines: AiInternalDocumentLine[]
  total_debit: number
  total_credit: number
  balanced: boolean
  unknown_accounts: string[]
  problems: string[]
  postable: boolean
}

export type AiAttachmentRef = { kind: 'file'; index: number } | { kind: 'document'; id: number }

export interface AiDocumentSuggestion {
  attachment: AiAttachmentRef
  folder_id: number | null
  folder_path: string | null
  new_folder_name: string | null
  title: string | null
}

export interface AiHistoryTurn {
  role: 'user' | 'assistant'
  content: string
}

export interface AiAskResponse {
  answer: string
  journal_entries: AiJournalEntry[]
  other_items?: AiOtherItem[]
  internal_documents?: AiInternalDocument[]
  document_suggestions: AiDocumentSuggestion[]
  warnings: string[]
  provider: string
  model: string
  usage: { input_tokens: number; output_tokens: number }
  /** Uložená konverzace a id uložené odpovědi (chybí, když server historii neukládá). */
  conversation_id?: number
  message_id?: number
}

export type AiAppliedKind = 'journal_entry' | 'other_item' | 'internal_document' | 'document'
/** Použité návrhy zprávy: {kind: {index: id}}. Ze serveru může dorazit i jako pole. */
export type AiApplied = Partial<Record<AiAppliedKind, Record<string, number> | number[]>>

export function appliedId(applied: AiApplied | null | undefined, kind: AiAppliedKind, index: number): number | null {
  const bucket = applied?.[kind] as Record<string, number> | number[] | undefined
  if (!bucket) return null
  const v = Array.isArray(bucket) ? bucket[index] : bucket[String(index)]
  const n = Number(v)
  return Number.isInteger(n) && n > 0 ? n : null
}

export interface AiConversationSummary {
  id: number
  title: string
  created_at: string
  updated_at: string
  question_count: number
}

export type AiStoredAttachment = { kind: 'file'; name: string } | { kind: 'document'; id: number }

export interface AiStoredMessage {
  id: number
  role: 'user' | 'assistant'
  content: string
  attachments: AiStoredAttachment[] | null
  response: {
    other_items?: AiOtherItem[]
    internal_documents?: AiInternalDocument[]
    journal_entries?: AiJournalEntry[]
    document_suggestions?: AiDocumentSuggestion[]
    warnings?: string[]
  } | null
  applied: AiApplied | null
  model: string | null
  created_at: string
}

export interface AiConversation {
  id: number
  title: string
  created_at: string
  updated_at: string
  messages: AiStoredMessage[]
}

/** Koncept pro ruční zápis (history.state.aiDraft) — jen JSON-klonovatelná data. */
export interface AiManualDraft {
  entry_date: string
  description: string
  document_no: string
  lines: { account_code: string; side: 'debit' | 'credit'; amount: number }[]
  document_ids: number[]
}

export interface AiAskInput {
  question: string
  history: AiHistoryTurn[]
  files: File[]
  documentIds: number[]
  includeChart: boolean
  model?: string
  /** Navazující dotaz v uložené konverzaci — kontext si server vezme sám. */
  conversationId?: number | null
}

export interface AiDocumentSummary {
  ai_summary_short: string | null
  ai_summary_long: string | null
  ai_summary_at: string | null
}

export interface AiDocumentAnalysis {
  detail: 'short' | 'long'
  accounting: boolean
  answer: string
  fields: { label: string; value: string }[]
  journal_entries: AiJournalEntry[]
  other_items?: AiOtherItem[]
  warnings: string[]
  provider: string | null
  model: string | null
  created_at: string | null
}

/** Tělo POST /accounting/internal-documents z návrhu AI: stejný tvar jako ruční editor. */
export function internalDocumentPayload(doc: AiInternalDocument): InternalDocumentPayload {
  return {
    kind: doc.kind,
    title: doc.title,
    document_date: doc.document_date,
    entry_date: doc.entry_date,
    partner_id: doc.partner_id,
    partner_name: doc.partner_name,
    note: doc.note,
    lines: doc.lines.map(l => {
      const dims: DimensionMap = {}
      if (!Array.isArray(l.dimensions)) {
        for (const [type, value] of Object.entries(l.dimensions ?? {})) dims[Number(type)] = Number(value)
      }
      return { account_code: l.account_code, side: l.side, amount: Number(l.amount), dimensions: dims }
    }),
    document_links: [],
  }
}

export function buildAskForm(input: AiAskInput): FormData {
  const fd = new FormData()
  fd.append('question', input.question)
  if (input.model) fd.append('model', input.model)
  if (input.conversationId) fd.append('conversation_id', String(input.conversationId))
  else fd.append('history', JSON.stringify(input.history.slice(-10)))
  for (const f of input.files) fd.append('files[]', f, f.name)
  for (const id of input.documentIds) fd.append('document_ids[]', String(id))
  fd.append('include_chart', input.includeChart ? '1' : '0')
  return fd
}

export const aiAssistantApi = {
  status: () => api.get<AiAssistantStatus>('/accounting/ai-assistant/status').then(r => r.data),

  ask: (input: AiAskInput) =>
    api.post<AiAskResponse>('/accounting/ai-assistant/ask', buildAskForm(input), {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  conversations: () =>
    api.get<{ items: AiConversationSummary[] }>('/accounting/ai-assistant/conversations').then(r => r.data.items ?? []),
  conversation: (id: number) =>
    api.get<AiConversation>(`/accounting/ai-assistant/conversations/${id}`).then(r => r.data),
  deleteConversation: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/ai-assistant/conversations/${id}`).then(r => r.data),
  markApplied: (messageId: number, kind: AiAppliedKind, index: number, targetId: number) =>
    api.post<{ applied: AiApplied }>(`/accounting/ai-assistant/messages/${messageId}/applied`, { kind, index, target_id: targetId })
      .then(r => r.data.applied),

  documentSummary: (id: number, length: 'short' | 'long') =>
    api.post<AiDocumentSummary>(`/documents/${id}/ai/summary`, { length }).then(r => r.data),

  documentAnalysis: (id: number, detail: 'short' | 'long', accounting: boolean) =>
    api.post<{ analysis: AiDocumentAnalysis }>(`/documents/${id}/ai/analysis`, { detail, accounting })
      .then(r => r.data.analysis),
}

/** Zpráva chyby z Json::error (`error.message`), jinak záložní text. */
export function aiErrorMessage(e: unknown, fallback: string): string {
  const msg = (e as { response?: { data?: { error?: { message?: unknown } } } })?.response?.data?.error?.message
  return typeof msg === 'string' && msg.trim() !== '' ? msg : fallback
}
