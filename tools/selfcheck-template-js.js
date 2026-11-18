/* Self-check: inline <script> blocks in templates must still parse after the site's
   HTML minifier (inc/template.php) deletes every tab/CR/LF. A // comment in a
   script becomes a swallow-the-rest-of-the-script bug once the newlines are gone.
   Run: node tools/selfcheck-template-js.js [template files...]   (default: templates/mod/topbar.html)
   Scripts containing Twig tags are skipped (they are not valid JS before rendering). */
const fs = require('fs');
const files = process.argv.length > 2 ? process.argv.slice(2) : ['templates/mod/topbar.html'];
let failed = 0;
for (const f of files) {
  const html = fs.readFileSync(f, 'utf8');
  const blocks = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map((m) => m[1]);
  blocks.forEach((js, i) => {
    if (/\{\{|\{%/.test(js)) return;
    try {
      new Function(js.replace(/[\t\r\n]/g, ''));
    } catch (e) {
      console.log(`FAIL ${f} script #${i + 1} after minify: ${e.message}`);
      failed++;
    }
  });
  console.log(`${f}: ${blocks.length} script block(s) checked`);
}
console.log(failed ? `FAILED ${failed}` : 'all template scripts survive minification');
process.exit(failed ? 1 : 0);
