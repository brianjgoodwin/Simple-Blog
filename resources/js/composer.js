/*
 * The post/page composer: Write/Preview tabs, auto-growing textarea,
 * word count, Ctrl/Cmd-S to save, and (drafts only) debounced autosave.
 *
 * Registered as Alpine.data('composer'); the Blade side passes config:
 *   previewUrl  — endpoint that renders Markdown server-side (always set)
 *   autosaveUrl — the post's update endpoint, or null to disable autosave
 *                 (null for new posts and for PUBLISHED posts — a published
 *                 post must never have half-typed edits pushed live by a timer)
 */
export default function composer(config) {
    return {
        tab: 'write',
        body: '',
        previewHtml: '',
        previewStale: true,

        // Autosave state, shown in the status line.
        autosaveStatus: '', // '', 'saving', 'saved', 'error'
        savedAtLabel: '',
        autosaveTimer: null,

        init() {
            // The textarea's content is server-rendered (so the form works
            // without JS); read it as the starting state.
            this.body = this.$refs.body?.value ?? '';
            this.$nextTick(() => this.growTextarea());

            // Ctrl/Cmd-S = submit the form (a real, deliberate save).
            this.$el.closest('form').addEventListener('keydown', (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                    e.preventDefault();
                    e.target.closest('form').requestSubmit();
                }
            });
        },

        wordCount() {
            const words = this.body.trim().split(/\s+/).filter(Boolean);
            return words.length;
        },

        growTextarea() {
            const el = this.$refs.body;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        },

        onInput() {
            this.growTextarea();
            this.previewStale = true;
            this.scheduleAutosave();
        },

        async showPreview() {
            this.tab = 'preview';
            if (!this.previewStale) return;

            const response = await fetch(config.previewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ body: this.body }),
            });

            if (response.ok) {
                this.previewHtml = await response.text();
                this.previewStale = false;
            } else {
                this.previewHtml = '<p class="text-red-600">Preview failed. Your text is safe — this only affects the preview.</p>';
            }
        },

        scheduleAutosave() {
            if (!config.autosaveUrl) return;
            clearTimeout(this.autosaveTimer);
            this.autosaveTimer = setTimeout(() => this.autosave(), 2500);
        },

        async autosave() {
            this.autosaveStatus = 'saving';

            const title = this.$el.closest('form').querySelector('#title').value;

            try {
                const response = await fetch(config.autosaveUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ title: title, body: this.body }),
                });

                if (!response.ok) throw new Error('autosave failed: ' + response.status);

                this.autosaveStatus = 'saved';
                this.savedAtLabel = new Date().toLocaleTimeString();
            } catch (e) {
                // Not fatal: the text is still in the textarea and a manual
                // save will retry. Just tell the author autosave didn't land.
                this.autosaveStatus = 'error';
            }
        },
    };
}
