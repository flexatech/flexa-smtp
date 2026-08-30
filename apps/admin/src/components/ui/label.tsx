import * as LabelPrimitive from "@radix-ui/react-label";
import { forwardRef, type ComponentPropsWithoutRef } from "react";
import { cn } from "@/lib/cn";

export const Label = forwardRef<
    HTMLLabelElement,
    ComponentPropsWithoutRef<typeof LabelPrimitive.Root>
>(({ className, ...props }, ref) => (
    <LabelPrimitive.Root
        ref={ref}
        className={cn(
            "fs:text-sm fs:font-medium fs:leading-none fs:text-slate-800 fs:peer-disabled:cursor-not-allowed fs:peer-disabled:opacity-70",
            className,
        )}
        {...props}
    />
));
Label.displayName = "Label";
