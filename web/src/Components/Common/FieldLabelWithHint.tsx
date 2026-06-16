// Label de campo de formulário com ícone de ajuda + tooltip on-demand.
//
// Substitui o padrão <Label> + <small> por baixo (helper-text permanente que
// desequilibra a grelha) por um label compacto + ícone de informação ⓘ
// que mostra o hint num tooltip.
//
// TOUCH-FIRST: trigger="hover focus click" cobre os 3 mecanismos de input.
// O `focus` puro pode falhar em alguns browsers mobile (Safari iOS dispara
// focus tardio em <span tabindex>); o `click` garante que o tap funciona
// sempre — UncontrolledTooltip do Reactstrap mostra E esconde no toggle.
//
// Acessibilidade: tabIndex={0} + role="button" + aria-label fazem o ícone
// alcançável por teclado e legível por screen-readers.

import React, { useId } from "react";
import { Label, UncontrolledTooltip } from "reactstrap";

interface FieldLabelWithHintProps {
    label: string;
    hint: string;
    htmlFor?: string;
    required?: boolean;
    className?: string;
}

const FieldLabelWithHint: React.FC<FieldLabelWithHintProps> = ({
    label,
    hint,
    htmlFor,
    required = false,
    className = "form-label",
}) => {
    // useId() do React garante id único cross-mount.
    // React 19 produz IDs com ":" (ex: ":r0:") que não são válidos como
    // selectores CSS — Reactstrap usa querySelector internamente com o
    // target. Substituir por chars CSS-safe.
    const reactId   = useId();
    const tooltipId = `hint-${reactId.replace(/:/g, "")}`;

    return (
        <>
            <Label htmlFor={htmlFor} className={className}>
                {label}: {required && <span className="text-danger">*</span>}
                <span
                    id={tooltipId}
                    tabIndex={0}
                    role="button"
                    aria-label={`Ajuda: ${label}`}
                    className="ms-1 d-inline-flex align-items-center"
                    style={{ cursor: "help", verticalAlign: "middle" }}
                >
                    <i className="ri-information-line text-muted" aria-hidden="true" />
                </span>
            </Label>
            <UncontrolledTooltip
                target={tooltipId}
                trigger="hover focus click"
                placement="top"
            >
                {hint}
            </UncontrolledTooltip>
        </>
    );
};

export default FieldLabelWithHint;
