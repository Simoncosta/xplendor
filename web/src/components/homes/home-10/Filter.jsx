import FlatFilter from "@/components/common/FlatFilter";
import React from "react";

export default function Filter() {
  return (
    <div className="flat-filter-search home10">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="flat-tabs">
              <FlatFilter styleClass="style2" justifyClass="justify-center" />
              <div className="wrap-icon flex align-center link-style-3 link-style-4 justify-center flex-wrap">
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-suv" />
                  <a href="#">Hatchback</a>
                </div>
                <div className="icon-box text-color-1 font">
                  <span className="icon-autodeal-coupe" />
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
