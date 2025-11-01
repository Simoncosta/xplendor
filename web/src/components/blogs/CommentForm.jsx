import React from "react";

export default function CommentForm() {
  return (
    <div id="comments" className="comments">
      <div className="respond-comment">
        <form
          onSubmit={(e) => e.preventDefault()}
          id="contactform"
          className="comment-form form-submit"
          acceptCharset="utf-8"
        >
          <div className="inner-1 form-wg flex">
            <fieldset className="wg-box">
              <label className="fw-6">Name</label>
              <input
                type="text"
                className="my-input"
                name="text"
                placeholder="Your name"
                required
              />
            </fieldset>
            <fieldset className="wg-box">
              <label className="fw-6">Email address</label>
              <input
                type="email"
                className="my-input"
                name="email"
                placeholder="Your email"
                required
              />
            </fieldset>
          </div>
          <label className="flex checkbox-wrap">
            <input type="checkbox" />
            <span className="btn-checkbox flex-two" />
            <span className="font-2">
              Save your name, email for the next time review
            </span>
          </label>
          <fieldset className="message-wrap">
            <label className="fw-6">Review</label>
            <textarea
              id="comment-message"
              name="message"
              rows={4}
              tabIndex={4}
              placeholder="Your Message:"
              aria-required="true"
              defaultValue={""}
            />
          </fieldset>
          <button className="sc-button" name="submit" type="submit">
            <span>Post Comment</span>
          </button>
        </form>
      </div>
    </div>
  );
}
