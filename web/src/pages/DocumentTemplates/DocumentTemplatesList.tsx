// React
import React, { useEffect, useMemo, useRef, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Button, Card, CardBody, CardHeader, Col, Container, Row, Input, Label, Badge, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
// Components
import XButton from "Components/Common/XButton";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
// Redux
import { getDocumentTemplates, createDocumentTemplate, updateDocumentTemplate, deleteDocumentTemplate } from "slices/documentTemplates/thunk";
// Helpers
import { getDocumentTemplateVariables, replaceDocumentTemplateFile } from "helpers/laravel_helper";
import { downloadGet } from "helpers/download_helper";
import { confirmDelete, promptInput } from "helpers/swal";
// Models
import { IDocumentTemplate, IDocumentVariable } from "common/models/documentTemplate.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

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
    const companyId = useWorkingCompanyId();

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
        if (!name.trim()) { toast.error("Indique o nome do modelo."); return; }
        if (!file) { toast.error("Escolha um ficheiro .docx."); return; }
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
                <PageHeader title="Modelos de documento" breadcrumbs={[{ label: "Finanças" }]}
                    description={<>Carregue um .docx com variáveis <code>{"{{ }}"}</code>. Depois, em cada venda, pode gerar o documento preenchido para descarregar.</>}
                    actions={<Button color="outline-primary" onClick={downloadExample}><i className="ri-download-2-line me-1" />Exemplo .docx</Button>} />

                <Row>
                    {/* Coluna esquerda: variáveis + exemplo */}
                    <Col lg={4} className="mb-3">
                        <Card className="h-100">
                            <CardHeader>
                                <h5 className="card-title mb-0">Variáveis disponíveis</h5>
                            </CardHeader>
                            <CardBody>
                                <p className="text-muted fs-13">Clique numa variável para a copiar e cole-a no seu .docx (Word ou Google Docs).</p>
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
                                                    className="btn btn-sm btn-outline-primary"
                                                    title={`${v.label}: clique para copiar {{${v.key}}}`}
                                                    onClick={() => copyTag(v.key)}
                                                >
                                                    <i className="ri-file-copy-line me-1" />{`{{${v.key}}}`}
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
                            <CardHeader><h5 className="card-title mb-0">Carregar novo modelo</h5></CardHeader>
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
                            <CardHeader><h5 className="card-title mb-0">Os meus modelos</h5></CardHeader>
                            <CardBody>
                                {loadingList ? (
                                    <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                                ) : templates.length === 0 ? (
                                    <p className="text-muted mb-0">Ainda não há modelos. Carregue o primeiro acima.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-2">
                                        {templates.map((t) => (
                                            <div key={t.id} className="d-flex align-items-center justify-content-between gap-2 border rounded p-3">
                                                <div className="min-w-0 text-break">
                                                    <div className="fw-medium"><i className="ri-file-word-2-line text-primary me-1" />{t.name}{t.archived && <Badge color="light" className="ms-2 text-muted">Arquivado</Badge>}</div>
                                                </div>
                                                <div className="d-flex gap-1 flex-shrink-0">
                                                    <Button size="sm" color="outline-primary" onClick={() => pickReplacement(t)} disabled={replacingId === t.id} title="Substituir ficheiro" aria-label={`Substituir ficheiro: ${t.name}`}>
                                                        {replacingId === t.id ? <Spinner size="sm" /> : <i className="ri-file-upload-line" />}
                                                    </Button>
                                                    <Button size="sm" color="outline-primary" onClick={() => rename(t)} title="Renomear" aria-label={`Renomear: ${t.name}`}><i className="ri-pencil-line" /></Button>
                                                    <ActionsMenu size="sm" label={`Mais ações: ${t.name}`} items={[
                                                        { label: t.archived ? "Reativar" : "Arquivar", icon: t.archived ? "ri-inbox-unarchive-line" : "ri-archive-line", onClick: () => void toggleArchive(t) },
                                                        { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(t) },
                                                    ]} />
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
