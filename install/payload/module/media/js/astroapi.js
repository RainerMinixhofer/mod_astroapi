/**
 * AstroAPI Module JavaScript
 * @package     Joomla.Site
 * @subpackage  mod_astroapi
 * @copyright   (C) 2026
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

(function() {
    'use strict';

    class AstroApiModule {
        constructor(options = {}) {
            this.options = {
                containerId: 'astroapi-module',
                apiUrl: options.apiUrl || '/api',
                displayMode: options.displayMode || 'list',
                itemsPerPage: options.itemsPerPage || 10,
                ...options
            };

            this.init();
        }

        /**
         * Initialize the module
         */
        init() {
            const container = document.getElementById(this.options.containerId);
            if (!container) {
                console.warn('AstroAPI container not found');
                return;
            }

            this.loadData();
            this.attachEventListeners();
        }

        /**
         * Load data from AstroAPI
         */
        async loadData() {
            try {
                const response = await fetch(
                    `${this.options.apiUrl}?limit=${this.options.itemsPerPage}`
                );

                if (!response.ok) {
                    throw new Error(`API returned ${response.status}`);
                }

                const data = await response.json();
                this.renderData(data);
            } catch (error) {
                console.error('Error loading AstroAPI data:', error);
                this.showError(error.message);
            }
        }

        /**
         * Render the data based on display mode
         *
         * @param {Array} data The data to render
         */
        renderData(data) {
            const container = document.getElementById(this.options.containerId);
            if (!container) return;

            switch (this.options.displayMode) {
                case 'gallery':
                    this.renderGallery(data, container);
                    break;
                case 'map':
                    this.renderMap(data, container);
                    break;
                default:
                    this.renderList(data, container);
            }
        }

        /**
         * Render list view
         *
         * @param {Array} data The data to render
         * @param {HTMLElement} container The container element
         */
        renderList(data, container) {
            const list = document.createElement('ul');
            list.className = 'astroapi-list list-group';

            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No items available</div>';
                return;
            }

            data.forEach(item => {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.innerHTML = `
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1">${this.escapeHtml(item.name || 'Unknown')}</h6>
                            <p class="mb-1 small text-muted">${this.escapeHtml(item.type || '')}</p>
                            <p class="mb-0 small">${this.escapeHtml((item.description || '').substring(0, 150))}</p>
                        </div>
                        ${item.magnitude ? `<span class="badge bg-primary">Mag: ${this.escapeHtml(item.magnitude)}</span>` : ''}
                    </div>
                `;
                list.appendChild(li);
            });

            container.innerHTML = '';
            container.appendChild(list);
        }

        /**
         * Render gallery view
         *
         * @param {Array} data The data to render
         * @param {HTMLElement} container The container element
         */
        renderGallery(data, container) {
            const gallery = document.createElement('div');
            gallery.className = 'astroapi-gallery row';

            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No items available</div>';
                return;
            }

            data.forEach(item => {
                const col = document.createElement('div');
                col.className = 'col-md-4 mb-4';
                col.innerHTML = `
                    <div class="card h-100">
                        ${item.image ? `<img src="${this.escapeHtml(item.image)}" class="card-img-top" alt="${this.escapeHtml(item.name || 'Object')}">` : ''}
                        <div class="card-body">
                            <h5 class="card-title">${this.escapeHtml(item.name || 'Unknown')}</h5>
                            <p class="card-text">${this.escapeHtml((item.description || '').substring(0, 100))}</p>
                        </div>
                        <div class="card-footer">
                            <small class="text-muted">${this.escapeHtml(item.type || '')}</small>
                        </div>
                    </div>
                `;
                gallery.appendChild(col);
            });

            container.innerHTML = '';
            container.appendChild(gallery);
        }

        /**
         * Render map view (placeholder)
         *
         * @param {Array} data The data to render
         * @param {HTMLElement} container The container element
         */
        renderMap(data, container) {
            container.innerHTML = `
                <div class="alert alert-info">
                    Map view requires additional configuration
                </div>
            `;
        }

        /**
         * Show error message
         *
         * @param {string} message Error message
         */
        showError(message) {
            const container = document.getElementById(this.options.containerId);
            if (container) {
                container.innerHTML = `<div class="alert alert-danger">Error: ${this.escapeHtml(message)}</div>`;
            }
        }

        /**
         * Attach event listeners
         */
        attachEventListeners() {
            // Add any event listeners here
        }

        /**
         * Escape HTML to prevent XSS
         *
         * @param {string} text The text to escape
         * @returns {string} Escaped text
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Export to global scope
    window.AstroApiModule = AstroApiModule;

})();
