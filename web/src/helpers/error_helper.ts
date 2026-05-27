import { toast } from "react-toastify";

/**
 * Mostra toast(s) de erro a partir de um erro do Axios.
 *
 * - HTTP 422 (validation): mostra um toast por cada mensagem
 *   em error.response.data.errors (estrutura standard do Laravel).
 * - Outros erros: mostra error.response.data.message se existir,
 *   senão fallbackMessage.
 */
export const showApiErrorToast = (
    error: any,
    fallbackMessage: string = "Erro ao gravar."
): void => {
    const status = error?.response?.status;
    const data = error?.response?.data;

    if (status === 422 && data?.errors && typeof data.errors === "object") {
        const messages = Object.values(data.errors).flat() as string[];
        if (messages.length > 0) {
            messages.forEach(msg => {
                toast.error(msg, { position: "top-right", autoClose: 6000, hideProgressBar: false });
            });
            return;
        }
    }

    const message = data?.message ?? fallbackMessage;
    toast.error(message, { position: "top-right", autoClose: 6000, hideProgressBar: false });
};
