//React
import { useEffect, useMemo } from "react";
import { Col, Label, Row } from "reactstrap";
import Select from "react-select";
import { createSelector } from "reselect";
import { useDispatch, useSelector } from "react-redux";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Datas
import { statusOptions, originOptions } from "common/data/cars";

// Models
import { ICarUpdatePayload } from "common/models/car.model";
import { getUsersPaginate } from "slices/users/thunk";

const selectUserState = (state: any) => state.User;

const selectSellerOptionsViewModel = createSelector(
    [selectUserState],
    (userState: any) => ({
        users: userState.data.users || [],
        loading: userState.loading.list,
    })
);

const vehicleTypeOptions = [
    { value: "car", label: "Carro" },
    { value: "motorhome", label: "Autocaravana" },
];

export default function CarInformationDataFields({
    isEdit,
    companyId,
    onStatusChange,
}: {
    isEdit: boolean;
    companyId?: number;
    onStatusChange?: (nextStatus: string | null, previousStatus: string | null) => void;
}) {
    const dispatch: any = useDispatch();
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();
    const { users, loading } = useSelector(selectSellerOptionsViewModel);
    const authUserRaw = sessionStorage.getItem("authUser");
    const authUser = authUserRaw ? JSON.parse(authUserRaw) : null;
    const resolvedCompanyId = Number(companyId ?? authUser?.company_id ?? 0);

    useEffect(() => {
        if (!resolvedCompanyId) return;

        dispatch(getUsersPaginate({
            page: 1,
            perPage: 99,
            companyId: resolvedCompanyId,
        }));
    }, [dispatch, resolvedCompanyId]);

    const sellerOptions = useMemo(() => (
        (users || []).map((user: any) => ({
            value: user.id,
            label: `${user.name}${user.role === "admin" ? " (Admin)" : ""}`,
        }))
    ), [users]);

    return (
        <div>
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Informação da Viatura</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <Label className="form-label">
                        Status:
                    </Label>
                    <Select
                        name="status"
                        options={statusOptions}
                        value={statusOptions.find((opt) => opt.value === values.status) || null}
                        onChange={(opt: any) => {
                            const previousStatus = values.status ?? null;
                            const nextStatus = opt?.value ?? null;

                            setFieldValue("status", nextStatus);
                            onStatusChange?.(nextStatus, previousStatus);
                        }}
                        onBlur={() => setFieldTouched("status", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label className="form-label">
                        Origem:
                    </Label>
                    <Select
                        name="origin"
                        options={originOptions}
                        value={originOptions.find((opt) => opt.value === values.origin) || null}
                        onChange={(opt: any) => setFieldValue("origin", opt?.value ?? null)}
                        onBlur={() => setFieldTouched("origin", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label className="form-label">
                        Vendedor:
                    </Label>
                    <Select
                        name="seller_user_id"
                        options={sellerOptions}
                        isClearable
                        isLoading={loading}
                        placeholder="Selecionar vendedor"
                        value={sellerOptions.find((opt: any) => opt.value === values.seller_user_id) || null}
                        onChange={(opt: any) => setFieldValue("seller_user_id", opt?.value ?? null)}
                        onBlur={() => setFieldTouched("seller_user_id", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label className="form-label">
                        Tipo de veículo:
                    </Label>
                    <Select
                        name="vehicle_type"
                        options={vehicleTypeOptions}
                        value={vehicleTypeOptions.find((opt) => opt.value === values.vehicle_type) || vehicleTypeOptions[0]}
                        onChange={(opt: any) => setFieldValue("vehicle_type", opt?.value ?? "car")}
                        onBlur={() => setFieldTouched("vehicle_type", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="license_plate"
                        label="Matrícula"
                        placeholder="Matrícula"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="vin"
                        label="VIN"
                        placeholder="VIN"
                        className="mb-3"
                    />
                </Col>
            </Row>
        </div>
    )
}
