import { api } from '@/api/client'

function responseFilename(disposition: string | undefined, fallback: string): string {
  const utf8 = disposition?.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  if (utf8) {
    try {
      return decodeURIComponent(utf8)
    } catch {
      return utf8
    }
  }
  return disposition?.match(/filename="?([^";]+)"?/i)?.[1] ?? fallback
}

function saveBlob(data: Blob, disposition: string | undefined, fallback: string): void {
  const objectUrl = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = responseFilename(disposition, fallback)
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

export async function downloadApiFile(url: string, fallbackFilename = 'export.xml'): Promise<void> {
  const requestUrl = url.startsWith('/api/') ? url.slice(4) : url
  const response = await api.get<Blob>(requestUrl, { responseType: 'blob' })
  saveBlob(response.data, response.headers['content-disposition'], fallbackFilename)
}

/**
 * Totéž POSTem — pro podání, jejichž věcná část je tak rozsáhlá, že se do query
 * stringu nevejde, a navíc nese osobní údaje, které do URL nepatří.
 */
export async function downloadApiFilePost(
  url: string,
  payload: unknown,
  fallbackFilename = 'export.xml',
): Promise<void> {
  const requestUrl = url.startsWith('/api/') ? url.slice(4) : url
  const response = await api.post<Blob>(requestUrl, payload, { responseType: 'blob' })
  saveBlob(response.data, response.headers['content-disposition'], fallbackFilename)
}
