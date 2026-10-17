import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str ?? ''));
    return div.innerHTML;
}

export default class extends Controller {
    static targets = ['modalContent', 'modalFooter'];
    static values = {
        libraryId: Number,
        scrapers: { type: Array, default: ['BDGest'] },
        locale: String,
    };

    _batchSaving = false;
    _anchorPath = null;
    _currentPath = null;
    _searchFiles = [];
    _searchIndex = 0;
    _searchScraper = 'BDGest';
    _searchSuccess = 0;
    _searchSkipped = 0;
    _searchDefaultQuery = null;
    _searchParsedQuery = null;

    async connect() {
        this.translator = await Translator.get(this.localeValue || 'en');
    }

    disconnect() {
        clearTimeout(this._flashTimeout);
    }

    async search(event) {
        const button = event.currentTarget;
        button.disabled = true;
        const path = event.params.path;
        this._currentPath = path;
        this.element.dataset.currentPath = path;

        this._searchFiles = [path];
        this._searchIndex = 0;
        this._searchScraper = this.scrapersValue[0] || 'BDGest';
        this._searchSuccess = 0;
        this._searchSkipped = 0;

        try {
            const data = await this._checkMetadata();
            this._showSearchModal(data);
        } catch (error) {
            this._showError(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async searchFiles(event) {
        const button = event.currentTarget;
        button.disabled = true;

        const files = this._selectedPaths();
        if (files.length === 0) { button.disabled = false; return; }

        this._searchFiles = files;
        this._searchIndex = 0;
        this._searchScraper = this.scrapersValue[0] || 'BDGest';
        this._searchSuccess = 0;
        this._searchSkipped = 0;
        this._pendingScrapeTasks = new Set();
        this._scrapeTaskIds = new Set();

        const selected = document.querySelector('.file-card.selected, tr.selected');
        if (selected) {
            const path = selected.dataset.scrapePathParam;
            if (path) {
                this._currentPath = path;
                this.element.dataset.currentPath = path;
            }
        }

        try {
            const data = await this._checkMetadata();
            this._showSearchModal(data);
        } catch (error) {
            this._showError(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async _checkMetadata() {
        const response = await fetch('/api/files/scrape/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                library_id: this.libraryIdValue,
                path: this._currentPath,
            }),
        });

        if (!response.ok) {
            throw new Error(this.translator.trans('js.scrape.metadata_check_error'));
        }

        return response.json();
    }

    _selectedPaths() {
        const sel = document.querySelectorAll('tr.selected, .file-card.selected');
        return Array.from(sel).map(el =>
            el.dataset.scrapePathParam
        ).filter(Boolean);
    }

    _updateSelectionCount() {
        const count = this._selectedPaths().length;
        const counter = document.getElementById('selection-counter');
        if (counter) {
            counter.textContent = count > 0 ? `${count} ${count > 1 ? this.translator.trans('js.scrape.files_selected') : this.translator.trans('js.scrape.file_selected')}` : '';
        }
        this._setStatusBar(count > 0 ? `${count} ${count > 1 ? this.translator.trans('js.scrape.files_selected') : this.translator.trans('js.scrape.file_selected')}` : '');
        const btns = document.querySelectorAll('.metadata-action-btn');
        btns.forEach(b => b.disabled = count === 0);
        const renameBtn = document.getElementById('rename-btn');
        if (renameBtn) {
            renameBtn.textContent = count > 1 ? `Renommer (${count})` : 'Renommer';
        }
        const urlBtn = document.getElementById('url-scrape-btn');
        if (urlBtn) {
            urlBtn.style.display = count === 1 ? '' : 'none';
        }
        const convertBtn = document.getElementById('convert-btn');
        if (convertBtn) {
            const hasConvertible = this._selectedPaths().some(p => {
                const ext = p.split('.').pop().toLowerCase();
                return ['cbr', 'pdf', 'epub'].includes(ext);
            });
            if (hasConvertible) {
                convertBtn.style.display = '';
                convertBtn.textContent = count > 1 ? `Convertir en CBZ (${count})` : 'Convertir en CBZ';
            } else {
                convertBtn.style.display = 'none';
            }
        }
    }

    async _search(query) {
        const useParsed = this._searchParsedQuery && query === this._searchDefaultQuery;
        const path = this._searchFiles.length > 0
            ? this._searchFiles[this._searchIndex]
            : this._currentPath;

        const response = await fetch('/api/files/scrape/search', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                library_id: this.libraryIdValue,
                path: path,
                scraper: this._searchScraper,
                query: query,
                _use_parsed_query: useParsed ? this._searchParsedQuery : undefined,
            }),
        });

        if (!response.ok) {
            throw new Error(this.translator.trans('js.scrape.search_error'));
        }

        return response.json();
    }

