import RevealText from "../animation/RevealText";
import AnimatedButton from "../animation/AnimatedButton";

export default function Cta() {
  return (
    <div className="mxd-section padding-default">
      <div className="mxd-container">
        {/* Block - Demo CTA Start */}
        <div className="mxd-block">
          <div className="mxd-demo-cta">
            <div className="mxd-demo-cta__caption anim-uni-in-up">
              <RevealText as="h2" className="h2-small reveal-type">
                Show your creativity and get noticed today!
              </RevealText>
            </div>
            <div className="mxd-demo-cta__btn anim-uni-in-up">
              <AnimatedButton
                text="Buy Now"
                as={"a"}
                className="btn btn-anim btn-default btn-large btn-additional slide-right"
                href="https://themeforest.net/user/ib-themes/portfolio"
                target="_blank"
              >
                <i className="ph-bold ph-shopping-cart-simple" />
              </AnimatedButton>
            </div>
          </div>
        </div>
        {/* Block - Demo CTA End */}
      </div>
    </div>
  );
}
