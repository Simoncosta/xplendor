import { carListings } from "@/data/cars";
import React from "react";
import { Link } from "react-router-dom";
import { Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

import { toggleItems2, toggleItems3 } from "@/data/faqs";
import Accordion from "../common/Accordions";
export default function Compare() {
  return (
    <section className="tf-section3 flat-blog-list flat-property">
      <div className="container">
        <div className="inner-heading flex-two flex-wrap">
          <h1 className="heading-listing">Compare vehicle</h1>
          <div className="social-listing flex-six flex-wrap">
            <p>Share this page:</p>
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
        </div>
        <div className="wrap-single-compare">
          <div className="inner-respond">
            <div className="header-compare">
              {carListings.map((elm, i) => (
                <div key={i} className="box-car-list hv-one">
                  <div className="image-group relative">
                    <div className="top flex-two">
                      <ul className="d-flex gap-8">
                        {elm.featured ? (
                          <li className="flag-tag success">Featured</li>
                        ) : (
                          ""
                        )}
                        <li className="flag-tag style-1">
                          <div className="icon">
                            <svg
                              width={16}
                              height={13}
                              viewBox="0 0 16 13"
                              fill="none"
                              xmlns="http://www.w3.org/2000/svg"
                            >
                              <path
                                d="M1.5 9L4.93933 5.56067C5.07862 5.42138 5.24398 5.31089 5.42597 5.2355C5.60796 5.16012 5.80302 5.12132 6 5.12132C6.19698 5.12132 6.39204 5.16012 6.57403 5.2355C6.75602 5.31089 6.92138 5.42138 7.06067 5.56067L10.5 9M9.5 8L10.4393 7.06067C10.5786 6.92138 10.744 6.81089 10.926 6.7355C11.108 6.66012 11.303 6.62132 11.5 6.62132C11.697 6.62132 11.892 6.66012 12.074 6.7355C12.256 6.81089 12.4214 6.92138 12.5607 7.06067L14.5 9M2.5 11.5H13.5C13.7652 11.5 14.0196 11.3946 14.2071 11.2071C14.3946 11.0196 14.5 10.7652 14.5 10.5V2.5C14.5 2.23478 14.3946 1.98043 14.2071 1.79289C14.0196 1.60536 13.7652 1.5 13.5 1.5H2.5C2.23478 1.5 1.98043 1.60536 1.79289 1.79289C1.60536 1.98043 1.5 2.23478 1.5 2.5V10.5C1.5 10.7652 1.60536 11.0196 1.79289 11.2071C1.98043 11.3946 2.23478 11.5 2.5 11.5ZM9.5 4H9.50533V4.00533H9.5V4ZM9.75 4C9.75 4.0663 9.72366 4.12989 9.67678 4.17678C9.62989 4.22366 9.5663 4.25 9.5 4.25C9.4337 4.25 9.37011 4.22366 9.32322 4.17678C9.27634 4.12989 9.25 4.0663 9.25 4C9.25 3.9337 9.27634 3.87011 9.32322 3.82322C9.37011 3.77634 9.4337 3.75 9.5 3.75C9.5663 3.75 9.62989 3.77634 9.67678 3.82322C9.72366 3.87011 9.75 3.9337 9.75 4Z"
                                stroke="white"
                                strokeWidth="1.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                              />
                            </svg>
                          </div>
                          6
                        </li>
                      </ul>
                      <div className="year flag-tag">{elm.year}</div>
                    </div>
                    <Swiper
                      modules={[Pagination]}
                      pagination={{ el: `.spd${i}`, clickable: true }}
                      className="swiper-container carousel-2 img-style"
                    >
                      {elm.images.map((elm2, i2) => (
                        <SwiperSlide key={i2} className="swiper-slide">
                          <img
                            className="lazyload"
                            alt="image"
                            src={elm2.src}
                            width={615}
                            height={462}
                          />
                        </SwiperSlide>
                      ))}

                      <div className="pagi2">
                        <div className={` swiper-pagination2 spd${i}`} />
                      </div>
                    </Swiper>
                  </div>
                  <div className="content">
                    <div className="text-address">
                      <p className="text-color-3 font">{elm.type}</p>
                    </div>
                    <h5 className="link-style-1">
                      <Link to={`/listing-detail-v2/${elm.id}`}>
                        {elm.title}
                      </Link>
                    </h5>

                    <div className="money fs-20 fw-5 lh-25 text-color-3">
                      ${elm.price.toLocaleString()}
                    </div>
                    <div className="days-box flex justify-space align-center">
                      <div className="img-author">
                        <img
                          className="lazyload"
                          alt="image"
                          src={elm.authorImage}
                          width={120}
                          height={120}
                        />
                        <span className="font text-color-2 fw-5">
                          {elm.authorName}
                        </span>
                      </div>
                      <Link
                        to={`/listing-detail-v2/${elm.id}`}
                        className="view-car"
                      >
                        View car
                      </Link>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            <nav
              id="navbar-example2"
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
                    Features
                  </a>
                </li>
                <li className="nav-item">
                  <a className="nav-link" href="#scrollspyHeading3">
                    Specification
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
              <div id="scrollspyHeading1">
                <div className="tf-compare-overview compare-table">
                  <h3 className="title-table">Car Overview</h3>
                  <div className="title-tr">Price</div>
                  <ul className="group-tr">
                    <li>$70,000</li>
                    <li>$80,000</li>
                    <li>$90,000</li>
                  </ul>
                  <div className="title-tr">Fuel Type</div>
                  <ul className="group-tr">
                    <li>Petrol</li>
                    <li>Petrol</li>
                    <li>Petrol</li>
                  </ul>
                  <div className="title-tr">Kms Driven</div>
                  <ul className="group-tr">
                    <li>22,737 Kms</li>
                    <li>28,937 Kms</li>
                    <li>17,730 Kms</li>
                  </ul>
                  <div className="title-tr">Ownership</div>
                  <ul className="group-tr">
                    <li>1st Owner</li>
                    <li>1st Owner</li>
                    <li>1st Owner</li>
                  </ul>
                  <div className="title-tr">Registration Year</div>
                  <ul className="group-tr">
                    <li>Jul 2024</li>
                    <li>Jul 2024</li>
                    <li>Jul 2024</li>
                  </ul>
                  <div className="title-tr">Transmission</div>
                  <ul className="group-tr">
                    <li>Manual</li>
                    <li>Manual</li>
                    <li>Manual</li>
                  </ul>
                  <div className="title-tr">Insurance Type</div>
                  <ul className="group-tr">
                    <li>Zero Dep</li>
                    <li>Zero Dep</li>
                    <li>Comprehensive</li>
                  </ul>
                  <div className="title-tr">Seats</div>
                  <ul className="group-tr">
                    <li>5 Seats</li>
                    <li>5 Seats</li>
                    <li>5 Seats</li>
                  </ul>
                  <div className="title-tr">Engine Displacement</div>
                  <ul className="group-tr">
                    <li>999 cc</li>
                    <li>999 cc</li>
                    <li>999 cc</li>
                  </ul>
                  <div className="title-tr">Car location</div>
                  <ul className="group-tr mb-0">
                    <li>4140 Parker Rd. Allentown, New Mexico 31134</li>
                    <li>2464 Royal Ln. Mesa, New Jersey 45463</li>
                    <li>4517 Washington Ave. Manchester, Kentucky 39495</li>
                  </ul>
                </div>
              </div>
              <div id="scrollspyHeading2">
                <div className="tf-compare-fatures compare-table">
                  <h3 className="title-table">Features</h3>
                  <div className="title-tr">Bottle Holder</div>
                  <ul className="group-tr">
                    <li>Front &amp; Rear Door</li>
                    <li>Front &amp; Rear Door</li>
                    <li>Front &amp; Rear Door</li>
                  </ul>
                  <div className="title-tr">Foldable Rear Seat</div>
                  <ul className="group-tr">
                    <li>60:40 Split</li>
                    <li>60:40 Split</li>
                    <li>60:40 Split</li>
                  </ul>
                  <div className="title-tr">Parking Sensors</div>
                  <ul className="group-tr">
                    <li>Rear</li>
                    <li>Rear</li>
                    <li>Rear</li>
                  </ul>
                  <div className="title-tr">Remote Trunk Opener</div>
                  <ul className="group-tr">
                    <li>n/a</li>
                    <li>n/a</li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                  </ul>
                  <div className="title-tr">Low Fuel Warning Light</div>
                  <ul className="group-tr">
                    <li>n/a</li>
                    <li>n/a</li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                  </ul>
                  <div className="title-tr">Trunk Light</div>
                  <ul className="group-tr">
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>n/a</li>
                    <li>n/a</li>
                  </ul>
                  <div className="title-tr">Seat Lumbar Support</div>
                  <ul className="group-tr">
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>n/a</li>
                    <li>n/a</li>
                  </ul>
                  <div className="title-tr">Navigation System</div>
                  <ul className="group-tr">
                    <li>n/a</li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>n/a</li>
                  </ul>
                  <div className="title-tr">Smart Access Card Entry</div>
                  <ul className="group-tr">
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>n/a</li>
                    <li>n/a</li>
                  </ul>
                  <div className="title-tr">Glove Box Cooling</div>
                  <ul className="group-tr">
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                  </ul>
                  <div className="title-tr">Usb Charger</div>
                  <ul className="group-tr">
                    <li>Front</li>
                    <li>Front</li>
                    <li>Front &amp; Rear</li>
                  </ul>
                  <div className="title-tr">Central Console Armrest</div>
                  <ul className="group-tr">
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                    <li>With Storage</li>
                  </ul>
                  <div className="title-tr">Luggage Hook And Net</div>
                  <ul className="group-tr">
                    <li>n/a</li>
                    <li>n/a</li>
                    <li>
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={18}
                        height={15}
                        viewBox="0 0 18 15"
                        fill="none"
                      >
                        <path
                          d="M1.5 8.25L7.5 14.25L16.5 0.75"
                          stroke="#FF7101"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </li>
                  </ul>
                  <div className="title-tr">
                    One Touch Operating Power Window
                  </div>
                  <ul className="group-tr">
                    <li>Driver's Window</li>
                    <li>Driver's Window</li>
                    <li>Driver's Window</li>
                  </ul>
                  <div className="col-lg-12 flat-accordion">
                    <Accordion
                      parentClass="flat-toggle style-1"
                      faqData={toggleItems2}
                    />
                  </div>
                </div>
              </div>
              <div id="scrollspyHeading3">
                <div className="tf-compare-overview compare-table">
                  <h3 className="title-table">Specification</h3>
                  <div className="col-lg-12 flat-accordion">
                    <Accordion
                      parentClass="flat-toggle style-1"
                      faqData={toggleItems3}
                    />
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
