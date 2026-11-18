<?php
// Self-check for imgboard_sanitize_html (inc/mod/pending.php). Run: docker compose exec -T php php < tools/selfcheck-sanitize.php
function imgboard_sanitize_html(string $html): string {
	if (trim($html) === '') return '';
	$d = new DOMDocument();
	libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();
	$xp = new DOMXPath($d);
	foreach ($xp->query('//script|//iframe|//object|//embed|//form|//meta|//link|//base|//style|//svg|//math') as $n) {
		$n->parentNode->removeChild($n);
	}
	foreach ($xp->query('//*[@*]') as $el) {
		foreach (iterator_to_array($el->attributes) as $a) {
			$name = strtolower($a->name);
			$val = strtolower(preg_replace('/[\x00-\x20]+/', '', $a->value));
			$bad = strpos($name, 'on') === 0 || $name === 'srcdoc' || $name === 'formaction'
				|| (in_array($name, ['href', 'src', 'xlink:href', 'action'], true)
					&& preg_match('/^(javascript|vbscript|data):/', $val) && strpos($val, 'data:image/') !== 0);
			if ($bad) $el->removeAttributeNode($a);
		}
	}
	$out = '';
	foreach ($d->documentElement->childNodes as $c) $out .= $d->saveHTML($c);
	return $out;
}
$t = [
 ['<p onclick="x()">hi</p><script>alert(1)</script>', ['<p>hi</p>'], ['script','onclick']],
 ['<a href="javascript:alert(1)">x</a>', ['<a>x</a>'], ['javascript']],
 ['<a href=" JaVa&#09;script:alert(1)">x</a>', [], ['script:']],
 ['<img src=x onerror=alert(1)>', [], ['onerror']],
 ['<iframe src="//evil"></iframe><b>ok</b>', ['<b>ok</b>'], ['iframe']],
 ['<a href="https://ok.com">fine</a> <b>bold</b>', ['https://ok.com','<b>bold</b>'], []],
 ['<img src="data:image/png;base64,AAA">', ['data:image/png'], []],
 ['', [], []],
];
$fail = 0;
foreach ($t as [$in, $must, $mustnt]) {
  $o = imgboard_sanitize_html($in);
  foreach ($must as $m) if (strpos($o, $m) === false) { echo "FAIL missing [$m] in [$o]\n"; $fail++; }
  foreach ($mustnt as $m) if (stripos($o, $m) !== false) { echo "FAIL leaked [$m] in [$o]\n"; $fail++; }
}
echo $fail ? "FAILED $fail\n" : "all sanitizer checks passed\n";
