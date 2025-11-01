import React from "react";
import { Link } from "react-router-dom";

import AgentList from "./AgentList";
import Cars from "./Cars";
import Review from "./Review";
import { carListings2 } from "@/data/cars";
import ContactMap from "../dashboard/Map";
export default function DealerDetails({ dealerItem }) {
  return (
    <section className="tf-section3 listing-detail style-1">
      <div className="container">
        <div className="row">
          <div className="col-lg-8 col-md-12">
            <div className="features-thumb mb-4">
              <img
                alt="images"
                src="/assets/images/dashboard/single-dealer.jpg"
                width={830}
                height={467}
              />
            </div>
            <h2 className="title mb-3">Honda cars Pasig</h2>
            <p className="mb-2">
              Stay informed about emerging trends in the housing market, such as
              the demand for sustainable homes, technological advancements, and
              demographic shifts. Companies aligning with these trends may
              present attractive investment <br />
              opportunities.
            </p>
            <p>
              Take a long-term investment approach if you believe in the
              stability and growth potential of the housing sector. Look for
              companies with solid fundamentals and a track record of success.
              For short-term traders, capitalize on market fluctuations driven
              by economic reports, interest rate changes, or industry-specific
              news. Keep a close eye on earnings reports and government housing
              data releases.
            </p>
            <div className="tf-sale-agent-list">
              <h2 className="mb-3">Sale agent list</h2>
              <AgentList />
              <a href="#" className="fs-16 fw-5 font text-color-3 lh-22">
                View all sale agents <i className="icon-autodeal-view-more" />
              </a>
            </div>
            <div className="tf-list-car-agent">
              <h2 className="mb-3">Dealership inventory (8)</h2>
              <Cars />
              <a href="#" className="fs-16 fw-5 font text-color-3 lh-22">
                View all car <i className="icon-autodeal-view-more" />
              </a>
            </div>
            <h2 className="mb-12">Honda cars pasig servicing</h2>
            <p className="mb-3">
              Check out what Honda Cars, Pasig serves their customers
            </p>
            <div className="widget-book-apoint">
              <h3>Book an appointment</h3>
              <p className="mb-3">
                You are interested in this dealership and want to book an
                appointment with them? <br />
                Just leave your contact and preferred date and time
              </p>
              <a href="#">Book an appointment with Dealership</a>
            </div>
            <div
              className="listing-reviews dealer-review flat-property-detail"
              id="scrollspyHeading5"
            >
              <div className="box-title mb-30">
                <h2 className="title-ct">Car User Reviews &amp; Rating</h2>
              </div>
              <Review />
            </div>
          </div>
          <div className="col-lg-4 col-md-12">
            <div className="dealer-sidebar">
              <div className="widget-dealer-contact mb-4">
                <h1>{dealerItem.title}</h1>
                <ul className="list-authencation">
                  <li>
                    <i className="icon-autodeal-check" /> Certified seller
                  </li>
                  <li>
                    <i className="icon-autodeal-check" />
                    Verified contact
                  </li>
                </ul>
                <div className="open-store">
                  <h6>Business hours</h6>
                  <ul>
                    <li>
                      <span className="left">Mon - Fri:</span>
                      <span className="right">06:-00 Am - 09:00 Pm</span>
                    </li>
                    <li>
                      <span className="left">Sat - Sun:</span>
                      <span className="right">07:-00 Am - 07:00 Pm</span>
                    </li>
                    <li>
                      <span className="left">Holiday:</span>
                      <span className="right">Close</span>
                    </li>
                  </ul>
                </div>
                <div className="rating">
                  <div className="content-left">
                    <p>0 Reviews</p>
                  </div>
                  <div className="content-right">
                    <div className="overall-rating-detail-star">
                      <i
                        className="star disabled-click icon-autodeal-star"
                        data-rating={1}
                      />
                      <i
                        className="star disabled-click icon-autodeal-star"
                        data-rating={2}
                      />
                      <i
                        className="star disabled-click icon-autodeal-star"
                        data-rating={3}
                      />
                      <i
                        className="star disabled-click icon-autodeal-star"
                        data-rating={4}
                      />
                      <i
                        className="star disabled-click icon-autodeal-star"
                        data-rating={5}
                      />
                      <span>
                        <b>5</b>/5
                      </span>
                    </div>
                  </div>
                </div>
                <a href="#" className="button-form-1">
                  Get quote
                </a>
                <a href="#" className="button-form-2">
                  Request test drive
                </a>
              </div>
              <div className="social-listing flex-wrap mb-4">
                <p>Share for Honda cars Pasig:</p>
                <div className="icon-social style1">
                  <a href="#">
                    <i className="icon-autodeal-facebook" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-linkedin" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-twitter" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-instagram" />
                  </a>
                </div>
              </div>
              <div className="widget-dealer-map mb-4">
                <h3 className="mb-3">Map to Honda cars Pasig</h3>
                <div className="address-dealer">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width={20}
                    height={20}
                    viewBox="0 0 20 20"
                    fill="none"
                  >
                    <path
                      d="M7.5 5.62525V12.5003M12.5 7.50025V14.3753M12.9192 17.2903L16.9817 15.2594C17.2992 15.1011 17.5 14.7761 17.5 14.4211V4.01692C17.5 3.32025 16.7667 2.86692 16.1433 3.17859L12.9192 4.79025C12.655 4.92275 12.3442 4.92275 12.0808 4.79025L7.91917 2.71025C7.78901 2.6452 7.64551 2.61133 7.5 2.61133C7.35449 2.61133 7.21098 2.6452 7.08083 2.71025L3.01833 4.74109C2.7 4.90025 2.5 5.22525 2.5 5.57942V15.9836C2.5 16.6803 3.23333 17.1336 3.85667 16.8219L7.08083 15.2103C7.345 15.0778 7.65583 15.0778 7.91917 15.2103L12.0808 17.2911C12.345 17.4228 12.6558 17.4228 12.9192 17.2911V17.2903Z"
                      stroke="#B6B6B6"
                      strokeWidth="1.5"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  2972 Westheimer Rd. Santa Ana, Illinois 85486
                </div>
                <div
                  id="map-single"
                  className="map-single"
                  data-map-zoom={16}
                  data-map-scroll="true"
                >
                  <ContactMap />
                </div>
                <a
                  href="http://maps.google.com/maps?q=4255 Northwest 156th Street, United States"
                  className="location-link"
                  target="__blank"
                >
                  View map
                </a>
              </div>
              <div className="widget-listing">
                <div className="listing-header mb-30">
                  <h3>Recommended Used Cars</h3>
                  <p>Showing 26 more cars you might like</p>
                </div>
                <div className="listing-recommended mb-30">
                  {carListings2.slice(0, 4).map((elm, i) => (
                    <div key={i} className="item flex">
                      <div className="image">
                        <img
                          className="ls-is-cached lazyloaded"
                          alt="image"
                          src={elm.imgSrc}
                          width={450}
                          height={338}
                        />
                      </div>
                      <div className="content">
                        <h6>
                          <Link to={`/listing-detail-v2/${elm.id}`}>
                            {elm.title}
                          </Link>
                        </h6>
                        <p className="fs-14 fw-7 text-color-2 font-1">
                          ${elm.price.toLocaleString()}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
                <a href="#" className="fs-16 fw-5 font text-color-3 lh-22">
                  View more reviews <i className="icon-autodeal-view-more" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
