import { useCallback, useEffect, useRef, useState } from "react";
import { buildSrcDoc } from "./srcDoc";

/**
 * O HTML de uma mensagem num iframe isolado. O sandbox não deixa correr scripts (nunca há
 * allow-scripts; o HTML já vem limpo e a CSP não permite scripts); com allow-same-origin, a página
 * consegue medir o conteúdo e o iframe cresce com a mensagem, como no Gmail. As ligações abrem num
 * separador novo.
 *
 * A altura mede-se quando o documento carrega, quando cada imagem carrega ou falha (apanhadas dentro
 * da mensagem) e quando a janela muda de largura, sempre no fotograma seguinte. Sem ResizeObserver: a
 * observar o corpo de outro documento (o do iframe), o navegador dava "ResizeObserver loop completed
 * with undelivered notifications" ao mostrar as imagens.
 */
export default function MessageFrame({ html, showImages, height = 420, autoHeight = false }: { html: string; showImages: boolean; height?: number | string; autoHeight?: boolean }) {
    const dark = document.documentElement.getAttribute("data-bs-theme") === "dark";
    const ref = useRef<HTMLIFrameElement>(null);
    const frame = useRef(0);
    const detach = useRef<() => void>(() => undefined);
    const [measured, setMeasured] = useState<number | null>(null);

    const measure = useCallback(() => {
        const body = ref.current?.contentDocument?.body;
        if (!autoHeight || !body) return;
        const next = Math.max(60, Math.ceil(body.getBoundingClientRect().height) + 16);
        setMeasured((prev) => (prev !== null && Math.abs(prev - next) < 2 ? prev : next));
    }, [autoHeight]);

    const schedule = useCallback(() => {
        cancelAnimationFrame(frame.current);
        frame.current = requestAnimationFrame(measure);
    }, [measure]);

    /** Cada documento carregado (a mensagem, ou "Mostrar imagens"): mede e escuta as imagens desse documento. */
    const onLoad = () => {
        detach.current();
        detach.current = () => undefined;
        const doc = ref.current?.contentDocument;
        if (!autoHeight || !doc?.body) return;
        schedule();
        doc.addEventListener("load", schedule, true); // as imagens (load não sobe; apanha-se na captura)
        doc.addEventListener("error", schedule, true);
        detach.current = () => {
            doc.removeEventListener("load", schedule, true);
            doc.removeEventListener("error", schedule, true);
        };
    };

    useEffect(() => {
        if (!autoHeight) return;
        window.addEventListener("resize", schedule);
        return () => window.removeEventListener("resize", schedule);
    }, [autoHeight, schedule]);

    useEffect(() => () => {
        detach.current();
        cancelAnimationFrame(frame.current);
    }, []);

    return (
        <iframe
            ref={ref}
            title="Mensagem"
            data-testid="mail-frame"
            sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
            referrerPolicy="no-referrer"
            srcDoc={buildSrcDoc(html, showImages, dark)}
            onLoad={onLoad}
            style={{ width: "100%", height: autoHeight && measured ? measured : height, border: 0, display: "block", borderRadius: dark ? 6 : 0 }}
        />
    );
}
