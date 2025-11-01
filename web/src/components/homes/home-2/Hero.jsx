import DropdownSelect from "@/components/common/DropDownSelect";
import React, { useState } from "react";

export default function Hero() {
  const categories = ["All Car", "New Car", "Used Car"];
  const [activeIndex, setActiveIndex] = useState(0); // Default active is the first item
  return (
    <div className="slider home2">
      <video autoPlay muted loop preload="auto">
        <source src="/assets/images/section/video.mp4" type="video/mp4" />
      </video>
      <div className="slider-item-content">
        <div className="container">
          <div className="flat-tabs">
            <div className="flat-tabs-wrap">
              <h1 className="title">Find your right car</h1>
              <div className="box-tab style1 center">
                <ul className="menu-tab tab-title flex flex-wrap">
                  {categories.map((category, index) => (
                    <li
                      key={index}
                      className={`item-title style ${
                        activeIndex === index ? "active" : ""
                      }`}
                      onClick={() => setActiveIndex(index)} // Update active index on click
                    >
                      <span className="inner fs-16 fw-5 lh-20">{category}</span>
                    </li>
                  ))}
                </ul>
              </div>
              <div className="content-tab">
                <div className="content-inner tab-content">
                  <div className="form-sl">
                    <form onSubmit={(e) => e.preventDefault()}>
                      <div className="wd-find-select">
                        <div className="inner-group-1">
                          <div className="form-group-1 flex radio-wrap">
                            <div className="group">
                              <input
                                type="radio"
                                id="test1"
                                name="radio-group"
                                defaultChecked=""
                              />
                              <label htmlFor="test1">Buy Budget</label>
                            </div>
                            <div className="group">
                              <input
                                type="radio"
                                id="test2"
                                name="radio-group"
                              />
                              <label htmlFor="test2">By Brand</label>
                            </div>
                          </div>
                          <div className="form-group-1">
                            <div className="group-select">
                              <DropdownSelect
                                defaultOption={"Select budget"}
                                options={[
                                  "10000$",
                                  "30000$",
                                  "50000$",
                                  "100000$",
                                ]}
                              />
                            </div>
                          </div>
                          <div className="form-group-1 radio-wrap">
                            <div className="group-select">
                              <DropdownSelect
                                options={[
                                  "All vehicle types",
                                  "Audi",
                                  "BMW",
                                  "Dongfeng",
                                  "Ford",
                                ]}
                              />
                            </div>
                          </div>
                        </div>
                        <div className="button-search sc-btn-top radio-wrap">
                          <a className="sc-button" href="#">
                            <span>Search</span>
                            <i className="far fa-search text-color-1" />
                          </a>
                        </div>
                        <div className="flat-bt-top">
                          <a className="sc-button pull-right">
                            <span>Advanced search</span>
                            <svg
                              width={17}
                              height={16}
                              viewBox="0 0 17 16"
                              fill="none"
                              xmlns="http://www.w3.org/2000/svg"
                            >
                              <path
                                d="M3.5 13L13.5 3M13.5 3V10.5M13.5 3H6"
                                stroke="CurrentColor"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                              />
                            </svg>
                          </a>
                        </div>
                      </div>
                      <div className="wd-find-select wd-search-form wd-search-form-2">
                        <div className="box1 grid-4">
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Fuel Type</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Gasoline">Gasoline</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="Petrol">Petrol</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Transmission</option>
                                <option value="Automatic">Automatic</option>
                                <option value="CVT">CVT</option>
                                <option value="DCT">DCT</option>
                                <option value="Manual">Manual</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Driver type</option>
                                <option value="4WD–Four-wheel-drive">
                                  4WD – Four-wheel drive
                                </option>
                                <option value="AWD–All-wheel-drive">
                                  AWD – All-wheel drive
                                </option>
                                <option value="FWD–Front-wheel-drive">
                                  FWD – Front-wheel drive
                                </option>
                                <option value="RWD–Rear-wheel-drive">
                                  RWD – Rear-wheel drive
                                </option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <input
                              type="text"
                              className="text-field"
                              placeholder="Cylinder"
                              defaultValue=""
                            />
                          </div>
                        </div>
                        <div className="box1 grid-4">
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Color</option>
                                <option value="Red">Red</option>
                                <option value="Black">Black</option>
                                <option value="White">White</option>
                                <option value="Green">Green</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <div className="widget widget-price">
                              <div className="caption flex-two">
                                <div>
                                  <span id="slider-range-value1" />
                                  <span id="slider-range-value2" />
                                </div>
                              </div>
                              <div id="slider-range" />
                              <div className="slider-labels">
                                <div>
                                  <input
                                    type="hidden"
                                    name="min-value"
                                    defaultValue=""
                                  />
                                  <input
                                    type="hidden"
                                    name="max-value"
                                    defaultValue=""
                                  />
                                </div>
                              </div>
                            </div>
                            {/* /.widget_price */}
                          </div>
                          <div className="form-group wg-box3">
                            <div className="widget widget-price">
                              <div className="caption flex-two">
                                <div>
                                  <span className="fw-6">Price: </span>
                                  <span id="slider-range-value01" />
                                  <span id="slider-range-value02" />
                                </div>
                              </div>
                              <div id="slider-range2" />
                              <div className="slider-labels">
                                <div>
                                  <input
                                    type="hidden"
                                    name="min-value2"
                                    defaultValue=""
                                  />
                                  <input
                                    type="hidden"
                                    name="max-value2"
                                    defaultValue=""
                                  />
                                </div>
                              </div>
                            </div>
                            {/* /.widget_price */}
                          </div>
                          <div className="form-group wg-box3">
                            <div className="widget widget-price">
                              <div className="caption flex-two">
                                <div>
                                  <span className="fw-6">Year: </span>
                                  <span id="slider-range-value03" />
                                  <span id="slider-range-value04" />
                                </div>
                              </div>
                              <div id="slider-range3" />
                              <div className="slider-labels">
                                <div>
                                  <input
                                    type="hidden"
                                    name="min-value3"
                                    defaultValue=""
                                  />
                                  <input
                                    type="hidden"
                                    name="max-value3"
                                    defaultValue=""
                                  />
                                </div>
                              </div>
                            </div>
                            {/* /.widget_price */}
                          </div>
                        </div>
                        <div className="box1 grid-4">
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Door</option>
                                <option value="2Door">2 Door</option>
                                <option value="4Door">4 Door</option>
                                <option value="6Door">6 Door</option>
                                <option value="8Door">8 Door</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Seat</option>
                                <option value="2Seat">2 Seat</option>
                                <option value="4Seat">4 Seat</option>
                                <option value="6Seat">6 Seat</option>
                                <option value="8Seat">8 Seat</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <div className="group-select tf-select">
                              <select className="nice-select">
                                <option>Ownership</option>
                                <option value="Ownership">Ownership</option>
                                <option value="Ownership">Ownership</option>
                                <option value="Ownership">Ownership</option>
                                <option value="Ownership">Ownership</option>
                              </select>
                            </div>
                          </div>
                          <div className="form-group wg-box3">
                            <input
                              type="text"
                              className="text-field"
                              placeholder="Cylinder"
                              defaultValue=""
                            />
                          </div>
                        </div>
                        <div className="boder-wg" />
                        <div className="features-wrap">
                          <h5>Features</h5>
                          <div className="box2 grid-5">
                            <div className="form-group wg-box4">
                              <div className="title fs-16 fw-5 lh-20 text-color-2">
                                Request Price Label
                              </div>
                              <div className="tf-amenities bg-white">
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    A/C: Front
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Backup Camera
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Cruise Control
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Navigation
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Power Locks
                                  </span>
                                </label>
                              </div>
                            </div>
                            <div className="form-group wg-box4">
                              <div className="title fs-16 fw-5 lh-20 text-color-2">
                                Entertainment
                              </div>
                              <div className="tf-amenities bg-white">
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Audio system
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Touchscreen display
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    GPS navigation
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Phone connectivity
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    In-car Wi-Fi
                                  </span>
                                </label>
                              </div>
                            </div>
                            <div className="form-group wg-box4">
                              <div className="title fs-16 fw-5 lh-20 text-color-2">
                                Safety
                              </div>
                              <div className="tf-amenities bg-white">
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Anti-lock brake system (ABS):
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Electronic stability control (ESC)
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Brake assist (BA)
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Airbags
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Blind spot monitoring system (BSM)
                                  </span>
                                </label>
                              </div>
                            </div>
                            <div className="form-group wg-box4">
                              <div className="title fs-16 fw-5 lh-20 text-color-2">
                                Interior
                              </div>
                              <div className="tf-amenities bg-white">
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Premium leather seats
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Wood trim
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Mini bar
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Rear seat ventilation system
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Large infotainment screen
                                  </span>
                                </label>
                              </div>
                            </div>
                            <div className="form-group wg-box4">
                              <div className="title fs-16 fw-5 lh-20 text-color-2">
                                Exterior
                              </div>
                              <div className="tf-amenities bg-white">
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Chrome-plated grill
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Smart headlight cluster
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Premium wheels
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    Body character lines
                                  </span>
                                </label>
                                <label className="flex-three">
                                  <input name="newsletter" type="checkbox" />
                                  <span className="btn-checkbox" />
                                  <span className="text-color-2 font-2">
                                    High-quality paint
                                  </span>
                                </label>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </form>
                    {/* End Job  Search Form*/}
                  </div>
                </div>
              </div>
            </div>
            <div className="wrap-icon trending2">
              <div className="icon-box text-color-1 fs-14 fw-4 flex align-center font">
                <span className="icon-autodeal-trending" />
                <a href="#">
                  <strong className="font-2 fw-7">Trending:</strong> Volkswagen
                  Scirocco, Honda Civic, Audi A3, Toyota Vios, Nissan GTR,
                  Subaru Impreza
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
