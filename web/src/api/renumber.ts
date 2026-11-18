import { api } from './client'

/**
 * Přečíslování dokladů měsíce v agendách s ročními řadami (pokladna, ostatní
 * pohledávky a závazky, interní doklady). Přijaté doklady mají vlastní endpoint
 * v `purchaseInvoicesApi` (renumberPreview / renumber).
 */
export type RenumberAgenda = 'cash' | 'other_receivable' | 'other_payable' | 'internal'

export type RenumberReason =
  | 'year_closed' | 'period_closed' | 'period_closing' | 'date_locked'
  | 'manual_number' | 'cash_in' | 'eet' | 'vat_document' | 'linked_invoice'

export interface AgendaRenumberRow {
  id: number
  number: string
  date: string
  partner: string
  description: string
  amount: number
  currency: string
  status: string
  doc_type?: 'in' | 'out'
  kind?: string
  new_number?: string
  series?: string
  changed?: boolean
  reason?: RenumberReason
}

export interface AgendaRenumberPlan {
  period: string
  changes: AgendaRenumberRow[]
  kept: AgendaRenumberRow[]
  blocked: AgendaRenumberRow[]
}

export interface AgendaRenumberScope {
  register_id?: number
}

function target(agenda: RenumberAgenda, scope: AgendaRenumberScope): { url: string, params: Record<string, string | number> } {
  switch (agenda) {
    case 'cash':
      return { url: '/accounting/cash-documents/renumber', params: { register_id: scope.register_id ?? 0 } }
    case 'other_receivable':
      return { url: '/accounting/other-items/renumber', params: { side: 'receivable' } }
    case 'other_payable':
      return { url: '/accounting/other-items/renumber', params: { side: 'payable' } }
    case 'internal':
      return { url: '/accounting/internal-documents/renumber', params: {} }
  }
}

export const renumberApi = {
  preview: (agenda: RenumberAgenda, period: string, scope: AgendaRenumberScope = {}) => {
    const { url, params } = target(agenda, scope)
    return api.get<AgendaRenumberPlan>(url, { params: { period, ...params } }).then(r => r.data)
  },

  renumber: (agenda: RenumberAgenda, period: string, scope: AgendaRenumberScope = {}) => {
    const { url, params } = target(agenda, scope)
    return api.post<AgendaRenumberPlan & { renumbered: number }>(url, { period, ...params }).then(r => r.data)
  },
}
