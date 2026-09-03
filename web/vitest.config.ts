import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Časové pásmo se pinuje ještě před startem workerů: `formatUtcDateTime`
// převádí UTC sloupce do pásma stroje, takže bez tohohle by tytéž testy prošly
// na vývojářském Windows (Praha) a spadly v CI (UTC).
process.env.TZ = 'Europe/Prague'

// Samostatná konfigurace pro testy — nedědí server proxy / tailwind z vite.config.ts,
// jen vue plugin (pro .vue SFC) + alias `@` → src (shodně s vite.config a tsconfig).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@shared': fileURLToPath(new URL('./shared', import.meta.url)),
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    env: { TZ: 'Europe/Prague' },
    fileParallelism: false,
    include: ['src/**/*.{test,spec}.ts'],
  },
})
