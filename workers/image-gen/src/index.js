/**
 * TPIX TRADE — AI Image Generation Worker
 * Uses Cloudflare Workers AI (FLUX.1-schnell) for high-quality image generation
 * Free: 10,000 neurons/day on Workers AI
 */

const ALLOWED_ORIGINS = [
  'https://tpix.online',
  'https://www.tpix.online',
  'http://localhost:8000',
];

// Secret key loaded from Cloudflare Worker environment variable
// Set via: wrangler secret put IMAGE_GEN_API_KEY

export default {
  async fetch(request, env) {
    // CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders(request) });
    }

    if (request.method !== 'POST') {
      return json({ error: 'POST only' }, 405, request);
    }

    // Auth check
    const authKey = request.headers.get('X-API-Key');
    if (!env.IMAGE_GEN_API_KEY || authKey !== env.IMAGE_GEN_API_KEY) {
      return json({ error: 'Unauthorized' }, 401, request);
    }

    try {
      const body = await request.json();
      const prompt = body.prompt;
      if (!prompt) {
        return json({ error: 'prompt required' }, 400, request);
      }

      const model = body.model || 'flux-schnell';
      let result;

      if (model === 'sdxl') {
        // Stable Diffusion XL Lightning — returns binary PNG
        result = await env.AI.run('@cf/bytedance/stable-diffusion-xl-lightning', {
          prompt,
          num_steps: body.steps || 8,
          guidance: body.guidance || 7.5,
        });
        // result is a ReadableStream of PNG
        const imageBytes = new Uint8Array(await new Response(result).arrayBuffer());
        return new Response(imageBytes, {
          headers: { ...corsHeaders(request), 'Content-Type': 'image/png' },
        });
      } else {
        // FLUX.1-schnell — returns JSON with base64
        result = await env.AI.run('@cf/black-forest-labs/flux-1-schnell', {
          prompt,
          steps: body.steps || 4,
        });

        if (result && result.image) {
          // Decode base64 to binary
          const imageBytes = Uint8Array.from(atob(result.image), c => c.charCodeAt(0));
          return new Response(imageBytes, {
            headers: { ...corsHeaders(request), 'Content-Type': 'image/png' },
          });
        }

        return json({ error: 'AI returned no image', detail: JSON.stringify(result).slice(0, 200) }, 500, request);
      }
    } catch (e) {
      return json({ error: e.message || 'Internal error' }, 500, request);
    }
  },
};

function corsHeaders(request) {
  const origin = request.headers.get('Origin') || '';
  const allowed = ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0];
  return {
    'Access-Control-Allow-Origin': allowed,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, X-API-Key',
  };
}

function json(data, status, request) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...corsHeaders(request), 'Content-Type': 'application/json' },
  });
}
