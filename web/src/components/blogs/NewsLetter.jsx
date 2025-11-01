import React from "react";

export default function NewsLetter() {
  return (
    <div className="widget newsletter">
      <h3 className="widget-title title-news">Join our newsletter</h3>
      <p>
        Signup to be the first to hear about exclusive deals, special offers and
        upcoming collections
      </p>
      <div className="form-s2">
        <form onSubmit={(e) => e.preventDefault()}>
          <div className="wd-find-select">
            <div className="form-newsletter relative">
              <input
                type="email"
                className="email-field"
                placeholder="Enter your email"
                defaultValue=""
                name="s"
                title="Enter your email"
                required
              />
              <div className="button-submit">
                <button className="" type="submit">
                  <div className="icon">
                    <svg
                      width={15}
                      height={14}
                      viewBox="0 0 15 14"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M3.00035 6.99998L1.17969 1.08398C5.53489 2.35043 9.6419 4.35118 13.3237 6.99998C9.64218 9.64917 5.53541 11.6504 1.18035 12.9173L3.00035 6.99998ZM3.00035 6.99998H8.00035"
                        stroke="currentColor"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </form>
        {/* End Job  Search Form*/}
      </div>
    </div>
  );
}
