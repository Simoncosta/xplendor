import React from "react";
import { Link } from "react-router-dom";

import { cars } from "@/data/cars";
import DropdownSelect from "../common/DropDownSelect";

import Pagination2 from "../common/Pagination2";
export default function MyFavourite() {
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title mb-3">My favorite</h1>
                <div className="tfcl-favorite-listing">
                  <div className="controller-sorting mb-3">
                    <div className="count-list">
                      <span>26</span> Car listing
                    </div>
                    <div className="sorting-input">
                      <div className="label">Sort By</div>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        options={["Newest", "New", "Old"]}
                      />
                    </div>
                  </div>
                  <div className="wrap-favorite-listing">
                    {cars.map((car, i) => (
                      <div key={i} className="box-car-list hv-one">
                        <div className="image-group relative">
                          <div className="img-style">
                            <img
                              className="lazyload"
                              alt="image"
                              src={car.imgSrc}
                              width={450}
                              height={338}
                            />
                          </div>
                        </div>
                        <div className="content">
                          <div className="text-address">
                            <p className="text-color-3 font">{car.type}</p>
                          </div>
                          <h5 className="link-style-1">
                            <Link to={`/listing-detail-v1/${car.id}`}>
                              {car.title}
                            </Link>
                          </h5>
                          <div className="icon-box flex flex-wrap">
                            <div className="icons flex-three">
                              <i className="icon-autodeal-km1" />
                              <span>{car.km.toLocaleString()} kms</span>
                            </div>
                            <div className="icons flex-three">
                              <i className="icon-autodeal-diesel" />
                              <span>{car.fuelType}</span>
                            </div>
                            <div className="icons flex-three">
                              <i className="icon-autodeal-automatic" />
                              <span>{car.transmission}</span>
                            </div>
                          </div>
                          <div className="money fs-20 fw-5 lh-25 text-color-3">
                            ${car.price.toLocaleString()}
                          </div>
                          <div className="days-box flex justify-space align-center">
                            <div className="img-author">
                              <img
                                className="lazyload"
                                alt="image"
                                src={car.authorImage}
                                width={120}
                                height={120}
                              />
                              <span className="font text-color-2 fw-5">
                                {car.authorName}
                              </span>
                            </div>
                            <Link
                              to={`/listing-detail-v1/${car.id}`}
                              className="view-car"
                            >
                              View car
                            </Link>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                  <div className="themesflat-pagination clearfix mt-40">
                    <ul>
                      <Pagination2 />
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
