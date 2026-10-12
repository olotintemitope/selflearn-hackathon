export const extractReadAloudText = (content) => {
    if (!content || content.matches?.('[hidden], [aria-hidden="true"], [inert]')) return '';

    const readableContent = content.cloneNode(true);
    readableContent.querySelectorAll('[hidden], [aria-hidden="true"], [inert]').forEach((element) => element.remove());
    const closedDetails = [
        ...(readableContent.matches?.('details:not([open])') ? [readableContent] : []),
        ...readableContent.querySelectorAll('details:not([open])'),
    ];
    closedDetails.forEach((details) => {
        [...details.children]
            .filter((child) => child.tagName !== 'SUMMARY')
            .forEach((child) => child.remove());
    });
    readableContent.querySelectorAll('[data-read-aloud-controls]').forEach((control) => control.remove());

    return (readableContent.innerText || readableContent.textContent || '').replace(/\s+/gu, ' ').trim();
};
