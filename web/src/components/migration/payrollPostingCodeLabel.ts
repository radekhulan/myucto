export function missingAccountingKind(code: string): 'internal' | 'commitment' | null {
  switch (code.toUpperCase()) {
    case '@EMPTY_INTERNAL': return 'internal'
    case '@EMPTY_COMMITMENT': return 'commitment'
    default: return null
  }
}

export function payrollPostingCodeLabel(code: string, t: (key: string) => string): string {
  const kind = missingAccountingKind(code)
  return kind === null ? code : t(`payroll_posting_import.code_map.missing_${kind}`)
}
