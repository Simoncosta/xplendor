import Accordion from "@/components/common/Accordions";
import { toggleItems } from "@/data/faqs";
import { pricingPlans } from "@/data/pricing";
import React, { useState } from "react";

export default function Pricing() {
  const [isYearly, setIsYearly] = useState(false);
  return (
    <section className="tf-section3 flat-property pb-0">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="inner-heading flex-two flex-wrap mb-40 gap-20">
              <h1 className="heading-listing">Car listing plan</h1>
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
          <div className="col-lg-12 mb-60">
            <div className="flat-tabs themesflat-tabs">
              <div className="box-tab style4 center">
                <ul className="menu-tab tab-title flex">
                  <li
                    className={`item-title ${!isYearly ? "active" : ""}`}
                    onClick={() => setIsYearly(false)}
                  >
                    <h5 className="inner">Monthly</h5>
                  </li>
                  <li
                    className={`item-title ${isYearly ? "active" : ""}`}
                    onClick={() => setIsYearly(true)}
                  >
                    <h5 className="inner">Annually -10%</h5>
                  </li>
                </ul>
              </div>
              <div className="content-tab">
                <div className="content-inner tab-content">
                  <div className="row">
                    {pricingPlans.map((plan, index) => (
                      <div className="col-lg-3 col-md-6" key={index}>
                        <div className="widget-pricing">
                          {plan.badge && (
                            <div className="badge-table">
                              <span>{plan.badge}</span>
                            </div>
                          )}
                          <div className="pricing-heading">
                            <h2 className="sub-title">{plan.title}</h2>
                            <p className="text-sub lh-16 fs-12">
                              {plan.description}
                            </p>
                          </div>
                          <div className="title-price flex-three">
                            <h2>$</h2>
                            <div className="price fw-6 font text-color-2">
                              {isYearly
                                ? Math.round((plan.price / 100) * 90 * 12)
                                : plan.price}
                            </div>
                          </div>
                          <ul className="check">
                            {plan.features.map((feature, i) => (
                              <li
                                key={i}
                                className={`flex-three ${
                                  feature.disabled
                                    ? "de-check-icon"
                                    : "check-icon"
                                }`}
                              >
                                {feature.feature || feature}
                              </li>
                            ))}
                          </ul>
                          <div className="button-pricing">
                            <a className="sc-button btn-1 w-100" href="#">
                              <span>Get started</span>
                            </a>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="col-lg-12 mb-60">
            <h2 className="mb-40">Frequently asked questions</h2>
            <div className="flat-accordion">
              <Accordion faqData={toggleItems} />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
