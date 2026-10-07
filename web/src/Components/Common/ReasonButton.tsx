import React, { useId, useState } from "react";
import { Button, Tooltip } from "reactstrap";

/**
 * Um botão desativado nunca fica sem explicação (documents/design-system.md): com `reason`,
 * o botão aparece desativado e o motivo mostra-se ao passar, focar ou tocar. Sem `reason`,
 * é um botão normal.
 */
type Props = React.ComponentProps<typeof Button> & { reason?: string | null };

export default function ReasonButton({ reason, children, ...props }: Props) {
    const id = `why-${useId().replace(/:/g, "")}`;
    const [open, setOpen] = useState(false);
    if (!reason) return <Button {...props}>{children}</Button>;
    return (
        <>
            <span id={id} tabIndex={0} role="button" aria-label={`${typeof children === "string" ? children : ""} (indisponível: ${reason})`} className="d-inline-block"
                onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)} onFocus={() => setOpen(true)} onBlur={() => setOpen(false)} onClick={() => setOpen((v) => !v)}>
                <Button {...props} disabled style={{ pointerEvents: "none", ...(props.style ?? {}) }}>{children}</Button>
            </span>
            <Tooltip target={id} isOpen={open} trigger="manual" placement="bottom">{reason}</Tooltip>
        </>
    );
}
