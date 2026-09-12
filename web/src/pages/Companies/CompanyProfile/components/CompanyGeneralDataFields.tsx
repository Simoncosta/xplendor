// React
// Components
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import XInputMask from "Components/Common/XInputMask";
import AddressFields from "Components/Common/AddressFields";
import { Row, Col } from "reactstrap";

export default function CompanyGeneralDataFields({ isEdit }: { isEdit: boolean }) {
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
                <h5 className="card-title mb-0">Faturação</h5>
                <small className="text-muted">Liga o regime de IVA por viatura para esta empresa.</small>
            </div>
            <Col lg={12} className="mb-2">
                <XInputCheckbox
                    name="uses_vat"
                    label="Esta empresa trabalha com IVA"
                />
            </Col>
            <Col lg={12} className="mb-2">
                <XInput
                    type="url"
                    name="google_review_url"
                    label="Link de avaliação do Google Business"
                    hint='O link "Deixar avaliação" do teu Perfil de Empresa no Google (Perfil de Empresa → Pedir avaliações → copiar link). Usado no relatório de pós-venda quando o cliente dá 4 ou 5 estrelas.'
                    placeholder="https://g.page/r/..."
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

            {/* Morada partilhada (mesmo componente usado nos Fornecedores). */}
            <Col lg={12}>
                <AddressFields />
            </Col>
        </Row>
    );
}
