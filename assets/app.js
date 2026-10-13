document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('[data-confirm]') && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

const noteForm = document.querySelector('.note-form');
if (noteForm) {
    const editor = noteForm.querySelector('.rich-editor');
    const source = noteForm.querySelector('.editor-source');
    noteForm.querySelectorAll('[data-command]').forEach((button) => {
        button.addEventListener('click', () => {
            editor.focus();
            document.execCommand(button.dataset.command, false);
        });
    });
    noteForm.addEventListener('submit', () => {
        const filename = noteForm.querySelector('[name="note_name"]').value.trim().toLowerCase();
        source.value = filename.endsWith('.html') || filename.endsWith('.htm')
            ? editor.innerHTML
            : editor.innerText;
    });
}
