// React
import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from 'reselect';
// Components
import XInput from "Components/Common/XInput";
import XInputMask from "Components/Common/XInputMask";
import Select from "react-select";
import { Row, Col, Label, FormFeedback } from "reactstrap";
// Slices
import { getDistricts } from 'slices/districts/thunk';
import { getMunicipalities } from 'slices/municipalities/thunk';
import { getParishes } from 'slices/parishes/thunk';
// Forms
import { useField, useFormikContext } from "formik";
// Models
import { IDistrict } from "common/models/district.model";
import { IMunicipality } from "common/models/municipality.model";
import { IParish } from "common/models/parish.model";

export default function CompanyGeneralDataFields({ isEdit }: { isEdit: boolean }) {
    const dispatch: any = useDispatch();

    const { setFieldValue, setFieldTouched } = useFormikContext<any>();
    const [field, meta] = useField<number | null>("district_id");
    const [fieldMunicipality, metaMunicipality] = useField<number | null>("municipality_id");
    const [fieldParish, metaParish] = useField<number | null>("parish_id");

    const selectDistrictState = (state: any) => state.District;
    const selectMunicipalityState = (state: any) => state.Municipality;
    const selectParishState = (state: any) => state.Parish;

    const districtSelector = createSelector(selectDistrictState, (state: any) => ({
        data: state.districts,
        loading: state.loading,
    }));

    const municipalitySelector = createSelector(selectMunicipalityState, (state: any) => ({
        data: state.municipalities,
        loading: state.loading,
    }));

    const parishSelector = createSelector(selectParishState, (state: any) => ({
        data: state.parishes,
        loading: state.loading,
    }));

    const { data: districts, loading: loadingDistricts } = useSelector(districtSelector);
    const { data: municipalities, loading: loadingMunicipalities } = useSelector(municipalitySelector);
    const { data: parishes, loading: loadingParishes } = useSelector(parishSelector);

    const hasError = Boolean(meta.touched && meta.error);
    const selected = districts.find((d: IDistrict) => d.id === field.value) ?? null;
    const selectedMunicipality = municipalities.find((m: IMunicipality) => m.id === fieldMunicipality.value) ?? null;
    const selectedParish = parishes.find((m: IParish) => m.id === fieldParish.value) ?? null;

    useEffect(() => {
        dispatch(getDistricts());
    }, [dispatch]);

    useEffect(() => {
        if (!selected) return;
        dispatch(getMunicipalities(selected?.id));
    }, [selected]);

    useEffect(() => {
        if (!selectedMunicipality) return;
        dispatch(getParishes(selectedMunicipality?.id));
    }, [selectedMunicipality]);

    return (
        <Row>
            {!isEdit && (
                <>
                    <div className="mb-2 border-bottom pb-2">
                        <h5 className="card-title">Login</h5>
                    </div>
                    <Col lg={6}>
                        <XInput
                            className='mb-2'
                            name="name_user"
                            label="Nome do usuário"
                            placeholder="Nome do usuário"
                            required={!isEdit}
                        />
                    </Col>
                    <Col lg={6}>
                        <XInput
                            type="email"
                            className='mb-2'
                            name="email_user"
                            label="Email de acesso"
                            placeholder="Email de acesso"
                            required={!isEdit}
                        />
                    </Col>
                </>
            )}
            <div className={`mb-2 border-bottom pb-2 ${!isEdit ? "mt-4" : ""}`}>
                <h5 className="card-title">Dados Gerais</h5>
            </div>
            <Col lg={6}>
                <XInput
                    className='mb-2'
                    name="nipc"
                    label="NIPC"
                    placeholder="Introduza o NIPC"
                    disabled={isEdit}
                    required
                />
            </Col>
            <Col lg={6}>
                <XInput
                    className='mb-2'
                    name="fiscal_name"
                    label="Designação Fiscal"
                    placeholder="Designação fiscal"
                    disabled={isEdit}
                    required
                />
            </Col>
            <Col lg={6}>
                <XInput
                    className='mb-2'
                    name="trade_name"
                    label="Nome Comercial"
                    placeholder="Nome comercial"
                />
            </Col>
            <Col lg={6}>
                <XInput
                    className='mb-2'
                    name="responsible_name"
                    label="Nome Responsável"
                    placeholder="Nome Responsável"
                />
            </Col>
            <Col lg={3}>
                <XInputMask
                    className='mb-2'
                    name="phone"
                    label="Telefone"
                    placeholder="123 456 789"
                    options={{
                        blocks: [3, 3, 3],
                        delimiter: " ",
                        numericOnly: true,
                    }}
                />
            </Col>
            <Col lg={3}>
                <XInputMask
                    className='mb-2'
                    name="mobile"
                    label="Telemóvel"
                    placeholder="123 456 789"
                    options={{
                        blocks: [3, 3, 3],
                        delimiter: " ",
                        numericOnly: true,
                    }}
                />
            </Col>
            <Col lg={3}>
                <XInput
                    type="email"
                    className='mb-2'
                    name="email"
                    label="Email"
                    placeholder="Email"
                />
            </Col>
            <Col lg={3}>
                <XInput
                    type="email"
                    className='mb-2'
                    name="invoice_email"
                    label="Email de faturação"
                    placeholder="Email de faturação"
                />
            </Col>
            <div className="mt-4 mb-2 border-bottom pb-2">
                <h5 className="card-title">Social</h5>
            </div>
            <Col lg={3}>
                <XInput
                    type="url"
                    className='mb-2'
                    name="website"
                    label="Website"
                    placeholder="Website"
                />
            </Col>
            <Col lg={2}>
                <XInput
                    className='mb-2'
                    name="instagram"
                    label="Instagram"
                    placeholder="@xplendor"
                />
            </Col>
            <Col lg={2}>
                <XInput
                    className='mb-2'
                    name="facebook"
                    label="Facebook"
                    placeholder="facebook"
                />
            </Col>
            <Col lg={2}>
                <XInput
                    className='mb-2'
                    name="youtube"
                    label="Youtube"
                    placeholder="youtube"
                />
            </Col>
            <Col lg={3}>
                <XInput
                    className='mb-2'
                    name="google"
                    label="Google"
                    placeholder="Google"
                />
            </Col>
            <div className="mt-4 mb-2 border-bottom pb-2">
                <h5 className="card-title">Endereço</h5>
            </div>
            <Col lg={2}>
                <XInputMask
                    className='mb-2'
                    name="postal_code"
                    label="Código Postal"
                    placeholder="1234-567"
                    options={{
                        blocks: [4, 3],
                        delimiter: "-",
                        numericOnly: true,
                    }}
                />
            </Col>
            <Col lg={10}>
                <XInput
                    className='mb-2'
                    name="address"
                    label="Endereço"
                    placeholder="Endereço"
                />
            </Col>
            <Col lg={4}>
                <Label className="form-label">
                    Distrito:
                </Label>
                <Select
                    className="mb-3"
                    inputId="district_id"
                    name="district_id"
                    options={districts}
                    getOptionLabel={(o: IDistrict) => o.name}
                    getOptionValue={(o: IDistrict) => String(o.id)}
                    value={selected}
                    onChange={(opt: IDistrict) => setFieldValue("district_id", (opt as IDistrict | null)?.id ?? null)}
                    onBlur={() => setFieldTouched("district_id", true)}
                    isDisabled={districts.length === 0}
                    classNamePrefix="react-select"
                    styles={{
                        control: (base: any) => ({
                            ...base,
                            borderColor: hasError ? "#dc3545" : base.borderColor,
                            boxShadow: "none",
                        }),
                    }}
                />

                {hasError && (
                    <FormFeedback style={{ display: "block" }}>
                        {String(meta.error)}
                    </FormFeedback>
                )}
            </Col>
            <Col lg={4}>
                <Label className="form-label">
                    Município:
                </Label>
                <Select
                    className="mb-3"
                    inputId="municipality_id"
                    name="municipality_id"
                    options={municipalities}
                    getOptionLabel={(o: IMunicipality) => o.name}
                    getOptionValue={(o: IMunicipality) => String(o.id)}
                    value={selectedMunicipality}
                    onChange={(opt: IMunicipality) => setFieldValue("municipality_id", (opt as IMunicipality | null)?.id ?? null)}
                    onBlur={() => setFieldTouched("municipality_id", true)}
                    isDisabled={municipalities.length === 0}
                    classNamePrefix="react-select"
                    styles={{
                        control: (base: any) => ({
                            ...base,
                            borderColor: hasError ? "#dc3545" : base.borderColor,
                            boxShadow: "none",
                        }),
                    }}
                />

                {hasError && (
                    <FormFeedback style={{ display: "block" }}>
                        {String(meta.error)}
                    </FormFeedback>
                )}
            </Col>
            <Col lg={4}>
                <Label className="form-label">
                    Freguesia:
                </Label>
                <Select
                    className="mb-3"
                    inputId="parish_id"
                    name="parish_id"
                    options={parishes}
                    getOptionLabel={(o: IParish) => o.name}
                    getOptionValue={(o: IParish) => String(o.id)}
                    value={selectedParish}
                    onChange={(opt: IParish) => setFieldValue("parish_id", (opt as IParish | null)?.id ?? null)}
                    onBlur={() => setFieldTouched("parish_id", true)}
                    isDisabled={parishes.length === 0}
                    classNamePrefix="react-select"
                    styles={{
                        control: (base: any) => ({
                            ...base,
                            borderColor: hasError ? "#dc3545" : base.borderColor,
                            boxShadow: "none",
                        }),
                    }}
                />

                {hasError && (
                    <FormFeedback style={{ display: "block" }}>
                        {String(meta.error)}
                    </FormFeedback>
                )}
            </Col>
        </Row>
    )
}