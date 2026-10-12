(() => {
    const DATABASE = 'selflearn-study-packs';
    const STORE = 'packs';
    const ATTEMPTS = 'attempts';
    const CURRENT_USER_KEY = 'selflearn:offline-current-user';
    const SYNC_CONTEXT_URL = '/offline-sync-context';

    const createAttemptUuid = () => {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };

    const database = () => new Promise((resolve, reject) => {
        if (!('indexedDB' in window)) return reject(new Error('This browser does not support local study packs.'));
        const request = indexedDB.open(DATABASE, 2);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE, { keyPath: 'key' });
            if (!request.result.objectStoreNames.contains(ATTEMPTS)) request.result.createObjectStore(ATTEMPTS, { keyPath: 'key' });
        };
        request.onsuccess = () => {
            request.result.onversionchange = () => request.result.close();
            resolve(request.result);
        };
        request.onerror = () => reject(request.error || new Error('Could not open local study storage.'));
    });

    const transaction = async (storeName, mode, action) => {
        const db = await database();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, mode);
            const result = action(tx.objectStore(storeName));
            tx.oncomplete = () => { db.close(); resolve(result?.result); };
            tx.onerror = tx.onabort = () => { db.close(); reject(tx.error || new Error('Could not update local study storage.')); };
        });
    };

    const getAll = (storeName = STORE) => transaction(storeName, 'readonly', (store) => store.getAll());
    const put = (item, storeName = STORE) => transaction(storeName, 'readwrite', (store) => store.put(item));
    const remove = (key, storeName = STORE) => transaction(storeName, 'readwrite', (store) => store.delete(key));
    const rememberUser = (userId) => {
        try { localStorage.setItem(CURRENT_USER_KEY, userId); } catch { /* IndexedDB packs remain partitioned, but cannot be selected in the offline reader. */ }
    };
    const currentUser = () => {
        try { return localStorage.getItem(CURRENT_USER_KEY); } catch { return null; }
    };

    const node = (tag, content, className = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (content !== undefined && content !== null) element.textContent = String(content);
        return element;
    };

    const appendFormattedSourceText = (container, text) => {
        const pattern = /\\(subsubsection|subsection|section)\*?\s*\{([^{}]*)\}/gu;
        const headingLevels = { section: 4, subsection: 5, subsubsection: 6 };
        let cursor = 0;
        let formatted = false;

        for (const match of text.matchAll(pattern)) {
            formatted = true;
            const offset = match.index;
            if (offset > cursor) container.append(document.createTextNode(text.slice(cursor, offset)));
            container.append(node(`h${headingLevels[match[1]]}`, match[2].trim()));
            cursor = offset + match[0].length;
        }

        if (!formatted) {
            container.append(document.createTextNode(text));
            return false;
        }
        if (cursor < text.length) container.append(document.createTextNode(text.slice(cursor)));

        return true;
    };

    const enableAppShell = async () => {
        if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
            try {
                await navigator.serviceWorker.register('/sw.js');
                await navigator.serviceWorker.ready;
                return true;
            } catch {
                return false;
            }
        }
        return false;
    };
    const appShellReady = enableAppShell();

    const controls = document.querySelector('[data-offline-controls]');
    if (controls) {
        const userId = String(controls.dataset.userId);
        const spaceId = String(controls.dataset.spaceId);
        const key = `${userId}:${spaceId}`;
        const status = controls.querySelector('[data-offline-status]');
        const downloadButton = controls.querySelector('[data-offline-download]');
        const openLink = controls.querySelector('[data-offline-open]');
        const deleteButton = controls.querySelector('[data-offline-delete]');
        rememberUser(userId);

        const renderSavedState = async () => {
            try {
                const pack = (await getAll()).find((item) => item.key === key);
                if (pack) {
                    const shellReady = await appShellReady;
                    status.textContent = shellReady
                        ? `Saved on this device · ${pack.sizeLabel} · study pack version ${pack.version}.`
                        : `Saved on this device · ${pack.sizeLabel}. The offline reader could not be installed in this browser.`;
                    openLink.hidden = !shellReady;
                    openLink.href = `/offline-study?space=${encodeURIComponent(spaceId)}`;
                    deleteButton.hidden = false;
                } else {
                    status.textContent = 'This study path has not been downloaded on this device.';
                    openLink.hidden = true;
                    deleteButton.hidden = true;
                }
            } catch (error) {
                status.textContent = error.message;
                downloadButton.disabled = true;
            }
        };

        downloadButton.addEventListener('click', async () => {
            downloadButton.disabled = true;
            status.textContent = 'Downloading the text study pack. Keep this page open until it finishes.';
            try {
                const response = await fetch(controls.dataset.packUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error(response.status === 403 ? 'This study pack is not available to this account.' : 'The study pack could not be downloaded. Reconnect and try again.');
                }
                const data = await response.json();
                if (String(data.space?.id) !== spaceId || !Array.isArray(data.concepts) || !Number.isInteger(data.version)) {
                    throw new Error('The study pack could not be verified. Nothing was saved.');
                }
                const bytes = new Blob([JSON.stringify(data)]).size;
                const sizeLabel = bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
                await put({ key, userId, spaceId, version: data.version, savedAt: new Date().toISOString(), sizeLabel, data });
                const shellReady = await appShellReady;
                status.textContent = shellReady
                    ? `Download complete. ${sizeLabel} saved on this device. You can open it without internet.`
                    : `Study pack saved (${sizeLabel}), but this browser could not install the offline reader. Try a supported browser while connected.`;
                openLink.hidden = !shellReady;
                openLink.href = `/offline-study?space=${encodeURIComponent(spaceId)}`;
                deleteButton.hidden = false;
            } catch (error) {
                status.textContent = error.message || 'The study pack could not be saved on this device.';
            } finally {
                downloadButton.disabled = false;
            }
        });

        deleteButton.addEventListener('click', async () => {
            if (!window.confirm('Delete this downloaded study pack from this device?')) return;
            try {
                await remove(key);
                status.textContent = 'Downloaded study pack deleted from this device.';
                await renderSavedState();
            } catch (error) {
                status.textContent = error.message;
            }
        });

        renderSavedState();
    }

    const reader = document.querySelector('[data-offline-reader]');
    if (reader) {
        const status = reader.querySelector('[data-reader-status]');
        const list = reader.querySelector('[data-pack-list]');
        const content = reader.querySelector('[data-pack-content]');
        const syncButton = reader.querySelector('[data-sync-now]');
        const syncStatus = reader.querySelector('[data-sync-status]');
        const activeUser = currentUser();
        const selectedSpace = new URLSearchParams(location.search).get('space');
        let syncInProgress = false;
        let retryCount = 0;
        let retryTimer = null;

        const syncMessage = (message) => { syncStatus.textContent = message; };
        const addResultsLink = (container, attempt) => {
            if (!attempt.resultUrl || !navigator.onLine) return;
            const link = node('a', 'View graded feedback');
            link.href = attempt.resultUrl;
            link.className = 'offline-result-link';
            container.append(link);
        };

        const renderAttemptQueue = async () => {
            const saved = (await getAll(ATTEMPTS))
                .filter((attempt) => activeUser && attempt.userId === activeUser)
                .sort((first, second) => second.createdAt.localeCompare(first.createdAt));
            syncButton.disabled = !navigator.onLine || !saved.some((attempt) => attempt.status === 'pending');
            const pending = saved.filter((attempt) => attempt.status === 'pending').length;
            syncMessage(pending
                ? `${pending} answer set${pending === 1 ? '' : 's'} saved on this device · Awaiting sync.`
                : saved.length ? 'All saved answers have synced.' : 'No answers are waiting to sync.');
            content.querySelectorAll('[data-attempt-receipt]').forEach((receipt) => {
                const attempt = saved.find((item) => item.assessmentId === receipt.dataset.attemptReceipt);
                if (!attempt) return;
                receipt.replaceChildren();
                if (attempt.status === 'synced') {
                    receipt.append(node('p', 'Synced. Your answers were graded and saved to your learning progress.'));
                    addResultsLink(receipt, attempt);
                } else {
                    receipt.append(node('p', 'Saved on this device · Awaiting sync.'));
                }
            });
        };

        const scheduleRetry = () => {
            if (retryCount >= 4 || retryTimer) {
                syncMessage('Answers are still saved on this device. Use Sync now when your connection is ready.');
                return;
            }
            const delay = Math.min(30000, 2000 * (2 ** retryCount));
            retryCount += 1;
            syncMessage(`Connection interrupted. Your answers are safe here; retrying in ${Math.ceil(delay / 1000)} seconds.`);
            retryTimer = window.setTimeout(() => {
                retryTimer = null;
                syncPending(false);
            }, delay);
        };

        const syncPending = async (manual = false) => {
            if (syncInProgress) return;
            if (!navigator.onLine) {
                syncMessage('You are offline. Answers remain saved on this device until you reconnect.');
                return;
            }
            syncInProgress = true;
            syncButton.disabled = true;
            try {
                const contextResponse = await fetch(SYNC_CONTEXT_URL, {
                    credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store',
                });
                const contextType = contextResponse.headers.get('content-type') || '';
                if (!contextResponse.ok || !contextType.includes('application/json')) {
                    syncMessage('Sign in to the same account while connected. Your saved answers have not been removed.');
                    return;
                }
                const context = await contextResponse.json();
                const queued = (await getAll(ATTEMPTS)).filter((attempt) => attempt.status === 'pending');
                const otherAccount = queued.some((attempt) => String(attempt.userId) !== String(context.user_id));
                const mine = queued.filter((attempt) => String(attempt.userId) === String(context.user_id));
                if (otherAccount && mine.length === 0) {
                    syncMessage('These saved answers belong to another account. Sign in to that same account to sync them.');
                    return;
                }
                for (const attempt of mine) {
                    const response = await fetch(attempt.submitUrl, {
                        method: 'POST', credentials: 'same-origin', cache: 'no-store',
                        headers: {
                            Accept: 'application/json', 'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': context.csrf_token,
                        },
                        body: JSON.stringify({
                            attempt_uuid: attempt.attemptUuid,
                            answers: attempt.answers,
                            responses: attempt.responses,
                        }),
                    });
                    const responseType = response.headers.get('content-type') || '';
                    if (response.status === 401 || response.status === 419 || response.redirected || !responseType.includes('application/json')) {
                        syncMessage('Your session needs attention. Sign in to the same account; your answers remain saved here.');
                        return;
                    }
                    if (response.status === 429 || response.status >= 500) {
                        scheduleRetry();
                        return;
                    }
                    if (!response.ok) {
                        syncMessage(response.status === 422
                            ? 'These answers could not be validated against the saved quiz. They remain on this device; contact support before deleting this pack.'
                            : 'The server could not accept these answers. They remain saved on this device.');
                        return;
                    }
                    const result = await response.json();
                    if (result.status !== 'synced' || !result.attempt_id) {
                        syncMessage('The server response could not be verified. Your answers remain saved here.');
                        return;
                    }
                    attempt.status = 'synced';
                    attempt.syncedAt = new Date().toISOString();
                    attempt.resultUrl = result.result_url;
                    attempt.serverAttemptId = result.attempt_id;
                    await put(attempt, ATTEMPTS);
                    const receipt = content.querySelector(`[data-attempt-receipt="${CSS.escape(attempt.key)}"]`);
                    if (receipt) {
                        receipt.replaceChildren(node('p', 'Synced. Your answers were graded and saved to your learning progress.'));
                        addResultsLink(receipt, attempt);
                    }
                }
                retryCount = 0;
                if (retryTimer) window.clearTimeout(retryTimer);
                retryTimer = null;
                await renderAttemptQueue();
            } catch {
                scheduleRetry();
            } finally {
                syncInProgress = false;
                getAll(ATTEMPTS).then((attempts) => {
                    syncButton.disabled = !navigator.onLine || !attempts.some((attempt) =>
                        attempt.status === 'pending' && attempt.userId === activeUser,
                    );
                }).catch(() => { syncButton.disabled = !navigator.onLine; });
            }
        };

        const showPack = (pack) => {
            content.replaceChildren();
            const data = pack.data;
            const heading = node('h2', data.space?.title || 'Downloaded study pack');
            const summary = node('p', `Study pack version ${data.version}. Saved ${new Date(pack.savedAt).toLocaleString()}.`);
            const lessonSection = node('section');
            lessonSection.append(node('h2', 'Your learning path'));
            data.concepts.forEach((concept) => {
                const article = node('article');
                let headingFormattingNoted = false;
                article.append(node('h3', `${concept.position}. ${concept.name}`));
                article.append(node('p', concept.description));
                if (concept.prerequisites?.length) article.append(node('p', `Builds on: ${concept.prerequisites.join(', ')}`));
                concept.sources?.forEach((source) => {
                    const quote = node('blockquote');
                    quote.append(node('strong', `${source.material} · Page ${source.page ?? 'unknown'}`));
                    quote.append(document.createElement('br'));
                    if (appendFormattedSourceText(quote, source.excerpt) && !headingFormattingNoted) {
                        const note = node('p', 'Section headings are formatted for readability.');
                        note.className = 'offline-format-note';
                        article.append(note);
                        headingFormattingNoted = true;
                    }
                    article.append(quote);
                });
                lessonSection.append(article);
            });
            content.append(heading, summary, lessonSection);

            const explanationSection = node('section');
            explanationSection.append(node('h2', 'Saved tutor explanations'));
            if (!data.explanations?.length) explanationSection.append(node('p', 'No previous tutor explanations are in this pack.'));
            data.explanations?.forEach((explanation) => {
                const article = node('article');
                article.append(node('p', explanation.content));
                explanation.citations?.forEach((citation) => article.append(node('blockquote', `${citation.material} · Page ${citation.page ?? 'unknown'}: ${citation.excerpt}`)));
                explanation.supplementary?.forEach((extra) => article.append(node('p', `Supplementary: ${extra.title}. ${extra.content}`)));
                explanationSection.append(article);
            });
            content.append(explanationSection);

            const questionSection = node('section', undefined, 'offline-questions');
            questionSection.append(node('h2', 'Practice prompts'));
            questionSection.append(node('p', 'Answer while offline. Your answers stay on this device until you reconnect and sync. Objective questions are graded on the server. Written responses are optional and receive provisional AI feedback after syncing.'));
            if (!data.assessments?.length) questionSection.append(node('p', 'No saved quizzes are available in this pack yet.'));
            data.assessments?.forEach((assessment) => {
                const form = node('form', undefined, 'offline-quiz');
                form.dataset.assessmentId = assessment.id;
                form.dataset.submitUrl = assessment.submit_url;
                form.dataset.resultUrl = assessment.result_url;
                form.append(node('h3', assessment.title));
                assessment.questions.forEach((question, index) => {
                    const fieldset = node('fieldset');
                    const legend = node('legend', `${index + 1}. ${question.prompt}`);
                    fieldset.append(legend);
                    if (question.type === 'multiple_choice') {
                        question.options.forEach((option) => {
                            const label = node('label');
                            const input = document.createElement('input');
                            input.type = 'radio';
                            input.name = `answers[${question.id}]`;
                            input.value = option.id;
                            input.required = true;
                            label.append(input, document.createTextNode(`${option.id}. ${option.text}`));
                            fieldset.append(label);
                        });
                    } else if (question.type === 'open_ended') {
                        const label = node('label', 'Your answer (optional)');
                        const textarea = document.createElement('textarea');
                        textarea.name = `responses[${question.id}]`;
                        textarea.required = false;
                        textarea.maxLength = 12000;
                        label.append(textarea);
                        fieldset.append(label);
                    }
                    form.append(fieldset);
                });
                const submit = node('button', 'Save answers on this device');
                submit.type = 'submit';
                form.append(submit);
                const receipt = node('div');
                receipt.dataset.attemptReceipt = assessment.id;
                form.append(receipt);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!activeUser || submit.disabled) return;
                    submit.disabled = true;
                    const answers = {};
                    const responses = {};
                    assessment.questions.forEach((question) => {
                        if (question.type === 'multiple_choice') {
                            const selected = form.querySelector(`input[name="answers[${CSS.escape(question.id)}]"]:checked`);
                            if (selected) answers[question.id] = selected.value;
                        } else if (question.type === 'open_ended') {
                            responses[question.id] = form.querySelector(`textarea[name="responses[${CSS.escape(question.id)}]"]`).value;
                        }
                    });
                    try {
                        const attemptUuid = createAttemptUuid();
                        const key = `${activeUser}:${attemptUuid}`;
                        await put({
                            key, userId: activeUser, spaceId: pack.spaceId, assessmentId: assessment.id,
                            attemptUuid, answers, responses, submitUrl: assessment.submit_url,
                            resultUrl: assessment.result_url, status: 'pending', createdAt: new Date().toISOString(),
                        }, ATTEMPTS);
                        form.querySelectorAll('input,textarea').forEach((field) => { field.disabled = true; });
                        submit.textContent = 'Answers saved';
                        receipt.replaceChildren(node('p', 'Saved on this device · Awaiting sync.'));
                        await renderAttemptQueue();
                        if (navigator.onLine) syncPending(false);
                    } catch {
                        submit.disabled = false;
                        receipt.replaceChildren(node('p', 'Could not save these answers on this device. Check storage space and try again.'));
                    }
                });
                questionSection.append(form);
            });
            content.append(questionSection);
            status.textContent = `Opened ${data.space?.title || 'study pack'} from this device. New AI answers and grading are unavailable offline.`;
            renderAttemptQueue().catch(() => syncMessage('Could not read saved answers on this device.'));
        };

        getAll().then((packs) => {
            const owned = packs.filter((pack) => activeUser && pack.userId === activeUser);
            list.replaceChildren();
            if (!owned.length) {
                status.textContent = 'No study packs for the current account were found on this device. Sign in and download a pack while connected.';
                return;
            }
            owned.forEach((pack) => {
                const item = node('li');
                const button = node('button', `${pack.data.space?.title || 'Study pack'} · ${pack.sizeLabel}`);
                button.type = 'button';
                button.addEventListener('click', () => showPack(pack));
                item.append(button);
                list.append(item);
            });
            const chosen = owned.find((pack) => pack.spaceId === selectedSpace) || owned[0];
            showPack(chosen);
        }).catch((error) => { status.textContent = error.message; });

        syncButton.addEventListener('click', () => syncPending(true));
        window.addEventListener('online', () => { renderAttemptQueue().then(() => syncPending(false)); });
        window.addEventListener('offline', () => {
            syncButton.disabled = true;
            syncMessage('You are offline. Answers remain saved on this device until you reconnect.');
        });
        if (navigator.onLine) window.setTimeout(() => syncPending(false), 0);
    }

    document.querySelectorAll('form[action$="/logout"]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!('indexedDB' in window)) {
                form.submit();
                return;
            }
            const pending = (await getAll(ATTEMPTS).catch(() => [])).filter((attempt) => attempt.status === 'pending').length;
            const unsynced = pending ? ` You also have ${pending} unsynced answer set${pending === 1 ? '' : 's'} that will be deleted.` : '';
            if (!window.confirm(`Signing out will delete downloaded study packs from this browser.${unsynced} Continue?`)) {
                return;
            }
            try {
                await new Promise((resolve, reject) => {
                    const request = indexedDB.deleteDatabase(DATABASE);
                    request.onsuccess = () => resolve();
                    request.onblocked = () => reject(new Error('Close other SelfLearn tabs and try signing out again so downloaded study data can be cleared.'));
                    request.onerror = () => reject(request.error);
                });
                try { localStorage.removeItem(CURRENT_USER_KEY); } catch { /* Signing out still proceeds. */ }
            } catch (error) {
                const status = form.querySelector('[data-offline-logout-status]') || document.querySelector('[data-offline-status]');
                if (status) {
                    status.hidden = false;
                    status.textContent = error.message || 'Downloaded study data could not be cleared. Close other SelfLearn tabs and try signing out again.';
                }
                return;
            }
            form.submit();
        });
    });

})();
