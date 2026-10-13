import { extractReadAloudText } from './read-aloud-content.js';

const readAloudControls = [...document.querySelectorAll('[data-read-aloud-controls]')];

if (readAloudControls.length) {
    const languageNames = { en: 'English', ha: 'Hausa', ig: 'Igbo', yo: 'Yoruba' };
    let active = null;

    const setIdle = (controls, message) => {
        controls.querySelector('[data-read-aloud-pause]').disabled = true;
        controls.querySelector('[data-read-aloud-stop]').disabled = true;
        controls.querySelector('[data-read-aloud-pause]').textContent = 'Pause';
        if (message) controls.querySelector('[data-read-aloud-status]').textContent = message;
    };

    const stopActive = (message = 'Audio stopped. Choose Listen to play it again.') => {
        if (!active) return;
        const previous = active;
        active = null;
        previous.abortController?.abort();
        previous.audio?.pause();
        if (previous.audio) previous.audio.currentTime = 0;
        previous.playButton.disabled = false;
        setIdle(previous.controls, message);
    };

    readAloudControls.forEach((controls) => {
        const playButton = controls.querySelector('[data-read-aloud-play]');
        const pauseButton = controls.querySelector('[data-read-aloud-pause]');
        const stopButton = controls.querySelector('[data-read-aloud-stop]');
        const status = controls.querySelector('[data-read-aloud-status]');
        const audio = controls.querySelector('audio.read-aloud-player');
        let cachedAudio = null;
        let playerObjectUrl = null;

        audio.addEventListener('play', () => {
            if (!active) active = { controls, audio, playButton };
            pauseButton.textContent = 'Pause';
            pauseButton.disabled = false;
            stopButton.disabled = false;
            status.textContent = `Playing audio in ${languageNames[controls.dataset.readAloudLanguage || 'en'] || 'the selected language'}.`;
        });
        audio.addEventListener('pause', () => {
            if (!audio.ended && audio.currentTime > 0) {
                pauseButton.textContent = 'Resume';
                status.textContent = 'Audio paused.';
            }
        });
        audio.addEventListener('ended', () => {
            if (active?.controls === controls) active = null;
            playButton.disabled = false;
            setIdle(controls, 'Audio finished. Choose Listen to replay it or use the audio player controls.');
        });
        audio.addEventListener('error', () => {
            if (active?.controls === controls) active = null;
            playButton.disabled = false;
            setIdle(controls, 'Audio playback failed. The full text remains available on screen. Please try again.');
        });

        playButton.addEventListener('click', async () => {
            const text = extractReadAloudText(document.getElementById(controls.dataset.readAloudTarget));
            const language = controls.dataset.readAloudLanguage || 'en';
            const languageName = languageNames[language] || language;

            if (!text) {
                status.textContent = 'There is no readable text here. The content is still available on screen.';
                return;
            }

            if (active) stopActive('Previous audio stopped.');

            const abortController = new AbortController();
            const current = { controls, abortController, audio, playButton };
            active = current;
            playButton.disabled = true;
            stopButton.disabled = false;
            status.textContent = `Preparing audio in ${languageName}. This may take a moment.`;

            try {
                let blob;
                if (cachedAudio?.text === text && cachedAudio.language === language) {
                    blob = cachedAudio.blob;
                } else {
                    const response = await fetch(controls.dataset.readAloudUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'audio/mpeg, application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ text, language }),
                        signal: abortController.signal,
                    });

                    if (!response.ok) {
                        const error = await response.json().catch(() => ({}));
                        throw new Error(error.message || 'Audio could not be prepared. The full text remains available on screen.');
                    }

                    blob = await response.blob();
                    if (!blob.size) throw new Error('No audio was returned. The full text remains available on screen.');
                    cachedAudio = { text, language, blob };
                }

                if (active !== current) return;
                if (playerObjectUrl) URL.revokeObjectURL(playerObjectUrl);
                playerObjectUrl = URL.createObjectURL(blob);
                audio.src = playerObjectUrl;
                audio.volume = 1;
                audio.muted = false;
                audio.hidden = false;
                audio.load();
                await audio.play();
            } catch (error) {
                if (active !== current) return;
                active = null;
                playButton.disabled = false;
                const message = error.name === 'AbortError'
                    ? 'Audio stopped.'
                    : error.message || 'Audio could not be played. The full text remains available on screen.';
                setIdle(controls, message);
            }
        });

        pauseButton.addEventListener('click', () => {
            if (active?.controls !== controls || !active.audio) return;
            if (active.audio.paused) {
                active.audio.play().then(() => {
                    pauseButton.textContent = 'Pause';
                    status.textContent = `Playing audio in ${languageNames[controls.dataset.readAloudLanguage || 'en'] || 'the selected language'}.`;
                }).catch(() => setIdle(controls, 'Audio could not resume. The full text remains available on screen.'));
            } else {
                active.audio.pause();
                pauseButton.textContent = 'Resume';
                status.textContent = 'Audio paused.';
            }
        });

        stopButton.addEventListener('click', () => {
            if (active?.controls === controls) stopActive();
        });

    });
}
