/* versio 1.0.5 */

(function () {
    'use strict';

    /**
     * Run a callback after the DOM is available.
     *
     * @param {Function} callback
     * @return {void}
     */
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    /**
     * Read a cookie value.
     *
     * @param {string} name
     * @return {string}
     */
    function getCookie(name) {
        const parts = document.cookie.split(';').map(function (part) {
            return part.trim();
        });

        for (const part of parts) {
            if (part.indexOf(name + '=') === 0) {
                return decodeURIComponent(part.substring(name.length + 1));
            }
        }

        return '';
    }

    /**
     * Store a cookie value.
     *
     * @param {string} name
     * @param {string} value
     * @param {number} days
     * @return {void}
     */
    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));

        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + expires.toUTCString()
            + '; path=/; SameSite=Lax';
    }

/**
 * Return whether this looks like a normal interactive browser.
 *
 * @return {boolean}
 */
function isKnownInteractiveBrowser() {
    const userAgent = navigator.userAgent || '';

    const knownBrowser = /Chrome|CriOS|Firefox|FxiOS|Safari|Edg|OPR|Opera/i.test(userAgent);
    const knownBot = /bot|applebot|crawler|spider|slurp|bingpreview|google|bing|yandex|duckduck|baidu|semrush|ahrefs|mj12|curl|wget|python|php|validator|lighthouse|pagespeed/i.test(userAgent);

    if (!knownBrowser || knownBot) {
        return false;
    }

    return !!(window.fetch && window.URLSearchParams && document.addEventListener);
}

/**
 * Initialize the access gate overlay form.
 *
 * @return {void}
 */
function initAccessGate() {
    const overlay = document.getElementById('accessOverlay');

    if (!overlay) {
        return;
    }

    /*
     * The overlay exists in HTML for normal browsers, but should only be
     * activated for real interactive browsers.
     */
    if (!isKnownInteractiveBrowser()) {
        return;
    }

    overlay.hidden = false;
    document.body.classList.add('access-gate-active');

    const emailForm = document.getElementById('accessEmailForm');
    const codeForm = document.getElementById('accessCodeForm');
    const emailInput = document.getElementById('accessEmail');
    const codeInput = document.getElementById('accessCode');
    const codeEmailInput = document.getElementById('accessCodeEmail');
    const message = document.getElementById('accessMessage');
    const resendButton = document.getElementById('accessResendButton');

    if (!emailForm || !codeForm || !emailInput || !codeInput || !codeEmailInput || !message) {
        console.warn('Access gate form is incomplete.');
        return;
    }

    let resendCooldown = 0;
    let resendTimer = null;

    function setMessage(text, isError) {
        message.textContent = text || '';
        message.className = 'access-message' + (isError ? ' error' : ' success');
    }

    async function postForm(url, data) {
        const body = new URLSearchParams();

        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body.toString(),
            credentials: 'same-origin'
        });

        const rawText = await response.text();

        try {
            return JSON.parse(rawText);
        } catch (error) {
            throw new Error('Server did not return JSON: ' + rawText.substring(0, 200));
        }
    }

    function startResendCooldown() {
        if (!resendButton) {
            return;
        }

        resendCooldown = 30;
        resendButton.disabled = true;
        resendButton.textContent = 'Resend code (' + resendCooldown + ')';

        if (resendTimer) {
            clearInterval(resendTimer);
        }

        resendTimer = setInterval(function () {
            resendCooldown--;

            if (resendCooldown <= 0) {
                clearInterval(resendTimer);
                resendButton.disabled = false;
                resendButton.textContent = 'Resend code';
                return;
            }

            resendButton.textContent = 'Resend code (' + resendCooldown + ')';
        }, 1000);
    }

    async function requestCode() {
        const email = emailInput.value.trim();

        if (!email) {
            setMessage('Please enter your email address.', true);
            return;
        }

        setMessage('Sending access email...', false);

        try {
            const result = await postForm('/access/request-code', {
                email: email,
                return_to: window.location.pathname + window.location.search
            });

            if (!result.ok) {
                setMessage(result.message || 'Could not send the access email.', true);
                return;
            }

            codeEmailInput.value = result.email || email;
            codeForm.style.display = '';
            codeInput.focus();

            setMessage(result.message || 'Access email sent.', false);
            startResendCooldown();
        } catch (error) {
            console.error(error);
            setMessage('Could not contact the server. Please try again.', true);
        }
    }

    async function verifyCode() {
        const email = codeEmailInput.value.trim() || emailInput.value.trim();
        const code = codeInput.value.trim();

        if (!email || !code) {
            setMessage('Please enter the code from your email.', true);
            return;
        }

        setMessage('Verifying code...', false);

        try {
            const result = await postForm('/access/verify-code', {
                email: email,
                code: code, 
		return_to: window.location.pathname + window.location.search
            });

            if (!result.ok) {
                setMessage(result.message || 'The code could not be verified.', true);
                return;
            }

            setMessage(result.message || 'Access granted.', false);
            //window.location.href = result.redirect || window.location.href;
	   const redirectUrl = result.redirect || window.location.pathname + window.location.search;
           const separator = redirectUrl.indexOf('?') === -1 ? '?' : '&';
 
           window.location.href = redirectUrl + separator + 'access_verified=' + Date.now();
        } catch (error) {
            console.error(error);
            setMessage('Could not contact the server. Please try again.', true);
        }
    }

    emailForm.addEventListener('submit', function (event) {
        event.preventDefault();
        requestCode();
    });

    codeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        verifyCode();
    });

    if (resendButton) {
        resendButton.addEventListener('click', function () {
            if (resendCooldown > 0) {
                return;
            }

            requestCode();
        });
    }
}