    async _writeMetadata(identifier) {
        const response = await fetch('/api/files/scrape/confirm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                library_id: this.libraryIdValue,
                path: this._currentPath,
                scraper: this._searchScraper,
                confirm_identifier: identifier,
            }),
        });

        if (!response.ok) {
            throw new Error(this.translator.trans('js.scrape.error_writing'));
        }

        return response.json();
    }

    async _scrapeUrl(identifier) {
        const response = await fetch('/api/files/scrape/url', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                scraper: this._searchScraper,
                url: identifier,
            }),
        });

        if (!response.ok) {
            throw new Error(this.translator.trans('js.scrape.error_scraping'));
        }

        return response.json();
    }

    _showSearchModal(data) {
        const isBatch = this._searchFiles.length > 1;
        const currentPath = this._searchFiles[this._searchIndex];
        const filename = currentPath.split('/').pop() || currentPath;
        const total = this._searchFiles.length;
        const index = this._searchIndex;

        const comicInfo = data && data.comic_info ? data.comic_info : null;

        let defaultQuery;
        if (comicInfo && (comicInfo.series || comicInfo.number || comicInfo.title)) {
            defaultQuery = [comicInfo.series, comicInfo.number].filter(Boolean).join(' ');
        } else if (data && data.parsed_query) {
            defaultQuery = data.parsed_query;
        } else {
            defaultQuery = filename.replace(/\.[^.]+$/, '');
        }

        this._searchDefaultQuery = defaultQuery;
        this._searchParsedQuery = data && data.parsed_query ? data.parsed_query : null;

        const selectOptions = this.scrapersValue.map(s =>
            `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`
        ).join('');

        const title = isBatch
            ? `${this.translator.trans('js.scrape.search_files')} ${index + 1}/${total}`
            : this.translator.trans('js.scrape.search_btn');

        const bodyHtml = `
            ${isBatch ? `<p style="margin-bottom: 1rem; color: #666;"><strong>${this.translator.trans('js.scrape.file_label')}</strong> ${escapeHtml(filename)}</p>` : ''}
            <div class="search-form">
                <label for="search-scraper">${this.translator.trans('js.scrape.source')}</label>
                <select id="search-scraper"
                        style="width: 100%; padding: 0.5rem; margin-top: 0.5rem; margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 4px;">
                    ${selectOptions}
                </select>
                <label for="search-query">${this.translator.trans('js.scrape.search_title')}</label>
                <input type="text" id="search-query"
                        value="${escapeHtml(defaultQuery)}"
                        style="width: 100%; padding: 0.5rem; margin-top: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div class="search-results"><div class="spinner"></div></div>
        `;

        const buttons = [];
        if (isBatch) {
            buttons.push({ text: this.translator.trans('js.scrape.skip'), class: 'btn', action: `${this.identifier}#skipResult` });
        }
        buttons.push(
            { text: this.translator.trans('js.scrape.search'), class: 'btn btn-primary', action: `${this.identifier}#doSearch` },
            { text: this.translator.trans('js.scrape.cancel'), class: 'btn', action: isBatch ? `${this.identifier}#cancelSearch` : 'modal#close' },
        );

        this._openModal(title, bodyHtml, buttons, isBatch ? () => {
            Turbo.visit(window.location.href, { action: 'replace' });
        } : undefined);

        // Auto-search
        this._doSearch();
    }

    async _doSearch() {
        const scraperSelect = document.querySelector('#search-scraper');
        const queryInput = document.querySelector('#search-query');
        if (!scraperSelect || !queryInput || !queryInput.value.trim()) return;

        const query = queryInput.value.trim();
        this._searchScraper = scraperSelect.value;

        const isBatch = this._searchFiles.length > 1;
        this._setStatusBar(this.translator.trans('js.scrape.searching', { query }));

        const resultsEl = document.querySelector('.search-results');
        if (resultsEl) {
            resultsEl.innerHTML = '<div class="spinner"></div>';
        }

        try {
            const data = await this._search(query);

            if (data.results && data.results.length > 0) {
                const resultMsg = data.results.length > 1
                    ? this.translator.trans('js.scrape.results_found', { count: data.results.length })
                    : this.translator.trans('js.scrape.result_found', { count: data.results.length });
                this._setStatusBar(resultMsg);

                let html = `<h3 style="margin-top: 1rem;">${this.translator.trans('js.scrape.results')}</h3><ul style="list-style: none; padding: 0;">`;
                data.results.forEach((r) => {
                    const thumb = r.coverUrl
                        ? `<img src="${escapeHtml(r.coverUrl)}" alt="" style="width: 100%; height: 100%; object-fit: cover;">`
                        : '<span style="font-size: 0.6rem; color: #999; text-align: center;">no cover</span>';
                    html += `
                        <li class="search-result-item"
                            data-action="click->${this.identifier}#selectResult"
                            data-identifier="${escapeHtml(r.identifier)}">
                            <div class="search-result-thumb">${thumb}</div>
                            <div class="search-result-info">
                                <strong>${escapeHtml(r.title)}</strong>
                                ${r.year ? ` <span style="color: #666;">(${escapeHtml(String(r.year))})</span>` : ''}
                                ${r.pageUrl ? `<br><a href="${escapeHtml(r.pageUrl)}" data-action="click->${this.identifier}#openLink" target="_blank" class="search-result-link">${escapeHtml(r.pageUrl)}</a>` : ''}
                            </div>
                        </li>`;
                });
                html += '</ul>';

                if (resultsEl) {
                    resultsEl.innerHTML = html;
                }
            } else {
                this._setStatusBar(this.translator.trans('js.scrape.no_results'));
                if (resultsEl) {
                    resultsEl.innerHTML = `<p style="color: #c62828; margin-top: 1rem;">${this.translator.trans('js.scrape.no_results')}</p>`;
                }
            }
        } catch (error) {
            this._setStatusBar(this.translator.trans('js.scrape.search_error'));
            if (resultsEl) {
                resultsEl.innerHTML = `<p style="color: #c62828; margin-top: 1rem;">${escapeHtml(error.message)}</p>`;
            }
        }
    }

    async doSearch(event) {
        if (event) event.preventDefault();
        await this._doSearch();
    }

    async selectResult(event) {
        const item = event.currentTarget;
        const identifier = item.dataset.identifier;

        const isBatch = this._searchFiles.length > 1;

        if (isBatch) {
            const path = this._searchFiles[this._searchIndex];
            item.style.opacity = '0.5';
            item.style.pointerEvents = 'none';

            try {
                const response = await fetch('/api/files/scrape/confirm', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        path: path,
                        scraper: this._searchScraper,
                        confirm_identifier: identifier,
                        batch: true,
                    }),
                });

                const result = await response.json();
                if (!response.ok || !result.queued) {
                    throw new Error(result.error || this.translator.trans('js.scrape.queue_error'));
                }

                if (!this._pendingScrapeTasks) this._pendingScrapeTasks = new Set();
                this._pendingScrapeTasks.add(path);
                if (!this._scrapeTaskIds) this._scrapeTaskIds = new Set();
                this._scrapeTaskIds.add(result.task_id);

                this._searchSuccess++;
                await this._advanceToNext();
            } catch (error) {
                this._showError(error.message);
                item.style.opacity = '';
                item.style.pointerEvents = '';
            }
        } else {
            this._setStatusBar(this.translator.trans('js.scrape.fetching_metadata'));

            try {
                const data = await this._scrapeUrl(identifier);

                if (data.comic_info) {
                    const modal = this._getModal();
                    if (modal) modal.close();

                    this._populateMetadataForm(data.comic_info);
                    this._setStatusBar(this.translator.trans('js.scrape.metadata_imported'));
                }
            } catch (error) {
                this._setStatusBar(this.translator.trans('js.scrape.error_scraping'));
                this._showError(error.message);
            }
        }
    }

    async urlScrape(event) {
        const button = event.currentTarget;
        button.disabled = true;

        const path = this._currentPath;
        if (!path) {
            this._showError(this.translator.trans('js.scrape.no_file_selected'));
            button.disabled = false;
            return;
        }

        this._openModal(
            this.translator.trans('js.scrape.scrape_url'),
            `
                <div class="search-form">
                    <label for="scrape-url">${this.translator.trans('js.scrape.url_placeholder')}</label>
                    <input type="url" id="scrape-url"
                            placeholder="https://www.bdgest.com/..."
                            value=""
                            style="width: 100%; padding: 0.5rem; margin-top: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <div id="scrape-url-error" style="color: #c62828; margin-top: 0.5rem; display: none;"></div>
                </div>
            `,
            [
                { text: this.translator.trans('js.scrape.cancel'), class: 'btn', action: 'modal#close' },
                { text: this.translator.trans('js.scrape.scrape'), class: 'btn btn-primary', action: `${this.identifier}#doUrlScrape` },
            ],
        );
        button.disabled = false;
    }

    async doUrlScrape(event) {
        event.preventDefault();

        const urlInput = document.querySelector('#scrape-url');
        const url = urlInput ? urlInput.value.trim() : '';
        if (!url) {
            const errorEl = document.querySelector('#scrape-url-error');
            if (errorEl) {
                errorEl.textContent = this.translator.trans('js.scrape.enter_url');
                errorEl.style.display = 'block';
            }
            return;
        }

        this._setStatusBar(this.translator.trans('js.scrape.scraping_url'));

        const scrapeBtn = event.currentTarget;
        scrapeBtn.disabled = true;
        scrapeBtn.textContent = this.translator.trans('js.scrape.scraping');

        try {
            const response = await fetch('/api/files/scrape/url', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url }),
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || this.translator.trans('js.scrape.error_scraping'));
            }

            const data = await response.json();

            if (data.comic_info) {
                const modal = this._getModal();
                if (modal) modal.close();

                this._populateMetadataForm(data.comic_info);
                this._setStatusBar(this.translator.trans('js.scrape.metadata_imported'));
            }
        } catch (error) {
            this._setStatusBar(this.translator.trans('js.scrape.error_scraping'));
            const errorEl = document.querySelector('#scrape-url-error');
            if (errorEl) {
                errorEl.textContent = error.message;
                errorEl.style.display = 'block';
            } else {
                this._showError(error.message);
            }
        } finally {
            scrapeBtn.disabled = false;
            scrapeBtn.textContent = this.translator.trans('js.scrape.scrape');
        }
    }

    openLink(event) {
        event.stopPropagation();
        event.preventDefault();
        window.open(event.currentTarget.href, '_blank');
    }

    _populateMetadataForm(comicInfo) {
        const form = document.getElementById('metadata-form');
        if (!form) return;

        form.querySelector('[name="series"]').value = comicInfo.series || '';
        form.querySelector('[name="title"]').value = comicInfo.title || '';
        form.querySelector('[name="number"]').value = comicInfo.number || '';
        form.querySelector('[name="writer"]').value = comicInfo.writer || '';
        form.querySelector('[name="penciler"]').value = comicInfo.penciler || '';
        form.querySelector('[name="inker"]').value = comicInfo.inker || '';
        form.querySelector('[name="colorist"]').value = comicInfo.colorist || '';
        form.querySelector('[name="letterer"]').value = comicInfo.letterer || '';
        form.querySelector('[name="cover_artist"]').value = comicInfo.cover_artist || '';
        form.querySelector('[name="publisher"]').value = comicInfo.publisher || '';
        form.querySelector('[name="year"]').value = comicInfo.year || '';
        form.querySelector('[name="summary"]').value = comicInfo.summary || '';
        form.querySelector('[name="isbn"]').value = comicInfo.isbn || '';
        form.querySelector('[name="age_rating"]').value = comicInfo.age_rating || '';
        form.querySelector('[name="language_iso"]').value = comicInfo.language_iso || '';
        const genreStr = comicInfo.genre ? (Array.isArray(comicInfo.genre) ? comicInfo.genre.join(', ') : comicInfo.genre) : '';
        form.querySelector('[name="genre"]').value = genreStr;
        const charsStr = comicInfo.characters ? (Array.isArray(comicInfo.characters) ? comicInfo.characters.join(', ') : comicInfo.characters) : '';
        form.querySelector('[name="characters"]').value = charsStr;

        if (this._currentPath) {
            const rows = document.querySelectorAll(`tr[data-scrape-path-param="${this._currentPath}"]`);
            rows.forEach(row => {
                const cell = row.querySelector('td:nth-child(3)');
                if (cell) cell.innerHTML = '<span style="color: #2e7d32;">✓</span>';
            });
            const cards = document.querySelectorAll(`.file-card[data-scrape-path-param="${this._currentPath}"]`);
            cards.forEach(card => {
                const badge = card.querySelector('.badge');
                if (badge) {
                    badge.className = 'badge badge-success';
                    badge.textContent = '✓';
                }
            });
        }
    }

    async _showBatchMetadata(paths) {
        if (!paths || paths.length < 2) return;

        const metadataDiv = document.getElementById('metadata-content');
        if (!metadataDiv) return;

        const metadataDisplay = document.getElementById('metadata-display');
        if (metadataDisplay) {
            metadataDisplay.style.display = 'flex';
        }

        try {
            // Fetch metadata for all selected files
            const results = await Promise.all(paths.map(async (path) => {
                const response = await fetch('/api/files/scrape/read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        path: path,
                    }),
                });
                if (!response.ok) return null;
                const data = await response.json();
                return data.comic_info || {};
            }));

            // Filter out failed fetches
            const validResults = results.filter(r => r !== null);
            if (validResults.length === 0) throw new Error(this.translator.trans('js.scrape.metadata_read_error'));

            // Compute common values
            const common = this._computeCommonValues(validResults);
            const coverUrl = `/api/files/cover?library_id=${this.libraryIdValue}&path=${encodeURIComponent(paths[0])}`;

            this._renderBatchForm(common, coverUrl, paths.length, paths);
        } catch (error) {
            metadataDiv.innerHTML = `<p style="color: #c62828;">${this.translator.trans('js.scrape.error_prefix')}${escapeHtml(error.message)}</p>`;
        }
    }

    _computeCommonValues(results) {
        const fields = ['series', 'title', 'number', 'year', 'writer', 'penciler', 'inker', 'colorist',
                        'letterer', 'cover_artist', 'publisher', 'isbn', 'age_rating', 'language_iso',
                        'genre', 'characters', 'summary'];
        const common = {};

        for (const field of fields) {
            const values = results.map(r => {
                const val = r[field];
                if (Array.isArray(val)) return val.join(', ');
                return val != null ? String(val) : '';
            });
            // If all values are identical, use it; otherwise leave empty
            common[field] = values.every(v => v === values[0]) ? values[0] : '';
        }

        return common;
    }

