import { CalendarDays, Mail, Send } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/cn";
import { __, sprintf } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import {
    type ReportData,
    type ReportPoint,
    useReports,
    useSendDigest,
} from "@/features/reports/useReports";
import { ROW_DIVIDER, SettingRow, ToggleRow } from "../SettingRow";
import { mailerLabel, type TabProps } from "../types";

const RANGES = [7, 30, 90];
const OPENS_COLOR = "#10b981";
const CLICKS_COLOR = "#f59e0b";

function StatCard({ label, value }: { label: string; value: string }) {
    return (
        <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:px-4 fs:py-3">
            <div className="fs:text-xl fs:font-semibold fs:text-slate-900">
                {value}
            </div>
            <div className="fs:text-xs fs:uppercase fs:tracking-wide fs:text-slate-500">
                {label}
            </div>
        </div>
    );
}

function Chart({ series }: { series: ReportPoint[] }) {
    const W = 640;
    const H = 180;
    const pad = { t: 12, r: 8, b: 4, l: 8 };
    const chartW = W - pad.l - pad.r;
    const chartH = H - pad.t - pad.b;
    const n = Math.max(1, series.length);
    const max = Math.max(
        1,
        ...series.map((p) => Math.max(p.sent, p.opens, p.clicks)),
    );
    const bw = chartW / n;
    const baseline = pad.t + chartH;
    const y = (v: number) => pad.t + chartH - (v / max) * chartH;
    const cx = (i: number) => pad.l + i * bw + bw / 2;

    const line = (key: "opens" | "clicks") =>
        series.map((p, i) => `${cx(i)},${y(p[key])}`).join(" ");

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            className="fs:h-44 fs:w-full"
            preserveAspectRatio="none"
            role="img"
            aria-label={__("Daily email activity")}
        >
            <line
                x1={pad.l}
                y1={baseline}
                x2={W - pad.r}
                y2={baseline}
                stroke="#e2e8f0"
                strokeWidth={1}
            />
            {series.map((p, i) => {
                const h = (p.sent / max) * chartH;
                return (
                    <rect
                        key={p.date}
                        x={pad.l + i * bw + bw * 0.2}
                        y={baseline - h}
                        width={Math.max(1, bw * 0.6)}
                        height={h}
                        rx={1}
                        fill="var(--fs-color-brand-500)"
                        opacity={0.85}
                    >
                        <title>{`${p.date}: ${p.sent} sent`}</title>
                    </rect>
                );
            })}
            {series.length > 1 && (
                <>
                    <polyline
                        points={line("opens")}
                        fill="none"
                        stroke={OPENS_COLOR}
                        strokeWidth={1.75}
                    />
                    <polyline
                        points={line("clicks")}
                        fill="none"
                        stroke={CLICKS_COLOR}
                        strokeWidth={1.75}
                    />
                </>
            )}
        </svg>
    );
}

function Legend() {
    const item = (color: string, label: string) => (
        <span className="fs:inline-flex fs:items-center fs:gap-1.5">
            <span
                className="fs:inline-block fs:h-2.5 fs:w-2.5 fs:rounded-sm"
                style={{ backgroundColor: color }}
            />
            <span className="fs:text-xs fs:text-slate-500">{label}</span>
        </span>
    );
    return (
        <div className="fs:flex fs:items-center fs:gap-4">
            {item("var(--fs-color-brand-500)", __("Sent"))}
            {item(OPENS_COLOR, __("Opens"))}
            {item(CLICKS_COLOR, __("Clicks"))}
        </div>
    );
}

function num(n: number): string {
    return n.toLocaleString();
}

