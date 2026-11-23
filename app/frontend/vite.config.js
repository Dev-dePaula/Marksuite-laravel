import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// O frontend é compilado para ../public/app e servido pelo Laravel em /app (a API fica em /api).
export default defineConfig({
  base: '/app/',
  plugins: [react()],
  build: { outDir: '../public/app', emptyOutDir: true },
  server: { port: 5173, proxy: { '/api': { target: process.env.DEV_API || 'http://localhost:8123', changeOrigin: true } } },
});
