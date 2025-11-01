import { carListings2 } from "@/data/cars";

import React from "react";

export default function Recommended() {
  return (
    <div className="listing-recommended mb-30">
      {carListings2.slice(0, 4).map((elm, i) => (
        <div key={i} className="item flex">
          <div className="image">
            <img
              className="lazyload"
              alt="image"
              src={elm.imgSrc}
              width={450}
              height={338}
            />
          </div>
          <div className="content">
            <h6>
              <a href="#">{elm.title}</a>
            </h6>
            <p className="fs-14 fw-7 text-color-2 font-1">
              ${elm.price.toLocaleString()}
            </p>
          </div>
        </div>
      ))}
    </div>
  );
}
