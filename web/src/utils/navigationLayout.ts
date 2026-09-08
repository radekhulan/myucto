const NAV_FIT_RESERVE = 12
const NAV_FIT_RELEASE_BUFFER = 80

export const NAVIGATION_RAIL_WIDTH_REM = 4.5
export const NAVIGATION_RAIL_MENU_WIDTH_REM = 18

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
