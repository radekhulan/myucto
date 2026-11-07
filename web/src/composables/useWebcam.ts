import { PhotoDecodeError, photoFileName } from './usePhotoCapture'

/** Kvalita snímku z kamery; další zmenšení a komprese proběhne až v ořezu dokladu. */
export const WEBCAM_JPEG_QUALITY = 0.92

export type WebcamErrorKind = 'denied' | 'busy' | 'not_found' | 'failed'

/** Prohlížeč umí kameru přes getUserMedia (jen zabezpečený kontext — https nebo localhost). */
export function supportsWebcam(): boolean {
  if (typeof navigator === 'undefined') return false
  const media = navigator.mediaDevices
  return typeof media?.getUserMedia === 'function' && typeof media.enumerateDevices === 'function'
}

/**
 * Připojené kamery. Výčet nežádá o oprávnění, jen bez něj nemají zařízení
 * popisky (a některé prohlížeče ukážou jen jedno), proto se volá znovu po spuštění streamu.
 */
export async function listCameras(): Promise<MediaDeviceInfo[]> {
  if (!supportsWebcam()) return []
  try {
    return (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput')
  } catch {
    return []
  }
}

export async function hasCamera(): Promise<boolean> {
  return (await listCameras()).length > 0
}

/** Bez vybrané kamery zadní (u notebooku jediná), jinak konkrétní zařízení. */
export function webcamConstraints(deviceId?: string): MediaStreamConstraints {
  const size = { width: { ideal: 1920 }, height: { ideal: 1080 } }
  return {
    audio: false,
    video: deviceId ? { deviceId: { exact: deviceId }, ...size } : { facingMode: 'environment', ...size },
  }
}

export function webcamErrorKind(error: unknown): WebcamErrorKind {
  const name = (error as { name?: unknown } | null)?.name
  if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') return 'denied'
  if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') return 'busy'
  if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') return 'not_found'
  return 'failed'
}

export function stopStream(stream: MediaStream | null): void {
  stream?.getTracks().forEach(track => track.stop())
}

/** Aktuální snímek videa jako JPEG `foto-YYYYMMDD-HHMMSS.jpg` v plném rozlišení kamery. */
export async function captureFrame(video: HTMLVideoElement, now = new Date()): Promise<File> {
  const width = video.videoWidth
  const height = video.videoHeight
  if (!width || !height) throw new PhotoDecodeError('no frame')
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new PhotoDecodeError('canvas unavailable')
  ctx.drawImage(video, 0, 0, width, height)
  const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', WEBCAM_JPEG_QUALITY))
  if (!blob) throw new PhotoDecodeError('toBlob failed')
  return new File([blob], photoFileName(now), { type: 'image/jpeg', lastModified: now.getTime() })
}
