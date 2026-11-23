import { BTN_BASE, FILLED, OUTLINE } from '@/components/ui/buttonStyles'

/**
 * Tlačítko přihlášení přes poskytovatele. Všichni poskytovatelé mají stejné
 * neutrální tlačítko s barevnou značkou (Apple připouští bílé s obrysem a černým
 * logem); zvýrazněný je jen poskytovatel, kterého firma vyžaduje.
 */
export function ssoButtonClass(_provider: string, emphasised = false): string[] {
  return [BTN_BASE, emphasised ? FILLED.primary : OUTLINE.neutral, 'w-full justify-center']
}
