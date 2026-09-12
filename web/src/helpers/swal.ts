import Swal, { SweetAlertIcon, SweetAlertResult } from "sweetalert2";

/**
 * Diálogos de confirmação/alerta do projeto — SEMPRE via SweetAlert2.
 * Nunca usar `window.confirm` / `window.alert` / `window.prompt` nativos.
 *
 * Botões mapeados às classes do tema (Velzon/Bootstrap) via `buttonsStyling:false`
 * + `customClass`, para o diálogo ficar coerente com o resto do painel.
 */

interface ConfirmOptions {
    title?: string;
    text?: string;
    confirmText?: string;
    cancelText?: string;
    icon?: SweetAlertIcon;
    confirmVariant?: "danger" | "primary" | "warning" | "success";
}

/** Confirmação genérica. Resolve `true` se o utilizador confirmar. */
export async function confirmAction(options: ConfirmOptions = {}): Promise<boolean> {
    const {
        title = "Tem a certeza?",
        text = "",
        confirmText = "Confirmar",
        cancelText = "Cancelar",
        icon = "warning",
        confirmVariant = "primary",
    } = options;

    const result: SweetAlertResult = await Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        buttonsStyling: false,
        customClass: {
            confirmButton: `btn btn-${confirmVariant} w-xs me-2 mb-1`,
            cancelButton: "btn btn-light w-xs mb-1",
        },
    });

    return result.isConfirmed;
}

/** Atalho para confirmação de eliminação (botão vermelho). */
export function confirmDelete(text: string, title = "Eliminar?"): Promise<boolean> {
    return confirmAction({
        title,
        text,
        icon: "warning",
        confirmText: "Sim, eliminar",
        cancelText: "Cancelar",
        confirmVariant: "danger",
    });
}

/**
 * Pergunta com campo de texto (substitui `window.prompt`).
 * Resolve com o valor introduzido, ou `null` se o utilizador cancelar.
 */
export async function promptInput(options: {
    title?: string;
    inputLabel?: string;
    initial?: string;
    confirmText?: string;
    cancelText?: string;
    placeholder?: string;
} = {}): Promise<string | null> {
    const {
        title = "Editar",
        inputLabel = "",
        initial = "",
        confirmText = "Guardar",
        cancelText = "Cancelar",
        placeholder = "",
    } = options;

    const result: SweetAlertResult = await Swal.fire({
        title,
        input: "text",
        inputLabel,
        inputValue: initial,
        inputPlaceholder: placeholder,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        buttonsStyling: false,
        customClass: {
            confirmButton: "btn btn-primary w-xs me-2 mb-1",
            cancelButton: "btn btn-light w-xs mb-1",
            input: "form-control",
        },
        inputValidator: (value) => (value && value.trim() ? null : "Escreve um valor."),
    });

    return result.isConfirmed ? String(result.value ?? "").trim() : null;
}

/** Aviso simples (substitui `window.alert`). */
export function alertMessage(text: string, title = "Aviso", icon: SweetAlertIcon = "info"): Promise<SweetAlertResult> {
    return Swal.fire({
        title,
        text,
        icon,
        buttonsStyling: false,
        customClass: { confirmButton: "btn btn-primary w-xs mb-1" },
    });
}
