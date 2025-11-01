import Slider from "rc-slider";

export default function Pricing({
  priceRange,
  setPriceRange,
  MIN = 10,
  MAX = 100,
}) {
  return (
    <Slider
      range
      max={MAX}
      min={MIN}
      value={priceRange}
      onChange={setPriceRange}
    />
  );
}
