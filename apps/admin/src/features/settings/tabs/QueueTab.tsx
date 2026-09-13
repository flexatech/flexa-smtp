import {
    AlertTriangle,
    Clock,
    Hash,
    Info,
    ListChecks,
    PlayCircle,
    Repeat,
    RotateCcw,
    Send,
    Trash2,
    Zap,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { __ } from "@/lib/i18n";
import { getPluginGlobal } from "@/lib/wp";
import { ROW_DIVIDER, SettingRow, ToggleRow } from "../SettingRow";
import { type TabProps } from "../types";
import { type QueueStats, useQueue, useQueueAction } from "@/features/queue/useQueue";

function StatChip({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: "neutral" | "warn" | "error";
}) {
    const toneClass =
        tone === "error"
            ? "fs:text-red-700"
            : tone === "warn"
              ? "fs:text-amber-700"
              : "fs:text-slate-900";
    return (
        <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:px-4 fs:py-3">
            <div className={`fs:text-2xl fs:font-semibold ${toneClass}`}>
                {value.toLocaleString()}
            </div>
            <div className="fs:mt-0.5 fs:text-xs fs:text-slate-500">{label}</div>
        </div>
    );
}

function nextRunLabel(next: string): string {
    if (next === "") {
        return __("Nothing scheduled");
    }
    const parsed = new Date(next.replace(" ", "T"));
    if (Number.isNaN(parsed.getTime())) {
        return next;
    }
    return parsed.toLocaleString();
}

function QueueStatus({ canManage }: { canManage: boolean }) {
    const q = useQueue();
    const act = useQueueAction();
    const stats: QueueStats | undefined = q.data;

    if (q.isLoading || !stats) {
        return (
            <div className="fs:px-5 fs:py-4 fs:text-sm fs:text-slate-500">
                {__("Loading queue status…")}
            </div>
        );
    }

    const busy = act.isPending;

    return (
        <div className="fs:space-y-4 fs:px-5 fs:py-5">
            <div className="fs:grid fs:grid-cols-2 fs:gap-3 fs:sm:grid-cols-4">
                <StatChip
                    label={__("Waiting")}
                    value={stats.pending}
                    tone="neutral"
                />
                <StatChip
                    label={__("In progress")}
                    value={stats.claimed}
                    tone="neutral"
                />
                <StatChip
                    label={__("Failed")}
                    value={stats.failed}
                    tone={stats.failed > 0 ? "error" : "neutral"}
                />
                <StatChip
                    label={__("Due now")}
                    value={stats.due}
                    tone={stats.due > 0 ? "warn" : "neutral"}
                />
            </div>

            <div className="fs:flex fs:flex-wrap fs:items-center fs:gap-x-6 fs:gap-y-2 fs:text-xs fs:text-slate-600">
                <span className="fs:inline-flex fs:items-center fs:gap-1.5">
                    <Zap className="fs:h-3.5 fs:w-3.5 fs:text-slate-400" aria-hidden />
                    {stats.action_scheduler
                        ? __("Runner: Action Scheduler")
                        : __("Runner: WP-Cron")}
                </span>
                <span className="fs:inline-flex fs:items-center fs:gap-1.5">
                    <Clock className="fs:h-3.5 fs:w-3.5 fs:text-slate-400" aria-hidden />
                    {__("Next run:")} {nextRunLabel(stats.next_at)}
                </span>
            </div>

            {canManage && (
                <div className="fs:flex fs:flex-wrap fs:gap-2 fs:pt-1">
                    <Button
                        variant="outline"
                        size="sm"
                        className="fs:gap-1.5"
                        disabled={busy}
                        onClick={() => act.mutate("run")}
                    >
                        <PlayCircle className="fs:h-4 fs:w-4" aria-hidden />
                        {__("Process now")}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="fs:gap-1.5"
                        disabled={busy || stats.failed === 0}
                        onClick={() => act.mutate("retry-failed")}
                    >
                        <RotateCcw className="fs:h-4 fs:w-4" aria-hidden />
                        {__("Retry failed")}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="fs:gap-1.5 fs:text-red-700 fs:hover:bg-red-50"
                        disabled={busy || stats.failed === 0}
                        onClick={() => act.mutate("clear-failed")}
                    >
                        <Trash2 className="fs:h-4 fs:w-4" aria-hidden />
                        {__("Clear failed")}
                    </Button>
                </div>
            )}
        </div>
    );
}

export function QueueTab({ form, setField }: TabProps) {
    const { canManageSettings } = getPluginGlobal();

    return (
        <div>
            <div className={ROW_DIVIDER}>
                <ToggleRow
                    icon={Send}
                    title={__("Send email in the background")}
                    description={__(
                        "Hand each message to a queue and deliver it out of band instead of during the page request. Off by default: with it on, wp_mail() returns true once a message is queued, before it actually goes out.",
                    )}
                    checked={form.enable_queue}
                    onChange={(v) => setField("enable_queue", v)}
                />
                <ToggleRow
                    icon={Repeat}
                    title={__("Retry failed sends")}
                    description={__(
                        "Automatically try again after a temporary failure (timeouts, rate limits, 4xx server errors) using exponential backoff. Permanent errors like a bad recipient or authentication failure are never retried.",
                    )}
                    checked={form.enable_retry}
                    onChange={(v) => setField("enable_retry", v)}
                />
                {form.enable_retry && (
                    <SettingRow
                        icon={Hash}
                        title={__("Maximum attempts")}
                        description={__(
                            "Total delivery attempts per message before it is marked failed (including the first).",
                        )}
                        htmlFor="flexa-queue-max-attempts"
                    >
                        <Input
                            id="flexa-queue-max-attempts"
                            type="number"
                            min={1}
                            max={10}
                            value={String(form.queue_max_attempts)}
                            onChange={(e) =>
                                setField(
                                    "queue_max_attempts",
                                    Math.max(1, Math.min(10, Number(e.target.value) || 1)),
                                )
                            }
                            className="fs:w-24"
                        />
                    </SettingRow>
                )}
            </div>

            {(form.enable_queue || form.enable_retry) && (
                <div className="fs:border-t fs:border-slate-100">
                    <div className="fs:flex fs:items-center fs:gap-2 fs:px-5 fs:pt-4 fs:text-sm fs:font-medium fs:text-slate-800">
                        <ListChecks
                            className="fs:h-4 fs:w-4 fs:text-brand-600"
                            aria-hidden
                        />
                        {__("Queue status")}
                    </div>
                    <QueueStatus canManage={canManageSettings} />
                </div>
            )}

            <div className="fs:flex fs:items-start fs:gap-2 fs:border-t fs:border-slate-100 fs:bg-slate-50/60 fs:px-5 fs:py-4 fs:text-xs fs:text-slate-500">
                <Info className="fs:mt-0.5 fs:h-4 fs:w-4 fs:shrink-0 fs:text-slate-400" aria-hidden />
                <p>
                    {__(
                        "The queue runs through Action Scheduler when your site has it (most WooCommerce sites do) and falls back to WP-Cron otherwise. A timeout does not always mean the email failed, so a retried message can in rare cases be delivered twice; leave retries off if that matters for your mail.",
                    )}
                </p>
            </div>

            {!canManageSettings && (
                <div className="fs:flex fs:items-start fs:gap-2 fs:px-5 fs:py-3 fs:text-xs fs:text-amber-700">
                    <AlertTriangle className="fs:mt-0.5 fs:h-4 fs:w-4 fs:shrink-0" aria-hidden />
                    <p>
                        {__(
                            "You can view the queue but need the settings capability to change these options or run the worker.",
                        )}
                    </p>
                </div>
            )}
        </div>
    );
}
