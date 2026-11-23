import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { i18n } from '@/i18n'

afterEach(() => {
  document.body.innerHTML = ''
})

function mountBar(actions: ActionItem[]) {
  return mount(ActionBar, {
    props: { actions },
    global: { plugins: [i18n] },
  })
}

/*
 * Zašedlé tlačítko bez věty „proč" je slepá ulička: uživatel vidí akci, ale
 * nemá jak zjistit, co pro ni musí udělat. Tooltip sám nestačí — na dotykovém
 * displeji se nedá vyvolat a u `disabled` prvku ho přeskočí i čtečka.
 */
describe('ActionBar — důvod u zašedlé akce', () => {
  it('ukáže důvod tooltipem i viditelnou větou', () => {
    const wrapper = mountBar([{
      key: 'post',
      label: 'Zaúčtovat',
      tier: 'primary',
      disabled: true,
      disabledReason: 'Nejdřív schvalte revizi za toto období.',
      run: () => {},
    }])

    expect(wrapper.get('button').attributes('title'))
      .toBe('Nejdřív schvalte revizi za toto období.')
    expect(wrapper.get('[data-test="action-disabled-reason"]').text())
      .toBe('Nejdřív schvalte revizi za toto období.')
  })

  it('mlčí, dokud je akce použitelná', () => {
    const wrapper = mountBar([{
      key: 'post',
      label: 'Zaúčtovat',
      tier: 'primary',
      disabled: false,
      disabledReason: 'Nejdřív schvalte revizi za toto období.',
      run: () => {},
    }])

    expect(wrapper.get('button').attributes('title')).toBeUndefined()
    expect(wrapper.find('[data-test="action-disabled-reason"]').exists()).toBe(false)
  })

  it('nechá přednost výslovnému title a stejný důvod nepíše dvakrát', () => {
    const wrapper = mountBar([
      {
        key: 'a',
        label: 'A',
        tier: 'primary',
        disabled: true,
        title: 'Vlastní tooltip',
        disabledReason: 'Chybí revize.',
        run: () => {},
      },
      { key: 'b', label: 'B', disabled: true, disabledReason: 'Chybí revize.', run: () => {} },
    ])

    expect(wrapper.get('button').attributes('title')).toBe('Vlastní tooltip')
    expect(wrapper.findAll('[data-test="action-disabled-reason"]')).toHaveLength(1)
  })
})

describe('ActionBar — počet akcí v hlavičce', () => {
  const four: ActionItem[] = [
    { key: 'a', label: 'A', tier: 'primary', run: () => {} },
    { key: 'b', label: 'B', run: () => {} },
    { key: 'c', label: 'C', run: () => {} },
    { key: 'd', label: 'D', run: () => {} },
  ]
  const desktopOnly = (wrapper: ReturnType<typeof mountBar>) =>
    wrapper.findAll('button.sm\\:inline-flex').map(b => b.text())

  it('na desktopu ukáže nejvýš tři akce', () => {
    expect(desktopOnly(mountBar(four))).toEqual(['C'])
  })

  it('stránka si může povolit víc', () => {
    const wrapper = mount(ActionBar, { props: { actions: four, desktopInline: 4 }, global: { plugins: [i18n] } })
    expect(desktopOnly(wrapper)).toEqual(['C', 'D'])
  })
})

/*
 * Prázdný stav a selhání načtení jsou dva různé stavy. Prázdno smí tvrdit
 * „nic tu není"; selhání o obsahu neví nic a smí jen nabídnout opakování.
 */
