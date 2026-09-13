import {
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    CircleHelp,
    Info,
    RefreshCw,
    XCircle,
    type LucideIcon,
} from "lucide-react";
import { type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { __, sprintf } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import { getPluginGlobal } from "@/lib/wp";
import { categoryLabel, mailerLabel } from "@/features/settings/types";
import {
    type HealthReport,
    type HealthSignal,
    type MonitoringData,
    type ProviderMetric,
    useHealth,
    useMonitoring,
    useRunHealth,
} from "./useOverview";

const MONITOR_DAYS = 30;

type Tone = "pass" | "warn" | "error" | "neutral";

const TONE_STYLES: Record<
    Tone,
    { icon: LucideIcon; chip: string; text: string; dot: string }
> = {
    pass: {
        icon: CheckCircle2,
        chip: "fs:bg-emerald-50 fs:text-emerald-700 fs:ring-emerald-200",
        text: "fs:text-emerald-700",
        dot: "fs:bg-emerald-500",
    },
    warn: {
        icon: AlertTriangle,
        chip: "fs:bg-amber-50 fs:text-amber-700 fs:ring-amber-200",
        text: "fs:text-amber-700",
        dot: "fs:bg-amber-500",
    },
    error: {
        icon: XCircle,
        chip: "fs:bg-red-50 fs:text-red-700 fs:ring-red-200",
        text: "fs:text-red-700",
        dot: "fs:bg-red-500",
    },
    neutral: {
        icon: CircleHelp,
        chip: "fs:bg-slate-100 fs:text-slate-600 fs:ring-slate-200",
        text: "fs:text-slate-600",
        dot: "fs:bg-slate-400",
    },
};

function healthTone(status: string): Tone {
    if (status === "pass") return "pass";
    if (status === "warn") return "warn";
    if (status === "error") return "error";
    return "neutral";
}

function signalTone(signal: HealthSignal): Tone {
    if (signal === "healthy") return "pass";
    if (signal === "degraded") return "warn";
    if (signal === "failing") return "error";
    return "neutral";
}

function statusLabel(status: string): string {
    switch (status) {
        case "pass":
            return __("Passed");
        case "warn":
            return __("Needs attention");
        case "error":
            return __("Problem found");
        case "na":
            return __("Not applicable");
        default:
            return __("Not checked");
    }
}

function signalLabel(signal: HealthSignal): string {
    switch (signal) {
        case "healthy":
            return __("Healthy");
        case "degraded":
            return __("Degraded");
        case "failing":
            return __("Failing");
        default:
            return __("Not enough data");
    }
}

/** A card that frames one of the three overview questions. */
function QuestionCard({
    step,
    question,
    action,
    children,
}: {
    step: number;
    question: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="fs:rounded-xl fs:border fs:border-slate-200 fs:bg-white fs:p-5 fs:shadow-sm">
            <div className="fs:mb-4 fs:flex fs:items-start fs:justify-between fs:gap-3">
                <div className="fs:flex fs:items-center fs:gap-3">
                    <span className="fs:flex fs:h-7 fs:w-7 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-full fs:bg-brand-500 fs:text-xs fs:font-bold fs:text-white">
                        {step}
                    </span>
                    <h3 className="fs:text-base fs:font-semibold fs:text-slate-900">
                        {question}
                    </h3>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function StatusPill({ status }: { status: string }) {
    const tone = TONE_STYLES[healthTone(status)];
    const Icon = tone.icon;
    return (
        <span
            className={cn(
                "fs:inline-flex fs:items-center fs:gap-1.5 fs:rounded-full fs:px-3 fs:py-1 fs:text-sm fs:font-medium fs:ring-1",
                tone.chip,
            )}
        >
            <Icon className="fs:h-4 fs:w-4" aria-hidden />
            {statusLabel(status)}
        </span>
    );
}

function relativeTime(unix: number): string {
    if (unix <= 0) {
        return __("never");
    }
    return new Date(unix * 1000).toLocaleString();
}

/* --- Question 1: health ------------------------------------------------- */

const HEALTH_HEADLINE: Record<Tone, string> = {
    pass: __("Your setup looks ready to send email."),
    warn: __("Email can still send, but some things should be improved."),
    error: __("Something is likely to stop or degrade your email delivery."),
    neutral: __("Run the checks to see whether your setup is ready."),
};

function HealthCard({ report }: { report: HealthReport }) {
    const tone = healthTone(report.status);
    // NA/not-checked rows carry no signal; keep them last and muted.
    const checks = [...report.checks].sort(
        (a, b) => rank(b.status) - rank(a.status),
    );

    return (
        <div className="fs:space-y-4">
            <div className="fs:flex fs:flex-wrap fs:items-center fs:gap-3">
                <StatusPill status={report.status} />
                <p className={cn("fs:text-sm", TONE_STYLES[tone].text)}>
                    {HEALTH_HEADLINE[tone]}
                </p>
            </div>

            <ul className="fs:divide-y fs:divide-slate-100 fs:rounded-lg fs:border fs:border-slate-200">
                {checks.map((c) => {
                    const t = TONE_STYLES[healthTone(c.status)];
                    const Icon = t.icon;
                    return (
                        <li
                            key={`${c.group}:${c.id}`}
                            className="fs:flex fs:gap-3 fs:px-4 fs:py-3"
                        >
                            <Icon
                                className={cn("fs:mt-0.5 fs:h-4 fs:w-4 fs:shrink-0", t.text)}
                                aria-hidden
                            />
                            <div className="fs:min-w-0 fs:flex-1">
                                <div className="fs:flex fs:flex-wrap fs:items-center fs:gap-2">
                                    <span className="fs:text-sm fs:font-medium fs:text-slate-800">
                                        {c.label}
                                    </span>
                                    <span
                                        className={cn(
                                            "fs:rounded fs:px-1.5 fs:py-0.5 fs:text-xs fs:font-medium fs:ring-1",
                                            t.chip,
                                        )}
                                    >
                                        {statusLabel(c.status)}
                                    </span>
                                </div>
                                <p className="fs:mt-0.5 fs:text-sm fs:text-slate-600">
                                    {c.message}
                                </p>
                                {c.remediation && c.status !== "pass" && (
                                    <p className="fs:mt-1 fs:text-xs fs:text-slate-500">
                                        {c.remediation}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>

            <p className="fs:text-xs fs:text-slate-400">
                {sprintf(
                    __("Last checked: %s."),
                    relativeTime(report.generated_at),
                )}
                {report.stale &&
                    ` ${__("This report may be out of date; run the checks for a fresh result.")}`}
            </p>
        </div>
    );
}

function rank(status: string): number {
    switch (status) {
        case "error":
            return 3;
        case "warn":
            return 2;
        case "pass":
            return 1;
        default:
            return 0;
    }
}

/* --- Question 2: deliverability ---------------------------------------- */

function pct(value: number): string {
    return `${value}%`;
}

function ProviderRow({ p }: { p: ProviderMetric }) {
    const tone = TONE_STYLES[signalTone(p.health)];
    return (
        <div className="fs:flex fs:items-center fs:gap-3 fs:py-2">
            <span
                className={cn("fs:h-2 fs:w-2 fs:shrink-0 fs:rounded-full", tone.dot)}
                aria-hidden
            />
            <div className="fs:min-w-0 fs:flex-1">
                <div className="fs:flex fs:items-center fs:justify-between fs:gap-2">
                    <span className="fs:truncate fs:text-sm fs:font-medium fs:text-slate-800">
                        {mailerLabel(p.mailer)}
                    </span>
                    <span className={cn("fs:text-xs fs:font-medium", tone.text)}>
                        {signalLabel(p.health)}
                    </span>
                </div>
                <div className="fs:text-xs fs:text-slate-500">
                    {sprintf(
                        __("%1$d sent, %2$d failed (%3$s failure rate)"),
                        p.sent,
                        p.failed,
                        pct(p.failure_rate),
                    )}
                </div>
            </div>
        </div>
    );
}

function DeliverabilityCard({ data }: { data: MonitoringData }) {
    const t = data.totals;
    if (t.total === 0) {
        return (
            <p className="fs:rounded-lg fs:border fs:border-dashed fs:border-slate-200 fs:px-4 fs:py-6 fs:text-center fs:text-sm fs:text-slate-400">
                {__("No emails have been sent in the last 30 days.")}
            </p>
        );
    }

    const tone: Tone =
        t.failure_rate >= 40 ? "error" : t.failure_rate >= 10 ? "warn" : "pass";

    return (
        <div className="fs:space-y-4">
            <div className="fs:grid fs:grid-cols-3 fs:gap-3">
                <Stat label={__("Sent")} value={t.sent.toLocaleString()} />
                <Stat label={__("Failed")} value={t.failed.toLocaleString()} />
                <Stat
                    label={__("Failure rate")}
                    value={pct(t.failure_rate)}
                    tone={tone}
                />
            </div>

            <div className="fs:rounded-lg fs:border fs:border-slate-200">
                <div className="fs:border-b fs:border-slate-100 fs:px-4 fs:py-2 fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                    {__("By provider")}
                </div>
                <div className="fs:divide-y fs:divide-slate-100 fs:px-4">
                    {data.providers.map((p) => (
                        <ProviderRow key={p.mailer} p={p} />
                    ))}
                </div>
            </div>

            <p className="fs:flex fs:items-start fs:gap-1.5 fs:text-xs fs:text-slate-400">
                <Info className="fs:mt-0.5 fs:h-3 fs:w-3 fs:shrink-0" aria-hidden />
                {__(
                    "These are your site's own send results, not the provider's published uptime.",
                )}
            </p>
        </div>
    );
}

function Stat({
    label,
    value,
    tone = "neutral",
}: {
    label: string;
    value: string;
    tone?: Tone;
}) {
    return (
        <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:px-4 fs:py-3">
            <div
                className={cn(
                    "fs:text-xl fs:font-semibold",
                    tone === "neutral"
                        ? "fs:text-slate-900"
                        : TONE_STYLES[tone].text,
                )}
            >
                {value}
            </div>
            <div className="fs:text-xs fs:uppercase fs:tracking-wide fs:text-slate-500">
                {label}
            </div>
        </div>
    );
}

/* --- Question 3: failure causes ---------------------------------------- */

function CausesCard({ data }: { data: MonitoringData }) {
    const setActive = useUiStore((s) => s.setActiveSection);
    const setCategory = useUiStore((s) => s.setLogCategory);
    const cats = data.categories;
    const max = Math.max(1, ...cats.map((c) => c.count));

    if (cats.length === 0) {
        return (
            <p className="fs:rounded-lg fs:border fs:border-dashed fs:border-slate-200 fs:px-4 fs:py-6 fs:text-center fs:text-sm fs:text-slate-400">
                {__("No failures recorded in the last 30 days.")}
            </p>
        );
    }

    const openLogs = (category: string) => {
        setCategory(category);
        setActive("logs");
    };

    return (
        <div className="fs:space-y-2">
            {cats.map((c) => (
                <button
                    key={c.category}
                    type="button"
                    onClick={() => openLogs(c.category)}
                    className="fs:group fs:flex fs:w-full fs:items-center fs:gap-3 fs:rounded-lg fs:px-2 fs:py-1.5 fs:text-left fs:transition-colors fs:hover:bg-slate-50"
                >
                    <span className="fs:w-40 fs:shrink-0 fs:truncate fs:text-sm fs:text-slate-700">
                        {categoryLabel(c.category)}
                    </span>
                    <span className="fs:relative fs:h-2 fs:flex-1 fs:overflow-hidden fs:rounded-full fs:bg-slate-100">
                        <span
                            className="fs:absolute fs:inset-y-0 fs:left-0 fs:rounded-full fs:bg-brand-400"
                            style={{ width: `${(c.count / max) * 100}%` }}
                        />
                    </span>
                    <span className="fs:w-10 fs:shrink-0 fs:text-right fs:text-sm fs:font-medium fs:text-slate-800">
                        {c.count}
                    </span>
                    <ChevronRight
                        className="fs:h-4 fs:w-4 fs:shrink-0 fs:text-slate-300 fs:group-hover:text-brand-400"
                        aria-hidden
                    />
                </button>
            ))}
            <p className="fs:pt-1 fs:text-xs fs:text-slate-400">
                {__("Select a cause to see the matching emails in the log.")}
            </p>
        </div>
    );
}

/* --- Page --------------------------------------------------------------- */

export function OverviewTab() {
    const health = useHealth();
    const runHealth = useRunHealth();
    const monitoring = useMonitoring(MONITOR_DAYS);
    const showToast = useUiStore((s) => s.showToast);
    const { canManageSettings } = getPluginGlobal();

    const onRun = () => {
        runHealth.mutate(undefined, {
            onSuccess: () => showToast(__("Health checks refreshed.")),
            onError: () => showToast(__("Could not run the checks."), "error"),
        });
    };

    return (
        <div className="fs:space-y-5 fs:p-5">
            <QuestionCard
                step={1}
                question={__("Can WordPress send email right now?")}
                action={
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onRun}
                        disabled={runHealth.isPending || !canManageSettings}
                    >
                        <RefreshCw
                            className={cn(
                                "fs:h-4 fs:w-4",
                                runHealth.isPending && "fs:animate-spin",
                            )}
                            aria-hidden
                        />
                        {runHealth.isPending
                            ? __("Checking…")
                            : __("Run checks")}
                    </Button>
                }
            >
                {health.isLoading && (
                    <p className="fs:text-sm fs:text-slate-500">
                        {__("Loading…")}
                    </p>
                )}
                {health.isError && (
                    <p className="fs:rounded-md fs:bg-red-50 fs:p-3 fs:text-sm fs:text-red-700">
                        {__("Failed to load the health report.")}
                    </p>
                )}
                {health.data && <HealthCard report={health.data} />}
            </QuestionCard>

            <QuestionCard
                step={2}
                question={__("Are your emails actually being delivered?")}
            >
                {monitoring.isLoading && (
                    <p className="fs:text-sm fs:text-slate-500">
                        {__("Loading…")}
                    </p>
                )}
                {monitoring.isError && (
                    <p className="fs:rounded-md fs:bg-red-50 fs:p-3 fs:text-sm fs:text-red-700">
                        {__("Failed to load delivery metrics.")}
                    </p>
                )}
                {monitoring.data && <DeliverabilityCard data={monitoring.data} />}
            </QuestionCard>

            <QuestionCard
                step={3}
                question={__("When something fails, what is the cause?")}
            >
                {monitoring.data && <CausesCard data={monitoring.data} />}
            </QuestionCard>
        </div>
    );
}
