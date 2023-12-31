! function (e, t, s) {
	"use strict";
	var i = s(".select2"),
		r = s(".select2-icons"),
		a = s(".max-length"),
		o = s(".hide-search"),
		n = s(".select2-data-array"),
		c = s(".select2-data-ajax"),
		l = s(".select2-size-lg"),
		d = s(".select2-size-sm"),
		p = s(".select2InModal");

	function u(e) {
		e.element;
		return e.id ? feather.icons[s(e.element).data("icon")].toSvg() + e.text : e.text
		//return "<img src='"+s(e.element).data("icon")+"'>" + e.text ;
		//return "<img src=''>" + e.text ;
	}
	i.each((function () {
		var e = s(this);
		e.wrap('<div class="position-relative"></div>'), e.select2({
			dropdownAutoWidth: !0,
			width: "100%",
			dropdownParent: e.parent()
		})
	})), r.each((function () {
		var e = s(this);
		e.wrap('<div class="position-relative"></div>'), e.select2({
			dropdownAutoWidth: !0,
			width: "100%",
			minimumResultsForSearch: 1 / 0,
			dropdownParent: e.parent(),
			templateResult: u,
			templateSelection: u,
			escapeMarkup: function (e) {
				return e
			}
		})
	})), a.wrap('<div class="position-relative"></div>').select2({
		dropdownAutoWidth: !0,
		width: "100%",
		maximumSelectionLength: 2,
		dropdownParent: a.parent(),
		placeholder: "Select maximum 2 items"
	}), o.select2({
		placeholder: "Select an option",
		minimumResultsForSearch: 1 / 0
	});
	n.wrap('<div class="position-relative"></div>').select2({
		dropdownAutoWidth: !0,
		dropdownParent: n.parent(),
		width: "100%",
		data: [{
			id: 0,
			text: "enhancement"
		}, {
			id: 1,
			text: "bug"
		}, {
			id: 2,
			text: "duplicate"
		}, {
			id: 3,
			text: "invalid"
		}, {
			id: 4,
			text: "wontfix"
		}]
	}), c.wrap('<div class="position-relative"></div>').select2({
		dropdownAutoWidth: !0,
		dropdownParent: c.parent(),
		width: "100%",
		ajax: {
			url: "https://api.github.com/search/repositories",
			dataType: "json",
			delay: 250,
			data: function (e) {
				return {
					q: e.term,
					page: e.page
				}
			},
			processResults: function (e, t) {
				return t.page = t.page || 1, {
					results: e.items,
					pagination: {
						more: 30 * t.page < e.total_count
					}
				}
			},
			cache: !0
		},
		placeholder: "Search for a repository",
		escapeMarkup: function (e) {
			return e
		},
		minimumInputLength: 1,
		templateResult: function (e) {
			if (e.loading) return e.text;
			var t = "<div class='select2-result-repository clearfix'><div class='select2-result-repository__avatar'><img src='" + e.owner.avatar_url + "' /></div><div class='select2-result-repository__meta'><div class='select2-result-repository__title'>" + e.full_name + "</div>";
			e.description && (t += "<div class='select2-result-repository__description'>" + e.description + "</div>");
			return t += "<div class='select2-result-repository__statistics'><div class='select2-result-repository__forks'>" + feather.icons["share-2"].toSvg({
				class: "mr-50"
			}) + e.forks_count + " Forks</div><div class='select2-result-repository__stargazers'>" + feather.icons.star.toSvg({
				class: "mr-50"
			}) + e.stargazers_count + " Stars</div><div class='select2-result-repository__watchers'>" + feather.icons.eye.toSvg({
				class: "mr-50"
			}) + e.watchers_count + " Watchers</div></div></div></div>"
		},
		templateSelection: function (e) {
			return e.full_name || e.text
		}
	}), l.each((function () {
		var e = s(this);
		e.wrap('<div class="position-relative"></div>'), e.select2({
			dropdownAutoWidth: !0,
			dropdownParent: e.parent(),
			width: "100%",
			containerCssClass: "select-lg"
		})
	})), d.each((function () {
		var e = s(this);
		e.wrap('<div class="position-relative"></div>'), e.select2({
			dropdownAutoWidth: !0,
			dropdownParent: e.parent(),
			width: "100%",
			containerCssClass: "select-sm"
		})
	})), s("#select2InModal").on("shown.bs.modal", (function () {
		p.select2({
			placeholder: "Select a state"
		})
	}))
}(window, document, jQuery);