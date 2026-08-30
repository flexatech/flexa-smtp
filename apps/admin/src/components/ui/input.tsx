import { forwardRef, type InputHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

export type InputProps = InputHTMLAttributes<HTMLInputElement>;

export const Input = forwardRef<HTMLInputElement, InputProps>(
    ({ className, type = "text", ...props }, ref) => {
        return (
            <input
                ref={ref}
                type={type}
                className={cn(
                    "flexa-smtp-control",
                    "fs:flex fs:h-9 fs:w-full fs:rounded-md fs:border fs:border-slate-300 fs:bg-white fs:px-3 fs:py-1 fs:text-sm fs:shadow-sm fs:transition-colors",
                    "fs:placeholder:text-slate-400",
                    "fs:focus-visible:outline-none fs:focus-visible:ring-2 fs:focus-visible:ring-brand-500 fs:focus-visible:ring-offset-1",
                    "fs:disabled:cursor-not-allowed fs:disabled:opacity-50",
                    className,
                )}
                {...props}
            />
        );
    },
);
Input.displayName = "Input";
