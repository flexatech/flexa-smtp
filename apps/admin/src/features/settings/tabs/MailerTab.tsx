import { useQuery } from "@tanstack/react-query";
import {
    AtSign,
    KeyRound,
    Link2,
    Mailbox,
    Send,
    Server,
    ShieldCheck,
    User,
} from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { api } from "@/lib/api";
import { __, sprintf } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import { getPluginGlobal, type FieldDef } from "@/lib/wp";
import { ROW_DIVIDER, SettingRow, ToggleRow } from "../SettingRow";
import {
    fieldLabel,
    mailerLabel,
    OAUTH_MAILERS,
    type TabProps,
} from "../types";

interface OAuthStatus {
    providers: Record<
        string,
        { connected: boolean; scope: string; expires_at: number }
    >;
}

function CredentialField({
    slug,
    name,
    def,
    value,
    onChange,
}: {
    slug: string;
    name: string;
    def: FieldDef;
    value: string | number | boolean | undefined;
    onChange: (value: string | number | boolean) => void;
}) {
    const { secretMask } = getPluginGlobal();
    const id = `fs-cred-${slug}-${name}`;
    const label = fieldLabel(name);

    if (def.type === "bool") {
        return (
            <SettingRow icon={ShieldCheck} title={label} htmlFor={id}>
                <Switch
                    id={id}
                    checked={Boolean(value)}
                    onCheckedChange={onChange}
                />
            </SettingRow>
        );
    }

    if (def.type === "enum" && def.open) {
        // Editable combobox: known values are datalist suggestions, but any typed
        // value is accepted (e.g. a new AWS region the plugin doesn't ship yet).
        const listId = `${id}-list`;
        return (
            <SettingRow icon={Server} title={label} htmlFor={id}>
                <Input
                    id={id}
                    type="text"
                    value={String(value ?? "")}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={def.enum?.[0] ?? ""}
                    list={listId}
                    className="fs:w-64"
                    autoComplete="off"
                />
                <datalist id={listId}>
                    {(def.enum ?? []).map((v) => (
                        <option key={v} value={v} />
                    ))}
                </datalist>
            </SettingRow>
        );
    }

    if (def.type === "enum") {
        return (
            <SettingRow icon={Server} title={label} htmlFor={id}>
                <Select
                    id={id}
                    value={String(value ?? def.enum?.[0] ?? "")}
                    onChange={(e) => onChange(e.target.value)}
                    options={(def.enum ?? []).map((v) => ({
                        value: v,
                        label: v,
                    }))}
                    className="fs:w-48"
                />
            </SettingRow>
        );
    }

    const isSecret = Boolean(def.secret);
    const stored = String(value ?? "");
    const showMask = isSecret && stored === secretMask;

    return (
        <SettingRow icon={isSecret ? KeyRound : Server} title={label} htmlFor={id}>
            <Input
                id={id}
                type={isSecret ? "password" : def.type === "int" ? "number" : "text"}
                value={showMask ? "" : stored}
                placeholder={showMask ? "••••••••" : ""}
                onChange={(e) =>
                    onChange(
                        def.type === "int"
                            ? Number(e.target.value)
                            : e.target.value,
                    )
                }
                className="fs:w-64"
                autoComplete={isSecret ? "new-password" : "off"}
            />
        </SettingRow>
    );
}

