import renderMathInElement from 'katex/contrib/auto-render';
import 'katex/dist/katex.min.css';
import './past-paper-exam.js';
import './accessibility-preferences.js';
import './read-aloud.js';

const renderLearnerMath = () => {
    document.querySelectorAll('[data-render-math]').forEach((element) => {
        renderMathInElement(element, {
            delimiters: [
                { left: '\\[', right: '\\]', display: true },
                { left: '$$', right: '$$', display: true },
                { left: '\\(', right: '\\)', display: false },
                { left: '$', right: '$', display: false },
            ],
            throwOnError: false,
            trust: false,
            strict: 'ignore',
            output: 'htmlAndMathml',
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderLearnerMath, { once: true });
} else {
    renderLearnerMath();
}
document.addEventListener('selflearn:render-math', renderLearnerMath);

document.querySelectorAll('[data-guided-session]').forEach((session) => {
    const duration = Number(session.dataset.duration) || 900;
    const toggle = session.querySelector('[data-session-toggle]');
    const reset = session.querySelector('[data-session-reset]');
    const status = session.querySelector('[data-session-status]');
    const clock = session.querySelector('[data-session-clock]');
    const phases = [...session.querySelectorAll('[data-session-phase]')];
    const phaseEnds = [180, 480, duration];
    const storageKey = `selflearn:study-session:${session.dataset.sessionKey}`;
    let startedAt = null;
    let elapsedBeforePause = 0;
    let interval = null;
    try {
        const saved = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
        if (saved && Number.isFinite(saved.elapsedBeforePause) && saved.elapsedBeforePause >= 0) {
            elapsedBeforePause = Math.min(duration, saved.elapsedBeforePause);
            startedAt = Number.isFinite(saved.startedAt) ? saved.startedAt : null;
        }
    } catch {
        // The session still works for this page when browser storage is unavailable.
    }

    const save = () => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify({ elapsedBeforePause, startedAt }));
        } catch {
            // Storage is an enhancement, not a requirement for using the study path.
        }
    };

    const render = () => {
        const elapsed = elapsedBeforePause + (startedAt === null ? 0 : Math.floor((Date.now() - startedAt) / 1000));
        const remaining = Math.max(0, duration - elapsed);
        const displayTime = `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
        clock.textContent = displayTime;
        clock.setAttribute('aria-label', remaining === 0 ? 'Session complete' : `${Math.floor(remaining / 60)} minutes and ${remaining % 60} seconds remaining`);
        const activeIndex = phaseEnds.findIndex((end) => elapsed < end);
        phases.forEach((phase, index) => {
            if (index === activeIndex) phase.setAttribute('aria-current', 'step');
            else phase.removeAttribute('aria-current');
        });

        if (remaining === 0) {
            startedAt = null;
            elapsedBeforePause = duration;
            save();
            window.clearInterval(interval);
            interval = null;
            toggle.textContent = 'Session complete';
            toggle.disabled = true;
            status.textContent = 'You completed this guided session. Review your practice feedback or choose what to learn next.';
            return;
        }

        if (startedAt === null) {
            toggle.textContent = elapsedBeforePause ? 'Resume session' : 'Start session';
            status.textContent = elapsedBeforePause
                ? 'Session paused. Follow the suggested order when you can, and ask the tutor to revisit a foundation if a later idea feels unclear.'
                : 'Suggested order builds one idea on the last. You can explore ahead, then ask the tutor to revisit a foundation if something feels unclear.';
        } else {
            toggle.textContent = 'Pause session';
            status.textContent = `Session running. ${phases[activeIndex]?.querySelector('strong')?.textContent ?? 'Keep going.'}`;
        }
        reset.hidden = elapsedBeforePause === 0 && startedAt === null;
    };

    toggle.addEventListener('click', () => {
        if (startedAt === null) {
            if (elapsedBeforePause >= duration) return;
            startedAt = Date.now();
            save();
            reset.hidden = false;
            interval = window.setInterval(render, 1000);
        } else {
            elapsedBeforePause += Math.floor((Date.now() - startedAt) / 1000);
            startedAt = null;
            save();
            window.clearInterval(interval);
            interval = null;
        }
        render();
    });

    reset.addEventListener('click', () => {
        startedAt = null;
        elapsedBeforePause = 0;
        save();
        window.clearInterval(interval);
        interval = null;
        toggle.disabled = false;
        render();
    });

    render();
});

document.querySelectorAll('[data-exam-timer]').forEach((timer) => {
    const form = document.querySelector('[data-exam-form]');
    if (!form) return;

    let remaining = Number(timer.dataset.seconds);
    let interval = null;
    const render = () => {
        const minutes = Math.floor(Math.max(remaining, 0) / 60);
        const seconds = Math.max(remaining, 0) % 60;
        timer.textContent = `Time remaining: ${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        if (remaining <= 0) {
            window.clearInterval(interval);
            timer.textContent = 'Time is up. Submitting your answers.';
            form.requestSubmit();
        }
        remaining -= 1;
    };

    render();
    interval = window.setInterval(render, 1000);
});
