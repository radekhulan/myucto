import { api } from './client'
import { pageParams, type PayrollPageParams } from './payroll'

export type PayrollPaymentLiabilityState =
  | 'open'
  | 'partially_batched'
  | 'batched'
  | 'partially_settled'
  | 'settled'

export interface PayrollPaymentLiability {
  id: number
  run_id: number
  revision_id: number
  revision_no: number
  employee_id: number | null
  employee_name: string | null
  recipient_name: string | null
  institution_type: string | null
  institution_code: string | null
  liability_kind: string
  direction: 'outgoing' | 'incoming'
  recipient_kind: 'bank' | 'cash'
  payment_target_status: 'ready'
  payment_target_masked: string | null
  batch_eligibility: 'ready' | 'blocked'
  batch_block_reason:
    | 'unsupported_direction'
    | 'unsupported_liability_kind'
    | null
  revision_kind: 'regular' | 'correction'
  due_on: string
  currency_code: string
  amount_minor: number
  allocated_minor: number
  settled_minor: number
  state: PayrollPaymentLiabilityState
  created_at: string
}

export interface PayrollPaymentLiabilityList {
  period: string
  items: PayrollPaymentLiability[]
  total: number
  /**
   * Součty za CELÉ období, ne za stránku. Znaménko už nese server (příchozí
   * závazek částku odečítá), takže se nedopočítávají z `items`.
   */
  totals: {
    amount_minor: number
    allocated_minor: number
    settled_minor: number
  }
  limit: number
  offset: number
}

export interface PayrollPaymentPreparationIssue {
  liability_kind: string
  reason: 'blocked'
  message: string
}

export interface PayrollPaymentPreparationResult {
  liability_ids: number[]
  created_count: number
  preparation_issues: PayrollPaymentPreparationIssue[]
}

export interface PayrollPayerOption {
  reference: string
  currency_id: number
  currency_code: string
  bank_name: string | null
  masked_account: string
  export_formats: Array<'abo' | 'sepa'>
}

export interface PayrollPaymentExport {
  id: number
  revision_no: number
  file_sha256: string
  size_bytes: number
  mime_type: string
  suggested_filename: string
  created_at: string
}

export interface PayrollPaymentBatch {
  id: number
  batch_reference: string
  channel: 'bank' | 'cash'
  export_format: 'abo' | 'sepa' | 'manual'
  /** Datum PŘÍKAZU — u odvodů dřív než zákonný termín, o rezervu na převod. */
  planned_payment_date: string
  /**
   * Zákonný termín ze splatnosti závazků v dávce. `null` u dávek založených
   * dřív, než se termín začal odvozovat — tehdy se o rozdílu netvrdí nic.
   */
  statutory_due_on: string | null
  /** Liší se datum příkazu od zákonného termínu? */
  is_shifted: boolean
  currency_code: string
  declared_total_minor: number
  declared_item_count: number
  settled_minor: number
  created_at: string
  exports: PayrollPaymentExport[]
}

export interface PayrollPaymentBatchList {
  period: string
  items: PayrollPaymentBatch[]
}

export interface PayrollPaymentBatchResult {
  batch_id: number
  batch_reference: string
  channel: 'bank' | 'cash'
  export_format: 'abo' | 'sepa' | 'manual'
  planned_payment_date: string
  currency_code: string
  declared_total_minor: number
  declared_item_count: number
  snapshot_hash: string
  created: boolean
  replayed: boolean
}

export interface PayrollPaymentExportResult {
  export_id: number
  batch_id: number
  export_format: 'abo' | 'sepa'
  export_revision_no: number
  source_snapshot_hash: string
  file_sha256: string
  size_bytes: number
  mime_type: string
  suggested_filename: string
  created: boolean
  replayed: boolean
}

export interface PayrollPaymentAllocation {
  id: number
  item_id: number
  item_reference: string
  batch_id: number
  batch_reference: string
  channel: 'bank' | 'cash'
  planned_payment_date: string
  liability_id: number
  liability_kind: string
  direction: 'outgoing' | 'incoming'
  currency_code: string
  employee_name: string | null
  amount_minor: number
  settled_minor: number
  remaining_minor: number
}

export interface PayrollIncomingRefundLiability {
  id: number
  liability_reference: string
  liability_kind: string
  direction: 'incoming'
  due_on: string
  currency_code: string
  employee_name: string | null
  amount_minor: number
  settled_minor: number
  remaining_minor: number
}

