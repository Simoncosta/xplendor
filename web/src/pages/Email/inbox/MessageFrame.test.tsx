import { act, fireEvent, render, screen } from "@testing-library/react";
import MessageFrame from "./MessageFrame";

/** O quadro da mensagem cresce com o conteúdo, sem ResizeObserver (que dava "ResizeObserver loop completed with undelivered notifications"). */
describe("MessageFrame (E-mail 4)", () => {
    const realRO = (global as any).ResizeObserver;
    let created = 0;
    beforeEach(() => {
        created = 0;
        (global as any).ResizeObserver = class { constructor() { created++; } observe() { /* nada */ } disconnect() { /* nada */ } };
        jest.useFakeTimers();
    });
    afterEach(() => { jest.useRealTimers(); (global as any).ResizeObserver = realRO; });

    it("mede no fotograma seguinte ao carregar, quando uma imagem carrega ou falha e quando a janela muda; nunca cria um ResizeObserver", () => {
        render(<MessageFrame html="<p>Olá</p>" showImages={false} height={300} autoHeight />);
        const frame = screen.getByTestId("mail-frame") as HTMLIFrameElement;
        const body = frame.contentDocument!.body;
        const rect = jest.spyOn(body, "getBoundingClientRect").mockReturnValue({ height: 500 } as DOMRect);
        expect(frame.style.height).toBe("300px");

        fireEvent.load(frame);
        expect(frame.style.height).toBe("300px"); // ainda não: no fotograma seguinte
        act(() => { jest.runOnlyPendingTimers(); });
        expect(frame.style.height).toBe("516px");

        // Uma imagem da mensagem carrega (o evento não sobe: apanha-se na captura do documento).
        rect.mockReturnValue({ height: 800 } as DOMRect);
        const img = frame.contentDocument!.createElement("img");
        body.appendChild(img);
        act(() => { img.dispatchEvent(new Event("load")); jest.runOnlyPendingTimers(); });
        expect(frame.style.height).toBe("816px");

        rect.mockReturnValue({ height: 600 } as DOMRect);
        act(() => { img.dispatchEvent(new Event("error")); jest.runOnlyPendingTimers(); });
        expect(frame.style.height).toBe("616px");

        rect.mockReturnValue({ height: 700 } as DOMRect);
        act(() => { window.dispatchEvent(new Event("resize")); jest.runOnlyPendingTimers(); });
        expect(frame.style.height).toBe("716px");

        expect(created).toBe(0);
    });

    it("sem autoHeight, fica com a altura dada", () => {
        render(<MessageFrame html="<p>Olá</p>" showImages={false} height={320} />);
        const frame = screen.getByTestId("mail-frame") as HTMLIFrameElement;
        fireEvent.load(frame);
        act(() => { jest.runOnlyPendingTimers(); });
        expect(frame.style.height).toBe("320px");
    });
});