function ReportBody({ data }: { data: ReportData }) {
    const t = data.totals;
    return (
        <div className="fs:space-y-5">
            <div className="fs:grid fs:grid-cols-2 fs:gap-3 fs:sm:grid-cols-3 fs:lg:grid-cols-6">
                <StatCard label={__("Sent")} value={num(t.sent)} />
                <StatCard label={__("Failed")} value={num(t.failed)} />
                <StatCard label={__("Opens")} value={num(t.opens)} />
                <StatCard label={__("Clicks")} value={num(t.clicks)} />
                <StatCard label={__("Open rate")} value={`${t.open_rate}%`} />
                <StatCard label={__("Click rate")} value={`${t.click_rate}%`} />
            </div>

            <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:p-4">
                <div className="fs:mb-2 fs:flex fs:items-center fs:justify-between">
                    <span className="fs:text-sm fs:font-medium fs:text-slate-700">
                        {__("Daily activity")}
                    </span>
                    <Legend />
                </div>
                <Chart series={data.series} />
            </div>

            <div className="fs:grid fs:gap-4 fs:md:grid-cols-2">
                <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:p-4">
                    <h3 className="fs:mb-2 fs:text-sm fs:font-semibold fs:text-slate-800">
                        {__("By mailer")}
                    </h3>
                    {data.mailers.length === 0 ? (
                        <p className="fs:text-xs fs:text-slate-400">
                            {__("No sends in this period.")}
                        </p>
                    ) : (
                        <ul className="fs:space-y-1">
                            {data.mailers.map((m) => (
                                <li
                                    key={m.mailer}
                                    className="fs:flex fs:justify-between fs:text-sm"
                                >
                                    <span className="fs:text-slate-600">
                                        {mailerLabel(m.mailer)}
                                    </span>
                                    <span className="fs:font-medium fs:text-slate-800">
                                        {num(m.count)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:p-4">
                    <h3 className="fs:mb-2 fs:text-sm fs:font-semibold fs:text-slate-800">
                        {__("Top links")}
                    </h3>
                    {data.top_links.length === 0 ? (
                        <p className="fs:text-xs fs:text-slate-400">
                            {__("No clicks in this period.")}
                        </p>
                    ) : (
                        <ul className="fs:space-y-1">
                            {data.top_links.map((l) => (
                                <li
                                    key={l.url}
                                    className="fs:flex fs:justify-between fs:gap-3 fs:text-sm"
                                >
                                    <span className="fs:truncate fs:text-slate-600">
                                        {l.url}
                                    </span>
                                    <span className="fs:shrink-0 fs:font-medium fs:text-slate-800">
                                        {num(l.clicks)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

export function ReportsTab({ form, setField }: TabProps) {
    const [days, setDays] = useState(30);
    const report = useReports(days);
    const sendDigest = useSendDigest();
    const showToast = useUiStore((s) => s.showToast);

    const onSendTest = () => {
        sendDigest.mutate(days, {
            onSuccess: (res) =>
                res.sent
                    ? showToast(
                          sprintf(
                              __("Test digest sent to %s."),
                              res.recipients.join(", "),
                          ),
                      )
                    : showToast(__("No recipients configured."), "error"),
            onError: () => showToast(__("Could not send digest."), "error"),
        });
    };

    return (
        <div>
            <div className="fs:p-5">
                <div className="fs:mb-4 fs:flex fs:items-center fs:gap-2">
                    <CalendarDays
                        className="fs:h-4 fs:w-4 fs:text-slate-400"
                        aria-hidden
                    />
                    {RANGES.map((r) => (
                        <button
                            key={r}
                            type="button"
                            onClick={() => setDays(r)}
                            className={cn(
                                "fs:rounded-md fs:px-3 fs:py-1 fs:text-xs fs:font-medium fs:transition-colors",
                                days === r
                                    ? "fs:bg-brand-500 fs:text-white"
                                    : "fs:bg-slate-100 fs:text-slate-600 fs:hover:bg-slate-200",
                            )}
                        >
                            {sprintf(__("%d days"), r)}
                        </button>
                    ))}
                </div>

                {report.isLoading && (
                    <p className="fs:text-sm fs:text-slate-500">
                        {__("Loading report…")}
                    </p>
                )}
                {report.isError && (
                    <p className="fs:rounded-md fs:bg-red-50 fs:p-3 fs:text-sm fs:text-red-700">
                        {__("Failed to load report.")}
                    </p>
                )}
                {report.data && <ReportBody data={report.data} />}
            </div>

            <div className="fs:border-t fs:border-slate-100">
                <div className="fs:bg-slate-50/60 fs:px-5 fs:py-2 fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                    {__("Scheduled digests")}
                </div>
                <div className={ROW_DIVIDER}>
                    <ToggleRow
                        icon={CalendarDays}
                        title={__("Weekly report")}
                        description={__("Email a summary once a week.")}
                        checked={form.enable_weekly_report}
                        onChange={(v) => setField("enable_weekly_report", v)}
                    />
                    <ToggleRow
                        icon={CalendarDays}
                        title={__("Monthly report")}
                        description={__("Email a summary once a month.")}
                        checked={form.enable_monthly_report}
                        onChange={(v) => setField("enable_monthly_report", v)}
                    />
                    <SettingRow
                        icon={Mail}
                        title={__("Recipients")}
                        description={__(
                            "Comma-separated email addresses. Defaults to the site admin.",
                        )}
                        htmlFor="fs-report-recipients"
                    >
                        <Input
                            id="fs-report-recipients"
                            value={form.report_recipients}
                            onChange={(e) =>
                                setField("report_recipients", e.target.value)
                            }
                            placeholder="admin@example.com"
                            className="fs:w-72"
                        />
                    </SettingRow>
                    <SettingRow
                        icon={Send}
                        title={__("Send a test digest")}
                        description={__(
                            "Send the current period's report to the recipients now.",
                        )}
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onSendTest}
                            disabled={sendDigest.isPending}
                        >
                            <Send className="fs:h-4 fs:w-4" aria-hidden />
                            {sendDigest.isPending
                                ? __("Sending…")
                                : __("Send now")}
                        </Button>
                    </SettingRow>
                </div>
            </div>
        </div>
    );
}
