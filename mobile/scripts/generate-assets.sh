#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# TPIX TRADE - Placeholder Asset Generator (Shell / ImageMagick)
#
# Generates placeholder image assets for the mobile app.
# Prefers ImageMagick (convert/magick), falls back to minimal PPM-to-PNG
# conversion or plain guidance if neither is available.
#
# Usage:
#   chmod +x scripts/generate-assets.sh
#   ./scripts/generate-assets.sh
# ---------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$PROJECT_DIR/assets/images"

BG_COLOR="#0a0e1a"
TEXT_COLOR="#00e5ff"
WHITE="#ffffff"

mkdir -p "$OUTPUT_DIR"

# ---------------------------------------------------------------------------
# Detect available tools
# ---------------------------------------------------------------------------
USE_MAGICK=""
MAGICK_CMD=""

if command -v magick &>/dev/null; then
    USE_MAGICK=1
    MAGICK_CMD="magick"
elif command -v convert &>/dev/null; then
    USE_MAGICK=1
    MAGICK_CMD="convert"
fi

# ---------------------------------------------------------------------------
# Helper: generate an image with ImageMagick
# Args: width height label output_filename
# ---------------------------------------------------------------------------
generate_with_magick() {
    local width="$1"
    local height="$2"
    local label="$3"
    local output="$4"

    local font_size=$(( width / 6 ))
    if [ "$font_size" -lt 8 ]; then
        font_size=8
    fi

    # For the splash screen, use a smaller relative font
    if [ "$width" -lt "$height" ]; then
        font_size=$(( width / 8 ))
    fi

    $MAGICK_CMD \
        -size "${width}x${height}" \
        "xc:${BG_COLOR}" \
        -gravity center \
        -fill "$TEXT_COLOR" \
        -pointsize "$font_size" \
        -font "Helvetica-Bold" \
        -annotate +0+0 "$label" \
        "$output" 2>/dev/null \
    || \
    $MAGICK_CMD \
        -size "${width}x${height}" \
        "xc:${BG_COLOR}" \
        -gravity center \
        -fill "$TEXT_COLOR" \
        -pointsize "$font_size" \
        -annotate +0+0 "$label" \
        "$output"

    echo "  Created $output  (${width}x${height})"
}

