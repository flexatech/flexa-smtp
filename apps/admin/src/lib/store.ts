import { create } from "zustand";
import { persist, type PersistOptions } from "zustand/middleware";

export interface ToastState {
    id: number;
    message: string;
    tone: "success" | "error";
}

interface UiState {
    /** Which settings pane is open. Persisted so a reload lands on the same
     *  tab; everything else here is transient. */
    activeSection: string;
    /** Failure category the Logs tab is filtered by. Set from the Overview so a
     *  "12 auth failures" figure can deep-link into the matching log rows.
     *  Transient (never persisted). */
    logCategory: string;
    /** Transient (never persisted): the active toast, or null. */
    toast: ToastState | null;
    setActiveSection: (id: string) => void;
    setLogCategory: (category: string) => void;
    showToast: (message: string, tone?: ToastState["tone"]) => void;
    dismissToast: () => void;
}

const persistOptions: PersistOptions<
    UiState,
    Pick<UiState, "activeSection">
> = {
    name: "flexa-smtp:ui",
    // Persist only the nav tab - never the toast queue or any server data.
    partialize: (state) => ({ activeSection: state.activeSection }),
};

export const useUiStore = create<UiState>()(
    persist(
        (set) => ({
            activeSection: "overview",
            logCategory: "",
            toast: null,
            setActiveSection: (activeSection) => set({ activeSection }),
            setLogCategory: (logCategory) => set({ logCategory }),
            showToast: (message, tone = "success") =>
                set({ toast: { id: Date.now(), message, tone } }),
            dismissToast: () => set({ toast: null }),
        }),
        persistOptions,
    ),
);
