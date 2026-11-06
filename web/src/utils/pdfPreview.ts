import type { PDFDocumentProxy } from 'pdfjs-dist'

const RENDERER_KEY = 'myucto.pdf_renderer'

function forcedRenderer(): string | null {
  try {
    return localStorage.getItem(RENDERER_KEY)
  } catch {
    return null
  }
}

function isAppleTouchDevice(): boolean {
  if (typeof navigator === 'undefined') return false
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

export function usesNativePdfViewer(): boolean {
  const forced = forcedRenderer()
  if (forced === 'pdfjs') return false
  if (forced === 'native') return true
  if (typeof navigator === 'undefined') return true
  if (navigator.pdfViewerEnabled === false) return false
  return !isAppleTouchDevice()
}

type PdfjsModule = typeof import('pdfjs-dist')

let pdfjsPromise: Promise<PdfjsModule> | null = null

function loadPdfjs(): Promise<PdfjsModule> {
  pdfjsPromise ??= Promise.all([
    import('pdfjs-dist'),
    import('pdfjs-dist/build/pdf.worker.min.mjs?worker'),
  ]).then(([pdfjs, worker]) => {
    pdfjs.GlobalWorkerOptions.workerPort = new worker.default()
    return pdfjs
  }).catch((error: unknown) => {
    pdfjsPromise = null
    throw error
  })
  return pdfjsPromise
}

export interface PdfDocumentLoad {
  promise: Promise<PDFDocumentProxy>
  destroy: () => void
}

export function openPdfDocument(src: string): PdfDocumentLoad {
  let task: { destroy: () => Promise<void> } | null = null
  let destroyed = false
  const promise = loadPdfjs().then((pdfjs) => {
    if (destroyed) throw new Error('aborted')
    const assets = `${import.meta.env.BASE_URL}assets/pdfjs-${pdfjs.version}/`
    const loading = pdfjs.getDocument({
      url: src,
      withCredentials: true,
      disableRange: true,
      disableStream: true,
      enableXfa: false,
      useWasm: false,
      cMapUrl: `${assets}cmaps/`,
      standardFontDataUrl: `${assets}standard_fonts/`,
      wasmUrl: `${assets}wasm/`,
    })
    task = loading
    return loading.promise
  })
  return {
    promise,
    destroy: () => {
      destroyed = true
      void task?.destroy()
    },
  }
}
