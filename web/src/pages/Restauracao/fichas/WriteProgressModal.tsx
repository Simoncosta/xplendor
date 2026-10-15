import { useEffect, useRef, useState } from "react";
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { getRecipeWrite } from "helpers/laravel_helper";
import { RecipeWriteInfo } from "common/models/recipe.model";

/**
 * Fatia 4 — o progresso de "Gravar no PingWin", passo a passo (abrir, ler e comparar, versão,
 * linhas n de m, lojas, gravar, confirmar por releitura), e o resultado. Pergunta o estado a cada
 * segundo enquanto a gravação está na fila ou a correr; nunca repete sozinha.
 * `useWriteProgress` + `WriteSteps` servem o editor (o progresso aparece dentro do modal de
 * edição); o modal deste ficheiro serve o "Apagar versão" e o "Apagar ficha".
 */
const STEPS: { id: string; label: string }[] = [
    { id: "abrir", label: "Abrir a ficha no PingWin" },
    { id: "ler", label: "Ler e comparar com a XPLENDOR" },
    { id: "versao", label: "Versão" },
    { id: "linhas", label: "Linhas" },
    { id: "lojas", label: "Lojas" },
    { id: "gravar", label: "Gravar" },
    { id: "confirmar", label: "Confirmar por releitura" },
];

export const OPERATION: Record<RecipeWriteInfo["operation"], string> = {
    criar: "Criar a ficha", nova_versao: "Nova versão", corrigir: "Corrigir a versão", apagar_versao: "Apagar a versão", apagar_ficha: "Apagar a ficha",
};

export const isFinal = (w: RecipeWriteInfo) => !["queued", "running"].includes(w.status);

/** O estado da gravação, perguntado a cada segundo até ficar final; `onFinished` chama-se uma vez. */
export function useWriteProgress(companyId: number, write: RecipeWriteInfo, onFinished: (w: RecipeWriteInfo) => void) {
    const [w, setW] = useState(write);
    const done = useRef(false);
    const finished = useRef(onFinished);
    finished.current = onFinished;

    useEffect(() => { setW(write); done.current = false; }, [write]);

    useEffect(() => {
        if (isFinal(w)) {
            if (!done.current) { done.current = true; finished.current(w); }
            return;
        }
        const t = setTimeout(async () => {
            try {
                const res: any = await getRecipeWrite(companyId, w.id);
                if (res?.data?.write) setW(res.data.write);
            } catch { /* tenta outra vez no próximo segundo */ }
        }, 1000);
        return () => clearTimeout(t);
    }, [w, companyId]);

    return w;
}

/** Os passos e o resultado (sem o modal à volta). */
export function WriteSteps({ w }: { w: RecipeWriteInfo }) {
    const seen = new Set((w.progress?.steps ?? []).map((s) => s.step));
    const order = STEPS.map((s) => s.id);
    const currentIdx = w.progress?.current ? order.indexOf(w.progress.current) : -1;
    const final = isFinal(w);
    const lastLine = [...(w.progress?.steps ?? [])].reverse().find((s) => s.step === "linhas");

    const stateOf = (id: string, i: number): "done" | "current" | "pending" | "skipped" => {
        if (final && w.status === "ok") return seen.has(id) ? "done" : "skipped";
        if (id === w.progress?.current && !final) return "current";
        if (seen.has(id) && i < currentIdx) return "done";
        if (seen.has(id) && final) return "done";
        if (final || i < currentIdx) return "skipped";
        return "pending";
    };

    return (
        <div data-testid="write-steps">
            {w.status === "queued" && <p className="text-muted fs-13"><Spinner size="sm" className="me-1" />Na fila: começa já a seguir.</p>}
            <ol className="list-unstyled mb-3" aria-live="polite">
                {STEPS.map((s, i) => {
                    const st = stateOf(s.id, i);
                    return (
                        <li key={s.id} className={`d-flex align-items-center gap-2 py-1 ${st === "pending" || st === "skipped" ? "text-muted" : ""}`} data-step={s.id} data-state={st}>
                            {st === "done" && <i className="ri-checkbox-circle-fill text-success" aria-hidden />}
                            {st === "current" && <Spinner size="sm" />}
                            {st === "pending" && <i className="ri-checkbox-blank-circle-line" aria-hidden />}
                            {st === "skipped" && <i className="ri-subtract-line" aria-hidden />}
                            <span>{s.label}{s.id === "linhas" && lastLine?.m ? ` (${lastLine.n} de ${lastLine.m})` : ""}{st === "skipped" ? " (não foi preciso)" : ""}</span>
                        </li>
                    );
                })}
            </ol>
            {w.status === "ok" && (
                <div className="alert alert-success py-2 mb-0" role="status"><i className="ri-check-double-line me-1" />Gravado e confirmado por releitura no PingWin.</div>
            )}
            {w.status === "conflict" && (
                <div className="alert alert-warning py-2 mb-0" role="alert">
                    <strong>Não gravei nada.</strong> {w.error}
                    {!!w.result?.diff?.length && <ul className="mb-0 mt-1 fs-13">{w.result.diff.map((d, i) => <li key={i}>{d}</li>)}</ul>}
                </div>
            )}
            {w.status === "mismatch" && (
                <div className="alert alert-warning py-2 mb-0" role="alert">
                    <strong>Gravado, mas a releitura não bate.</strong> A operação fica como falhada e não se repete sozinha.
                    <ul className="mb-0 mt-1 fs-13">{(w.result?.diff ?? []).map((d, i) => <li key={i}>{d}</li>)}</ul>
                </div>
            )}
            {w.status === "failed" && (
                <div className="alert alert-danger py-2 mb-0" role="alert"><strong>Falhou antes de gravar.</strong> {w.error} Sem SAVE, o PingWin não guardou nada.</div>
            )}
        </div>
    );
}

export default function WriteProgressModal({ companyId, write, onClose, onFinished }: {
    companyId: number; write: RecipeWriteInfo; onClose: () => void; onFinished: (w: RecipeWriteInfo) => void;
}) {
    const w = useWriteProgress(companyId, write, onFinished);
    const final = isFinal(w);

    return (
        <Modal isOpen toggle={() => final && onClose()} centered backdrop="static" data-testid="write-progress">
            <ModalHeader toggle={final ? onClose : undefined}>{OPERATION[w.operation]} no PingWin</ModalHeader>
            <ModalBody><WriteSteps w={w} /></ModalBody>
            <ModalFooter>
                <Button type="button" color="light" onClick={onClose}>{final ? "Fechar" : "Fechar (continua em segundo plano)"}</Button>
            </ModalFooter>
        </Modal>
    );
}
