import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import {
    forwardRef,
    type ComponentPropsWithoutRef,
    type ElementRef,
    type HTMLAttributes,
} from "react";
import { cn } from "@/lib/cn";

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

const DialogOverlay = forwardRef<
    ElementRef<typeof DialogPrimitive.Overlay>,
    ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            "fs:fixed fs:inset-0 fs:z-[160001] fs:bg-black/40 fs:backdrop-blur-sm fs:data-[state=open]:animate-in fs:data-[state=closed]:animate-out fs:data-[state=closed]:fade-out-0 fs:data-[state=open]:fade-in-0",
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

interface DialogContentProps
    extends ComponentPropsWithoutRef<typeof DialogPrimitive.Content> {
    overlayClassName?: string;
}

export const DialogContent = forwardRef<
    ElementRef<typeof DialogPrimitive.Content>,
    DialogContentProps
>(({ className, overlayClassName, children, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <DialogOverlay className={overlayClassName} />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                "fs:fixed fs:left-1/2 fs:top-1/2 fs:z-[160002] fs:grid fs:w-full fs:max-w-md fs:-translate-x-1/2 fs:-translate-y-1/2 fs:gap-4 fs:rounded-lg fs:border fs:border-slate-200 fs:bg-white fs:p-6 fs:shadow-xl",
                "fs:data-[state=open]:animate-in fs:data-[state=closed]:animate-out",
                className,
            )}
            {...props}
        >
            {children}
            <DialogPrimitive.Close className="fs:absolute fs:right-4 fs:top-4 fs:rounded-sm fs:opacity-70 fs:transition-opacity fs:hover:opacity-100 fs:focus:outline-none fs:focus:ring-2 fs:focus:ring-brand-500">
                <X className="fs:h-4 fs:w-4" />
                <span className="fs:sr-only">Close</span>
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
DialogContent.displayName = DialogPrimitive.Content.displayName;

export const DialogHeader = ({ className, ...props }: HTMLAttributes<HTMLDivElement>) => (
    <div className={cn("fs:flex fs:flex-col fs:space-y-1.5 fs:text-left", className)} {...props} />
);
DialogHeader.displayName = "DialogHeader";

export const DialogFooter = ({ className, ...props }: HTMLAttributes<HTMLDivElement>) => (
    <div className={cn("fs:flex fs:justify-end fs:gap-2", className)} {...props} />
);
DialogFooter.displayName = "DialogFooter";

export const DialogTitle = forwardRef<
    ElementRef<typeof DialogPrimitive.Title>,
    ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Title
        ref={ref}
        className={cn("fs:text-lg fs:font-semibold fs:leading-none fs:tracking-tight", className)}
        {...props}
    />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

export const DialogDescription = forwardRef<
    ElementRef<typeof DialogPrimitive.Description>,
    ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Description
        ref={ref}
        className={cn("fs:text-sm fs:text-slate-500", className)}
        {...props}
    />
));
DialogDescription.displayName = DialogPrimitive.Description.displayName;
