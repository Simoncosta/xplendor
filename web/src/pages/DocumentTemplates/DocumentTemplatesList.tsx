// React
import React, { useEffect, useMemo, useRef, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, CardHeader, Col, Container, Row, Input, Label, Badge, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
// Components
import XButton from "Components/Common/XButton";
// Redux
import { getDocumentTemplates, createDocumentTemplate, updateDocumentTemplate, deleteDocumentTemplate } from "slices/documentTemplates/thunk";
// Helpers
import { getDocumentTemplateVariables, replaceDocumentTemplateFile } from "helpers/laravel_helper";
import { downloadGet } from "helpers/download_helper";
import { confirmDelete, promptInput } from "helpers/swal";
// Models
import { IDocumentTemplate, IDocumentVariable } from "common/models/documentTemplate.model";

const selectVM = createSelector(
    [(state: any) => state.DocumentTemplate],
    (s) => ({
        templates: s.data.templates as IDocumentTemplate[],
        loadingList: s.loading.list as boolean,
        creating: s.loading.create as boolean,
    })
);

const DocumentTemplatesList = () => {
    const dispatch: any = useDispatch();
    document.title = "Modelos de documento | Xplendor";

    const { templates, loadingList, creating } = useSelector(selectVM);
    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [variables, setVariables] = useState<IDocumentVariable[]>([]);
    const [name, setName] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const fileRef = useRef<HTMLInputElement | null>(null);

    // Substituir ficheiro de um modelo existente (input escondido + alvo).
    const replaceRef = useRef<HTMLInputElement | null>(null);
    const [replaceTarget, setReplaceTarget] = useState<IDocumentTemplate | null>(null);
    const [replacingId, setReplacingId] = useState<number | null>(null);

    useEffect(() => {
        if (!companyId) return;
        dispatch(getDocumentTemplates({ companyId }));
        getDocumentTemplateVariables(companyId)
            .then((res: any) => setVariables(res?.data ?? []))
            .catch(() => setVariables([]));
    }, [companyId, dispatch]);

    const groups = useMemo(() => {
        const g: Record<string, IDocumentVariable[]> = {};
        variables.forEach((v) => { (g[v.group] ||= []).push(v); });
        return g;
    }, [variables]);

    const copyTag = (key: string) => {
        const tag = `{{${key}}}`;
        navigator.clipboard?.writeText(tag).then(
            () => toast.success(`${tag} copiado`),
            () => toast.info(tag)
        );
    };

    const submit = async () => {
        if (!companyId) return;
        if (!name.trim()) { toast.error("Dá um nome ao modelo."); return; }
        if (!file) { toast.error("Escolhe um ficheiro .docx."); return; }
        const fd = new FormData();
        fd.append("name", name.trim());
        fd.append("file", file);
        try {
            await dispatch(createDocumentTemplate({ companyId, data: fd })).unwrap();
            toast.success("Modelo carregado.");
            setName(""); setFile(null);
            if (fileRef.current) fileRef.current.value = "";
        } catch (err: any) {
            const msg = err?.errors?.file?.[0] || err?.message || "Não foi possível carregar o modelo.";
            toast.error(msg);
        }
    };

    const rename = async (t: IDocumentTemplate) => {
        const novo = await promptInput({
            title: "Renomear modelo",
            inputLabel: "Novo nome do modelo",
            initial: t.name,
        });
        if (novo == null || novo === t.name) return;
        await dispatch(updateDocumentTemplate({ companyId, id: t.id, data: { name: novo } })).unwrap()
            .then(() => toast.success("Nome atualizado."))
            .catch(() => toast.error("Não foi possível renomear."));
    };

    const toggleArchive = async (t: IDocumentTemplate) => {
        await dispatch(updateDocumentTemplate({ companyId, id: t.id, data: { archived: !t.archived } })).unwrap()
            .then(() => toast.success(t.archived ? "Modelo reativado." : "Modelo arquivado."))
            .catch(() => toast.error("Não foi possível atualizar."));
    };

    const remove = async (t: IDocumentTemplate) => {
        const ok = await confirmDelete(`Eliminar o modelo "${t.name}"?`);
        if (!ok) return;
        await dispatch(deleteDocumentTemplate({ companyId, id: t.id })).unwrap()
            .then(() => toast.success("Modelo eliminado."))
            .catch(() => toast.error("Não foi possível eliminar."));
    };

    const pickReplacement = (t: IDocumentTemplate) => {
        setReplaceTarget(t);
        if (replaceRef.current) { replaceRef.current.value = ""; replaceRef.current.click(); }
    };

    const onReplacementChosen = async (ev: React.ChangeEvent<HTMLInputElement>) => {
        const f = ev.target.files?.[0];
        const t = replaceTarget;
        setReplaceTarget(null);
        if (!f || !t || !companyId) return;
        const fd = new FormData();
        fd.append("file", f);
        setReplacingId(t.id);
        try {
            await replaceDocumentTemplateFile(companyId, t.id, fd);
            toast.success(`Ficheiro de "${t.name}" substituído.`);
            dispatch(getDocumentTemplates({ companyId }));
        } catch (err: any) {
            toast.error(err?.errors?.file?.[0] || "Não foi possível substituir o ficheiro.");
        } finally {
            setReplacingId(null);
        }
    };

    const downloadExample = () => {
        downloadGet(`/companies/${companyId}/document-templates/example`, "exemplo-modelo.docx")
            .then((r) => { if (!r.ok) toast.error("Não foi possível descarregar o exemplo."); });
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1">Modelos de documento</h4>
                        <p className="text-muted mb-0">Carregue um .docx com variáveis <code>{"{{ }}"}</code>. Depois, em cada venda, gera o documento preenchido para descarregar.</p>
                    </Col>
                </Row>

                <Row>
                    {/* Coluna esquerda: variáveis + exemplo */}
                    <Col lg={4} className="mb-3">
                        <Card className="h-100">
                            <CardHeader className="d-flex align-items-center justify-content-between">
                                <h5 className="mb-0">Variáveis disponíveis</h5>
                                <XButton variant="primary" soft size="sm" onClick={downloadExample} icon={<i className="ri-download-2-line" />}>Exemplo .docx</XButton>
                            </CardHeader>
                            <CardBody>
                                <p className="text-muted fs-13">Clica numa variável para copiar. Cola-a no teu .docx (Word/Google Docs).</p>
                                {Object.keys(groups).length === 0 ? (
                                    <div className="text-muted fs-13">A carregar…</div>
                                ) : Object.entries(groups).map(([group, vars]) => (
                                    <div key={group} className="mb-3">
                                        <div className="fw-semibold fs-13 text-uppercase text-muted mb-1">{group}</div>
                                        <div className="d-flex flex-wrap gap-1">
                                            {vars.map((v) => (
                                                <button
                                                    key={v.key}
                                                    type="button"
                                                    className="btn btn-sm btn-light border"
                                                    title={`${v.label} — clica para copiar {{${v.key}}}`}
                                                    onClick={() => copyTag(v.key)}
                                                >
                                                    <i className="ri-file-copy-line me-1 text-primary" />{`{{${v.key}}}`}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </CardBody>
                        </Card>
                    </Col>

                    {/* Coluna direita: upload + lista */}
                    <Col lg={8}>
                        <Card className="mb-3">
                            <CardHeader><h5 className="mb-0">Carregar novo modelo</h5></CardHeader>
                            <CardBody>
                                <Row className="g-2 align-items-end">
                                    <Col md={5}>
                                        <Label className="form-label">Nome do modelo</Label>
                                        <Input type="text" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex.: Contrato de compra e venda" />
                                    </Col>
                                    <Col md={5}>
                                        <Label className="form-label">Ficheiro (.docx)</Label>
                                        <Input innerRef={fileRef as any} type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                                    </Col>
                                    <Col md={2}>
                                        <XButton variant="primary" onClick={submit} loading={creating} className="w-100" icon={<i className="ri-upload-2-line" />}>Carregar</XButton>
                                    </Col>
                                </Row>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader><h5 className="mb-0">Os meus modelos</h5></CardHeader>
                            <CardBody>
                                {loadingList ? (
                                    <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                                ) : templates.length === 0 ? (
                                    <p className="text-muted mb-0">Ainda não há modelos. Carrega o primeiro acima.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-2">
                                        {templates.map((t) => (
                                            <div key={t.id} className="d-flex align-items-center justify-content-between border rounded p-3">
                                                <div>
                                                    <div className="fw-medium"><i className="ri-file-word-2-line text-primary me-1" />{t.name}{t.archived && <Badge color="secondary" className="ms-2">Arquivado</Badge>}</div>
                                                </div>
                                                <div className="d-flex gap-1">
                                                    <button type="button" className="btn btn-sm btn-light" onClick={() => pickReplacement(t)} disabled={replacingId === t.id} title="Substituir ficheiro">
                                                        {replacingId === t.id ? <Spinner size="sm" /> : <i className="ri-file-upload-line" />}
                                                    </button>
                                                    <button type="button" className="btn btn-sm btn-light" onClick={() => rename(t)} title="Renomear"><i className="ri-pencil-line" /></button>
                                                    <button type="button" className="btn btn-sm btn-light" onClick={() => toggleArchive(t)} title={t.archived ? "Reativar" : "Arquivar"}><i className={t.archived ? "ri-inbox-unarchive-line" : "ri-archive-line"} /></button>
                                                    <button type="button" className="btn btn-sm btn-soft-danger" onClick={() => remove(t)} title="Eliminar"><i className="ri-delete-bin-line" /></button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

                {/* Input escondido partilhado para "Substituir ficheiro". */}
                <input
                    ref={replaceRef}
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    style={{ display: "none" }}
                    onChange={onReplacementChosen}
                />
            </Container>
        </div>
    );
};

export default DocumentTemplatesList;
