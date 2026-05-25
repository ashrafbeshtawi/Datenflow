import { NextResponse } from 'next/server';
import { sendMail, escapeHtml } from '@/lib/mailer';
import { contactLimiter, clientIp } from '@/lib/ratelimit';

export const runtime = 'nodejs';

const LIMITS = {
  name: 200,
  company: 200,
  email: 320,
  phone: 50,
  topic: 200,
  message: 5000,
};

const REQUIRED = ['name', 'company', 'email', 'topic', 'message'];

function validate(body) {
  const errors = [];
  for (const f of REQUIRED) {
    const v = body?.[f];
    if (typeof v !== 'string' || v.trim() === '') {
      errors.push({ field: f, reason: 'required' });
    }
  }
  for (const [f, max] of Object.entries(LIMITS)) {
    const v = body?.[f];
    if (typeof v === 'string' && v.length > max) {
      errors.push({ field: f, reason: 'too_long' });
    }
  }
  if (typeof body?.email === 'string' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email)) {
    errors.push({ field: 'email', reason: 'invalid' });
  }
  return errors;
}

function formatAnswersText(answers) {
  if (!Array.isArray(answers) || answers.length === 0) return '';
  const lines = ['\nVorqualifizierung:'];
  for (const { q, selected } of answers) {
    if (!Array.isArray(selected) || selected.length === 0) continue;
    lines.push(`  ${q}`);
    lines.push(`  → ${selected.join(', ')}`);
  }
  return lines.length > 1 ? lines.join('\n') : '';
}

function formatText(b) {
  const answersBlock = formatAnswersText(b.answers);
  return [
    `Name:    ${b.name}`,
    `Firma:   ${b.company}`,
    `E-Mail:  ${b.email}`,
    `Telefon: ${b.phone || '-'}`,
    `Thema:   ${b.topic}`,
    ...(answersBlock ? [answersBlock] : []),
    '',
    'Nachricht:',
    b.message,
  ].join('\n');
}

function formatAnswersHtml(answers, esc) {
  if (!Array.isArray(answers) || answers.length === 0) return '';
  const rows = answers
    .filter(({ selected }) => Array.isArray(selected) && selected.length > 0)
    .map(({ q, selected }) => `
      <tr>
        <td style="padding:6px 0 2px;color:#555;font-size:13px">${esc(q)}</td>
      </tr>
      <tr>
        <td style="padding:0 0 10px">
          ${selected.map((s) => `<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 10px;background:#f0eeff;border-radius:999px;font-size:12px;color:#5a3fbf">${esc(s)}</span>`).join('')}
        </td>
      </tr>`)
    .join('');
  if (!rows) return '';
  return `
    <h3 style="margin:20px 0 8px">Vorqualifizierung</h3>
    <table style="border-collapse:collapse;width:100%">${rows}</table>`;
}

function formatHtml(b) {
  const esc = escapeHtml;
  return `
    <div style="font-family:system-ui,sans-serif;font-size:14px;line-height:1.55;color:#111">
      <h2 style="margin:0 0 16px">Neue Kontaktanfrage</h2>
      <table style="border-collapse:collapse">
        <tr><td style="padding:4px 12px 4px 0"><b>Name:</b></td><td>${esc(b.name)}</td></tr>
        <tr><td style="padding:4px 12px 4px 0"><b>Firma:</b></td><td>${esc(b.company)}</td></tr>
        <tr><td style="padding:4px 12px 4px 0"><b>E-Mail:</b></td><td><a href="mailto:${esc(b.email)}">${esc(b.email)}</a></td></tr>
        <tr><td style="padding:4px 12px 4px 0"><b>Telefon:</b></td><td>${esc(b.phone || '-')}</td></tr>
        <tr><td style="padding:4px 12px 4px 0"><b>Thema:</b></td><td>${esc(b.topic)}</td></tr>
      </table>
      ${formatAnswersHtml(b.answers, esc)}
      <h3 style="margin:20px 0 8px">Nachricht</h3>
      <p style="white-space:pre-wrap;margin:0">${esc(b.message)}</p>
    </div>
  `.trim();
}

export async function POST(request) {
  const ip = clientIp(request);
  const rl = contactLimiter(ip);
  if (!rl.ok) {
    return NextResponse.json(
      { ok: false, error: 'rate_limit' },
      { status: 429, headers: { 'Retry-After': String(rl.retryAfter) } },
    );
  }

  let body;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ ok: false, error: 'invalid_json' }, { status: 400 });
  }

  // honeypot: pretend success so bots get no signal
  if (body && typeof body._hp === 'string' && body._hp.trim() !== '') {
    return NextResponse.json({ ok: true });
  }

  const errors = validate(body);
  if (errors.length) {
    return NextResponse.json({ ok: false, error: 'validation', fields: errors }, { status: 400 });
  }

  const to = process.env.MAIL_TO_CONTACT;
  if (!to) {
    console.error('[contact] MAIL_TO_CONTACT is not configured');
    return NextResponse.json({ ok: false, error: 'server_misconfigured' }, { status: 500 });
  }

  try {
    await sendMail({
      to,
      replyTo: `${body.name} <${body.email}>`,
      subject: `[Datenflow] Kontakt: ${body.topic}`,
      text: formatText(body),
      html: formatHtml(body),
    });
    return NextResponse.json({ ok: true });
  } catch (err) {
    console.error('[contact] mail send failed', err);
    return NextResponse.json({ ok: false, error: 'send_failed' }, { status: 500 });
  }
}