export interface PayrollPaymentEvidence {
  kind: 'bank' | 'cash'
  bank_statement_id: number | null
  bank_transaction_id: number | null
  cash_document_id: number | null
  date: string
  amount_minor: number
  currency_code: string
  direction: 'outgoing' | 'incoming'
  description: string | null
  status?: 'posted' | 'reversed'
  reference?: string | null
  available_match_minor: number
  available_reversal_minor: number
}

export interface PayrollPaymentMatch {
  id: number
  allocation_id: number | null
  liability_id: number
  event_kind: 'matched' | 'reversed'
  source_match_id: number | null
  amount_minor: number
  evidence_kind: 'bank' | 'cash'
  bank_statement_id: number | null
  bank_transaction_id: number | null
  cash_document_id: number | null
  actual_payment_date: string
  evidence_amount_minor: number
  evidence_currency_code: string
  evidence_fact_hash: string
  batch_reference: string | null
  liability_kind: string
  /**
   * Směr a měna PŘÍSLUŠNÉ ALOKACE jedou s událostí. Nabídka alokací je od
   * zavedení stropu jen výsek, takže dohledávat je v ní by u alokace mimo
   * výsek tiše znemožnilo storno.
   */
  allocation_direction: 'outgoing' | 'incoming'
  allocation_currency_code: string
  employee_name: string | null
  reversible_minor: number
  created_at: string
}

export interface PayrollPaymentReconciliation {
  period: string
  /**
   * Nabídka pro picker, OŘEZANÁ na `offered_limit`. Krátký seznam přijde celý
   * a picker funguje bez jediného dalšího volání; delší se dohledává přes
   * `searchOptions` a `*_truncated` říká, že poslané není všechno.
   */
  allocations: PayrollPaymentAllocation[]
  allocations_truncated: boolean
  incoming_liabilities: PayrollIncomingRefundLiability[]
  incoming_liabilities_truncated: boolean
  offered_limit: number
  matches: PayrollPaymentMatch[]
  matches_total: number
  matches_limit: number
  matches_offset: number
  /**
   * Vratné události pro výběr storna. Nejde o stránku historie — kdyby se
   * nabídka brala z ní, zmizely by z výběru události ležící na jiné straně.
   */
  reversible_matches: PayrollPaymentMatch[]
  bank_evidence: PayrollPaymentEvidence[]
  bank_evidence_truncated: boolean
  cash_evidence: PayrollPaymentEvidence[]
  cash_evidence_truncated: boolean
}

export type PayrollPaymentOptionKind =
  | 'allocations'
  | 'incoming_liabilities'
  | 'bank_evidence'
  | 'cash_evidence'

export interface PayrollPaymentOptionSearch {
  kind: PayrollPaymentOptionKind
  /** Nejlepší shody, nejvýš `limit` kusů. */
  items: Array<
    | PayrollPaymentAllocation
    | PayrollIncomingRefundLiability
    | PayrollPaymentEvidence
  >
  /** Shod je víc, než kolik se vešlo — nabídka NENÍ úplná. */
  truncated: boolean
  limit: number
}

export interface PayrollPaymentReconciliationEventResult {
  id: number
  allocation_id: number | null
  liability_id?: number
  event_kind: 'matched' | 'reversed'
  source_match_id: number | null
  amount_minor: number
  evidence_kind: 'bank' | 'cash'
  bank_statement_id: number | null
  bank_transaction_id: number | null
  cash_document_id: number | null
  actual_payment_date: string
  evidence_amount_minor: number
  evidence_currency_code: string
  evidence_fact_hash: string
  replayed: boolean
}

