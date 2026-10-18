import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';
import { setMetadataBadge } from '../badges.js';

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str ?? ''));
    return div.innerHTML;
}

export default class extends Controller {
    static values = {
        libraryId: Number,
        locale: String,
    };

    _batchFiles = [];
    _batchIndex = 0;
    _batchDone = 0;
    _batchSkipped = 0;

    async connect() {
        this.translator = await Translator.get(this.localeValue || 'en');
    }

    async smartPreview(event) {
        const button = event.currentTarget;
        button.disabled = true;

        const selectedPaths = this._selectedPaths();

        if (selectedPaths.length <= 1) {
            const path = this.element.dataset.currentPath;
            if (!path) {
                this._showError(this.translator.trans('js.rename.select_file'));
                button.disabled = false;
                return;
            }
            await this._doPreview(path);
            button.disabled = false;
        } else {
            this._batchFiles = selectedPaths;
            this._batchIndex = 0;
            this._batchDone = 0;
            this._batchSkipped = 0;
            button.disabled = false;
            this._showBatchRename();
        }
    }

    async preview(event) {
        this._currentPath = event.params.path;
        await this._doPreview(this._currentPath);
    }

    async previewCurrent(event) {
        const path = this.element.dataset.currentPath;
        if (!path) {
            this._showError(this.translator.trans('js.rename.select_file'));
            return;
        }
        await this._doPreview(path);
    }

    _selectedPaths() {
        const sel = document.querySelectorAll('tr.selected, .file-card.selected');
        return Array.from(sel).map(el => el.dataset.scrapePathParam).filter(Boolean);
    }

    async _doPreview(path, expression = null) {
        this._currentPath = path;

        try {
            const data = await this._fetchPreview(path, expression);
            if (data.error) {
                this._showError(data.error);
                return;
            }
            this._showPreview(data);
        } catch (error) {
            this._showError(error.message);
        }
    }

