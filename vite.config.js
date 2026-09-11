import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        // TT-3.1c/SCRUM-276: node_modules grew enough (amazon-chime-sdk-js alone adds hundreds
        // of files) that native inotify-based watching started hitting this host's
        // fs.inotify.max_user_instances limit inside the bind-mounted Docker container, crashing
        // the dev server on startup with EMFILE. Polling sidesteps the host's inotify limits
        // entirely -- the standard fix for this exact Docker+bind-mount failure mode.
        watch: {
            usePolling: true,
        },
    }
});