describe('EmptyState — varianta failed', () => {
  it('nabídne opakování a nevydává se za prázdnou agendu', async () => {
    const wrapper = mount(EmptyState, {
      props: { variant: 'failed' },
      global: { plugins: [i18n] },
    })

    expect(wrapper.get('[data-empty-state]').attributes('data-empty-state')).toBe('failed')
    const cta = wrapper.get('[data-test="empty-state-cta"]')
    expect(cta.text()).toBe(i18n.global.t('common.empty_state.retry'))
    expect(wrapper.text()).not.toContain(i18n.global.t('common.empty_state.title'))

    await cta.trigger('click')
    expect(wrapper.emitted('action')).toHaveLength(1)
  })

  it('u prázdné agendy žádné opakování nenabízí', () => {
    const wrapper = mount(EmptyState, {
      props: { variant: 'empty' },
      global: { plugins: [i18n] },
    })

    expect(wrapper.get('[data-empty-state]').attributes('data-empty-state')).toBe('empty')
    expect(wrapper.find('[data-test="empty-state-cta"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(i18n.global.t('common.empty_state.title'))
  })
})

describe('ActionBar — skupina v nabídce', () => {
  it('akce jedné skupiny stojí v „…" pod jedním nadpisem a od ostatních je dělí čára', async () => {
    const wrapper = mount(ActionBar, {
      props: { actions: [
        { key: 'edit', label: 'Upravit', tier: 'secondary', run: () => {} },
        { key: 'schedule', label: 'Nastavit opakování', tier: 'overflow', group: 'Plány', run: () => {} },
        { key: 'split', label: 'Rozložit na splátky', tier: 'overflow', group: 'Plány', run: () => {} },
        { key: 'delete', label: 'Smazat', tier: 'overflow', variant: 'danger', run: () => {} },
      ] },
      global: { plugins: [i18n] },
      attachTo: document.body,
    })
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const menu = document.querySelector('[data-test="action-bar-menu"]')!
    const groups = menu.querySelectorAll('[data-test="action-bar-group"]')
    expect(Array.from(groups).map(group => group.textContent?.trim())).toEqual(['Plány'])
    // Akce skupiny jsou pro čtečku v jednom role=group pojmenovaném nadpisem.
    const group = menu.querySelector('[data-test="action-bar-group-items"]')!
    expect(group.getAttribute('role')).toBe('group')
    expect(group.getAttribute('aria-label')).toBe('Plány')
    expect(Array.from(group.querySelectorAll('button')).map(button => button.textContent?.trim())).toEqual(['Nastavit opakování', 'Rozložit na splátky'])
    // Jediná čára stojí mezi skupinou a Smazat, ne před skupinou.
    const rules = menu.querySelectorAll('[data-test="action-bar-group-rule"]')
    expect(rules).toHaveLength(1)
    expect(rules[0].previousElementSibling).toBe(group)
    expect(rules[0].nextElementSibling?.textContent).toContain('Smazat')
    wrapper.unmount()
  })

  it('zamčenou akci v nabídce nespustí ani klávesa', async () => {
    const run = vi.fn()
    const wrapper = mount(ActionBar, {
      props: { actions: [{ key: 'reverse', label: 'Stornovat', tier: 'overflow', disabled: true, disabledReason: 'Položka má úhrady.', run }] },
      global: { plugins: [i18n] },
      attachTo: document.body,
    })
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const item = Array.from(document.querySelectorAll('[data-test="action-bar-menu"] button')).find(button => button.textContent?.includes('Stornovat'))!
    expect(item.getAttribute('aria-disabled')).toBe('true')
    ;(item as HTMLButtonElement).click()
    expect(run).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('zamčená akce v Pokročilých nese aria-disabled', async () => {
    const wrapper = mount(ActionBar, {
      props: { actions: [{ key: 'purge', label: 'Vymazat', tier: 'advanced', disabled: true, disabledReason: 'Nejdřív uzávěrka.', run: () => {} }] },
      global: { plugins: [i18n] },
      attachTo: document.body,
    })
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const toggle = Array.from(document.querySelectorAll('[data-test="action-bar-menu"] button')).find(button => !button.textContent?.includes('Vymazat'))!
    ;(toggle as HTMLButtonElement).click()
    await nextTick()
    const item = Array.from(document.querySelectorAll('[data-test="action-bar-menu"] button')).find(button => button.textContent?.includes('Vymazat'))!
    expect(item.getAttribute('aria-disabled')).toBe('true')
    wrapper.unmount()
  })

  it('zamčená akce s cílem se vykreslí jako tlačítko, ne odkaz', async () => {
    const wrapper = mount(ActionBar, {
      props: { actions: [{ key: 'open', label: 'Otevřít', tier: 'overflow', disabled: true, disabledReason: 'Nejdřív uložte.', to: '/other-items/1' }] },
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a data-test="router-link"><slot /></a>' } } },
      attachTo: document.body,
    })
    await wrapper.get('button[aria-expanded]').trigger('click')
    await nextTick()
    const menu = document.querySelector('[data-test="action-bar-menu"]')!
    expect(menu.querySelector('[data-test="router-link"]')).toBeNull()
    expect(Array.from(menu.querySelectorAll('button')).some(button => button.textContent?.includes('Otevřít'))).toBe(true)
    wrapper.unmount()
  })
})
