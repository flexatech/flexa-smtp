import { PauseCircle } from "lucide-react";
import { __ } from "@/lib/i18n";
import { ROW_DIVIDER, ToggleRow } from "../SettingRow";
import { type TabProps } from "../types";

export function AdditionalTab({ form, setField }: TabProps) {
    return (
        <div className={ROW_DIVIDER}>
            <ToggleRow
                icon={PauseCircle}
                title={__("Pause Email Delivery")}
                description={__(
                    "Development mode: stop sending real email. Messages are still logged so you can inspect them.",
                )}
                checked={form.disable_delivery}
                onChange={(v) => setField("disable_delivery", v)}
            />
        </div>
    );
}