//--

    /**
     * Initialize collapse/expand behavior for books in the documentation tree.
     *
     * @return {void}
     */
    function initTreeCollapse() {
        const cookieName = 'doc_tree_collapsed_books';

        /**
         * Read the collapsed book list from the cookie.
         *
         * @return {string[]}
         */
        function readCollapsedBooks() {
            const value = getCookie(cookieName);

            if (!value) {
                return [];
            }

            try {
                const parsed = JSON.parse(value);

                if (Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (error) {
                return [];
            }

            return [];
        }

        /**
         * Store the collapsed book list in the cookie.
         *
         * @param {string[]} items
         * @return {void}
         */
        function writeCollapsedBooks(items) {
            setCookie(cookieName, JSON.stringify(items), 180);
        }

        /**
         * Apply the collapsed/expanded visual state to a book element.
         *
         * @param {HTMLElement} bookElement
         * @param {boolean} collapsed
         * @return {void}
         */
        function applyState(bookElement, collapsed) {
            const button = bookElement.querySelector('.tree-book-toggle');
            const icon = bookElement.querySelector('.tree-book-toggle-icon');

            bookElement.classList.toggle('collapsed', collapsed);

            if (button) {
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }

            if (icon) {
                icon.textContent = collapsed ? '▸' : '▾';
            }
        }

        const collapsedBooks = readCollapsedBooks();

        document.querySelectorAll('.doc-tree .tree-book[data-book-slug]').forEach(function (bookElement) {
            const slug = bookElement.getAttribute('data-book-slug');
            const hasActivePage = bookElement.querySelector('.tree-page.active') !== null;

            if (!slug) {
                return;
            }

            /*
             * If a page inside this book is currently open,
             * always expand this book, even when the cookie says it is collapsed.
             */
            if (hasActivePage) {
                const index = collapsedBooks.indexOf(slug);

                if (index !== -1) {
                    collapsedBooks.splice(index, 1);
                    writeCollapsedBooks(collapsedBooks);
                }

                applyState(bookElement, false);
            } else {
                applyState(bookElement, collapsedBooks.indexOf(slug) !== -1);
            }

            const button = bookElement.querySelector('.tree-book-toggle');

            if (!button) {
                return;
            }

            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const isCollapsed = bookElement.classList.contains('collapsed');
                const newCollapsed = !isCollapsed;

                applyState(bookElement, newCollapsed);

                const current = readCollapsedBooks();
                const index = current.indexOf(slug);

                if (newCollapsed && index === -1) {
                    current.push(slug);
                }

                if (!newCollapsed && index !== -1) {
                    current.splice(index, 1);
                }

                writeCollapsedBooks(current);
            });
        });
    }

    /**
     * Initialize the draggable documentation-tree width resizer.
     *
     * @return {void}
     */
    function initDocTreeResizer() {
        const split = document.getElementById('docSplit');
        const resizer = document.getElementById('docResizer');

        if (!split || !resizer) {
            return;
        }

        const storageKey = 'bookstack_public_nav_width';

        /**
         * Calculate the maximum allowed navigation width.
         *
         * @return {number}
         */
        function maxWidth() {
            return Math.floor(window.innerWidth * 0.25);
        }

        /**
         * Calculate the minimum allowed navigation width.
         *
         * @return {number}
         */
        function minWidth() {
            return Math.min(240, maxWidth());
        }

        /**
         * Apply a new navigation width.
         *
         * @param {number} width
         * @return {void}
         */
        function applyWidth(width) {
            const min = minWidth();
            const max = maxWidth();
            const safeWidth = Math.max(min, Math.min(max, width));

            split.style.setProperty('--doc-nav-width', safeWidth + 'px');
            localStorage.setItem(storageKey, String(safeWidth));
        }

        const saved = parseInt(localStorage.getItem(storageKey) || '', 10);

        if (!Number.isNaN(saved)) {
            applyWidth(saved);
        } else {
            applyWidth(maxWidth());
        }

        let dragging = false;

        resizer.addEventListener('pointerdown', function (event) {
            dragging = true;
            document.body.classList.add('resizing-doc-nav');
            resizer.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        window.addEventListener('pointermove', function (event) {
            if (!dragging) {
                return;
            }

            const splitRect = split.getBoundingClientRect();
            const requestedWidth = event.clientX - splitRect.left;

            applyWidth(requestedWidth);
        });

        window.addEventListener('pointerup', function () {
            dragging = false;
            document.body.classList.remove('resizing-doc-nav');
        });

        window.addEventListener('resize', function () {
            const current = parseInt(
                getComputedStyle(split).getPropertyValue('--doc-nav-width'),
                10
            );

            if (!Number.isNaN(current)) {
                applyWidth(current);
            }
        });
    }

    ready(function () {
        initAccessGate();
        initTreeCollapse();
        initDocTreeResizer();
    });
})();

