'use client';

import { useState, useEffect, useCallback } from 'react';
import { useLangContext } from './LangProvider';

export default function BookingFlow() {
  const { bookingFlowOpen, closeBookingFlow, t } = useLangContext();
  const bf = t.bookingFlow;
  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState(() => bf.steps.map(() => []));

  const totalSteps = bf.steps.length;

  useEffect(() => {
    if (bookingFlowOpen) {
      setStep(0);
      setAnswers(bf.steps.map(() => []));
    }
  }, [bookingFlowOpen, bf.steps.length]);

  useEffect(() => {
    if (!bookingFlowOpen) return;
    const onKey = (e) => { if (e.key === 'Escape') closeBookingFlow(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [bookingFlowOpen, closeBookingFlow]);

  const toggleOpt = useCallback((opt) => {
    setAnswers((prev) => {
      const next = [...prev];
      const current = next[step];
      next[step] = current.includes(opt) ? current.filter((o) => o !== opt) : [...current, opt];
      return next;
    });
  }, [step]);

  const goNext = () => {
    if (step < totalSteps - 1) {
      setStep((s) => s + 1);
    } else {
      const payload = bf.steps.map((s, i) => ({ q: s.q, selected: answers[i] }));
      try { sessionStorage.setItem('df-booking-answers', JSON.stringify(payload)); } catch {}
      closeBookingFlow();
      window.location.href = '/contact';
    }
  };

  const goBack = () => {
    if (step > 0) setStep((s) => s - 1);
  };

  if (!bookingFlowOpen) return null;

  const current = bf.steps[step];
  const selected = answers[step];
  const isLast = step === totalSteps - 1;

  return (
    <div className="bflow-backdrop" onClick={closeBookingFlow} role="dialog" aria-modal="true" aria-label={bf.title}>
      <div className="bflow-modal" onClick={(e) => e.stopPropagation()}>
        <button className="bflow-close" onClick={closeBookingFlow} aria-label="Schließen">✕</button>

        <div className="bflow-header">
          <span className="bflow-kicker">{bf.title}</span>
          <div className="bflow-progress-bar">
            {bf.steps.map((_, i) => (
              <div key={i} className={`bflow-seg${i <= step ? ' bflow-seg-active' : ''}`} />
            ))}
          </div>
          <div className="bflow-step-label">
            {bf.stepLabel} {step + 1} {bf.of} {totalSteps}
          </div>
        </div>

        <h2 className="bflow-question">{current.q}</h2>
        <p className="bflow-hint">{bf.hint}</p>

        <div className="bflow-opts">
          {current.opts.map((opt) => (
            <button
              key={opt}
              type="button"
              className={`bflow-opt${selected.includes(opt) ? ' bflow-opt-on' : ''}`}
              onClick={() => toggleOpt(opt)}
            >
              <span className="bflow-check" aria-hidden>{selected.includes(opt) ? '✓' : ''}</span>
              {opt}
            </button>
          ))}
        </div>

        <div className="bflow-actions">
          {step > 0 && (
            <button type="button" className="bflow-btn-back" onClick={goBack}>
              ← {bf.back}
            </button>
          )}
          <button type="button" className="bflow-btn-next" onClick={goNext}>
            {isLast ? bf.finish : bf.next} →
          </button>
        </div>
      </div>
    </div>
  );
}
