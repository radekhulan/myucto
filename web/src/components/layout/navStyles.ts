/**
 * Vzhled rámu aplikace sdílený hlavním layoutem a technickou Správou instance.
 * Technická plocha nesmí tahat AppLayout (firemní menu, přepínač firem, pracovní
 * plochu), takže společné jsou třídy a malé komponenty, ne celý layout.
 */
export const APP_HEADER = 'nav-inverted sticky top-0 z-30 bg-surface border-b border-neutral-200 shadow-md'
export const APP_HEADER_ROW = 'h-12 px-3 flex items-center gap-1'
export const APP_BRAND_LINK = 'flex h-10 items-center gap-2 shrink-0 px-1.5 rounded-md hover:bg-neutral-100'

export const TOP_NAV_ITEM = 'cursor-pointer group relative inline-flex min-w-0 self-center items-center h-8 rounded-lg text-sm font-medium whitespace-nowrap text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 transition-colors'
export const TOP_NAV_ITEM_PADDING = 'px-2.5 xl:px-3'
export const TOP_NAV_ITEM_ACTIVE = 'text-neutral-900 font-semibold'

export const DRAWER_ITEM = 'flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight'
export const DRAWER_ITEM_ACTIVE = 'bg-primary-50 text-primary-700 font-medium'
export const DRAWER_ITEM_IDLE = 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'

export const USER_MENU_ITEM = 'flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700'
