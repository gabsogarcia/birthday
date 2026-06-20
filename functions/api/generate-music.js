const KIE_API_URL = 'https://api.kie.ai/api/v1/generate';
const DEFAULT_STYLE = 'Upbeat feel-good pop, acoustic guitar, ukulele, hand claps, bright piano, warm cheerful vocals, sing-along chorus, major key, 120 BPM, festive party energy, radio-friendly, family-friendly';

const json = (body, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  },
});

export async function onRequestPost({ request, env }) {
  if (!env.KIE_API_KEY) {
    return json({ error: 'KIE_API_KEY is not configured.' }, 503);
  }

  let input;
  try {
    input = await request.json();
  } catch {
    return json({ error: 'Invalid JSON body.' }, 400);
  }

  const name = String(input.name || '').trim();
  const age = Number(input.age || 0);
  const message = String(input.message || '').trim();
  const lyrics = String(input.lyrics || '').trim();
  const email = String(input.email || '').trim();
  const sessionId = String(input.sessao || '').replace(/[^a-zA-Z0-9_-]/g, '');

  if (!name || name.length > 50) {
    return json({ error: 'Invalid name.' }, 422);
  }
  if (!Number.isInteger(age) || age < 1 || age > 120) {
    return json({ error: 'Invalid age.' }, 422);
  }
  if (message.length > 400 || !lyrics || lyrics.length > 5000) {
    return json({ error: 'Invalid lyrics or message.' }, 422);
  }
  if (!email.includes('@') || !sessionId) {
    return json({ error: 'Valid email and session are required.' }, 422);
  }

  if (env.BIRTHDAY_DATA) {
    const existing = await env.BIRTHDAY_DATA.get(`generation:${sessionId}`, 'json');
    if (existing?.taskId) {
      return json({ taskId: existing.taskId });
    }
  }

  const kieResponse = await fetch(KIE_API_URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.KIE_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      prompt: lyrics,
      customMode: true,
      instrumental: false,
      model: env.KIE_MODEL || 'V4_5',
      style: env.KIE_MUSIC_STYLE || DEFAULT_STYLE,
      title: `Happy Birthday ${name}`.slice(0, 80),
    }),
  });

  const kieData = await kieResponse.json().catch(() => ({}));
  const taskId = kieData?.data?.taskId;
  if (!kieResponse.ok || !taskId) {
    console.error('Kie generation error', kieResponse.status, kieData);
    return json({ error: kieData.msg || 'Kie.ai did not return a taskId.' }, 502);
  }

  if (env.BIRTHDAY_DATA) {
    await env.BIRTHDAY_DATA.put(
      `generation:${sessionId}`,
      JSON.stringify({ taskId, name, email, createdAt: new Date().toISOString() }),
      { expirationTtl: 60 * 60 * 24 * 7 },
    );
  }

  return json({ taskId });
}
