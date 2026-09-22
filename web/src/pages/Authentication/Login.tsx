import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
    Input, Label, Button,
    FormFeedback, Form, Alert, InputGroup, InputGroupText, Spinner
} from 'reactstrap';
import './login.css';
import logoLight from '../../assets/images/logo-light.png';

import { useDispatch, useSelector } from 'react-redux';
import withRouter from "../../Components/Common/withRouter";
import * as Yup from "yup";
import { useFormik } from "formik";

import { loginUser } from 'slices/thunks';
import { reset_login_flag } from 'slices/auth/login/reducer';

const Login = (props: any) => {
    const dispatch: any = useDispatch();

    const [passwordShow, setPasswordShow] = useState<boolean>(false);
    const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
    const [loginError, setLoginError] = useState<string | null>(null);

    const errorMsg: boolean = useSelector((state: any) => state.Login.data.errorMsg);

    useEffect(() => {
        if (errorMsg) {
            setLoginError('Email ou palavra-passe incorrectos.');
            setIsSubmitting(false);
        }
    }, [errorMsg]);

    const validation = useFormik({
        enableReinitialize: true,
        initialValues: {
            email: '',
            password: '',
        },
        validationSchema: Yup.object({
            email: Yup.string().required("Introduza o seu email"),
            password: Yup.string().required("Introduza a sua palavra-passe"),
        }),
        onSubmit: (values) => {
            setIsSubmitting(true);
            setLoginError(null);
            dispatch(reset_login_flag());
            dispatch(loginUser(values, props.router.navigate));
        }
    });

    const clearError = () => {
        if (loginError) {
            setLoginError(null);
            dispatch(reset_login_flag());
        }
    };

    document.title = "Entrar | Xplendor";

    // Imagem premium servida de web/public (evita a resolução de url() do webpack).
    const bgImageVar = {
        ['--xl-bgimg' as any]: `url(${process.env.PUBLIC_URL}/background-auth.webp)`,
    } as React.CSSProperties;

    return (
        <div className="xlogin" style={bgImageVar}>
            {/* Voltar ao início (landing pública "/") */}
            <Link to="/" className="xlogin-back">
                <i className="ri-arrow-left-line" aria-hidden="true" />
                Voltar ao início
            </Link>

            {/* Imagem full-screen de fundo + form centrado por cima (estilo Resend) */}
            <div className="xlogin-panel">
                <div className="xlogin-form">
                        <img src={logoLight} alt="XPLENDOR" className="xlogin-logo" />
                        <h1 className="xlogin-title">Entrar na XPLENDOR</h1>
                        <p className="xlogin-subtitle">Introduza as suas credenciais para continuar.</p>

                        <Form
                            onSubmit={(e) => {
                                e.preventDefault();
                                validation.handleSubmit();
                                return false;
                            }}
                            action="#">

                            {loginError && (
                                <Alert color="danger" className="mb-3">
                                    <i className="ri-error-warning-line align-middle me-2"></i>
                                    {loginError}
                                </Alert>
                            )}

                            <div className="mb-3">
                                <Label htmlFor="email" className="form-label">E-mail</Label>
                                <InputGroup className={validation.touched.email && validation.errors.email ? 'has-validation' : ''}>
                                    <InputGroupText className="bg-transparent">
                                        <i className="ri-mail-line text-muted"></i>
                                    </InputGroupText>
                                    <Input
                                        type="text"
                                        className="form-control"
                                        id="email"
                                        placeholder="O seu email"
                                        name="email"
                                        onChange={(e) => { clearError(); validation.handleChange(e); }}
                                        onBlur={validation.handleBlur}
                                        value={validation.values.email || ""}
                                        invalid={validation.touched.email && !!validation.errors.email}
                                    />
                                    {validation.touched.email && validation.errors.email && (
                                        <FormFeedback type="invalid">{validation.errors.email}</FormFeedback>
                                    )}
                                </InputGroup>
                            </div>

                            <div className="mb-3">
                                <div className="float-end">
                                    <Link to="/auth-pass-reset-cover" className="xlogin-forgot">Esqueceu-se da palavra-passe?</Link>
                                </div>
                                <Label className="form-label" htmlFor="password-input">Palavra-passe</Label>
                                <InputGroup className={validation.touched.password && validation.errors.password ? 'has-validation' : ''}>
                                    <InputGroupText className="bg-transparent">
                                        <i className="ri-lock-2-line text-muted"></i>
                                    </InputGroupText>
                                    <Input
                                        type={passwordShow ? "text" : "password"}
                                        className="form-control password-input"
                                        placeholder="A sua palavra-passe"
                                        id="password-input"
                                        name="password"
                                        value={validation.values.password || ""}
                                        onChange={(e) => { clearError(); validation.handleChange(e); }}
                                        onBlur={validation.handleBlur}
                                        invalid={validation.touched.password && !!validation.errors.password}
                                    />
                                    <button
                                        className="btn"
                                        type="button"
                                        id="password-addon"
                                        onClick={() => setPasswordShow(!passwordShow)}
                                    >
                                        <i className={passwordShow ? "ri-eye-off-fill align-middle" : "ri-eye-fill align-middle"}></i>
                                    </button>
                                    {validation.touched.password && validation.errors.password && (
                                        <FormFeedback type="invalid">{validation.errors.password}</FormFeedback>
                                    )}
                                </InputGroup>
                            </div>

                            <div className="form-check">
                                <Input className="form-check-input" type="checkbox" value="" id="auth-remember-check" />
                                <Label className="form-check-label" htmlFor="auth-remember-check">Manter sessão iniciada</Label>
                            </div>

                            <div className="mt-4">
                                <Button className="xlogin-submit" type="submit" disabled={isSubmitting}>
                                    {isSubmitting ? (
                                        <>
                                            <Spinner size="sm" className="me-2" />
                                            A entrar...
                                        </>
                                    ) : 'Entrar'}
                                </Button>
                            </div>

                        </Form>

                        <div className="xlogin-foot">
                            © {new Date().getFullYear()} XPLENDOR · <Link to="/privacy">Privacidade</Link>
                        </div>
                    </div>
                </div>
        </div>
    );
};

export default withRouter(Login);
