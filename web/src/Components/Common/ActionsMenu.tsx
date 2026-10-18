import React from "react";
import { Link } from "react-router-dom";
import { DropdownItem, DropdownMenu, DropdownToggle, UncontrolledDropdown } from "reactstrap";

/**
 * O menu "..." (documents/design-system.md): as ações raras e as destrutivas (a vermelho,
 * sempre com confirmação depois). Uma ação desativada mostra o motivo por baixo do nome.
 */
export type MenuAction = {
    label: string;
    icon?: string;
    onClick?: () => void;
    /** Ligação interna (em vez de onClick). */
    to?: string;
    /** Apagar, anular, retirar: a vermelho, separada das outras. */
    danger?: boolean;
    /** Motivo de estar desativada (mostrado no menu). */
    disabledReason?: string | null;
    hidden?: boolean;
};

/** Acima do modal (1055) e do seu fundo (1050), abaixo dos avisos de confirmação (SweetAlert, 1060+ não colide: o menu fecha-se ao clicar). */
export const MENU_Z_INDEX = 1070;

type Props = {
    items: MenuAction[];
    /** Nome para leitores de ecrã, ex.: "Mais ações: Fatura 12". */
    label?: string;
    size?: "sm" | "md";
    disabled?: boolean;
    className?: string;
};

export default function ActionsMenu({ items, label = "Mais ações", size = "md", disabled, className = "" }: Props) {
    const visible = items.filter((i) => !i.hidden);
    if (visible.length === 0) return null;
    const normal = visible.filter((i) => !i.danger);
    const danger = visible.filter((i) => i.danger);
    const item = (a: MenuAction, k: number) => {
        const inner = (
            <>
                {a.icon && <i className={`${a.icon} align-bottom me-2`} />}{a.label}
                {a.disabledReason && <div className="fs-12 text-muted text-wrap" style={{ maxWidth: 260 }}>{a.disabledReason}</div>}
            </>
        );
        const cls = a.danger ? "text-danger" : "";
        if (a.to && !a.disabledReason) return <DropdownItem key={k} tag={Link} to={a.to} className={cls}>{inner}</DropdownItem>;
        return <DropdownItem key={k} className={cls} disabled={!!a.disabledReason} onClick={a.onClick}>{inner}</DropdownItem>;
    };
    return (
        <UncontrolledDropdown className={`d-inline-block ${className}`}>
            <DropdownToggle tag="button" type="button" className={`btn btn-outline-primary ${size === "sm" ? "btn-sm" : ""} btn-icon-text`} aria-label={label} title={label} disabled={disabled}>
                <i className="ri-more-2-fill align-bottom" />
            </DropdownToggle>
            {/* No document.body e acima dos modais (o modal do Bootstrap está em 1055): o menu nunca fica por baixo. */}
            <DropdownMenu end container="body" className="actions-menu" style={{ zIndex: MENU_Z_INDEX }}>
                {normal.map(item)}
                {normal.length > 0 && danger.length > 0 && <DropdownItem divider />}
                {danger.map((a, i) => item(a, normal.length + i))}
            </DropdownMenu>
        </UncontrolledDropdown>
    );
}
