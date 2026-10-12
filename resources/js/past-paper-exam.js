document.querySelectorAll('[data-paper-exam]').forEach((exam) => {
    const form = exam.querySelector('[data-paper-form]');
    const questions = [...form.querySelectorAll('[data-paper-question]')];
    const navButtons = [...exam.querySelectorAll('[data-paper-go]')];
    const clock = exam.querySelector('[data-paper-clock]');
    const progress = exam.querySelector('[data-paper-progress]');
    const status = exam.querySelector('[data-paper-status]');
    const previous = form.querySelector('[data-paper-previous]');
    const next = form.querySelector('[data-paper-next]');
    const submit = form.querySelector('[data-paper-submit]');
    const storageKey = `selflearn:past-paper:${exam.dataset.runKey}`;
    let current = 0;
    let forcedSubmit = false;
    let submitting = false;
    let warnedFiveMinutes = false;
    let interval = null;
    let saved = {};

    try {
        saved = JSON.parse(window.sessionStorage.getItem(storageKey) || '{}');
        if (saved && typeof saved === 'object') {
            form.querySelectorAll('input, textarea').forEach((field) => {
                if (!field.name || !Object.hasOwn(saved, field.name)) return;
                const value = saved[field.name];
                if (field.type === 'radio' || field.type === 'checkbox') field.checked = Array.isArray(value) ? value.includes(field.value) : value === field.value;
                else field.value = typeof value === 'string' ? value : '';
            });
        }
    } catch {
        saved = {};
    }

    const answered = (question) => [...question.querySelectorAll('input, textarea')].some((field) => field.type === 'radio' || field.type === 'checkbox' ? field.checked : field.value.trim() !== '');
    const storeAnswers = () => {
        const data = {};
        form.querySelectorAll('input, textarea').forEach((field) => {
            if (!field.name) return;
            if (field.type === 'radio' && !field.checked) return;
            if (field.type === 'checkbox') {
                data[field.name] ||= [];
                if (field.checked) data[field.name].push(field.value);
            } else data[field.name] = field.value;
        });
        try { window.sessionStorage.setItem(storageKey, JSON.stringify(data)); } catch { /* Answers still submit normally if storage is unavailable. */ }
    };

    const render = () => {
        questions.forEach((question, index) => {
            question.hidden = index !== current;
            const review = question.querySelector('[data-paper-review]');
            const label = question.querySelector('[data-paper-review-label]');
            const isReview = navButtons[index].dataset.review === 'true';
            review.setAttribute('aria-pressed', String(isReview));
            review.textContent = isReview ? 'Remove review flag' : 'Mark for review';
            label.textContent = isReview ? 'Marked for review' : '';
            navButtons[index].classList.toggle('is-answered', answered(question));
            navButtons[index].classList.toggle('is-review', isReview);
            navButtons[index].setAttribute('aria-current', index === current ? 'step' : 'false');
            navButtons[index].setAttribute('aria-label', `Question ${index + 1}${answered(question) ? ', answered' : ', unanswered'}${isReview ? ', marked for review' : ''}`);
        });
        progress.textContent = `Question ${current + 1} of ${questions.length}`;
        status.textContent = `${questions.filter(answered).length} answered. ${navButtons.filter((button) => button.dataset.review === 'true').length} marked for review.`;
        previous.disabled = current === 0;
        next.disabled = current === questions.length - 1;
    };
    const goTo = (index) => {
        if (index < 0 || index >= questions.length) return;
        current = index;
        render();
        questions[current].focus();
    };

    navButtons.forEach((button, index) => {
        button.addEventListener('click', () => goTo(index));
        questions[index].querySelector('[data-paper-review]').addEventListener('click', (event) => {
            const active = event.currentTarget.getAttribute('aria-pressed') === 'true';
            button.dataset.review = String(!active);
            render();
        });
    });
    previous.addEventListener('click', () => goTo(current - 1));
    next.addEventListener('click', () => goTo(current + 1));
    form.addEventListener('input', () => { storeAnswers(); render(); });
    form.addEventListener('change', () => { storeAnswers(); render(); });
    form.addEventListener('submit', (event) => {
        if (!forcedSubmit && !window.confirm(`Submit your practice? ${questions.filter((question) => !answered(question)).length} question(s) are unanswered.`)) {
            event.preventDefault();
        } else {
            submitting = true;
            window.sessionStorage.removeItem(storageKey);
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!submitting && questions.some(answered)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    const expiresAt = Date.parse(exam.dataset.expiresAt);
    const tick = () => {
        const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        clock.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        clock.setAttribute('aria-label', `${minutes} minutes and ${seconds} seconds remaining`);
        clock.classList.toggle('is-low', remaining <= 300);
        if (remaining <= 300 && !warnedFiveMinutes) {
            warnedFiveMinutes = true;
            status.textContent = `Five minutes or less remain. ${questions.filter(answered).length} answered.`;
        }
        if (remaining === 0) {
            window.clearInterval(interval);
            clock.textContent = '00:00';
            status.textContent = 'Time is up. Submitting your answers.';
            forcedSubmit = true;
            form.requestSubmit();
        }
    };
    render();
    tick();
    interval = window.setInterval(tick, 1000);
});
