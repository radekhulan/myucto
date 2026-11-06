import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

const photoMocks = vi.hoisted(() => ({
  decodePhoto: vi.fn(),
  compressPhoto: vi.fn(),
  detectDocumentQuad: vi.fn(),
  warpQuad: vi.fn(),
}))

vi.mock('@/composables/usePhotoCapture', async importOriginal => ({
  ...(await importOriginal<typeof import('@/composables/usePhotoCapture')>()),
  decodePhoto: photoMocks.decodePhoto,
  compressPhoto: photoMocks.compressPhoto,
  pixelsSource: () => ({ width: 10, height: 10, createCanvas: vi.fn() }),
}))

vi.mock('@/composables/documentCrop', async importOriginal => ({
  ...(await importOriginal<typeof import('@/composables/documentCrop')>()),
  detectDocumentQuad: photoMocks.detectDocumentQuad,
  warpQuad: photoMocks.warpQuad,
}))

import PhotoCropDialog from './PhotoCropDialog.vue'

/** Dekodér a canvas zastupují mocky; jde o to, jaký soubor dialog vydá. */
const ModalStub = defineComponent({
  name: 'Modal',
  template: '<div><slot /><slot name="footer" /></div>',
})

function source() {
  return {
    width: 4000,
    height: 3000,
    createCanvas: () => ({ width: 1, height: 1, draw: vi.fn(), toBlob: async () => new Blob(['preview']) }),
    pixels: (width: number, height: number) => ({ width, height, data: new Uint8ClampedArray(width * height * 4) }),
    close: vi.fn(),
  }
}

async function mountDialog(props: { file: File; fromFile?: boolean; allowRetake?: boolean }) {
  const wrapper = mount(PhotoCropDialog, { props, global: { stubs: { Modal: ModalStub } } })
  await flushPromises()
  return wrapper
}

async function confirm(wrapper: Awaited<ReturnType<typeof mountDialog>>) {
  await wrapper.get('[data-testid="capture-photo-confirm"]').trigger('click')
  await new Promise(resolve => setTimeout(resolve, 150))
  await flushPromises()
}

const compressed = new File(['small'], 'foto-20261106-120000.jpg', { type: 'image/jpeg' })
const QUAD = [{ x: 40, y: 30 }, { x: 360, y: 30 }, { x: 360, y: 270 }, { x: 40, y: 270 }]

describe('PhotoCropDialog', () => {
  beforeEach(() => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:preview')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    photoMocks.decodePhoto.mockReset().mockImplementation(async () => source())
    photoMocks.compressPhoto.mockReset().mockResolvedValue(compressed)
    photoMocks.detectDocumentQuad.mockReset().mockReturnValue(null)
    photoMocks.warpQuad.mockReset().mockImplementation((_px, _q, width: number, height: number) => ({ width, height, data: new Uint8ClampedArray(4) }))
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('fotku ze souborů bez ořezu vydá v originále, nezmenšenou', async () => {
    const original = new File(['scan'], 'FA-2026-114.png', { type: 'image/png' })
    const wrapper = await mountDialog({ file: original, fromFile: true })

    await confirm(wrapper)

    expect(photoMocks.compressPhoto).not.toHaveBeenCalled()
    expect(wrapper.emitted('done')).toEqual([[original]])
  })

  it('fotku ze souborů s vypnutým nalezeným ořezem vydá v originále', async () => {
    photoMocks.detectDocumentQuad.mockReturnValue(QUAD)
    const original = new File(['photo'], 'FA-2026-114.jpg', { type: 'image/jpeg' })
    const wrapper = await mountDialog({ file: original, fromFile: true })

    await wrapper.get('[data-testid="capture-photo-crop"]').trigger('change')
    await confirm(wrapper)

    expect(photoMocks.compressPhoto).not.toHaveBeenCalled()
    expect(wrapper.emitted('done')).toEqual([[original]])
  })

  it('výřez fotky ze souborů zmenší a nechá mu základ názvu', async () => {
    photoMocks.detectDocumentQuad.mockReturnValue(QUAD)
    const wrapper = await mountDialog({ file: new File(['photo'], 'FA-2026-114.heic', { type: '' }), fromFile: true })

    await confirm(wrapper)

    expect(photoMocks.compressPhoto).toHaveBeenCalledTimes(1)
    expect(photoMocks.compressPhoto.mock.calls[0][1]).toMatchObject({ name: 'FA-2026-114.jpg' })
    expect(wrapper.emitted('done')).toEqual([[compressed]])
  })

  it('celou fotku z fotoaparátu dál zmenší s časovou značkou', async () => {
    const wrapper = await mountDialog({ file: new File(['photo'], 'image.jpg', { type: 'image/jpeg' }), allowRetake: true })

    await confirm(wrapper)

    expect(photoMocks.compressPhoto).toHaveBeenCalledTimes(1)
    expect(photoMocks.compressPhoto.mock.calls[0][1]).toEqual({ name: undefined })
    expect(wrapper.emitted('done')).toEqual([[compressed]])
  })
})
