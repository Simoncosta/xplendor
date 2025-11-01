import FlatFilter2 from "@/components/common/FlatFilter2";
import React from "react";

export default function Hero() {
  return (
    <div className="mainslider slider home9">
      <div className="container relative">
        <div className="row">
          <div className="col-lg-12">
            <div className="content po-content-two">
              <div className="heading center">
                <div
                  className="sub-title2 fs-20 fw-3 lh-25 text-color-1 wow fadeInUp"
                  data-wow-delay="0ms"
                  data-wow-duration="1200ms"
                >
                  Over 95,000 classified ads listing
                </div>
                <h1
                  className="wow fadeInUp js-letters text-color-1"
                  data-wow-delay="200ms"
                  data-wow-duration="1200ms"
                >
                  Find what are you looking for
                </h1>
              </div>
              {/* filter */}
              <div className="flat-filter-search home9">
                <div className="flat-tabs">
                  <FlatFilter2
                    styleClass="style2"
                    justifyClass="justify-center"
                    tabStyle="style2"
                  />
                </div>
              </div>
              <div className="wrap-icon flex align-center link-style-3 justify-center">
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-suv" />
                  <a href="#">SUV</a>
                </div>
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-coupe" />
                  <a href="#">Coupe</a>
                </div>
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-hatchback" />
                  <a href="#">Hatchback</a>
                </div>
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-hybrid" />
                  <a href="#">Hybrid</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
