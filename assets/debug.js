(function() {
	let c = document;
	function q(s, p) {
		return (p || c).querySelectorAll(s)
	}
	if (h = document.querySelector('#tab-phlo ol.code')) h.innerHTML = highlight_Phlo([...h.querySelectorAll('li')].map(li=>li.textContent).join('\n')).split('\n').map((l,i)=>`<li id="l${i+1}">${l||''}</li>`).join('')
	q(".tabs .tab-link").forEach(function(b) {
		b.addEventListener("click", function() {
			let id = b.getAttribute("data-tab");
			q(".tabs .tab-link", b.parentNode).forEach(function(x) {
					x.classList.remove("active")
			});
			b.classList.add("active");
			let panel = b.closest(".code-panel");
			q(".tab-content", panel).forEach(function(tc) {
					tc.classList.remove("active")
			});
			let t = panel.querySelector("#" + id);
			t && t.classList.add("active")
		})
	})
})();
