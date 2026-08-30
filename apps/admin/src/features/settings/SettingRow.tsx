import { ChevronRight, type LucideIcon } from "lucide-react";
import { type ReactNode } from "react";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/cn";

export const ROW_DIVIDER = "fs:divide-y fs:divide-slate-100";

export interface SectionMeta {
    id: string;
    title: string;
    subtitle: string;
    icon: LucideIcon;
    paneTitle: string;
    paneSubtitle: string;
}

export function NavItem({
    section,
    selected,
    onSelect,
}: {
    section: SectionMeta;
    selected: boolean;
    onSelect: () => void;
}) {
    const Icon = section.icon;
    return (
        <button
            type="button"
            onClick={onSelect}
            className={cn(
                "fs:flex fs:w-full fs:items-center fs:gap-3 fs:rounded-lg fs:border fs:px-3 fs:py-2.5 fs:text-left fs:transition-colors",
                selected
                    ? "fs:border-brand-200 fs:bg-brand-50 fs:text-brand-700"
                    : "fs:border-transparent fs:bg-transparent fs:text-slate-700 fs:hover:bg-slate-100",
            )}
        >
            <span
                className={cn(
                    "fs:flex fs:h-8 fs:w-8 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-md",
                    selected
                        ? "fs:bg-brand-500 fs:text-white"
                        : "fs:bg-slate-100 fs:text-slate-500",
                )}
            >
                <Icon className="fs:h-4 fs:w-4" aria-hidden />
            </span>
            <span className="fs:min-w-0 fs:flex-1 fs:leading-tight">
                <span className="fs:block fs:text-sm fs:font-medium">
                    {section.title}
                </span>
                <span className="fs:block fs:truncate fs:text-xs fs:text-slate-500">
                    {section.subtitle}
                </span>
            </span>
            {selected && (
                <ChevronRight
                    className="fs:h-4 fs:w-4 fs:text-brand-400"
                    aria-hidden
                />
            )}
        </button>
    );
}

export function PaneHeader({ section }: { section: SectionMeta }) {
    const Icon = section.icon;
    return (
        <div className="fs:flex fs:items-center fs:gap-3 fs:border-b fs:border-slate-100 fs:bg-slate-50/60 fs:px-5 fs:py-4">
            <span className="fs:flex fs:h-9 fs:w-9 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-md fs:bg-white fs:text-brand-600 fs:shadow-sm fs:ring-1 fs:ring-slate-200">
                <Icon className="fs:h-4 fs:w-4" aria-hidden />
            </span>
            <div className="fs:leading-tight">
                <h2 className="fs:text-base fs:font-semibold fs:text-slate-900">
                    {section.paneTitle}
                </h2>
                <p className="fs:text-xs fs:text-slate-500">
                    {section.paneSubtitle}
                </p>
            </div>
        </div>
    );
}

/**
 * One label:control row — icon chip, label + description on the left, control
 * right-aligned. Matches the screenshot `Label : [control] description` line.
 */
export function SettingRow({
    icon: Icon,
    title,
    description,
    htmlFor,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    htmlFor?: string;
    children: ReactNode;
}) {
    return (
        <div className="fs:flex fs:items-center fs:gap-3 fs:px-5 fs:py-4">
            <span className="fs:flex fs:h-8 fs:w-8 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-md fs:bg-slate-100 fs:text-slate-500">
                <Icon className="fs:h-4 fs:w-4" aria-hidden />
            </span>
            <div className="fs:min-w-0 fs:flex-1">
                <Label htmlFor={htmlFor} className="fs:block fs:text-slate-800">
                    {title}
                </Label>
                {description && (
                    <p className="fs:mt-0.5 fs:text-xs fs:text-slate-500">
                        {description}
                    </p>
                )}
            </div>
            <div className="fs:flex fs:shrink-0 fs:items-center">{children}</div>
        </div>
    );
}

/** A SettingRow whose control is a Switch. */
export function ToggleRow({
    icon,
    title,
    description,
    checked,
    onChange,
    disabled,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <SettingRow icon={icon} title={title} description={description}>
            <Switch checked={checked} onCheckedChange={onChange} disabled={disabled} />
        </SettingRow>
    );
}
