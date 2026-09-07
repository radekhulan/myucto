import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

// Locale musí být reaktivní ref — komponenta na jeho změnu přeformátuje text.
const i18n = vi.hoisted(() => ({
  locale: { value: 'cs' } as { value: string },
}))

vi.mock('vue-i18n', async () => {
  const { ref } = await import('vue')
  i18n.locale = ref('cs')
  return {
    useI18n: () => ({
      locale: i18n.locale,
      t: (key: string) => key,
    }),
  }
})

import DateInput from '@/components/ui/DateInput.vue'

afterEach(() => {
  i18n.locale.value = 'cs'
  document.body.innerHTML = ''
})

const textField = (wrapper: ReturnType<typeof mount>) => wrapper.get<HTMLInputElement>('input[type="text"]')
const nativeField = (wrapper: ReturnType<typeof mount>) => wrapper.get<HTMLInputElement>('input[type="date"]')

describe('DateInput', () => {
  it('zobrazí ISO hodnotu ve formátu jazyka aplikace, ne prohlížeče', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    expect(textField(wrapper).element.value).toBe('01. 09. 2026')

    i18n.locale.value = 'en'
    await nextTick()
    expect(textField(wrapper).element.value).toBe('09/01/2026')
  })

  it('český zápis emituje ISO už během psaní a na blur se kanonizuje', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' } })
    const input = textField(wrapper)

    await input.trigger('focus')
    await input.setValue('1.9.2026')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-01']])
    expect(input.element.value).toBe('1.9.2026')

    await wrapper.setProps({ modelValue: '2026-09-01' })
    // Model odpovídá tomu, co je napsané → nepřeformátovat pod kurzorem.
    expect(input.element.value).toBe('1.9.2026')
    await input.trigger('blur')
    expect(input.element.value).toBe('01. 09. 2026')
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })

  it('anglický zápis čte měsíc před dnem', async () => {
    i18n.locale.value = 'en'
    const wrapper = mount(DateInput, { props: { modelValue: '' } })
    await textField(wrapper).setValue('9/1/2026')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-01']])
  })

  it('dvouciferný rok se během psaní nepotvrdí („1.9.20" není rok 2020), doplní se až na blur', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' } })
    const input = textField(wrapper)

    await input.trigger('focus')
    await input.setValue('1.9.20')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    // Rozepsaný zápis se při psaní nečervená, jen drží formulář neodeslatelný.
    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.element.validity.customError).toBe(true)

    await input.setValue('1.9.2026')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-01']])

    // Kdo skončí u „1.9.26" a odejde, dostane 2026 — zkratka platí, jen ne uprostřed psaní.
    await input.setValue('1.9.26')
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    await input.trigger('blur')
    // Bez rodiče se prop nezmění, takže blur emituje znovu — podstatné je, že rok je 2026.
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-01'], ['2026-09-01']])
    expect(input.element.value).toBe('01. 09. 2026')
    expect(input.element.validity.customError).toBe(false)
  })

  it('neplatné datum nic neuloží, po opuštění pole ho označí a shodí nativní validitu formuláře', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    const input = textField(wrapper)

    await input.trigger('focus')
    await input.setValue('31.2.2026')
    expect(input.attributes('aria-invalid')).toBeUndefined()
    await input.trigger('blur')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.classes()).toContain('border-danger-500')
    expect(input.element.validity.customError).toBe(true)
    expect(input.element.validationMessage).toBe('common.date_input.invalid')
    // Rozepsaný text zůstane, aby ho uživatel mohl opravit — nepřepíše se starou hodnotou.
    expect(input.element.value).toBe('31.2.2026')
  })

  it('vymazání pole emituje prázdný řetězec (stejně jako nativní input)', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    await textField(wrapper).setValue('')
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
  })

  it('změna zvenčí přepíše text, ale ne uživateli pod rukama', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    const input = textField(wrapper)

    await wrapper.setProps({ modelValue: '2026-10-15' })
    expect(input.element.value).toBe('15. 10. 2026')

    await input.trigger('focus')
    await input.setValue('1.1.')
    await wrapper.setProps({ modelValue: '2026-12-24' })
    expect(input.element.value).toBe('1.1.')
  })

  it('přepočet zvenčí se ukáže i v poli, které má fokus, ale nic rozepsaného', async () => {
    // Scénář z editoru: fokus je ve Splatnosti, uživatel kalendářem změní Vystaveno,
    // watcher přepočte splatnost — pole ji musí ukázat, jinak by ji blur přepsal.
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-13' } })
    const input = textField(wrapper)

    await input.trigger('focus')
    await wrapper.setProps({ modelValue: '2026-11-13' })
    expect(input.element.value).toBe('13. 11. 2026')

    await input.trigger('blur')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(input.element.value).toBe('13. 11. 2026')
  })

  it('změna zvenčí uvolní i blokaci validity po neplatném zápisu', async () => {
    // Uživatel nechá ve Splatnosti „31.2.2026", pak vybere jiného klienta a editor
    // splatnost přepočte. Pole musí ukázat nové datum A přestat blokovat formulář.
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    const input = textField(wrapper)

    await input.trigger('focus')
    await input.setValue('31.2.2026')
    await input.trigger('blur')
    expect(input.element.validity.customError).toBe(true)

    await wrapper.setProps({ modelValue: '2026-03-01' })
    await nextTick()
    expect(input.element.value).toBe('01. 03. 2026')
    expect(input.element.validity.customError).toBe(false)
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })

  it('nativní kalendář zapíše vybrané datum do textu i modelu a vrátí fokus textu', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' }, attachTo: document.body })
    const native = nativeField(wrapper)
    native.element.focus()
    native.element.value = '2026-03-08'
    await native.trigger('change')

    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-03-08']])
    expect(textField(wrapper).element.value).toBe('08. 03. 2026')
    // Safari zavře popover kalendáře až ztrátou fokusu nativního inputu.
    expect(document.activeElement).toBe(textField(wrapper).element)
    expect(native.attributes('tabindex')).toBe('-1')
    expect(native.attributes('aria-hidden')).toBeUndefined()
    expect(native.attributes('aria-label')).toBe('common.date_input.open_calendar')
    wrapper.unmount()
  })

  it('„Vymazat" v nativním kalendáři vyprázdní pole i model (kontrakt 1:1)', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
    const native = nativeField(wrapper)
    native.element.value = ''
    await native.trigger('change')

    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    expect(textField(wrapper).element.value).toBe('')
  })

  it('tlačítko kalendáře nejdřív fokusuje nativní pole a pak volá showPicker', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' }, attachTo: document.body })
    const native = nativeField(wrapper).element as HTMLInputElement & { showPicker?: () => void }
    const calls: string[] = []
    native.showPicker = vi.fn(() => {
      calls.push(document.activeElement === native ? 'showPicker-with-focus' : 'showPicker-without-focus')
    })

    await wrapper.get('button').trigger('click')
    expect(calls).toEqual(['showPicker-with-focus'])
    expect(wrapper.get('button').attributes('aria-label')).toBe('common.date_input.open_calendar')
    wrapper.unmount()
  })

  it('psaní do neviditelného nativního pole (po zavření kalendáře bez výběru) přesměruje do textu i se znakem', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' }, attachTo: document.body })
    const native = nativeField(wrapper)
    native.element.focus()

    await native.trigger('keydown', { key: '1' })
    expect(document.activeElement).toBe(textField(wrapper).element)
    expect(textField(wrapper).element.value).toBe('1')

    // Tab se nepřesměrovává — má kam jít.
    native.element.focus()
    const tab = new KeyboardEvent('keydown', { key: 'Tab', cancelable: true })
    native.element.dispatchEvent(tab)
    expect(tab.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it('předá required, třídy a data-atributy textovému poli; w-full roztáhne i obal', () => {
    const wrapper = mount(DateInput, {
      props: { modelValue: '', required: true },
      attrs: { class: 'w-full h-10 px-3 border', 'data-row-input': 'inv-wr', name: 'issue_date', title: 'Od' },
    })
    const input = textField(wrapper)
    expect(input.attributes('required')).toBeDefined()
    expect(input.attributes('data-row-input')).toBe('inv-wr')
    expect(input.attributes('name')).toBe('issue_date')
    expect(input.attributes('title')).toBe('Od')
    expect(input.classes()).toEqual(expect.arrayContaining(['w-full', 'h-10', 'px-3', 'border']))
    expect(wrapper.element.className).toContain('w-full')
  })

  it('disabled zamkne text, kalendář i tlačítko', () => {
    const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01', disabled: true } })
    expect(textField(wrapper).attributes('disabled')).toBeDefined()
    expect(nativeField(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })

  /**
   * Hodnotu mimo rozsah nativní pole PŘIJME a jen ji označí za neplatnou
   * (rangeUnderflow). Stránky, které si na rozsah hlídají vlastní hlášku
   * („konec platnosti před začátkem"), ji musí dostat do modelu, jinak nemají
   * co vyhodnotit — proto se emituje i sem, jen s blokujícím customValidity.
   */
  it('min/max: zápis mimo rozsah se do modelu dostane, ale blokuje odeslání', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '', min: '2026-01-01', max: '2026-12-31' } })
    const input = textField(wrapper)

    await input.setValue('1.1.2025')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2025-01-01']])
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.element.validationMessage).toBe('common.date_input.out_of_range_between')

    await input.setValue('1.6.2026')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2025-01-01'], ['2026-06-01']])
    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.element.validity.customError).toBe(false)

    await wrapper.setProps({ min: undefined })
    await input.setValue('1.1.2027')
    expect(input.element.validationMessage).toBe('common.date_input.out_of_range_max')
  })
})

