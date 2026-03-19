import React, { useMemo } from "react";
import { createSelector } from "reselect";
import { useSelector } from "react-redux";
import { Card, CardBody, Col } from "reactstrap";

const selectLoginState = (state: any) => state.Login;
const selectAuthUserViewModel = createSelector(
    [selectLoginState],
    (loginState) => ({
        user: loginState?.data?.user ?? null,
    })
);

type TCompanySubscriptionData = {
    subscription_status?: string | null;
    trial_ends_at?: string | null;
    subscription_ends_at?: string | null;
};

const readSessionAuthUser = () => {
    try {
        const authUser = sessionStorage.getItem("authUser");
        return authUser ? JSON.parse(authUser) : null;
    } catch {
        return null;
    }
};

const resolveCompanySubscription = (authUser: any): TCompanySubscriptionData => {
    const company = authUser?.company ?? {};

    return {
        subscription_status: company.subscription_status ?? authUser?.subscription_status ?? null,
        trial_ends_at: company.trial_ends_at ?? authUser?.trial_ends_at ?? null,
        subscription_ends_at: company.subscription_ends_at ?? authUser?.subscription_ends_at ?? null,
    };
};

const getDaysUntil = (value?: string | null) => {
    if (!value) return null;

    const target = new Date(value);
    if (Number.isNaN(target.getTime())) return null;

    const diffMs = target.getTime() - Date.now();
    const dayMs = 1000 * 60 * 60 * 24;

    return Math.max(0, Math.ceil(diffMs / dayMs));
};

const formatDatePt = (value?: string | null) => {
    if (!value) return null;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;

    return new Intl.DateTimeFormat("pt-PT", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    }).format(date);
};

export default function SubscriptionTrialBanner() {
    const { user } = useSelector(selectAuthUserViewModel);

    const bannerData = useMemo(() => {
        const authUser = (user && Object.keys(user).length > 0) ? user : readSessionAuthUser();
        const subscription = resolveCompanySubscription(authUser);
        const status = subscription.subscription_status;

        if (status !== "trial") {
            return null;
        }

        const daysLeft = getDaysUntil(subscription.trial_ends_at);
        const formattedDate = formatDatePt(subscription.trial_ends_at);

        if (daysLeft === null || !formattedDate) {
            return null;
        }

        const isExpiringSoon = daysLeft <= 5;

        return {
            isExpiringSoon,
            iconClass: isExpiringSoon ? "ri-alarm-warning-line" : "ri-time-line",
            cardClass: isExpiringSoon
                ? "border-warning bg-warning-subtle"
                : "border-primary bg-primary-subtle",
            iconWrapperClass: isExpiringSoon
                ? "bg-warning text-warning-emphasis"
                : "bg-primary text-white",
            title: isExpiringSoon
                ? `O teu trial expira em ${daysLeft} ${daysLeft === 1 ? "dia" : "dias"}`
                : `Trial ativo até ${formattedDate}`,
            subtitle: isExpiringSoon
                ? "Garante a continuidade da tua conta antes do fim do período experimental."
                : "Estás a explorar a Xplendor com acesso experimental completo.",
            badgeText: isExpiringSoon
                ? `Expira em ${daysLeft} ${daysLeft === 1 ? "dia" : "dias"}`
                : "Trial",
            badgeClass: isExpiringSoon
                ? "bg-warning text-warning-emphasis"
                : "bg-white text-primary",
        };
    }, [user]);

    if (!bannerData) {
        return null;
    }

    return (
        <Col xl={12}>
            <Card className={`border-0 shadow-sm overflow-hidden ${bannerData.cardClass}`}>
                <CardBody className="p-3 p-lg-4">
                    <div className="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div className="d-flex align-items-start gap-3 flex-grow-1">
                            <div className="flex-shrink-0">
                                <div
                                    className={`rounded-circle d-flex align-items-center justify-content-center ${bannerData.iconWrapperClass}`}
                                    style={{ width: 48, height: 48 }}
                                >
                                    <i className={`${bannerData.iconClass} fs-4`} />
                                </div>
                            </div>

                            <div className="flex-grow-1">
                                <div className="d-flex flex-column flex-lg-row align-items-lg-center gap-2 mb-1">
                                    <span className={`badge rounded-pill px-3 py-2 ${bannerData.badgeClass}`}>
                                        {bannerData.badgeText}
                                    </span>
                                    <h5 className="mb-0">{bannerData.title}</h5>
                                </div>

                                <p className="text-muted mb-0">
                                    {bannerData.subtitle}
                                </p>
                            </div>
                        </div>
                    </div>
                </CardBody>
            </Card>
        </Col>
    );
}
