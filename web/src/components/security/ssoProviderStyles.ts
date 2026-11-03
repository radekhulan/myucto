import { BTN_BASE, FILLED, OUTLINE } from '@/components/ui/buttonStyles'

/** Apple svá tlačítka připouští jen černá, nebo bílá v tmavém motivu. */
export const APPLE_BUTTON =
  'border border-black bg-black text-white hover:bg-black/85 dark:border-white dark:bg-white dark:text-black dark:hover:bg-white/90'

/**
 * Tlačítko přihlášení přes poskytovatele. Kromě Apple zůstávají neutrální
 * s barevnou značkou; zvýrazněný je jen poskytovatel, kterého firma vyžaduje.
 */
export function ssoButtonClass(provider: string, emphasised = false): string[] {
  if (provider === 'apple') return [BTN_BASE, APPLE_BUTTON, 'w-full justify-center']
  return [BTN_BASE, emphasised ? FILLED.primary : OUTLINE.neutral, 'w-full justify-center']
}
