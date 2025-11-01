import React from "react";
import DashboardChart from "./DashboardChart";
import { Link } from "react-router-dom";

import DropdownSelect from "../common/DropDownSelect";
import { cars } from "@/data/cars";
import Pagination2 from "../common/Pagination2";
export default function DashBoard() {
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title">Dashboard</h1>
                <div className="tfcl-dashboard-overview">
                  <div className="row">
                    <div className="col-sm-6 col-xl-3">
                      <a className="tfcl-card" href="#">
                        <div className="card-body">
                          <div className="tfcl-icon-overview">
                            <img
                              alt="icon"
                              src="/assets/images/dashboard/overview1.svg"
                              width={36}
                              height={36}
                            />
                          </div>
                          <div className="content-overview">
                            <h5>Your listing</h5>
                            <div className="tfcl-dashboard-title">
                              <div className="listing-text d-flex">
                                <b>32 </b>
                                <div className="per">/50 remaining</div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </a>
                    </div>
                    <div className="col-sm-6 col-xl-3">
                      <a className="tfcl-card" href="#">
                        <div className="card-body">
                          <div className="tfcl-icon-overview">
                            <img
                              alt="icon"
                              src="/assets/images/dashboard/overview4.svg"
                              width={36}
                              height={36}
                            />
                          </div>
                          <div className="content-overview">
                            <h5>Pending</h5>
                            <div className="tfcl-dashboard-title">
                              <span>
                                <b>02</b>
                              </span>
                            </div>
                          </div>
                        </div>
                      </a>
                    </div>
                    <div className="col-sm-6 col-xl-3">
                      <a className="tfcl-card" href="#">
                        <div className="card-body">
                          <div className="tfcl-icon-overview">
                            <img
                              alt="icon"
                              src="/assets/images/dashboard/overview3.svg"
                              width={36}
                              height={36}
                            />
                          </div>
                          <div className="content-overview">
                            <h5>Favorites</h5>
                            <div className="tfcl-dashboard-title">
                              <span>
                                <b>06</b>
                              </span>
                            </div>
                          </div>
                        </div>
                      </a>
                    </div>
                    <div className="col-sm-6 col-xl-3">
                      <a className="tfcl-card" href="#">
                        <div className="card-body">
                          <div className="tfcl-icon-overview">
                            <img
                              alt="icon"
                              src="/assets/images/dashboard/overview2.svg"
                              width={36}
                              height={36}
                            />
                          </div>
                          <div className="content-overview">
                            <h5>Reviews</h5>
                            <div className="tfcl-dashboard-title">
                              <span>
                                <b>1.483</b>
                              </span>
                            </div>
                          </div>
                        </div>
                      </a>
                    </div>
                  </div>
                </div>
                <div className="tfcl-dashboard-middle mt-2">
                  <div className="row">
                    <div className="tfcl-dashboard-middle-left col-md-12">
                      <div className="tfcl-dashboard-listing">
                        <h5 className="title-dashboard-table">New listing</h5>
                        <div className="row">
                          <div className="col-xl-3 col-lg-6 mb-2">
                            <div className="group-input-icon search">
                              <input
                                type="text"
                                name="title_search"
                                id="title_search"
                                defaultValue=""
                                placeholder="Search..."
                              />
                              <span className="datepicker-icon">
                                <svg
                                  xmlns="http://www.w3.org/2000/svg"
                                  width={18}
                                  height={18}
                                  viewBox="0 0 18 18"
                                  fill="none"
                                >
                                  <path
                                    d="M15.7506 15.7506L11.8528 11.8528M11.8528 11.8528C12.9078 10.7979 13.5004 9.36711 13.5004 7.87521C13.5004 6.38331 12.9078 4.95252 11.8528 3.89759C10.7979 2.84265 9.36711 2.25 7.87521 2.25C6.38331 2.25 4.95252 2.84265 3.89759 3.89759C2.84265 4.95252 2.25 6.38331 2.25 7.87521C2.25 9.36711 2.84265 10.7979 3.89759 11.8528C4.95252 12.9078 6.38331 13.5004 7.87521 13.5004C9.36711 13.5004 10.7979 12.9078 11.8528 11.8528Z"
                                    stroke="#B6B6B6"
                                    strokeWidth="1.5"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                  />
                                </svg>
                              </span>
                            </div>
                          </div>
                          <div className="col-xl-3 col-lg-6 mb-2">
                            <div className="group-input-icon">
                              <input
                                type="text"
                                id="from-date"
                                className="datetimepicker hasDatepicker"
                                name="from_date"
                                defaultValue=""
                                placeholder="From Date"
                              />
                              <span className="datepicker-icon">
                                <svg
                                  xmlns="http://www.w3.org/2000/svg"
                                  width={19}
                                  height={18}
                                  viewBox="0 0 19 18"
                                  fill="none"
                                >
                                  <path
                                    d="M5.5625 2.25V3.9375M13.4375 2.25V3.9375M2.75 14.0625V5.625C2.75 5.17745 2.92779 4.74823 3.24426 4.43176C3.56072 4.11529 3.98995 3.9375 4.4375 3.9375H14.5625C15.0101 3.9375 15.4393 4.11529 15.7557 4.43176C16.0722 4.74823 16.25 5.17745 16.25 5.625V14.0625M2.75 14.0625C2.75 14.5101 2.92779 14.9393 3.24426 15.2557C3.56072 15.5722 3.98995 15.75 4.4375 15.75H14.5625C15.0101 15.75 15.4393 15.5722 15.7557 15.2557C16.0722 14.9393 16.25 14.5101 16.25 14.0625M2.75 14.0625V8.4375C2.75 7.98995 2.92779 7.56073 3.24426 7.24426C3.56072 6.92779 3.98995 6.75 4.4375 6.75H14.5625C15.0101 6.75 15.4393 6.92779 15.7557 7.24426C16.0722 7.56073 16.25 7.98995 16.25 8.4375V14.0625M9.5 9.5625H9.506V9.5685H9.5V9.5625ZM9.5 11.25H9.506V11.256H9.5V11.25ZM9.5 12.9375H9.506V12.9435H9.5V12.9375ZM7.8125 11.25H7.8185V11.256H7.8125V11.25ZM7.8125 12.9375H7.8185V12.9435H7.8125V12.9375ZM6.125 11.25H6.131V11.256H6.125V11.25ZM6.125 12.9375H6.131V12.9435H6.125V12.9375ZM11.1875 9.5625H11.1935V9.5685H11.1875V9.5625ZM11.1875 11.25H11.1935V11.256H11.1875V11.25ZM11.1875 12.9375H11.1935V12.9435H11.1875V12.9375ZM12.875 9.5625H12.881V9.5685H12.875V9.5625ZM12.875 11.25H12.881V11.256H12.875V11.25Z"
                                    stroke="#B6B6B6"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                  />
                                </svg>
                              </span>
                            </div>
                          </div>
                          <div className="col-xl-3 col-lg-6 mb-2">
                            <div className="group-input-icon">
                              <input
                                type="text"
                                id="to-date"
                                className="datetimepicker hasDatepicker"
                                name="to_date"
                                defaultValue=""
                                placeholder="To Date"
                              />
                              <span className="datepicker-icon">
                                <svg
                                  xmlns="http://www.w3.org/2000/svg"
                                  width={19}
                                  height={18}
                                  viewBox="0 0 19 18"
                                  fill="none"
                                >
                                  <path
                                    d="M5.5625 2.25V3.9375M13.4375 2.25V3.9375M2.75 14.0625V5.625C2.75 5.17745 2.92779 4.74823 3.24426 4.43176C3.56072 4.11529 3.98995 3.9375 4.4375 3.9375H14.5625C15.0101 3.9375 15.4393 4.11529 15.7557 4.43176C16.0722 4.74823 16.25 5.17745 16.25 5.625V14.0625M2.75 14.0625C2.75 14.5101 2.92779 14.9393 3.24426 15.2557C3.56072 15.5722 3.98995 15.75 4.4375 15.75H14.5625C15.0101 15.75 15.4393 15.5722 15.7557 15.2557C16.0722 14.9393 16.25 14.5101 16.25 14.0625M2.75 14.0625V8.4375C2.75 7.98995 2.92779 7.56073 3.24426 7.24426C3.56072 6.92779 3.98995 6.75 4.4375 6.75H14.5625C15.0101 6.75 15.4393 6.92779 15.7557 7.24426C16.0722 7.56073 16.25 7.98995 16.25 8.4375V14.0625M9.5 9.5625H9.506V9.5685H9.5V9.5625ZM9.5 11.25H9.506V11.256H9.5V11.25ZM9.5 12.9375H9.506V12.9435H9.5V12.9375ZM7.8125 11.25H7.8185V11.256H7.8125V11.25ZM7.8125 12.9375H7.8185V12.9435H7.8125V12.9375ZM6.125 11.25H6.131V11.256H6.125V11.25ZM6.125 12.9375H6.131V12.9435H6.125V12.9375ZM11.1875 9.5625H11.1935V9.5685H11.1875V9.5625ZM11.1875 11.25H11.1935V11.256H11.1875V11.25ZM11.1875 12.9375H11.1935V12.9435H11.1875V12.9375ZM12.875 9.5625H12.881V9.5685H12.875V9.5625ZM12.875 11.25H12.881V11.256H12.875V11.25Z"
                                    stroke="#B6B6B6"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                  />
                                </svg>
                              </span>
                            </div>
                          </div>
                          <div className="col-xl-3 col-lg-6 mb-2">
                            <DropdownSelect
                              addtionalParentClass="form-control"
                              defaultOption={"Select Status"}
                              options={["hidden", "sold"]}
                            />
                          </div>
                        </div>
                        <div className="tfcl-table-listing">
                          <div className="table-responsive">
                            <span className="result-text">
                              <b>16</b> results found
                            </span>
                            <table className="table">
                              <thead>
                                <tr>
                                  <th>Listing</th>
                                  <th>Status</th>
                                  <th>Posting date</th>
                                  <th>Action</th>
                                </tr>
                              </thead>
                              <tbody className="tfcl-table-content">
                                {cars.slice(2, 7).map((elm, i) => (
                                  <tr key={i}>
                                    <td className="column-listing">
                                      <div className="tfcl-listing-product">
                                        <Link
                                          to={`/listing-detail-v1/${elm.id}`}
                                        >
                                          <img
                                            alt="image"
                                            src={elm.imgSrc}
                                            width={168}
                                            height={95}
                                          />
                                        </Link>
                                        <div className="tfcl-listing-summary">
                                          <h4 className="tfcl-listing-title">
                                            <Link
                                              to={`/listing-detail-v1/${elm.id}`}
                                            >
                                              {elm.title}
                                            </Link>
                                          </h4>
                                          <div className="features-text">
                                            1st owned, automatic transmission,
                                            Apple Carplay...
                                          </div>
                                          <div className="price">
                                            <div className="inner tfcl-listing-price">
                                              ${elm.price.toLocaleString()}
                                            </div>
                                          </div>
                                        </div>
                                      </div>
                                    </td>
                                    <td className="column-status">
                                      <span
                                        className={`tfcl-listing-status status-${
                                          elm.status == "Approved"
                                            ? "publish"
                                            : elm.status == "Sold"
                                            ? "sold"
                                            : "pending"
                                        }`}
                                      >
                                        {elm.status}
                                      </span>
                                    </td>
                                    <td className="column-date">
                                      <div className="tfcl-listing-date">
                                        March 22, 2023
                                      </div>
                                    </td>
                                    <td className="column-controller">
                                      <div className="inner-controller">
                                        <span className="icon">
                                          <img
                                            alt="icon"
                                            src="/assets/images/dashboard/pen.svg"
                                            width={16}
                                            height={16}
                                          />
                                        </span>{" "}
                                        <a
                                          href="#"
                                          className="btn-action tfcl-dashboard-action-edit"
                                        >
                                          Edit
                                        </a>
                                      </div>
                                      <div className="inner-controller">
                                        <span className="icon">
                                          <img
                                            alt="icon"
                                            src="/assets/images/dashboard/hide.svg"
                                            width={16}
                                            height={16}
                                          />
                                        </span>{" "}
                                        <a
                                          href="#"
                                          className="btn-action tfcl-dashboard-action-edit"
                                        >
                                          Sold
                                        </a>
                                      </div>
                                      <div className="inner-controller">
                                        <span className="icon">
                                          <img
                                            alt="icon"
                                            src="/assets/images/dashboard/trash.svg"
                                            width={16}
                                            height={16}
                                          />
                                        </span>{" "}
                                        <a
                                          href="#"
                                          className="btn-action tfcl-dashboard-action-edit"
                                        >
                                          Delete
                                        </a>
                                      </div>
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                          <div className="themesflat-pagination clearfix mt-40">
                            <ul>
                              <Pagination2 />
                            </ul>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="tfcl-page-insight tfcl-dashboard-listing">
                  <h5 className="mb-2">Page Insights</h5>
                  <div className="row">
                    <div className="col-md-12">
                      <div className="group-insight-controller">
                        <div className="group-btn-insignt">
                          <button>Day</button>
                          <button>Week</button>
                          <button>Month</button>
                          <button>Year</button>
                        </div>
                        <div className="group-input-insight">
                          <div className="group-input-icon">
                            <input
                              type="text"
                              id="from-date"
                              className="datetimepicker hasDatepicker"
                              name="from_date"
                              defaultValue=""
                              placeholder="From Date"
                            />
                            <span className="datepicker-icon">
                              <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width={19}
                                height={18}
                                viewBox="0 0 19 18"
                                fill="none"
                              >
                                <path
                                  d="M5.5625 2.25V3.9375M13.4375 2.25V3.9375M2.75 14.0625V5.625C2.75 5.17745 2.92779 4.74823 3.24426 4.43176C3.56072 4.11529 3.98995 3.9375 4.4375 3.9375H14.5625C15.0101 3.9375 15.4393 4.11529 15.7557 4.43176C16.0722 4.74823 16.25 5.17745 16.25 5.625V14.0625M2.75 14.0625C2.75 14.5101 2.92779 14.9393 3.24426 15.2557C3.56072 15.5722 3.98995 15.75 4.4375 15.75H14.5625C15.0101 15.75 15.4393 15.5722 15.7557 15.2557C16.0722 14.9393 16.25 14.5101 16.25 14.0625M2.75 14.0625V8.4375C2.75 7.98995 2.92779 7.56073 3.24426 7.24426C3.56072 6.92779 3.98995 6.75 4.4375 6.75H14.5625C15.0101 6.75 15.4393 6.92779 15.7557 7.24426C16.0722 7.56073 16.25 7.98995 16.25 8.4375V14.0625M9.5 9.5625H9.506V9.5685H9.5V9.5625ZM9.5 11.25H9.506V11.256H9.5V11.25ZM9.5 12.9375H9.506V12.9435H9.5V12.9375ZM7.8125 11.25H7.8185V11.256H7.8125V11.25ZM7.8125 12.9375H7.8185V12.9435H7.8125V12.9375ZM6.125 11.25H6.131V11.256H6.125V11.25ZM6.125 12.9375H6.131V12.9435H6.125V12.9375ZM11.1875 9.5625H11.1935V9.5685H11.1875V9.5625ZM11.1875 11.25H11.1935V11.256H11.1875V11.25ZM11.1875 12.9375H11.1935V12.9435H11.1875V12.9375ZM12.875 9.5625H12.881V9.5685H12.875V9.5625ZM12.875 11.25H12.881V11.256H12.875V11.25Z"
                                  stroke="#B6B6B6"
                                  strokeLinecap="round"
                                  strokeLinejoin="round"
                                />
                              </svg>
                            </span>
                          </div>
                          <div className="group-input-icon">
                            <input
                              type="text"
                              id="from-date"
                              className="datetimepicker hasDatepicker"
                              name="from_date"
                              defaultValue=""
                              placeholder="To date"
                            />
                            <span className="datepicker-icon">
                              <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width={19}
                                height={18}
                                viewBox="0 0 19 18"
                                fill="none"
                              >
                                <path
                                  d="M5.5625 2.25V3.9375M13.4375 2.25V3.9375M2.75 14.0625V5.625C2.75 5.17745 2.92779 4.74823 3.24426 4.43176C3.56072 4.11529 3.98995 3.9375 4.4375 3.9375H14.5625C15.0101 3.9375 15.4393 4.11529 15.7557 4.43176C16.0722 4.74823 16.25 5.17745 16.25 5.625V14.0625M2.75 14.0625C2.75 14.5101 2.92779 14.9393 3.24426 15.2557C3.56072 15.5722 3.98995 15.75 4.4375 15.75H14.5625C15.0101 15.75 15.4393 15.5722 15.7557 15.2557C16.0722 14.9393 16.25 14.5101 16.25 14.0625M2.75 14.0625V8.4375C2.75 7.98995 2.92779 7.56073 3.24426 7.24426C3.56072 6.92779 3.98995 6.75 4.4375 6.75H14.5625C15.0101 6.75 15.4393 6.92779 15.7557 7.24426C16.0722 7.56073 16.25 7.98995 16.25 8.4375V14.0625M9.5 9.5625H9.506V9.5685H9.5V9.5625ZM9.5 11.25H9.506V11.256H9.5V11.25ZM9.5 12.9375H9.506V12.9435H9.5V12.9375ZM7.8125 11.25H7.8185V11.256H7.8125V11.25ZM7.8125 12.9375H7.8185V12.9435H7.8125V12.9375ZM6.125 11.25H6.131V11.256H6.125V11.25ZM6.125 12.9375H6.131V12.9435H6.125V12.9375ZM11.1875 9.5625H11.1935V9.5685H11.1875V9.5625ZM11.1875 11.25H11.1935V11.256H11.1875V11.25ZM11.1875 12.9375H11.1935V12.9435H11.1875V12.9375ZM12.875 9.5625H12.881V9.5685H12.875V9.5625ZM12.875 11.25H12.881V11.256H12.875V11.25Z"
                                  stroke="#B6B6B6"
                                  strokeLinecap="round"
                                  strokeLinejoin="round"
                                />
                              </svg>
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div className="map-chart">
                    <DashboardChart />
                  </div>
                </div>
                <div className="tfcl-dashboard-middle-right">
                  <div className="tfcl-card tfcl-dashboard-reviews">
                    <h5>Recent Reviews</h5>
                    <ul>
                      <li className="comment-by-user">
                        <div className="group-author">
                          <img
                            loading="lazy"
                            className="avatar"
                            width={56}
                            height={56}
                            alt="avatar"
                            src="/assets/images/dashboard/rate4.png"
                          />
                          <div className="group-name">
                            <div className="review-name">
                              <b>Bessie Cooper</b>
                              <span className="review-date">3 days ago</span>
                            </div>
                          </div>
                        </div>
                        <div className="content">
                          <p>
                            Maecenas eu lorem et urna accumsan vestibulum vel
                            vitae magna.
                          </p>
                        </div>
                        <div className="rating-wrap">
                          <div className="form-group">
                            <div className="star-rating-review">
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={1}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={2}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={3}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={4}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={5}
                              />
                            </div>
                          </div>
                        </div>
                      </li>
                      <li className="comment-by-user">
                        <div className="group-author">
                          <img
                            loading="lazy"
                            className="avatar"
                            width={56}
                            height={56}
                            alt="avatar"
                            src="/assets/images/dashboard/rate3.png"
                          />
                          <div className="group-name">
                            <div className="review-name">
                              <b>Annette Black</b>
                              <span className="review-date">3 days ago</span>
                            </div>
                          </div>
                        </div>
                        <div className="content">
                          <p>
                            Nullam rhoncus dolor arcu, et commodo tellus semper
                            vitae. Aenean finibus tristique lectus, ac lobortis
                            mauris venenatis ac.
                          </p>
                        </div>
                        <div className="rating-wrap">
                          <div className="form-group">
                            <div className="star-rating-review">
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={1}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={2}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={3}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={4}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={5}
                              />
                            </div>
                          </div>
                        </div>
                      </li>
                      <li className="comment-by-user">
                        <div className="group-author">
                          <img
                            loading="lazy"
                            className="avatar"
                            width={56}
                            height={56}
                            alt="avatar"
                            src="/assets/images/dashboard/rate2.png"
                          />
                          <div className="group-name">
                            <div className="review-name">
                              <b>Ralph Edwards</b>
                              <span className="review-date">3 days ago</span>
                            </div>
                          </div>
                        </div>
                        <div className="content">
                          <p>
                            Lorem ipsum dolor sit amet, consectetur adipiscing
                            elit. Vivamus viverra semper convallis. Integer
                            vestibulum tempus tincidunt.
                          </p>
                        </div>
                        <div className="rating-wrap">
                          <div className="form-group">
                            <div className="star-rating-review">
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={1}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={2}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={3}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={4}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={5}
                              />
                            </div>
                          </div>
                        </div>
                      </li>
                      <li className="comment-by-user">
                        <div className="group-author">
                          <img
                            loading="lazy"
                            className="avatar"
                            width={56}
                            height={56}
                            alt="avatar"
                            src="/assets/images/dashboard/rate1.png"
                          />
                          <div className="group-name">
                            <div className="review-name">
                              <b>Jerome Bell</b>
                              <span className="review-date">3 days ago</span>
                            </div>
                          </div>
                        </div>
                        <div className="content">
                          <p>
                            Fusce sit amet purus eget quam eleifend hendrerit
                            nec a erat. Sed turpis neque, iaculis blandit
                            viverra ut, dapibus eget nisi.
                          </p>
                        </div>
                        <div className="rating-wrap">
                          <div className="form-group">
                            <div className="star-rating-review">
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={1}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={2}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={3}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={4}
                              />
                              <i
                                className="star disabled-click icon-autodeal-star active"
                                data-rating={5}
                              />
                            </div>
                          </div>
                        </div>
                      </li>
                    </ul>
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
