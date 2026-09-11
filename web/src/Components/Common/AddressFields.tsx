// React
import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
// Components
import XInput from "Components/Common/XInput";
import XInputMask from "Components/Common/XInputMask";
import Select from "react-select";
import { Row, Col, Label, FormFeedback } from "reactstrap";
// Forms
import { useField, useFormikContext } from "formik";
// Slices
import { getDistricts } from "slices/districts/thunk";
import { getMunicipalities } from "slices/municipalities/thunk";
import { getParishes } from "slices/parishes/thunk";
// Models
import { IDistrict } from "common/models/district.model";
import { IMunicipality } from "common/models/municipality.model";
import { IParish } from "common/models/parish.model";

/**
 * Bloco de morada partilhado — mesma estrutura usada na configuração da empresa.
 * Espera que o formulário Formik tenha os campos: address, postal_code,
 * district_id, municipality_id, parish_id. Reutilizado em Empresas e Fornecedores
 * (não duplicar os selects encadeados nem a lógica de carregamento).
 *
 * Extraído de CompanyGeneralDataFields.tsx na sub-fase 1c.1 do DMS.
 */

const selectDistrictState = (state: any) => state.District;
const selectMunicipalityState = (state: any) => state.Municipality;
const selectParishState = (state: any) => state.Parish;

const selectDistrictViewModel = createSelector(
    [selectDistrictState],
    (districtState) => ({
        data: districtState.data.districts,
        loading: districtState.loading.list,
    })
);

const selectMunicipalityViewModel = createSelector(
    [selectMunicipalityState],
    (municipalityState) => ({
        data: municipalityState.data.municipalities,
        loading: municipalityState.loading.list,
    })
);

const selectParishViewModel = createSelector(
    [selectParishState],
    (parishState) => ({
        data: parishState.data.parishes,
        loading: parishState.loading.list,
    })
);

interface AddressFieldsProps {
    /** Mostra o cabeçalho "Endereço" (default true). */
    showHeader?: boolean;
}

export default function AddressFields({ showHeader = true }: AddressFieldsProps) {
    const dispatch: any = useDispatch();

    const { setFieldValue, setFieldTouched } = useFormikContext<any>();
    const [field, meta] = useField<number | null>("district_id");
    const [fieldMunicipality] = useField<number | null>("municipality_id");
    const [fieldParish] = useField<number | null>("parish_id");

    const { data: districts } = useSelector(selectDistrictViewModel);
    const { data: municipalities } = useSelector(selectMunicipalityViewModel);
    const { data: parishes } = useSelector(selectParishViewModel);

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
    }, [dispatch, selected]);

    useEffect(() => {
        if (!selectedMunicipality) return;
        dispatch(getParishes(selectedMunicipality?.id));
    }, [dispatch, selectedMunicipality]);

    return (
        <Row>
            {showHeader && (
                <div className="mt-4 mb-2 border-bottom pb-2">
                    <h5 className="card-title">Endereço</h5>
                </div>
            )}
            <Col lg={2}>
                <XInputMask
                    className="mb-2"
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
                    className="mb-2"
                    name="address"
                    label="Endereço"
                    placeholder="Endereço"
                />
            </Col>
            <Col lg={4}>
                <Label className="form-label">Distrito:</Label>
                <Select
                    className="mb-3"
                    inputId="district_id"
                    name="district_id"
                    options={districts}
                    getOptionLabel={(o: IDistrict) => o.name}
                    getOptionValue={(o: IDistrict) => String(o.id)}
                    value={selected}
                    onChange={(opt: IDistrict | null) => {
                        setFieldValue("district_id", (opt as IDistrict | null)?.id ?? null);
                        // Limpa dependentes ao trocar de distrito.
                        setFieldValue("municipality_id", null);
                        setFieldValue("parish_id", null);
                    }}
                    onBlur={() => setFieldTouched("district_id", true)}
                    isDisabled={districts.length === 0}
                    isClearable
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
                <Label className="form-label">Município:</Label>
                <Select
                    className="mb-3"
                    inputId="municipality_id"
                    name="municipality_id"
                    options={municipalities}
                    getOptionLabel={(o: IMunicipality) => o.name}
                    getOptionValue={(o: IMunicipality) => String(o.id)}
                    value={selectedMunicipality}
                    onChange={(opt: IMunicipality | null) => {
                        setFieldValue("municipality_id", (opt as IMunicipality | null)?.id ?? null);
                        setFieldValue("parish_id", null);
                    }}
                    onBlur={() => setFieldTouched("municipality_id", true)}
                    isDisabled={municipalities.length === 0}
                    isClearable
                    classNamePrefix="react-select"
                />
            </Col>
            <Col lg={4}>
                <Label className="form-label">Freguesia:</Label>
                <Select
                    className="mb-3"
                    inputId="parish_id"
                    name="parish_id"
                    options={parishes}
                    getOptionLabel={(o: IParish) => o.name}
                    getOptionValue={(o: IParish) => String(o.id)}
                    value={selectedParish}
                    onChange={(opt: IParish | null) => setFieldValue("parish_id", (opt as IParish | null)?.id ?? null)}
                    onBlur={() => setFieldTouched("parish_id", true)}
                    isDisabled={parishes.length === 0}
                    isClearable
                    classNamePrefix="react-select"
                />
            </Col>
        </Row>
    );
}
