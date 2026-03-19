import PropTypes from "prop-types";
import React, { useEffect } from "react";
import { Navigate } from "react-router-dom";

import { logoutUser } from "../../slices/thunks";

//redux
import { useSelector, useDispatch } from "react-redux";

import withRouter from "../../Components/Common/withRouter";
import { createSelector } from "reselect";

const selectIsUserLogout = createSelector(
  [(state: any) => state.Login],
  (loginState) => loginState.data.isUserLogout
);

const Logout = (props: any) => {
  const dispatch: any = useDispatch();
  const isUserLogout = useSelector(selectIsUserLogout);

  useEffect(() => {
    dispatch(logoutUser());
  }, [dispatch]);

  if (isUserLogout) {
    return <Navigate to="/login" />;
  }

  return <></>;
};

Logout.propTypes = {
  history: PropTypes.object,
};


export default withRouter(Logout);
