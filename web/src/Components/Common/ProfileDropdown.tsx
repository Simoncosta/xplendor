import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Dropdown, DropdownItem, DropdownMenu, DropdownToggle } from 'reactstrap';
import { createSelector } from 'reselect';
import { useSelector } from 'react-redux';

//import images
import avatar1 from '../../assets/images/users/avatar-company.jpg';

const ProfileDropdown = () => {
    const profiledropdownData = createSelector(
        (state: any) => state.Profile,
        (user) => user.user
    );

    // Inside your component
    const user = useSelector(profiledropdownData);

    const [userId, setUserId] = useState(0);
    const [userName, setUserName] = useState("");
    const [avatar, setAvatar] = useState(avatar1);
    const [companyId, setCompanyId] = useState(0);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setUserId(obj.id);
            setAvatar(obj.avatar ? String(process.env.REACT_APP_PUBLIC_URL) + "/storage/" + obj.avatar : avatar1);
            setUserName(obj.name);
            setCompanyId(obj.company_id);
        }
    }, [userName, user]);

    //Dropdown Toggle
    const [isProfileDropdown, setIsProfileDropdown] = useState(false);
    const toggleProfileDropdown = () => {
        setIsProfileDropdown(!isProfileDropdown);
    };
    return (
        <React.Fragment>
            <Dropdown isOpen={isProfileDropdown} toggle={toggleProfileDropdown} className="ms-sm-3 header-item topbar-user">
                <DropdownToggle tag="button" type="button" className="btn">
                    <span className="d-flex align-items-center">
                        <img className="rounded-circle header-profile-user" src={avatar}
                            alt="Header Avatar" />
                        <span className="text-start ms-xl-2">
                            <span className="d-none d-xl-inline-block ms-1 fw-medium user-name-text">{userName}</span>
                            <span className="d-none d-xl-block ms-1 fs-12 text-muted user-name-sub-text">Utilizador</span>
                        </span>
                    </span>
                </DropdownToggle>
                <DropdownMenu className="dropdown-menu-end">
                    <h6 className="dropdown-header">Bem vindo(a) {userName}!</h6>
                    {userId !== 0 && (
                        <DropdownItem className='p-0'>
                            <Link to={`/users/${userId}`} className="dropdown-item">
                                <i className="mdi mdi-account-circle text-muted fs-16 align-middle me-1"></i>
                                <span className="align-middle">Meu perfil</span>
                            </Link>
                        </DropdownItem>
                    )}
                    <DropdownItem className='p-0'>
                        <Link to={`/companies/${companyId}`} className="dropdown-item">
                            <i className="bx bx-buildings text-muted fs-16 align-middle me-1"></i>
                            <span className="align-middle">Perfil da empresa</span>
                        </Link>
                    </DropdownItem>
                    <div className="dropdown-divider"></div>
                    <DropdownItem className='p-0'>
                        <Link to="/logout" className="dropdown-item">
                            <i
                                className="mdi mdi-logout text-muted fs-16 align-middle me-1"></i> <span
                                    className="align-middle" data-key="t-logout">Logout</span>
                        </Link>
                    </DropdownItem>
                </DropdownMenu>
            </Dropdown>
        </React.Fragment>
    );
};

export default ProfileDropdown;