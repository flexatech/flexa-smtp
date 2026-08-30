import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";

/** One mailer's credential values (secrets arrive masked). */
export type MailerValues = Record<string, string | number | boolean>;

/**
 * Mirrors `Flexa\Smtp\Support\Settings::for_rest()`. Keep the keys in lockstep
 * with the PHP schema — the REST sanitizer drops anything unknown, so a typo
 * here silently no-ops on save.
 */
export interface SettingsData {
    current_mailer: string;
    from_email: string;
    from_name: string;
    force_from_email: boolean;
    force_from_name: boolean;
    disable_delivery: boolean;
    fallback_enabled: boolean;
    fallback_mailer: string;
    enable_email_log: boolean;
    log_retention_days: number;
    enable_open_tracking: boolean;
    enable_click_tracking: boolean;
    enable_weekly_report: boolean;
    enable_monthly_report: boolean;
    report_recipients: string;
    mailers: Record<string, MailerValues>;
}

const SETTINGS_KEY = ["settings"] as const;

export function useSettings() {
    return useQuery<SettingsData>({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<SettingsData>("settings"),
    });
}

export function useSaveSettings() {
    const qc = useQueryClient();
    return useMutation({
        // Partial payload: only changed fields. The PHP controller merges the
        // sanitized payload over what is stored (mailers merged per-slug), so
        // sending the whole blob would race a concurrent edit.
        mutationFn: (input: Partial<SettingsData>) =>
            api.post<SettingsData>("settings", input as Record<string, unknown>),
        onMutate: async (input) => {
            await qc.cancelQueries({ queryKey: SETTINGS_KEY });
            const prev = qc.getQueryData<SettingsData>(SETTINGS_KEY);
            if (prev) {
                qc.setQueryData<SettingsData>(SETTINGS_KEY, {
                    ...prev,
                    ...input,
                    mailers: { ...prev.mailers, ...(input.mailers ?? {}) },
                });
            }
            return { prev };
        },
        onError: (_err, _vars, ctx) => {
            if (ctx?.prev) {
                qc.setQueryData(SETTINGS_KEY, ctx.prev);
            }
        },
        onSuccess: (next) => {
            qc.setQueryData(SETTINGS_KEY, next);
        },
    });
}

export function useResetAllData() {
    const qc = useQueryClient();
    return useMutation({
        // POST /reset runs Maintenance\Eraser::erase_all(); the body carries the
        // typed confirm phrase only for symmetry with the confirmation UX.
        mutationFn: (confirm: string) => api.post<unknown>("reset", { confirm }),
        onSuccess: () => {
            void qc.invalidateQueries();
        },
    });
}
