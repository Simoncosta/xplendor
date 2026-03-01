import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Input } from 'reactstrap';

//SimpleBar
import SimpleBar from "simplebar-react";

//import images
import image2 from "../../assets/images/users/avatar-2.jpg";
import image3 from "../../assets/images/users/avatar-3.jpg";
import image5 from "../../assets/images/users/avatar-5.jpg";

const SearchOption = () => {

    const [value, setValue] = useState("");
    const onChangeData = (value: any) => {
        setValue(value);
    };

    useEffect(() => {
        const searchOptions = document.getElementById("search-close-options") as HTMLElement;
        const dropdown = document.getElementById("search-dropdown") as HTMLElement;
        const searchInput = document.getElementById("search-options") as HTMLInputElement;

        const handleFocus = () => {
            const inputLength = searchInput.value.length;
            if (inputLength > 0) {
                dropdown.classList.add("show");
                searchOptions.classList.remove("d-none");
            } else {
                dropdown.classList.remove("show");
                searchOptions.classList.add("d-none");
            }
        };

        const handleKeyUp = () => {
            const inputLength = searchInput.value.length;
            if (inputLength > 0) {
                dropdown.classList.add("show");
                searchOptions.classList.remove("d-none");
            } else {
                dropdown.classList.remove("show");
                searchOptions.classList.add("d-none");
            }
        };

        const handleClick = () => {
            searchInput.value = "";
            dropdown.classList.remove("show");
            searchOptions.classList.add("d-none");
        };

        const handleBodyClick = (e: MouseEvent) => {
            const target = e.target as HTMLElement;
            if (target.getAttribute("id") !== "search-options") {
                dropdown.classList.remove("show");
                searchOptions.classList.add("d-none");
            }
        };

        searchInput.addEventListener("focus", handleFocus);
        searchInput.addEventListener("keyup", handleKeyUp);
        searchOptions.addEventListener("click", handleClick);
        document.body.addEventListener("click", handleBodyClick);

        return () => {
            searchInput.removeEventListener("focus", handleFocus);
            searchInput.removeEventListener("keyup", handleKeyUp);
            searchOptions.removeEventListener("click", handleClick);
            document.body.removeEventListener("click", handleBodyClick);
        };
    }, []);

    return (
        <React.Fragment>
            <form className="app-search d-none d-md-block">
                <div className="position-relative">
                    <Input type="text" className="form-control" placeholder="Search..."
                        id="search-options"
                        value={value}
                        onChange={(e: any) => {
                            onChangeData(e.target.value);
                        }} />
                    <span className="mdi mdi-magnify search-widget-icon"></span>
                    <span className="mdi mdi-close-circle search-widget-icon search-widget-icon-close d-none"
                        id="search-close-options"></span>
                </div>
                <div className="dropdown-menu dropdown-menu-lg" id="search-dropdown">
                    <SimpleBar style={{ height: "320px" }}>

                        <div className="dropdown-header">
                            <h6 className="text-overflow text-muted mb-0 text-uppercase">Recent Searches</h6>
                        </div>

                        <div className="dropdown-item bg-transparent text-wrap">
                            <Link to="/" className="btn btn-soft-secondary btn-sm rounded-pill">how to setup <i
                                className="mdi mdi-magnify ms-1"></i></Link>
                            <Link to="/" className="btn btn-soft-secondary btn-sm rounded-pill">buttons <i
                                className="mdi mdi-magnify ms-1"></i></Link>
                        </div>

                        <div className="dropdown-header mt-2">
                            <h6 className="text-overflow text-muted mb-1 text-uppercase">Pages</h6>
                        </div>


                        <Link to="#" className="dropdown-item notify-item">
                            <i className="ri-bubble-chart-line align-middle fs-18 text-muted me-2"></i>
                            <span>Analytics Dashboard</span>
                        </Link>


                        <Link to="#" className="dropdown-item notify-item">
                            <i className="ri-lifebuoy-line align-middle fs-18 text-muted me-2"></i>
                            <span>Help Center</span>
                        </Link>


                        <Link to="#" className="dropdown-item notify-item">
                            <i className="ri-user-settings-line align-middle fs-18 text-muted me-2"></i>
                            <span>My account settings</span>
                        </Link>


                        <div className="dropdown-header mt-2">
                            <h6 className="text-overflow text-muted mb-2 text-uppercase">Members</h6>
                        </div>

                        <div className="notification-list">

                            <Link to="#" className="dropdown-item notify-item py-2">
                                <div className="d-flex">
                                    <img src={image2} className="me-3 rounded-circle avatar-xs"
                                        alt="user-pic" />
                                    <div className="flex-grow-1">
                                        <h6 className="m-0">Angela Bernier</h6>
                                        <span className="fs-11 mb-0 text-muted">Manager</span>
                                    </div>
                                </div>
                            </Link>

                            <Link to="#" className="dropdown-item notify-item py-2">
                                <div className="d-flex">
                                    <img src={image3} className="me-3 rounded-circle avatar-xs"
                                        alt="user-pic" />
                                    <div className="flex-grow-1">
                                        <h6 className="m-0">David Grasso</h6>
                                        <span className="fs-11 mb-0 text-muted">Web Designer</span>
                                    </div>
                                </div>
                            </Link>

                            <Link to="#" className="dropdown-item notify-item py-2">
                                <div className="d-flex">
                                    <img src={image5} className="me-3 rounded-circle avatar-xs"
                                        alt="user-pic" />
                                    <div className="flex-grow-1">
                                        <h6 className="m-0">Mike Bunch</h6>
                                        <span className="fs-11 mb-0 text-muted">React Developer</span>
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </SimpleBar>

                    <div className="text-center pt-3 pb-1">
                        <Link to="/pages-search-results" className="btn btn-primary btn-sm">View All Results <i
                            className="ri-arrow-right-line ms-1"></i></Link>
                    </div>
                </div>
            </form>
        </React.Fragment>
    );
};

export default SearchOption;