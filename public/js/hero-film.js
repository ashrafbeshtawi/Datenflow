// Hero film reveal: the film starts hidden behind a play button. Pressing it fades the hero copy
// out, lets the film emerge in a circle growing from the button, and starts playback. Closing
// (button, Escape, or the film ending) reverses it. Without this script the film simply sits
// beside the copy, so nothing here is required for the page to work.
document.addEventListener('DOMContentLoaded', () => {
    const body = document.querySelector('.hero-body');
    const play = document.querySelector('.film-play');
    const frame = body?.querySelector('.film-frame');
    const close = frame?.querySelector('.film-close');
    if (!body || !play || !frame || !close) return;

    body.classList.add('film-reveal');
    play.hidden = false;
    close.hidden = false;

    // Only one of the two cuts is displayed, depending on screen orientation.
    const visibleVideo = () => [...frame.querySelectorAll('video')].find(v => getComputedStyle(v).display !== 'none');

    const setHeight = (px) => { body.style.height = `${px}px`; };

    // Circle centred on the play button, large enough to cover the whole frame when open.
    const aimClip = () => {
        const f = frame.getBoundingClientRect();
        const b = play.getBoundingClientRect();
        const x = b.left + b.width / 2 - f.left;
        const y = b.top + b.height / 2 - f.top;
        const r = Math.max(...[[0, 0], [f.width, 0], [0, f.height], [f.width, f.height]]
            .map(([cx, cy]) => Math.hypot(cx - x, cy - y)));
        frame.style.setProperty('--clip-x', `${x}px`);
        frame.style.setProperty('--clip-y', `${y}px`);
        frame.style.setProperty('--clip-r', `${Math.ceil(r)}px`);
    };

    const isOpen = () => body.classList.contains('is-playing');

    const open = () => {
        if (isOpen()) return;
        aimClip();
        setHeight(body.offsetHeight);
        void body.offsetHeight; // commit the start height so the change below animates
        body.classList.add('is-playing');
        setHeight(frame.offsetHeight);
        const video = visibleVideo();
        video?.play().catch(() => {});
        close.focus({ preventScroll: true });
    };

    const shut = () => {
        if (!isOpen()) return;
        frame.querySelectorAll('video').forEach(v => v.pause());
        aimClip();
        setHeight(body.querySelector('.hero-copy').offsetHeight);
        body.classList.remove('is-playing');
        // Hand the height back to the layout once the collapse (0.9s) has finished.
        setTimeout(() => { if (!isOpen()) body.style.height = ''; }, 1000);
        play.focus({ preventScroll: true });
    };

    play.addEventListener('click', open);
    close.addEventListener('click', shut);
    frame.querySelectorAll('video').forEach(v => v.addEventListener('ended', shut));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') shut(); });
    window.addEventListener('resize', () => { if (isOpen()) setHeight(frame.offsetHeight); });
});
