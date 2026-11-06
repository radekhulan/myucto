export function base64ToBytes(content: string): Uint8Array<ArrayBuffer> {
  const binary = atob(content)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index)
  return bytes
}

export function downloadAbo(filename: string, content: string, encoding: string): Uint8Array {
  const bytes = base64ToBytes(content)
  const blob = new Blob([bytes], { type: `text/plain;charset=${encoding}` })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  return bytes
}
