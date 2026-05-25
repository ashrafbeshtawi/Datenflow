import { useState, useEffect, useRef, useCallback } from 'react';
import { SCENES, ICONS } from './Scenes';
import { useLangContext } from './LangProvider';

const SLIDE_KEYS = ['docs', 'chat', 'sec', 'custom'];
const AUTO_MS = 7000;

export default function Showcase({ t }) {
  const { openBookingFlow } = useLangContext();
  const [idx, setIdx] = useState(0);
  const [playing, setPlaying] = useState(true);
  const [hovering, setHovering] = useState(false);
  const timer = useRef(null);

  const goTo = useCallback((i) => {
    setIdx(((i % SLIDE_KEYS.length) + SLIDE_KEYS.length) % SLIDE_KEYS.length);
  }, []);

  useEffect(() => {
    if (!playing || hovering) {
      if (timer.current) clearTimeout(timer.current);
      return;
    }
    timer.current = setTimeout(() => setIdx((i) => (i + 1) % SLIDE_KEYS.length), AUTO_MS);
    return () => clearTimeout(timer.current);
  }, [idx, playing, hovering]);

  useEffect(() => {
    const onKey = (e) => {
      if (e.target.matches?.('input, textarea, select')) return;
      if (e.key === 'ArrowRight') goTo(idx + 1);
      else if (e.key === 'ArrowLeft') goTo(idx - 1);
      else if (e.key === ' ') { e.preventDefault(); setPlaying(p => !p); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [idx, goTo]);

  const slide = t.slides[idx];
  const Scene = SCENES[SLIDE_KEYS[idx]];
  const Icon = ICONS[SLIDE_KEYS[idx]];
  const nextSlide = t.slides[(idx + 1) % SLIDE_KEYS.length];

  return (
    <div className="landing">
      <div /> {/* nav placeholder row */}

      <div
        className="showcase"
        onMouseEnter={() => setHovering(true)}
        onMouseLeave={() => setHovering(false)}
      >
        <div className="showcase-text">
          <span className="eyebrow">
            <span className="dot" aria-hidden></span>
            {t.eyebrow}
          </span>
          <div className="case-icon-row" key={'icon-' + idx}>
            {Icon ? <Icon /> : null}
            <span className="case-tag">{slide.tag}</span>
          </div>
          <h1 className="case-h1 scene-enter" key={'h1-' + idx}>
            {slide.h1Pre} <em>{slide.h1Em}</em>
          </h1>
          <p className="case-lede" key={'lede-' + idx}>{slide.lede}</p>
          <div className="case-cta">
            <button type="button" className="btn btn-accent btn-lg" onClick={openBookingFlow}>{t.primary}<span aria-hidden>→</span></button>
            <a href="/services" className="btn btn-lg">{t.secondary}</a>
          </div>
          <div className="case-metrics" key={'m-' + idx}>
            <div className="m">
              <span className="m-label">{t.beforeLabel}</span>
              <span className="m-before">{slide.before.value}</span>
            </div>
            <div className="m">
              <span className="m-label">{t.afterLabel}</span>
              <span className="m-after">{slide.after.value}</span>
            </div>
          </div>
        </div>

        <div className="showcase-stage" key={'stage-' + idx}>
          <span className="stage-divider-label left">{t.beforeLabel}</span>
          <span className="stage-divider-label right">{t.afterLabel}</span>
          <Scene />
          <div className="stage-vertical-line" aria-hidden></div>
        </div>
      </div>

      <div className="cbar">
        <div className="cbar-progress">
          <span>{String(idx + 1).padStart(2, '0')} / {String(SLIDE_KEYS.length).padStart(2, '0')}</span>
          <div className="dots" role="tablist">
            {SLIDE_KEYS.map((_, i) => (
              <button
                key={i}
                className={'dot-btn ' + (i === idx ? 'active' : '') + (hovering || !playing ? ' paused' : '')}
                onClick={() => goTo(i)}
                aria-label={`Use case ${i + 1}`}
                style={{ '--auto-ms': `${AUTO_MS}ms` }}
              >
                <span className="fill" key={'fill-' + idx + '-' + i + '-' + (hovering || !playing ? 'p' : 'r')}></span>
              </button>
            ))}
          </div>
        </div>

        <div className="cbar-titles">
          <span className="now">{slide.tag}</span>
          <span className="arrow">→</span>
          <span>{nextSlide.tag}</span>
        </div>

        <div className="cbar-nav">
          <button className="play-btn" onClick={() => setPlaying(p => !p)} aria-label={playing ? 'Pause' : 'Play'}>
            {playing ? (
              <svg width="12" height="12" viewBox="0 0 12 12"><rect x="2" y="2" width="3" height="8" fill="currentColor" /><rect x="7" y="2" width="3" height="8" fill="currentColor" /></svg>
            ) : (
              <svg width="12" height="12" viewBox="0 0 12 12"><path d="M3 2 L10 6 L3 10 Z" fill="currentColor" /></svg>
            )}
          </button>
          <button className="nav-btn" onClick={() => goTo(idx - 1)} aria-label="Previous">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9 2L4 7l5 5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
          <button className="nav-btn" onClick={() => goTo(idx + 1)} aria-label="Next">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 2l5 5-5 5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
        </div>
      </div>
    </div>
  );
}
