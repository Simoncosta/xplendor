import React from "react";
import { Navigate } from "react-router-dom";

// login
import ForgetPasswordPage from "../pages/Authentication/ForgetPassword";
import Logout from "../pages/Authentication/Logout";
import Register from "../pages/Authentication/Register";
import Login from "../pages/Authentication/Login"

// Company
import CompanyList from "pages/Companies/CompanyList";
import CompanyProfileUpdate from "pages/Companies/CompanyProfile/CompanyProfileUpdate";
import CompanyProfileCreate from "pages/Companies/CompanyProfile/CompanyProfileCreate";

// Cars
import CarList from "pages/Cars/CarList";
import CarCreate from "pages/Cars/Car/CarCreate";
import CarUpdate from "pages/Cars/Car/CarUpdate";

const authProtectedRoutes = [
    { path: "/dashboard", component: <></> },

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars
    { path: "/cars", component: <CarList /> },
    { path: "/cars/create", component: <CarCreate /> },
    { path: "/cars/:id", component: <CarUpdate /> },

    // this route should be at the end of all other routes
    // eslint-disable-next-line react/display-name
    {
        path: "/",
        exact: true,
        component: <Navigate to="/dashboard" />,
    },
    { path: "*", component: <Navigate to="/dashboard" /> },
];

const publicRoutes = [
    // Authentication Page
    { path: "/logout", component: <Logout /> },
    { path: "/login", component: <Login /> },
    { path: "/forgot-password", component: <ForgetPasswordPage /> },
    { path: "/register", component: <Register /> },
];

export { authProtectedRoutes, publicRoutes };