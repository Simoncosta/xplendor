import HomePage from "@/components/homes/HomePage";
import { pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("home");

// A raiz (/) serve a landing da XPLENDOR (a antiga rota /home-main foi removida).
export default function Home() {
  return <HomePage />;
}
