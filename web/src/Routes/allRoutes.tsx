import React from "react";
import { Navigate } from "react-router-dom";

// Dashboard
import Dashboard from "pages/Dashboards/Dashboard";

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
import CarAnalytics from "pages/Cars/Car/CarAnalytics";

// Users
import UsersList from "pages/Users/UsersList";

// Landing 
import Landing from "pages/Landing";
import UserCreate from "pages/Users/User/UserCreate";
import UserUpdate from "pages/Users/User/UserUpdate";

// Blogs
import BlogList from "pages/Blogs/BlogList";
import BlogCreate from "pages/Blogs/Blog/BlogCreate";
import BlogUpdate from "pages/Blogs/Blog/BlogUpdate";
import BlogShow from "pages/Blogs/Blog/BlogShow";

const authProtectedRoutes = [
    { path: "/dashboard", component: <Dashboard /> },

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars
    { path: "/cars", component: <CarList /> },
    { path: "/cars/create", component: <CarCreate /> },
    { path: "/cars/:id", component: <CarUpdate /> },
    { path: "/cars/:id/analytics", component: <CarAnalytics /> },

    // Users
    { path: "/users", component: <UsersList /> },
    { path: "/users/create", component: <UserCreate /> },
    { path: "/users/:id", component: <UserUpdate /> },

    // Blogs
    { path: "/blogs", component: <BlogList /> },
    { path: "/blogs/create", component: <BlogCreate /> },
    { path: "/blogs/:id", component: <BlogUpdate /> },
    { path: "/blogs/:id/show", component: <BlogShow /> },

    // this route should be at the end of all other routes
    // eslint-disable-next-line react/display-name
    {
        path: "/dashboard",
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

    // Landing Page
    { path: "/", component: <Landing /> },
];

export { authProtectedRoutes, publicRoutes };