import {
    ClipboardList,
    Clock,
    Download,
    Eye,
    MousePointerClick,
    Search,
} from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { cn } from "@/lib/cn";
import { __, sprintf } from "@/lib/i18n";
import { getPluginGlobal } from "@/lib/wp";
import { useLogDetail, useLogs } from "@/features/logs/useLogs";
import { ROW_DIVIDER, SettingRow, ToggleRow } from "../SettingRow";
import { mailerLabel, type TabProps } from "../types";

const PER_PAGE = 20;

function StatusBadge({ status }: { status: number }) {
    const map: Record<number, { label: string; cls: string }> = {
        0: { label: __("Failed"), cls: "fs:bg-red-50 fs:text-red-700 fs:ring-red-200" },
        1: {
            label: __("Sent"),
            cls: "fs:bg-emerald-50 fs:text-emerald-700 fs:ring-emerald-200",
        },
        2: {
            label: __("Pending"),
            cls: "fs:bg-amber-50 fs:text-amber-700 fs:ring-amber-200",
        },
    };
    const s = map[status] ?? map[0];
    return (
        <span
            className={cn(
                "fs:inline-flex fs:items-center fs:rounded-full fs:px-2 fs:py-0.5 fs:text-xs fs:font-medium fs:ring-1",
                s.cls,
            )}
        >
            {s.label}
        </span>
    );
}

