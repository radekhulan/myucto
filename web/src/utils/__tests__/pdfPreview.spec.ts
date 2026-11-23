import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PdfFrame from '@/components/documents/PdfFrame.vue'
import { usesNativePdfViewer } from '@/utils/pdfPreview'

let current: Partial<Navigator> = {}

function stubNavigator(values: Partial<Navigator>) {
  current = { ...current, ...values }
  vi.stubGlobal('navigator', current)
}

afterEach(() => {
  current = {}
  vi.unstubAllGlobals()
})

describe('usesNativePdfViewer', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.removeItem('myucto.pdf_renderer')
  })

  it('nechá PDF prohlížeči, který ho umí zobrazit', () => {
    stubNavigator({ pdfViewerEnabled: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0) Edg/140', platform: 'Win32', maxTouchPoints: 0 })
    expect(usesNativePdfViewer()).toBe(true)
  })

  it('na Androidu bez vestavěného prohlížeče PDF kreslí sám', () => {
    stubNavigator({ pdfViewerEnabled: false, userAgent: 'Mozilla/5.0 (Linux; Android 15) EdgA/140', platform: 'Linux armv8l', maxTouchPoints: 5 })
    expect(usesNativePdfViewer()).toBe(false)
  })

  it('na iPadu i iPhonu kreslí sám, protože rám ukáže jen první stránku', () => {
    stubNavigator({ pdfViewerEnabled: true, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5 })
    expect(usesNativePdfViewer()).toBe(false)
    stubNavigator({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', platform: 'iPhone' })
    expect(usesNativePdfViewer()).toBe(false)
  })

  it('přepínač v localStorage má přednost před detekcí', () => {
    stubNavigator({ pdfViewerEnabled: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0)', platform: 'Win32', maxTouchPoints: 0 })
    localStorage.setItem('myucto.pdf_renderer', 'pdfjs')
    expect(usesNativePdfViewer()).toBe(false)
    localStorage.setItem('myucto.pdf_renderer', 'native')
    stubNavigator({ pdfViewerEnabled: false })
    expect(usesNativePdfViewer()).toBe(true)
  })
})

describe('PdfFrame', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.removeItem('myucto.pdf_renderer')
  })

  it('s vestavěným prohlížečem roztáhne stránku na šířku a vlastní fragment zachová', () => {
    stubNavigator({ pdfViewerEnabled: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0)', platform: 'Win32', maxTouchPoints: 0 })
    expect(mount(PdfFrame, { props: { src: '/api/pdf/1' } }).get('iframe').attributes('src')).toBe('/api/pdf/1#view=FitH')
    expect(mount(PdfFrame, { props: { src: '/api/pdf/1#page=3' } }).get('iframe').attributes('src')).toBe('/api/pdf/1#page=3')
    expect(mount(PdfFrame, { props: { src: 'blob:abc' } }).get('embed').attributes('src')).toBe('blob:abc')
  })

  it('bez vestavěného prohlížeče předá kreslení pdf.js bez fragmentu', () => {
    localStorage.setItem('myucto.pdf_renderer', 'pdfjs')
    const wrapper = mount(PdfFrame, {
      props: { src: '/api/pdf/1#view=FitH', title: 'Faktura' },
      global: { stubs: { PdfCanvasViewer: { props: ['src', 'title'], template: '<div data-test="canvas" :data-src="src" />' } } },
    })
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.get('[data-test="canvas"]').attributes('data-src')).toBe('/api/pdf/1')
  })
})
