import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    // PWA GELİŞTİRME BOYUNCA KAPALI (2026-08-06): service worker her deploy'da
    // eski kabuğu sunmayı sürdürüyor ve yeni ekranlar görünmüyordu.
    // selfDestroying, ZATEN KURULU service worker'ları da kendini kaldırmaya ve
    // önbelleğini temizlemeye zorlar — sadece VitePWA'yı silmek yetmezdi.
    // Uygulama kararlı hale gelince false'a çekip offline desteği geri açacağız.
    VitePWA({
      selfDestroying: true,
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'apple-touch-icon.png'],
      manifest: {
        name: 'ERP Web',
        short_name: 'ERP',
        description: 'ERP web arayüzü',
        lang: 'tr',
        start_url: '/',
        display: 'standalone',
        background_color: '#ffffff',
        theme_color: '#863bff',
        icons: [
          { src: '/pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512.png', sizes: '512x512', type: 'image/png' },
          {
            src: '/pwa-512-maskable.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api/, /^\/sanctum/, /^\/up/],
        // Bunny fonts: ilk kullanımda cache'e girer, offline'da yazı tipi korunur
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'bunny-fonts',
              expiration: { maxEntries: 16, maxAgeSeconds: 60 * 60 * 24 * 365 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, 'src'),
    },
  },
  server: {
    // Dev'de API istekleri lokal Laravel'e (php artisan serve) yönlenir;
    // prod'da nginx aynı domain üzerinden yönlendirdiği için baseURL değişmez.
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
      '/up': 'http://localhost:8000',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['src/test/setup.ts'],
    css: false,
  },
})