function LogDetailDialog({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const detail = useLogDetail(id);
    const d = detail.data;
    return (
        <Dialog open={id !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="fs:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{d?.subject || __("Email details")}</DialogTitle>
                    <DialogDescription>
                        {d ? `${d.from} · ${d.date_time}` : __("Loading…")}
                    </DialogDescription>
                </DialogHeader>

                {detail.isLoading && (
                    <p className="fs:text-sm fs:text-slate-500">
                        {__("Loading…")}
                    </p>
                )}

                {d && (
                    <div className="fs:max-h-[60vh] fs:space-y-4 fs:overflow-y-auto fs:text-sm">
                        <div className="fs:flex fs:flex-wrap fs:items-center fs:gap-3">
                            <StatusBadge status={d.status} />
                            <span className="fs:text-slate-500">
                                {mailerLabel(d.mailer)}
                            </span>
                            {d.source && (
                                <span className="fs:text-slate-400">
                                    {__("via")} {d.source}
                                </span>
                            )}
                        </div>

                        <div>
                            <div className="fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                                {__("Recipients")}
                            </div>
                            <div className="fs:mt-1 fs:text-slate-700">
                                {d.to
                                    .map((r) =>
                                        r.name
                                            ? `${r.name} <${r.address}>`
                                            : r.address,
                                    )
                                    .join(", ") || "—"}
                            </div>
                        </div>

                        {d.status === 0 && d.reason_error && (
                            <div className="fs:rounded-md fs:bg-red-50 fs:p-3 fs:text-red-700 fs:ring-1 fs:ring-red-200">
                                {d.reason_error}
                            </div>
                        )}

                        <div className="fs:flex fs:gap-6">
                            <span className="fs:inline-flex fs:items-center fs:gap-1.5 fs:text-slate-600">
                                <Eye className="fs:h-4 fs:w-4" aria-hidden />
                                {sprintf(__("%d opens"), d.opens)}
                            </span>
                            <span className="fs:inline-flex fs:items-center fs:gap-1.5 fs:text-slate-600">
                                <MousePointerClick
                                    className="fs:h-4 fs:w-4"
                                    aria-hidden
                                />
                                {sprintf(
                                    __("%d links clicked"),
                                    d.clicks.length,
                                )}
                            </span>
                        </div>

                        {d.clicks.length > 0 && (
                            <div className="fs:space-y-1">
                                {d.clicks.map((c) => (
                                    <div
                                        key={c.url}
                                        className="fs:flex fs:items-center fs:justify-between fs:gap-3 fs:rounded fs:bg-slate-50 fs:px-2 fs:py-1"
                                    >
                                        <span className="fs:truncate fs:text-slate-600">
                                            {c.url}
                                        </span>
                                        <span className="fs:shrink-0 fs:text-xs fs:text-slate-400">
                                            {sprintf(__("%d×"), c.count)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div>
                            <div className="fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                                {__("Body")}
                            </div>
                            <pre className="fs:mt-1 fs:max-h-64 fs:overflow-auto fs:whitespace-pre-wrap fs:break-words fs:rounded-md fs:bg-slate-50 fs:p-3 fs:text-xs fs:text-slate-700">
                                {d.body_content || "—"}
                            </pre>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function LogsTab({ form, setField }: TabProps) {
    const { schema, restUrl, restNonce } = getPluginGlobal();
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [mailer, setMailer] = useState("");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<number | null>(null);

    const logs = useLogs({ page, per_page: PER_PAGE, search, status, mailer });
    const total = logs.data?.total ?? 0;
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));

    const mailerOptions = [
        { value: "", label: __("All mailers") },
        ...Object.keys(schema).map((s) => ({ value: s, label: mailerLabel(s) })),
    ];

    const exportUrl = () => {
        const p = new URLSearchParams();
        if (search) p.set("search", search);
        if (status !== "") p.set("status", status);
        if (mailer) p.set("mailer", mailer);
        p.set("_wpnonce", restNonce);
        return `${restUrl.replace(/\/$/, "")}/logs/export?${p.toString()}`;
    };

    return (
        <div>
            <div className={ROW_DIVIDER}>
                <ToggleRow
                    icon={ClipboardList}
                    title={__("Enable Email Log")}
                    description={__(
                        "Record every email WordPress sends through this site.",
                    )}
                    checked={form.enable_email_log}
                    onChange={(v) => setField("enable_email_log", v)}
                />
                <SettingRow
                    icon={Clock}
                    title={__("Log Retention (days)")}
                    description={__(
                        "Automatically delete logs older than this. 0 keeps them forever.",
                    )}
                    htmlFor="fs-retention"
                >
                    <Input
                        id="fs-retention"
                        type="number"
                        min={0}
                        value={form.log_retention_days}
                        onChange={(e) =>
                            setField(
                                "log_retention_days",
                                Math.max(0, Number(e.target.value)),
                            )
                        }
                        className="fs:w-24"
                    />
                </SettingRow>
            </div>

            {/* Log browser */}
            <div className="fs:border-t fs:border-slate-100 fs:p-5">
                <div className="fs:mb-4 fs:flex fs:flex-wrap fs:items-center fs:gap-2">
                    <div className="fs:relative fs:flex-1 fs:min-w-48">
                        <Search
                            className="fs:pointer-events-none fs:absolute fs:left-2.5 fs:top-1/2 fs:h-4 fs:w-4 fs:-translate-y-1/2 fs:text-slate-400"
                            aria-hidden
                        />
                        <Input
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setPage(1);
                            }}
                            placeholder={__("Search subject, from, or to…")}
                            className="fs:pl-8"
                        />
                    </div>
                    <Select
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}
                        options={[
                            { value: "", label: __("All statuses") },
                            { value: "1", label: __("Sent") },
                            { value: "0", label: __("Failed") },
                        ]}
                    />
                    <Select
                        value={mailer}
                        onChange={(e) => {
                            setMailer(e.target.value);
                            setPage(1);
                        }}
                        options={mailerOptions}
                    />
                    <Button variant="outline" asChild>
                        <a href={exportUrl()}>
                            <Download className="fs:h-4 fs:w-4" aria-hidden />
                            {__("Export CSV")}
                        </a>
                    </Button>
                </div>

                <div className="fs:overflow-hidden fs:rounded-lg fs:border fs:border-slate-200">
                    <table className="fs:w-full fs:text-left fs:text-sm">
                        <thead className="fs:bg-slate-50 fs:text-xs fs:uppercase fs:tracking-wide fs:text-slate-500">
                            <tr>
                                <th className="fs:px-3 fs:py-2 fs:font-medium">
                                    {__("Status")}
                                </th>
                                <th className="fs:px-3 fs:py-2 fs:font-medium">
                                    {__("Subject")}
                                </th>
                                <th className="fs:px-3 fs:py-2 fs:font-medium">
                                    {__("To")}
                                </th>
                                <th className="fs:px-3 fs:py-2 fs:font-medium">
                                    {__("Mailer")}
                                </th>
                                <th className="fs:px-3 fs:py-2 fs:font-medium">
                                    {__("Date")}
                                </th>
                                <th className="fs:px-3 fs:py-2" />
                            </tr>
                        </thead>
                        <tbody className="fs:divide-y fs:divide-slate-100">
                            {logs.isLoading && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="fs:px-3 fs:py-8 fs:text-center fs:text-slate-400"
                                    >
                                        {__("Loading…")}
                                    </td>
                                </tr>
                            )}
                            {logs.data && logs.data.items.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="fs:px-3 fs:py-8 fs:text-center fs:text-slate-400"
                                    >
                                        {__("No emails logged yet.")}
                                    </td>
                                </tr>
                            )}
                            {logs.data?.items.map((log) => (
                                <tr
                                    key={log.id}
                                    className="fs:hover:bg-slate-50"
                                >
                                    <td className="fs:px-3 fs:py-2">
                                        <StatusBadge status={log.status} />
                                    </td>
                                    <td className="fs:px-3 fs:py-2 fs:max-w-xs fs:truncate fs:text-slate-800">
                                        {log.subject || "—"}
                                    </td>
                                    <td className="fs:px-3 fs:py-2 fs:text-slate-600">
                                        {log.to[0]?.address ?? "—"}
                                        {log.to.length > 1 &&
                                            ` +${log.to.length - 1}`}
                                    </td>
                                    <td className="fs:px-3 fs:py-2 fs:text-slate-600">
                                        {mailerLabel(log.mailer)}
                                    </td>
                                    <td className="fs:px-3 fs:py-2 fs:whitespace-nowrap fs:text-slate-500">
                                        {log.date_time}
                                    </td>
                                    <td className="fs:px-3 fs:py-2 fs:text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setSelected(log.id)}
                                        >
                                            {__("View")}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="fs:mt-3 fs:flex fs:items-center fs:justify-between fs:text-xs fs:text-slate-500">
                    <span>
                        {sprintf(__("%d emails"), total)}
                    </span>
                    <div className="fs:flex fs:items-center fs:gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={page <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                        >
                            {__("Previous")}
                        </Button>
                        <span>
                            {sprintf(__("Page %1$d of %2$d"), page, pages)}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={page >= pages}
                            onClick={() => setPage((p) => Math.min(pages, p + 1))}
                        >
                            {__("Next")}
                        </Button>
                    </div>
                </div>
            </div>

            <LogDetailDialog id={selected} onClose={() => setSelected(null)} />
        </div>
    );
}
