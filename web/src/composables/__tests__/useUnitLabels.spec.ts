import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { useUnitLabels } from '../useUnitLabels'

describe('useUnitLabels', () => {
  const units = ref([
    { code: 'lekce_55', label_cs: 'Lekce (55 min)', label_en: 'Class (55 min)' },
    { code: 'ks', label_cs: 'kus', label_en: 'piece' },
    { code: 'bal', label_cs: '', label_en: '  ' },
  ])

  it('zobrazí popisek v jazyce UI', () => {
    expect(useUnitLabels(units, 'cs').unitLabel('lekce_55')).toBe('Lekce (55 min)')
    expect(useUnitLabels(units, 'en').unitLabel('lekce_55')).toBe('Class (55 min)')
  })

  it('reaguje na přepnutí jazyka', () => {
    const locale = ref('cs')
    const { unitLabel } = useUnitLabels(units, locale)
    expect(unitLabel('ks')).toBe('kus')
    locale.value = 'en'
    expect(unitLabel('ks')).toBe('piece')
  })

  it('páruje kód bez ohledu na velikost písmen a mezery', () => {
    expect(useUnitLabels(units, 'cs').unitLabel(' LEKCE_55 ')).toBe('Lekce (55 min)')
  })

  it('jednotka mimo číselník nebo bez popisku zůstane kódem', () => {
    const { unitLabel } = useUnitLabels(units, 'cs')
    expect(unitLabel('KT')).toBe('KT')
    expect(unitLabel('bal')).toBe('bal')
    expect(useUnitLabels(units, 'en').unitLabel('bal')).toBe('bal')
    expect(unitLabel(null)).toBe('')
  })
})
