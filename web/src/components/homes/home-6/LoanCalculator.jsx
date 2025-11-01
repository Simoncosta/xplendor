import DropdownSelect from "@/components/common/DropDownSelect";
import React from "react";

export default function LoanCalculator() {
  return (
    <section className="loan-calculator inner-4">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="loan-calculator-form w-560">
              <div className="box-title">
                <h2 className="title-ct">Auto Loan Calculator</h2>
                <p>Use our calculator to estimate your monthly car payments.</p>
              </div>
              <div id="comments" className="comments">
                <div className="respond-comment">
                  <form
                    onSubmit={(e) => e.preventDefault()}
                    id="loan-calculator"
                    className="comment-form form-submit"
                    acceptCharset="utf-8"
                  >
                    <fieldset className="name-wrap">
                      <label className="font-1 fs-14 fw-5">Total Price</label>
                      <input
                        type="number"
                        className="tb-my-input"
                        name="name"
                        placeholder="$"
                        required
                      />
                    </fieldset>
                    <div className="grid-sw-2">
                      <fieldset className="email-wrap style-text">
                        <label className="font-1 fs-14 fw-5">
                          Down payment
                        </label>
                        <input
                          type="number"
                          className="tb-my-input"
                          name="number"
                          placeholder="0%"
                          required
                        />
                      </fieldset>
                      <fieldset className="phone-wrap style-text">
                        <label className="font-1 fs-14 fw-5">Terms</label>
                        <DropdownSelect
                          addtionalParentClass="relative"
                          options={[
                            "Monthly",
                            "3 Monthly",
                            "6 Monthly",
                            "12 Monthly",
                          ]}
                        />
                      </fieldset>
                    </div>
                    <div className="grid-sw-2">
                      <fieldset className="email-wrap style-text">
                        <label className="font-1 fs-14 fw-5">
                          Interest rate
                        </label>
                        <input
                          type="number"
                          className="tb-my-input"
                          name="number"
                          placeholder="0%"
                          required
                        />
                      </fieldset>
                      <fieldset className="phone-wrap style-text">
                        <label className="font-1 fs-14 fw-5">Terms</label>
                        <DropdownSelect
                          addtionalParentClass="relative"
                          options={[
                            "1 months",
                            "1 months",
                            "3 months",
                            "6 months",
                            "12 months",
                          ]}
                        />
                      </fieldset>
                    </div>
                    <div className="list-total">
                      <ul>
                        <li className="flex-three">
                          <div className="title-total fs-16 fw-5 lh-20 text-color-2 font">
                            Down payment amount
                          </div>
                          <div className="fs-16 fw-5 lh-20 text-color-2 font">
                            $0
                          </div>
                        </li>
                        <li className="flex-three">
                          <div className="title-total fs-16 fw-5 lh-20 text-color-2 font">
                            Amount financed
                          </div>
                          <div className="fs-16 fw-5 lh-20 text-color-2 font">
                            $600.00
                          </div>
                        </li>
                        <li className="flex-three">
                          <div className="title-total fs-16 fw-5 lh-20 text-color-3 font">
                            Monthly payment
                          </div>
                          <div className="fs-16 fw-5 lh-20 text-color-3 font">
                            $60.00
                          </div>
                        </li>
                      </ul>
                    </div>
                    <div className="button-boxs">
                      <button className="sc-button" name="submit" type="submit">
                        <span>Apply for a loan</span>
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
