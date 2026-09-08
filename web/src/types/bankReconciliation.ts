export interface BankReconciliationCandidate {
  confirmation_key: string
  posted_at: string
  amount: string
  currency: string
  existing_transaction_id: number
  existing_statement_id: number
  description: string
  existing_description: string
  counterparty_account: string
  existing_counterparty_account: string
  variable_symbol: string
  existing_variable_symbol: string
}
