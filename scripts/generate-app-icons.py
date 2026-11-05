# /// script
# requires-python = ">=3.11"
# dependencies = ["cairosvg==2.9.1", "pillow==12.3.0"]
# ///
"""Generate ICO and full-bleed Apple icons from public/favicon.svg with uv run."""

import argparse
from io import BytesIO
from pathlib import Path
from xml.etree import ElementTree

import cairosvg
from PIL import Image, ImageDraw, ImageFont

PUBLIC = Path(__file__).resolve().parents[1] / "public"
SVG_NAMESPACE = "http://www.w3.org/2000/svg"


def render(svg: bytes, size: int) -> Image.Image:
    png = cairosvg.svg2png(bytestring=svg, output_width=size, output_height=size)
    with Image.open(BytesIO(png)) as image:
        return image.convert("RGBA")


def full_bleed(svg: bytes) -> bytes:
    ElementTree.register_namespace("", SVG_NAMESPACE)
    root = ElementTree.fromstring(svg)
    if root.get("viewBox") != "0 0 120 120":
        raise ValueError("Expected the brand favicon's 120-square viewBox")
    tile = root.find(f"{{{SVG_NAMESPACE}}}rect")
    if tile is None or tile.get("width") != "120" or tile.get("rx") is None:
        raise ValueError("Expected the brand favicon's rounded tile as the first rect")
    # Apple applies its own corner mask; a rounded tile would leave transparent corners.
    del tile.attrib["rx"]
    return ElementTree.tostring(root, encoding="utf-8")


def contact_sheet(images: list[tuple[str, Image.Image]], destination: Path) -> None:
    sheet = Image.new("RGB", (1440, 360), "#e8ebed")
    draw = ImageDraw.Draw(sheet)
    font = ImageFont.load_default(size=17)
    for index, (label, image) in enumerate(images):
        left = index * 240
        draw.text((left + 12, 12), label, fill="#101418", font=font)
        for y in range(40, 232, 12):
            for x in range(left + 24, left + 216, 12):
                shade = "#f5f6f7" if ((x - left) // 12 + y // 12) % 2 else "#dfe3e6"
                draw.rectangle((x, y, x + 11, y + 11), fill=shade)
        zoom = image if image.width == 180 else image.resize((192, 192), Image.Resampling.NEAREST)
        sheet.paste(zoom, (left + 24 + (192 - zoom.width) // 2, 40 + (192 - zoom.height) // 2), zoom)
        native = image.convert("RGBA")
        if image.width < 180:
            sheet.paste(native, (left + (240 - image.width) // 2, 255), native)
            draw.text((left + 12, 326), "Native size / zoom above", fill="#3f4952", font=font)
        else:
            draw.text((left + 12, 255), "180 x 180 RGB; full bleed", fill="#3f4952", font=font)
    sheet.save(destination)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--evidence-dir", type=Path)
    args = parser.parse_args()
    svg = (PUBLIC / "favicon.svg").read_bytes()
    frames = {size: render(svg, size) for size in (32, 48)}
    frames[48].save(PUBLIC / "favicon.ico", format="ICO", sizes=[(32, 32), (48, 48)], append_images=[frames[32]])
    with Image.open(PUBLIC / "favicon.ico") as icon:
        if icon.ico.sizes() != {(32, 32), (48, 48)}:
            raise ValueError("ICO must contain only 32 and 48 px frames")
        decoded = {size: icon.ico.getimage((size, size)).convert("RGBA") for size in (32, 48)}
    for size in (32, 48):
        if decoded[size].tobytes() != frames[size].tobytes():
            raise ValueError(f"ICO frame {size} does not match its SVG render")

    square_svg = full_bleed(svg)
    apple = render(square_svg, 180)
    if apple.getchannel("A").getextrema() != (255, 255):
        raise ValueError("Full-bleed Apple icon must be opaque")
    apple = apple.convert("RGB")
    apple.save(PUBLIC / "apple-touch-icon.png", optimize=False, compress_level=9)
    print("ICO frames: 32x32, 48x48; no 16 px frame; exact SVG raster pixels")
    print(f"Apple icon: {apple.width}x{apple.height} {apple.mode}; all alpha values 255 before RGB conversion")
    print(f"Apple corners: {[apple.getpixel(point) for point in [(0, 0), (179, 0), (0, 179), (179, 179)]]}")

    if args.evidence_dir is not None:
        args.evidence_dir.mkdir(parents=True, exist_ok=True)
        proof = []
        for size in (16, 32, 48):
            image = render(svg, size)
            image.save(args.evidence_dir / f"svg-{size}.png")
            proof.append((f"SVG {size}px", image))
        for size in (32, 48):
            decoded[size].save(args.evidence_dir / f"ico-{size}.png")
            proof.append((f"ICO {size}px", decoded[size]))
        (args.evidence_dir / "full-bleed.svg").write_bytes(square_svg)
        apple.save(args.evidence_dir / "apple-180.png")
        proof.append(("Apple 180px", apple.convert("RGBA")))
        contact_sheet(proof, args.evidence_dir / "contact-sheet.png")
        print(f"Contact sheet: {args.evidence_dir / 'contact-sheet.png'}")


if __name__ == "__main__":
    main()
