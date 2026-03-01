import React, { useEffect, useState } from "react";
import { Row, Col, CardBody, Card, Container, Button, Spinner } from "reactstrap";

// Formik Validation
import * as Yup from "yup";
import { FormikProvider, useFormik } from "formik";

import { ToastContainer, toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

// Action

//redux
import { useSelector, useDispatch } from "react-redux";

import { Link, useNavigate, useSearchParams } from "react-router-dom";

//import images 
import logoLight from "../../assets/images/logo-light.png";
import ParticlesAuth from "../AuthenticationInner/ParticlesAuth";
import { createSelector } from "reselect";
import { getUserByInvite, registerByInvite } from "slices/thunks";
import XInput from "Components/Common/XInput";

const Register = () => {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();
    const [searchParams] = useSearchParams();

    const token = searchParams.get("token");

    const [loader, setLoader] = useState<boolean>(false);

    const selectRegisterInviteState = (state: any) => state.RegisterInvite;

    const registerInviteSelector = createSelector(selectRegisterInviteState, (state: any) => ({
        data: state.userInvite,
        error: state.meta,
        loading: state.loading,
    }));

    const { data } = useSelector(registerInviteSelector);

    useEffect(() => {
        if (!token) return;
        dispatch(getUserByInvite(token));
    }, [dispatch, token]);

    const validationSchema = Yup.object({
        password: Yup.string()
            .required("Password é obrigatória")
            .min(8, "Password deve ter no mínimo 8 caracteres"),

        password_confirmation: Yup.string()
            .required("Confirmação de password é obrigatória")
            .oneOf([Yup.ref("password")], "As passwords não coincidem"),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema,
        onSubmit: (values) => {
            dispatch(registerByInvite({
                token: String(token),
                password: values.password,
                password_confirmation: values.password_confirmation
            }, navigate));
            toast("Convite aceito com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
        },
    });

    document.title = "Registro | Xplendor";

    return (
        <React.Fragment>
            <ParticlesAuth>
                <div className="auth-page-content mt-lg-5">
                    <ToastContainer />
                    <Container>
                        <Row>
                            <Col lg={12}>
                                <div className="text-center mt-sm-5 mb-4 text-white-50">
                                    <div>
                                        <Link to="/" className="d-inline-block auth-logo">
                                            <img src={logoLight} alt="" height="20" />
                                        </Link>
                                    </div>
                                    <p className="mt-3 fs-15 fw-medium">Xplendor - Smart Ads para Stands</p>
                                </div>
                            </Col>
                        </Row>

                        <Row className="justify-content-center">
                            <Col md={8} lg={6} xl={5}>
                                <Card className="mt-4">

                                    <CardBody className="p-4">
                                        <div className="text-center mt-2">
                                            <h5 className="text-primary">Registrar conta</h5>
                                            {/* <p className="text-muted">Crie</p> */}
                                        </div>
                                        <div className="p-2 mt-4">
                                            <FormikProvider value={formik}>
                                                <form onSubmit={formik.handleSubmit} className="needs-validation">

                                                    <XInput
                                                        className="mb-3"
                                                        type="email"
                                                        placeholder="Email"
                                                        name="email"
                                                        label="Email"
                                                        disabled={true}
                                                    />
                                                    <XInput
                                                        className="mb-3"
                                                        placeholder="Nome"
                                                        name="name"
                                                        label="Nome"
                                                        disabled={true}
                                                    />
                                                    <XInput
                                                        className="mb-3"
                                                        placeholder="Password"
                                                        type="password"
                                                        name="password"
                                                        label="Password"
                                                        required
                                                    />
                                                    <XInput
                                                        className="mb-3"
                                                        placeholder="Confirmar Password"
                                                        type="password"
                                                        name="password_confirmation"
                                                        label="Confirmar Password"
                                                        required
                                                    />

                                                    <div className="mt-4">
                                                        <Button color="success" className="w-100" type="submit" disabled={loader && true}>
                                                            {loader && <Spinner size="sm" className='me-2'> Loading... </Spinner>}
                                                            Registrar
                                                        </Button>
                                                    </div>

                                                </form>
                                            </FormikProvider>
                                        </div>
                                    </CardBody>
                                </Card>
                                <div className="mt-4 text-center">
                                    <p className="mb-0">Você já tem uma conta? <Link to="/login" className="fw-semibold text-primary text-decoration-underline"> Login </Link> </p>
                                </div>
                            </Col>
                        </Row>
                    </Container>
                </div>
            </ParticlesAuth>
        </React.Fragment >
    );
};

export default Register;
