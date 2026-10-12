const readAloud = document.querySelector('[data-read-aloud]');

if (readAloud) {
    const playButton = readAloud.querySelector('[data-speech-play]');
    const pauseButton = readAloud.querySelector('[data-speech-pause]');
    const stopButton = readAloud.querySelector('[data-speech-stop]');
    const voiceChoice = readAloud.querySelector('[data-voice-choice]');
    const status = readAloud.querySelector('[data-speech-status]');
    const explanation = document.querySelector('#clock-learning-text')?.textContent?.trim() ?? '';
    const speech = window.speechSynthesis;
    let voices = [];
    let activeUtterance = null;

    const refreshVoices = () => {
        if (!speech) return;
        voices = speech.getVoices().filter((voice) => voice.lang.toLowerCase().startsWith('en'));
        const selected = voiceChoice.value;
        voiceChoice.replaceChildren(new Option('Default English voice', ''));
        voices.forEach((voice) => {
            voiceChoice.add(new Option(`${voice.name} (${voice.lang})`, voice.name));
        });
        voiceChoice.value = voices.some((voice) => voice.name === selected) ? selected : '';
    };

    if (!speech || !('SpeechSynthesisUtterance' in window)) {
        playButton.disabled = true;
        pauseButton.disabled = true;
        stopButton.disabled = true;
        voiceChoice.disabled = true;
        status.textContent = 'Read-aloud is unavailable in this browser. The explanation is available above to read.';
    } else {
        refreshVoices();
        speech.addEventListener('voiceschanged', refreshVoices);

        playButton.addEventListener('click', () => {
            activeUtterance = null;
            speech.cancel();
            const utterance = new SpeechSynthesisUtterance(explanation);
            activeUtterance = utterance;
            utterance.lang = 'en';
            utterance.rate = 0.9;
            utterance.voice = voices.find((voice) => voice.name === voiceChoice.value) ?? voices[0] ?? null;
            utterance.onstart = () => {
                if (activeUtterance !== utterance) return;
                pauseButton.disabled = false;
                stopButton.disabled = false;
                pauseButton.textContent = 'Pause';
                status.textContent = 'Reading the explanation aloud in English.';
            };
            utterance.onend = () => {
                if (activeUtterance !== utterance) return;
                activeUtterance = null;
                pauseButton.disabled = true;
                stopButton.disabled = true;
                status.textContent = 'Read-aloud finished. You can replay it or read the explanation above.';
            };
            utterance.onerror = () => {
                if (activeUtterance !== utterance) return;
                activeUtterance = null;
                pauseButton.disabled = true;
                stopButton.disabled = true;
                status.textContent = 'This voice could not read the explanation. The text is still available above.';
            };
            speech.speak(utterance);
        });

        pauseButton.addEventListener('click', () => {
            if (speech.paused) {
                speech.resume();
                pauseButton.textContent = 'Pause';
                status.textContent = 'Continuing to read the explanation aloud.';
            } else {
                speech.pause();
                pauseButton.textContent = 'Resume';
                status.textContent = 'Read-aloud paused.';
            }
        });

        stopButton.addEventListener('click', () => {
            activeUtterance = null;
            speech.cancel();
            pauseButton.disabled = true;
            stopButton.disabled = true;
            pauseButton.textContent = 'Pause';
            status.textContent = 'Read-aloud stopped. Choose Read aloud to replay it.';
        });
    }
}
