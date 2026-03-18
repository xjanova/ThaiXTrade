/**
 * TPIX TRADE - Placeholder Icon Generator
 *
 * Generates placeholder image assets for the mobile app using the `canvas` package.
 *
 * Usage:
 *   npm install canvas
 *   node scripts/generate-icons.js
 *
 * Generated files:
 *   assets/images/icon.png          (1024x1024)  App icon
 *   assets/images/adaptive-icon.png (1024x1024)  Android adaptive icon
 *   assets/images/splash.png        (1284x2778)  Splash screen
 *   assets/images/favicon.png       (48x48)      Web favicon
 */

const fs = require('fs');
const path = require('path');

let createCanvas;
try {
    ({ createCanvas } = require('canvas'));
} catch {
    console.error(
        'Error: The "canvas" package is not installed.\n' +
        'Install it with:\n\n' +
        '  npm install canvas\n\n' +
        'On Linux you may also need system dependencies:\n' +
        '  sudo apt-get install build-essential libcairo2-dev libpango1.0-dev libjpeg-dev libgif-dev librsvg2-dev\n\n' +
        'Alternatively, run scripts/generate-assets.sh which uses ImageMagick.'
    );
    process.exit(1);
}

const OUTPUT_DIR = path.resolve(__dirname, '..', 'assets', 'images');

const COLORS = {
    bgDark: '#0a0e1a',
    cyan: '#00e5ff',
    purple: '#a855f7',
    white: '#ffffff',
    subtleWhite: 'rgba(255, 255, 255, 0.5)',
};

/**
 * Create a linear gradient from cyan to purple (vertical).
 */
function createGradient(ctx, x0, y0, x1, y1) {
    const grad = ctx.createLinearGradient(x0, y0, x1, y1);
    grad.addColorStop(0, COLORS.cyan);
    grad.addColorStop(1, COLORS.purple);
    return grad;
}

/**
 * Draw the stylized "T" logo on the given context.
 * The logo is centered in the canvas and scaled to `size`.
 */
function drawLogo(ctx, cx, cy, size) {
    const grad = createGradient(ctx, cx - size / 2, cy - size / 2, cx + size / 2, cy + size / 2);

    ctx.save();
    ctx.translate(cx, cy);

    // Horizontal bar of the T
    const barWidth = size * 0.8;
    const barHeight = size * 0.14;
    const barRadius = barHeight / 2;

    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.roundRect(-barWidth / 2, -size * 0.4, barWidth, barHeight, barRadius);
    ctx.fill();

    // Vertical stem of the T
    const stemWidth = size * 0.18;
    const stemHeight = size * 0.6;
    const stemRadius = stemWidth / 2;

    ctx.beginPath();
    ctx.roundRect(-stemWidth / 2, -size * 0.4 + barHeight * 0.6, stemWidth, stemHeight, stemRadius);
    ctx.fill();

    // Small decorative diamond at the bottom of the stem
    const diamondSize = size * 0.06;
    const diamondY = -size * 0.4 + barHeight * 0.6 + stemHeight + diamondSize * 1.5;
    ctx.beginPath();
    ctx.moveTo(0, diamondY - diamondSize);
    ctx.lineTo(diamondSize, diamondY);
    ctx.lineTo(0, diamondY + diamondSize);
    ctx.lineTo(-diamondSize, diamondY);
    ctx.closePath();
    ctx.fill();

    ctx.restore();
}

/**
 * Draw a subtle radial glow behind the logo.
 */
function drawGlow(ctx, cx, cy, radius) {
    const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius);
    glow.addColorStop(0, 'rgba(0, 229, 255, 0.15)');
    glow.addColorStop(0.5, 'rgba(168, 85, 247, 0.05)');
    glow.addColorStop(1, 'rgba(0, 0, 0, 0)');
    ctx.fillStyle = glow;
    ctx.fillRect(cx - radius, cy - radius, radius * 2, radius * 2);
}

