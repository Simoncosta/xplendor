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
import CarMarketing from "pages/Cars/Car/CarMarketing";
import CarIntelligencePage from "pages/Cars/Car/CarIntelligencePage";
import CarAdsPage from "pages/Cars/Car/CarAdsPage";
import CarFichaPage from "pages/Cars/Car/CarFichaPage";
import ActionCenterPage from "pages/Actions/ActionCenterPage";

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

// Leads
import LeadList from "pages/Leads/LeadList";

// Internal tools
import ScraperRunner from "pages/Internal/ScraperRunner";

// OAuth Meta
import MetaOAuthCallback from "pages/OAuthCallback/MetaOAuthCallback";

const authProtectedRoutes = [
    { path: "/dashboard", component: <Dashboard /> },

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars
    { path: "/cars", component: <CarList /> },
    { path: "/actions", component: <ActionCenterPage /> },
    { path: "/cars/create", component: <CarCreate /> },
    { path: "/cars/:id", component: <CarUpdate /> },
    { path: "/cars/:id/analytics", component: <CarAnalytics /> },
    { path: "/cars/:id/intelligence", component: <CarIntelligencePage /> },
    { path: "/cars/:id/ads", component: <CarAdsPage /> },
    { path: "/cars/:id/ficha", component: <CarFichaPage /> },
    { path: "/cars/:id/marketing", component: <CarMarketing /> },

    // Leads
    { path: "/leads", component: <LeadList /> },

    // Users
    { path: "/users", component: <UsersList /> },
    { path: "/users/create", component: <UserCreate /> },
    { path: "/users/:id", component: <UserUpdate /> },

    // Blogs
    { path: "/blogs", component: <BlogList /> },
    { path: "/blogs/create", component: <BlogCreate /> },
    { path: "/blogs/:id", component: <BlogUpdate /> },
    { path: "/blogs/:id/show", component: <BlogShow /> },

    // Internal tools
    { path: "/internal/scraper", component: <ScraperRunner /> },

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

    { path: "/oauth/meta/callback", component: <MetaOAuthCallback /> },

    // Landing Page
    { path: "/", component: <Landing /> },
];

export { authProtectedRoutes, publicRoutes };
