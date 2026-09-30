import BackgroundParallax from "../animation/BackgroundParallax";

export default function ParallaxDivider2() {
  return (
    <div className="mxd-section">
      <div className="mxd-container">
        <div className="mxd-divider loading__fade">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image prj-details-img-01 parallax-img"
          />
        </div>
      </div>
    </div>
  );
}
