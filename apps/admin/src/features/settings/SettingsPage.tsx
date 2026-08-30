import {
    BarChart3,
    ClipboardList,
    Mail,
    Mailbox,
    MousePointerClick,
    Save,
    ShieldAlert,
    SlidersHorizontal,
} from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { __ } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import { getPluginGlobal } from "@/lib/wp";
import { DangerZone } from "./DangerZone";
import { NavItem, PaneHeader, type SectionMeta } from "./SettingRow";
import { MailerTab } from "./tabs/MailerTab";
import { LogsTab } from "./tabs/LogsTab";
import { TrackingTab } from "./tabs/TrackingTab";
import { ReportsTab } from "./tabs/ReportsTab";
import { AdditionalTab } from "./tabs/AdditionalTab";
import {
    type MailerValues,
    type SettingsData,
    useSaveSettings,
    useSettings,
} from "./useSettings";

const SCALAR_KEYS: Array<keyof SettingsData> = [
    "current_mailer",
    "from_email",
    "from_name",
    "force_from_email",
    "force_from_name",
    "disable_delivery",
    "fallback_enabled",
    "fallback_mailer",
    "enable_email_log",
    "log_retention_days",
    "enable_open_tracking",
    "enable_click_tracking",
    "enable_weekly_report",
    "enable_monthly_report",
    "report_recipients",
];

function diffSettings(
    form: SettingsData,
    base: SettingsData,
): Partial<SettingsData> {
    const out: Record<string, unknown> = {};
    for (const k of SCALAR_KEYS) {
        if (form[k] !== base[k]) {
            out[k] = form[k];
        }
    }
    const mailers: Record<string, MailerValues> = {};
    for (const slug of Object.keys(form.mailers)) {
        if (
            JSON.stringify(form.mailers[slug]) !==
            JSON.stringify(base.mailers[slug] ?? {})
        ) {
            mailers[slug] = form.mailers[slug];
        }
    }
    if (Object.keys(mailers).length > 0) {
        out.mailers = mailers;
    }
    return out as Partial<SettingsData>;
}

const SECTIONS: SectionMeta[] = [
    {
        id: "mailer",
        title: __("Mailer"),
        subtitle: __("Sender and delivery service"),
        icon: Mailbox,
        paneTitle: __("Mailer Settings"),
        paneSubtitle: __("Choose how your site sends email and who it's from."),
    },
    {
        id: "logs",
        title: __("Email Logs"),
        subtitle: __("Every message sent"),
        icon: ClipboardList,
        paneTitle: __("Email Logs"),
        paneSubtitle: __("Browse, search, and export logged emails."),
    },
    {
        id: "tracking",
        title: __("Tracking"),
        subtitle: __("Opens and clicks"),
        icon: MousePointerClick,
        paneTitle: __("Tracking"),
        paneSubtitle: __("Measure how recipients engage with your emails."),
    },
    {
        id: "reports",
        title: __("Reports"),
        subtitle: __("Delivery trends"),
        icon: BarChart3,
        paneTitle: __("Reports"),
        paneSubtitle: __("Charts and summaries of your email activity."),
    },
    {
        id: "additional",
        title: __("Additional"),
        subtitle: __("Developer options"),
        icon: SlidersHorizontal,
        paneTitle: __("Additional Settings"),
        paneSubtitle: __("Extra controls for testing and development."),
    },
    {
        id: "danger",
        title: __("Danger Zone"),
        subtitle: __("Reset all data"),
        icon: ShieldAlert,
        paneTitle: __("Danger Zone"),
        paneSubtitle: __("Irreversible actions — proceed with care."),
    },
];

