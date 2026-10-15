import { useEffect, useState } from "react";

/**
 * true só depois de `flag` estar true há `delay` ms (por omissão 200). Serve para não piscar o
 * skeleton (ou o indicador de carregamento) em respostas rápidas: se a resposta chega antes,
 * nunca chega a aparecer. Volta logo a false quando `flag` passa a false.
 */
export default function useDelayedFlag(flag: boolean, delay = 200): boolean {
    const [shown, setShown] = useState(false);

    useEffect(() => {
        if (!flag) {
            setShown(false);
            return;
        }
        if (delay <= 0) {
            setShown(true);
            return;
        }
        const t = setTimeout(() => setShown(true), delay);
        return () => clearTimeout(t);
    }, [flag, delay]);

    return flag && (shown || delay <= 0);
}
