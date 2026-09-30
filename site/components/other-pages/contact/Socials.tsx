import socials from "@/data/socials.json";
export default function Socials() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container">
        {/* Block - Socials List Start */}
        <div className="mxd-block">
          <div className="mxd-links-lines">
            {/* item */}
            {socials.map((social, i) => (
              <div key={i} className="mxd-links-lines__item">
                <div className="mxd-links-lines__divider anim-uni-in-up" />
                <a
                  className="mxd-links-lines__link anim-uni-in-up"
                  href={social.url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <h6 className="mxd-links-lines__title">{social.title}</h6>
                  <div className="mxd-links-lines__icon">
                    <i className="ph ph-arrow-up-right" />
                  </div>
                </a>
                <div className="mxd-links-lines__divider anim-uni-in-up" />
              </div>
            ))}
          </div>
        </div>
        {/* Block - Socials List Start */}
      </div>
    </div>
  );
}