export function SettingsPage() {
    const settings = useSettings();
    const save = useSaveSettings();
    const { canManageSettings } = getPluginGlobal();
    const showToast = useUiStore((s) => s.showToast);
    const active = useUiStore((s) => s.activeSection);
    const setActive = useUiStore((s) => s.setActiveSection);

    const [form, setForm] = useState<SettingsData | null>(null);
    useEffect(() => {
        if (settings.data && !form) {
            setForm(settings.data);
        }
    }, [settings.data, form]);

    if (settings.isLoading || !form) {
        return (
            <div className="fs:p-6 fs:text-sm fs:text-slate-500">
                {__("Loading settings…")}
            </div>
        );
    }
    if (settings.isError) {
        return (
            <div className="fs:m-6 fs:rounded-md fs:bg-red-50 fs:p-4 fs:text-sm fs:text-red-700">
                {__("Failed to load settings:")}{" "}
                {(settings.error as Error).message}
            </div>
        );
    }

    const base = settings.data as SettingsData;
    const changed = diffSettings(form, base);
    const dirty = Object.keys(changed).length > 0;

    const setField = <K extends keyof SettingsData>(
        key: K,
        value: SettingsData[K],
    ) => setForm({ ...form, [key]: value });

    const setMailerField = (
        slug: string,
        field: string,
        value: string | number | boolean,
    ) =>
        setForm({
            ...form,
            mailers: {
                ...form.mailers,
                [slug]: { ...(form.mailers[slug] ?? {}), [field]: value },
            },
        });

    const onSave = () => {
        if (!dirty) {
            return;
        }
        save.mutate(changed, {
            onSuccess: (next) => {
                setForm(next);
                showToast(__("Settings saved."));
            },
            onError: () => showToast(__("Save failed."), "error"),
        });
    };

    const activeSection =
        SECTIONS.find((s) => s.id === active) ?? SECTIONS[0];
    const tabProps = { form, setField, setMailerField };
    const showSave = active !== "danger";

    return (
        <div className="fs:min-h-full fs:bg-slate-50">
            {/* Brand strip */}
            <div className="fs:border-b fs:border-slate-200 fs:bg-white">
                <div className="fs:mx-auto fs:flex fs:max-w-6xl fs:items-center fs:gap-4 fs:px-6 fs:py-3">
                    <span className="fs:flex fs:h-10 fs:w-10 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-lg fs:bg-brand-500 fs:text-white fs:shadow-sm">
                        <Mail className="fs:h-5 fs:w-5" aria-hidden />
                    </span>
                    <div className="fs:leading-tight">
                        <div className="fs:text-sm fs:font-semibold fs:text-slate-900">
                            {__("Flexa SMTP")}
                        </div>
                        <div className="fs:text-xs fs:text-slate-500">
                            {__("for WordPress")}
                        </div>
                    </div>
                </div>
            </div>

            {/* Page header */}
            <div className="fs:mx-auto fs:flex fs:max-w-6xl fs:flex-wrap fs:items-start fs:justify-between fs:gap-4 fs:px-6 fs:pt-8 fs:pb-6">
                <div className="fs:space-y-1">
                    <h1 className="fs:text-3xl fs:font-bold fs:text-slate-900">
                        {__("Settings")}
                    </h1>
                    <p className="fs:text-sm fs:text-slate-600">
                        {__(
                            "Configure how WordPress sends, logs, and tracks email.",
                        )}
                    </p>
                </div>
                {showSave && (
                    <div className="fs:flex fs:items-center fs:gap-3">
                        {save.isSuccess && !dirty && (
                            <span className="fs:text-sm fs:text-emerald-700">
                                {__("Saved.")}
                            </span>
                        )}
                        {save.isError && (
                            <span className="fs:text-sm fs:text-red-700">
                                {__("Save failed.")}
                            </span>
                        )}
                        <Button
                            onClick={onSave}
                            disabled={!dirty || save.isPending || !canManageSettings}
                            className="fs:gap-2"
                        >
                            <Save className="fs:h-4 fs:w-4" aria-hidden />
                            {save.isPending ? __("Saving…") : __("Save Settings")}
                        </Button>
                    </div>
                )}
            </div>

            {/* Body: nav + pane */}
            <div className="fs:mx-auto fs:flex fs:max-w-6xl fs:flex-col fs:gap-6 fs:px-6 fs:pb-12 fs:md:flex-row">
                <aside className="fs:w-full fs:shrink-0 fs:space-y-2 fs:md:w-72">
                    {SECTIONS.map((s) => (
                        <NavItem
                            key={s.id}
                            section={s}
                            selected={active === s.id}
                            onSelect={() => setActive(s.id)}
                        />
                    ))}
                </aside>

                <main className="fs:flex-1">
                    <div className="fs:overflow-hidden fs:rounded-xl fs:border fs:border-slate-200 fs:bg-white fs:shadow-sm">
                        <PaneHeader section={activeSection} />
                        {active === "mailer" && <MailerTab {...tabProps} />}
                        {active === "logs" && <LogsTab {...tabProps} />}
                        {active === "tracking" && <TrackingTab {...tabProps} />}
                        {active === "reports" && <ReportsTab {...tabProps} />}
                        {active === "additional" && <AdditionalTab {...tabProps} />}
                        {active === "danger" && <DangerZone />}
                    </div>
                </main>
            </div>
        </div>
    );
}
