import BackgroundParallax from "@/components/animation/BackgroundParallax";

export default function ParallaxBackround() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container">
        <div className="mxd-divider">
          <BackgroundParallax className="mxd-divider__image divider-image-2 parallax-img" />
        </div>
      </div>
    </div>
  );
}
