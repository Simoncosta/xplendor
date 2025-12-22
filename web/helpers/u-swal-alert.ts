import Swal, { SweetAlertIcon } from 'sweetalert2';

export function toastSuccess(message: string) {
    const toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
    });

    toast.fire({
        icon: 'success',
        title: message,
        padding: '10px 20px',
    });
}

export function toastError(message: string) {
    const toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
    });

    toast.fire({
        icon: 'error',
        title: message,
        padding: '10px 20px',
    });
}

export function toastWarning(message: string) {
    const toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
    });

    toast.fire({
        icon: 'warning',
        title: message,
        padding: '10px 20px',
    });
}

/**Função auxiliar para exibir um toast customizado */
export function showDialog(options: {
    title: string;
    text?: string;
    icon?: SweetAlertIcon
    showDenyButton?: boolean;
    showCancelButton?: boolean;
    confirmButtonText?: string;
    denyButtonText?: string;
    cancelButtonText?: string;
}) {
    return Swal.fire({
        title: options.title,
        text: options.text || "",
        icon: options.icon || "info",
        showDenyButton: options.showDenyButton ?? false,
        showCancelButton: options.showCancelButton ?? false,
        confirmButtonText: options.confirmButtonText || "OK",
        denyButtonText: options.denyButtonText || "Não",
        cancelButtonText: options.cancelButtonText || "Cancelar",
    });
}