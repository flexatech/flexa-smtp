import { forwardRef, type SelectHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    options: Array<{ value: string; label: string }>;
}

/**
 * Lightweight native `<select>`. We deliberately avoid the Radix Select for
 * the settings page - native renders correctly inside the WP admin frame
 * and is fully a11y/keyboard-conformant out of the box.
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(
    ({ options, className, ...rest }, ref) => (
        <select
            ref={ref}
            className={cn(
                "flexa-smtp-control",
                "fs:h-9 fs:rounded-md fs:border fs:border-slate-300 fs:bg-white fs:px-2 fs:text-sm fs:text-slate-900 fs:shadow-sm fs:transition-colors",
                "fs:focus-visible:outline-none fs:focus-visible:ring-2 fs:focus-visible:ring-brand-500 fs:focus-visible:ring-offset-1",
                "fs:disabled:cursor-not-allowed fs:disabled:opacity-60",
                className,
            )}
            {...rest}
        >
            {options.map((opt) => (
                <option key={opt.value} value={opt.value}>
                    {opt.label}
                </option>
            ))}
        </select>
    ),
);
Select.displayName = "Select";
