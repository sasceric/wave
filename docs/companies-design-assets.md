# Companies directory artwork

The decorative hero artwork was generated with the built-in ImageGen tool on 2026-10-07. These are illustrations, not company-owned campaign images. The Vue page references project assets; the original generated PNGs are retained in Codex's generated-images directory.

| Asset | Source | Production copy | Dimensions |
| --- | --- | --- | --- |
| Product photograph | `frontend/public/images/companies-hero.webp` | `public/images/companies-hero.webp` | 900 × 600 |
| Photographer photograph | `frontend/public/images/companies-photographer.webp` | `public/images/companies-photographer.webp` | 360 × 360 |

Vite copies the source files into Symfony's public directory when building. These static decorative assets do not change the upload processor's lossless WebP policy or its 600px width limit.

## Final prompts

Product:

> Use case: product-mockup. Create one photorealistic editorial website hero photo, landscape 3:2. A premium unlabeled amber glass perfume bottle with matte black cap on beige travertine stone, olive branches behind, warm late afternoon sunlight and organic leaf shadows, shallow depth of field, sophisticated muted forest green and beige tones. Bottle centered and fully visible; wide composition suitable for cropping into an arch on a website. No typography, no logos, no watermark, no UI. Save this project asset and return local file path.

Photographer:

> Create a photorealistic editorial website photograph, square composition, of a young woman with brown hair holding a black mirrorless camera up at chest level, looking toward the viewer, camera and hands clearly visible in the center of the frame, cream linen shirt, warm natural olive green outdoor background, soft sunlight, muted warm beige and forest green palette. Medium close-up from head to waist, subject centered for a circular crop. No text, no logo, no watermark. This is a decorative website asset.

Company cards use the company’s own uploaded cover through the responsive media thumbnail API. Companies without a cover use `frontend/public/images/company-cover.webp`, supplied by the project owner from `~/Downloads/company-cover.webp` on 2026-10-07; Vite copies it to `public/images/company-cover.webp`. Campaign images are independent. No company size, collaboration type, saved favorites or other invented business data is displayed.
