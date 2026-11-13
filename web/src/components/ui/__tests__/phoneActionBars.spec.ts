import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { i18n } from '@/i18n'

const phone = ref(false)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phone, installPhoneViewport: () => {} }))

const { default: ActionBar } = await import('@/components/ui/ActionBar.vue')
const { default: BulkActionBar } = await import('@/components/ui/BulkActionBar.vue')

afterEach(() => {
  document.body.innerHTML = ''
  phone.value = false
})

const actions = [
  { key: 'remind', label: 'Odeslat upomínku', tier: 'primary' as const, run: () => {} },
  { key: 'paid', label: 'Označit zaplaceno', tier: 'primary' as const, variant: 'success' as const, run: () => {} },
  { key: 'pdf', label: 'PDF', tier: 'secondary' as const, run: () => {} },
  { key: 'delete', label: 'Smazat', tier: 'overflow' as const, variant: 'danger' as const, run: () => {} },
]

function inlineLabels(wrapper: ReturnType<typeof mount>): string[] {
  return wrapper.findAll('button, a').map(b => b.text()).filter(text => text !== '')
}

describe('ActionBar na telefonu', () => {
  it('mimo telefon nechá obě hlavní akce inline jako dosud', () => {
    const wrapper = mount(ActionBar, { props: { actions }, global: { plugins: [i18n] } })
    expect(inlineLabels(wrapper)).toEqual(expect.arrayContaining(['Odeslat upomínku', 'Označit zaplaceno']))
  })

  it('na telefonu nechá inline jen první hlavní akci a ostatní dá do nabídky zespodu', async () => {
    phone.value = true
    const wrapper = mount(ActionBar, { props: { actions }, global: { plugins: [i18n] }, attachTo: document.body })
    expect(inlineLabels(wrapper)).toContain('Odeslat upomínku')
    expect(inlineLabels(wrapper)).not.toContain('Označit zaplaceno')
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const menu = document.querySelector('[data-test="action-bar-menu"]')!
    expect(menu.className).toContain('bottom-0')
    expect(menu.className).toContain('max-h-[85dvh]')
    expect(menu.textContent).toContain('Označit zaplaceno')
    expect(menu.textContent).toContain('Smazat')
    wrapper.unmount()
  })

  it('na desktopu zůstává nabídka vedle tlačítka', async () => {
    const wrapper = mount(ActionBar, { props: { actions }, global: { plugins: [i18n] }, attachTo: document.body })
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const menu = document.querySelector<HTMLElement>('[data-test="action-bar-menu"]')!
    expect(menu.className).toContain('w-60')
    expect(menu.className).not.toContain('bottom-0')
    expect(menu.style.top).not.toBe('')
    wrapper.unmount()
  })
})

describe('BulkActionBar na telefonu', () => {
  const slots = { default: '<button type="button" class="h-8">Zaplaceno</button><button type="button">Stornovat</button>' }

  it('mimo telefon ukazuje akce přímo v liště', () => {
    mount(BulkActionBar, { props: { count: 2 }, slots, global: { plugins: [i18n] }, attachTo: document.body })
    expect(document.body.textContent).toContain('Stornovat')
    expect(document.querySelector('[data-test="bulk-bar-phone"]')).toBeNull()
  })

  it('na telefonu drží jeden řádek a akce otevře v nabídce, klik na akci ji zavře', async () => {
    phone.value = true
    const wrapper = mount(BulkActionBar, { props: { count: 2 }, slots, global: { plugins: [i18n] }, attachTo: document.body })
    expect(document.querySelector('[data-test="bulk-bar-phone"]')).not.toBeNull()
    expect(document.body.textContent).not.toContain('Stornovat')
    ;(document.querySelector('[data-test="bulk-bar-actions"]') as HTMLButtonElement).click()
    await nextTick()
    const sheet = document.querySelector('[data-test="bulk-bar-sheet"]')!
    expect(sheet.textContent).toContain('Stornovat')
    ;(sheet.querySelector('button') as HTMLButtonElement).click()
    await nextTick()
    expect(document.querySelector('[data-test="bulk-bar-sheet"]')).toBeNull()
    wrapper.unmount()
  })
})
