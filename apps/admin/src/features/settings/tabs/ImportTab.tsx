import { Download, PackageOpen } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { __, sprintf } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import {
    type ImportMode,
    type ImportResult,
    type ImportSource,
    useImportSources,
    useRunImport,
} from "@/features/import/useImport";

const MODE_OPTIONS: Array<{ value: ImportMode; label: string }> = [
    { value: "both", label: __("Settings + logs") },
    { value: "settings", label: __("Settings only") },
    { value: "logs", label: __("Logs only") },
];

function summarize(res: ImportResult): string {
    const parts: string[] = [];
    if (res.settings_imported) {
        parts.push(__("settings imported"));
    }
    if (res.logs_imported > 0) {
        parts.push(sprintf(__("%d log(s) imported"), res.logs_imported));
    } else if (res.logs_skipped) {
        parts.push(__("logs already imported"));
    }
    return parts.length > 0 ? parts.join(", ") : __("nothing to import");
}

function SourceRow({ source }: { source: ImportSource }) {
    const [mode, setMode] = useState<ImportMode>("both");
    const run = useRunImport();
    const showToast = useUiStore((s) => s.showToast);

    const onImport = () => {
        run.mutate(
            { source: source.slug, mode, force: mode === "logs" },
            {
                onSuccess: (res) =>
                    showToast(
                        sprintf(
                            __("%1$s: %2$s"),
                            source.label,
                            summarize(res),
                        ),
                    ),
                onError: () =>
                    showToast(
                        sprintf(__("Could not import from %s."), source.label),
                        "error",
                    ),
            },
        );
    };

    return (
        <div className="fs:flex fs:items-center fs:gap-3 fs:px-5 fs:py-4">
            <span className="fs:flex fs:h-9 fs:w-9 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-lg fs:bg-slate-100 fs:text-slate-500">
                <PackageOpen className="fs:h-4 fs:w-4" aria-hidden />
            </span>
            <div className="fs:min-w-0 fs:flex-1">
                <div className="fs:text-sm fs:font-medium fs:text-slate-800">
                    {source.label}
                </div>
                {source.logs_imported && (
                    <div className="fs:text-xs fs:text-slate-400">
                        {__("Logs already imported once.")}
                    </div>
                )}
            </div>
            <Select
                options={MODE_OPTIONS}
                value={mode}
                onChange={(e) => setMode(e.target.value as ImportMode)}
                className="fs:w-44"
                aria-label={__("What to import")}
            />
            <Button
                variant="outline"
                size="sm"
                onClick={onImport}
                disabled={run.isPending}
            >
                <Download className="fs:h-4 fs:w-4" aria-hidden />
                {run.isPending ? __("Importing…") : __("Import")}
            </Button>
        </div>
    );
}

export function ImportTab() {
    const sources = useImportSources();

    return (
        <div className="fs:p-5">
            <p className="fs:mb-4 fs:text-sm fs:text-slate-600">
                {__(
                    "Migrate your configuration and email logs from another SMTP plugin. Importing settings merges over your current Flexa SMTP settings; secrets are re-encrypted on import.",
                )}
            </p>

            {sources.isLoading && (
                <p className="fs:text-sm fs:text-slate-500">
                    {__("Detecting other plugins…")}
                </p>
            )}
            {sources.isError && (
                <p className="fs:rounded-md fs:bg-red-50 fs:p-3 fs:text-sm fs:text-red-700">
                    {__("Failed to detect importable plugins.")}
                </p>
            )}
            {sources.data && sources.data.length === 0 && (
                <p className="fs:rounded-md fs:border fs:border-dashed fs:border-slate-200 fs:p-6 fs:text-center fs:text-sm fs:text-slate-500">
                    {__("No other SMTP plugins with importable data were found.")}
                </p>
            )}
            {sources.data && sources.data.length > 0 && (
                <div className="fs:divide-y fs:divide-slate-100 fs:overflow-hidden fs:rounded-lg fs:border fs:border-slate-200">
                    {sources.data.map((s) => (
                        <SourceRow key={s.slug} source={s} />
                    ))}
                </div>
            )}
        </div>
    );
}
