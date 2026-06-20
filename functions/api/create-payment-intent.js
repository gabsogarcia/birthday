const PRICE_BY_CURRENCY = {
  zar: 9700,
  php: 19700,
  usd: 900,
  cad: 900,
  gbp: 900,
  aud: 900,
};

const BUMP_AMOUNTS = {
  style: 299,
  video: 499,
};

const json = (body, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  },
});

const cleanSession = value => String(value || '').replace(/[^a-zA-Z0-9_-]/g, '');

export async function onRequestPost({ request, env }) {
  if (!env.STRIPE_SECRET_KEY) {
    return json({ error: 'STRIPE_SECRET_KEY is not configured.' }, 503);
  }

  let input;
  try {
    input = await request.json();
  } catch {
    return json({ error: 'Invalid JSON body.' }, 400);
  }

  const currency = String(input.currency || '').toLowerCase();
  const amount = Number(input.amount || 0);
  const email = String(input.email || '').trim();
  const name = String(input.name || '').trim();
  const sessionId = cleanSession(input.sessao);
  const bumps = Array.isArray(input.bumps) ? [...new Set(input.bumps)] : [];

  if (!PRICE_BY_CURRENCY[currency]) {
    return json({ error: 'Unsupported currency.' }, 422);
  }
  if (!email.includes('@') || !name || !sessionId) {
    return json({ error: 'Valid email, name and session are required.' }, 422);
  }

  let expectedAmount = PRICE_BY_CURRENCY[currency];
  const validBumps = [];
  for (const bump of bumps) {
    if (BUMP_AMOUNTS[bump]) {
      expectedAmount += BUMP_AMOUNTS[bump];
      validBumps.push(bump);
    }
  }

  if (amount !== expectedAmount) {
    return json({ error: 'Order total does not match server pricing.' }, 422);
  }

  const form = new URLSearchParams();
  form.set('amount', String(expectedAmount));
  form.set('currency', currency);
  form.set('receipt_email', email);
  form.set('payment_method_types[0]', 'card');
  form.set('metadata[sessao]', sessionId);
  form.set('metadata[name]', name.slice(0, 100));
  form.set('metadata[bumps]', validBumps.join(','));

  const stripeResponse = await fetch('https://api.stripe.com/v1/payment_intents', {
    method: 'POST',
    headers: {
      Authorization: `Basic ${btoa(`${env.STRIPE_SECRET_KEY}:`)}`,
      'Content-Type': 'application/x-www-form-urlencoded',
      'Idempotency-Key': `birthday-${sessionId}-${currency}-${expectedAmount}`,
    },
    body: form,
  });

  const stripeData = await stripeResponse.json().catch(() => ({}));
  if (!stripeResponse.ok || !stripeData.client_secret) {
    console.error('Stripe PaymentIntent error', stripeResponse.status, stripeData);
    return json({
      error: stripeData.error?.message || 'Could not create PaymentIntent.',
    }, 502);
  }

  return json({ clientSecret: stripeData.client_secret });
}
