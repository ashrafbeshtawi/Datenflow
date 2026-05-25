'use client';

import { useState } from 'react';
import { LuMenu, LuX } from 'react-icons/lu';
import Logomark from './Logomark';
import { useLangContext } from './LangProvider';

export default function Nav({ current = 'home' }) {
  const { lang, setLang, t, openBookingFlow } = useLangContext();
  const [open, setOpen] = useState(false);
  const close = () => setOpen(false);

  const links = [
    { href: '/',         key: 'home',     label: t.nav.home },
    { href: '/services', key: 'services', label: t.nav.services },
    { href: '/tools',    key: 'tools',    label: t.nav.tools },
    { href: '/process',  key: 'process',  label: t.nav.process },
    { href: '/faq',      key: 'faq',      label: t.nav.faq },
    { href: '/contact',  key: 'contact',  label: t.nav.contact },
  ];

  return (
    <nav className={`nav ${open ? 'nav-open' : ''}`}>
      <div className="shell nav-inner">
        <a className="logo" href="/">
          <Logomark />
          <span>Datenflow</span>
        </a>

        <div className="nav-links">
          {links.map((l) => (
            <a key={l.key} href={l.href} className={current === l.key ? 'current' : ''}>
              {l.label}
            </a>
          ))}
        </div>

        <div className="nav-right">
          <div className="lang-toggle" role="tablist" aria-label="Language">
            <button className={lang === 'de' ? 'active' : ''} onClick={() => setLang('de')}>DE</button>
            <button className={lang === 'en' ? 'active' : ''} onClick={() => setLang('en')}>EN</button>
          </div>
          <button type="button" className="btn btn-primary" onClick={openBookingFlow}>
            {t.nav.cta}<span aria-hidden>→</span>
          </button>
        </div>

        <div className="nav-mobile-bar">
          <button type="button" className="btn btn-primary nav-cta-mobile" onClick={openBookingFlow}>
            {t.nav.cta}<span aria-hidden>→</span>
          </button>
          <button
            type="button"
            className="nav-toggle"
            aria-label={open ? 'Close menu' : 'Open menu'}
            aria-expanded={open}
            onClick={() => setOpen((v) => !v)}
          >
            {open ? <LuX /> : <LuMenu />}
          </button>
        </div>
      </div>

      <div className="nav-mobile" hidden={!open}>
        <div className="shell nav-mobile-inner">
          {links.map((l) => (
            <a
              key={l.key}
              href={l.href}
              className={current === l.key ? 'current' : ''}
              onClick={close}
            >
              {l.label}
            </a>
          ))}
          <div className="nav-mobile-foot">
            <div className="lang-toggle" role="tablist" aria-label="Language">
              <button className={lang === 'de' ? 'active' : ''} onClick={() => setLang('de')}>DE</button>
              <button className={lang === 'en' ? 'active' : ''} onClick={() => setLang('en')}>EN</button>
            </div>
          </div>
        </div>
      </div>
    </nav>
  );
}
