import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async () => {
  const { ref: vueRef } = await import('vue')
  return { useI18n: () => ({ locale: vueRef('cs'), t: (key: string) => key }) }
})

import DateInput from '@/components/ui/DateInput.vue'
import { rowKey } from '@/utils/rowKey'

/**
 * Regrese k „známé mezi" z PR #57: řádky mazatelné tabulky klíčované indexem
 * svážou instanci s POZICÍ, ne s řádkem. Po smazání řádku převezme instance
 * data souseda i svůj vnitřní stav — a rozepsaný neplatný text v DateInput
 * přes setCustomValidity zablokuje uložení formuláře v řádku, kam nepatří.
 *
 * Scénář má ZÁMĚRNĚ dva řádky se stejným datem. DateInput se totiž při změně
 * `modelValue` sám vrací k modelu, takže chybu přetáhne jen instance, které se
 * po posunu model NEZMĚNÍ — a to je právě dvojice stejných dat (u splátkového
 * kalendáře nebo výkazu víceprací běžná).
 */
const SHARED_DATE = '2026-02-02'

function harness(keyMode: 'index' | 'stable') {
  return defineComponent({
    setup() {
      const rows = ref([{ d: '2026-01-01' }, { d: SHARED_DATE }, { d: SHARED_DATE }])
      return { rows, remove: (i: number) => rows.value.splice(i, 1) }
    },
    render() {
      return h('div', this.rows.map((row, i) => h(DateInput, {
        key: keyMode === 'index' ? i : rowKey(row),
        modelValue: row.d,
        'onUpdate:modelValue': (value: string) => { row.d = value },
      })))
    },
  })
}

/** Rozepíše nesmysl do prostředního řádku a smaže řádek NAD ním. */
async function typeGarbageIntoMiddleRowAndDropFirst(component: ReturnType<typeof harness>) {
  const wrapper = mount(component)
  const fields = () => wrapper.findAll<HTMLInputElement>('input[type="text"]')

  const middle = fields()[1]
  await middle.trigger('focus')
  await middle.setValue('31.2.2026')
  await middle.trigger('blur')
  expect(middle.element.validity.customError).toBe(true)

  ;(wrapper.vm as unknown as { remove: (i: number) => void }).remove(0)
  await nextTick()
  await nextTick()
  return wrapper
}

/** Index řádku, který drží chybu blokující odeslání formuláře; -1 = žádný. */
function erroringRow(wrapper: ReturnType<typeof mount>): number {
  return wrapper.findAll<HTMLInputElement>('input[type="text"]')
    .findIndex(field => field.element.validity.customError)
}

describe('rowKey', () => {
  it('dá každému objektu vlastní stabilní klíč a nezapisuje do dat', () => {
    const a = { d: '2026-01-01' }
    const b = { d: '2026-01-01' }

    expect(rowKey(a)).toBe(rowKey(a))
    expect(rowKey(a)).not.toBe(rowKey(b))
    expect(Object.keys(a)).toEqual(['d'])
    expect(JSON.stringify(a)).toBe('{"d":"2026-01-01"}')
  })

  it('po smazání řádku zůstane chyba na řádku, do kterého se psalo', async () => {
    const wrapper = await typeGarbageIntoMiddleRowAndDropFirst(harness('stable'))

    expect(wrapper.findAll('input[type="text"]')).toHaveLength(2)
    expect(erroringRow(wrapper)).toBe(0)
  })

  /**
   * Falzifikace opravy: s `:key="i"` (stav před opravou) tentýž scénář chybu
   * přesune na SOUSEDNÍ řádek — uživatel ji vidí u data, které nikdy needitoval,
   * a jeho vlastní překlep zmizí. Kdyby tenhle test přestal padat na indexovém
   * klíči, je test výše bezcenný.
   */
  it('s indexovým klíčem chyba skočí na sousední řádek — proto ta oprava vznikla', async () => {
    const wrapper = await typeGarbageIntoMiddleRowAndDropFirst(harness('index'))

    expect(wrapper.findAll('input[type="text"]')).toHaveLength(2)
    expect(erroringRow(wrapper)).toBe(1)
  })
})
