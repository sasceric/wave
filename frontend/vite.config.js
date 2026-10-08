import { rmSync } from 'node:fs'
import vue from '@vitejs/plugin-vue'
import VueI18nPlugin from '@intlify/unplugin-vue-i18n/vite'
import { fileURLToPath } from 'node:url'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vite'

const builtAssetsDirectory = fileURLToPath(new URL('../public/build/assets', import.meta.url))

export default defineConfig({
  plugins: [
    {
      name: 'clean-wave-assets',
      apply: 'build',
      buildStart() {
        rmSync(builtAssetsDirectory, { recursive: true, force: true })
      },
    },
    vue(),
    VueI18nPlugin({
      include: fileURLToPath(new URL('./src/locales/**', import.meta.url)),
      compositionOnly: true,
      dropMessageCompiler: true,
    }),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.js',
      injectRegister: null,
      registerType: 'prompt',
      devOptions: {
        enabled: true,
        type: 'module',
        navigateFallback: '/index.html',
      },
      manifest: {
        name: 'Wave — saradnje kreatora i brendova',
        short_name: 'Wave',
        description: 'Mirno mjesto gdje kreatori i brendovi pronalaze prave partnere.',
        theme_color: '#173d36',
        background_color: '#f8f7f4',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/pwa-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: '/pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      injectManifest: {
        globPatterns: ['**/*.{css,html,ico,js,png,svg,webp,woff2}'],
      },
    }),
  ],
  build: {
    cssCodeSplit: true,
    outDir: '../public',
    assetsDir: 'build/assets',
    emptyOutDir: false,
  },
  server: {
    host: '127.0.0.1',
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
