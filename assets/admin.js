(function () {
	function showSpinner() {
		var spin = document.getElementById("wcs_spinner");
		if (spin) {
			spin.style.display = "block";
		}
	}

	function bindSpinner() {
		var runForm = document.getElementById("wcs_run_form");
		var saveForm = document.getElementById("wcs_form");

		if (runForm) {
			runForm.addEventListener("submit", showSpinner);
		}
		if (saveForm) {
			saveForm.addEventListener("submit", showSpinner);
		}
	}

	function setTab(name) {
		var tabs = document.querySelectorAll("#wcs_app .wcs_tab");
		var panels = {
			charts: document.getElementById("wcs_panel_charts"),
			rankings: document.getElementById("wcs_panel_rankings"),
			segments: document.getElementById("wcs_panel_segments"),
			about: document.getElementById("wcs_panel_about")
		};

		for (var k in panels) {
			if (!panels[k]) {
				continue;
			}
			panels[k].style.display = (k === name) ? "block" : "none";
		}

		for (var i = 0; i < tabs.length; i++) {
			var t = tabs[i];
			var active = (t.getAttribute("data-tab") === name);
			if (active) {
				t.classList.add("is_active");
			} else {
				t.classList.remove("is_active");
			}
		}

		if (window.wcsCharts && typeof window.wcsCharts.resizeAll === "function") {
			setTimeout(window.wcsCharts.resizeAll, 80);
		}
	}

	function bindTabs() {
		var tabs = document.querySelectorAll("#wcs_app .wcs_tab");
		if (!tabs.length) {
			return;
		}

		for (var i = 0; i < tabs.length; i++) {
			tabs[i].addEventListener("click", function () {
				setTab(this.getAttribute("data-tab"));
			});
		}

		setTab("charts");
	}

	function init() {
		bindSpinner();
		bindTabs();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();