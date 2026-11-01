import { vi } from 'vitest'

/**
 * jsdom nemá layout, šířka každého prvku je 0 a `DocumentPreviewSplit` by náhled
 * nevykreslil vůbec. Tady se nastaví, jak široké rozložení „změřilo".
 * 976 px = okno 1280 CSS px bez bočního menu a odsazení, typický 16" notebook.
 */
export function stubSplitWidth(width: number): void {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
    width, height: 800, left: 0, right: width, top: 0, bottom: 800, x: 0, y: 0, toJSON: () => ({}),
  } as DOMRect)
}
