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

/**
 * Chybová odpověď na stahování přijde jako JSON, ale `responseType: 'blob'`
 * z ní udělá Blob a interceptorům i obrazovkám zbude prázdná hláška. Rozbalí
 * se proto zpátky do objektu, aby se uživatel dozvěděl skutečný důvod.
 */
async function unwrapBlobError(error: any): Promise<never> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      error.response.data = JSON.parse(await data.text())
    } catch {
      // Neparsovatelné tělo necháváme být — původní chyba je pořád lepší
      // než chyba z parsování.
    }
  }
  throw error
}

/**
 * Vrací hlavičky odpovědi, aby volající mohl zobrazit i to, co se o souboru
 * dozvěděl server — typicky výsledek kontroly podání proti XSD. Stažení tím
 * neblokujeme: soubor se uloží vždy, hlavičky jsou informace navíc. Volající,
 * kteří je nepotřebují, návratovou hodnotu prostě ignorují.
 */
export async function downloadApiFile(
  url: string,
  fallbackFilename = 'export.xml',
): Promise<Record<string, string>> {
  const requestUrl = url.startsWith('/api/') ? url.slice(4) : url
  const response = await api.get<Blob>(requestUrl, { responseType: 'blob' })
    .catch(unwrapBlobError)
  saveBlob(response.data, response.headers['content-disposition'], fallbackFilename)

  return (response.headers ?? {}) as Record<string, string>
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
    .catch(unwrapBlobError)
  saveBlob(response.data, response.headers['content-disposition'], fallbackFilename)
}
