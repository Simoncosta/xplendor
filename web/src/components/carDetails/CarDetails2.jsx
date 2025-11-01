import React from "react";
import Slider2 from "./sliders/Slider2";

import Description from "./detailComponents/Description";
import Overview from "./detailComponents/Overview";
import Features from "./detailComponents/Features";
import LoanCalculator from "./detailComponents/LoanCalculator";
import CarReview from "./detailComponents/CarReview";
import ProfileInfo from "./detailComponents/ProfileInfo";
import Recommended from "./detailComponents/Recommended";
import { allCars } from "@/data/cars";
import SidebarToggleButton from "./SidebarToggleButton";
export default function CarDetails2({ carItem = allCars[0] }) {
  return (
    <>
      <section className="tf-section3 listing-detail style-2">
        <div className="container">
          <div className="row">
            <div className="heading-widget flex-one mb-20 flex-wrap gap-8">
              <div className="inner">
                <h1 className="title">{carItem.title}</h1>
                <div className="icon-box flex flex-wrap">
                  <div className="icons flex-three gap-8">
                    <i className="icon-autodeal-km1" />
                    <span>{carItem.km.toLocaleString()} kms</span>
                  </div>
                  <div className="icons flex-three gap-8">
                    <i className="icon-autodeal-diesel" />
                    <span>{carItem.fuelType}</span>
                  </div>
                  <div className="icons flex-three gap-8">
                    <i className="icon-autodeal-automatic" />
                    <span>{carItem.transmission}</span>
                  </div>
                  <div className="icons flex-three gap-8">
                    <i className="icon-autodeal-owner" />
                    <span>1st owner</span>
                  </div>
                </div>
                <div className="money text-color-3 font">
                  ${carItem.price.toLocaleString()}
                </div>
                <div className="price-wrap flex">
                  <p className="fs-12 lh-16 text-color-2">
                    Monthly installment payment:
                    <span className="fs-14 fw-5 font">$4,000</span>
                  </p>
                  <p className="fs-12 lh-16">New car price: $100.000</p>
                </div>
              </div>
              <ul className="action-icon style-1 flex flex-wrap">
                <li>
                  <a href="#" className="icon">
                    <svg
                      width={16}
                      height={18}
                      viewBox="0 0 16 18"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M4.41276 8.18022C4.23116 7.85345 3.94619 7.59624 3.60259 7.44895C3.25898 7.30167 2.8762 7.27265 2.51432 7.36645C2.15244 7.46025 1.83196 7.67157 1.60317 7.96722C1.37438 8.26287 1.25024 8.62613 1.25024 8.99997C1.25024 9.37381 1.37438 9.73706 1.60317 10.0327C1.83196 10.3284 2.15244 10.5397 2.51432 10.6335C2.8762 10.7273 3.25898 10.6983 3.60259 10.551C3.94619 10.4037 4.23116 10.1465 4.41276 9.81972M4.41276 8.18022C4.54776 8.42322 4.62501 8.70222 4.62501 8.99997C4.62501 9.29772 4.54776 9.57747 4.41276 9.81972M4.41276 8.18022L11.5873 4.19472M4.41276 9.81972L11.5873 13.8052M11.5873 4.19472C11.6924 4.39282 11.8361 4.56797 12.0097 4.70991C12.1834 4.85186 12.3836 4.95776 12.5987 5.02143C12.8138 5.08509 13.0394 5.10523 13.2624 5.08069C13.4853 5.05614 13.7011 4.98739 13.8972 4.87846C14.0933 4.76953 14.2657 4.62261 14.4043 4.44628C14.5429 4.26995 14.645 4.06775 14.7046 3.85151C14.7641 3.63526 14.78 3.40931 14.7512 3.18686C14.7225 2.96442 14.6496 2.74994 14.537 2.55597C14.3151 2.17372 13.952 1.89382 13.5259 1.77643C13.0997 1.65904 12.6445 1.71352 12.2582 1.92818C11.8718 2.14284 11.585 2.50053 11.4596 2.92436C11.3341 3.34819 11.38 3.80433 11.5873 4.19472ZM11.5873 13.8052C11.4796 13.999 11.4112 14.2121 11.3859 14.4323C11.3606 14.6525 11.3789 14.8756 11.4398 15.0887C11.5007 15.3019 11.603 15.5009 11.7408 15.6746C11.8787 15.8482 12.0494 15.9929 12.2431 16.1006C12.4369 16.2082 12.65 16.2767 12.8702 16.302C13.0905 16.3273 13.3135 16.3089 13.5267 16.248C13.7398 16.1871 13.9389 16.0848 14.1125 15.947C14.2861 15.8092 14.4309 15.6385 14.5385 15.4447C14.7559 15.0534 14.809 14.5917 14.686 14.1612C14.563 13.7307 14.274 13.3668 13.8826 13.1493C13.4913 12.9319 13.0296 12.8789 12.5991 13.0019C12.1686 13.1249 11.8047 13.4139 11.5873 13.8052Z"
                        stroke="CurrentColor"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </a>
                </li>
                <li>
                  <a href="#" className="icon">
                    <svg
                      width={16}
                      height={14}
                      viewBox="0 0 16 14"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M14.75 4.1875C14.75 2.32375 13.1758 0.8125 11.234 0.8125C9.78275 0.8125 8.53625 1.657 8 2.86225C7.46375 1.657 6.21725 0.8125 4.76525 0.8125C2.825 0.8125 1.25 2.32375 1.25 4.1875C1.25 9.6025 8 13.1875 8 13.1875C8 13.1875 14.75 9.6025 14.75 4.1875Z"
                        stroke="CurrentColor"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </a>
                </li>
                <li>
                  <a href="#" className="icon">
                    <svg
                      width={16}
                      height={16}
                      viewBox="0 0 16 16"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <path
                        d="M4.625 14.75L1.25 11.375M1.25 11.375L4.625 8M1.25 11.375H11.375M11.375 1.25L14.75 4.625M14.75 4.625L11.375 8M14.75 4.625H4.625"
                        stroke="CurrentColor"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </a>
                </li>
              </ul>
            </div>
          </div>
        </div>
        <div className="container-fluid">
          <Slider2 />
        </div>
        <div className="container">
          <div className="row">
            <div className="col-md-12">
              <nav
                id="navbar-example2 "
                className="navbar tab-listing-scroll mb-30"
              >
                <ul className="nav nav-pills">
                  <li className="nav-item">
                    <a className="nav-link" href="#scrollspyHeading1">
                      Overview
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#scrollspyHeading2">
                      Specs &amp; features
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#scrollspyHeading3">
                      Recommended cars
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#scrollspyHeading4">
                      Loan calculator
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#scrollspyHeading5">
                      New car reviews
                    </a>
                  </li>
                </ul>
              </nav>
            </div>
            <div className="col-lg-8">
              <div className="listing-detail-wrap">
                <div className="row">
                  <div className="col-lg-12">
                    <div
                      data-bs-spy="scroll"
                      data-bs-target="#navbar-example2"
                      data-bs-offset={0}
                      className="scrollspy-example"
                      tabIndex={0}
                    >
                      <div className="listing-description mb-40 widget-listing box-sd">
                        <div className="tfcl-listing-header">
                          <h2>Description</h2>
                        </div>
                        <Description />
                      </div>
                      <div
                        className="listing-description widget-listing box-sd mb-30 footer-col-block"
                        id="scrollspyHeading1"
                      >
                        <div className="footer-heading-desktop">
                          <h2>Car overview</h2>
                        </div>
                        <div className="footer-heading-mobie listing-details-mobie">
                          <h2>Car overview</h2>
                        </div>
                        <Overview />
                      </div>
                      <div
                        className="listing-features widget-listing box-sd mb-30 footer-col-block"
                        id="scrollspyHeading2"
                      >
                        <div className="footer-heading-desktop">
                          <h2>Features</h2>
                        </div>
                        <div className="footer-heading-mobie listing-details-mobie mb-30">
                          <h2>Features</h2>
                        </div>
                        <Features />
                      </div>
                      <div
                        className="listing-calculator loan-calculator-form widget-listing box-sd mb-30"
                        id="scrollspyHeading4"
                      >
                        <div className="box-title">
                          <h2 className="title-ct">Auto Loan Calculator</h2>
                          <p>
                            Use our calculator to estimate your monthly car
                            payments.
                          </p>
                        </div>
                        <LoanCalculator />
                      </div>
                      <div
                        className="listing-location widget-listing box-sd mb-30"
                        id="scrollspyHeading3"
                      >
                        <div className="box-title">
                          <h2 className="title-ct">Location</h2>
                          <div className="list-icon-pf gap-8 flex-three">
                            <i className="far fa-map" />
                            <p className="font-1">
                              2972 Westheimer Rd. Santa Ana, Illinois 85486
                            </p>
                          </div>
                        </div>
                        <iframe
                          className="map-content"
                          src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d7302.453092836291!2d90.47477022812872!3d23.77494577893369!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1svi!2s!4v1627293157601!5m2!1svi!2s"
                          allowFullScreen=""
                          loading="lazy"
                        />
                      </div>
                      <div
                        className="listing-reviews flat-property-detail widget-listing box-sd mb-30"
                        id="scrollspyHeading5"
                      >
                        <div className="box-title">
                          <h2 className="title-ct">
                            Car User Reviews &amp; Rating
                          </h2>
                        </div>
                        <CarReview />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div className="col-lg-4">
              <div className="overlay-siderbar-mobie" />
              <div className="listing-sidebar">
                <div className="widget-listing mb-30 box-sd">
                  <ProfileInfo />
                </div>
                <div className="list-icon-pf gap-8 flex-three mb-40">
                  <i className="far fa-flag" />
                  <p className="font-1">Report this listing</p>
                </div>
                <div className="widget-listing box-sd">
                  <div className="listing-header mb-30">
                    <h3>Recommended Used Cars</h3>
                    <p>Showing 26 more cars you might like</p>
                  </div>
                  <Recommended />
                  <a href="#" className="fs-16 fw-5 font text-color-3 lh-22">
                    View more reviews <i className="icon-autodeal-view-more" />
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <SidebarToggleButton />
    </>
  );
}
