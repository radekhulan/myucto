import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import CapturePhotoButton from './CapturePhotoButton.vue'

const ModalStub = defineComponent({
  name: 'Modal',
  emits: ['close'],
  template: '<div><slot /><slot name="footer" /></div>',
})

/** Ořez má vlastní spec; tady jde o to, jaký soubor do něj tlačítko pošle. */
const CropStub = defineComponent({
  name: 'PhotoCropDialog',
  props: { file: { type: Object, required: true }, allowRetake: Boolean },
  emits: ['done', 'retake', 'cancel'],
  template: '<div data-testid="crop-stub" />',
})

type Device = { kind: string; deviceId: string; label: string }

function camera(deviceId: string, label = ''): Device {
  return { kind: 'videoinput', deviceId, label }
}

function fakeStream(deviceId = 'cam-1') {
  const track = { stop: vi.fn(), getSettings: () => ({ deviceId }) }
  // Prototyp MediaStream kvůli kontrole typu v setteru `video.srcObject`.
  const stream = Object.assign(Object.create(MediaStream.prototype) as MediaStream, {
    getTracks: () => [track],
    getVideoTracks: () => [track],
  })
  return { track, stream }
}

let devices: Device[] = []
let deviceListeners: Array<() => void> = []
const getUserMedia = vi.fn()

function installMediaDevices() {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia,
      enumerateDevices: vi.fn(async () => devices),
      addEventListener: (_type: string, fn: () => void) => { deviceListeners.push(fn) },
      removeEventListener: (_type: string, fn: () => void) => { deviceListeners = deviceListeners.filter(f => f !== fn) },
    },
  })
}

