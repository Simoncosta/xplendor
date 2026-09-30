"use client";

export default function SubscribeForm() {
  return (
    <form
      className="form notify-form form-light"
      onSubmit={(e) => e.preventDefault()}
    >
      <input type="email" placeholder="Your Email" required />
      <button
        className="btn btn-form btn-absolute-right btn-muted slide-right-up anim-no-delay"
        type="submit"
      >
        <i className="ph ph-arrow-up-right" />
      </button>
    </form>
  );
}
