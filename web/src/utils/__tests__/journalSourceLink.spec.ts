import { describe, expect, it } from 'vitest'
import { journalSourceLink, journalSourceRefLabel } from '@/utils/journalSourceLink'

describe('journalSourceLink', () => {
  it('opens the exact issued charge, including a reversal source', () => {
    expect(journalSourceLink({ source_type: 'unit_charge', source_id: 91 })).toEqual({ name: 'property-charge-issues', query: { charge_id: '91' } })
    expect(journalSourceLink({ source_type: 'unit_charge', source_link_id: 92, source_id: null })).toEqual({ name: 'property-charge-issues', query: { charge_id: '92' } })
    expect(journalSourceLink({ source_type: 'unit_charge', source_id: null })).toBeNull()
  })
  it('otevře konkrétní pokladní pohyb i bez čísla dokladu', () => {
    expect(journalSourceLink({ source_type: 'cash', source_id: 91 })).toEqual({
      name: 'accounting-cash', query: { document_id: '91' },
    })
    expect(journalSourceLink({ source_type: 'cash', source_link_id: 92, source_id: null })).toEqual({
      name: 'accounting-cash', query: { document_id: '92' },
    })
  })
  it('otevře ostatní pohledávku i závazek podle ID zdrojové položky', () => {
    expect(journalSourceLink({ source_type: 'other_item', source_id: 42 })).toEqual({
      name: 'other-item-detail', params: { id: 42 },
    })
    expect(journalSourceLink({ source_type: 'other_item', source_id: null, source_link_id: 43 })).toEqual({
      name: 'other-item-detail', params: { id: 43 },
    })
    expect(journalSourceLink({ source_type: 'other_item', source_id: null })).toBeNull()
  })
})

describe('journalSourceRefLabel', () => {
  it('storno zápis bez source_id nese ID zdroje z link_id, nikdy „#null“', () => {
    expect(journalSourceRefLabel({ source_type: 'invoice', source_id: null, source_link_id: 12485 })).toBe('#12485')
    expect(journalSourceRefLabel({ source_type: 'invoice', source_id: null })).toBe('')
    expect(journalSourceRefLabel({ source_type: 'asset', source_id: 5, source_asset_name: 'Stroj' })).toBe('Stroj')
  })
  it('ukáže číslo dokladu místo interního ID', () => {
    expect(journalSourceRefLabel({ source_type: 'purchase_invoice', source_id: 59312, source_ref: 'NU2610155' })).toBe('NU2610155')
    expect(journalSourceRefLabel({ source_type: 'invoice', source_id: null, source_link_id: 12485, source_ref: '202603162' })).toBe('202603162')
    expect(journalSourceRefLabel({ source_type: 'bank', source_id: 7, source_ref: null })).toBe('#7')
  })
})
