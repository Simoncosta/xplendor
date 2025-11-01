import React from "react";

import { Link } from "react-router-dom";
import { dealerData } from "@/data/dealers";
import DropdownSelect from "../common/DropDownSelect";
import Pagination2 from "../common/Pagination2";
export default function Dealers() {
  return (
    <section>
      <div className="container">
        <div className="row">
          <div className="col-md-12">
            <div className="group-dealer-title">
              <h2>Find car dealerships</h2>
              <div className="group-sort-filter">
                <DropdownSelect
                  options={["Show: 10", "Show: 1", "Show: 2", "Show: 3"]}
                />
                <DropdownSelect
                  options={[
                    "Sort by (Defaut)",
                    "Sort by Date",
                    "Sort by Year",
                    "Sort by Name",
                  ]}
                />
              </div>
            </div>
            <div className="wrap-scoll-dealer">
              <div className="inner-scroll">
                {dealerData.map((dealer, i) => (
                  <div key={i} className="tf-dealer-list">
                    <div className="infor-dealder">
                      <div className="thumbnail">
                        <img
                          alt="Dealer image"
                          src={dealer.imageSrc}
                          width={162}
                          height={80}
                        />
                      </div>
                      <div className="content">
                        <h4>
                          <a href="#">{dealer.title}</a>
                        </h4>
                        <div className="rating">
                          {dealer.reviews} Reviews
                          <div className="icon-star text-color-3 fs-12">
                            {[...Array(5)].map((_, index) => (
                              <i
                                key={index}
                                className={`icon-autodeal-star ${
                                  index < dealer.rating ? "active" : ""
                                }`}
                              />
                            ))}
                          </div>
                          <span>
                            <b>{dealer.rating}</b>/5
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="dealer-phone">
                      <h4>{dealer.phone}</h4>
                      <a href="#">
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width={16}
                          height={17}
                          viewBox="0 0 16 17"
                          fill="none"
                        >
                          <path
                            d="M1.5 5C1.5 10.5227 5.97733 15 11.5 15H13C13.3978 15 13.7794 14.842 14.0607 14.5607C14.342 14.2794 14.5 13.8978 14.5 13.5V12.5853C14.5 12.2413 14.266 11.9413 13.932 11.858L10.9833 11.1207C10.69 11.0473 10.382 11.1573 10.2013 11.3987L9.55467 12.2607C9.36667 12.5113 9.042 12.622 8.748 12.514C7.65659 12.1128 6.66544 11.4791 5.84319 10.6568C5.02094 9.83456 4.38725 8.84341 3.986 7.752C3.878 7.458 3.98867 7.13333 4.23933 6.94533L5.10133 6.29867C5.34333 6.118 5.45267 5.80933 5.37933 5.51667L4.642 2.568C4.60143 2.4058 4.50781 2.2618 4.37604 2.15889C4.24426 2.05598 4.08187 2.00006 3.91467 2H3C2.60218 2 2.22064 2.15804 1.93934 2.43934C1.65804 2.72064 1.5 3.10218 1.5 3.5V5Z"
                            stroke="#FF7101"
                            strokeWidth="1.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          />
                        </svg>
                        Show number
                      </a>
                    </div>
                    <div className="dealer-address">
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width={16}
                        height={16}
                        viewBox="0 0 16 16"
                        fill="none"
                      >
                        <path
                          d="M10 7C10 7.53043 9.78929 8.03914 9.41421 8.41421C9.03914 8.78929 8.53043 9 8 9C7.46957 9 6.96086 8.78929 6.58579 8.41421C6.21071 8.03914 6 7.53043 6 7C6 6.46957 6.21071 5.96086 6.58579 5.58579C6.96086 5.21071 7.46957 5 8 5C8.53043 5 9.03914 5.21071 9.41421 5.58579C9.78929 5.96086 10 6.46957 10 7Z"
                          stroke="#B6B6B6"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                        <path
                          d="M13 7C13 11.7613 8 14.5 8 14.5C8 14.5 3 11.7613 3 7C3 5.67392 3.52678 4.40215 4.46447 3.46447C5.40215 2.52678 6.67392 2 8 2C9.32608 2 10.5979 2.52678 11.5355 3.46447C12.4732 4.40215 13 5.67392 13 7Z"
                          stroke="#B6B6B6"
                          strokeWidth="1.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                      {dealer.address}
                    </div>
                    <div className="dealder-button">
                      <Link to={`/dealer-detail/${dealer.id}`}>
                        Dealer detail <i className="icon-autodeal-next" />
                      </Link>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <div className="themesflat-pagination clearfix style-dealer mt-40">
              <ul>
                <Pagination2 />
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
