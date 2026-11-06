import { describe, expect, it } from 'vitest'
import {
  crownsToMinorUnits,
  currentRow,
  defaultRow,
  evidenceDetailFields,
  minorUnitsToCrowns,
  primaryFields,
  rowIssues,
  STATUTORY_SECTIONS,
} from '@/pages/payroll/statutoryEvidenceForm'

describe('statutoryEvidenceForm money fields', () => {
  it('převede koruny na haléře bez chyb plovoucí čárky', () => {
    expect(crownsToMinorUnits('15000')).toBe('1500000')
    expect(crownsToMinorUnits('15 000,50')).toBe('1500050')
    expect(crownsToMinorUnits('180.98')).toBe('18098')
    expect(crownsToMinorUnits('0,1')).toBe('10')
    expect(crownsToMinorUnits('')).toBeNull()
    expect(crownsToMinorUnits('-5')).toBeNull()
    expect(crownsToMinorUnits('1,234,5')).toBeNull()
    expect(crownsToMinorUnits('12.345')).toBeNull()
  })

  it('haléře ukáže v korunách', () => {
    expect(minorUnitsToCrowns('1500000')).toBe('15000')
    expect(minorUnitsToCrowns('1500050')).toBe('15000.50')
    expect(minorUnitsToCrowns('')).toBe('')
  })
})

describe('statutoryEvidenceForm health minimum exceptions', () => {
  const reductions = STATUTORY_SECTIONS.find(item => item.key === 'health_minimum_reductions')!
  const context = { effectiveOn: '2026-08-31', defaultInsurerCode: null, employerReferences: [] }

  it('výjimka z minima smí začít i skončit uprostřed měsíce', () => {
    const issues = rowIssues(reductions, {
      reason: 'ztp_or_ztp_p',
      effective_from: '2026-08-10',
      effective_to: '2026-09-15',
    }, context)

    expect(issues).toEqual([])
  })

  it('konec před začátkem hlídá dál', () => {
    const issues = rowIssues(reductions, {
      reason: 'state_insured',
      effective_from: '2026-08-10',
      effective_to: '2026-08-01',
    }, context)

    expect(issues.map(issue => issue.key)).toEqual(['effective_to_before_from'])
  })
})

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

describe('statutoryEvidenceForm current state', () => {
  const declarations = STATUTORY_SECTIONS.find(item => item.key === 'tax_declarations')!
  const monthly = STATUTORY_SECTIONS.find(item => item.key === 'health_month_evidence')!

  const rows = [
    { id: 1, status: 'not-signed', effective_from: '2026-01-01', effective_to: '2026-05-31' },
    { id: 2, status: 'signed', effective_from: '2026-06-01', effective_to: null },
  ]

  it('vybere verzi platnou k danému dni, ne poslední ani první v poli', () => {
    expect(currentRow(declarations, rows, '2026-03-15')?.id).toBe(1)
    expect(currentRow(declarations, rows, '2026-08-31')?.id).toBe(2)
    expect(currentRow(declarations, rows, '2025-12-31')).toBeNull()
  })

  it('měsíční evidenci páruje přes měsíc, ne přes celé datum', () => {
    const months = [{ id: 9, period_start: '2026-07-01', top_up_responsibility: 'employee' }]

    expect(currentRow(monthly, months, '2026-07-31')?.id).toBe(9)
    expect(currentRow(monthly, months, '2026-08-31')).toBeNull()
  })

  it('doklad a poznámka nejsou věcná pole — patří do sbaleného doplňku', () => {
    const row = { status: 'signed' }

    expect(primaryFields(declarations, row).map(field => field.key)).toEqual(['status', 'signed_on'])
    expect(primaryFields(declarations, { status: 'not-signed' }).map(field => field.key)).toEqual(['status'])
    expect(evidenceDetailFields(declarations, row).map(field => field.key))
      .toEqual(['evidence_reference'])
  })
})
