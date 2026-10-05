import React, { useEffect, useState } from "react";
import { Button, Spinner } from "reactstrap";

// Formik Validation
import * as Yup from "yup";
import { FormikProvider, useFormik } from "formik";

import { ToastContainer, toast } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import "./login.css";

//redux
import { useSelector, useDispatch } from "react-redux";

import { Link, useNavigate, useSearchParams } from "react-router-dom";

//import images
import logoLight from "../../assets/images/logo-light.png";
import { createSelector } from "reselect";
import { getUserByInvite, registerByInvite } from "slices/thunks";
import XInput from "Components/Common/XInput";

const selectRegisterInviteState = (state: any) => state.RegisterInvite;

const selectRegisterInviteViewModel = createSelector(
    [selectRegisterInviteState],
    (registerInviteState) => ({
        data: registerInviteState.data.userInvite,
        error: registerInviteState.error.show,
        loading: registerInviteState.loading.show,
    })
);

const Register = () => {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();
    const [searchParams] = useSearchParams();

    const token = searchParams.get("token");

    const [loader] = useState<boolean>(false);

    const { data, error } = useSelector(selectRegisterInviteViewModel);
    // Convite inválido, usado ou expirado: o servidor devolve uma mensagem clara (404/410).
    const inviteError: string | null = !token
        ? "Falta o código do convite no endereço. Abra o link que recebeu por email."
        : error
            ? (typeof error === "string" ? error : error?.message) || "Este convite é inválido, já foi usado ou expirou. Peça um novo convite ao administrador da empresa."
            : null;

    useEffect(() => {
        if (!token) return;
        dispatch(getUserByInvite(token));
    }, [dispatch, token]);

    const validationSchema = Yup.object({
        password: Yup.string()
            .required("A palavra-passe é obrigatória")
            .min(8, "A palavra-passe deve ter no mínimo 8 caracteres"),

        password_confirmation: Yup.string()
            .required("Confirme a palavra-passe")
            .oneOf([Yup.ref("password")], "As palavras-passe não coincidem"),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema,
        onSubmit: (values) => {
            dispatch(
                registerByInvite(
                    {
                        token: String(token),
                        password: values.password,
                        password_confirmation: values.password_confirmation,
                    },
                    navigate
                )
            );
            toast("Convite aceite com sucesso!", {
                position: "top-right",
                hideProgressBar: false,
                className: "bg-success text-white",
            });
        },
    });

    document.title = "Criar conta | Xplendor";

    // Imagem premium servida de web/public (evita a resolução de url() do webpack).
    const bgImageVar = {
        ["--xl-bgimg" as any]: `url(${process.env.PUBLIC_URL}/background-auth.webp)`,
    } as React.CSSProperties;

    return (
        <div className="xlogin" style={bgImageVar}>
            {/* Voltar ao início (landing pública "/"). Âncora normal (não Link do
                react-router): a app corre com basename "/app", por isso um Link "/"
                iria para "/app/". A landing vive fora da SPA, em "/". */}
            <a href="/" className="xlogin-back">
                <i className="ri-arrow-left-line" aria-hidden="true" />
                Voltar ao início
            </a>

            <ToastContainer />

            {/* Imagem full-screen de fundo + form centrado por cima (estilo Resend) */}
            <div className="xlogin-panel">
                <div className="xlogin-form">
                    <img src={logoLight} alt="XPLENDOR" className="xlogin-logo" />
                    <h1 className="xlogin-title">Criar a sua conta</h1>
                    <p className="xlogin-subtitle">
                        Defina a sua palavra-passe para concluir o registo.
                    </p>

                    {inviteError && <div className="alert alert-danger fs-13" role="alert">{inviteError}</div>}
                    {data?.company_name && !inviteError && <p className="text-muted fs-13 mb-3">Convite para {data.company_name}.</p>}
                    <FormikProvider value={formik}>
                        <form onSubmit={formik.handleSubmit} className="needs-validation">
                            <XInput
                                className="mb-3"
                                type="email"
                                placeholder="Email"
                                name="email"
                                label="E-mail"
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
                                placeholder="A sua palavra-passe"
                                type="password"
                                name="password"
                                label="Palavra-passe"
                                required
                            />
                            <XInput
                                className="mb-3"
                                placeholder="Confirmar a palavra-passe"
                                type="password"
                                name="password_confirmation"
                                label="Confirmar palavra-passe"
                                required
                            />

                            <div className="mt-4">
                                <Button
                                    className="xlogin-submit"
                                    type="submit"
                                    disabled={loader}
                                >
                                    {loader ? (
                                        <>
                                            <Spinner size="sm" className="me-2" />
                                            A registar...
                                        </>
                                    ) : (
                                        "Criar conta"
                                    )}
                                </Button>
                            </div>
                        </form>
                    </FormikProvider>

                    <div className="xlogin-foot">
                        Já tem conta?{" "}
                        <Link to="/login" className="xlogin-forgot">
                            Entrar
                        </Link>
                    </div>
                    <div className="xlogin-foot">
                        © {new Date().getFullYear()} XPLENDOR ·{" "}
                        <Link to="/privacy">Privacidade</Link>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Register;
