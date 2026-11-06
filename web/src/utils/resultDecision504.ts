import type { ProfitDistribution504Preview, ResultDecision504Allocation, ResultDecision504Cell } from '@/api/closing'

export function resultDecisionResourceAccounts(preview: ProfitDistribution504Preview, cell: ResultDecision504Cell, role: ResultDecision504Allocation['role']): ResultDecision504Cell[] {
  const prefix = preview.target_roles.find(target => target.role === role)?.account_code
  if (!prefix || role === 'result.retained_profit') return []
  const dimensions = (value: Record<number, number>) => JSON.stringify(Object.entries(value).sort(([a], [b]) => Number(a) - Number(b)))
  return preview.resources.filter(resource => resource.account_code.startsWith(prefix) && resource.available_cents > 0
    && dimensions(resource.dimensions) === dimensions(cell.dimensions)
    && resource.cost_center === cell.cost_center && resource.project_id === cell.project_id)
}

export function resultDecisionResourceSelected(preview: ProfitDistribution504Preview, row: ResultDecision504Allocation): boolean {
  const cell = preview.cells.find(candidate => candidate.key === row.cell_key)
  if (!cell) return false
  if (cell.available_cents >= 0 || row.role === 'result.retained_profit') return true
  const resources = resultDecisionResourceAccounts(preview, cell, row.role)
  return row.account_code ? resources.some(resource => resource.account_code === row.account_code) : resources.length === 1
}
