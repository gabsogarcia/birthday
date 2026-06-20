const KIE_STATUS_URL = 'https://api.kie.ai/api/v1/generate/record-info';

const json = (body, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  },
});

export async function onRequestGet({ request, env }) {
  if (!env.KIE_API_KEY) {
    return json({ error: 'KIE_API_KEY is not configured.' }, 503);
  }

  const taskId = new URL(request.url).searchParams.get('taskId') || '';
  if (!/^[a-zA-Z0-9_-]{8,128}$/.test(taskId)) {
    return json({ error: 'Invalid taskId.' }, 422);
  }

  const kieResponse = await fetch(`${KIE_STATUS_URL}?taskId=${encodeURIComponent(taskId)}`, {
    headers: { Authorization: `Bearer ${env.KIE_API_KEY}` },
  });
  const kieData = await kieResponse.json().catch(() => ({}));

  if (!kieResponse.ok) {
    console.error('Kie status error', kieResponse.status, kieData);
    return json({ error: 'Could not read generation status.' }, 502);
  }

  const data = kieData.data || {};
  const status = String(data.status || 'PENDING');
  const firstTrack = data.response?.sunoData?.[0] || {};
  const failedStatuses = new Set([
    'CREATE_TASK_FAILED',
    'GENERATE_AUDIO_FAILED',
    'CALLBACK_EXCEPTION',
    'SENSITIVE_WORD_ERROR',
  ]);

  if (failedStatuses.has(status)) {
    return json({
      status,
      error: data.errorMessage || 'Music generation failed.',
    }, 502);
  }

  return json({
    status,
    ...(firstTrack.audioUrl || firstTrack.streamAudioUrl
      ? { audioUrl: firstTrack.audioUrl || firstTrack.streamAudioUrl }
      : {}),
  });
}
