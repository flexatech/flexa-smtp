import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "node:path";

const root = resolve(__dirname);
const projectRoot = resolve(__dirname, "../..");

export default defineConfig(({ mode }) => ({
    root,
    base: mode === "development" ? "/" : "",
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            "@": resolve(root, "src"),
        },
    },
    server: {
        port: 5173,
        strictPort: true,
        cors: true,
        origin: "http://localhost:5173",
        hmr: {
            host: "localhost",
            protocol: "ws",
        },
    },
    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: resolve(projectRoot, "assets/dist"),
        sourcemap: mode !== "production",
        rollupOptions: {
            input: {
                main: resolve(root, "src/main.tsx"),
            },
        },
    },
}));
