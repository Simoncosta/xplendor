import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Col, Dropdown, DropdownMenu, DropdownToggle, Row } from 'reactstrap';
import { Link, useLocation } from 'react-router-dom';
import classnames from 'classnames';

import bell from "../../assets/images/svg/bell.svg";
import SimpleBar from "simplebar-react";

import {
    getCompanyAlertsApi,
    getCompanyAlertsUnreadCountApi,
    markCompanyAlertReadApi,
    markCompanyAlertsReadApi,
} from '../../helpers/laravel_helper';
import { AlertItem } from '../../pages/Actions/types';

// O interceptor Axios (api_helper) desempacota `response.data`, portanto cada
// chamada devolve directamente o body JSON. Tipamos esse body aqui (sem `any`).
interface AlertsListResponse {
    data?: AlertItem[];
}

interface UnreadCountResponse {
    count?: number;
}

// Outros sítios (ex.: Action Center) re-sincronizam ao ouvir este evento;
// continuamos a emiti-lo sempre que marcamos algo como lido.
const ALERTS_UPDATED_EVENT = "xplendor-alerts-updated";

const NotificationDropdown = () => {
    const location = useLocation();
    const [isOpen, setIsOpen] = useState(false);
    const [alerts, setAlerts] = useState<AlertItem[]>([]);
    // Contagem verdadeira de não-lidos, vinda do endpoint dedicado — o badge
    // não pode depender da lista (limitada a 12) senão mente acima de 12.
    const [unreadTotal, setUnreadTotal] = useState(0);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");

        if (!authUser) return 0;

        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

    const fetchAll = useCallback(async () => {
        if (!companyId) {
            setAlerts([]);
            setUnreadTotal(0);
            return;
        }

        let list: AlertItem[] = [];

        try {
            const res = (await getCompanyAlertsApi(companyId, { limit: 12 })) as unknown as AlertsListResponse;
            list = res?.data ?? [];
        } catch {
            list = [];
        }

        setAlerts(list);

        try {
            const res = (await getCompanyAlertsUnreadCountApi(companyId)) as unknown as UnreadCountResponse;
            setUnreadTotal(Number(res?.count ?? 0));
        } catch {
            // Fallback: se o endpoint de contagem falhar, deriva da lista carregada.
            setUnreadTotal(list.filter((alert) => !alert.is_read).length);
        }
    }, [companyId]);

    useEffect(() => {
        fetchAll();

        const handleAlertsUpdated = () => fetchAll();

        window.addEventListener(ALERTS_UPDATED_EVENT, handleAlertsUpdated);

        return () => {
            window.removeEventListener(ALERTS_UPDATED_EVENT, handleAlertsUpdated);
        };
    }, [fetchAll, location.pathname]);

    const toggle = () => {
        const next = !isOpen;
        setIsOpen(next);

        // Refetch ao abrir → badge e lista sempre frescos, mesmo sem mudança de rota.
        if (next) {
            fetchAll();
        }
    };

    // Marcar UM como lido. Optimista (UI actualiza já) e fire-and-forget:
    // não bloqueia a navegação que acontece em paralelo pelo <Link>.
    const markOneRead = useCallback((alertId: number) => {
        if (!companyId) return;

        setAlerts((current) => current.map((alert) => (
            alert.id === alertId ? { ...alert, is_read: true } : alert
        )));
        setUnreadTotal((current) => Math.max(0, current - 1));

        markCompanyAlertReadApi(companyId, alertId)
            .then(() => window.dispatchEvent(new Event(ALERTS_UPDATED_EVENT)))
            .catch(() => { /* leitura é irreversível; re-sincroniza no próximo fetch */ });
    }, [companyId]);

    const handleItemClick = (alert: AlertItem) => {
        setIsOpen(false);

        if (!alert.is_read) {
            markOneRead(alert.id);
        }
    };

    const handleMarkAllRead = () => {
        if (!companyId || unreadTotal === 0) return;

        setAlerts((current) => current.map((alert) => ({ ...alert, is_read: true })));
        setUnreadTotal(0);

        // Endpoint bulk sem `ids` → marca todas as não-lidas da empresa.
        markCompanyAlertsReadApi(companyId)
            .then(() => window.dispatchEvent(new Event(ALERTS_UPDATED_EVENT)))
            .catch(() => { /* re-sincroniza no próximo fetch */ });
    };

    return (
        <React.Fragment>
            <Dropdown isOpen={isOpen} toggle={toggle} className="topbar-head-dropdown ms-1 header-item">
                <DropdownToggle type="button" tag="button" className="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle position-relative">
                    <i className='bx bx-bell fs-22'></i>
                    {unreadTotal > 0 && (
                        <span className="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger">
                            {unreadTotal > 9 ? "9+" : unreadTotal}
                            <span className="visually-hidden">notificações por ler</span>
                        </span>
                    )}
                </DropdownToggle>

                <DropdownMenu className="dropdown-menu-lg dropdown-menu-end p-0">
                    <div className="dropdown-head bg-primary bg-pattern rounded-top">
                        <div className="p-3">
                            <Row className="align-items-center">
                                <Col>
                                    <h6 className="m-0 fs-16 fw-semibold text-white">Notificações</h6>
                                </Col>
                                {unreadTotal > 0 && (
                                    <div className="col-auto">
                                        <span className="badge bg-light-subtle text-body fs-12">{unreadTotal} por ler</span>
                                    </div>
                                )}
                            </Row>
                        </div>

                        {unreadTotal > 0 && (
                            <div className="px-3 pb-2 text-end">
                                <button
                                    type="button"
                                    className="btn btn-link btn-sm p-0 text-white text-decoration-underline fs-12"
                                    onClick={handleMarkAllRead}
                                >
                                    Marcar todas como lidas
                                </button>
                            </div>
                        )}
                    </div>

                    <AlertsList alerts={alerts} onItemClick={handleItemClick} />
                </DropdownMenu>
            </Dropdown>
        </React.Fragment>
    );
};

