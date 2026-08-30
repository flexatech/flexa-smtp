import { cva, type VariantProps } from "class-variance-authority";
import { forwardRef, type ButtonHTMLAttributes } from "react";
import { Slot } from "@radix-ui/react-slot";
import { cn } from "@/lib/cn";

const buttonVariants = cva(
    "fs:inline-flex fs:cursor-pointer fs:items-center fs:justify-center fs:gap-2 fs:whitespace-nowrap fs:rounded-md fs:text-sm fs:font-medium fs:transition-colors fs:focus-visible:outline-none fs:focus-visible:ring-2 fs:focus-visible:ring-offset-2 fs:disabled:pointer-events-none fs:disabled:opacity-50",
    {
        variants: {
            variant: {
                default:
                    "fs:bg-brand-600 fs:text-white fs:hover:bg-brand-700 fs:focus-visible:ring-brand-500",
                ghost: "fs:bg-transparent fs:hover:bg-slate-100 fs:text-slate-900",
                outline:
                    "fs:border fs:border-slate-300 fs:bg-white fs:hover:bg-slate-50 fs:text-slate-900",
                destructive:
                    "fs:bg-red-600 fs:text-white fs:hover:bg-red-700 fs:focus-visible:ring-red-500",
            },
            size: {
                default: "fs:h-9 fs:px-4 fs:py-2",
                sm: "fs:h-8 fs:rounded-md fs:px-3 fs:text-xs",
                lg: "fs:h-10 fs:rounded-md fs:px-6",
                icon: "fs:h-9 fs:w-9",
            },
        },
        defaultVariants: { variant: "default", size: "default" },
    },
);

export interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild, ...props }, ref) => {
        const Comp = asChild ? Slot : "button";
        return (
            <Comp
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                {...props}
            />
        );
    },
);
Button.displayName = "Button";

export { buttonVariants };
