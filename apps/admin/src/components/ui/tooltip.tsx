import * as TooltipPrimitive from "@radix-ui/react-tooltip";
import {
    forwardRef,
    type ComponentPropsWithoutRef,
    type ElementRef,
} from "react";
import { cn } from "@/lib/cn";

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export const TooltipContent = forwardRef<
    ElementRef<typeof TooltipPrimitive.Content>,
    ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
    <TooltipPrimitive.Portal>
        <TooltipPrimitive.Content
            ref={ref}
            sideOffset={sideOffset}
            className={cn(
                "fs:z-[160003] fs:overflow-hidden fs:rounded-md fs:bg-slate-900 fs:px-2.5 fs:py-1.5 fs:text-xs fs:font-medium fs:text-slate-50 fs:shadow-md fs:animate-in fs:fade-in-0 fs:zoom-in-95 fs:data-[state=closed]:animate-out fs:data-[state=closed]:fade-out-0 fs:data-[state=closed]:zoom-out-95 fs:data-[side=bottom]:slide-in-from-top-1 fs:data-[side=left]:slide-in-from-right-1 fs:data-[side=right]:slide-in-from-left-1 fs:data-[side=top]:slide-in-from-bottom-1",
                className,
            )}
            {...props}
        />
    </TooltipPrimitive.Portal>
));
TooltipContent.displayName = TooltipPrimitive.Content.displayName;