_renderBatchForm(common, coverUrl, count, paths) {
    const metadataDiv = document.getElementById('metadata-content');
    if (!metadataDiv) return;

    const genreStr = common.genre || '';
    const charsStr = common.characters || '';

    metadataDiv.innerHTML = `
        <div class="metadata-horizontal">
            <div class="metadata-horizontal-cover">
                <img src="${coverUrl}" alt=""
                     onerror="this.parentElement.innerHTML='<p style=\\'color:var(--text-subtle);font-size:0.75rem;text-align:center;padding:2rem 0;\\'>Pas d\\'image</p>'">
                <span class="batch-badge">Batch (${count})</span>
            </div>
            <div class="metadata-horizontal-form">
                <form id="metadata-form">
                    <div class="metadata-horizontal-grid">
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.series')}</label>
                            <input type="text" name="series" value="${escapeHtml(common.series || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.title')}</label>
                            <input type="text" name="title" value="${escapeHtml(common.title || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.number')}</label>
                            <div class="number-input-wrapper">
                                <input type="text" name="number" data-batch-number value="${escapeHtml(common.number || '')}">
                                <button type="button" class="btn-number-fill" data-action="${this.identifier}#batchAutoNumber"
                                    title="${this.translator.trans('js.scrape.auto_number_tooltip')}" disabled>↓</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.year')}</label>
                            <input type="text" name="year" value="${escapeHtml(common.year || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.writer')}</label>
                            <input type="text" name="writer" value="${escapeHtml(common.writer || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.penciler')}</label>
                            <input type="text" name="penciler" value="${escapeHtml(common.penciler || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.publisher')}</label>
                            <input type="text" name="publisher" value="${escapeHtml(common.publisher || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.genre')}</label>
                            <input type="text" name="genre" value="${escapeHtml(genreStr)}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.inker')}</label>
                            <input type="text" name="inker" value="${escapeHtml(common.inker || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.colorist')}</label>
                            <input type="text" name="colorist" value="${escapeHtml(common.colorist || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.letterer')}</label>
                            <input type="text" name="letterer" value="${escapeHtml(common.letterer || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.cover_artist')}</label>
                            <input type="text" name="cover_artist" value="${escapeHtml(common.cover_artist || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.isbn')}</label>
                            <input type="text" name="isbn" value="${escapeHtml(common.isbn || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.age_rating')}</label>
                            <input type="text" name="age_rating" value="${escapeHtml(common.age_rating || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.language')}</label>
                            <input type="text" name="language_iso" value="${escapeHtml(common.language_iso || '')}">
                        </div>
                        <div class="form-group">
                            <label>${this.translator.trans('js.scrape.characters')}</label>
                            <input type="text" name="characters" value="${escapeHtml(charsStr)}">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label>${this.translator.trans('js.scrape.summary')}</label>
                        <textarea name="summary" rows="2">${escapeHtml(common.summary || '')}</textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">${this.translator.trans('js.scrape.save')} (${count})</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    // Attach batch save handler
    const form = metadataDiv.querySelector('#metadata-form');
    if (form) {
        const capturedPaths = [...paths];
        form.addEventListener('submit', (e) => this._batchSave(e, capturedPaths));
    }

    // Auto-number button toggle
    const numInput = metadataDiv.querySelector('[name="number"]');
    const numBtn = metadataDiv.querySelector('.btn-number-fill');
    if (numInput && numBtn) {
        const toggle = () => numBtn.disabled = !numInput.value.trim();
        numInput.addEventListener('input', toggle);
        toggle();
    }

    // Cover click -> lightbox carousel over all selected files
    const coverBox = metadataDiv.querySelector('.metadata-horizontal-cover');
    if (coverBox) {
        const urls = paths.map(p =>
            `/api/files/cover?library_id=${this.libraryIdValue}&path=${encodeURIComponent(p)}`
        );
        coverBox.addEventListener('click', () => this._openLightbox(urls, 0));
    }
}

    async _batchSave(event, paths) {
        event.preventDefault();
        if (this._batchSaving) return;
        this._batchSaving = true;

        const form = document.getElementById('metadata-form');
        if (!form || !paths || paths.length === 0) {
            this._batchSaving = false;
            return;
        }

        const formData = new FormData(form);
        const metadata = {};
        let hasFields = false;
        formData.forEach((value, key) => {
            if (value !== '') {
                metadata[key] = value;
                hasFields = true;
            }
        });

        if (!hasFields) {
            this._batchSaving = false;
            this._showError(this.translator.trans('js.scrape.no_fields'));
            return;
        }

        this._batchSuccess = 0;
        this._batchErrors = [];

        // Show progress in status bar
        const statusBar = document.getElementById('status-bar-text') || document.getElementById('status-bar');

        for (let i = 0; i < paths.length; i++) {
            const path = paths[i];
            const filename = path.split('/').pop() || path;
            if (statusBar) statusBar.textContent = `${this.translator.trans('js.scrape.batch')} ${i + 1}/${paths.length}  ${filename}`;

            try {
                const response = await fetch('/api/files/scrape/update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        path: path,
                        ...metadata,
                    }),
                });

                if (!response.ok) throw new Error(this.translator.trans('js.scrape.save_error'));

                const result = await response.json();
                if (result.written) {
                    this._batchSuccess++;
                    const cards = document.querySelectorAll(`.file-card[data-scrape-path-param="${CSS.escape(path)}"]`);
                    cards.forEach(card => {
                        const badge = card.querySelector('.badge');
                        if (badge) {
                            badge.className = 'badge badge-success';
                            badge.textContent = '✓';
                        }
                    });
                }
            } catch (error) {
                this._batchErrors.push(`${escapeHtml(filename)}: ${escapeHtml(error.message)}`);
            }
        }

        // Clear status bar
        if (statusBar) statusBar.textContent = '';

        // Show flash result
        this._showFlash(this._batchErrors.length === 0
            ? { type: 'success', text: `${this._batchSuccess} ${this._batchSuccess > 1 ? this.translator.trans('js.scrape.files_updated') : this.translator.trans('js.scrape.file_updated')}.` }
            : { type: 'warning', text: `${this._batchSuccess}/${paths.length} ${this.translator.trans('js.scrape.files_updated')}, ${this._batchErrors.length} ${this._batchErrors.length > 1 ? this.translator.trans('js.scrape.errors') : this.translator.trans('js.scrape.error')}.` }
        );

        // Clean up batch state
        this._batchSaving = false;
        this._batchErrors = [];
    }

    closeMetadata(event) {
        if (event) event.preventDefault();
        this._clearMetadataPanel();
        document.querySelectorAll('tr.selected, .file-card.selected').forEach(r => r.classList.remove('selected'));
        this._updateSelectionCount();
    }

    _clearMetadataPanel() {
        const metadataDiv = document.getElementById('metadata-content');
        if (metadataDiv) {
            metadataDiv.innerHTML = `<p>${this.translator.trans('js.scrape.no_file_selected')}</p>`;
        }
        const metadataDisplay = document.getElementById('metadata-display');
        if (metadataDisplay) {
            metadataDisplay.style.display = 'none';
        }
    }

    _openLightbox(urls, index = 0) {
        if (this._lightbox) this._closeLightbox();

        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';

        const img = document.createElement('img');
        img.className = 'lightbox-image';
        img.alt = '';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'lightbox-close';
        closeBtn.setAttribute('aria-label', this.translator.trans('js.scrape.close'));
        closeBtn.textContent = '×';

        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'lightbox-nav lightbox-nav-prev';
        prevBtn.textContent = '‹';

        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'lightbox-nav lightbox-nav-next';
        nextBtn.textContent = '›';

        const hasMultiple = urls.length > 1;
        const setImage = (i) => {
            index = i;
            img.src = urls[index] || '';
        };
        setImage(index);
        prevBtn.style.display = hasMultiple ? '' : 'none';
        nextBtn.style.display = hasMultiple ? '' : 'none';

        overlay.append(closeBtn, prevBtn, img, nextBtn);
        document.body.appendChild(overlay);

        const showPrev = () => setImage((index - 1 + urls.length) % urls.length);
        const showNext = () => setImage((index + 1) % urls.length);

        const onKey = (event) => {
            if (event.key === 'Escape') {
                this._closeLightbox();
            } else if (event.key === 'ArrowLeft' && hasMultiple) {
                showPrev();
            } else if (event.key === 'ArrowRight' && hasMultiple) {
                showNext();
            }
        };

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) this._closeLightbox();
        });
        closeBtn.addEventListener('click', () => this._closeLightbox());
        prevBtn.addEventListener('click', showPrev);
        nextBtn.addEventListener('click', showNext);

        this._lightbox = overlay;
        this._lightboxKeyHandler = onKey;
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
    }

    _closeLightbox() {
        if (this._lightboxKeyHandler) {
            document.removeEventListener('keydown', this._lightboxKeyHandler);
            this._lightboxKeyHandler = null;
        }
        if (this._lightbox) {
            this._lightbox.remove();
            this._lightbox = null;
        }
        document.body.style.overflow = '';
    }

    async batchAutoNumber(event) {
        const numInput = document.querySelector('[name="number"]');
        const baseStr = numInput ? numInput.value.trim() : '';
        if (!baseStr) return;

        const base = parseInt(baseStr, 10);
        if (isNaN(base)) {
            this._showError(this.translator.trans('js.scrape.invalid_number'));
            return;
        }

        const selected = this._selectedPaths();
        if (selected.length < 2) return;

        const statusBar = document.getElementById('status-bar-text') || document.getElementById('status-bar');
        let successCount = 0;
        const errors = [];

        for (let i = 0; i < selected.length; i++) {
            const path = selected[i];
            const filename = path.split('/').pop() || path;
            const number = String(base + i);
            if (statusBar) statusBar.textContent = `${this.translator.trans('js.scrape.auto_number')} ${i + 1}/${selected.length}  ${filename}`;

            try {
                const response = await fetch('/api/files/scrape/update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        library_id: this.libraryIdValue,
                        path: path,
                        number: number,
                    }),
                });
                if (!response.ok) throw new Error(this.translator.trans('js.scrape.save_error'));
                const result = await response.json();
                if (result.written) {
                    successCount++;
                    const cards = document.querySelectorAll(`.file-card[data-scrape-path-param="${CSS.escape(path)}"]`);
                    cards.forEach(card => {
                        const badge = card.querySelector('.badge');
                        if (badge) {
                            badge.className = 'badge badge-success';
                            badge.textContent = '✓';
                        }
                    });
                }
            } catch (error) {
                errors.push(`${escapeHtml(filename)}: ${escapeHtml(error.message)}`);
            }
        }

        if (statusBar) statusBar.textContent = '';

        this._showFlash(errors.length === 0
            ? { type: 'success', text: `${successCount} ${successCount > 1 ? this.translator.trans('js.scrape.files_updated') : this.translator.trans('js.scrape.file_updated')}.` }
            : { type: 'warning', text: `${successCount}/${selected.length} ${this.translator.trans('js.scrape.files_updated')}, ${errors.length} ${errors.length > 1 ? this.translator.trans('js.scrape.errors') : this.translator.trans('js.scrape.error')}.` }
        );
    }

    async skipResult(event) {
        if (event) event.preventDefault();
        this._searchSkipped++;
        await this._advanceToNext();
    }

    async cancelSearch(event) {
        if (event) event.preventDefault();
        const modal = this._getModal();
        if (modal) modal.close();
        Turbo.visit(window.location.href, { action: 'replace' });
    }

    async _advanceToNext() {
        this._searchIndex++;

        const total = this._searchFiles.length;
        if (this._searchIndex < total) {
            const path = this._searchFiles[this._searchIndex];
            const filename = path.split('/').pop() || path;
            this._setStatusBar(`${this.translator.trans('js.scrape.scraping')} ${this._searchIndex + 1}/${total}  ${filename}`);

            // Fetch metadata for next file and show modal
            this._currentPath = path;
            try {
                const data = await this._checkMetadata();
                this._showSearchModal(data);
            } catch (error) {
                this._showError(error.message);
            }
            return;
        }

        this._setStatusBar('');
        const modal = this._getModal();
        if (modal) modal.close();

        const queued = this._pendingScrapeTasks?.size || 0;
        if (queued > 0) {
            const text = `${queued} ${queued > 1 ? this.translator.trans('js.scrape.tasks_queued_plural') : this.translator.trans('js.scrape.tasks_queued')}`;
            this._showFlash({ type: 'success', text });
            this._setStatusBar(`${this.translator.trans('js.scrape.scraping')} 0/${queued}`);
            this._pollScrapeProgress(queued);
        } else {
            const filesWord = total > 1 ? this.translator.trans('js.scrape.files_scraped') : this.translator.trans('js.scrape.file_scraped');
            const text = this._searchSkipped > 0
                ? `${this._searchSuccess}/${total} ${filesWord}, ${this._searchSkipped} ${this._searchSkipped > 1 ? this.translator.trans('js.scrape.skipped_plural') : this.translator.trans('js.scrape.skipped')}.`
                : `${this._searchSuccess}/${total} ${filesWord}.`;
            this._showFlash({ type: 'success', text });
        }
    }

    async _pollScrapeProgress(expected) {
        if (!this._scrapeTaskIds || this._scrapeTaskIds.size === 0) return;
        const taskIds = new Set(this._scrapeTaskIds);
        let completedIds = new Set();
        let interval = setInterval(async () => {
            try {
                const response = await fetch('/api/tasks/list');
                const tasks = await response.json();
                const ourTasks = tasks.filter(t => taskIds.has(t.id));
                const done = ourTasks.filter(t => t.status === 'completed' || t.status === 'failed').length;
                const failed = ourTasks.filter(t => t.status === 'failed').length;

                for (const task of ourTasks) {
                    if ((task.status === 'completed' || task.status === 'failed') && !completedIds.has(task.id)) {
                        completedIds.add(task.id);
                        this._updateCardAfterScrape(task);
                    }
                }

                if (done >= expected) {
                    clearInterval(interval);
                    const text = failed > 0
                        ? `${this.translator.trans('js.scrape.scraping_done')} (${done - failed} ${this.translator.trans('js.scrape.success')}, ${failed} ${this.translator.trans('js.scrape.failed')})`
                        : `${this.translator.trans('js.scrape.scraping_done')} (${done} ${this.translator.trans('js.scrape.success')})`;
                    this._setStatusBar(text);
                    this._showToast(text);
                } else {
                    this._setStatusBar(`${this.translator.trans('js.scrape.scraping')} ${done}/${expected}`);
                }
            } catch {}
        }, 3000);
    }

    _updateCardAfterScrape(task) {
        const card = document.querySelector(`[data-path="${CSS.escape(task.path)}"]`);
        if (!card) return;

        const badge = card.querySelector('.file-card-body .badge');
        if (badge) {
            if (task.status === 'failed') {
                badge.className = 'badge badge-danger';
                badge.textContent = '✗';
            } else {
                badge.className = 'badge badge-success';
                badge.textContent = '✓';
            }
        }
    }

    async showMetadata(event) {
        if (event.shiftKey && this._anchorPath) {
            const clicked = event.currentTarget.closest('tr, .file-card');
            const allCards = Array.from(document.querySelectorAll('tr, .file-card'));
            const anchorIdx = allCards.findIndex(el => el.dataset.scrapePathParam === this._anchorPath);
            const clickedIdx = clicked ? allCards.indexOf(clicked) : -1;
            if (anchorIdx !== -1 && clickedIdx !== -1) {
                const [from, to] = anchorIdx < clickedIdx ? [anchorIdx, clickedIdx] : [clickedIdx, anchorIdx];
                for (let i = from; i <= to; i++) {
                    allCards[i].classList.add('selected');
                }
                this._updateSelectionCount();
                const selected = this._selectedPaths();
                if (selected.length >= 2) {
                    this._isBatchMode = true;
                    await this._showBatchMetadata(selected);
                }
            }
            return;
        }

        if (event.ctrlKey || event.metaKey) {
            const row = event.currentTarget.closest('tr, .file-card');
            if (row) {
                row.classList.toggle('selected');
                const path = event.params.path;
                if (path) this._anchorPath = path;
                this._updateSelectionCount();
                const selected = this._selectedPaths();
                if (selected.length >= 2) {
                    this._isBatchMode = true;
                    await this._showBatchMetadata(selected);
                } else {
                    this._isBatchMode = false;
                    if (selected.length === 1) {
                        const remainingPath = selected[0];
                        const remainingRow = document.querySelector(`[data-scrape-path-param="${CSS.escape(remainingPath)}"]`);
                        if (remainingRow) {
                            const fakeEvent = { ctrlKey: false, metaKey: false, currentTarget: remainingRow, params: { path: remainingPath } };
                            await this.showMetadata(fakeEvent);
                        }
                    } else {
                        this._clearMetadataPanel();
                    }
                }
            }
            return;
        }

        // Clear other selections when doing single-click
        document.querySelectorAll('tr.selected, .file-card.selected').forEach(r => r.classList.remove('selected'));
        this._isBatchMode = false;
        const row = event.currentTarget.closest('tr, .file-card');
        if (row) row.classList.add('selected');
        const path = event.params.path;
        if (path) this._anchorPath = path;
        this._updateSelectionCount();

        this._currentPath = path;
        this.element.dataset.currentPath = path;

        const metadataDiv = document.getElementById('metadata-content');
        if (!metadataDiv) return;

        // Show metadata panel and enable action buttons
        const metadataDisplay = document.getElementById('metadata-display');
        if (metadataDisplay) {
            metadataDisplay.style.display = 'flex';
            const btns = metadataDisplay.querySelectorAll('.metadata-action-btn');
            btns.forEach(b => b.disabled = false);
        }

        // Show convert button for convertible files
        const convertBtn = document.getElementById('convert-btn');
        if (convertBtn) {
            const ext = path.split('.').pop().toLowerCase();
            if (['cbr', 'pdf', 'epub'].includes(ext)) {
                convertBtn.style.display = '';
                convertBtn.dataset.path = path;
            } else {
                convertBtn.style.display = 'none';
            }
        }

        try {
            const response = await fetch('/api/files/scrape/read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    library_id: this.libraryIdValue,
                    path: path,
                }),
            });

            if (!response.ok) {
                throw new Error(this.translator.trans('js.scrape.metadata_read_error'));
            }

            const data = await response.json();
            const comicInfo = data.comic_info || {};
            const coverUrl = `/api/files/cover?library_id=${this.libraryIdValue}&path=${encodeURIComponent(path)}`;

            const genreStr = comicInfo.genre ? (Array.isArray(comicInfo.genre) ? comicInfo.genre.join(', ') : comicInfo.genre) : '';
            const charsStr = comicInfo.characters ? (Array.isArray(comicInfo.characters) ? comicInfo.characters.join(', ') : comicInfo.characters) : '';

            metadataDiv.innerHTML = `
                <div class="metadata-horizontal">
                    <div class="metadata-horizontal-cover">
                        <img src="${coverUrl}" alt=""
                     onerror="this.parentElement.innerHTML='<p style=\\'color:var(--text-subtle);font-size:0.75rem;text-align:center;padding:2rem 0;\\'>${this.translator.trans('js.scrape.no_image')}</p>'">
                    </div>
                    <div class="metadata-horizontal-form">
                        <form id="metadata-form">
                            <div class="metadata-horizontal-grid">
                                <div class="form-group">
                            <label>${this.translator.trans('js.scrape.series')}</label>
                                    <input type="text" name="series" value="${escapeHtml(comicInfo.series || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.title')}</label>
                                    <input type="text" name="title" value="${escapeHtml(comicInfo.title || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.number')}</label>
                                    <input type="text" name="number" value="${escapeHtml(comicInfo.number || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.year')}</label>
                                    <input type="text" name="year" value="${escapeHtml(comicInfo.year || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.writer')}</label>
                                    <input type="text" name="writer" value="${escapeHtml(comicInfo.writer || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.penciler')}</label>
                                    <input type="text" name="penciler" value="${escapeHtml(comicInfo.penciler || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.publisher')}</label>
                                    <input type="text" name="publisher" value="${escapeHtml(comicInfo.publisher || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.genre')}</label>
                                    <input type="text" name="genre" value="${escapeHtml(genreStr)}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.inker')}</label>
                                    <input type="text" name="inker" value="${escapeHtml(comicInfo.inker || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.colorist')}</label>
                                    <input type="text" name="colorist" value="${escapeHtml(comicInfo.colorist || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.letterer')}</label>
                                    <input type="text" name="letterer" value="${escapeHtml(comicInfo.letterer || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.cover_artist')}</label>
                                    <input type="text" name="cover_artist" value="${escapeHtml(comicInfo.cover_artist || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.isbn')}</label>
                                    <input type="text" name="isbn" value="${escapeHtml(comicInfo.isbn || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.age_rating')}</label>
                                    <input type="text" name="age_rating" value="${escapeHtml(comicInfo.age_rating || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.language')}</label>
                                    <input type="text" name="language_iso" value="${escapeHtml(comicInfo.language_iso || '')}">
                                </div>
                                <div class="form-group">
                                    <label>${this.translator.trans('js.scrape.characters')}</label>
                                    <input type="text" name="characters" value="${escapeHtml(charsStr)}">
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0.5rem;">
                                <label>${this.translator.trans('js.scrape.summary')}</label>
                                <textarea name="summary" rows="2">${escapeHtml(comicInfo.summary || '')}</textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary btn-sm">${this.translator.trans('js.scrape.save')}</button>
                                <button type="button" class="btn btn-danger btn-sm" data-action="${this.identifier}#deleteMetadata">${this.translator.trans('js.scrape.delete')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            const coverBox = metadataDiv.querySelector('.metadata-horizontal-cover');
            if (coverBox) {
                coverBox.addEventListener('click', () => this._openLightbox([coverUrl], 0));
            }

            const form = metadataDiv.querySelector('#metadata-form');
            if (form) {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (this._isBatchMode) return;

                    const formData = new FormData(form);
                    const metadata = {};
                    formData.forEach((value, key) => {
                        metadata[key] = value;
                    });

                    const submitBtn = e.target.querySelector('button[type="submit"]');
                    submitBtn.disabled = true;
                    submitBtn.textContent = this.translator.trans('js.scrape.saving');

                    try {
                        const response = await fetch('/api/files/scrape/update', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                library_id: this.libraryIdValue,
                                path: path,
                                ...metadata,
                            }),
                        });

                        if (!response.ok) {
                            throw new Error(this.translator.trans('js.scrape.save_error'));
                        }

                        const result = await response.json();
                        if (result.written) {
                            const rows = document.querySelectorAll(`tr[data-scrape-path-param="${path}"]`);
                            rows.forEach(row => {
                                const metadataCell = row.querySelector('td:nth-child(3)');
                                if (metadataCell) {
                                    metadataCell.innerHTML = '<span style="color: #2e7d32;">✓</span>';
                                }
                            });
                            // Update file-card badge for grid layout
                            const cards = document.querySelectorAll(`.file-card[data-scrape-path-param="${path}"]`);
                            cards.forEach(card => {
                                const badge = card.querySelector('.badge');
                                if (badge) {
                                    badge.className = 'badge badge-success';
                                    badge.textContent = '✓';
                                }
                            });

                            const flash = document.getElementById('flash');
                            if (flash) {
                                flash.innerHTML = `<div class="flash flash-success">${this.translator.trans('js.scrape.saved')}</div>`;
                            }
                        }
                    } catch (error) {
                        const flash = document.getElementById('flash');
                        if (flash) {
                            flash.innerHTML = `<div class="flash flash-error">${escapeHtml(error.message)}</div>`;
                        }
                    } finally {
                        submitBtn.disabled = false;
                        submitBtn.textContent = this.translator.trans('js.scrape.save');
                    }
                });
            }
        } catch (error) {
            metadataDiv.innerHTML = `<p style="color: #c62828;">${this.translator.trans('js.scrape.error_prefix')}${escapeHtml(error.message)}</p>`;
        }
    }

    async deleteMetadata(event) {
        if (!confirm(this.translator.trans('js.scrape.delete_metadata_confirm'))) return;

        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = this.translator.trans('js.scrape.deleting');

        const path = this._currentPath;

        try {
            const response = await fetch('/api/files/scrape/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    library_id: this.libraryIdValue,
                    path: path,
                }),
            });

            if (!response.ok) throw new Error(this.translator.trans('js.scrape.delete_error'));

            const result = await response.json();
            if (result.deleted) {
                const rows = document.querySelectorAll(`tr[data-scrape-path-param="${path}"]`);
                rows.forEach(row => {
                    const metadataCell = row.querySelector('td:nth-child(3)');
                    if (metadataCell) {
                        metadataCell.innerHTML = '<span style="color: #c62828;">✗</span>';
                    }
                });
                const cards = document.querySelectorAll(`.file-card[data-scrape-path-param="${path}"]`);
                cards.forEach(card => {
                    const badge = card.querySelector('.badge');
                    if (badge) {
                        badge.className = 'badge badge-danger';
                        badge.textContent = '✗';
                    }
                });

                const flash = document.getElementById('flash');
                if (flash) {
                    flash.innerHTML = `<div class="flash flash-success">${this.translator.trans('js.scrape.deleted')}</div>`;
                }

                // Re-render form with empty metadata
                const row = document.querySelector(`tr[data-scrape-path-param="${CSS.escape(path)}"], .file-card[data-scrape-path-param="${CSS.escape(path)}"]`);
                if (row) {
                    const fakeEvent = { ctrlKey: false, metaKey: false, currentTarget: row, params: { path } };
                    await this.showMetadata(fakeEvent);
                }
            }
        } catch (error) {
            const flash = document.getElementById('flash');
            if (flash) {
                flash.innerHTML = `<div class="flash flash-error">${escapeHtml(error.message)}</div>`;
            }
        } finally {
            button.disabled = false;
            button.textContent = this.translator.trans('js.scrape.delete_metadata');
        }
    }

    _openModal(title, bodyHtml, footerButtons, onClose) {
        const modal = this._getModal();
        if (!modal) return;

        modal.contentTarget.innerHTML = `
            <div class="modal-header">
                <h2>${escapeHtml(title)}</h2>
                <button type="button" class="modal-close" data-action="modal#close">&times;</button>
            </div>
            <div class="modal-body">${bodyHtml}</div>
            <div class="modal-footer">
                ${footerButtons.map(b =>
                    `<button type="button" class="${escapeHtml(b.class)}" data-action="${escapeHtml(b.action)}">${escapeHtml(b.text)}</button>`
                ).join('')}
            </div>
        `;

        if (onClose) {
            const origClose = modal.close.bind(modal);
            modal.close = () => {
                origClose();
                onClose();
            };
        }

        modal.openValue = true;
    }

    _setFooterButtons(buttons) {
        const footer = document.querySelector('.modal-footer');
        if (footer) {
            footer.innerHTML = buttons.map(b =>
                `<button type="button" class="${escapeHtml(b.class)}" data-action="${escapeHtml(b.action)}">${escapeHtml(b.text)}</button>`
            ).join('');
        }
    }

    _getModal() {
        const el = document.querySelector('[data-controller="modal"]');
        if (!el) return null;
        return this.application.getControllerForElementAndIdentifier(el, 'modal');
    }

    _showToast(text) {
        const panel = document.querySelector('.status-bar-panel .task-list');
        if (panel) {
            const item = document.createElement('div');
            item.className = 'task-item';
            item.textContent = text;
            panel.prepend(item);
            setTimeout(() => item.remove(), 5000);
        }
    }

    _setStatusBar(text) {
        const el = document.getElementById('status-bar-text') || document.getElementById('status-bar');
        if (el) el.textContent = text ?? '';
    }

    _showError(message) {
        this._showFlash({ type: 'error', text: message });
    }

    _showFlash({ type, text }) {
        const flashFrame = document.getElementById('flash');
        if (!flashFrame) return;
        flashFrame.innerHTML = `<div class="flash flash-${escapeHtml(type)}">${escapeHtml(text)}</div>`;
        clearTimeout(this._flashTimeout);
        this._flashTimeout = setTimeout(() => { flashFrame.innerHTML = ''; }, 5000);
    }

    _closeModalAndRefresh(flashMessage) {
        const modal = this._getModal();
        if (modal) {
            modal.close();
        }

        const flashFrame = document.getElementById('flash');
        if (flashFrame) {
            flashFrame.innerHTML = `<div class="flash flash-success">${escapeHtml(flashMessage)}</div>`;
        }

        Turbo.visit(window.location.href, { action: 'replace' });
    }
}
