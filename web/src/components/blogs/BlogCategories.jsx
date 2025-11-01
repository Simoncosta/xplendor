const categories = [
  { title: "New car review", count: 50 },
  { title: "First Drives", count: 34 },
  { title: "Car Buying", count: 69 },
  { title: "Official", count: 25 },
  { title: "Technology", count: 12 },
  { title: "Recalls", count: 12 },
  { title: "Races and chases", count: 69 },
];

export default function BlogCategories() {
  return (
    <div className="widget widget-categories style">
      <h3 className="widget-titles title-categories">Categories</h3>
      <ul>
        {categories.map((category, index) => (
          <li key={index} className="flex-two">
            <a href="#" className="font-2 fw-7">
              {category.title}
            </a>
            <div className="number">({category.count})</div>
          </li>
        ))}
      </ul>
    </div>
  );
}
