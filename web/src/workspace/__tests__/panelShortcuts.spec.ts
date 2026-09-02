import { describe, expect, it } from 'vitest'
import { paneCountFromShortcut, panelIndexFromShortcut } from '@/workspace/panelShortcuts'

function shortcut(overrides: Partial<KeyboardEvent> = {}): Pick<KeyboardEvent, 'altKey' | 'code' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey'> {
  return {
    altKey: true,
    code: 'Digit1',
    ctrlKey: true,
    key: '1',
    metaKey: false,
    shiftKey: false,
    ...overrides,
  }
}

describe('panel keyboard shortcuts', () => {
  it.each([
    ['Digit1', 0],
    ['Digit2', 1],
    ['Digit3', 2],
    ['Numpad3', 2],
  ])('mapuje Ctrl+Alt+%s na panel', (code, index) => {
    expect(panelIndexFromShortcut(shortcut({ code }))).toBe(index)
  })

  it('funguje i podle key, pokud code není dostupný', () => {
    expect(panelIndexFromShortcut(shortcut({ code: '', key: '2' }))).toBe(1)
  })

  it('na macOS používá Cmd+Option místo Ctrl+Alt', () => {
    expect(panelIndexFromShortcut(shortcut({ ctrlKey: false, metaKey: true }), true)).toBe(0)
    expect(panelIndexFromShortcut(shortcut(), true)).toBeNull()
  })

  it.each([
    { ctrlKey: false },
    { altKey: false },
    { metaKey: true },
    { shiftKey: true },
    { code: 'Digit4', key: '4' },
  ])('ignoruje jinou kombinaci %#', (overrides) => {
    expect(panelIndexFromShortcut(shortcut(overrides))).toBeNull()
  })

  it.each([
    ['Digit1', 1],
    ['Digit2', 2],
    ['Digit3', 3],
    ['Numpad2', 2],
  ])('mapuje Shift+Alt+%s na počet panelů', (code, count) => {
    expect(paneCountFromShortcut(shortcut({ code, ctrlKey: false, shiftKey: true }))).toBe(count)
  })

  it.each([
    { ctrlKey: true, shiftKey: true },
    { ctrlKey: false, shiftKey: false },
    { ctrlKey: false, shiftKey: true, altKey: false },
    { ctrlKey: false, shiftKey: true, code: 'Digit4', key: '4' },
  ])('nepovažuje jinou kombinaci za změnu rozložení %#', (overrides) => {
    expect(paneCountFromShortcut(shortcut(overrides))).toBeNull()
  })
})