// ---------------------------------------------------------------------------
// Generate icon.png (1024x1024)
// ---------------------------------------------------------------------------
function generateIcon() {
    const size = 1024;
    const canvas = createCanvas(size, size);
    const ctx = canvas.getContext('2d');

    // Background
    ctx.fillStyle = COLORS.bgDark;
    ctx.fillRect(0, 0, size, size);

    // Rounded corners mask
    const radius = size * 0.2;
    ctx.save();
    ctx.beginPath();
    ctx.roundRect(0, 0, size, size, radius);
    ctx.clip();
    ctx.fillStyle = COLORS.bgDark;
    ctx.fillRect(0, 0, size, size);

    // Glow
    drawGlow(ctx, size / 2, size / 2, size * 0.5);

    // Logo
    drawLogo(ctx, size / 2, size / 2, size * 0.55);

    ctx.restore();

    const outPath = path.join(OUTPUT_DIR, 'icon.png');
    fs.writeFileSync(outPath, canvas.toBuffer('image/png'));
    console.log(`  Created ${outPath}  (${size}x${size})`);
}

// ---------------------------------------------------------------------------
// Generate adaptive-icon.png (1024x1024) - no rounded corners, extra padding
// ---------------------------------------------------------------------------
function generateAdaptiveIcon() {
    const size = 1024;
    const canvas = createCanvas(size, size);
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = COLORS.bgDark;
    ctx.fillRect(0, 0, size, size);

    drawGlow(ctx, size / 2, size / 2, size * 0.45);

    // Slightly smaller logo to account for adaptive icon safe zone
    drawLogo(ctx, size / 2, size / 2, size * 0.4);

    const outPath = path.join(OUTPUT_DIR, 'adaptive-icon.png');
    fs.writeFileSync(outPath, canvas.toBuffer('image/png'));
    console.log(`  Created ${outPath}  (${size}x${size})`);
}

// ---------------------------------------------------------------------------
// Generate splash.png (1284x2778)
// ---------------------------------------------------------------------------
function generateSplash() {
    const width = 1284;
    const height = 2778;
    const canvas = createCanvas(width, height);
    const ctx = canvas.getContext('2d');

    // Background
    ctx.fillStyle = COLORS.bgDark;
    ctx.fillRect(0, 0, width, height);

    // Large subtle glow
    drawGlow(ctx, width / 2, height * 0.4, width * 0.8);

    // Logo centered vertically, slightly above middle
    const logoSize = width * 0.35;
    drawLogo(ctx, width / 2, height * 0.38, logoSize);

    // "TPIX TRADE" text below the logo
    const fontSize = width * 0.08;
    ctx.font = `bold ${fontSize}px "Helvetica Neue", Helvetica, Arial, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';

    const textY = height * 0.38 + logoSize * 0.55;

    // Text gradient
    const textGrad = createGradient(ctx, width * 0.3, textY, width * 0.7, textY + fontSize);
    ctx.fillStyle = textGrad;
    ctx.fillText('TPIX TRADE', width / 2, textY);

    // Subtitle
    const subFontSize = width * 0.03;
    ctx.font = `${subFontSize}px "Helvetica Neue", Helvetica, Arial, sans-serif`;
    ctx.fillStyle = COLORS.subtleWhite;
    ctx.fillText('Decentralized Exchange', width / 2, textY + fontSize * 1.4);

    const outPath = path.join(OUTPUT_DIR, 'splash.png');
    fs.writeFileSync(outPath, canvas.toBuffer('image/png'));
    console.log(`  Created ${outPath}  (${width}x${height})`);
}

// ---------------------------------------------------------------------------
// Generate favicon.png (48x48)
// ---------------------------------------------------------------------------
function generateFavicon() {
    const size = 48;
    const canvas = createCanvas(size, size);
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = COLORS.bgDark;
    ctx.fillRect(0, 0, size, size);

    // Simple T at small size
    const grad = createGradient(ctx, 0, 0, size, size);
    ctx.fillStyle = grad;

    // Horizontal bar
    ctx.fillRect(size * 0.15, size * 0.18, size * 0.7, size * 0.15);

    // Vertical stem
    ctx.fillRect(size * 0.38, size * 0.18, size * 0.24, size * 0.65);

    const outPath = path.join(OUTPUT_DIR, 'favicon.png');
    fs.writeFileSync(outPath, canvas.toBuffer('image/png'));
    console.log(`  Created ${outPath}  (${size}x${size})`);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
console.log('TPIX TRADE - Generating placeholder image assets...\n');

if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

generateIcon();
generateAdaptiveIcon();
generateSplash();
generateFavicon();

console.log('\nDone! All placeholder assets have been generated.');
