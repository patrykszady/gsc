import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import basicSsl from '@vitejs/plugin-basic-ssl';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const useHttps = env.VITE_DEV_HTTPS === 'true';
    const devPort = Number(env.VITE_DEV_PORT || 5173);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            ...(useHttps ? [basicSsl()] : []),
            tailwindcss(),
        ],
        server: {
            host: '127.0.0.1',
            port: devPort,
            strictPort: false,
            https: useHttps,
            hmr: {
                host: '127.0.0.1',
                protocol: useHttps ? 'wss' : 'ws',
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
