import { BarChart3 } from "lucide-react";
import { __ } from "@/lib/i18n";

/**
 * Placeholder until WP7 (Reports) lands its aggregation endpoint. The tab
 * exists now so the navigation matches the final shape; the charts wire up
 * once `GET /reports` is available.
 */
export function ReportsTab() {
    return (
        <div className="fs:flex fs:flex-col fs:items-center fs:justify-center fs:gap-3 fs:px-6 fs:py-16 fs:text-center">
            <span className="fs:flex fs:h-12 fs:w-12 fs:items-center fs:justify-center fs:rounded-full fs:bg-slate-100 fs:text-slate-400">
                <BarChart3 className="fs:h-6 fs:w-6" aria-hidden />
            </span>
            <h3 className="fs:text-sm fs:font-semibold fs:text-slate-800">
                {__("Reports are coming soon")}
            </h3>
            <p className="fs:max-w-sm fs:text-xs fs:text-slate-500">
                {__(
                    "Delivery, open, and click trends will appear here once the reporting module is enabled.",
                )}
            </p>
        </div>
    );
}
