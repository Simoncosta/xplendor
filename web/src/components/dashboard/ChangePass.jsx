import React from "react";

export default function ChangePass() {
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title mb-3">Change Password</h1>
                <div className="tfcl-add-listing profile-inner">
                  <h3>Change passwords</h3>
                  <div className="tfcl-add-listing profile-password">
                    <div className="form-group">
                      <label htmlFor="listing_title">Old password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Old password"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">New password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="New password"
                        defaultValue=""
                      />
                    </div>
                    <ul className="list-check-req mb-3">
                      <li className="check">
                        <span>One number</span>
                      </li>
                      <li>
                        <span>One lowercase character</span>
                      </li>
                      <li>
                        <span>One uppercase character</span>
                      </li>
                      <li>
                        <span>8 characters minimum</span>
                      </li>
                    </ul>
                    <div className="form-group">
                      <label htmlFor="listing_title">Confirm password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Confirm password"
                        defaultValue=""
                      />
                    </div>
                    <div className="group-button-submit left mb-0">
                      <button className="pre-btn">Change passwords</button>
                    </div>
                  </div>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </div>
  );
}
