import React from "react";
import Slider1 from "./sliders/Slider1";

import Description from "./detailComponents/Description";
import Overview from "./detailComponents/Overview";

import LoanCalculator from "./detailComponents/LoanCalculator";
import CarReview from "./detailComponents/CarReview";
import CarInfo from "./detailComponents/CarInfo";
import ProfileInfo from "./detailComponents/ProfileInfo";
import Recommended from "./detailComponents/Recommended";
import Features from "./detailComponents/Features";
import SidebarToggleButton from "./SidebarToggleButton";
export default function CarDetails1({ carItem }) {
  return (
    <>
      <section className="tf-section3 listing-detail style-1">
        <div className="container">
          <div className="row">
            <div className="col-lg-8">
              <div className="listing-detail-wrap">
                <Slider1 />
                <div className="row">
                  <div className="col-lg-12">
                    <nav
                      id="navbar-example2"
                      className="navbar tab-listing-scroll"
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
                    <div
                      data-bs-spy="scroll"
                      data-bs-target="#navbar-example2"
                      data-bs-offset={0}
                      className="scrollspy-example"
                      tabIndex={0}
                    >
                      <div className="listing-description mb-40">
                        <div className="tfcl-listing-header">
                          <h2>Description</h2>
                        </div>
                        <Description />
                      </div>
                      <div
                        className="listing-description footer-col-block"
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
                      <div className="listing-line" />
                      <div
                        className="listing-features footer-col-block"
                        id="scrollspyHeading2"
                      >
                        <div className="footer-heading-desktop mb-30">
                          <h2>Features</h2>
                        </div>
                        <div className="footer-heading-mobie listing-details-mobie mb-30">
                          <h2>Features</h2>
                        </div>
                        <Features />
                      </div>
                      <div className="listing-line" />
                      <div
                        className="listing-calculator loan-calculator-form"
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
                      <div className="listing-line" />
                      <div className="listing-location" id="scrollspyHeading3">
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
                      <div className="listing-line" />
                      <div
                        className="listing-reviews flat-property-detail"
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
                <div className="widget-listing mb-40">
                  <div className="heading-widget">
                    <h2 className="title">{carItem.title}</h2>
                    <CarInfo carItem={carItem} />
                  </div>
                </div>
                <div className="widget-listing mb-30">
                  <ProfileInfo />
                </div>
                <div className="list-icon-pf gap-8 flex-three mb-40">
                  <i className="far fa-flag" />
                  <p className="font-1">Report this listing</p>
                </div>
                <div className="widget-listing">
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