interface AlertsListProps {
    alerts: AlertItem[];
    onItemClick: (alert: AlertItem) => void;
}

function AlertsList({ alerts, onItemClick }: AlertsListProps) {
    if (alerts.length === 0) {
        return (
            <div className="p-4">
                <div className="w-25 w-sm-50 pt-3 mx-auto">
                    <img src={bell} className="img-fluid" alt="Sem notificações" />
                </div>
                <div className="text-center pb-4 mt-2">
                    <h6 className="fs-16 fw-semibold lh-base mb-1">Estás em dia!</h6>
                    <p className="text-muted mb-0">Sem notificações por agora.</p>
                </div>
            </div>
        );
    }

    return (
        <SimpleBar style={{ maxHeight: "300px" }}>
            {alerts.map((alert) => {
                const isUnread = !alert.is_read;
                // Backend fornece detail_path (/cars/{id}/ficha); fallback defensivo.
                const detailPath = alert.detail_path || `/cars/${alert.car_id}`;

                return (
                    <Link
                        key={alert.id}
                        to={detailPath}
                        onClick={() => onItemClick(alert)}
                        className={classnames("dropdown-item notification-item d-block text-reset py-2", {
                            active: isUnread,
                        })}
                    >
                        <div className="d-flex align-items-start">
                            <div className="avatar-xs me-3 flex-shrink-0">
                                <span className={`avatar-title rounded-circle fs-16 ${resolveAlertTone(alert)}`}>
                                    <i className={resolveAlertIcon(alert)}></i>
                                </span>
                            </div>

                            <div className="flex-grow-1" style={{ minWidth: 0 }}>
                                <h6 className={classnames("mt-0 mb-1 fs-13 text-truncate", isUnread ? "fw-bold" : "fw-medium text-muted")}>
                                    {alert.title}
                                </h6>
                                <p className={classnames("mb-1 fs-13", isUnread ? "text-body" : "text-muted")}>
                                    {alert.message}
                                </p>
                                {alert.car_name && (
                                    <p className="mb-1 fs-12 fw-medium text-muted text-truncate">{alert.car_name}</p>
                                )}
                                <p className="mb-0 fs-11 text-muted">
                                    <i className="mdi mdi-clock-outline"></i> {formatRelativeDate(alert.created_at)}
                                </p>
                            </div>

                            <div className="flex-shrink-0 ps-2 d-flex align-items-center" style={{ minWidth: 16 }}>
                                {isUnread && (
                                    <span
                                        className="rounded-circle bg-primary d-inline-block"
                                        style={{ width: 8, height: 8 }}
                                        aria-hidden="true"
                                    />
                                )}
                            </div>
                        </div>
                    </Link>
                );
            })}
        </SimpleBar>
    );
}

function resolveAlertTone(alert: AlertItem): string {
    return {
        urgent: "bg-danger-subtle text-danger",
        warning: "bg-warning-subtle text-warning",
        opportunity: "bg-success-subtle text-success",
    }[alert.type];
}

function resolveAlertIcon(alert: AlertItem): string {
    return {
        urgent: "ri-alarm-warning-line",
        warning: "ri-error-warning-line",
        opportunity: "ri-checkbox-circle-line",
    }[alert.type];
}

function formatRelativeDate(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    const diffMs = Date.now() - date.getTime();
    const diffMinutes = Math.max(1, Math.floor(diffMs / 60000));

    if (diffMinutes < 60) {
        return `${diffMinutes} min atrás`;
    }

    const diffHours = Math.floor(diffMinutes / 60);

    if (diffHours < 24) {
        return `${diffHours} h atrás`;
    }

    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays} dia${diffDays > 1 ? "s" : ""} atrás`;
}

export default NotificationDropdown;
