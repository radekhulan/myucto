import type { SetupProposal } from '@/api/accountingSetupAssistant'

function referencedAccountCodes(proposal: SetupProposal): string[] {
  const payload = proposal.proposal_json
  return [...new Set([
    payload.target_account_code,
    payload.debit_account_code,
    payload.credit_account_code,
  ].map(value => String(value || '').trim()).filter(Boolean))]
}

export function dependentProposalIds(proposals: SetupProposal[], accountCode: string): number[] {
  return proposals
    .filter(proposal => proposal.proposal_type !== 'chart_account'
      && referencedAccountCodes(proposal).includes(accountCode))
    .map(proposal => proposal.id)
}

export function requiredChartProposalIds(proposals: SetupProposal[], proposal: SetupProposal): number[] {
  const referenced = new Set(referencedAccountCodes(proposal))
  return proposals
    .filter(candidate => candidate.proposal_type === 'chart_account' && candidate.proposal_json.create !== false
      && referenced.has(String(candidate.proposal_json.account_code || '').trim()))
    .map(candidate => candidate.id)
}
