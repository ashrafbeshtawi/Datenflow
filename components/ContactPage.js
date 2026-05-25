'use client';

import { useState, useEffect } from 'react';
import Nav from './Nav';
import Footer from './Footer';
import PageIcon from './PageIcon';
import { useLang } from '@/lib/useLang';

export default function ContactPage() {
  const { lang, setLang, t } = useLang();
  const c = t.contact;
  const [submitted, setSubmitted] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [bookingAnswers, setBookingAnswers] = useState(null);

  useEffect(() => {
    try {
      const raw = sessionStorage.getItem('df-booking-answers');
      if (raw) setBookingAnswers(JSON.parse(raw));
    } catch {}
  }, []);

  const onSubmit = async (e) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    setFieldErrors({});

    const fd = new FormData(e.currentTarget);
    const payload = {
      name: fd.get('name'),
      company: fd.get('company'),
      email: fd.get('email'),
      phone: fd.get('phone') || '',
      topic: fd.get('topic'),
      message: fd.get('message'),
      _hp: fd.get('_hp') || '',
      ...(bookingAnswers ? { answers: bookingAnswers } : {}),
    };

    try {
      const res = await fetch('/api/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.ok) {
        try { sessionStorage.removeItem('df-booking-answers'); } catch {}
        setSubmitted(true);
      } else {
        const key = data?.error || 'send_failed';
        setError(c.f.errors[key] || c.f.errors.send_failed);
        if (data?.fields) {
          const map = {};
          for (const fe of data.fields) map[fe.field] = true;
          setFieldErrors(map);
        }
      }
    } catch {
      setError(c.f.errors.network);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <Nav lang={lang} setLang={setLang} t={t} current="contact" />
      <main className="page shell">
        <header className="page-head">
          <PageIcon name="contact" />
          <div className="kicker">{c.kicker}</div>
          <h1 className="h1">{c.title}</h1>
        </header>
        <section className="contact">
          <div>
            <h3>{c.title}</h3>
            <p>{c.body}</p>
            <ul className="contact-checks">
              {c.checks.map((check) => <li key={check}>{check}</li>)}
            </ul>
          </div>
          {submitted ? (
            <div className="form-thanks" role="status">✓ {c.f.thanks}</div>
          ) : (
            <form className="form" onSubmit={onSubmit} noValidate>
              {bookingAnswers && bookingAnswers.some((a) => a.selected.length > 0) && (
                <div className="booking-summary">
                  <div className="booking-summary-head">
                    <span>{c.f.summaryLabel}</span>
                    <button
                      type="button"
                      className="booking-summary-clear"
                      onClick={() => { setBookingAnswers(null); try { sessionStorage.removeItem('df-booking-answers'); } catch {} }}
                    >
                      ✕
                    </button>
                  </div>
                  {bookingAnswers.filter((a) => a.selected.length > 0).map((a, i) => (
                    <div key={i} className="booking-summary-row">
                      <div className="booking-summary-q">{a.q}</div>
                      <div className="booking-summary-opts">
                        {a.selected.map((opt) => <span key={opt} className="booking-summary-tag">{opt}</span>)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
              <div className="row">
                <div className={`field ${fieldErrors.name ? 'field-error' : ''}`}>
                  <label htmlFor="name">{c.f.name}</label>
                  <input id="name" name="name" type="text" required maxLength={200} />
                </div>
                <div className={`field ${fieldErrors.company ? 'field-error' : ''}`}>
                  <label htmlFor="company">{c.f.company}</label>
                  <input id="company" name="company" type="text" required maxLength={200} />
                </div>
              </div>
              <div className="row">
                <div className={`field ${fieldErrors.email ? 'field-error' : ''}`}>
                  <label htmlFor="email">{c.f.email}</label>
                  <input id="email" name="email" type="email" required maxLength={320} />
                </div>
                <div className={`field ${fieldErrors.phone ? 'field-error' : ''}`}>
                  <label htmlFor="phone">{c.f.phone}</label>
                  <input id="phone" name="phone" type="tel" maxLength={50} />
                </div>
              </div>
              <div className={`field ${fieldErrors.topic ? 'field-error' : ''}`}>
                <label htmlFor="topic">{c.f.topic}</label>
                <select id="topic" name="topic" defaultValue={c.f.topicOpts[0]}>
                  {c.f.topicOpts.map((opt) => <option key={opt} value={opt}>{opt}</option>)}
                </select>
              </div>
              <div className={`field ${fieldErrors.message ? 'field-error' : ''}`}>
                <label htmlFor="message">{c.f.message}</label>
                <textarea id="message" name="message" rows={4} placeholder={c.f.messagePh} required maxLength={5000} />
              </div>
              <div className="hp-field" aria-hidden="true">
                <label htmlFor="hp-website">Website</label>
                <input id="hp-website" name="_hp" type="text" tabIndex={-1} autoComplete="off" />
              </div>
              {error && <div className="form-error" role="alert">{error}</div>}
              <button type="submit" className="submit" disabled={submitting}>
                {submitting ? c.f.sending : c.f.submit}
                <span aria-hidden>→</span>
              </button>
            </form>
          )}
        </section>
      </main>
      <Footer t={t} />
    </>
  );
}
