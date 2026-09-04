import { describe, expect, it } from 'vitest'
import type { SetupProposal } from '@/api/accountingSetupAssistant'
import {
  dependentProposalIds,
  requiredChartProposalIds,
} from '@/utils/accountingSetupDependencies'

function proposal(id: number, proposalType: SetupProposal['proposal_type'], proposalJson: Record<string, unknown>): SetupProposal {
  return {
    id,
    proposal_type: proposalType,
    title: `Návrh ${id}`,
    confidence: 0.9,
    occurrence_count: 2,
    affected_amount: 0,
    proposal_json: proposalJson,
    evidence_json: {},
    decision: 'pending',
  }
}

describe('accounting setup proposal dependencies', () => {
  const proposals = [
    proposal(1, 'chart_account', { account_code: '501.200' }),
    proposal(2, 'chart_account', { account_code: '518.100' }),
    proposal(3, 'expense_rule', { target_account_code: '501.200' }),
    proposal(4, 'posting_rule', { debit_account_code: '518.100', credit_account_code: '321' }),
    proposal(5, 'bank_rule', { debit_account_code: '221', credit_account_code: '518.100' }),
  ]

  it('finds rules referencing an analytic on target, debit or credit side', () => {
    expect(dependentProposalIds(proposals, '518.100')).toEqual([4, 5])
  })

  it('finds every proposed analytic required by a rule', () => {
    expect(requiredChartProposalIds(proposals, proposals[4])).toEqual([2])
    expect(requiredChartProposalIds(proposals, proposals[2])).toEqual([1])
  })
})
