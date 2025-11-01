import Footer1 from "@/components/footers/Footer1";
import Header2 from "@/components/headers/Header2";
import Compare from "@/components/otherPages/Compare";
import React from "react";
import { Link } from "react-router-dom";
import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Compare || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function ComparePage() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <section className="flat-title">
        <div className="container2">
          <div className="row">
            <div className="col-lg-12">
              <div className="title-inner style">
                <div className="title-group fs-12">
                  <Link className="home fw-6 text-color-3" to={`/`}>
                    Home
                  </Link>
                  <span>Used cars for sale</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <Compare />
      <Footer1 />
    </>
  );
}
