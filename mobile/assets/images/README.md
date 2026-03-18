# TPIX TRADE - Image Assets

Placeholder image assets for the TPIX TRADE mobile app.

## Required Images

| File | Size | Description |
|------|------|-------------|
| `icon.png` | 1024x1024 | App icon (dark bg, stylized "T" with cyan-to-purple gradient) |
| `adaptive-icon.png` | 1024x1024 | Android adaptive icon (same design, extra safe-zone padding) |
| `splash.png` | 1284x2778 | Splash screen (dark bg, centered logo, "TPIX TRADE" text) |
| `favicon.png` | 48x48 | Small web favicon |

## Design Specs

- **Background**: `#0a0e1a` (dark navy)
- **Primary gradient**: `#00e5ff` (cyan) to `#a855f7` (purple)
- **Text color**: white / cyan
- **Style**: Glass morphism dark theme

## How to Generate Placeholders

There are three methods available, listed from best quality to simplest:

### Method 1: Node.js + canvas (best quality)

Generates icons with gradients, rounded corners, and glow effects.

```bash
# Install system dependencies (Ubuntu/Debian)
sudo apt-get install build-essential libcairo2-dev libpango1.0-dev \
    libjpeg-dev libgif-dev librsvg2-dev

# Install canvas package
cd mobile
npm install canvas

# Generate assets
node scripts/generate-icons.js
```

### Method 2: ImageMagick (good quality)

Generates icons with text labels on dark backgrounds.

```bash
# Install ImageMagick
sudo apt-get install imagemagick

# Generate assets
cd mobile
chmod +x scripts/generate-assets.sh
./scripts/generate-assets.sh
```

### Method 3: Python3 fallback (basic)

Generates solid dark-color PNG files (no text or design). Useful as minimal
placeholders so the app can build without errors.

```bash
# Python3 is usually pre-installed. Just run:
cd mobile
chmod +x scripts/generate-assets.sh
./scripts/generate-assets.sh
```

## For Production

Replace these placeholders with proper designs before release. The final assets
should follow the TPIX TRADE brand guidelines:

- Dark glass morphism aesthetic
- Cyan-to-purple gradient accents
- Clean, modern typography
- The stylized "T" logo mark
