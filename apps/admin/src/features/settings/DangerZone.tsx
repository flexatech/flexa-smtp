import { ShieldAlert, Trash2 } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { __ } from "@/lib/i18n";
import { useUiStore } from "@/lib/store";
import { getPluginGlobal } from "@/lib/wp";
import { useResetAllData } from "./useSettings";

const CONFIRM_PHRASE = "RESET";

export function DangerZone() {
    const reset = useResetAllData();
    const showToast = useUiStore((s) => s.showToast);
    const { canManageSettings, adminUrl } = getPluginGlobal();
    const [confirm, setConfirm] = useState("");
    const [open, setOpen] = useState(false);

    const onReset = () => {
        reset.mutate(confirm, {
            onSuccess: () => {
                showToast(__("All plugin data was removed."));
                setOpen(false);
                // Settings + tables are gone; reload to a clean state.
                window.location.href = adminUrl;
            },
            onError: (e) =>
                showToast(
                    __("Reset failed: ") + (e as Error).message,
                    "error",
                ),
        });
    };

    return (
        <div className="fs:p-5">
            <div className="fs:rounded-lg fs:border fs:border-red-200 fs:bg-red-50/50 fs:p-5">
                <div className="fs:flex fs:items-start fs:gap-3">
                    <span className="fs:flex fs:h-9 fs:w-9 fs:shrink-0 fs:items-center fs:justify-center fs:rounded-md fs:bg-red-100 fs:text-red-600">
                        <ShieldAlert className="fs:h-4 fs:w-4" aria-hidden />
                    </span>
                    <div className="fs:flex-1">
                        <h3 className="fs:text-sm fs:font-semibold fs:text-red-800">
                            {__("Reset all data")}
                        </h3>
                        <p className="fs:mt-1 fs:text-xs fs:text-red-700/80">
                            {__(
                                "Deletes all settings and drops the email log, open, and click tables. This cannot be undone.",
                            )}
                        </p>

                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    className="fs:mt-4"
                                    disabled={!canManageSettings}
                                >
                                    <Trash2
                                        className="fs:h-4 fs:w-4"
                                        aria-hidden
                                    />
                                    {__("Reset everything")}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        {__("Reset all Flexa SMTP data?")}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {__(
                                            "This permanently deletes your settings and every logged email. Type RESET to confirm.",
                                        )}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="fs:space-y-2">
                                    <Label
                                        htmlFor="fs-reset-confirm"
                                        className="fs:block"
                                    >
                                        {__("Confirmation")}
                                    </Label>
                                    <Input
                                        id="fs-reset-confirm"
                                        value={confirm}
                                        onChange={(e) =>
                                            setConfirm(e.target.value)
                                        }
                                        placeholder={CONFIRM_PHRASE}
                                        autoComplete="off"
                                    />
                                </div>

                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="outline">
                                            {__("Cancel")}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        disabled={
                                            confirm !== CONFIRM_PHRASE ||
                                            reset.isPending
                                        }
                                        onClick={onReset}
                                    >
                                        {reset.isPending
                                            ? __("Resetting…")
                                            : __("Reset everything")}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            </div>
        </div>
    );
}
