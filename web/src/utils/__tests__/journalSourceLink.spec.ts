import { describe, expect, it } from 'vitest'
import { journalSourceLink } from '@/utils/journalSourceLink'

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
