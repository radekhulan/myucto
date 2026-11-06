import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { createEditTracker, installWindowDataRefresh, registerWindowRefreshHandler } from '../windowDataRefresh'

const cleanups: (() => void)[] = []

function start(path = '/accounting/journal', visible = true) {
  const route = { path }
  const shown = ref(visible)
  const remount = vi.fn()
  const refresh = installWindowDataRefresh({ path: () => route.path, visible: shown, remount })
  cleanups.push(refresh.destroy)
  return { route, shown, remount, refresh }
}

function changed(self = false) {
  window.dispatchEvent(new CustomEvent('myucto:window-data-changed', {
    detail: { supplierId: 1, resource: 'accounting', ...(self ? { self } : {}) },
  }))
}

function field(value = '') {
  const input = document.createElement('input')
  input.value = value
  document.body.append(input)
  return input
}

function type(input: HTMLInputElement, value: string) {
  input.focus()
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
}

async function settled() {
  vi.advanceTimersByTime(1)
  await flushPromises()
}

describe('obnova stránky bez vlastní obnovy', () => {
  beforeEach(() => { vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }) })
  afterEach(() => {
    cleanups.splice(0).forEach(cleanup => cleanup())
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  it('přehled se znovu vytvoří hned a bez značky', () => {
    const { remount, refresh } = start()
    changed()
    expect(remount).toHaveBeenCalledTimes(1)
    expect(refresh.showNotice.value).toBe(false)
  })

  it('stránka s vlastní tichou obnovou se znovu nevytváří', () => {
    const { remount } = start()
    const release = registerWindowRefreshHandler()
    cleanups.push(release)
    changed()
    expect(remount).not.toHaveBeenCalled()
  })

  it('aktivní okno se po uložení v popupu obnoví až po zavření popupu', async () => {
    const { remount, refresh } = start()
    const popup = document.createElement('div')
    popup.className = 'fixed inset-0'
    const input = document.createElement('input')
    popup.append(input)
    document.body.append(popup)
    type(input, 'hodnota v popupu')
    changed(true)
    expect(remount).not.toHaveBeenCalled()
    expect(refresh.showNotice.value).toBe(false)
    popup.remove()
    await flushPromises()
    expect(remount).toHaveBeenCalledTimes(1)
  })

  it('rozepsané pole mimo popup nikdy nezahodí, ukáže značku a obnoví se po vrácení hodnoty', async () => {
    const { remount, refresh } = start()
    const input = field('puvodni')
    type(input, 'rozepsano')
    changed()
    expect(remount).not.toHaveBeenCalled()
    expect(refresh.showNotice.value).toBe(true)
    input.value = 'puvodni'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.blur()
    await settled()
    expect(remount).toHaveBeenCalledTimes(1)
    expect(refresh.showNotice.value).toBe(false)
  })

  it('fokus v poli obnovu odloží, dokud z něj uživatel neodejde', async () => {
    const { remount, refresh } = start()
    const input = field('text')
    input.focus()
    changed()
    expect(remount).not.toHaveBeenCalled()
    expect(refresh.showNotice.value).toBe(false)
    input.blur()
    await settled()
    expect(remount).toHaveBeenCalledTimes(1)
  })

  it('zavřený inline editor práci nedrží', () => {
    const { remount } = start()
    const input = field('puvodni')
    type(input, 'zmena')
    input.blur()
    input.remove()
    changed()
    expect(remount).toHaveBeenCalledTimes(1)
  })

  it('formulář se sám nenačte ani bez úprav, jen ukáže značku a obnoví se až na pokyn', () => {
    const { remount, refresh } = start('/invoices/5/edit')
    changed()
    expect(remount).not.toHaveBeenCalled()
    expect(refresh.showNotice.value).toBe(true)
    refresh.reload()
    expect(remount).toHaveBeenCalledTimes(1)
    expect(refresh.showNotice.value).toBe(false)
  })

  it('vlastní uložení formuláře značku neukáže', () => {
    const { remount, refresh } = start('/invoices/5/edit')
    changed(true)
    expect(remount).not.toHaveBeenCalled()
    expect(refresh.showNotice.value).toBe(false)
  })

  it('skryté okno se obnoví až při zobrazení', async () => {
    const { remount, shown } = start('/accounting/journal', false)
    changed()
    expect(remount).not.toHaveBeenCalled()
    shown.value = true
    await flushPromises()
    expect(remount).toHaveBeenCalledTimes(1)
  })

  it('přechod na jinou stránku čekající obnovu i značku zruší', async () => {
    const { remount, refresh, route, shown } = start('/invoices/5/edit')
    changed()
    expect(refresh.showNotice.value).toBe(true)
    route.path = '/invoices/5'
    refresh.routeChanged()
    expect(refresh.showNotice.value).toBe(false)
    shown.value = false
    shown.value = true
    await flushPromises()
    expect(remount).not.toHaveBeenCalled()
  })
})

describe('sledování rozepsané práce', () => {
  afterEach(() => { document.body.innerHTML = '' })

  it('rozpozná změnu zaškrtávacího pole a výběru a ignoruje tlačítka', () => {
    const tracker = createEditTracker()
    const checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    const button = document.createElement('input')
    button.type = 'button'
    document.body.append(checkbox, button)
    button.focus()
    button.dispatchEvent(new Event('change', { bubbles: true }))
    expect(tracker.isDirty()).toBe(false)
    checkbox.focus()
    expect(tracker.isDirty()).toBe(false)
    checkbox.checked = true
    expect(tracker.isDirty()).toBe(true)
    tracker.reset()
    expect(tracker.isDirty()).toBe(false)
    tracker.destroy()
  })
})
