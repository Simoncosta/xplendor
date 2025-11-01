import { feeItems, supportItems, toggleItems } from "@/data/faqs";
import React from "react";
import Accordion from "../common/Accordions";

export default function Faqs() {
  return (
    <section className="tf-section3 flat-property">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="inner-heading flex-two flex-wrap gap-20">
              <h1 className="heading-listing">Frequently asked questions</h1>
              <div className="social-listing flex-six flex-wrap">
                <p>Share this page:</p>
                <div className="icon-social style1">
                  <a href="#">
                    <i className="icon-autodeal-facebook" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-linkedin" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-twitter" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-instagram" />
                  </a>
                </div>
              </div>
            </div>
          </div>
          <div className="col-lg-12 mb-50">
            <h2 className="mb-40">Overview</h2>
            <div className="flat-accordion">
              <Accordion faqData={toggleItems} />
            </div>
          </div>
          <div className="col-lg-12 mb-50">
            <h2 className="mb-40">Costs and Payments</h2>
            <div className="flat-accordion">
              <Accordion faqData={feeItems} />
            </div>
          </div>
          <div className="col-lg-12">
            <h2 className="mb-40">Safety and Security</h2>
            <div className="flat-accordion">
              <Accordion faqData={supportItems} />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
