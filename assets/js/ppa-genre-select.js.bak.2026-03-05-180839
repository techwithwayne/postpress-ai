/* PostPress AI — Genre Select Enhancer
 * Native <select> + Search + Favorites + Recent (localStorage)
 *
 * Targets:
 * - select[data-ppa-genre-select="1"]  (preferred)
 * - #ppa-genre                         (fallback)
 *
 * Notes:
 * - Additive UI only (admin.js still owns payload + requests).
 * - Uses localStorage when available; silently degrades if blocked.
 */
(function () {
	"use strict";

	const LS_RECENT_KEY = "ppa_genre_recent_v1";
	const LS_FAV_KEY = "ppa_genre_favs_v1";
	const MAX_RECENT = 8;

	function safeGetStorage(key, fallback) {
		try {
			if (!window.localStorage) return fallback;
			const raw = window.localStorage.getItem(key);
			if (!raw) return fallback;
			const parsed = JSON.parse(raw);
			return parsed ?? fallback;
		} catch (e) {
			return fallback;
		}
	}

	function safeSetStorage(key, value) {
		try {
			if (!window.localStorage) return;
			window.localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// localStorage disabled; silently degrade
		}
	}

	function uniqPreserveOrder(arr) {
		const seen = new Set();
		const out = [];
		for (const x of arr) {
			if (!x || seen.has(x)) continue;
			seen.add(x);
			out.push(x);
		}
		return out;
	}

	function getRecent() {
		return safeGetStorage(LS_RECENT_KEY, []);
	}

	function setRecent(list) {
		const clean = uniqPreserveOrder(list).slice(0, MAX_RECENT);
		safeSetStorage(LS_RECENT_KEY, clean);
	}

	function getFavs() {
		return safeGetStorage(LS_FAV_KEY, []);
	}

	function setFavs(list) {
		const clean = uniqPreserveOrder(list);
		safeSetStorage(LS_FAV_KEY, clean);
	}

	function optionToData(optionEl, groupLabel) {
		return {
			value: optionEl.value,
			label: optionEl.textContent || "",
			group: groupLabel || "",
			disabled: !!optionEl.disabled,
		};
	}

	function snapshotSelect(selectEl) {
		// Captures original option order + optgroups (if present)
		const data = [];
		const children = Array.from(selectEl.children);
		const hasOptgroups = children.some((c) => c.tagName === "OPTGROUP");

		if (hasOptgroups) {
			for (const child of children) {
				if (child.tagName === "OPTGROUP") {
					const groupLabel = child.label || "";
					const opts = Array.from(child.querySelectorAll("option"));
					for (const opt of opts) data.push(optionToData(opt, groupLabel));
				} else if (child.tagName === "OPTION") {
					data.push(optionToData(child, ""));
				}
			}
		} else {
			const opts = Array.from(selectEl.querySelectorAll("option"));
			for (const opt of opts) data.push(optionToData(opt, ""));
		}

		return data;
	}

	function buildOptgroup(label, options, selectedValue) {
		const og = document.createElement("optgroup");
		og.label = label;

		for (const d of options) {
			const opt = document.createElement("option");
			opt.value = d.value;
			opt.textContent = d.label;
			opt.disabled = !!d.disabled;
			if (selectedValue !== null && opt.value === selectedValue) opt.selected = true;
			og.appendChild(opt);
		}

		return og;
	}

	function rebuildSelect(selectEl, baseData, query, favs, recent) {
		const selectedValue = selectEl.value;
		const q = (query || "").trim().toLowerCase();

		// Filter base data by query; always keep selected visible (even if it doesn't match query)
		let filteredBase = q
			? baseData.filter((d) => (d.label || "").toLowerCase().includes(q))
			: baseData.slice();

		const byValue = new Map();
		for (const d of baseData) byValue.set(d.value, d);

		const selectedData = selectedValue ? byValue.get(selectedValue) : null;
		const selectedVisible =
			!q ||
			(selectedData && (selectedData.label || "").toLowerCase().includes(q)) ||
			filteredBase.some((d) => d.value === selectedValue);

		if (q && selectedData && !selectedVisible) {
			// Inject selected into filtered list so it never “disappears” mid-search
			filteredBase = [selectedData, ...filteredBase];
		}

		const favOptions = favs
			.map((v) => byValue.get(v))
			.filter(Boolean)
			.filter((d) => (q ? (d.label || "").toLowerCase().includes(q) : true));

		const recentOptions = recent
			.map((v) => byValue.get(v))
			.filter(Boolean)
			.filter((d) => (q ? (d.label || "").toLowerCase().includes(q) : true))
			.filter((d) => !favs.includes(d.value));

		// Group remaining options by original group label (preserve order)
		const grouped = new Map();
		const alreadyUsed = new Set();
		for (const d of favOptions) alreadyUsed.add(d.value);
		for (const d of recentOptions) alreadyUsed.add(d.value);

		for (const d of filteredBase) {
			// Keep Auto as a top-level option, not duplicated
			if (d.value === "") continue;
			if (alreadyUsed.has(d.value)) continue;

			const groupLabel = d.group || "";
			if (!grouped.has(groupLabel)) grouped.set(groupLabel, []);
			grouped.get(groupLabel).push(d);
		}

		// Wipe + rebuild
		selectEl.innerHTML = "";

		// Auto at top (if present)
		const auto = baseData.find((d) => d.value === "");
		if (auto) {
			const autoOpt = document.createElement("option");
			autoOpt.value = auto.value;
			autoOpt.textContent = auto.label;
			if (selectedValue === "") autoOpt.selected = true;
			selectEl.appendChild(autoOpt);
		}

		// If searching and selected doesn’t match, show it clearly
		if (q && selectedData && selectedData.value && !((selectedData.label || "").toLowerCase().includes(q))) {
			selectEl.appendChild(buildOptgroup("Selected", [selectedData], selectedValue));
		}

		if (favOptions.length) {
			selectEl.appendChild(buildOptgroup("★ Favorites", favOptions, selectedValue));
		}

		if (recentOptions.length) {
			selectEl.appendChild(buildOptgroup("Recent", recentOptions, selectedValue));
		}

		// Original groups
		for (const [groupLabel, options] of grouped.entries()) {
			if (!groupLabel) {
				for (const d of options) {
					const opt = document.createElement("option");
					opt.value = d.value;
					opt.textContent = d.label;
					opt.disabled = !!d.disabled;
					if (opt.value === selectedValue) opt.selected = true;
					selectEl.appendChild(opt);
				}
			} else {
				selectEl.appendChild(buildOptgroup(groupLabel, options, selectedValue));
			}
		}

		// Restore selection if still present
		if (selectedValue !== null && selectedValue !== undefined) {
			selectEl.value = selectedValue;
		}
	}

	function makeEnhancer(selectEl) {
		const baseData = snapshotSelect(selectEl);

		// Build minimal toolbar (no CSS dependency)
		const tools = document.createElement("div");
		tools.className = "ppa-genre-tools";

		const search = document.createElement("input");
		search.type = "search";
		search.className = "ppa-genre-search";
		search.placeholder = "Search genres…";
		search.autocomplete = "off";
		search.setAttribute("aria-label", "Search genres");

		// Use Composer button classes if present; harmless if not styled
		const btnFav = document.createElement("button");
		btnFav.type = "button";
		btnFav.className = "ppa-btn ppa-btn-secondary ppa-genre-fav";
		btnFav.textContent = "☆"; // toggles to ★ when favored
		btnFav.title = "Toggle favorite";

		const btnClear = document.createElement("button");
		btnClear.type = "button";
		btnClear.className = "ppa-btn ppa-btn-secondary ppa-genre-clear";
		btnClear.textContent = "Clear";
		btnClear.title = "Clear search";

		// Insert tools directly before select (inside same form-group)
		selectEl.parentNode.insertBefore(tools, selectEl);
		tools.appendChild(search);
		tools.appendChild(btnFav);
		tools.appendChild(btnClear);

		function refreshFavButton() {
			const v = selectEl.value;
			const favs = getFavs();
			const isFav = !!v && v !== "" && favs.includes(v);
			btnFav.textContent = isFav ? "★" : "☆";
			btnFav.setAttribute("aria-pressed", isFav ? "true" : "false");
		}

		function rerender() {
			const favs = getFavs();
			const recent = getRecent();
			rebuildSelect(selectEl, baseData, search.value, favs, recent);
			refreshFavButton();
		}

		search.addEventListener("input", rerender);

		btnClear.addEventListener("click", function () {
			search.value = "";
			rerender();
			search.focus();
		});

		btnFav.addEventListener("click", function () {
			const v = selectEl.value;
			if (!v) return;

			const favs = getFavs();
			const idx = favs.indexOf(v);
			if (idx >= 0) favs.splice(idx, 1);
			else favs.unshift(v);

			setFavs(favs);
			rerender();
		});

		selectEl.addEventListener("change", function () {
			const v = selectEl.value;
			if (!v) {
				refreshFavButton();
				return;
			}

			const recent = getRecent();
			recent.unshift(v);
			setRecent(recent);
			refreshFavButton();
		});

		// Initial render
		rerender();
	}

	document.addEventListener("DOMContentLoaded", function () {
		// Preferred: explicit data attribute
		let selects = Array.from(document.querySelectorAll('select[data-ppa-genre-select="1"]'));

		// Fallback for fast testing before markup changes land
		if (!selects.length) {
			const fallback = document.getElementById("ppa-genre");
			if (fallback && fallback.tagName === "SELECT") selects = [fallback];
		}

		if (!selects.length) return;

		for (const sel of selects) {
			// Prevent double-init
			if (sel.dataset && sel.dataset.ppaGenreEnhanced === "1") continue;
			if (sel.dataset) sel.dataset.ppaGenreEnhanced = "1";

			// If tools already exist (manual insert), skip
			try {
				if (sel.parentNode && sel.parentNode.querySelector(".ppa-genre-tools")) continue;
			} catch (e0) {}

			makeEnhancer(sel);
		}
	});
})();
