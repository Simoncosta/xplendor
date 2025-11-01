import React from "react";
import { Link } from "react-router-dom";

export default function LoanCalculator() {
  return (
    <section className="loan-calculator inner-6">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="w-520">
              <div className="box-car-list style-4 hv-one">
                <div className="content">
                  <div className="text-address">
                    <p className="text-color-3 font">Sedan</p>
                  </div>
                  <h2 className="link-style-1">
                    <a href={`#`}>2017 BMV X1 xDrive 20d xline</a>
                  </h2>
                  <div className="icon-box flex flex-wrap">
                    <div className="icons flex-three">
                      <i className="icon-autodeal-km1" />
                      <span>72,491 kms</span>
                    </div>
                    <div className="icons flex-three">
                      <i className="icon-autodeal-diesel" />
                      <span>Diesel</span>
                    </div>
                    <div className="icons flex-three">
                      <i className="icon-autodeal-automatic" />
                      <span>Automatic</span>
                    </div>
                  </div>
                  <div className="days-box flex justify-space align-center">
                    <div className="img-author flex-three">
                      <img
                        className="ls-is-cached lazyloaded"
                        data-src="/assets/images/author/avt2.jpg"
                        alt="image"
                        src="/assets/images/author/avt2.jpg"
                        width={450}
                        height={450}
                      />
                      <div className="content-inner">
                        <p>Dealer</p>
                        <h5 className="font text-color-2">John Smith</h5>
                      </div>
                    </div>
                    <Link to={`/listing-detail-v1/1`} className="view-car">
                      View car
                    </Link>
                  </div>
                  <div className="bottom-box flex-two">
                    <div className="money fs-20 fw-5 lh-25 text-color-3">
                      $73,000
                    </div>
                    <ul className="change-heart style-2 flex">
                      <li className="box-icon">
                        <div className="icon">
                          <svg
                            width={18}
                            height={16}
                            viewBox="0 0 18 16"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                          >
                            <path
                              d="M16.5 4.875C16.5 2.80417 14.7508 1.125 12.5933 1.125C10.9808 1.125 9.59583 2.06333 9 3.4025C8.40417 2.06333 7.01917 1.125 5.40583 1.125C3.25 1.125 1.5 2.80417 1.5 4.875C1.5 10.8917 9 14.875 9 14.875C9 14.875 16.5 10.8917 16.5 4.875Z"
                              stroke="CurrentColor"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </div>
                      </li>
                      <li className="box-icon">
                        <div className="icon">
                          <svg
                            width={18}
                            height={18}
                            viewBox="0 0 18 18"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                          >
                            <path
                              d="M5.25 16.5L1.5 12.75M1.5 12.75L5.25 9M1.5 12.75H12.75M12.75 1.5L16.5 5.25M16.5 5.25L12.75 9M16.5 5.25H5.25"
                              stroke="CurrentColor"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </div>
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