function setTouch(touch: boolean) {
  window.matchMedia = vi.fn((query: string) => ({
    matches: touch && query === '(pointer: coarse)',
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia
}

async function mountButton() {
  const wrapper = mount(CapturePhotoButton, {
    attachTo: document.body,
    global: { stubs: { Modal: ModalStub, PhotoCropDialog: CropStub } },
  })
  await flushPromises()
  return wrapper
}

async function openPreview(wrapper: Awaited<ReturnType<typeof mountButton>>) {
  await wrapper.get('[data-testid="capture-photo"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="webcam-video"]').trigger('loadedmetadata')
}

describe('CapturePhotoButton', () => {
  beforeEach(() => {
    devices = []
    deviceListeners = []
    getUserMedia.mockReset()
    setTouch(false)
    installMediaDevices()
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined)
    Object.defineProperty(HTMLVideoElement.prototype, 'videoWidth', { configurable: true, get: () => 1920 })
    Object.defineProperty(HTMLVideoElement.prototype, 'videoHeight', { configurable: true, get: () => 1080 })
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage: vi.fn() } as unknown as CanvasRenderingContext2D)
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (cb: BlobCallback) {
      cb(new Blob(['jpeg'], { type: 'image/jpeg' }))
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
    delete (navigator as { mediaDevices?: unknown }).mediaDevices
    document.body.innerHTML = ''
  })

  it('na počítači bez kamery se neukáže', async () => {
    const wrapper = await mountButton()

    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(false)
  })

  it('na počítači bez podpory getUserMedia se neukáže', async () => {
    delete (navigator as { mediaDevices?: unknown }).mediaDevices
    const wrapper = await mountButton()

    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(false)
  })

  it('na počítači s kamerou se ukáže bez žádosti o oprávnění', async () => {
    devices = [{ kind: 'audioinput', deviceId: 'mic', label: '' }, camera('cam-1')]
    const wrapper = await mountButton()

    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(true)
    expect(getUserMedia).not.toHaveBeenCalled()
  })

  it('reaguje na připojení kamery (devicechange)', async () => {
    const wrapper = await mountButton()
    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(false)

    devices = [camera('cam-1')]
    deviceListeners.forEach(fn => fn())
    await flushPromises()

    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(true)
  })

  it('klik na počítači otevře živý náhled a spustí kameru', async () => {
    devices = [camera('cam-1')]
    const { stream } = fakeStream()
    getUserMedia.mockResolvedValue(stream)
    const wrapper = await mountButton()

    await openPreview(wrapper)

    expect(getUserMedia).toHaveBeenCalledWith({
      audio: false,
      video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
    })
    const video = wrapper.get('[data-testid="webcam-video"]').element as HTMLVideoElement
    expect(video.srcObject).toBe(stream)
    expect(wrapper.get('[data-testid="webcam-capture"]').attributes('disabled')).toBeUndefined()
  })

  it('vyfocení vytvoří JPEG soubor, zastaví kameru a otevře ořez', async () => {
    devices = [camera('cam-1')]
    const { stream, track } = fakeStream()
    getUserMedia.mockResolvedValue(stream)
    const wrapper = await mountButton()
    await openPreview(wrapper)

    await wrapper.get('[data-testid="webcam-capture"]').trigger('click')
    await flushPromises()

    const crop = wrapper.findComponent(CropStub)
    expect(crop.exists()).toBe(true)
    const file = crop.props('file') as File
    expect(file).toBeInstanceOf(File)
    expect(file.type).toBe('image/jpeg')
    expect(file.name).toMatch(/^foto-\d{8}-\d{6}\.jpg$/)
    expect(crop.props('allowRetake')).toBe(true)
    expect(track.stop).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="webcam-video"]').exists()).toBe(false)

    crop.vm.$emit('done', file)
    await flushPromises()
    expect(wrapper.emitted('captured')?.[0]).toEqual([file])
  })

  it('„Vyfotit znovu“ z ořezu vrátí do živého náhledu', async () => {
    devices = [camera('cam-1')]
    getUserMedia.mockImplementation(async () => fakeStream().stream)
    const wrapper = await mountButton()
    await openPreview(wrapper)
    await wrapper.get('[data-testid="webcam-capture"]').trigger('click')
    await flushPromises()

    wrapper.findComponent(CropStub).vm.$emit('retake')
    await flushPromises()

    expect(wrapper.findComponent(CropStub).exists()).toBe(false)
    expect(wrapper.find('[data-testid="webcam-video"]').exists()).toBe(true)
    expect(getUserMedia).toHaveBeenCalledTimes(2)
  })

  it('zrušení zastaví kameru a zavře náhled', async () => {
    devices = [camera('cam-1')]
    const { stream, track } = fakeStream()
    getUserMedia.mockResolvedValue(stream)
    const wrapper = await mountButton()
    await openPreview(wrapper)

    await wrapper.get('[data-testid="webcam-cancel"]').trigger('click')
    await flushPromises()

    expect(track.stop).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="webcam-video"]').exists()).toBe(false)
    expect(wrapper.emitted('captured')).toBeUndefined()
  })

  it('odpojení komponenty zastaví kameru', async () => {
    devices = [camera('cam-1')]
    const { stream, track } = fakeStream()
    getUserMedia.mockResolvedValue(stream)
    const wrapper = await mountButton()
    await openPreview(wrapper)

    wrapper.unmount()

    expect(track.stop).toHaveBeenCalled()
  })

  it('kamera spuštěná až po zavření náhledu se hned zastaví', async () => {
    devices = [camera('cam-1')]
    const { stream, track } = fakeStream()
    let resolve: (s: unknown) => void = () => {}
    getUserMedia.mockReturnValue(new Promise(r => { resolve = r }))
    const wrapper = await mountButton()
    await wrapper.get('[data-testid="capture-photo"]').trigger('click')
    await wrapper.get('[data-testid="webcam-cancel"]').trigger('click')

    resolve(stream)
    await flushPromises()

    expect(track.stop).toHaveBeenCalled()
  })

  it('odepřené oprávnění zobrazí chybu a dialog jde zavřít', async () => {
    devices = [camera('cam-1')]
    getUserMedia.mockRejectedValue(Object.assign(new Error('denied'), { name: 'NotAllowedError' }))
    const wrapper = await mountButton()
    await wrapper.get('[data-testid="capture-photo"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="webcam-error"]').text()).toContain('capture_photo.webcam_denied')
    expect(wrapper.find('[data-testid="webcam-capture"]').exists()).toBe(false)

    await wrapper.get('[data-testid="webcam-cancel"]').trigger('click')
    expect(wrapper.find('[data-testid="webcam-error"]').exists()).toBe(false)
  })

  it('obsazená kamera zobrazí srozumitelnou chybu', async () => {
    devices = [camera('cam-1')]
    getUserMedia.mockRejectedValue(Object.assign(new Error('busy'), { name: 'NotReadableError' }))
    const wrapper = await mountButton()
    await wrapper.get('[data-testid="capture-photo"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="webcam-error"]').text()).toContain('capture_photo.webcam_busy')
  })

  it('s více kamerami nabídne výběr a přepne na zvolenou', async () => {
    devices = [camera('cam-1', 'Integrovaná'), camera('cam-2', 'USB')]
    const first = fakeStream('cam-1')
    getUserMedia.mockResolvedValueOnce(first.stream).mockResolvedValueOnce(fakeStream('cam-2').stream)
    const wrapper = await mountButton()
    await openPreview(wrapper)

    const select = wrapper.get('[data-testid="webcam-camera"]')
    expect(select.findAll('option').map(o => o.text())).toEqual(['Integrovaná', 'USB'])
    await select.setValue('cam-2')
    await flushPromises()

    expect(first.track.stop).toHaveBeenCalled()
    expect(getUserMedia).toHaveBeenLastCalledWith({
      audio: false,
      video: { deviceId: { exact: 'cam-2' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
    })
  })

  it('na dotykovém zařízení dál otevře fotoaparát přes input capture', async () => {
    setTouch(true)
    devices = [camera('cam-1')]
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = await mountButton()

    await wrapper.get('[data-testid="capture-photo"]').trigger('click')

    expect(click).toHaveBeenCalled()
    expect(wrapper.get('input[type="file"]').attributes('capture')).toBe('environment')
    expect(getUserMedia).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="webcam-video"]').exists()).toBe(false)
  })

  it('na dotykovém zařízení se ukáže i bez kamery v seznamu zařízení', async () => {
    setTouch(true)
    delete (navigator as { mediaDevices?: unknown }).mediaDevices
    const wrapper = await mountButton()

    expect(wrapper.find('[data-testid="capture-photo"]').exists()).toBe(true)
  })
})
