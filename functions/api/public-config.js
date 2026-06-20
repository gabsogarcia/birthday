const json = (body, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  },
});

export async function onRequestGet({ env }) {
  if (!env.STRIPE_PUBLISHABLE_KEY) {
    return json({ error: 'STRIPE_PUBLISHABLE_KEY is not configured.' }, 503);
  }

  return json({ stripePublishableKey: env.STRIPE_PUBLISHABLE_KEY });
}
