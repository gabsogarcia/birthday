const json = (body, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  },
});

const hexToBytes = hex => {
  if (!/^[0-9a-f]+$/i.test(hex) || hex.length % 2) return null;
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = Number.parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return bytes;
};

async function verifyStripeSignature(payload, header, secret, tolerance = 300) {
  let timestamp = 0;
  const signatures = [];

  for (const part of header.split(',')) {
    const [key, value] = part.trim().split('=', 2);
    if (key === 't') timestamp = Number(value);
    if (key === 'v1' && value) signatures.push(value);
  }

  if (!timestamp || !signatures.length || Math.abs(Date.now() / 1000 - timestamp) > tolerance) {
    return false;
  }

  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['verify'],
  );
  const signedPayload = encoder.encode(`${timestamp}.${payload}`);

  for (const signature of signatures) {
    const bytes = hexToBytes(signature);
    if (bytes && await crypto.subtle.verify('HMAC', key, bytes, signedPayload)) {
      return true;
    }
  }
  return false;
}

export async function onRequestPost({ request, env }) {
  if (!env.STRIPE_WEBHOOK_SECRET) {
    return json({ error: 'STRIPE_WEBHOOK_SECRET is not configured.' }, 503);
  }

  const payload = await request.text();
  const signature = request.headers.get('Stripe-Signature') || '';
  if (!await verifyStripeSignature(payload, signature, env.STRIPE_WEBHOOK_SECRET)) {
    return json({ error: 'Invalid Stripe signature.' }, 400);
  }

  let event;
  try {
    event = JSON.parse(payload);
  } catch {
    return json({ error: 'Invalid Stripe event.' }, 400);
  }

  if (event.type !== 'payment_intent.succeeded') {
    return json({ received: true });
  }

  const intent = event.data?.object || {};
  const sessionId = String(intent.metadata?.sessao || '').replace(/[^a-zA-Z0-9_-]/g, '');

  if (env.BIRTHDAY_DATA && sessionId) {
    await env.BIRTHDAY_DATA.put(
      `payment:${sessionId}`,
      JSON.stringify({
        eventId: event.id,
        paymentIntentId: intent.id || '',
        sessao: sessionId,
        name: intent.metadata?.name || '',
        amount: intent.amount_received || intent.amount || 0,
        currency: intent.currency || '',
        paidAt: new Date().toISOString(),
        generationTriggeredBy: 'frontend_before_checkout',
      }),
    );
  }

  return json({ received: true });
}
