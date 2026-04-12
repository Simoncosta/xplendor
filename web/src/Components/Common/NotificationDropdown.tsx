import React, { useEffect, useMemo, useState } from 'react';
import {
    Col,
    Dropdown,
    DropdownMenu,
    DropdownToggle,
    Nav,
    NavItem,
    NavLink,
    Row,
    TabContent,
    TabPane
} from 'reactstrap';
import { Link, useLocation } from 'react-router-dom';
import classnames from 'classnames';

import bell from "../../assets/images/svg/bell.svg";
import SimpleBar from "simplebar-react";

import { getCompanyAlertsApi, markCompanyAlertReadApi } from '../../helpers/laravel_helper';
import { AlertItem } from '../../pages/Actions/types';

const NotificationDropdown = () => {
    const location = useLocation();
    const [isNotificationDropdown, setIsNotificationDropdown] = useState(false);
    const [activeTab, setActiveTab] = useState('1');
    const [alerts, setAlerts] = useState<AlertItem[]>([]);
    const [markingAlertId, setMarkingAlertId] = useState<number | null>(null);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");

        if (!authUser) return 0;

        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

    const unreadCount = alerts.filter((alert) => !alert.is_read).length;
    const unreadAlerts = alerts.filter((alert) => !alert.is_read);

    const toggleNotificationDropdown = () => {
        setIsNotificationDropdown(!isNotificationDropdown);
    };

    const toggleTab = (tab: string) => {
        if (activeTab !== tab) {
            setActiveTab(tab);
        }
    };

    useEffect(() => {
        if (!companyId) {
            setAlerts([]);
            return;
        }

        const fetchAlerts = async () => {
            try {
                const response: any = await getCompanyAlertsApi(companyId, { limit: 12 });
                setAlerts(response?.data ?? []);
            } catch {
                setAlerts([]);
            }
        };

        fetchAlerts();

        const handleAlertsUpdated = () => {
            fetchAlerts();
        };

        window.addEventListener("xplendor-alerts-updated", handleAlertsUpdated);

        return () => {
            window.removeEventListener("xplendor-alerts-updated", handleAlertsUpdated);
        };
    }, [companyId, location.pathname]);

    const handleMarkAsRead = async (alertId: number) => {
        if (!companyId || markingAlertId === alertId) {
            return;
        }

        setMarkingAlertId(alertId);

        try {
            await markCompanyAlertReadApi(companyId, alertId);
            setAlerts((current) => current.map((alert) => (
                alert.id === alertId ? { ...alert, is_read: true } : alert
            )));
            window.dispatchEvent(new Event("xplendor-alerts-updated"));
        } finally {
            setMarkingAlertId(null);
        }
    };

    const visibleAlerts = activeTab === '2' ? unreadAlerts : alerts;

    return (
        <React.Fragment>
            <Dropdown isOpen={isNotificationDropdown} toggle={toggleNotificationDropdown} className="topbar-head-dropdown ms-1 header-item">
                <DropdownToggle type="button" tag="button" className="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle position-relative">
                    <i className='bx bx-bell fs-22'></i>
                    {unreadCount > 0 && (
                        <span className="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger">
                            {unreadCount > 9 ? "9+" : unreadCount}
                            <span className="visually-hidden">unread alerts</span>
                        </span>
                    )}
                </DropdownToggle>
                <DropdownMenu className="dropdown-menu-lg dropdown-menu-end p-0">
                    <div className="dropdown-head bg-primary bg-pattern rounded-top">
                        <div className="p-3">
                            <Row className="align-items-center">
                                <Col>
                                    <h6 className="m-0 fs-16 fw-semibold text-white"> Alertas </h6>
                                </Col>
                                <div className="col-auto dropdown-tabs">
                                    <span className="badge bg-light-subtle text-body fs-13"> {unreadCount} por ler</span>
                                </div>
                            </Row>
                        </div>

                        <div className="px-2 pt-2">
                            <Nav className="nav-tabs dropdown-tabs nav-tabs-custom">
                                <NavItem>
                                    <NavLink
                                        href="#"
                                        className={classnames({ active: activeTab === '1' })}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            toggleTab('1');
                                        }}
                                    >
                                        All ({alerts.length})
                                    </NavLink>
                                </NavItem>
                                <NavItem>
                                    <NavLink
                                        href="#"
                                        className={classnames({ active: activeTab === '2' })}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            toggleTab('2');
                                        }}
                                    >
                                        Alerts ({unreadCount})
                                    </NavLink>
                                </NavItem>
                            </Nav>
                        </div>
                    </div>

                    <TabContent activeTab={activeTab}>
                        <TabPane tabId="1" className="py-2 ps-2">
                            <AlertsList
                                alerts={visibleAlerts}
                                markingAlertId={markingAlertId}
                                onMarkAsRead={handleMarkAsRead}
                            />
                        </TabPane>

                        <TabPane tabId="2" className="py-2 ps-2">
                            <AlertsList
                                alerts={visibleAlerts}
                                markingAlertId={markingAlertId}
                                onMarkAsRead={handleMarkAsRead}
                            />
                        </TabPane>
                    </TabContent>
                </DropdownMenu>
            </Dropdown>
        </React.Fragment>
    );
};