describe('DateInput ve formuláři', () => {
  it('neplatný zápis zablokuje odeslání formuláře, oprava ho zase pustí', async () => {
    const wrapper = mount({
      components: { DateInput },
      template: '<form @submit.prevent><DateInput v-model="value" required /><button type="submit">Uložit</button></form>',
      data: () => ({ value: '2026-09-01' }),
    }, { attachTo: document.body })
    const form = wrapper.get('form').element as HTMLFormElement
    const input = textField(wrapper)

    await input.trigger('focus')
    await input.setValue('31.2.2026')
    await input.trigger('blur')
    expect(form.checkValidity()).toBe(false)
    // Model drží poslední platnou hodnotu — nesmí se tedy uložit s ní, když pole říká něco jiného.
    expect((wrapper.vm as unknown as { value: string }).value).toBe('2026-09-01')

    await input.trigger('focus')
    await input.setValue('28.2.2026')
    await input.trigger('blur')
    expect(form.checkValidity()).toBe(true)
    expect((wrapper.vm as unknown as { value: string }).value).toBe('2026-02-28')

    await input.setValue('')
    expect(form.checkValidity()).toBe(false) // required
    wrapper.unmount()
  })

  it('bez showPicker spadne na focus+click nativního pole', async () => {
    const wrapper = mount(DateInput, { props: { modelValue: '' } })
    const native = nativeField(wrapper).element
    // jsdom showPicker nemá; explicitně ho odstraníme, ať test nezávisí na verzi jsdom.
    Object.defineProperty(native, 'showPicker', { value: undefined, configurable: true })
    const focus = vi.spyOn(native, 'focus')
    const click = vi.spyOn(native, 'click')

    await wrapper.get('button').trigger('click')
    expect(focus).toHaveBeenCalledTimes(1)
    expect(click).toHaveBeenCalledTimes(1)
  })

  it('kopie, která přestane být vykreslená (responzivní duplikát), zahodí rozepsanou chybu a přestane blokovat formulář', async () => {
    type RoCallback = (entries: Array<{ contentRect: { width: number; height: number } }>) => void
    let callback: RoCallback | null = null
    const observe = vi.fn()
    const disconnect = vi.fn()
    vi.stubGlobal('ResizeObserver', class {
      constructor(cb: RoCallback) { callback = cb }
      observe = observe
      disconnect = disconnect
    })
    try {
      const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
      const input = textField(wrapper)
      expect(observe).toHaveBeenCalledWith(input.element)

      await input.trigger('focus')
      await input.setValue('31.2.2026')
      await input.trigger('blur')
      expect(input.element.validity.customError).toBe(true)

      callback!([{ contentRect: { width: 0, height: 0 } }])
      await nextTick()
      await nextTick()
      expect(input.element.value).toBe('01. 09. 2026')
      expect(input.element.validity.customError).toBe(false)

      wrapper.unmount()
      expect(disconnect).toHaveBeenCalledTimes(1)
    } finally {
      vi.unstubAllGlobals()
    }
  })
  /**
   * Filtry sestav visí na `@change="load"`. Nativní pole hlásí změnu na blur
   * a po výběru z kalendáře; kdyby to komponenta dělala po znacích, střílela
   * by dotaz na server po každé číslici.
   */
  describe('událost change (sémantika nativního pole)', () => {
    it('nehlásí změnu během psaní, ale až na blur', async () => {
      const wrapper = mount(DateInput, { props: { modelValue: '' } })
      const input = textField(wrapper)

      await input.trigger('focus')
      await input.setValue('1.9.2026')
      expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-01']])
      expect(wrapper.emitted('change')).toBeUndefined()

      await wrapper.setProps({ modelValue: '2026-09-01' })
      await input.trigger('blur')
      expect(wrapper.emitted('change')).toEqual([['2026-09-01']])
    })

    it('nehlásí změnu, když uživatel hodnotu nezměnil', async () => {
      const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
      const input = textField(wrapper)

      await input.trigger('focus')
      await input.trigger('blur')
      expect(wrapper.emitted('change')).toBeUndefined()
    })

    it('rozepsaný nesmysl hodnotu nepotvrzuje, takže změnu nehlásí', async () => {
      const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
      const input = textField(wrapper)

      await input.trigger('focus')
      await input.setValue('31.2.2026')
      await input.trigger('blur')
      expect(wrapper.emitted('change')).toBeUndefined()
      expect(input.element.validity.customError).toBe(true)
    })

    it('výběr z kalendáře hlásí změnu hned', async () => {
      const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
      const native = nativeField(wrapper)

      native.element.value = '2026-09-15'
      await native.trigger('change')
      expect(wrapper.emitted('update:modelValue')).toEqual([['2026-09-15']])
      expect(wrapper.emitted('change')).toEqual([['2026-09-15']])
    })

    it('vymazání v kalendáři hlásí prázdnou hodnotu', async () => {
      const wrapper = mount(DateInput, { props: { modelValue: '2026-09-01' } })
      const native = nativeField(wrapper)

      native.element.value = ''
      await native.trigger('change')
      expect(wrapper.emitted('change')).toEqual([['']])
    })
  })
})