    async _fetchPreview(path, expression = null) {
        const payload = {
            library_id: this.libraryIdValue,
            path: path,
        };
        if (expression) {
            payload.expression = expression;
        }
        const response = await fetch('/api/files/rename/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        return response.json();
    }

    async _fetchExecute(path, newPath, expression = null) {
        const payload = {
            library_id: this.libraryIdValue,
            path: path,
            new_path: newPath,
        };
        if (expression) {
            payload.expression = expression;
        }
        const response = await fetch('/api/files/rename/execute', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        return response.json();
    }

    _showPreview(data) {
        const modal = this._getModal();
        if (!modal) return;

        const currentExpr = data.current_expression || '';

        modal.contentTarget.innerHTML = `
            <div class="modal-header">
                <h2>${this.translator.trans('js.rename.preview_title')}</h2>
                <button type="button" class="modal-close" data-action="modal#close">&times;</button>
            </div>
            <div class="modal-body">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding-right:1rem; vertical-align:top;"><strong>${this.translator.trans('js.rename.current')}</strong></td>
                        <td style="word-break:break-word;"><code>${data.original_path}</code></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.new')}</strong></td>
                        <td style="padding-top:0.5rem;"><input type="text" id="rename-new-path" value="${escapeHtml(data.new_path)}"
                             style="width:100%; padding:0.5rem; border:1px solid #ced4da; border-radius:4px; font-family:monospace; box-sizing:border-box;"></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.expression')}</strong></td>
                        <td style="padding-top:0.5rem;"><input type="text" id="rename-expression" value="${escapeHtml(currentExpr)}"
                             placeholder="Saisir une expression (optionnel)"
                             data-action="input->${this.identifier}#rePreview"
                             style="width:100%; padding:0.5rem; border:1px solid #ced4da; border-radius:4px; font-family:monospace; box-sizing:border-box;"></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.preview')}</strong></td>
                        <td style="padding-top:0.5rem;"><code id="rename-preview-output" style="padding:0.5rem; display:block; background:var(--bg-secondary,#f5f5f5); border-radius:4px; font-family:monospace; min-height:1.2rem; word-break:break-word;">${escapeHtml(data.new_path)}</code></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-action="modal#close">${this.translator.trans('js.rename.cancel')}</button>
                <button type="button" class="btn btn-primary" data-action="${this.identifier}#execute">${this.translator.trans('js.rename.confirm')}</button>
            </div>
        `;

        modal.openValue = true;
    }

    async rePreview(event) {
        clearTimeout(this._repreviewTimer);
        this._repreviewTimer = setTimeout(async () => {
            const exprInput = document.getElementById('rename-expression');
            if (!exprInput || !this._currentPath) return;
            const expression = exprInput.value.trim() || null;

            try {
                const data = await this._fetchPreview(this._currentPath, expression);
                if (data.error) {
                    return;
                }
                const pathInput = document.getElementById('rename-new-path');
                if (pathInput) {
                    pathInput.value = data.new_path;
                }
                const previewOut = document.getElementById('rename-preview-output');
                if (previewOut) {
                    previewOut.textContent = data.new_path;
                }
                const exprOut = document.getElementById('rename-expression');
                if (exprOut && data.current_expression !== undefined) {
                    exprOut.value = data.current_expression;
                }
            } catch (error) {
                // ignore preview errors during typing
            }
        }, 300);
    }

    async execute(event) {
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = this.translator.trans('js.rename.renaming');

        const pathInput = document.getElementById('rename-new-path');
        const customPath = pathInput ? pathInput.value.trim() : null;
        const exprInput = document.getElementById('rename-expression');
        const expression = exprInput ? exprInput.value.trim() : null;

        const filename = this._currentPath.split('/').pop() || this._currentPath;
        this._setStatusBar(`${this.translator.trans('js.rename.renaming')} ${filename}`);

        try {
            const data = await this._fetchExecute(this._currentPath, customPath, expression);

            if (data.new_path) {
                this._closeModalAndRefresh(this.translator.trans('js.rename.renamed'));
            } else {
                this._setStatusBar('');
                this._showError(data.error || this.translator.trans('js.rename.error'));
                button.disabled = false;
                button.textContent = this.translator.trans('js.rename.confirm');
            }
        } catch (error) {
            this._setStatusBar('');
            this._showError(error.message);
            button.disabled = false;
            button.textContent = this.translator.trans('js.rename.confirm');
        }
    }

    async convert(event) {
        const convertBtn = event.currentTarget;
        const paths = this._selectedPaths();

        if (paths.length === 0) return;

        if (paths.length === 1) {
            const path = paths[0];
            convertBtn.disabled = true;
            convertBtn.textContent = this.translator.trans('js.rename.converting');

            const filename = path.split('/').pop() || path;
            this._setStatusBar(`${this.translator.trans('js.rename.converting')} ${filename}`);

            try {
                const response = await fetch('/api/files/convert', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        path: path,
                    }),
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || this.translator.trans('js.rename.error'));
                }

                this._setStatusBar('');
                this._updateCardForConverted(path, this.libraryIdValue);
                setTimeout(() => {
                    Turbo.visit(window.location.href, { action: 'replace' });
                }, 1500);
            } catch (error) {
                convertBtn.disabled = false;
                convertBtn.textContent = this.translator.trans('js.rename.convert_to_cbz');
                this._setStatusBar('');

                const flash = document.getElementById('flash');
                if (flash) {
                    flash.innerHTML = `<div class="flash flash-error">${escapeHtml(error.message)}</div>`;
                }
            }
        } else {
            convertBtn.disabled = true;
            convertBtn.textContent = this.translator.trans('js.rename.converting');

            const convertible = paths.filter(p => {
                const ext = p.split('.').pop().toLowerCase();
                return ['cbr', 'pdf', 'epub'].includes(ext);
            });

            if (convertible.length === 0) {
                convertBtn.disabled = false;
                convertBtn.textContent = this.translator.trans('js.rename.convert_to_cbz');
                return;
            }

            this._setStatusBar(`${this.translator.trans('js.rename.converting')} 0/${convertible.length}`);

            try {
                const response = await fetch('/api/files/convert/batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        paths: convertible,
                    }),
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || this.translator.trans('js.rename.error'));
                }

                this._pollTaskProgress(convertBtn);
            } catch (error) {
                convertBtn.disabled = false;
                convertBtn.textContent = this.translator.trans('js.rename.convert_to_cbz');
                this._setStatusBar('');

                const flash = document.getElementById('flash');
                if (flash) {
                    flash.innerHTML = `<div class="flash flash-error">${escapeHtml(error.message)}</div>`;
                }
            }
        }
    }

    async _pollTaskProgress(convertBtn) {
        if (!this._convertedTaskIds) this._convertedTaskIds = new Set();

        let interval = setInterval(async () => {
            try {
                const statusRes = await fetch('/api/tasks/status');
                const statusData = await statusRes.json();
                const done = (statusData.completed || 0) + (statusData.failed || 0);
                const pending = (statusData.pending || 0) + (statusData.processing || 0);

                await this._checkCompletedTasks();

                if (pending > 0) {
                    this._setStatusBar(`${this.translator.trans('js.rename.converting')} ${done}/${done + pending}`);
                } else {
                    clearInterval(interval);
                    this._setStatusBar(`${this.translator.trans('js.rename.conversion_done')} (${statusData.completed || 0} ${this.translator.trans('js.rename.success')}, ${statusData.failed || 0} ${this.translator.trans('js.rename.failed')})`);
                    convertBtn.disabled = false;
                    convertBtn.textContent = this.translator.trans('js.rename.convert_to_cbz');
                    setTimeout(() => {
                        Turbo.visit(window.location.href, { action: 'replace' });
                    }, 2000);
                }
            } catch {
                clearInterval(interval);
                convertBtn.disabled = false;
                convertBtn.textContent = this.translator.trans('js.rename.convert_to_cbz');
                this._setStatusBar('');
            }
        }, 2000);
    }

    async _checkCompletedTasks() {
        try {
            const response = await fetch('/api/tasks/list');
            const tasks = await response.json();
            for (const task of tasks) {
                if (task.type === 'convert' && task.status === 'completed' && !this._convertedTaskIds.has(task.id)) {
                    this._convertedTaskIds.add(task.id);
                    this._updateCardAfterConversion(task);
                }
                if (task.type === 'scrape' && (task.status === 'completed' || task.status === 'failed') && !this._convertedTaskIds.has('scrape-'+task.id)) {
                    this._convertedTaskIds.add('scrape-'+task.id);
                    this._updateCardAfterScrape(task);
                }
            }
        } catch {}
    }

    _updateCardAfterScrape(task) {
        const card = document.querySelector(`[data-path="${CSS.escape(task.path)}"]`);
        if (!card) return;

        const badge = card.querySelector('.file-card-body .badge');
        if (badge) {
            if (task.status === 'failed') {
                setMetadataBadge(badge, 'failed', this.translator);
            } else {
                setMetadataBadge(badge, 'ok', this.translator);
            }
        }
    }

    _updateCardAfterConversion(task) {
        const cbzPath = task.path.replace(/\.(cbr|pdf|epub)$/i, '.cbz');
        this._updateCardForConverted(task.path, this.libraryIdValue, cbzPath);
    }

    _updateCardForConverted(originalPath, libraryId, cbzPath) {
        const card = document.querySelector(`[data-path="${CSS.escape(originalPath)}"]`);
        if (!card) return;

        const computedCbzPath = cbzPath || originalPath.replace(/\.(cbr|pdf|epub)$/i, '.cbz');
        const cbzName = computedCbzPath.split('/').pop() || computedCbzPath;
        const ts = Date.now();

        card.dataset.scrapePathParam = computedCbzPath;
        card.dataset.path = computedCbzPath;
        card.classList.remove('file-card-unreadable');

        const cover = card.querySelector('.file-card-cover');
        if (cover) {
            const icon = cover.querySelector('.file-icon');
            const existingImg = cover.querySelector('img');

            if (existingImg) {
                existingImg.src = `/api/files/cover?library_id=${libraryId}&path=${encodeURIComponent(computedCbzPath)}&t=${ts}`;
                existingImg.style.display = '';
                existingImg.onerror = function () {
                    this.style.display = 'none';
                    const fb = this.parentElement.querySelector('.file-icon');
                    if (fb) fb.style.display = 'flex';
                };
                if (icon) icon.style.display = 'none';
            } else {
                const img = document.createElement('img');
                img.src = `/api/files/cover?library_id=${libraryId}&path=${encodeURIComponent(computedCbzPath)}&t=${ts}`;
                img.alt = '';
                img.loading = 'lazy';
                img.onerror = function () {
                    this.style.display = 'none';
                    const fb = this.parentElement.querySelector('.file-icon');
                    if (fb) fb.style.display = 'flex';
                };
                if (icon) {
                    icon.style.display = 'none';
                    cover.insertBefore(img, icon);
                } else {
                    cover.prepend(img);
                }
            }
        }

        const nameEl = card.querySelector('.file-card-name');
        if (nameEl) nameEl.textContent = cbzName;

        const badge = card.querySelector('.file-card-body .badge');
        if (badge) {
            setMetadataBadge(badge, 'ok', this.translator);
        } else {
            const body = card.querySelector('.file-card-body');
            if (body) {
                const newBadge = document.createElement('span');
                setMetadataBadge(newBadge, 'ok', this.translator);
                body.appendChild(newBadge);
            }
        }
    }

    /* ---- Batch rename ---- */

    _showBatchRename() {
        this._showBatchRenamePreview();
    }

    async _showBatchRenamePreview() {
        const path = this._batchFiles[this._batchIndex];
        const filename = path.split('/').pop() || path;
        const total = this._batchFiles.length;
        const index = this._batchIndex;

        this._setStatusBar(`${this.translator.trans('js.rename.renaming')} ${index + 1}/${total}  ${filename}`);

        const modal = this._getModal();
        if (!modal) return;

        modal.contentTarget.innerHTML = `
            <div class="modal-header">
                <h2>${this.translator.trans('js.rename.batch_title')} ${index + 1}/${total}</h2>
                <button type="button" class="modal-close" data-action="${this.identifier}#batchCancel">&times;</button>
            </div>
            <div class="modal-body" id="batch-rename-body">
                <p style="margin-bottom: 1rem; color: #666;">
                    <strong>${this.translator.trans('js.rename.file')}</strong> ${escapeHtml(filename)}
                </p>
                <div id="batch-rename-spinner"><div class="spinner"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-action="${this.identifier}#batchSkip">${this.translator.trans('js.rename.skip')}</button>
                <button type="button" class="btn" data-action="${this.identifier}#batchCancel">${this.translator.trans('js.rename.cancel')}</button>
                <button type="button" class="btn btn-primary" data-action="${this.identifier}#batchExecute" disabled>${this.translator.trans('js.rename.confirm')}</button>
                <button type="button" class="btn btn-success" data-action="${this.identifier}#batchRenameAll">${this.translator.trans('js.rename.rename_all')}</button>
            </div>
        `;

        modal.openValue = true;

        try {
            const data = await this._fetchPreview(path);

            if (data.error) {
                document.getElementById('batch-rename-body').innerHTML = `
                    <p style="color: #c62828; margin-top: 1rem;">${escapeHtml(data.error)}</p>
                `;
                return;
            }

            this._batchCurrentPreview = data;
            this._currentPath = path;

            const currentExpr = data.current_expression || '';

            document.getElementById('batch-rename-body').innerHTML = `
                <p style="margin-bottom: 1rem; color: #666;">
                    <strong>${this.translator.trans('js.rename.file')}</strong> ${escapeHtml(filename)}
                </p>
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding-right:1rem; vertical-align:top;"><strong>${this.translator.trans('js.rename.current')}</strong></td>
                        <td style="word-break:break-word;"><code>${data.original_path}</code></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.new')}</strong></td>
                        <td style="padding-top:0.5rem;"><input type="text" id="rename-new-path" value="${escapeHtml(data.new_path)}"
                             style="width:100%; padding:0.5rem; border:1px solid #ced4da; border-radius:4px; font-family:monospace; box-sizing:border-box;"></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.expression')}</strong></td>
                        <td style="padding-top:0.5rem;"><input type="text" id="rename-expression" value="${escapeHtml(currentExpr)}"
                             placeholder="Saisir une expression (optionnel)"
                             data-action="input->${this.identifier}#batchRePreview"
                             style="width:100%; padding:0.5rem; border:1px solid #ced4da; border-radius:4px; font-family:monospace; box-sizing:border-box;"></td>
                    </tr>
                    <tr>
                        <td style="width:1%; white-space:nowrap; padding:0.5rem 1rem 0 0; vertical-align:top;"><strong>${this.translator.trans('js.rename.preview')}</strong></td>
                        <td style="padding-top:0.5rem;"><code id="rename-preview-output" style="padding:0.5rem; display:block; background:var(--bg-secondary,#f5f5f5); border-radius:4px; font-family:monospace; min-height:1.2rem; word-break:break-word;">${escapeHtml(data.new_path)}</code></td>
                    </tr>
                </table>
            `;

            const confirmBtn = document.querySelector('[data-action$="#batchExecute"]');
            if (confirmBtn) confirmBtn.disabled = false;
        } catch (error) {
            document.getElementById('batch-rename-body').innerHTML = `
                <p style="color: #c62828; margin-top: 1rem;">${escapeHtml(error.message)}</p>
            `;
        }
    }

    async batchRePreview(event) {
        clearTimeout(this._batchRepreviewTimer);
        this._batchRepreviewTimer = setTimeout(async () => {
            const exprInput = document.getElementById('rename-expression');
            if (!exprInput || !this._currentPath) return;
            const expression = exprInput.value.trim() || null;

            try {
                const data = await this._fetchPreview(this._currentPath, expression);
                if (data.error) {
                    return;
                }
                const pathInput = document.getElementById('rename-new-path');
                if (pathInput) {
                    pathInput.value = data.new_path;
                }
                const previewOut = document.getElementById('rename-preview-output');
                if (previewOut) {
                    previewOut.textContent = data.new_path;
                }
            } catch (error) {
                // ignore
            }
        }, 300);
    }

    async batchExecute(event) {
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = this.translator.trans('js.rename.renaming');

        const pathInput = document.getElementById('rename-new-path');
        const customPath = pathInput ? pathInput.value.trim() : null;
        const exprInput = document.getElementById('rename-expression');
        const expression = exprInput ? exprInput.value.trim() : null;

        try {
            const data = await this._fetchExecute(this._currentPath, customPath, expression);
            if (data.new_path) {
                this._batchDone++;
                this._showToast(this.translator.trans('js.rename.renamed'), 'success');
            } else {
                this._batchSkipped++;
                this._showToast(data.error || this.translator.trans('js.rename.error'), 'error');
            }
        } catch (error) {
            this._batchSkipped++;
            this._showToast(error.message, 'error');
        }

        setTimeout(() => this._advanceBatch(), 800);
    }

    batchSkip(event) {
        this._batchSkipped++;
        this._advanceBatch();
    }

    batchCancel(event) {
        if (this._batchIndex > 0 || this._batchDone > 0) {
            this._showBatchSummary();
        } else {
            const modal = this._getModal();
            if (modal) modal.close();
        }
    }

    _advanceBatch() {
        this._batchIndex++;

        if (this._batchIndex >= this._batchFiles.length) {
            this._showBatchSummary();
        } else {
            this._showBatchRenamePreview();
        }
    }

    _showBatchSummary() {
        this._setStatusBar('');

        const modal = this._getModal();
        if (!modal) return;

        modal.contentTarget.innerHTML = `
            <div class="modal-header">
                <h2>${this.translator.trans('js.rename.batch_done')}</h2>
                <button type="button" class="modal-close" data-action="${this.identifier}#_closeAndRefresh">&times;</button>
            </div>
            <div class="modal-body">
                <p>${this._batchDone} ${this._batchDone > 1 ? this.translator.trans('js.rename.files_renamed') : this.translator.trans('js.rename.file_renamed')}.</p>
                ${this._batchSkipped > 0 ? `<p>${this._batchSkipped} ${this._batchSkipped > 1 ? this.translator.trans('js.rename.files_skipped') : this.translator.trans('js.rename.file_skipped')}.</p>` : ''}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-action="${this.identifier}#_closeAndRefresh">${this.translator.trans('js.rename.done')}</button>
            </div>
        `;

        modal.openValue = true;
    }

    async batchRenameAll(event) {
        const modal = this._getModal();
        if (modal) modal.close();

        const exprInput = document.getElementById('rename-expression');
        const expression = exprInput ? exprInput.value.trim() : null;

        let done = 0;
        let skipped = 0;
        const total = this._batchFiles.length;

        this._setStatusBar(`${this.translator.trans('js.rename.renaming')} 0/${total}`);

        for (let i = 0; i < total; i++) {
            const path = this._batchFiles[i];
            const filename = path.split('/').pop() || path;
            this._setStatusBar(`${this.translator.trans('js.rename.renaming')} ${i + 1}/${total}  ${filename}`);

            try {
                const preview = await this._fetchPreview(path, expression);
                if (preview.error) {
                    skipped++;
                    continue;
                }

                const customPath = preview.new_path;
                const data = await this._fetchExecute(path, customPath, expression);
                if (data.new_path) {
                    done++;
                } else {
                    skipped++;
                }
            } catch (error) {
                skipped++;
            }
        }

        this._setStatusBar('');

        const flash = document.getElementById('flash');
        if (flash) {
            const successMsg = `${done} ${done > 1 ? this.translator.trans('js.rename.files_renamed') : this.translator.trans('js.rename.file_renamed')}.`;
            const skipMsg = skipped > 0 ? ` ${skipped} ${skipped > 1 ? this.translator.trans('js.rename.files_skipped') : this.translator.trans('js.rename.file_skipped')}.` : '';
            flash.innerHTML = `<div class="flash flash-success">${successMsg}${skipMsg}</div>`;
        }

        Turbo.visit(window.location.href, { action: 'replace' });
    }

    _closeAndRefresh() {
        const modal = this._getModal();
        if (modal) modal.close();
        Turbo.visit(window.location.href, { action: 'replace' });
    }

    /* ---- Helpers ---- */

    _getModal() {
        const el = document.querySelector('[data-controller="modal"]');
        if (!el) return null;
        return this.application.getControllerForElementAndIdentifier(el, 'modal');
    }

    _setStatusBar(text) {
        const el = document.getElementById('status-bar-text') || document.getElementById('status-bar');
        if (el) el.textContent = text ?? '';
    }

    _showError(message) {
        const flashFrame = document.getElementById('flash');
        if (flashFrame) {
            flashFrame.innerHTML = `<div class="flash flash-error">${message}</div>`;
        }
    }

    _showToast(message, type = 'success') {
        const flashFrame = document.getElementById('flash');
        if (!flashFrame) return;
        const cls = type === 'error' ? 'flash-error' : 'flash-success';
        flashFrame.innerHTML = `<div class="flash ${cls}">${message}</div>`;
    }

    _closeModalAndRefresh(flashMessage) {
        const modal = this._getModal();
        if (modal) {
            modal.close();
        }

        const flashFrame = document.getElementById('flash');
        if (flashFrame) {
            flashFrame.innerHTML = `<div class="flash flash-success">${flashMessage}</div>`;
        }

        Turbo.visit(window.location.href, { action: 'replace' });
    }
}
