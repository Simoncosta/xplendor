import React from "react";
import { Alert } from "reactstrap";
import type { ApiValidationError } from "helpers/error_helper";

// As mensagens vêm já em pt-PT do backend (lang/pt/validation.php), incluindo
// o nome do campo via bloco `attributes`. O Alert mostra a(s) mensagem(ns) tal
// como vêm — sem prefixo de label próprio, para não duplicar o nome do campo.
// `err.field` continua a ser a chave do React (estável e única).

interface ValidationAlertProps {
    errors: ApiValidationError[] | null;
    onDismiss?: () => void;
}

export default function ValidationAlert({ errors, onDismiss }: ValidationAlertProps) {
    if (!errors || errors.length === 0) {
        return null;
    }

    return (
        <Alert
            color="danger"
            toggle={onDismiss}
            className="mb-3"
            role="alert"
        >
            <div className="fw-semibold mb-2">
                <i className="ri-error-warning-line me-2" />
                Não foi possível gravar. Corrige os seguintes campos:
            </div>
            <ul className="mb-0 ps-3">
                {errors.map((err) => (
                    <li key={err.field} className="mb-1">
                        {err.messages.join(" · ")}
                    </li>
                ))}
            </ul>
        </Alert>
    );
}
