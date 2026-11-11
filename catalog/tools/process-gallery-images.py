"""Create transparent gallery PNGs without changing source/catalog product data.

Usage: python catalog/tools/process-gallery-images.py --manifest ../gallery-originals/manifest.json
Original source URLs remain keys in gallery-index.js so admin edits still work.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path

os.environ.setdefault('OMP_NUM_THREADS', '2')
import numpy as np
from PIL import Image, ImageDraw, ImageFont
from rembg import new_session, remove
from scipy.ndimage import binary_fill_holes, binary_propagation, gaussian_filter, label
from scipy.spatial import ConvexHull


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--manifest', required=True, type=Path)
    parser.add_argument('--only', nargs='*')
    args = parser.parse_args()
    catalog = Path(__file__).resolve().parents[1]
    project = catalog.parent
    output = project / 'assets/products/gallery'
    output.mkdir(parents=True, exist_ok=True)
    rows = json.loads(args.manifest.read_text(encoding='utf-8'))
    mask_file = Path(__file__).with_name('reviewed-gallery-masks.json')
    reviewed_masks = json.loads(mask_file.read_text(encoding='utf-8')) if mask_file.exists() else {}
    role_file = Path(__file__).with_name('gallery-image-roles.json')
    image_roles = json.loads(role_file.read_text(encoding='utf-8')) if role_file.exists() else {}
    session = None
    mapping = {}
    report = []
    for row in rows:
        key = f"{row['id']}-{row['index'] + 1}"
        selected = not args.only or key in args.only
        target = output / f'{key}.png'
        if (not target.exists() or (args.only and key in args.only)) and key not in reviewed_masks:
            source = Image.open(row['path']).convert('RGBA')
            if row.get('transparent'):
                result = source
            else:
                if session is None:
                    session = new_session('isnet-general-use', providers=['CPUExecutionProvider'])
                result = remove(source, session=session).convert('RGBA')
            alpha = result.getchannel('A')
            # Ignore near-zero mask dust when computing the crop; retain soft edges.
            box = alpha.point(lambda v: 255 if v >= 24 else 0).getbbox()
            if not box:
                raise ValueError(f'Empty mask for {key}')
            result = result.crop(box)
            padding = max(8, round(max(result.size) * .035))
            canvas = Image.new('RGBA', (result.width + padding * 2, result.height + padding * 2))
            canvas.alpha_composite(result, (padding, padding))
            canvas.save(target, optimize=True)
        result = Image.open(target).convert('RGBA') if target.exists() else Image.open(row['path']).convert('RGBA')
        outline_repairs = {'1002-1', '1002-2', '1002-3', '1004-1', '1011-1', '1012-1', '1012-2', '1-1', '1-3'}
        if selected and key in reviewed_masks:
            # Hand-reviewed source-space contours replace the generic mask only.
            # Supersampling smooths alpha without changing source RGB or branding.
            result = Image.open(row['path']).convert('RGBA')
            spec = reviewed_masks[key]
            if list(result.size) != spec['size']:
                raise ValueError(f'Source size changed for reviewed mask {key}')
            scale = 4
            mask_image = Image.new('L', (result.width * scale, result.height * scale))
            painter = ImageDraw.Draw(mask_image)
            for polygon in spec.get('polygons', []):
                painter.polygon([(x * scale, y * scale) for x, y in polygon], fill=255)
            for x0, y0, x1, y1, radius in spec.get('rounded_rects', []):
                painter.rounded_rectangle((x0 * scale, y0 * scale, x1 * scale, y1 * scale), radius=radius * scale, fill=255)
            result.putalpha(mask_image.resize(result.size, Image.Resampling.LANCZOS))
            result = result.crop(result.getchannel('A').getbbox())
            padding = max(8, round(max(result.size) * .035))
            canvas = Image.new('RGBA', (result.width + padding * 2, result.height + padding * 2))
            canvas.alpha_composite(result, (padding, padding))
            result = canvas
        elif selected and key in outline_repairs:
            # Preserve bright white casing using its visible outline where the
            # general segmentation model confuses housing and white backdrop.
            result = Image.open(row['path']).convert('RGBA')
            rgb = np.asarray(result)[:, :, :3].astype(np.int16)
            corners = [rgb[0, 0], rgb[0, -1], rgb[-1, 0], rgb[-1, -1]]
            background_color = max(corners, key=lambda color: color.sum())
            candidate = np.abs(rgb - background_color).sum(axis=2) < 70
            seeds = np.zeros(candidate.shape, dtype=bool)
            for y, x in [(0, 0), (0, -1), (-1, 0), (-1, -1)]:
                if np.abs(rgb[y, x] - background_color).sum() < 30:
                    seeds[y, x] = True
            background = binary_propagation(seeds, mask=candidate)
            mask = binary_fill_holes(~background)
            if key != '1002-3':
                components, count = label(mask)
                hull_mask = Image.new('L', result.size, 0)
                painter = ImageDraw.Draw(hull_mask)
                for component in range(1, count + 1):
                    ys, xs = np.where(components == component)
                    if len(xs) < 30:
                        continue
                    points = np.column_stack((xs, ys))
                    try:
                        hull = ConvexHull(points)
                        painter.polygon([tuple(point) for point in points[hull.vertices]], fill=255)
                    except Exception:
                        continue
                mask = np.asarray(hull_mask) > 0
            if key == '1011-1':
                if session is None:
                    session = new_session('isnet-general-use', providers=['CPUExecutionProvider'])
                model = remove(result, session=session).convert('RGBA')
                mask |= np.asarray(model.getchannel('A')) >= 64
            result.putalpha(Image.fromarray(np.uint8(mask) * 255))
            result = result.crop(result.getchannel('A').getbbox())
            padding = max(8, round(max(result.size) * .035))
            canvas = Image.new('RGBA', (result.width + padding * 2, result.height + padding * 2))
            canvas.alpha_composite(result, (padding, padding))
            result = canvas
        if selected and not row.get('transparent') and key not in reviewed_masks:
            # White casing highlights must remain solid, rather than becoming
            # translucent along with the similarly colored background.
            solid = binary_fill_holes(np.asarray(result.getchannel('A')) >= 64)
            refined = gaussian_filter(solid.astype(np.float32), sigma=0.55)
            result.putalpha(Image.fromarray(np.uint8(np.clip(refined * 255, 0, 255))))
        if selected:
            result.save(target, optimize=True)
        alpha = np.asarray(result.getchannel('A'))
        record = {'id': row['id'], 'index': row['index'], 'source': row.get('replacement_url', row['url']),
                  'file': f'/assets/products/gallery/{key}.png', 'size': result.size,
                  'opaque_pixels': int((alpha >= 240).sum()), 'transparent_pixels': int((alpha == 0).sum())}
        if not record['opaque_pixels'] or not record['transparent_pixels']:
            raise ValueError(f'Invalid transparency for {key}')
        revision = hashlib.sha256(target.read_bytes()).hexdigest()[:12]
        mapping[row['url']] = record['file'] + '?v=' + revision
        report.append(record)
        print(f'{key}: {result.size}', flush=True)
    (output.parent / 'gallery-index.js').write_text(
        '// Original URLs map to locally hosted transparent images.\nwindow.PRODUCT_GALLERY_ASSETS = ' +
        json.dumps(mapping, ensure_ascii=False, indent=2) + ';\nwindow.PRODUCT_GALLERY_ROLES = ' +
        json.dumps({f'/assets/products/gallery/{key}.png': role for key, role in image_roles.items()}, indent=2) + ';\n', encoding='utf-8')
    (output / 'sources.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    # Review sheet is saved outside the published catalog.
    thumb_w, thumb_h, cols = 240, 210, 5
    sheet = Image.new('RGB', (cols * thumb_w, ((len(report) + cols - 1) // cols) * thumb_h), '#252525')
    draw = ImageDraw.Draw(sheet)
    for i, record in enumerate(report):
        im = Image.open(project / record['file'].lstrip('/')).convert('RGBA')
        im.thumbnail((220, 175), Image.Resampling.LANCZOS)
        x, y = i % cols * thumb_w, i // cols * thumb_h
        sheet.paste(im, (x + (thumb_w - im.width)//2, y + (175 - im.height)//2), im)
        draw.text((x + 12, y + 184), f"{record['id']}-{record['index'] + 1}", fill='#e8c96b')
    sheet.save(args.manifest.parent / 'gallery-review.jpg', quality=92)
    print(f'Finished: {len(report)} transparent gallery assets', flush=True)


if __name__ == '__main__':
    main()
