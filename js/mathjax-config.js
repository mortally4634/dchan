/*
 * mathjax-config.js — example.com
 *
 * MathJax v3 configuration. MUST load before js/mathjax/tex-svg.js.
 *
 * Posts use [math]...[/math] for inline and [eqn]...[/eqn] for display math;
 * the server markup (inc/config.php) rewrites those to \(...\) and \[...\],
 * which are the delimiters configured here. Bare $...$ is deliberately NOT a
 * delimiter — dollar signs are far too common in ordinary posts.
 */
window.MathJax = {
	tex: {
		inlineMath: [['\\(', '\\)']],
		displayMath: [['\\[', '\\]']],
		processEscapes: true,
		// No \require / autoload: they let a post load and enable further TeX packages (links, styles).
		packages: {'[-]': ['require', 'autoload']}
	},
	svg: {
		fontCache: 'global'
	},
	options: {
		// Don't scan code/pre or anything explicitly opted out.
		skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'],
		// Only the spans the server makes from [math] / [eqn] are typeset, nothing else on the page.
		ignoreHtmlClass: '.*',
		processHtmlClass: 'math-tex'
	},
	startup: {
		typeset: true,
		ready: function () {
			MathJax.startup.defaultReady();
			// \mmlToken can hang href="javascript:..." on a symbol: maths in posts gets no
			// links and no inline styles (which could fetch remote addresses).
			MathJax.startup.document.inputJax[0].postFilters.add(function (arg) {
				arg.data.root.walkTree(function (node) {
					var set = node.attributes && node.attributes.getAllAttributes();
					if (set) { delete set.href; delete set.style; }
				});
			});
		}
	}
};