export const payrollPaymentsApi = {
  liabilities: (period: string, page?: PayrollPageParams) =>
    api.get<PayrollPaymentLiabilityList>('/payroll/payments/liabilities', {
      params: { period, ...pageParams(page) },
    }).then(response => response.data),
  materializeNetWages: (revisionId: number) =>
    api.post<{ liability_ids: number[]; created_count: number }>(
      `/payroll/revisions/${revisionId}/payments/net-wage-liabilities`,
    ).then(response => response.data),
  materializeLiabilities: (revisionId: number) =>
    api.post<PayrollPaymentPreparationResult>(
      `/payroll/revisions/${revisionId}/payments/liabilities`,
    ).then(response => response.data),
  payerOptions: () =>
    api.get<{ items: PayrollPayerOption[] }>('/payroll/payments/payer-options')
      .then(response => response.data.items),
  batches: (period: string) =>
    api.get<PayrollPaymentBatchList>('/payroll/payments/batches', {
      params: { period },
    }).then(response => response.data),
  createBatch: (payload: {
    export_format: 'abo' | 'sepa' | 'manual'
    payer_reference: string
    items: Array<{ liability_id: number; amount_minor: number }>
  }) =>
    api.post<PayrollPaymentBatchResult>(
      '/payroll/payments/batches',
      payload,
    ).then(response => response.data),
  reconciliation: (period: string, page?: PayrollPageParams) =>
    api.get<PayrollPaymentReconciliation>(
      '/payroll/payments/reconciliation',
      { params: { period, ...pageParams(page) } },
    ).then(response => response.data),
  /**
   * Serverové hledání v nabídce pickeru.
   *
   * Why: nabídky nešlo stránkovat — z pickeru by se stalo „vybrat jde jen to,
   * co je na první straně". Zúžení podle měny, směru a použitelnosti jde na
   * server spolu s dotazem, aby ze serverem vybraných shod nezbylo po
   * klientském filtru prázdno.
   */
  searchOptions: (params: {
    period: string
    kind: PayrollPaymentOptionKind
    q?: string
    currency?: string
    direction?: 'outgoing' | 'incoming'
    usage?: 'match' | 'reversal'
    cash_document_id?: number
  }) =>
    api.get<PayrollPaymentOptionSearch>(
      '/payroll/payments/reconciliation/options',
      { params },
    ).then(response => response.data),
  match: (payload: {
    allocation_id: number
    amount_minor: number
    evidence: {
      kind: 'bank' | 'cash'
      bank_statement_id?: number
      bank_transaction_id?: number
      cash_document_id?: number
    }
    idempotency_key: string
  }) =>
    api.post<{ event: PayrollPaymentReconciliationEventResult }>(
      '/payroll/payments/reconciliation/matches',
      payload,
    ).then(response => response.data.event),
  reverse: (payload: {
    source_match_id: number
    amount_minor: number
    evidence: {
      kind: 'bank' | 'cash'
      bank_statement_id?: number
      bank_transaction_id?: number
      cash_document_id?: number
    }
    idempotency_key: string
  }) =>
    api.post<{ event: PayrollPaymentReconciliationEventResult }>(
      '/payroll/payments/reconciliation/reversals',
      payload,
    ).then(response => response.data.event),
  matchIncomingRefund: (payload: {
    liability_id: number
    amount_minor: number
    evidence: {
      kind: 'bank' | 'cash'
      bank_statement_id?: number
      bank_transaction_id?: number
      cash_document_id?: number
    }
    idempotency_key: string
  }) =>
    api.post<{ event: PayrollPaymentReconciliationEventResult }>(
      '/payroll/payments/reconciliation/incoming-refunds',
      payload,
    ).then(response => response.data.event),
  reverseIncomingRefund: (payload: {
    source_match_id: number
    amount_minor: number
    evidence: {
      kind: 'bank' | 'cash'
      bank_statement_id?: number
      bank_transaction_id?: number
      cash_document_id?: number
    }
    idempotency_key: string
  }) =>
    api.post<{ event: PayrollPaymentReconciliationEventResult }>(
      '/payroll/payments/reconciliation/incoming-refund-reversals',
      payload,
    ).then(response => response.data.event),
  generateExport: (batchId: number, idempotencyKey: string) =>
    api.post<PayrollPaymentExportResult>(
      `/payroll/payments/batches/${batchId}/exports`,
      { idempotency_key: idempotencyKey },
    ).then(response => response.data),
  createDownloadGrant: (exportId: number) =>
    api.post<{
      grant_id: number
      export_id: number
      token: string
      expires_at: string
    }>(
      `/payroll/payments/exports/${exportId}/download-grants`,
      { ttl_seconds: 120 },
    ).then(response => response.data),
  downloadExport: (token: string) =>
    api.post<Blob>(
      '/payroll/payments/exports/download',
      { token },
      { responseType: 'blob' },
    ).then(response => response.data),
}
