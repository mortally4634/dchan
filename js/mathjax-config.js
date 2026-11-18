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
		processEscapes: true
	},
	svg: {
		fontCache: 'global'
	},
	options: {
		// Don't scan code/pre or anything explicitly opted out.
		skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'],
		ignoreHtmlClass: 'no-mathjax'
	},
	startup: {
		typeset: true
	}
};
