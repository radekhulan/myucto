import { describe, expect, it } from 'vitest'
import { compactJournalDescription } from '@/utils/journalDescription'

const full = 'PF PF-2099-0008 / dod. DOD-77 — Ukázka s.r.o. (VS 770077) — Vývojářské licence'
const parts = { kind: null, partner: 'Ukázka s.r.o.', vs: '770077', text: 'Vývojářské licence' }
const visible = (...keys: string[]) => (key: string) => keys.includes(key)

describe('compactJournalDescription', () => {
  it('vynechá číslo dokladu, když je vidět sloupec Doklad nebo Zdroj', () => {
    expect(compactJournalDescription(full, parts, visible('document_no'))).toBe('Ukázka s.r.o. (VS 770077) — Vývojářské licence')
  })
  it('vynechá firmu a VS, když mají vlastní sloupec', () => {
    expect(compactJournalDescription(full, parts, visible('source', 'partner_name'))).toBe('VS 770077 — Vývojářské licence')
    expect(compactJournalDescription(full, parts, visible('source', 'partner_name', 'variable_symbol'))).toBe('Vývojářské licence')
    expect(compactJournalDescription(full, parts, visible('source', 'variable_symbol'))).toBe('Ukázka s.r.o. — Vývojářské licence')
  })
  it('bez sloupce s číslem dokladu nebo bez rozkladu ukáže celý popis', () => {
    expect(compactJournalDescription(full, parts, visible('partner_name'))).toBe(full)
    expect(compactJournalDescription(full, undefined, visible('source'))).toBe(full)
  })
  it('druh dokladu zůstane a prázdný zbytek dá prázdný text', () => {
    expect(compactJournalDescription('Dobropis FV 1 — A — vrácení', { kind: 'Dobropis FV', partner: 'A', vs: null, text: 'vrácení' }, visible('source', 'partner_name'))).toBe('Dobropis FV — vrácení')
    expect(compactJournalDescription('FV 1 — A', { kind: null, partner: 'A', vs: null, text: null }, visible('source', 'partner_name'))).toBe('')
  })
})
