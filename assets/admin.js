/*
 * ARVE'S House admin panel and customer area: theme, menus, search, notifications, charts, dialogs.
 * Loaded at the end of every page by includes/admin-shell.php and includes/customer-shell.php.
 * Addresses are relative (search.php, notifications.php): each area answers them in its own folder.
 * No libraries.
 *
 * What pages can use (all optional, all plain HTML attributes):
 *   <form data-confirm="Delete this room?" data-confirm-ok="Delete" data-confirm-tone="danger">
 *   <button data-confirm="...">            a question before this button's form is sent
 *   <input data-table-filter="table-id">   hides the rows of that table that don't match
 *   <button data-copy="text">              copies the text
 *   <button data-dialog-open="id">         opens <dialog id="id">; data-dialog-close closes it;
 *                                          <dialog data-dialog-auto> is open when the page arrives
 *   <div class="chart" data-chart> <script type="application/json">{...}</script> </div>
 *   <button data-chart-table="chart-id">   shows the chart's numbers as a table
 *   adminToast("Saved")                    a short message at the bottom of the screen
 */
(function () {
    "use strict";

    const doc = document;
    const root = doc.documentElement;
    const phone = window.matchMedia("(max-width: 767px)");
    const drawer = window.matchMedia("(max-width: 1023px)");

    const $ = (selector, scope) => (scope || doc).querySelector(selector);
    const $$ = (selector, scope) => Array.from((scope || doc).querySelectorAll(selector));

    function csrf() {
        const meta = $('meta[name="csrf-token"]');
        return meta ? meta.content : "";
    }

    function el(tag, className, text) {
        const node = doc.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function svg(tag, attributes) {
        const node = doc.createElementNS("http://www.w3.org/2000/svg", tag);
        for (const name in attributes || {}) node.setAttribute(name, attributes[name]);
        return node;
    }


    // ======================================================
    // TOASTS
    // ======================================================

    const CHECK = '<svg class="i" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
    const WARN = '<svg class="i" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';

    function toast(message, isError) {
        const holder = $("#toasts");
        if (!holder) return;

        const node = el("div", "toast" + (isError ? " is-error" : ""));
        node.innerHTML = isError ? WARN : CHECK;
        node.appendChild(el("span", "", message));
        holder.appendChild(node);

        // next frame, so the browser sees the starting state and animates from it
        requestAnimationFrame(() => requestAnimationFrame(() => node.classList.add("is-on")));

        setTimeout(() => {
            node.classList.remove("is-on");
            setTimeout(() => node.remove(), 250);
        }, isError ? 5000 : 3000);
    }

    window.adminToast = toast;


    // ======================================================
    // LIGHT AND DARK
    // ======================================================

    // The customer area uses this file too: <html data-theme-cookie> names its own cookie, and
    // <meta name="theme-color" data-light data-dark> gives its own colors for the browser bar.
    const themeColor = $('meta[name="theme-color"]');
    const THEME_COOKIE = root.dataset.themeCookie || "arves_admin_theme";
    const THEME_COLORS = {
        dark: (themeColor && themeColor.dataset.dark) || "#060912",
        light: (themeColor && themeColor.dataset.light) || "#f3f5fa",
    };

    function setTheme(theme) {
        root.dataset.theme = theme;
        doc.cookie = THEME_COOKIE + "=" + theme + "; path=/; max-age=31536000; samesite=lax";

        if (themeColor) themeColor.content = THEME_COLORS[theme];
    }

    $$("[data-theme-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
            setTheme(root.dataset.theme === "light" ? "dark" : "light");
        });
    });


    // ======================================================
    // TOP BAR: a line under it once the page has scrolled
    // ======================================================

    const topbar = $("#topbar");

    if (topbar) {
        const mark = () => topbar.classList.toggle("is-stuck", window.scrollY > 4);
        window.addEventListener("scroll", mark, { passive: true });
        mark();
    }


    // ======================================================
    // SIDE MENU AS A DRAWER (tablets and phones)
    // ======================================================

    const sidebar = $("#sidebar");
    const scrim = $(".scrim");
    let drawerOpener = null;

    // on a short screen the menu scrolls: start with the current page in view
    const currentPage = $(".sidebar .nav-item.is-active");
    const menuScroll = $(".sidebar-scroll");

    if (currentPage && menuScroll) {
        const item = currentPage.getBoundingClientRect();
        const box = menuScroll.getBoundingClientRect();

        if (item.bottom > box.bottom - 8) {
            menuScroll.scrollTop += item.bottom - box.bottom + 24;
        }
    }

    function setDrawer(open) {
        if (!sidebar) return;

        sidebar.classList.toggle("is-open", open);
        if (scrim) scrim.classList.toggle("is-open", open);
        doc.body.style.overflow = open ? "hidden" : "";
        $$("[data-sidebar-open]").forEach((button) => button.setAttribute("aria-expanded", String(open)));

        sidebar.style.transform = "";
        sidebar.style.transition = "";

        if (open) {
            const current = $(".nav-item.is-active", sidebar) || $(".nav-item", sidebar);
            if (current) current.focus({ preventScroll: true });
        } else if (drawerOpener) {
            drawerOpener.focus({ preventScroll: true });
            drawerOpener = null;
        }
    }

    $$("[data-sidebar-open]").forEach((button) => {
        button.addEventListener("click", () => {
            drawerOpener = button;
            setDrawer(!sidebar.classList.contains("is-open"));
        });
    });

    $$("[data-sidebar-close]").forEach((node) => node.addEventListener("click", () => setDrawer(false)));

    drawer.addEventListener("change", (event) => {
        if (!event.matches) setDrawer(false);
    });

    // drag the drawer to the left to close it; a quick flick is enough
    if (sidebar) {
        let startX = 0;
        let startY = 0;
        let startTime = 0;
        let dragging = false;
        let decided = false;
        let pointer = null;

        sidebar.addEventListener("pointerdown", (event) => {
            if (!sidebar.classList.contains("is-open") || event.pointerType === "mouse" || pointer !== null) return;
            pointer = event.pointerId;
            startX = event.clientX;
            startY = event.clientY;
            startTime = event.timeStamp;
            dragging = false;
            decided = false;
        });

        sidebar.addEventListener("pointermove", (event) => {
            if (event.pointerId !== pointer) return;

            const dx = event.clientX - startX;
            const dy = event.clientY - startY;

            if (!decided) {
                if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
                decided = true;
                dragging = Math.abs(dx) > Math.abs(dy) && dx < 0;
                if (dragging) sidebar.style.transition = "none";
            }

            if (dragging) sidebar.style.transform = "translateX(" + Math.min(0, dx) + "px)";
        });

        const release = (event) => {
            if (event.pointerId !== pointer) return;
            pointer = null;

            if (!dragging) return;
            dragging = false;

            const dx = Math.min(0, event.clientX - startX);
            const speed = Math.abs(dx) / Math.max(1, event.timeStamp - startTime);

            sidebar.style.transition = "";
            sidebar.style.transform = "";

            if (Math.abs(dx) > sidebar.offsetWidth * 0.35 || speed > 0.5) setDrawer(false);
        };

        sidebar.addEventListener("pointerup", release);
        sidebar.addEventListener("pointercancel", release);
    }


    // ======================================================
    // DROP-DOWN MENUS
    // ======================================================

    let openMenu = null;
    let openTrigger = null;

    function closeMenu(returnFocus) {
        if (!openMenu) return;

        openMenu.classList.remove("is-open", "is-instant");
        openTrigger.setAttribute("aria-expanded", "false");
        if (returnFocus) openTrigger.focus({ preventScroll: true });

        openMenu = null;
        openTrigger = null;
    }

    function showMenu(trigger, menu, byKeyboard) {
        closeMenu(false);

        // opened from the keyboard: it appears at once, without the animation
        menu.classList.toggle("is-instant", byKeyboard);
        menu.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
        openMenu = menu;
        openTrigger = trigger;

        if (byKeyboard) {
            const first = $(".menu-item, a, button", menu);
            if (first) first.focus({ preventScroll: true });
        }

        menu.dispatchEvent(new CustomEvent("menu:open"));
    }

    $$("[data-menu]").forEach((trigger) => {
        const menu = doc.getElementById(trigger.dataset.menu);
        if (!menu) return;

        trigger.addEventListener("click", (event) => {
            // on a phone the bell simply opens the notifications page
            if (trigger.tagName === "A" && phone.matches) return;

            event.preventDefault();

            if (openMenu === menu) {
                closeMenu(false);
            } else {
                showMenu(trigger, menu, event.detail === 0);
            }
        });

        // a link used as a button also answers to the space bar
        if (trigger.tagName === "A") {
            trigger.addEventListener("keydown", (event) => {
                if (event.key === " ") {
                    event.preventDefault();
                    trigger.click();
                }
            });
        }
    });

    doc.addEventListener("click", (event) => {
        if (openMenu && !openMenu.contains(event.target) && !openTrigger.contains(event.target)) {
            closeMenu(false);
        }
    });

    doc.addEventListener("keydown", (event) => {
        if (!openMenu || !["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;

        const items = $$(".menu-item, .feed-item[href], .notif-foot, .link-btn:not(:disabled)", openMenu);
        if (!items.length) return;

        event.preventDefault();

        let index = items.indexOf(doc.activeElement);

        if (event.key === "Home") index = 0;
        else if (event.key === "End") index = items.length - 1;
        else if (event.key === "ArrowDown") index = (index + 1) % items.length;
        else index = index <= 0 ? items.length - 1 : index - 1;

        items[index].focus();
    });


    // ======================================================
    // SEARCH
    // ======================================================

    const search = $("#search");
    const searchInput = $("#search-input");
    const searchPanel = $("#search-panel");

    if (search && searchInput && searchPanel) {
        let hits = [];
        let current = -1;
        let timer = 0;
        let request = null;
        let lastQuery = null;

        // with nothing typed: the pages of the menu
        const pages = $$(".sidebar .nav-item").map((link) => ({
            title: link.querySelector("span").textContent.trim(),
            sub: "",
            href: link.getAttribute("href"),
            icon: link.querySelector("svg").outerHTML,
        }));

        const setOpen = (open) => {
            search.classList.toggle("is-open", open);
            searchInput.setAttribute("aria-expanded", String(open));
        };

        const setCurrent = (index) => {
            current = index;

            hits.forEach((hit, i) => {
                hit.classList.toggle("is-current", i === index);
                hit.setAttribute("aria-selected", String(i === index));
            });

            if (hits[index]) {
                hits[index].scrollIntoView({ block: "nearest" });
                searchInput.setAttribute("aria-activedescendant", hits[index].id);
            } else {
                searchInput.removeAttribute("aria-activedescendant");
            }
        };

        const render = (groups, emptyText) => {
            searchPanel.textContent = "";
            hits = [];

            groups.forEach((group) => {
                if (!group.items.length) return;

                searchPanel.appendChild(el("div", "search-group", group.title));

                group.items.forEach((item) => {
                    const link = el("a", "search-hit");
                    link.href = item.href;
                    link.id = "search-hit-" + hits.length;
                    link.setAttribute("role", "option");

                    const iconBox = el("span", "search-hit-icon");
                    iconBox.innerHTML = item.icon || "";   // drawn by the server, never typed by a person

                    const text = el("span", "search-hit-text");
                    text.appendChild(el("span", "search-hit-title", item.title));
                    if (item.sub) text.appendChild(el("span", "search-hit-sub", item.sub));

                    link.appendChild(iconBox);
                    link.appendChild(text);
                    searchPanel.appendChild(link);
                    hits.push(link);
                });
            });

            if (!hits.length) searchPanel.appendChild(el("div", "search-note", emptyText));

            setCurrent(hits.length ? 0 : -1);
        };

        const showPages = () => render([{ title: "Go to", items: pages }], "");

        const run = (query) => {
            if (query === lastQuery) return;
            lastQuery = query;

            if (request) request.abort();

            if (query.length < 2) {
                showPages();
                return;
            }

            request = new AbortController();

            fetch("search.php?q=" + encodeURIComponent(query), {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
                signal: request.signal,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.ok) throw new Error(data.error || "Search failed");
                    render(data.groups, 'Nothing found for "' + query + '"');
                })
                .catch((error) => {
                    if (error.name === "AbortError") return;
                    render([], "Search is not available right now. Please try again.");
                });
        };

        const close = () => {
            setOpen(false);
            search.classList.remove("is-active");
            doc.body.style.overflow = "";
        };

        searchInput.addEventListener("focus", () => {
            if (searchInput.value.trim().length < 2) {
                lastQuery = null;
                showPages();
            }
            setOpen(true);
        });

        searchInput.addEventListener("input", () => {
            clearTimeout(timer);
            const query = searchInput.value.trim();
            setOpen(true);
            timer = setTimeout(() => run(query), query.length < 2 ? 0 : 200);
        });

        searchInput.addEventListener("keydown", (event) => {
            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                if (!hits.length) return;
                event.preventDefault();
                const step = event.key === "ArrowDown" ? 1 : -1;
                setCurrent((current + step + hits.length) % hits.length);
            } else if (event.key === "Enter") {
                if (hits[current]) {
                    event.preventDefault();
                    window.location.href = hits[current].href;
                }
            } else if (event.key === "Escape") {
                event.preventDefault();
                searchInput.value = "";
                lastQuery = null;
                close();
                searchInput.blur();
            }
        });

        doc.addEventListener("click", (event) => {
            if (!search.contains(event.target)) setOpen(false);
        });

        // "/" or Ctrl+K from anywhere
        doc.addEventListener("keydown", (event) => {
            const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable;
            const slash = event.key === "/" && !typing && !event.ctrlKey && !event.metaKey && !event.altKey;
            const shortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k";

            if (!slash && !shortcut) return;

            event.preventDefault();
            closeMenu(false);

            if (phone.matches) {
                search.classList.add("is-active");
                doc.body.style.overflow = "hidden";
            }

            searchInput.focus();
            searchInput.select();
        });

        // on a phone the search is opened from the menu: the menu closes, the search sheet opens
        $$("[data-search-open]").forEach((button) => {
            button.addEventListener("click", () => {
                drawerOpener = null;
                setDrawer(false);
                search.classList.add("is-active");
                doc.body.style.overflow = "hidden";
                searchInput.focus();
            });
        });

        $$("[data-search-close]").forEach((button) => {
            button.addEventListener("click", (event) => {
                event.preventDefault();
                searchInput.value = "";
                lastQuery = null;
                close();
            });
        });

        phone.addEventListener("change", close);
    }


    // ======================================================
    // NOTIFICATIONS
    // ======================================================

    const notifList = $("[data-notif-list]");
    const notifReadAll = $("[data-notif-read-all]");
    const notifMenu = $("#notif-menu");
    let notifChecked = Date.now();

    function showUnread(count) {
        $$("[data-notif-count]").forEach((badge) => {
            badge.textContent = count > 99 ? "99+" : String(count);
            badge.hidden = count < 1;
        });

        const bell = $("[data-notif-bell]");
        if (bell) bell.setAttribute("aria-label", "Notifications" + (count > 0 ? ", " + count + " unread" : ""));
        if (notifReadAll) notifReadAll.disabled = count < 1;
    }

    function loadNotifications(what, options) {
        return fetch("notifications.php?api=" + what, Object.assign({
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        }, options || {}))
            .then((response) => response.json())
            .then((data) => {
                if (!data.ok) throw new Error(data.error || "Could not load notifications");

                notifChecked = Date.now();
                showUnread(data.unread);
                if (notifList && typeof data.html === "string") notifList.innerHTML = data.html;

                return data;
            });
    }

    if (notifMenu) {
        notifMenu.addEventListener("menu:open", () => {
            loadNotifications("list").catch(() => {});
        });
    }

    if (notifReadAll) {
        notifReadAll.addEventListener("click", () => {
            notifReadAll.disabled = true;

            const body = new FormData();
            body.append("csrf_token", csrf());

            loadNotifications("read_all", { method: "POST", body: body })
                .then(() => toast("All notifications marked as read"))
                .catch(() => {
                    notifReadAll.disabled = false;
                    toast("Could not mark them as read. Please try again.", true);
                });
        });
    }

    // Nothing runs in the background (the host does not allow it). The count is checked again
    // when the admin comes back to this tab after a minute or more away.
    doc.addEventListener("visibilitychange", () => {
        if (doc.visibilityState === "visible" && Date.now() - notifChecked > 60000) {
            loadNotifications("count").catch(() => {});
        }
    });


    // ======================================================
    // "ARE YOU SURE?" BEFORE A FORM IS SENT
    // ======================================================

    const confirmDialog = $("#confirm-dialog");

    doc.addEventListener("submit", (event) => {
        const form = event.target;
        const button = event.submitter;
        const question = (button && button.dataset.confirm) || form.dataset.confirm;

        if (!question || form.dataset.confirmed === "1") {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();

        const source = button && button.dataset.confirm ? button : form;
        const okText = source.dataset.confirmOk || "Continue";
        const danger = source.dataset.confirmTone === "danger";

        const send = () => {
            form.dataset.confirmed = "1";
            if (form.requestSubmit) form.requestSubmit(button || undefined);
            else form.submit();
        };

        if (!confirmDialog || !confirmDialog.showModal) {
            if (window.confirm(question)) send();
            return;
        }

        $("#confirm-title").textContent = source.dataset.confirmTitle || "Are you sure?";
        $("#confirm-text").textContent = question;

        const ok = $("#confirm-ok");
        ok.textContent = okText;
        ok.className = "btn " + (danger ? "btn-danger" : "btn-primary");

        confirmDialog.returnValue = "cancel";
        confirmDialog.showModal();

        confirmDialog.addEventListener("close", function done() {
            confirmDialog.removeEventListener("close", done);
            if (confirmDialog.returnValue === "ok") send();
        });
    });

    // a dialog that must be open when the page arrives (a form that came back with errors)
    $$("dialog[data-dialog-auto]").forEach((dialog) => {
        if (dialog.showModal && !dialog.open) dialog.showModal();
    });

    // dialogs opened by a button: <button data-dialog-open="dialog-id">, closed by data-dialog-close
    doc.addEventListener("click", (event) => {
        const opener = event.target.closest("[data-dialog-open]");

        if (opener) {
            const dialog = doc.getElementById(opener.dataset.dialogOpen);
            if (dialog && dialog.showModal) dialog.showModal();
            return;
        }

        const closer = event.target.closest("[data-dialog-close]");

        if (closer) {
            const dialog = closer.closest("dialog");
            if (dialog) dialog.close();
            return;
        }

        // a click on the dark area around a dialog closes it
        if (event.target.tagName === "DIALOG" && event.target.open) {
            const box = event.target.getBoundingClientRect();
            const inside = event.clientX >= box.left && event.clientX <= box.right
                && event.clientY >= box.top && event.clientY <= box.bottom;
            if (!inside) event.target.close();
        }
    });


    // ======================================================
    // SMALL HELPERS FOR PAGES
    // ======================================================

    $$("[data-table-filter]").forEach((input) => {
        const table = doc.getElementById(input.dataset.tableFilter);
        if (!table) return;

        const rows = $$("tbody tr", table).filter((row) => !row.hasAttribute("data-filter-empty"));
        const empty = $("[data-filter-empty]", table);

        input.addEventListener("input", () => {
            const words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            let shown = 0;

            rows.forEach((row) => {
                const text = row.textContent.toLowerCase();
                const match = words.every((word) => text.includes(word));
                row.hidden = !match;
                if (match) shown++;
            });

            if (empty) empty.hidden = shown > 0;
        });
    });

    doc.addEventListener("click", (event) => {
        const button = event.target.closest("[data-copy]");
        if (!button || !navigator.clipboard) return;

        navigator.clipboard.writeText(button.dataset.copy)
            .then(() => toast("Copied"))
            .catch(() => toast("Could not copy", true));
    });

    // Escape closes whatever is open, the newest thing first
    doc.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;

        if (openMenu) {
            closeMenu(true);
        } else if (sidebar && sidebar.classList.contains("is-open")) {
            setDrawer(false);
        }
    });


    // ======================================================
    // CHARTS
    // ======================================================

    const formats = {
        peso: (value) => "₱" + Number(value).toLocaleString("en-PH", {
            minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
            maximumFractionDigits: 2,
        }),
        number: (value) => Number(value).toLocaleString("en-PH", { maximumFractionDigits: 1 }),
        percent: (value) => Number(value).toLocaleString("en-PH", { maximumFractionDigits: 1 }) + "%",
    };

    function compact(value) {
        const abs = Math.abs(value);
        if (abs >= 1000000) return trim(value / 1000000) + "M";
        if (abs >= 1000) return trim(value / 1000) + "K";
        return trim(value);
    }

    function trim(value) {
        return String(Math.round(value * 10) / 10);
    }

    function tickText(format, value) {
        if (format === "peso") return "₱" + compact(value);
        if (format === "percent") return trim(value) + "%";
        return compact(value);
    }

    // round steps for the side of the chart: 0 / 500 / 1,000 / 1,500
    function niceScale(max, wholeNumbers) {
        if (max <= 0) max = wholeNumbers ? 4 : 1;

        const rough = max / 4;
        const power = Math.pow(10, Math.floor(Math.log10(rough)));
        const fraction = rough / power;
        let step = (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10) * power;

        if (wholeNumbers) step = Math.max(1, Math.ceil(step));

        const ticks = [];
        for (let value = 0; value < max + step - 1e-9; value += step) ticks.push(Math.round(value * 1e6) / 1e6);

        return { max: ticks[ticks.length - 1], ticks: ticks };
    }

    // a smooth line that never swings past the numbers it joins (monotone cubic)
    function smoothPath(points) {
        const n = points.length;
        if (n === 0) return "";
        if (n === 1) return "M" + points[0][0] + " " + points[0][1];

        const dx = [];
        const slope = [];

        for (let i = 0; i < n - 1; i++) {
            dx[i] = points[i + 1][0] - points[i][0];
            slope[i] = (points[i + 1][1] - points[i][1]) / dx[i];
        }

        const tangent = [slope[0]];

        for (let i = 1; i < n - 1; i++) {
            if (slope[i - 1] * slope[i] <= 0) {
                tangent[i] = 0;
            } else {
                const w1 = 2 * dx[i] + dx[i - 1];
                const w2 = dx[i] + 2 * dx[i - 1];
                tangent[i] = (w1 + w2) / (w1 / slope[i - 1] + w2 / slope[i]);
            }
        }

        tangent[n - 1] = slope[n - 2];

        let path = "M" + points[0][0].toFixed(2) + " " + points[0][1].toFixed(2);

        for (let i = 0; i < n - 1; i++) {
            const third = dx[i] / 3;
            path += "C" + (points[i][0] + third).toFixed(2) + " " + (points[i][1] + tangent[i] * third).toFixed(2)
                + " " + (points[i + 1][0] - third).toFixed(2) + " " + (points[i + 1][1] - tangent[i + 1] * third).toFixed(2)
                + " " + points[i + 1][0].toFixed(2) + " " + points[i + 1][1].toFixed(2);
        }

        return path;
    }

    function drawChart(holder) {
        const source = $('script[type="application/json"]', holder);
        if (!source) return;

        let config;

        try {
            config = JSON.parse(source.textContent);
        } catch (error) {
            return;
        }

        const labels = config.labels || [];
        const series = (config.series || []).slice(0, 3);
        const count = labels.length;
        const format = formats[config.format] ? config.format : "number";
        const show = formats[format];
        const isBars = config.type === "bars";

        if (!count || !series.length) return;

        let tip = $(".chart-tip", holder);
        let picture = $("svg", holder);

        if (!tip) {
            tip = el("div", "chart-tip");
            tip.setAttribute("role", "status");
            holder.appendChild(tip);
        }

        if (picture) picture.remove();

        const styles = getComputedStyle(holder);
        const width = Math.max(220, holder.clientWidth - parseFloat(styles.paddingLeft) - parseFloat(styles.paddingRight));
        const height = phone.matches ? Math.min(config.height || 220, 200) : (config.height || 220);

        const highest = Math.max.apply(null, series.map((one) => Math.max.apply(null, one.values.concat([0]))));
        const wholeNumbers = format === "number" && series.every((one) => one.values.every(Number.isInteger));
        const scale = niceScale(format === "percent" ? Math.min(100, Math.max(highest, 1)) : highest, wholeNumbers);

        const tickLabels = scale.ticks.map((value) => tickText(format, value));
        const left = Math.max.apply(null, tickLabels.map((text) => text.length)) * 6.4 + 12;
        const box = { left: left, right: 14, top: 12, bottom: 26 };
        const plotWidth = width - box.left - box.right;
        const plotHeight = height - box.top - box.bottom;
        const band = plotWidth / count;

        const x = (index) => isBars
            ? box.left + band * (index + 0.5)
            : box.left + (count === 1 ? plotWidth / 2 : plotWidth * index / (count - 1));
        const y = (value) => box.top + plotHeight - (value / scale.max) * plotHeight;

        picture = svg("svg", {
            width: width,
            height: height,
            viewBox: "0 0 " + width + " " + height,
            role: "img",
            tabindex: "0",
            "aria-label": config.label || "Chart. Use the left and right arrow keys to read the values.",
        });

        // lines across, each with its number
        scale.ticks.forEach((value, index) => {
            const line = svg("line", {
                class: value === 0 ? "chart-base" : "chart-grid",
                x1: box.left,
                x2: width - box.right,
                y1: Math.round(y(value)) + 0.5,
                y2: Math.round(y(value)) + 0.5,
            });
            picture.appendChild(line);

            const text = svg("text", { x: box.left - 8, y: y(value) + 4, "text-anchor": "end" });
            text.textContent = tickLabels[index];
            picture.appendChild(text);
        });

        // a few dates along the bottom, as many as fit, counted back from the newest
        const every = Math.max(1, Math.ceil(count / Math.max(2, Math.floor(plotWidth / 78))));

        for (let index = count - 1; index >= 0; index -= every) {
            const words = (config.ticks && config.ticks[index]) || labels[index];
            const half = words.length * 3.2;
            let anchor = "middle";

            if (x(index) + half > width - 2) anchor = "end";
            else if (x(index) - half < 2) anchor = "start";

            const text = svg("text", { x: x(index), y: height - 7, "text-anchor": anchor });
            text.textContent = words;
            picture.appendChild(text);
        }

        const marks = [];

        if (isBars) {
            const thick = Math.max(3, Math.min(24, band - 2));

            series[0].values.forEach((value, index) => {
                const top = y(value);
                const tall = box.top + plotHeight - top;
                const round = Math.min(4, thick / 2, tall);
                const x0 = x(index) - thick / 2;
                const base = box.top + plotHeight;

                // round at the top, square where it stands on the line
                const bar = svg("path", {
                    class: "chart-bar",
                    d: tall <= 0 ? "" : "M" + x0 + " " + base + "V" + (top + round) + "Q" + x0 + " " + top + " " + (x0 + round) + " " + top
                        + "H" + (x0 + thick - round) + "Q" + (x0 + thick) + " " + top + " " + (x0 + thick) + " " + (top + round) + "V" + base + "Z",
                });

                picture.appendChild(bar);
                marks.push(bar);
            });
        } else {
            series.forEach((one, s) => {
                const points = one.values.map((value, index) => [x(index), y(value)]);
                const name = s === 0 ? "" : " series-" + (s + 1);
                const path = smoothPath(points);

                if (s === 0 && series.length === 1 && config.area !== false && count > 1) {
                    const base = box.top + plotHeight;
                    picture.appendChild(svg("path", {
                        class: "chart-area",
                        d: path + "L" + points[count - 1][0].toFixed(2) + " " + base + "L" + points[0][0].toFixed(2) + " " + base + "Z",
                    }));
                }

                picture.appendChild(svg("path", { class: "chart-line" + name, d: path }));

                // the newest point carries a dot
                const last = points[count - 1];
                picture.appendChild(svg("circle", { class: "chart-dot" + name, cx: last[0], cy: last[1], r: 4 }));
            });
        }

        // what follows the pointer
        const cross = svg("line", { class: "chart-cross", y1: box.top, y2: box.top + plotHeight, visibility: "hidden" });
        picture.appendChild(cross);

        const dots = isBars ? [] : series.map((one, s) => {
            const dot = svg("circle", { class: "chart-dot" + (s === 0 ? "" : " series-" + (s + 1)), r: 4.5, visibility: "hidden" });
            picture.appendChild(dot);
            return dot;
        });

        const catcher = svg("rect", {
            x: box.left - 6,
            y: 0,
            width: plotWidth + 12,
            height: height,
            fill: "transparent",
        });
        picture.appendChild(catcher);

        holder.insertBefore(picture, tip);

        let shown = -1;

        const hide = () => {
            shown = -1;
            cross.setAttribute("visibility", "hidden");
            dots.forEach((dot) => dot.setAttribute("visibility", "hidden"));
            marks.forEach((mark) => mark.classList.remove("is-current"));
            holder.classList.remove("is-hovering");
            tip.classList.remove("is-on");
        };

        const point = (index) => {
            index = Math.max(0, Math.min(count - 1, index));
            if (index === shown) return;
            shown = index;

            const px = x(index);

            if (isBars) {
                holder.classList.add("is-hovering");
                marks.forEach((mark, i) => mark.classList.toggle("is-current", i === index));
            } else {
                cross.setAttribute("x1", Math.round(px) + 0.5);
                cross.setAttribute("x2", Math.round(px) + 0.5);
                cross.setAttribute("visibility", "visible");

                dots.forEach((dot, s) => {
                    dot.setAttribute("cx", px);
                    dot.setAttribute("cy", y(series[s].values[index]));
                    dot.setAttribute("visibility", "visible");
                });
            }

            // the number first, then what it is
            tip.textContent = "";
            tip.appendChild(el("div", "chart-tip-title", labels[index]));

            series.forEach((one, s) => {
                const row = el("div", "chart-tip-row");
                row.appendChild(el("span", "chart-tip-key" + (s === 0 ? "" : " series-" + (s + 1))));
                row.appendChild(el("span", "chart-tip-value", show(one.values[index])));
                row.appendChild(el("span", "chart-tip-name", one.name));
                tip.appendChild(row);
            });

            const offsetLeft = parseFloat(styles.paddingLeft);
            const tipWidth = tip.offsetWidth;
            const topValue = Math.max.apply(null, series.map((one) => one.values[index]));
            let tipLeft = offsetLeft + px + 12;

            if (tipLeft + tipWidth > holder.clientWidth - 4) tipLeft = offsetLeft + px - tipWidth - 12;
            tipLeft = Math.max(4, tipLeft);

            const tipTop = Math.max(0, Math.min(y(topValue) - tip.offsetHeight / 2, height - tip.offsetHeight - 8));

            tip.style.transform = "translate(" + Math.round(tipLeft) + "px," + Math.round(tipTop) + "px)";
            tip.classList.add("is-on");
        };

        const nearest = (event) => {
            const bounds = picture.getBoundingClientRect();
            const px = event.clientX - bounds.left;

            return isBars
                ? Math.floor((px - box.left) / band)
                : Math.round((px - box.left) / (plotWidth / Math.max(1, count - 1)));
        };

        catcher.addEventListener("pointermove", (event) => point(nearest(event)));
        catcher.addEventListener("pointerdown", (event) => point(nearest(event)));
        catcher.addEventListener("pointerleave", hide);

        picture.addEventListener("keydown", (event) => {
            if (event.key === "ArrowRight") point(shown < 0 ? count - 1 : shown + 1);
            else if (event.key === "ArrowLeft") point(shown < 0 ? count - 1 : shown - 1);
            else if (event.key === "Home") point(0);
            else if (event.key === "End") point(count - 1);
            else if (event.key === "Escape") hide();
            else return;

            event.preventDefault();
        });

        picture.addEventListener("focus", () => {
            if (picture.matches(":focus-visible")) point(count - 1);
        });
        picture.addEventListener("blur", hide);

        holder.chartConfig = config;
    }

    const charts = $$("[data-chart]");

    if (charts.length) {
        let frame = 0;
        const widths = new Map();

        const redraw = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                charts.forEach((holder) => {
                    // only when the width really changed: drawing changes the height
                    if (widths.get(holder) === holder.clientWidth) return;
                    widths.set(holder, holder.clientWidth);
                    drawChart(holder);
                });
            });
        };

        redraw();

        if (window.ResizeObserver) {
            const watcher = new ResizeObserver(redraw);
            charts.forEach((holder) => watcher.observe(holder));
        } else {
            window.addEventListener("resize", redraw);
        }

        phone.addEventListener("change", () => {
            widths.clear();
            redraw();
        });
    }

    // the chart's numbers as a table
    $$("[data-chart-table]").forEach((button) => {
        const holder = doc.getElementById(button.dataset.chartTable);
        if (!holder) return;

        let table = null;

        button.setAttribute("aria-expanded", "false");

        button.addEventListener("click", () => {
            const config = holder.chartConfig;
            if (!config) return;

            if (!table) {
                const show = formats[config.format] || formats.number;

                table = el("div", "chart-table scroll");
                table.hidden = true;

                const grid = el("table", "table");
                const head = el("tr");
                head.appendChild(el("th", "", config.axis || "Date"));
                config.series.forEach((one) => head.appendChild(el("th", "right", one.name)));

                const thead = el("thead");
                thead.appendChild(head);
                grid.appendChild(thead);

                const body = el("tbody");

                config.labels.forEach((label, index) => {
                    const row = el("tr");
                    row.appendChild(el("td", "", label));
                    config.series.forEach((one) => row.appendChild(el("td", "right num", show(one.values[index]))));
                    body.appendChild(row);
                });

                grid.appendChild(body);
                table.appendChild(grid);
                holder.insertAdjacentElement("afterend", table);
            }

            table.hidden = !table.hidden;
            button.setAttribute("aria-expanded", String(!table.hidden));
            button.classList.toggle("is-active", !table.hidden);
        });
    });
})();
