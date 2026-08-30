import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { AppProviders } from "./app/providers";
import { Toaster } from "./components/Toaster";
import { SettingsPage } from "./features/settings/SettingsPage";
import "./styles/index.css";

const root = document.getElementById("flexa-smtp-admin-root");
if (root) {
    createRoot(root).render(
        <StrictMode>
            <AppProviders>
                <SettingsPage />
                <Toaster />
            </AppProviders>
        </StrictMode>,
    );
}
