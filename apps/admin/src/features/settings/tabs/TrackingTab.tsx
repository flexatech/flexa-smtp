import { Eye, MousePointerClick } from "lucide-react";
import { __ } from "@/lib/i18n";
import { ROW_DIVIDER, ToggleRow } from "../SettingRow";
import { type TabProps } from "../types";

export function TrackingTab({ form, setField }: TabProps) {
    const loggingOff = !form.enable_email_log;
    return (
        <div>
            {loggingOff && (
                <div className="fs:m-5 fs:rounded-md fs:bg-amber-50 fs:px-4 fs:py-3 fs:text-xs fs:text-amber-800 fs:ring-1 fs:ring-amber-200">
                    {__(
                        "Email logging is off. Tracking needs a log entry to attach opens and clicks to, so enable logging on the Email Logs tab first.",
                    )}
                </div>
            )}
            <div className={ROW_DIVIDER}>
                <ToggleRow
                    icon={Eye}
                    title={__("Open Tracking")}
                    description={__(
                        "Add an invisible pixel to HTML emails to record when they are opened.",
                    )}
                    checked={form.enable_open_tracking}
                    onChange={(v) => setField("enable_open_tracking", v)}
                    disabled={loggingOff}
                />
                <ToggleRow
                    icon={MousePointerClick}
                    title={__("Click Tracking")}
                    description={__(
                        "Rewrite links in HTML emails to record which ones recipients click.",
                    )}
                    checked={form.enable_click_tracking}
                    onChange={(v) => setField("enable_click_tracking", v)}
                    disabled={loggingOff}
                />
            </div>
        </div>
    );
}
