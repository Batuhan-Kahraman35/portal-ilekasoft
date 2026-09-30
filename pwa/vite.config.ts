import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  // Uygulama portal.ornekfirma.com/app/ altinda yayinlanir
  base: '/app/',
  server: {
    host: true, // LAN + tunel erisimi
    allowedHosts: true, // gecici test icin tunel domainlerine izin
    proxy: {
      // PWA'nin /api istekleri yerel Node API'ye (tek origin -> tunel calisir)
      '/api': 'http://localhost:3000',
    },
  },
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      // Manifest'i Node API dinamik uretiyor (marka DB'den gelir),
      // bu yuzden plugin'in manifest'ini kapatiyoruz; link index.html'de.
      manifest: false,
      injectRegister: 'auto',
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico}'],
        navigateFallback: '/app/index.html',
      },
    }),
  ],
})