function OAuthConnect({ slug }: { slug: string }) {
    const showToast = useUiStore((s) => s.showToast);
    const status = useQuery<OAuthStatus>({
        queryKey: ["oauth", "status"],
        queryFn: () => api.get<OAuthStatus>("oauth/status"),
    });
    const [busy, setBusy] = useState(false);
    const connected = status.data?.providers?.[slug]?.connected ?? false;

    const connect = async () => {
        setBusy(true);
        try {
            const { url } = await api.get<{ url: string }>(
                `oauth/${slug}/authorize`,
            );
            window.location.href = url;
        } catch (e) {
            setBusy(false);
            showToast(
                __("Could not start the connection: ") + (e as Error).message,
                "error",
            );
        }
    };

    const disconnect = async () => {
        setBusy(true);
        try {
            await api.post(`oauth/${slug}/disconnect`);
            await status.refetch();
            showToast(__("Disconnected."));
        } catch (e) {
            showToast(__("Disconnect failed: ") + (e as Error).message, "error");
        } finally {
            setBusy(false);
        }
    };

    return (
        <SettingRow
            icon={Link2}
            title={__("Connection")}
            description={
                connected
                    ? __("This account is connected and ready to send.")
                    : __("Authorize this provider to allow sending mail.")
            }
        >
            <div className="fs:flex fs:items-center fs:gap-3">
                <span
                    className={
                        connected
                            ? "fs:text-xs fs:font-medium fs:text-emerald-700"
                            : "fs:text-xs fs:font-medium fs:text-slate-500"
                    }
                >
                    {connected ? __("Connected") : __("Not connected")}
                </span>
                {connected ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={disconnect}
                        disabled={busy}
                    >
                        {__("Disconnect")}
                    </Button>
                ) : (
                    <Button size="sm" onClick={connect} disabled={busy}>
                        {busy ? __("Redirecting…") : __("Connect")}
                    </Button>
                )}
            </div>
        </SettingRow>
    );
}

type TestResult =
    | { ok: true; mailer: string }
    | { ok: false; error: string };

