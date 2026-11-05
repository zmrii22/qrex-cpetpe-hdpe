'use strict';

let overviewPlayerRequest;

function loadOverviewPlayer() {
    if (!overviewPlayerRequest) {
        overviewPlayerRequest = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'vendor/lottie-light-canvas-5.13.0.min.js';
            script.onload = () => resolve(window.lottie);
            script.onerror = () => {
                script.remove();
                overviewPlayerRequest = null;
                reject(new Error('Welcome animation unavailable.'));
            };
            document.head.appendChild(script);
        });
    }
    return overviewPlayerRequest;
}

async function animateOverviewWelcome(art) {
    try {
        const player = await loadOverviewPlayer();
        if (!art.isConnected) return;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const animation = player.loadAnimation({
            container: art.querySelector('.overview-welcome-animation'),
            renderer: 'canvas',
            path: 'assets/animations/welcome-cat.json?v=1',
            loop: false,
            autoplay: !reducedMotion.matches,
            rendererSettings: {clearCanvas: true}
        });
        const motionChanged = () => {
            if (reducedMotion.matches) animation.goToAndStop(0, true);
        };
        const visibilityChanged = () => {
            if (document.hidden) animation.pause();
        };
        const observer = new MutationObserver(() => {
            if (!art.isConnected) {
                observer.disconnect();
                reducedMotion.removeEventListener('change', motionChanged);
                document.removeEventListener('visibilitychange', visibilityChanged);
                animation.destroy();
            }
        });
        animation.addEventListener('DOMLoaded', () => {
            if (reducedMotion.matches) animation.goToAndStop(0, true);
            art.classList.add('is-ready');
        });
        animation.addEventListener('data_failed', () => {
            art.classList.remove('is-ready');
        });
        reducedMotion.addEventListener('change', motionChanged);
        document.addEventListener('visibilitychange', visibilityChanged);
        observer.observe($('#main'), {childList: true});
    } catch {
        // The local still image remains visible if the optional animation fails.
    }
}

async function dashboard(ticket) {
    const d = await api('dashboard');
    if (ticket !== S.routeTicket) return;
    const m = d.stats;
    $('#main').innerHTML = `
        <section class="overview-welcome" aria-labelledby="overview-welcome-title">
            <div class="overview-welcome-art" aria-hidden="true">
                <img src="assets/images/welcome-cat.png" alt="" width="280" height="240">
                <div class="overview-welcome-animation"></div>
            </div>
            <div class="overview-welcome-copy">
                <p class="eyebrow">${icon('sun')} A fresh start</p>
                <h1 id="overview-welcome-title">Welcome back,<span class="overview-welcome-name">${esc(S.user.name)}</span></h1>
                <p class="overview-welcome-message">Here's to a good day and great work.</p>
            </div>
        </section>
        <div class="metrics">
            ${[
                ['Created today',m.today,'calendar','Inspection units started today'],
                ['Draft inspections',m.drafts,'edit','Continue unfinished work'],
                ['Awaiting verification',m.pending,'clipboard-check','Submitted for supervisor review'],
                ['Failed inspections',m.failed,'alert-circle','Prioritize quality exceptions']
            ].map(([name,value,glyph,sub]) => `<div class="metric"><div class="metric-label">${name}${icon(glyph)}</div><div class="metric-value">${Number(value||0)}</div><div class="metric-foot">${sub}</div></div>`).join('')}
        </div>`;
    animateOverviewWelcome($('.overview-welcome-art'));
}
