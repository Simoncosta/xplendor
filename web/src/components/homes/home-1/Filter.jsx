import FlatFilter from "@/components/common/FlatFilter";
import React from "react";

export default function Filter() {
  return (
    <div className="flat-filter-search home">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="flat-tabs">
              <FlatFilter />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