interface AlertsListProps {
    alerts: AlertItem[];
    markingAlertId: number | null;
    onMarkAsRead: (alertId: number) => void;
}

function AlertsList({ alerts, markingAlertId, onMarkAsRead }: AlertsListProps) {
    if (alerts.length === 0) {
        return (
            <TabPane tabId="empty" className="p-4 d-block">
                <div className="w-25 w-sm-50 pt-3 mx-auto">
                    <img src={bell} className="img-fluid" alt="Sem alertas" />
                </div>
                <div className="text-center pb-4 mt-2">
                    <h6 className="fs-18 fw-semibold lh-base mb-1">Sem alertas neste momento</h6>
                    <p className="text-muted mb-0">Quando houver risco ou oportunidade, aparece aqui.</p>
                </div>
            </TabPane>
        );
    }

    return (
        <SimpleBar style={{ maxHeight: "300px" }} className="pe-2">
            {alerts.map((alert) => (
                <div
                    key={alert.id}
                    className={classnames("text-reset notification-item d-block dropdown-item position-relative", {
                        active: !alert.is_read,
                    })}
                >
                    <div className="d-flex">
                        <div className="avatar-xs me-3">
                            <span className={`avatar-title rounded-circle fs-16 ${resolveAlertTone(alert)}`}>
                                <i className={resolveAlertIcon(alert)}></i>
                            </span>
                        </div>
                        <div className="flex-grow-1">
                            <Link to={`/cars/${alert.car_id}`} className="stretched-link">
                                <h6 className="mt-0 mb-1 fs-13 fw-semibold">{alert.title}</h6>
                            </Link>
                            <div className="fs-13 text-muted">
                                <p className="mb-1">{alert.message}</p>
                                <p className="mb-1 fw-medium">{alert.car_name}</p>
                            </div>
                            <p className="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                <span><i className="mdi mdi-clock-outline"></i> {formatRelativeDate(alert.created_at)}</span>
                            </p>
                        </div>
                        <div className="px-2 fs-15">
                            <div className="form-check notification-check">
                                <input
                                    className="form-check-input"
                                    type="checkbox"
                                    checked={alert.is_read}
                                    disabled={alert.is_read || markingAlertId === alert.id}
                                    onChange={() => onMarkAsRead(alert.id)}
                                    id={`notification-alert-check-${alert.id}`}
                                />
                                <label className="form-check-label" htmlFor={`notification-alert-check-${alert.id}`}></label>
                            </div>
                        </div>
                    </div>
                </div>
            ))}

            <div className="my-3 text-center">
                <Link to="/actions" className="btn btn-soft-success waves-effect waves-light">
                    Ver Action Center <i className="ri-arrow-right-line align-middle"></i>
                </Link>
            </div>
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
