import { Link } from "react-router-dom";
import useMailboxUnread, { unreadLabel } from "./useMailboxUnread";

/**
 * E-mail 3 · O envelope do cabeçalho, à esquerda do sino. Aparece sempre (para a pessoa saber que
 * o e-mail existe); o número de não lidos só com caixa e acima de 0. Leva a /email.
 */
export default function EmailHeaderButton() {
    const { hasMailbox, unread } = useMailboxUnread();
    const label = hasMailbox ? unreadLabel(unread) : null;
    return (
        <div className="ms-1 header-item">
            <Link to="/email" className="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle position-relative" aria-label="E-mail" title="E-mail" data-testid="header-email">
                <i className="bx bx-envelope fs-22" />
                {label && (
                    <span className="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger" data-testid="header-email-badge">
                        {label}
                        <span className="visually-hidden">e-mails por ler</span>
                    </span>
                )}
            </Link>
        </div>
    );
}
