const NAV_FIT_RESERVE = 12
const NAV_FIT_RELEASE_BUFFER = 80

export const NAVIGATION_RAIL_WIDTH_REM = 4.5
export const NAVIGATION_RAIL_MENU_WIDTH_REM = 18

/**
 * Úrovně šetření místem v horní liště. 0 = plná podoba, 1..5 = kumulativně zapnuté
 * úspory (iniciály místo jména, logo bez textu, menu bez šipek, užší položky menu,
 * přepínač panelů jako jedno tlačítko), 6 = boční menu.
 */
export const NAV_COMPACT_STEPS = 5
export const NAV_COMPACT_SIDE = NAV_COMPACT_STEPS + 1

/** Šířka tlačítka uživatelského menu, když je z něj jen kolečko s iniciálami. */
export const NAV_USER_COMPACT_WIDTH = 32
/** Šířka tlačítka uživatelského menu s celým jménem, než se změří skutečná (cca). */
export const NAV_USER_FULL_WIDTH_FALLBACK = 146
/** Šířka textu loga „MyÚčto.cz" než se změří skutečná (cca) + mezera k ikoně. */
export const NAV_LOGO_TEXT_WIDTH_FALLBACK = 118
export const NAV_LOGO_TEXT_GAP = 8
/** Šipka u položky menu (w-3) + mezera (gap-1.5). */
export const NAV_MENU_ARROW_WIDTH = 18
/** Úspora vodorovného paddingu jedné položky menu (px-3 / px-2.5 na px-1.5). */
export const NAV_MENU_PADDING_SAVING_XL = 12
export const NAV_MENU_PADDING_SAVING = 8
/** Plný přepínač panelů (3 tlačítka) vs. jedno tlačítko s nabídkou. */
export const NAV_LAYOUT_TOGGLE_SAVING = 52

export function canFitCompactNavigation(availableWidth: number, rootFontSize: number): boolean {
  return availableWidth > 0 && rootFontSize > 0
    && (NAVIGATION_RAIL_WIDTH_REM + NAVIGATION_RAIL_MENU_WIDTH_REM) * rootFontSize <= availableWidth * 0.9
}

export function prefersCompactNavigation(preference: boolean | null, desktopCompact: boolean): boolean {
  return preference ?? desktopCompact
}

export function shouldUseAutomaticSideNavigation(
  requiredWidth: number,
  availableWidth: number,
  currentlySide: boolean,
): boolean {
  const releaseBuffer = currentlySide ? NAV_FIT_RELEASE_BUFFER : 0
  return requiredWidth > availableWidth - NAV_FIT_RESERVE - releaseBuffer
}

/**
 * Vybere nejnižší úroveň šetření (0..6), při které se horní menu vejde.
 *
 * @param slack         dostupná šířka minus šířka menu, změřeno při `currentLevel`
 * @param currentLevel  úroveň, při které se měřilo (6 = boční menu; to měří plnou podobu)
 * @param savings       úspora šířky každého z kroků 1..5 oproti předchozímu
 *
 * Přepnutí na nižší úroveň než současnou chce navíc rezervu `NAV_FIT_RELEASE_BUFFER`,
 * ať lišta nekmitá na hranici.
 */
export function selectNavCompactLevel(
  slack: number,
  currentLevel: number,
  savings: readonly number[],
): number {
  if (!Number.isFinite(slack)) return NAV_COMPACT_SIDE
  const steps = Math.min(NAV_COMPACT_STEPS, savings.length)
  const applied = currentLevel >= NAV_COMPACT_SIDE ? 0 : Math.max(0, Math.min(currentLevel, steps))
  let slackAtFull = slack
  for (let i = 0; i < applied; i++) slackAtFull -= Math.max(0, savings[i] ?? 0)

  let slackAtLevel = slackAtFull
  for (let level = 0; level <= steps; level++) {
    if (level > 0) slackAtLevel += Math.max(0, savings[level - 1] ?? 0)
    const buffer = level < currentLevel ? NAV_FIT_RELEASE_BUFFER : 0
    if (slackAtLevel >= NAV_FIT_RESERVE + buffer) return level
  }
  return NAV_COMPACT_SIDE
}

/** Iniciály z jména („Radek Hulán" -> „RH"), jinak z e-mailu; prázdný řetězec když není z čeho. */
export function userInitials(name?: string | null, email?: string | null): string {
  const first = (word: string) => Array.from(word)[0] ?? ''
  const words = (name ?? '').split(/\s+/).filter(word => /[\p{L}\p{N}]/u.test(word))
  if (words.length >= 2) return (first(words[0]!) + first(words[words.length - 1]!)).toLocaleUpperCase()
  if (words.length === 1) return Array.from(words[0]!).slice(0, 2).join('').toLocaleUpperCase()
  const local = (email ?? '').split('@')[0] ?? ''
  const parts = local.split(/[._\-+\s]+/).filter(word => /[\p{L}\p{N}]/u.test(word))
  if (parts.length >= 2) return (first(parts[0]!) + first(parts[1]!)).toLocaleUpperCase()
  if (parts.length === 1) return Array.from(parts[0]!).slice(0, 2).join('').toLocaleUpperCase()
  return ''
}
