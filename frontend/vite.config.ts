import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const apiProxyTarget = env.VITE_API_PROXY_TARGET || process.env.VITE_API_PROXY_TARGET || 'http://localhost:8080'

  return {
  base: '/admin/',

  plugins: [
    vue(),
    AutoImport({
      resolvers: [ElementPlusResolver()],
      imports: ['vue', 'vue-router', 'pinia'],
      dts: 'src/auto-imports.d.ts',
    }),
    Components({
      resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
      dts: 'src/components.d.ts',
    }),
  ],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  css: {
    preprocessorOptions: {
      scss: {
        additionalData: `@use "@/styles/element-overrides.scss" as *;`,
        // Silence Dart Sass 2.0 legacy-JS-API deprecation from Element Plus SCSS source
        silenceDeprecations: ['legacy-js-api'],
      },
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: apiProxyTarget,
        changeOrigin: true,
        timeout: 600_000,
        proxyTimeout: 600_000,
      },
      '/storage': {
        target: apiProxyTarget,
        changeOrigin: true,
      },
    },
  },

  build: {
    outDir: '../public/admin',
    // Avoid colliding with SPA route /admin/assets (AssetsView). A real
    // public/admin/assets/ directory makes nginx try_files treat refresh as a folder.
    assetsDir: 'static',
    emptyOutDir: true,
    chunkSizeWarningLimit: 1500,
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-vue':     ['vue', 'vue-router', 'pinia'],
          'vendor-axios':   ['axios'],
        },
      },
    },
  },
  }
})