function SendTestEmail() {
    const showToast = useUiStore((s) => s.showToast);
    const [email, setEmail] = useState("");
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<TestResult | null>(null);

    const send = async () => {
        const to = email.trim();
        if (to === "") {
            showToast(__("Enter a recipient email address first."), "error");
            return;
        }
        setBusy(true);
        setResult(null);
        try {
            // The endpoint returns HTTP 200 with { ok: false, error } on a send
            // failure, so inspect the payload rather than trusting the status.
            const res = await api.post<TestResult>("test-mail", {
                to,
                html: true,
            });
            setResult(res);
            showToast(
                res.ok ? __("Test email sent.") : __("Test email failed."),
                res.ok ? "success" : "error",
            );
        } catch (e) {
            const message = (e as Error).message;
            setResult({ ok: false, error: message });
            showToast(__("Test email failed: ") + message, "error");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="fs:border-t fs:border-slate-100">
            <div className="fs:bg-slate-50/60 fs:px-5 fs:py-2 fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                {__("Send a test email")}
            </div>
            <SettingRow
                icon={Send}
                title={__("Test recipient")}
                description={__(
                    "Sends through your saved mailer settings. Save any changes first.",
                )}
                htmlFor="fs-test-email"
            >
                <div className="fs:flex fs:items-center fs:gap-2">
                    <Input
                        id="fs-test-email"
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === "Enter" && !busy) {
                                send();
                            }
                        }}
                        placeholder="you@example.com"
                        className="fs:w-56"
                        autoComplete="off"
                    />
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={send}
                        disabled={busy}
                        className="fs:gap-2"
                    >
                        <Send className="fs:h-4 fs:w-4" aria-hidden />
                        {busy ? __("Sending…") : __("Send test")}
                    </Button>
                </div>
            </SettingRow>
            {result && (
                <div className="fs:px-5 fs:pb-4">
                    {result.ok ? (
                        <p className="fs:rounded-md fs:bg-emerald-50 fs:px-3 fs:py-2 fs:text-xs fs:text-emerald-700">
                            {sprintf(
                                __("Sent through %s. Check the inbox."),
                                mailerLabel(result.mailer),
                            )}
                        </p>
                    ) : (
                        <p className="fs:rounded-md fs:bg-red-50 fs:px-3 fs:py-2 fs:text-xs fs:text-red-700">
                            {result.error}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

export function MailerTab({ form, setField, setMailerField }: TabProps) {
    const { schema } = getPluginGlobal();
    const mailerSlugs = Object.keys(schema);
    const mailerOptions = mailerSlugs.map((s) => ({
        value: s,
        label: mailerLabel(s),
    }));

    const active = form.current_mailer;
    const activeFields: Record<string, FieldDef> = schema[active] ?? {};
    const isOAuth = OAUTH_MAILERS.has(active);

    return (
        <div>
            <div className={ROW_DIVIDER}>
                <SettingRow
                    icon={AtSign}
                    title={__("From Email")}
                    description={__("The address outgoing mail is sent from.")}
                    htmlFor="fs-from-email"
                >
                    <Input
                        id="fs-from-email"
                        type="email"
                        value={form.from_email}
                        onChange={(e) => setField("from_email", e.target.value)}
                        placeholder="you@example.com"
                        className="fs:w-64"
                    />
                </SettingRow>
                <ToggleRow
                    icon={ShieldCheck}
                    title={__("Force From Email")}
                    description={__(
                        "Use the address above even if a plugin sets its own.",
                    )}
                    checked={form.force_from_email}
                    onChange={(v) => setField("force_from_email", v)}
                />
                <SettingRow
                    icon={User}
                    title={__("From Name")}
                    description={__("The sender name shown to recipients.")}
                    htmlFor="fs-from-name"
                >
                    <Input
                        id="fs-from-name"
                        value={form.from_name}
                        onChange={(e) => setField("from_name", e.target.value)}
                        placeholder="Acme Inc."
                        className="fs:w-64"
                    />
                </SettingRow>
                <ToggleRow
                    icon={ShieldCheck}
                    title={__("Force From Name")}
                    description={__(
                        "Use the name above even if a plugin sets its own.",
                    )}
                    checked={form.force_from_name}
                    onChange={(v) => setField("force_from_name", v)}
                />
            </div>

            <div className="fs:border-t fs:border-slate-100">
                <SettingRow
                    icon={Mailbox}
                    title={__("Mailer")}
                    description={__("The service that delivers your email.")}
                    htmlFor="fs-current-mailer"
                >
                    <Select
                        id="fs-current-mailer"
                        value={active}
                        onChange={(e) => setField("current_mailer", e.target.value)}
                        options={mailerOptions}
                        className="fs:w-64"
                    />
                </SettingRow>
            </div>

            {(Object.keys(activeFields).length > 0 || isOAuth) && (
                <div className="fs:border-t fs:border-slate-100">
                    <div className="fs:bg-slate-50/60 fs:px-5 fs:py-2 fs:text-xs fs:font-semibold fs:uppercase fs:tracking-wide fs:text-slate-500">
                        {mailerLabel(active)}
                    </div>
                    <div className={ROW_DIVIDER}>
                        {Object.entries(activeFields).map(([name, def]) => (
                            <CredentialField
                                key={name}
                                slug={active}
                                name={name}
                                def={def}
                                value={form.mailers[active]?.[name]}
                                onChange={(v) => setMailerField(active, name, v)}
                            />
                        ))}
                        {isOAuth && <OAuthConnect slug={active} />}
                    </div>
                </div>
            )}

            <div className="fs:border-t fs:border-slate-100">
                <ToggleRow
                    icon={Server}
                    title={__("Enable Fallback")}
                    description={__(
                        "Try a second mailer if the primary one fails.",
                    )}
                    checked={form.fallback_enabled}
                    onChange={(v) => setField("fallback_enabled", v)}
                />
                {form.fallback_enabled && (
                    <div className="fs:border-t fs:border-slate-100">
                        <SettingRow
                            icon={Mailbox}
                            title={__("Fallback Mailer")}
                            description={__("Used only when the primary fails.")}
                            htmlFor="fs-fallback-mailer"
                        >
                            <Select
                                id="fs-fallback-mailer"
                                value={form.fallback_mailer}
                                onChange={(e) =>
                                    setField("fallback_mailer", e.target.value)
                                }
                                options={[
                                    { value: "", label: __("— None —") },
                                    ...mailerOptions.filter(
                                        (o) => o.value !== active,
                                    ),
                                ]}
                                className="fs:w-64"
                            />
                        </SettingRow>
                    </div>
                )}
            </div>

            <SendTestEmail />
        </div>
    );
}
