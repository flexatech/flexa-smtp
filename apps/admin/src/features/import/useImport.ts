import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";

export interface ImportSource {
    slug: string;
    label: string;
    logs_imported: boolean;
}

export type ImportMode = "settings" | "logs" | "both";

export interface ImportResult {
    source: string;
    settings_imported: boolean;
    logs_imported: number;
    logs_skipped: boolean;
}

const SOURCES_KEY = ["import", "sources"] as const;

export function useImportSources() {
    return useQuery<ImportSource[]>({
        queryKey: SOURCES_KEY,
        queryFn: async () =>
            (await api.get<{ sources: ImportSource[] }>("import")).sources,
    });
}

export function useRunImport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { source: string; mode: ImportMode; force?: boolean }) =>
            api.post<ImportResult>("import", input as Record<string, unknown>),
        onSuccess: () => {
            // An import can touch settings, logs, and report aggregates — refresh
            // every server-state key the migration may have changed.
            void qc.invalidateQueries({ queryKey: ["settings"] });
            void qc.invalidateQueries({ queryKey: ["logs"] });
            void qc.invalidateQueries({ queryKey: ["reports"] });
            void qc.invalidateQueries({ queryKey: SOURCES_KEY });
        },
    });
}
