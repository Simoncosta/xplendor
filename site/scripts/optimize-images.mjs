/**
 * XPLENDOR: otimização das imagens do site (correr à mão, não faz parte do build):
 *   node scripts/optimize-images.mjs
 *
 * Cada imagem fica com o dobro da largura a que é mostrada no maior ecrã (nitidez em
 * ecrãs de alta resolução), sem passar a largura original. PNG passam a WebP (e AVIF
 * nas capturas grandes, servido com <picture>); os originais saem de public/. As WebP
 * do modelo maiores do que o necessário são reduzidas no próprio ficheiro.
 * Imprime as dimensões finais, para os atributos width/height do código.
 */
import sharp from "sharp";
import fs from "node:fs";
import path from "node:path";

const PUBLIC = path.join(path.dirname(new URL(import.meta.url).pathname), "..", "public");

// [ficheiro, largura final, formatos novos (vazio = reduzir a WebP no próprio ficheiro)]
const JOBS = [
  ["img/avatars/simon-costa.png", 80, ["webp"]],
  ["img/logo/xplendor-x-dark.png", 112, ["webp"]],
  ["img/logo/xplendor-x-light.png", 112, ["webp"]],
  ...["yuko", "bscaixilharia", "confidere", "domiway", "quebom", "uzierp"].map((n) => [`img/casos/${n}.png`, 1736, ["webp", "avif"]]),
  ...["dashboard", "stock", "leads", "documentos", "ficha", "pos-venda"].map((n) => [`img/plataforma/${n}.png`, 1726, ["webp", "avif"]]),
  ["img/icons/coracao.webp", 566, []],
  ["img/icons/capacete.webp", 360, []],
  ["img/icons/cubo.webp", 374, []],
  ["img/icons/aspiral.webp", 120, []],
  ...[1, 2, 3, 4, 5].map((i) => [`img/icons/h70_appr-0${i}.webp`, 140, []]),
  ["img/icons/300x300_obj-cta-01.webp", 140, []],
  ["img/illustrations/cta-img-02.webp", 280, []],
  ...[1, 2, 3, 4].map((i) => [`img/services/800x800_ser-0${i}.webp`, 540, []]),
];

const kb = (n) => `${Math.round(n / 1024)} KB`;
let before = 0;
let after = 0;

for (const [rel, width, formats] of JOBS) {
  const src = path.join(PUBLIC, rel);
  if (!fs.existsSync(src)) { console.log(`(já tratado) ${rel}`); continue; }
  const input = fs.readFileSync(src);
  const meta = await sharp(input).metadata();
  const w = Math.min(width, meta.width);
  before += input.length;

  const outputs = formats.length ? formats : ["webp-inplace"];
  for (const fmt of outputs) {
    let pipeline = sharp(input).resize({ width: w, withoutEnlargement: true });
    let dest;
    if (fmt === "avif") { pipeline = pipeline.avif({ quality: 52, effort: 6 }); dest = src.replace(/\.\w+$/, ".avif"); }
    else { pipeline = pipeline.webp({ quality: rel.includes("casos/") || rel.includes("plataforma/") ? 80 : 82, effort: 6 }); dest = fmt === "webp-inplace" ? src : src.replace(/\.\w+$/, ".webp"); }
    const buf = await pipeline.toBuffer();
    const info = await sharp(buf).metadata();
    fs.writeFileSync(dest, buf);
    if (fmt !== "avif") after += buf.length;
    console.log(`${rel} -> ${path.relative(PUBLIC, dest)}  ${info.width}x${info.height}  ${kb(input.length)} -> ${kb(buf.length)}`);
  }
  if (formats.length && !rel.endsWith(".webp")) fs.unlinkSync(src);   // o original sai de public/
}

// Logótipo quadrado para os dados estruturados (schema.org Organization.logo).
const logoSrc = path.join(PUBLIC, "img/logo/xplendor-x-dark.webp");
if (fs.existsSync(logoSrc)) {
  const mark = await sharp(logoSrc).resize({ width: 360 }).png().toBuffer();
  await sharp({ create: { width: 512, height: 512, channels: 4, background: "#ffffff" } })
    .composite([{ input: mark, gravity: "center" }]).png({ compressionLevel: 9 })
    .toFile(path.join(PUBLIC, "img/logo/xplendor-logo-512.png"));
  console.log("img/logo/xplendor-logo-512.png criado (512x512)");
}

console.log(`\nTotal (sem contar AVIF): ${kb(before)} -> ${kb(after)}`);
