import { forwardRef, type InputHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

interface SwitchProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
    checked: boolean;
    onCheckedChange: (next: boolean) => void;
}

/**
 * Minimal CSS-only toggle - no extra Radix package needed. The hidden input
 * is what the form/keyboard interacts with; the visual is two divs that
 * follow the `peer-checked:` state.
 */
export const Switch = forwardRef<HTMLInputElement, SwitchProps>(
    ({ checked, onCheckedChange, className, disabled, id, ...rest }, ref) => {
        return (
            <label
                className={cn(
                    "fs:relative fs:inline-flex fs:h-5 fs:w-9 fs:cursor-pointer fs:items-center",
                    disabled && "fs:cursor-not-allowed fs:opacity-60",
                    className,
                )}
            >
                <input
                    ref={ref}
                    id={id}
                    type="checkbox"
                    role="switch"
                    checked={checked}
                    disabled={disabled}
                    onChange={(e) => onCheckedChange(e.target.checked)}
                    className="fs:peer fs:sr-only"
                    {...rest}
                />
                <span
                    aria-hidden
                    className="fs:h-5 fs:w-9 fs:rounded-full fs:bg-slate-300 fs:transition-colors fs:peer-checked:bg-brand-600 fs:peer-focus-visible:ring-2 fs:peer-focus-visible:ring-brand-500 fs:peer-focus-visible:ring-offset-2"
                />
                <span
                    aria-hidden
                    className="fs:absolute fs:left-0.5 fs:h-4 fs:w-4 fs:rounded-full fs:bg-white fs:shadow fs:transition-transform fs:peer-checked:translate-x-4"
                />
            </label>
        );
    },
);
Switch.displayName = "Switch";
