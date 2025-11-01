import FlatFilter2 from "@/components/common/FlatFilter2";
import React from "react";

export default function Filtering() {
  return (
    <div className="flat-filter-search home3">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="flat-tabs">
              <FlatFilter2
                styleClass="style2"
                justifyClass=""
                tabStyle="style2"
              />
              <div className="wrap-icon flex align-center link-style-3 flex-wrap">
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
