import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import { bulkDimensionsPayload, bulkDimensionsSummary } from '@/components/dimensions/bulkDimensions'

const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
const t = (key: string, params?: Record<string, unknown>) => i18n.global.t(key, params ?? {})

describe('hromadné dimenze dokladů', () => {
  it('nastavovaný typ nese hodnotu, odebíraný null, ponechaný v těle chybí', () => {
    expect(bulkDimensionsPayload({
      1: { mode: 'set', valueId: 10 },
      2: { mode: 'remove', valueId: 5 },
      3: { mode: 'keep', valueId: 7 },
    })).toEqual({ 1: 10, 2: null })
  })

  it('bez změny nebo s Nastavit bez hodnoty nic neodešle', () => {
    expect(bulkDimensionsPayload({ 1: { mode: 'keep', valueId: null } })).toBeNull()
    expect(bulkDimensionsPayload({ 1: { mode: 'set', valueId: null }, 2: { mode: 'remove', valueId: null } })).toBeNull()
  })

  it('souhrn uvede počty a důvod každého přeskočeného dokladu', () => {
    const msg = bulkDimensionsSummary({
      updated: [1, 2],
      unchanged: [3],
      needs_repost: [2],
      skipped: [
        { id: 4, varsymbol: '2026004', code: 'period_not_open', message: 'Dimenze uzavřeného účetního období nelze měnit.' },
        { id: 5, varsymbol: null, code: 'not_found', message: 'Doklad nenalezen.' },
      ],
    }, t)
    expect(msg).toContain('Upraveno 2, beze změny 1, přeskočeno 2.')
    expect(msg).toContain('Přeúčtovat je potřeba 1')
    expect(msg).toContain('2026004: Dimenze uzavřeného účetního období nelze měnit.')
    expect(msg).toContain('#5: Doklad nenalezen.')
  })
})
