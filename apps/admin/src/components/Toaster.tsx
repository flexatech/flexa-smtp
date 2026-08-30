import { Check, X } from "lucide-react";
import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { cn } from "@/lib/cn";
import { useUiStore } from "@/lib/store";

const AUTO_DISMISS_MS = 2500;

let activeClaim: symbol | null = null;

/**
 * The admin app can mount more than one React root in a page. A module-level
 * claim ensures only the first-mounted Toaster renders, so we don't get
 * duplicate portal'd toasts stacked on top of one another.
 */
export function Toaster() {
    const toast = useUiStore((s) => s.toast);
    const dismiss = useUiStore((s) => s.dismissToast);
    const [owns, setOwns] = useState(false);

    useEffect(() => {
        if (activeClaim !== null) {
            return;
        }
        const claim = Symbol("toaster");
        activeClaim = claim;
        setOwns(true);
        return () => {
            if (activeClaim === claim) {
                activeClaim = null;
            }
            setOwns(false);
        };
    }, []);

    useEffect(() => {
        if (!owns || !toast) {
            return;
        }
        const timer = window.setTimeout(dismiss, AUTO_DISMISS_MS);
        return () => window.clearTimeout(timer);
    }, [owns, toast, dismiss]);

    if (!owns || !toast) {
        return null;
    }

    return createPortal(
        <div
            key={toast.id}
            role="status"
            aria-live="polite"
            className="fs:pointer-events-none fs:fixed fs:left-1/2 fs:top-6 fs:z-[160003] fs:-translate-x-1/2"
        >
            <div
                className={cn(
                    "fs:pointer-events-auto fs:flex fs:items-center fs:gap-2 fs:rounded-full fs:px-4 fs:py-2 fs:text-sm fs:shadow-lg fs:ring-1",
                    toast.tone === "error"
                        ? "fs:bg-red-50 fs:text-red-700 fs:ring-red-200"
                        : "fs:bg-emerald-50 fs:text-emerald-800 fs:ring-emerald-200",
                )}
            >
                {toast.tone === "error" ? (
                    <X aria-hidden className="fs:h-4 fs:w-4" />
                ) : (
                    <Check aria-hidden className="fs:h-4 fs:w-4" />
                )}
                <span>{toast.message}</span>
            </div>
        </div>,
        document.body,
    );
}
