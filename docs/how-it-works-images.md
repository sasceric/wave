# How it works photographs

The user requested the creator-filming and company-at-laptop photographs from the
shared how-it-works design, including the wavy photo edges. The built-in image
creation tool reconstructed the two photographic scenes from that design reference;
these are reference-based reconstructions, not original full-resolution source files.

Final project assets:

- `frontend/public/images/how-it-works-creator.webp` — 600 × 900, approximately 59 KB.
- `frontend/public/images/how-it-works-company.webp` — 600 × 900, approximately 39 KB.

The assets were encoded locally as WebP. The organic edges are applied through SVG
clip paths in `HowItWorksView.vue`, rather than baked into the photographs. Keep the
rectangular source assets when adjusting their responsive framing.

## Prompts used with the built-in tool

Reference input for both prompts: the user-supplied screenshot
`codex-clipboard-e8f3432d-5094-4e9d-bee0-3e10b69ea696.png`.

### Creator

Use case: identity-preserve / photo extraction. Asset type: portrait photograph for the LEFT creator workflow card on Wave website. Input image 1 is a user-supplied website design reference. Extract and faithfully reconstruct ONLY the creator photograph from the LEFT card: the SAME smiling young woman with shoulder-length brunette hair, small gold hoop earrings, muted sage knitted sweater, seated in a warm daylight home studio filming a video with a black smartphone on a small tripod in the left foreground. Preserve her face, hairstyle, expression, pose, hand gesture, sweater and pale shelves/plants behind her exactly as visible in the reference photo. Output ONLY a clean rectangular portrait photo, aspect ratio 2:3, without any UI, text, icons, cards, shapes, or floating badges. Reconstruct the parts obscured by UI badges seamlessly. No redesigned subject, no new identity, no illustration, no watermark. Frame the woman and the smartphone as in the reference, with natural photographic skin texture and warm daylight. The website will apply its own wavy clip path; do not bake the mask into this image.

### Company

Use case: identity-preserve / photo extraction. Asset type: portrait photograph for the RIGHT company workflow card on Wave website. Input image 1 is a user-supplied website design reference. Extract and faithfully reconstruct ONLY the company photograph from the RIGHT card: the SAME smiling man with wavy dark brown hair, black rectangular glasses and short stubble, wearing a dark forest-green button-up shirt and working on a silver laptop in a warmly daylight-lit office. Preserve his face, hairstyle, expression, shirt, seated pose, hands, laptop and neutral shelves/windows behind him as visible in the reference. Output ONLY a clean rectangular portrait photograph, aspect ratio 2:3, without UI, text, icons, cards, shapes, avatars or floating badges. Reconstruct portions obscured by badges seamlessly. No different identity, no redesigned scene, no illustration, no watermark. Frame his head at the upper right and the laptop and hands lower center like the reference. The website will apply its own wavy clip path; do not bake the mask into this photo.
