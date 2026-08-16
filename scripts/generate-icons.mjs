import sharp from "sharp";

const out = "public";
const targets = [
  ["icon-192.png", 192, "any"],
  ["icon-512.png", 512, "any"],
  ["icon-maskable-512.png", 512, "maskable"],
  ["apple-touch-icon.png", 180, "any"],
];

for (const [file, size, purpose] of targets) {
  await sharp("public/icon.svg")
    .resize(size, size)
    .png()
    .toFile(`${out}/${file}`);
  console.log(`wrote ${file} (${size}x${size}, ${purpose})`);
}