# ---------------------------------------------------------------------------
# Helper: generate a minimal solid-color PPM, convert to PNG if possible
# This is the fallback when ImageMagick is not available.
# Args: width height output_filename
# ---------------------------------------------------------------------------
generate_ppm_fallback() {
    local width="$1"
    local height="$2"
    local output="$3"
    local ppm_file="${output%.png}.ppm"

    # Parse BG_COLOR (#0a0e1a) to decimal RGB
    local r=$((16#0a))
    local g=$((16#0e))
    local b=$((16#1a))

    {
        printf "P6\n%d %d\n255\n" "$width" "$height"
        # Generate one row, repeat height times
        local row=""
        row=$(python3 -c "
import sys
pixel = bytes([${r}, ${g}, ${b}])
row = pixel * ${width}
for _ in range(${height}):
    sys.stdout.buffer.write(row)
" 2>/dev/null) && printf '%s' "$row"
    } > "$ppm_file"

    # Try to convert PPM to PNG
    if command -v python3 &>/dev/null; then
        python3 -c "
import struct, zlib, sys, os

def write_png(ppm_path, png_path):
    with open(ppm_path, 'rb') as f:
        # Read PPM header
        magic = f.readline().strip()
        # Skip comments
        line = f.readline()
        while line.startswith(b'#'):
            line = f.readline()
        w, h = map(int, line.split())
        f.readline()  # maxval
        data = f.read()

    # Build PNG
    def chunk(ctype, cdata):
        c = ctype + cdata
        return struct.pack('>I', len(cdata)) + c + struct.pack('>I', zlib.crc32(c) & 0xffffffff)

    raw = b''
    stride = w * 3
    for y in range(h):
        raw += b'\x00' + data[y*stride:(y+1)*stride]

    with open(png_path, 'wb') as out:
        out.write(b'\x89PNG\r\n\x1a\n')
        out.write(chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)))
        out.write(chunk(b'IDAT', zlib.compress(raw)))
        out.write(chunk(b'IEND', b''))

    os.remove(ppm_path)

write_png('${ppm_file}', '${output}')
" 2>/dev/null && echo "  Created $output  (${width}x${height})" && return 0
    fi

    # If Python conversion failed, keep PPM
    if [ -f "$ppm_file" ]; then
        mv "$ppm_file" "$output"
        echo "  Created $output  (${width}x${height}) [PPM format - install ImageMagick for proper PNG]"
    else
        echo "  SKIP $output - could not generate without ImageMagick or Python3"
    fi
}

# ---------------------------------------------------------------------------
# Generate assets
# ---------------------------------------------------------------------------
echo ""
echo "TPIX TRADE - Generating placeholder image assets..."
echo "Output: $OUTPUT_DIR"
echo ""

if [ -n "$USE_MAGICK" ]; then
    echo "Using ImageMagick ($MAGICK_CMD)..."
    echo ""

    generate_with_magick 1024 1024 "TPIX" "$OUTPUT_DIR/icon.png"
    generate_with_magick 1024 1024 "TPIX" "$OUTPUT_DIR/adaptive-icon.png"
    generate_with_magick 1284 2778 "TPIX\nTRADE" "$OUTPUT_DIR/splash.png"
    generate_with_magick 48   48   "T"    "$OUTPUT_DIR/favicon.png"

else
    echo "ImageMagick not found. Using fallback generator..."
    echo "(Install ImageMagick for better results: sudo apt-get install imagemagick)"
    echo ""

    if command -v python3 &>/dev/null; then
        echo "Python3 detected - generating solid-color PNG placeholders..."
        echo ""

        # Use Python directly for all images (solid color, no text)
        python3 -c "
import struct, zlib, sys

def make_png(path, w, h, r, g, b):
    def chunk(ctype, cdata):
        c = ctype + cdata
        return struct.pack('>I', len(cdata)) + c + struct.pack('>I', zlib.crc32(c) & 0xffffffff)

    pixel = bytes([r, g, b])
    row = b'\x00' + pixel * w
    raw = row * h

    with open(path, 'wb') as f:
        f.write(b'\x89PNG\r\n\x1a\n')
        f.write(chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)))
        f.write(chunk(b'IDAT', zlib.compress(raw, 1)))
        f.write(chunk(b'IEND', b''))

    print(f'  Created {path}  ({w}x{h})')

make_png('${OUTPUT_DIR}/icon.png',          1024, 1024, 10, 14, 26)
make_png('${OUTPUT_DIR}/adaptive-icon.png', 1024, 1024, 10, 14, 26)
make_png('${OUTPUT_DIR}/splash.png',        1284, 2778, 10, 14, 26)
make_png('${OUTPUT_DIR}/favicon.png',       48,   48,   10, 14, 26)
"
    else
        echo "Neither ImageMagick nor Python3 found."
        echo ""
        echo "Please install one of the following to generate assets:"
        echo ""
        echo "  Option A (recommended):"
        echo "    sudo apt-get install imagemagick"
        echo "    ./scripts/generate-assets.sh"
        echo ""
        echo "  Option B:"
        echo "    sudo apt-get install python3"
        echo "    ./scripts/generate-assets.sh"
        echo ""
        echo "  Option C (Node.js with canvas):"
        echo "    npm install canvas"
        echo "    node scripts/generate-icons.js"
        echo ""
        exit 1
    fi
fi

echo ""
echo "Done! Placeholder assets generated in $OUTPUT_DIR"
echo ""
echo "Note: These are basic placeholders. Replace them with proper designs before release."
echo "For higher-quality generated icons, install the canvas npm package and run:"
echo "  node scripts/generate-icons.js"
