import React from "react";

export default function Login() {
  return (
    <div
      className="modal fade popup login-form"
      id="popup_bid"
      tabIndex={-1}
      aria-hidden="true"
    >
      <div className="modal-dialog modal-dialog-centered" role="document">
        <div className="modal-content">
          <button
            type="button"
            className="close"
            data-bs-dismiss="modal"
            aria-label="Close"
          >
            <span aria-hidden="true">×</span>
          </button>
          <div className="modal-body space-y-20 pd-40">
            <div className="wrap-modal flex">
              <div className="images flex-none">
                <img
                  alt="images"
                  src="/assets/images/section/login.jpg"
                  width={380}
                  height={640}
                />
              </div>
              <div className="content">
                <h1 className="title-login">Login</h1>
                <div className="comments">
                  <div className="respond-comment">
                    <form
                      onSubmit={(e) => e.preventDefault()}
                      className="comment-form form-submit"
                      acceptCharset="utf-8"
                    >
                      <fieldset className="">
                        <label className="fw-6">Account</label>
                        <input
                          type="text"
                          id="text"
                          className="tb-my-input"
                          name="text"
                          placeholder="Your name"
                        />
                        <div className="icon">
                          <svg
                            width={18}
                            height={18}
                            viewBox="0 0 18 18"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                          >
                            <path
                              d="M11.8127 4.5C11.8127 5.24592 11.5164 5.96129 10.989 6.48874C10.4615 7.01618 9.74615 7.3125 9.00023 7.3125C8.25431 7.3125 7.53893 7.01618 7.01149 6.48874C6.48404 5.96129 6.18773 5.24592 6.18773 4.5C6.18773 3.75408 6.48404 3.03871 7.01149 2.51126C7.53893 1.98382 8.25431 1.6875 9.00023 1.6875C9.74615 1.6875 10.4615 1.98382 10.989 2.51126C11.5164 3.03871 11.8127 3.75408 11.8127 4.5ZM3.37598 15.0885C3.40008 13.6128 4.00323 12.2056 5.05536 11.1705C6.10749 10.1354 7.52429 9.55535 9.00023 9.55535C10.4762 9.55535 11.893 10.1354 12.9451 11.1705C13.9972 12.2056 14.6004 13.6128 14.6245 15.0885C12.86 15.8976 10.9413 16.3151 9.00023 16.3125C6.99323 16.3125 5.08823 15.8745 3.37598 15.0885Z"
                              stroke="#B6B6B6"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </div>
                      </fieldset>
                      <fieldset className="style-wrap">
                        <label className="fw-6">Password</label>
                        <input
                          type="password"
                          className="input-form password-input"
                          placeholder="Your password"
                        />
                        <div className="icon">
                          <svg
                            width={18}
                            height={18}
                            viewBox="0 0 18 18"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                          >
                            <path
                              d="M12.375 7.875V5.0625C12.375 4.16739 12.0194 3.30895 11.3865 2.67601C10.7535 2.04308 9.89511 1.6875 9 1.6875C8.10489 1.6875 7.24645 2.04308 6.61351 2.67601C5.98058 3.30895 5.625 4.16739 5.625 5.0625V7.875M5.0625 16.3125H12.9375C13.3851 16.3125 13.8143 16.1347 14.1307 15.8182C14.4472 15.5018 14.625 15.0726 14.625 14.625V9.5625C14.625 9.11495 14.4472 8.68573 14.1307 8.36926C13.8143 8.05279 13.3851 7.875 12.9375 7.875H5.0625C4.61495 7.875 4.18573 8.05279 3.86926 8.36926C3.55279 8.68573 3.375 9.11495 3.375 9.5625V14.625C3.375 15.0726 3.55279 15.5018 3.86926 15.8182C4.18573 16.1347 4.61495 16.3125 5.0625 16.3125Z"
                              stroke="#B6B6B6"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </div>
                      </fieldset>
                      <div className="title-forgot">
                        <a className="fs-14 fw-4">Forgot password</a>
                      </div>
                      <button className="sc-button" name="submit" type="submit">
                        <span>Login</span>
                      </button>
                    </form>
                  </div>
                </div>
                <div className="text-box text-center fs-14">
                  Don’t you have an account?{" "}
                  <a
                    className="font-2 fw-7 fs-14 color-popup text-color-3"
                    data-bs-toggle="modal"
                    data-bs-target="#popup_bid2"
                  >
                    Register
                  </a>
                </div>
                <p className="texts line fs-12 text-center">or login with</p>
                <div className="button-box flex">
                  <a href="#" className="flex align-center hover-login-social">
                    <svg
                      width={20}
                      height={21}
                      viewBox="0 0 20 21"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M4.43242 12.5863L3.73625 15.1852L1.19176 15.239C0.431328 13.8286 0 12.2149 0 10.5C0 8.84179 0.403281 7.27804 1.11812 5.90112H1.11867L3.38398 6.31644L4.37633 8.56815C4.16863 9.17366 4.05543 9.82366 4.05543 10.5C4.05551 11.2341 4.18848 11.9374 4.43242 12.5863Z"
                        fill="#FBBB00"
                      />
                      <path
                        d="M19.8242 8.6319C19.939 9.23682 19.9989 9.86155 19.9989 10.5C19.9989 11.216 19.9236 11.9143 19.7802 12.588C19.2934 14.8803 18.0214 16.8819 16.2594 18.2984L16.2588 18.2978L13.4055 18.1522L13.0017 15.6314C14.1709 14.9456 15.0847 13.8726 15.566 12.588H10.2188V8.6319H19.8242Z"
                        fill="#518EF8"
                      />
                      <path
                        d="M16.2595 18.2978L16.2601 18.2984C14.5464 19.6758 12.3694 20.5 9.99965 20.5C6.19141 20.5 2.88043 18.3715 1.19141 15.239L4.43207 12.5863C5.27656 14.8401 7.45074 16.4445 9.99965 16.4445C11.0952 16.4445 12.1216 16.1484 13.0024 15.6313L16.2595 18.2978Z"
                        fill="#28B446"
                      />
                      <path
                        d="M16.382 2.80219L13.1425 5.45437C12.2309 4.88461 11.1534 4.55547 9.99906 4.55547C7.39246 4.55547 5.17762 6.23348 4.37543 8.56812L1.11773 5.90109H1.11719C2.78148 2.6923 6.13422 0.5 9.99906 0.5C12.4254 0.5 14.6502 1.3643 16.382 2.80219Z"
                        fill="#F14336"
                      />
                    </svg>
                    <span className="fw-6">Google</span>
                  </a>
                  <a href="#" className="flex align-center hover-login-social">
                    <svg
                      width={21}
                      height={21}
                      viewBox="0 0 21 21"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M20.5 10.5C20.5 15.4914 16.843 19.6285 12.0625 20.3785V13.3906H14.3926L14.8359 10.5H12.0625V8.62422C12.0625 7.8332 12.45 7.0625 13.6922 7.0625H14.9531V4.60156C14.9531 4.60156 13.8086 4.40625 12.7145 4.40625C10.4305 4.40625 8.9375 5.79063 8.9375 8.29688V10.5H6.39844V13.3906H8.9375V20.3785C4.15703 19.6285 0.5 15.4914 0.5 10.5C0.5 4.97734 4.97734 0.5 10.5 0.5C16.0227 0.5 20.5 4.97734 20.5 10.5Z"
                        fill="#1877F2"
                      />
                      <path
                        d="M14.3926 13.3906L14.8359 10.5H12.0625V8.62418C12.0625 7.83336 12.4499 7.0625 13.6921 7.0625H14.9531V4.60156C14.9531 4.60156 13.8088 4.40625 12.7146 4.40625C10.4304 4.40625 8.9375 5.79063 8.9375 8.29688V10.5H6.39844V13.3906H8.9375V20.3785C9.44664 20.4584 9.96844 20.5 10.5 20.5C11.0316 20.5 11.5534 20.4584 12.0625 20.3785V13.3906H14.3926Z"
                        fill="white"
                      />
                    </svg>
                    <span className="fw-6">Facebook</span>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
