import { isApplePlatform } from '@/utils/clientPlatform'

type PanelShortcutEvent = Pick<KeyboardEvent, 'altKey' | 'code' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey'>

function shortcutDigit(event: PanelShortcutEvent): number | null {
  const codeMatch = /^(?:Digit|Numpad)([123])$/.exec(event.code)
  const digit = codeMatch?.[1] ?? (/^[123]$/.test(event.key) ? event.key : null)
  return digit ? Number(digit) : null
}

export function panelIndexFromShortcut(event: PanelShortcutEvent, apple = isApplePlatform()): number | null {
  const primaryPressed = apple
    ? event.metaKey && !event.ctrlKey
    : event.ctrlKey && !event.metaKey
  if (!primaryPressed || !event.altKey || event.shiftKey) return null
  const digit = shortcutDigit(event)
  return digit === null ? null : digit - 1
}

export function paneCountFromShortcut(event: PanelShortcutEvent): 1 | 2 | 3 | null {
  if (event.ctrlKey || !event.altKey || event.metaKey || !event.shiftKey) return null
  return shortcutDigit(event) as 1 | 2 | 3 | null
}
