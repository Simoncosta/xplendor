import { Link } from "react-router-dom";
import { FOLDER_ICON, MailFolder } from "./types";
import { FoldersSkeleton } from "./skeletons";

/**
 * E-mail 4 · As pastas da caixa (do LIST), no formato do EmailSidebar do modelo. Sem "Escrever"
 * nem etiquetas: responder e escrever chegam com o E-mail 5.
 */
export default function EmailSidebar({ email, folders, current, unread, onPick, profileLink, loading = false }: {
    email: string; folders: MailFolder[]; current: string; unread: number | null; onPick: (path: string) => void; profileLink: string; loading?: boolean;
}) {
    return (
        <div className="email-menu-sidebar">
            <div className="p-4 d-flex flex-column h-100">
                <div className="pb-3 border-bottom border-bottom-dashed">
                    <div className="text-muted fs-12 text-uppercase">A minha caixa</div>
                    {email ? <div className="fw-semibold text-truncate" title={email}>{email}</div>
                        : <div className="placeholder-glow"><span className="placeholder xp-skeleton-bar" style={{ width: "70%", height: 12 }} /></div>}
                </div>
                <div className="mx-n4 px-4 email-menu-sidebar-scroll overflow-auto">
                    {loading ? <FoldersSkeleton /> : <div className="mail-list mt-3" data-testid="mail-folders">
                        {folders.map((f) => (
                            <a href="#!" key={f.path} className={f.path === current ? "active" : ""} onClick={(e) => { e.preventDefault(); onPick(f.path); }}>
                                <i className={`${FOLDER_ICON[f.special ?? ""] ?? "ri-folder-2-fill"} me-3 align-middle fw-medium`} />
                                <span className="text-truncate">{f.name}</span>
                                {f.special === "inbox" && !!unread && unread > 0 && (
                                    <span className="badge bg-success-subtle text-success ms-auto">{unread > 99 ? "99+" : unread}</span>
                                )}
                            </a>
                        ))}
                    </div>}
                </div>
                <div className="mt-auto pt-3 border-top border-top-dashed">
                    <Link to={profileLink} className="text-muted fs-13"><i className="ri-settings-3-line me-1" />A minha caixa no Perfil</Link>
                </div>
            </div>
        </div>
    );
}
