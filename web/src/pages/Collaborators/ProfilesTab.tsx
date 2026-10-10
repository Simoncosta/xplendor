import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import { confirmAction } from "helpers/swal";
import { assignUserProfile, deletePermissionProfile, getPermissionProfiles } from "helpers/laravel_helper";
import { PermissionProfile, ProfilesPayload, SIDE_LABEL } from "common/models/permissionProfile.model";
import { useModules } from "contexts/ModulesContext";
import ProfileEditorModal from "./ProfileEditorModal";

/**
 * ACL (F5): Configurações › Colaboradores › Perfis. Os perfis da empresa (o Administrador e
 * os "como hoje" são de sistema; os outros criam-se a partir de sugestões, D13) e o perfil de
 * cada pessoa. Cada empresa tem sempre pelo menos um administrador ativo (D12): o backend
 * recusa e explica.
 */
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type UserRow = ProfilesPayload["users"][number];

export default function ProfilesTab({ companyId }: { companyId: number }) {
    const { reason } = useModules();
    const [data, setData] = useState<ProfilesPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [editor, setEditor] = useState<null | "new" | PermissionProfile | { from: PermissionProfile }>(null);
    const [details, setDetails] = useState<PermissionProfile | null>(null);
    const [saving, setSaving] = useState<number | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getPermissionProfiles(companyId);
            setData(r?.data ?? null);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível carregar os perfis."));
        } finally { setLoading(false); }
    }, [companyId]);
    useEffect(() => { void load(); }, [load]);

    const canManage = !!data?.can_manage;
    const manageReason = canManage ? null : reason("utilizadores.configurar");
    const listed = (data?.profiles ?? []).filter((p) => !p.is_suggestion);
    const assignable = (side: "cliente" | "agencia") => listed.filter((p) => p.side === side && p.assignable);
    const name = (id: number | null) => listed.find((p) => p.id === id)?.name ?? "Sem perfil";

    const assign = async (u: UserRow, profileId: number) => {
        setSaving(u.id);
        try {
            await assignUserProfile(companyId, u.id, profileId);
            toast.success(`Perfil de ${u.name} atualizado.`);
            await load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível atribuir o perfil."));
        } finally { setSaving(null); }
    };

    const remove = async (p: PermissionProfile) => {
        const ok = await confirmAction({ title: `Apagar o perfil "${p.name}"?`, text: "Só se apaga um perfil que não esteja atribuído a ninguém.", confirmText: "Apagar", confirmVariant: "danger" });
        if (!ok) return;
        try {
            await deletePermissionProfile(companyId, p.id);
            toast.success("Perfil apagado.");
            await load();
        } catch (e: any) { toast.error(errorMessage(e, "Não foi possível apagar o perfil.")); }
    };

    const kind = (p: PermissionProfile) => p.is_admin
        ? <Badge color="primary-subtle" className="text-primary fw-medium">Sistema, não se edita</Badge>
        : p.is_system ? <Badge color="light" className="text-body fw-medium">Sistema</Badge>
            : <Badge color="success-subtle" className="text-success fw-medium">Personalizado</Badge>;

    const profileCols = useDataColumns<PermissionProfile>("configuracoes.perfis", [
        {
            id: "name", header: "Perfil", value: (p) => p.name, hideable: false, mobile: "title",
            cell: (p) => (
                <div className="min-w-0">
                    <div className="fw-medium text-body">{p.name}</div>
                    {p.description && <div className="text-muted fs-12 text-wrap">{p.description}</div>}
                </div>
            ),
        },
        { id: "side", header: "Para quem", value: (p) => SIDE_LABEL[p.side], cell: (p) => <span className="fs-13">{SIDE_LABEL[p.side]}</span> },
        { id: "kind", header: "Tipo", value: (p) => (p.is_system ? "Sistema" : "Personalizado"), cell: kind },
        { id: "users", header: "Pessoas", value: (p) => p.users, align: "end", cell: (p) => (p.side === "teto" ? <span className="text-muted fs-12">Teto</span> : p.users) },
    ] as DTColumn<PermissionProfile>[]);

    const userCols = useDataColumns<UserRow>("configuracoes.perfis.pessoas", [
        {
            id: "name", header: "Pessoa", value: (u) => `${u.name} ${u.email}`, hideable: false, mobile: "title",
            cell: (u) => (
                <div className="min-w-0">
                    <div className="fw-medium text-body">{u.name}{!u.active && <small className="text-muted ms-1">(sem acesso)</small>}</div>
                    <div className="text-muted fs-12 text-truncate">{u.email}</div>
                </div>
            ),
        },
        {
            id: "profile", header: "Perfil na empresa", value: (u) => name(u.profile_id),
            cell: (u) => canManage ? (
                <div style={{ minWidth: 200 }} onClick={(e) => e.stopPropagation()}>
                    <XSelect small ariaLabel={`Perfil de ${u.name}`} value={u.profile_id ?? 0} disabled={saving === u.id} searchable={false}
                        onChange={(v) => { if (v && v !== u.profile_id) void assign(u, Number(v)); }}
                        options={assignable("cliente").map((p) => ({ value: p.id, label: p.name }))} />
                </div>
            ) : <span className="fs-13">{name(u.profile_id)}{u.approver && <small className="text-muted ms-1">(aprova conteúdos)</small>}</span>,
        },
        ...(data?.is_agency ? [{
            id: "agency", header: "Dentro dos clientes", value: (u: UserRow) => name(u.agency_profile_id),
            cell: (u: UserRow) => canManage ? (
                <div style={{ minWidth: 200 }} onClick={(e) => e.stopPropagation()}>
                    <XSelect small ariaLabel={`Perfil de ${u.name} dentro dos clientes`} value={u.agency_profile_id ?? 0} disabled={saving === u.id} searchable={false}
                        onChange={(v) => { if (v && v !== u.agency_profile_id) void assign(u, Number(v)); }}
                        options={assignable("agencia").map((p) => ({ value: p.id, label: p.name }))} />
                </div>
            ) : <span className="fs-13">{name(u.agency_profile_id)}</span>,
        }] : []),
    ] as DTColumn<UserRow>[]);

    return (
        <>
            <PageCard
                title="Perfis"
                info="O que cada pessoa pode fazer na empresa. O Administrador pode tudo e é de sistema; cada empresa tem sempre pelo menos um administrador ativo."
                status={!loading && data ? <>{listed.length} perfi{listed.length === 1 ? "l" : "s"}</> : undefined}
                loading={loading && !!data}
                actions={<>
                    {profileCols.selector}
                    <ReasonButton color="primary" size="sm" onClick={() => setEditor("new")} reason={manageReason}><i className="ri-add-line me-1" />Novo perfil</ReasonButton>
                </>}
            >
                <DataTable
                    columns={profileCols}
                    data={listed}
                    rowKey={(p) => p.id}
                    loading={loading && !data}
                    caption="Perfis"
                    onRowClick={(p) => setDetails(p)}
                    empty={{ message: "Sem perfis." }}
                    rowActions={(p) => (
                        <>
                            <Button size="sm" color="outline-primary" aria-label={`${p.editable && canManage ? "Editar" : "Ver"}: ${p.name}`}
                                onClick={() => (p.editable && canManage ? setEditor(p) : setDetails(p))}>
                                <i className={p.editable && canManage ? "ri-pencil-line" : "ri-eye-line"} />
                            </Button>
                            <ActionsMenu size="sm" label={`Mais ações: ${p.name}`} items={[
                                { label: "Criar perfil a partir deste", icon: "ri-file-copy-line", hidden: !canManage || p.is_admin, onClick: () => setEditor({ from: p }) },
                                { label: "Apagar", icon: "ri-delete-bin-line", danger: true, hidden: !canManage || !p.editable, onClick: () => void remove(p) },
                                { label: "Ver o que dá", icon: "ri-eye-line", onClick: () => setDetails(p) },
                            ]} />
                        </>
                    )}
                />
            </PageCard>

            <PageCard title="Pessoas e perfis" status={!loading && data ? <>{data.users.length} {data.users.length === 1 ? "pessoa" : "pessoas"}</> : undefined}
                info="O perfil Administrador anda com o papel de administrador. Para retirar o último administrador, nomeie primeiro outro.">
                <DataTable columns={userCols} data={data?.users ?? []} rowKey={(u) => u.id} loading={loading && !data} caption="Pessoas e perfis"
                    empty={{ message: "Sem pessoas com acesso à plataforma." }} />
            </PageCard>

            {data && <ProfileEditorModal companyId={companyId} data={data} target={editor} onClose={() => setEditor(null)} onSaved={() => { setEditor(null); void load(); }} />}

            <Modal isOpen={details !== null} toggle={() => setDetails(null)} centered size="lg" scrollable>
                <ModalHeader toggle={() => setDetails(null)}>{details?.name}</ModalHeader>
                <ModalBody>
                    <p className="text-muted fs-13">{details?.description ?? (details ? SIDE_LABEL[details.side] : "")}</p>
                    <ul className="list-unstyled vstack gap-1 mb-0">
                        {details?.summary.map((s) => (
                            <li key={s.area} className="d-flex flex-wrap gap-2 fs-13">
                                <span className="fw-medium" style={{ minWidth: 170 }}>{s.label}</span>
                                <span className={s.actions.length ? "" : "text-muted"}>{s.text}</span>
                            </li>
                        ))}
                    </ul>
                </ModalBody>
                <ModalFooter><Button color="light" onClick={() => setDetails(null)}>Fechar</Button></ModalFooter>
            </Modal>
        </>
    );
}
