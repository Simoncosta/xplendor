import React from "react";
import { Link } from "react-router-dom";

import { compareItems4 } from "@/data/carCompare";
export default function CarCompare() {
  return (
    <section className="tf-section3">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section flex align-center justify-space flex-wrap gap-20">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Compare to buy the right car
              </h2>
              <Link
                to={`/compare`}
                className="tf-btn-arrow wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                View all
                <i className="icon-autodeal-btn-right" />
              </Link>
            </div>
          </div>
        </div>
        <div className="row">
          {compareItems4.map((item, index) => (
            <div key={index} className="col-md-4">
              <div className="compare-item">
                <div className="image-compare relative flex">
                  <div className="image">
                    <img
                      className="lazyload"
                      data-src={item.imgSrc1}
                      alt="images"
                      src={item.imgSrc1}
                      width={308}
                      height={225}
                    />
                  </div>
                  <div className="image">
                    <img
                      className="lazyload"
                      data-src={item.imgSrc2}
                      alt="images"
                      src={item.imgSrc2}
                      width={308}
                      height={225}
                    />
                  </div>
                  <div className="vs fs-16 fw-5 text-color-2">VS</div>
                </div>
                <div className="content-compare">
                  <div className="compare-list-inner">
                    <div className="compare-list flex-two flex-wrap">
                      <p className="font">{item.brand1}</p>
                      <p className="font">{item.brand2}</p>
                    </div>
                    <div className="compare-list flex-two flex-wrap">
                      <p className="font fs-18 fw-5 lh-20 text-color-2">
                        {item.model1}
                      </p>
                      <p className="font fs-18 fw-5 lh-20 text-color-2">
                        {item.model2}
                      </p>
                    </div>
                    <div className="compare-list flex-two flex-wrap">
                      <p className="font fs-16 fw-5 lh-20 text-color-2">
                        {item.price}
                      </p>
                      <p className="font fs-16 fw-5 lh-20 text-color-2">
                        {item.price}
                      </p>
                    </div>
                  </div>
                  <div className="btn-wrap">
                    <Link to={`/compare`} className="sc-button btn-1 w-100">
                      <span>{item.linkText}</span>
                    </Link>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
