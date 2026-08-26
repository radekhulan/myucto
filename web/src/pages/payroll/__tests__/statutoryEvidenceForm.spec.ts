import { describe, expect, it } from 'vitest'
import {
  defaultRow,
  STATUTORY_SECTIONS,
} from '@/pages/payroll/statutoryEvidenceForm'

describe('statutoryEvidenceForm defaults', () => {
  it('does not claim that a new employee signed the tax declaration', () => {
    const section = STATUTORY_SECTIONS.find(item => item.key === 'tax_declarations')!
    const row = defaultRow(section, '2026-08-01', {
      effectiveOn: '2026-08-01',
      defaultInsurerCode: '111',
      employerReferences: [],
    })

    expect(row.status).toBe('not-signed')
    expect(row.evidence_reference).toBeNull()
  })
})
